<?php
/**
 * TDM 澳门电视台 EPG 生成器 (PHP 7.4+)
 * 输出 XMLTV 格式，时间格式：20261031000000 +0800
 * 生成今明两天所有电视台节目表
 * channel id 直接使用 display-name
 * 适配 GitHub Actions 运行环境
 */

date_default_timezone_set('Asia/Shanghai'); // +0800

// ==================== 配置 ====================
$baseApi    = 'https://www.tdm.com.mo/api/v1.0';
$outputFile = __DIR__ . '/epg/tdm.xml';    // 输出路径：脚本目录下 epg/tdm.xml
$days       = 7;                           // 今明两天
$requestDelay = 200000;                    // 请求间隔 0.2 秒

// ==================== 工具函数 ====================
function httpGet(string $url): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; TDM-EPG/1.0; GitHub Actions)',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Referer: https://www.tdm.com.mo/zh-hant/program_list',
            'Origin: https://www.tdm.com.mo',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($code !== 200 || $body === false) {
        echo "  [警告] 请求失败 HTTP:{$code}, 错误:{$error}\n";
        return null;
    }
    $json = json_decode($body, true);
    return (isset($json['code']) && $json['code'] === 0) ? $json['data'] : null;
}

/**
 * 把 "2026-10-31 19:00:00" 转成 XMLTV 时间 "20261031190000 +0800"
 */
function toXmltvTime(string $datetime): string
{
    $ts = strtotime($datetime);
    return date('YmdHis', $ts) . ' +0800';
}

/**
 * XML 转义
 */
function xmlEscape(string $str): string
{
    return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ==================== 主逻辑 ====================
echo "=== TDM EPG 生成任务开始 ===\n";
echo "目标接口: {$baseApi}\n";
echo "输出文件: {$outputFile}\n\n";

// 自动创建输出目录
$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
    echo "已创建输出目录: {$outputDir}\n";
}

echo "正在获取频道列表...\n";
$channels = httpGet($baseApi . '/program-list/Channel?type=tv');
if (empty($channels)) {
    die("【错误】获取频道列表失败，任务终止\n");
}
echo "获取到频道数量: " . count($channels) . "\n\n";

// 生成日期列表（今明两天）
$dates = [];
for ($i = 0; $i < $days; $i++) {
    $dates[] = date('Y-m-d', strtotime("+{$i} day"));
}

// 开始构建 XML
$xml = new DOMDocument('1.0', 'UTF-8');
$xml->formatOutput = true;

$tv = $xml->createElement('tv');
$tv->setAttribute('generator-info-name', 'TDM-EPG-GitHub-Actions');
$tv->setAttribute('generator-info-url', 'https://www.tdm.com.mo');
$xml->appendChild($tv);

// 1. 写入所有频道（channel id = display-name）
foreach ($channels as $ch) {
    $channelId = $ch['title'];

    $channelNode = $xml->createElement('channel');
    $channelNode->setAttribute('id', $channelId);

    $displayName = $xml->createElement('display-name', xmlEscape($ch['title']));
    $displayName->setAttribute('lang', 'zh');
    $channelNode->appendChild($displayName);

    if (!empty($ch['logo'])) {
        $icon = $xml->createElement('icon');
        $icon->setAttribute('src', $ch['logo']);
        $channelNode->appendChild($icon);
    }

    $tv->appendChild($channelNode);
}

// 2. 抓取并写入节目
$totalProgrammes = 0;

foreach ($channels as $ch) {
    $channelId = $ch['title'];
    echo "\n处理频道：{$ch['title']} (ID:{$ch['id']})\n";

    foreach ($dates as $date) {
        $url = sprintf(
            '%s/program-list/%s?date=%s&type=tv&channelId=%d',
            $baseApi,
            $date,
            $date,
            $ch['id']
        );

        $programmes = httpGet($url);
        if (empty($programmes)) {
            echo "  - {$date} 无数据\n";
            continue;
        }

        // 按时间排序
        usort($programmes, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        $count = count($programmes);
        for ($i = 0; $i < $count; $i++) {
            $prog = $programmes[$i];
            $start = $prog['date'];

            // 结束时间 = 下一个节目的开始时间，最后一个节目默认 +1 小时
            if ($i + 1 < $count) {
                $stop = $programmes[$i + 1]['date'];
            } else {
                $stop = date('Y-m-d H:i:s', strtotime($start) + 3600);
            }

            $programme = $xml->createElement('programme');
            $programme->setAttribute('start', toXmltvTime($start));
            $programme->setAttribute('stop',  toXmltvTime($stop));
            $programme->setAttribute('channel', $channelId);

            // 节目标题
            $title = $xml->createElement('title', xmlEscape(trim($prog['title'])));
            $title->setAttribute('lang', 'zh');
            $programme->appendChild($title);

            // 可选：直播标记
            if (!empty($prog['isLive'])) {
                $live = $xml->createElement('live');
                $programme->appendChild($live);
            }

            $tv->appendChild($programme);
            $totalProgrammes++;
        }

        echo "  - {$date} 共 {$count} 个节目\n";
        usleep($requestDelay);
    }
}

// 保存文件
$xml->save($outputFile);

echo "\n=== 任务完成 ===\n";
echo "频道总数： " . count($channels) . "\n";
echo "节目总数： {$totalProgrammes}\n";
echo "输出文件： {$outputFile}\n";
echo "文件大小： " . round(filesize($outputFile) / 1024, 2) . " KB\n";
