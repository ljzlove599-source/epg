<?php
/**
 * 中天電視節目表提取與 XML EPG 生成腳本
 * GitHub Actions 适配版
 * 數據源: https://asia-east1-ctitv-237901.cloudfunctions.net/ProgramList-Api2
 * 优化：延长超时阈值、增加失败重试、兼容SSL证书，解决 curl err: 28 超时问题
 */
date_default_timezone_set('Asia/Shanghai');

// 設定參數
$chid = 'a1'; // 北美台
$date = isset($argv[1]) ? $argv[1] : date('Y-m-d');
$next_date = date('Y-m-d', strtotime($date . ' +1 day'));

$api_url = "https://asia-east1-ctitv-237901.cloudfunctions.net/ProgramList-Api2?chid={$chid}&start={$date}&end={$next_date}";

// ========== 核心优化：curl 重试机制 + 超时配置 ==========
$max_retries = 3;          // 最多重试 3 次
$retry_delay = 2;          // 每次重试间隔 2 秒
$json_data = false;
$last_errno = 0;
$last_error = '';

for ($retry = 0; $retry < $max_retries; $retry++) {
    if ($retry > 0) {
        echo "[重试 {$retry}/{$max_retries}] 等待 {$retry_delay} 秒后重试...\n";
        sleep($retry_delay);
    }

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,    // 连接阶段超时 20 秒
        CURLOPT_TIMEOUT => 60,           // 整体请求总超时 60 秒
        CURLOPT_SSL_VERIFYPEER => false, // 兼容云函数SSL证书问题
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
    ]);

    $json_data = curl_exec($ch);
    $last_errno = curl_errno($ch);
    $last_error = curl_error($ch);
    curl_close($ch);

    // 请求成功则退出重试循环
    if ($last_errno === 0 && $json_data !== false) {
        break;
    }
}

if ($last_errno !== 0 || !$json_data) {
    die("無法從 API 獲取數據, curl err: {$last_errno} - {$last_error}\n");
}
// ======================================================

$programs = json_decode($json_data, true);
if (!is_array($programs)) {
    die("JSON 解析失敗\n");
}

function format_epg_date($iso_date) {
    $dt = new DateTime($iso_date);
    $dt->setTimezone(new DateTimeZone('Asia/Shanghai'));
    return $dt->format('YmdHis') . ' +0800';
}

$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><tv/>');
$xml->addAttribute('generator-info-name', 'Manus EPG Generator');

$channel = $xml->addChild('channel');
$channel->addAttribute('id',  '中天北美台');
$channel->addChild('display-name', '中天北美台');

foreach ($programs as $prog) {
    // 取消 continue 过滤，保留跨天节目
    $programme = $xml->addChild('programme');
    $programme->addAttribute('start', format_epg_date($prog['start']));
    $programme->addAttribute('stop', format_epg_date($prog['end']));
    $programme->addAttribute('channel', '中天北美台');
    
    $title = $programme->addChild('title', htmlspecialchars($prog['title']));
    $title->addAttribute('lang', 'zh');
    
    if (isset($prog['levelName'])) {
        $rating = $programme->addChild('rating');
        $rating->addChild('value', htmlspecialchars($prog['levelName']));
    }
}

$xml_content = $xml->asXML();
$outFile = __DIR__ . '/epg_sources/epg_zhongtianam.xml';
file_put_contents($outFile, $xml_content);
echo "✅ EPG 生成完成：{$outFile}\n";
?>
