<?php
/**
 * 台湾大宽频 kbro EPG 采集脚本（PHP 版 · GitHub Actions 适配 · 繁转简修复版）
 * 输出标准 XMLTV 格式文件
 * 接口来源：https://epg.kbro.com.tw:2543/epg/
 */

// ==================== 基础配置 ====================
define('KBRO_BASE', 'https://epg.kbro.com.tw:2543/epg');
define('APPID_CHANNEL', 'WEB');   // 频道列表接口 appid
define('APPID_PROGRAM', 'TFM');   // 节目单接口 appid
define('CACHE_TTL', 3600);        // 缓存有效期（秒），默认1小时
define('CACHE_DIR', __DIR__ . '/cache/kbro');
define('T2S_MAP_FILE', __DIR__ . '/t2s.json'); // 同目录繁体转简体映射
define('OUTPUT_FILE', __DIR__ . '/epg_sources/kbro_epg.xml'); // 指定输出路径
define('FETCH_DAYS', 3);          // 采集天数，接口最多支持 3 天

// 初始化目录
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}
$outputDir = dirname(OUTPUT_FILE);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// ==================== 核心工具函数 ====================

/**
 * 繁体转简体（依赖 t2s.json 映射表，无文件则原样返回）
 */
function toSimplified(string $text): string
{
    static $map = null;
    if ($map === null) {
        if (file_exists(T2S_MAP_FILE)) {
            $json = file_get_contents(T2S_MAP_FILE);
            $map = json_decode($json, true);
            if (!is_array($map)) {
                echo "[警告] t2s.json 解析失败，格式不正确\n";
                $map = [];
            } else {
                echo "[信息] 已加载 t2s.json，共 " . count($map) . " 条映射\n";
            }
        } else {
            echo "[警告] 未找到 t2s.json 文件，跳过繁转简\n";
            $map = [];
        }
    }
    if (empty($map)) return $text;
    return strtr($text, $map);
}

/**
 * 安全抓取 JSON，带本地缓存 + 并发防损机制
 * @throws Exception
 */
function fetchJson(string $url, string $cacheFile, bool $force = false): array
{
    // 命中有效缓存直接返回
    if (!$force && file_exists($cacheFile)) {
        $stat = stat($cacheFile);
        if (time() - $stat['mtime'] < CACHE_TTL && $stat['size'] > 1024) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
    }

    // HTTP 请求头（必须带 Referer，否则接口返回 403）
    $headers = [
        'Referer: https://www.twmbroadband.com/',
        'Accept: application/json, text/plain, */*',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($content)) {
        throw new Exception("请求失败 [HTTP {$httpCode}]: {$error}，URL: {$url}");
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON 解析失败: " . json_last_error_msg() . "，URL: {$url}");
    }

    // 先写临时文件再重命名，避免并发读取半成品
    $tmpFile = $cacheFile . '.tmp';
    file_put_contents($tmpFile, $content);
    rename($tmpFile, $cacheFile);

    return $data;
}

/**
 * 合并所有经营区(so)的频道数据，按 channelid 去重
 * 必须合并全部区域，单区域会丢失频道
 */
function mergeChannelData(array $data): array
{
    $byId = [];
    foreach ($data as $soGroup => $channelList) {
        if (!is_array($channelList)) continue;
        foreach ($channelList as $ch) {
            $chId = $ch['CHANNELID'];
            if (isset($byId[$chId])) continue;
            $byId[$chId] = [
                'channel_id' => $chId,
                'service_id' => $ch['SERVICEID'],
                'number' => $ch['CHANNELNUMBER'],
                'name' => $ch['CHANNELNAME'],
                'type' => !empty($ch['CH_CODENAME'])
                    ? $ch['CH_CODENAME']
                    : implode('/', $ch['CHANNELTYPE'] ?? []),
            ];
        }
    }
    return $byId;
}

/**
 * 加载全量频道列表
 */
function loadChannels(bool $force = false): array
{
    $cacheFile = CACHE_DIR . '/channels.json';
    $url = KBRO_BASE . '/epg_channel.php?appid=' . APPID_CHANNEL;
    $raw = fetchJson($url, $cacheFile, $force);
    return [
        'channels' => mergeChannelData($raw['DATA'] ?? []),
        'update_time' => $raw['UPDATETIME'] ?? '',
    ];
}

/**
 * 加载指定日期的节目单，按 channelid 分组
 * @param string $ymd 日期格式 YYYYMMDD
 */
function loadProgrammes(string $ymd, bool $force = false): array
{
    $cacheFile = CACHE_DIR . "/prog_{$ymd}.json";
    $url = KBRO_BASE . '/epg_program.php?appid=' . APPID_PROGRAM . '&date=' . $ymd;
    $raw = fetchJson($url, $cacheFile, $force);

    $byChannel = [];
    foreach ($raw['PROG'] ?? [] as $prog) {
        $chId = (string)$prog['channelid'];
        if (!isset($byChannel[$chId])) {
            $byChannel[$chId] = [];
        }
        $byChannel[$chId][] = $prog;
    }

    // 每个频道按开始时间升序排序
    foreach ($byChannel as &$list) {
        usort($list, function ($a, $b) {
            return strcmp((string)$a['starttime'], (string)$b['starttime']);
        });
    }
    unset($list);

    return $byChannel;
}

/**
 * 解析 kbro 时间字符串 (YYYYMMDDHHMMSS) 为 UTC 时间戳
 * 台湾时区为 UTC+8
 */
function parseKbroTime(string $timeStr): ?int
{
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', trim($timeStr), $m)) {
        return null;
    }
    // 转 UTC 时间戳
    return gmmktime((int)$m[4] - 8, (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
}

/**
 * 时间戳转 XMLTV 标准格式 (YYYYMMDDHHMMSS +0800)
 */
function formatXmltvTime(int $timestamp): string
{
    return gmdate('YmdHis', $timestamp + 8 * 3600) . ' +0800';
}

/**
 * 节目标题归一化（用于简介去重、匹配）
 */
function normalizeTitle(string $title): string
{
    $s = toSimplified($title);
    $s = preg_replace('/[\s\x{3000}]+/u', '', $s);
    $s = preg_replace('/[（(]\d+[)）]/u', '', $s);
    $s = preg_replace('/[（(][^\u4e00-\u9fa5A-Za-z]{1,4}[)）]$/u', '', $s);
    $s = preg_replace('/[:：]\s*\d+$/u', '', $s);
    $s = preg_replace('/第\d+季[:：]?\d*$/u', '', $s);
    $s = preg_replace('/#\d+$/u', '', $s);
    $s = preg_replace('/\[[^\]]{1,6}\]/u', '', $s);
    $s = preg_replace('/[·・,，、\-—_]/u', '', $s);
    return mb_strtolower($s, 'UTF-8');
}

/**
 * 判断是否为有效节目简介（过滤复读标题、过短、频道宣传语）
 */
function isValidDescription(string $desc, string $title): bool
{
    $desc = trim($desc);
    $title = trim($title);
    if (empty($desc)) return false;
    if ($desc === $title) return false;
    if (mb_strlen($desc, 'UTF-8') < 12) return false;
    if (normalizeTitle($desc) === normalizeTitle($title)) return false;
    return true;
}

/**
 * 过滤频道宣传语：同一频道内≥3个不同节目共用同一句简介，视为频道宣传语
 */
function filterPromoDesc(array $programs): array
{
    $descCount = [];
    foreach ($programs as $p) {
        $d = trim($p['desc'] ?? '');
        if ($d === '') continue;
        if (!isset($descCount[$d])) $descCount[$d] = 0;
        $descCount[$d]++;
    }

    $promoSet = [];
    foreach ($descCount as $d => $cnt) {
        if ($cnt >= 3) $promoSet[$d] = true;
    }

    if (empty($promoSet)) return $programs;

    foreach ($programs as &$p) {
        if (isset($promoSet[trim($p['desc'] ?? '')])) {
            $p['desc'] = '';
        }
    }
    unset($p);

    return $programs;
}

// ==================== 主流程：生成 XMLTV ====================

try {
    echo "=== kbro EPG 采集开始 ===\n";
    
    // 提前触发一次繁转简加载，打印日志
    toSimplified('');
    
    echo "正在加载频道列表...\n";
    $channelData = loadChannels();
    $channels = $channelData['channels'];
    echo "共加载到 " . count($channels) . " 个唯一频道\n";

    // 生成采集日期列表（今天 + 未来 N-1 天）
    $dates = [];
    $today = strtotime('today');
    for ($i = 0; $i < FETCH_DAYS; $i++) {
        $ts = $today + $i * 86400;
        $dates[] = date('Ymd', $ts);
    }

    // 按天加载节目单
    $allPrograms = []; // [channelId => [...programs]]
    foreach ($dates as $ymd) {
        echo "正在采集 {$ymd} 的节目单...\n";
        try {
            $dayPrograms = loadProgrammes($ymd);
            foreach ($dayPrograms as $chId => $progs) {
                if (!isset($allPrograms[$chId])) {
                    $allPrograms[$chId] = [];
                }
                $allPrograms[$chId] = array_merge($allPrograms[$chId], $progs);
            }
            echo "  ✓ {$ymd} 采集完成，覆盖 " . count($dayPrograms) . " 个频道\n";
        } catch (Exception $e) {
            echo "  ✗ {$ymd} 采集失败: " . $e->getMessage() . "\n";
        }
    }

    echo "\n正在生成 XMLTV 文件（已启用繁转简）...\n";

    // 打开输出流
    $fp = fopen(OUTPUT_FILE, 'w');
    if (!$fp) throw new Exception("无法打开输出文件: " . OUTPUT_FILE);

    // XML 头部
    fwrite($fp, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
    fwrite($fp, '<tv generator-info-name="kbro-epg-php" generator-info-url="https://epg.kbro.com.tw">' . "\n");

    // 输出频道节点（频道名也转简体）
    foreach ($channels as $chId => $ch) {
        $id = htmlspecialchars($chId, ENT_XML1);
        $name = htmlspecialchars(toSimplified($ch['name']), ENT_XML1);
        fwrite($fp, "  <channel id=\"{$id}\">\n");
        fwrite($fp, "    <display-name>{$name}</display-name>\n");
        if (!empty($ch['number'])) {
            $num = htmlspecialchars($ch['number'], ENT_XML1);
            fwrite($fp, "    <display-name>{$num}</display-name>\n");
        }
        fwrite($fp, "  </channel>\n");
    }

    // 输出节目节点（标题+简介都转简体）
    $totalProg = 0;
    foreach ($allPrograms as $chId => $rawProgs) {
        // 格式化节目数据
        $formatted = [];
        foreach ($rawProgs as $p) {
            $startTs = parseKbroTime($p['starttime'] ?? '');
            if ($startTs === null) continue;

            $stopTs = parseKbroTime($p['endtime'] ?? '');
            // endtime 缺失则用 duration 补
            if ($stopTs === null && !empty($p['duration'])) {
                $stopTs = $startTs + (int)$p['duration'] * 1000;
            }

            $title = trim($p['programname'] ?? '');
            if ($title === '') continue;

            $desc = trim($p['programdescr'] ?? '');
            if (!isValidDescription($desc, $title)) {
                $desc = '';
            }

            $formatted[] = [
                'start' => $startTs,
                'stop' => $stopTs,
                'title' => $title,
                'desc' => $desc,
            ];
        }

        // 过滤频道宣传语
        $formatted = filterPromoDesc($formatted);
        // 全局按时间排序
        usort($formatted, function ($a, $b) {
            return $a['start'] - $b['start'];
        });

        $chIdEsc = htmlspecialchars($chId, ENT_XML1);
        foreach ($formatted as $prog) {
            $start = formatXmltvTime($prog['start']);
            $stop = $prog['stop'] ? formatXmltvTime($prog['stop']) : '';
            // 标题转简体后再转义输出
            $title = htmlspecialchars(toSimplified($prog['title']), ENT_XML1);

            $line = "  <programme start=\"{$start}\"";
            if ($stop) $line .= " stop=\"{$stop}\"";
            $line .= " channel=\"{$chIdEsc}\">\n";
            fwrite($fp, $line);
            fwrite($fp, "    <title lang=\"zh-CN\">{$title}</title>\n");

            if (!empty($prog['desc'])) {
                // 简介转简体后再转义输出
                $desc = htmlspecialchars(toSimplified($prog['desc']), ENT_XML1);
                fwrite($fp, "    <desc lang=\"zh-CN\">{$desc}</desc>\n");
            }

            fwrite($fp, "  </programme>\n");
            $totalProg++;
        }
    }

    fwrite($fp, '</tv>');
    fclose($fp);

    echo "\n=== 采集完成 ===\n";
    echo "输出文件: " . OUTPUT_FILE . "\n";
    echo "频道数: " . count($channels) . "\n";
    echo "节目总数: {$totalProg}\n";
    echo "文件大小: " . round(filesize(OUTPUT_FILE) / 1024 / 1024, 2) . " MB\n";

} catch (Exception $e) {
    die("运行错误: " . $e->getMessage() . "\n");
}

