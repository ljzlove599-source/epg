<?php
/**
 * 台湾中华电信 MOD 节目单抓取 (mod.cht.com.tw) - PHP 版
 * 输出标准 XMLTV 格式文件，兼容 Web 与 CLI 双环境
 * 
 * 修复：Web 环境下 STDERR 未定义问题
 * 新增：Web 环境执行超时保护、实时输出刷新
 */

// ==================== 配置常量 ====================
define('LIST_URL', 'https://mod.cht.com.tw/%E9%A0%BB%E9%81%93TV/%E5%85%A8%E9%83%A8.do');
define('EPG_BASE', 'https://modp.cht.com.tw/modinfo/epginfob.php');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36');
define('REFERER', 'https://mod.cht.com.tw/');
define('TIMEOUT', 25);          // 单次请求超时时间（秒）
define('RETRIES', 2);           // 重试次数
define('RETRY_DELAY', 800000);  // 重试间隔（微秒，800ms）
define('OUTPUT_FILE', __DIR__ . '/epg_sources/mod_epg.xml'); // 输出文件（同目录绝对路径）
define('TIMEZONE', 'Asia/Taipei');    // 台湾时区（UTC+8）
define('MAX_WEEK', 1);          // 抓取周数：0=本周7天，1=含下周共14天

// 全局缓存
$weekCache = [];
$listCache = null;

// ==================== 环境兼容初始化 ====================
// 取消 PHP 执行时间限制（Web 环境防止 30s 超时）
set_time_limit(0);
// Web 环境关闭输出缓冲，实时显示进度
if (php_sapi_name() !== 'cli') {
    if (ob_get_level()) ob_end_clean();
    ob_implicit_flush(true);
    header('Content-Type: text/html; charset=utf-8');
}
date_default_timezone_set(TIMEZONE);

/**
 * 兼容输出错误信息（CLI 写 STDERR，Web 写日志+页面提示）
 */
function write_error($msg) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
    } else {
        error_log($msg);
        echo '<p style="color:#d93025; margin:4px 0;">[警告] ' . htmlspecialchars($msg) . '</p>';
    }
}

/**
 * 兼容输出普通信息
 */
function write_log($msg) {
    if (php_sapi_name() === 'cli') {
        echo $msg . PHP_EOL;
    } else {
        echo '<p style="margin:4px 0; color:#333;">' . htmlspecialchars($msg) . '</p>';
    }
}

// ==================== 基础工具函数 ====================

/**
 * 带重试的 HTTP GET 请求
 */
function http_get($url, $timeout = TIMEOUT) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_REFERER => REFERER,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => 'gzip, deflate',
    ]);

    $lastError = '';
    for ($i = 0; $i <= RETRIES; $i++) {
        if ($i > 0) {
            usleep(RETRY_DELAY);
        }
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($html !== false && $httpCode === 200) {
            curl_close($ch);
            return $html;
        }
        $lastError = curl_error($ch) . " (HTTP {$httpCode})";
    }
    curl_close($ch);
    throw new Exception("请求失败 [{$url}]: {$lastError}");
}

/**
 * 获取台湾本地日期 YYYYMMDD
 */
function tw_ymd($offsetDays = 0) {
    $dt = new DateTime('now', new DateTimeZone(TIMEZONE));
    if ($offsetDays !== 0) {
        $dt->modify("{$offsetDays} day");
    }
    return $dt->format('Ymd');
}

/**
 * 计算两个 YYYYMMDD 日期相差天数
 */
function day_diff($a, $b) {
    $d1 = DateTime::createFromFormat('Ymd', $a, new DateTimeZone('UTC'));
    $d2 = DateTime::createFromFormat('Ymd', $b, new DateTimeZone('UTC'));
    return (int)$d1->diff($d2)->days * ($d1 > $d2 ? 1 : -1);
}

// ==================== 频道列表解析 ====================

/**
 * 获取频道清单
 */
function fetch_channel_list() {
    global $listCache;
    if ($listCache !== null) {
        return $listCache;
    }

    $html = http_get(LIST_URL, 35);
    $pattern = '/<div class="number">(\d+)<\/div>[\s\S]{0,900}?<h3><a[^>]*>([^<]+)<\/a><\/h3>/';
    preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

    $out = [];
    $seen = [];
    foreach ($matches as $m) {
        $id = trim($m[1]);
        if (isset($seen[$id])) continue;
        if ($id === '2') continue; // 跳过占位台
        $seen[$id] = true;
        $out[] = [
            'sourceId' => $id,
            'name' => trim($m[2]),
            'num' => $id,
        ];
    }

    if (empty($out)) {
        throw new Exception("频道清单解析为空，页面结构可能已变更");
    }

    $listCache = $out;
    return $out;
}

// ==================== EPG 解析 ====================

/**
 * 解析 EPG HTML → 按天分组的节目数组
 */
function parse_epg_html($html) {
    $days = [];
    $blocks = explode("<div class='epgdays'>", $html);
    array_shift($blocks);

    foreach ($blocks as $block) {
        $head = substr($block, 0, 260);
        $headPlain = strip_tags($head);
        if (!preg_match('/(\d{4})-(\d{2})-(\d{2})/', $headPlain, $dm)) {
            continue;
        }
        $date = $dm[1] . $dm[2] . $dm[3];

        $itemPattern = "/<div class='(past|now|future)'[^>]*><div class='time'>\s*([\d:]+)<\/div><div class='channelTitle'>\s*([^<]+?)\s*<\/div>/";
        preg_match_all($itemPattern, $block, $items, PREG_SET_ORDER);

        $dayItems = [];
        foreach ($items as $it) {
            $hhmm = trim($it[2]);
            $title = trim($it[3]);
            if (!preg_match('/^\d{1,2}:\d{2}$/', $hhmm) || empty($title)) {
                continue;
            }
            $dayItems[] = [
                'hhmm' => $hhmm,
                'title' => $title,
            ];
        }

        $days[] = [
            'date' => $date,
            'items' => $dayItems,
        ];
    }

    return $days;
}

/**
 * 抓取单频道整周节目（带缓存）
 */
function fetch_week($channelId, $w) {
    global $weekCache;
    $key = "{$channelId}|{$w}";
    if (isset($weekCache[$key])) {
        return $weekCache[$key];
    }

    $url = EPG_BASE . '?id=' . urlencode($channelId) . '&w=' . $w;
    $html = http_get($url);
    $days = parse_epg_html($html);
    $weekCache[$key] = $days;
    return $days;
}

// ==================== 节目时间构建 ====================

/**
 * 构建带起止时间的节目行
 */
function build_rows($days, $ymd) {
    $target = null;
    foreach ($days as $d) {
        if ($d['date'] === $ymd) {
            $target = $d;
            break;
        }
    }
    if (!$target || empty($target['items'])) {
        return [];
    }

    $baseDt = DateTime::createFromFormat('Ymd His', $ymd . ' 000000', new DateTimeZone(TIMEZONE));
    $baseTs = $baseDt->getTimestamp();

    $rows = [];
    $itemCount = count($target['items']);
    for ($i = 0; $i < $itemCount; $i++) {
        $it = $target['items'][$i];
        list($h, $m) = explode(':', $it['hhmm']);
        $startTs = $baseTs + (int)$h * 3600 + (int)$m * 60;

        if ($i + 1 < $itemCount) {
            list($nh, $nm) = explode(':', $target['items'][$i + 1]['hhmm']);
            $stopTs = $baseTs + (int)$nh * 3600 + (int)$nm * 60;
        } else {
            $stopTs = $baseTs + 86400;
        }

        if ($stopTs <= $startTs) continue;
        if ($stopTs - $startTs > 6 * 3600) continue;

        $rows[] = [
            'start' => $startTs,
            'stop' => $stopTs,
            'title' => $it['title'],
            'desc' => '',
            'category' => '',
        ];
    }

    return $rows;
}

// ==================== XML 生成 ====================

/**
 * 时间戳转 XMLTV 时间格式
 */
function ts_to_xmltv($ts) {
    return date('YmdHis O', $ts);
}

/**
 * XML 转义
 */
function xml_escape($str) {
    return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * 生成完整 XMLTV 内容
 */
function generate_xml() {
    $channels = fetch_channel_list();
    $today = tw_ymd(0);

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<!DOCTYPE tv SYSTEM "xmltv.dtd">' . "\n";
    $xml .= '<tv source-info-name="中华电信MOD" generator-info-name="mod-epg-php">' . "\n";

    // 频道列表
    foreach ($channels as $ch) {
        $id = xml_escape('mod.' . $ch['sourceId']);
        $name = xml_escape($ch['name']);
        $xml .= "  <channel id=\"{$id}\">\n";
        $xml .= "    <display-name>{$name}</display-name>\n";
        $xml .= "    <display-name>" . xml_escape($ch['num']) . "</display-name>\n";
        $xml .= "  </channel>\n";
    }

    // 节目单
    foreach ($channels as $idx => $ch) {
        $channelId = $ch['sourceId'];
        $xmlId = xml_escape('mod.' . $channelId);

        try {
            for ($w = 0; $w <= MAX_WEEK; $w++) {
                $days = fetch_week($channelId, $w);
                foreach ($days as $day) {
                    if ($day['date'] < $today) continue;
                    $rows = build_rows($days, $day['date']);
                    foreach ($rows as $prog) {
                        $start = ts_to_xmltv($prog['start']);
                        $stop = ts_to_xmltv($prog['stop']);
                        $title = xml_escape($prog['title']);
                        $xml .= "  <programme start=\"{$start}\" stop=\"{$stop}\" channel=\"{$xmlId}\">\n";
                        $xml .= "    <title lang=\"zh\">{$title}</title>\n";
                        if (!empty($prog['desc'])) {
                            $xml .= "    <desc lang=\"zh\">" . xml_escape($prog['desc']) . "</desc>\n";
                        }
                        $xml .= "  </programme>\n";
                    }
                }
            }
            // 每 10 个频道输出一次进度
            if (($idx + 1) % 10 === 0) {
                write_log("已处理 " . ($idx + 1) . " / " . count($channels) . " 个频道");
            }
        } catch (Exception $e) {
            write_error("频道 {$ch['name']} ({$channelId}) 抓取失败: " . $e->getMessage());
            continue;
        }
    }

    $xml .= '</tv>';
    return $xml;
}

// ==================== 主入口 ====================
try {
    write_log("正在抓取频道列表...");
    $channels = fetch_channel_list();
    write_log("共获取到 " . count($channels) . " 个频道");

    write_log("正在抓取节目单并生成 XML 文件...");
    $xmlContent = generate_xml();

    if (file_put_contents(OUTPUT_FILE, $xmlContent) === false) {
        throw new Exception("文件写入失败，请检查目录权限：" . OUTPUT_FILE);
    }

    $fileSize = round(filesize(OUTPUT_FILE) / 1024, 2);
    write_log("✅ 生成完成！");
    write_log("文件路径：" . OUTPUT_FILE);
    write_log("频道数量：" . count($channels) . " 个");
    write_log("文件大小：" . $fileSize . " KB");

} catch (Exception $e) {
    write_error("运行失败：" . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        exit;
    } else {
        exit(1);
    }
}
