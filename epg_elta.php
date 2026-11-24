<?php
/**
 * 台湾 ELTA OTT 节目单数据源（eltaott.tv）—— PHP 移植版
 * 输出标准 XMLTV 格式 EPG 文件：epg/epg_elta.xml
 * 功能：繁转简（同目录 t2s.json）、内置频道别名映射、广播日跨天去重、半成品日期过滤
 * 
 * ⚠️ 运行环境要求：必须在海外网络（如 GitHub Actions 海外机房）执行
 * 境内 DNS 会污染 CDN 域名 piceltaott-elta.cdn.hinet.net，导致数据请求失败
 */

define('BASE_URL', 'https://piceltaott-elta.cdn.hinet.net/production/json');
define('PAGE_URL', 'https://eltaott.tv/channel/program_detail');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36');
define('PROBE_BACK', 3);   // 向前探测天数（仅用于边界校验）
define('PROBE_AHEAD', 7);  // 向后探测天数
define('OUTPUT_FILE', __DIR__ . '/epg_sources/epg_elta.xml');
define('TIMEZONE', 'Asia/Shanghai');
define('T2S_FILE', __DIR__ . '/t2s.json');

// 静态缓存
static $channelCache = null;
static $t2sMap = null;

// ==================== 频道别名配置区（直接在此修改） ====================
// 键：繁转简后的原始频道名；值：最终显示的频道名
function get_channel_alias_map(): array
{
    return [
        // 体育基础台
        '体育 1 台' => '爱尔达体育1台',
        '体育 2 台' => '爱尔达体育2台',
        '体育 3 台' => '爱尔达体育3台',
        '体育 4 台' => '爱尔达体育4台',

        // 体育MAX系列
        '体育MAX1台' => '爱尔达体育MAX1台',
        '体育MAX2台' => '爱尔达体育MAX2台',
        '体育MAX3台' => '爱尔达体育MAX3台',
        '体育MAX4台' => '爱尔达体育MAX4台',
        '体育MAX5台' => '爱尔达体育MAX5台',
        '体育MAX6台' => '爱尔达体育MAX6台',
        '体育MAX7台' => '爱尔达体育MAX7台',
        '体育MAX8台' => '爱尔达体育MAX8台',
        '体育MAX9台' => '爱尔达体育MAX9台',
        '体育MAX10台' => '爱尔达体育MAX10台',

        // 新闻/综合台
        '影剧台' => '爱尔达影剧台',
        '综合台' => '爱尔达综合台',
        '日韩台' => '爱尔达日韩台',

        // 电竞/主题台
        'Ginx电竞频道' => 'Ginx TV电竞频道',
    ];
}
// =======================================================================

/**
 * 移除 JSON 中的单行与多行注释
 */
function strip_json_comments(string $json): string
{
    $json = preg_replace('/\/\*[\s\S]*?\*\//', '', $json);
    $json = preg_replace('/\/\/.*$/m', '', $json);
    return trim($json);
}

/**
 * 加载繁转简映射表
 * @return array 繁=>简映射数组，加载失败返回空数组
 */
function load_t2s_map(): array
{
    global $t2sMap;
    if ($t2sMap !== null) return $t2sMap;

    if (!file_exists(T2S_FILE)) {
        echo "[提示] 未找到 t2s.json，跳过繁转简处理" . PHP_EOL;
        $t2sMap = [];
        return $t2sMap;
    }

    $content = file_get_contents(T2S_FILE);
    if ($content === false) {
        echo "[警告] 读取 t2s.json 失败，跳过繁转简处理" . PHP_EOL;
        $t2sMap = [];
        return $t2sMap;
    }

    $cleanJson = strip_json_comments($content);
    $map = json_decode($cleanJson, true);
    if (!is_array($map)) {
        echo "[警告] t2s.json 格式解析失败：" . json_last_error_msg() . "，跳过繁转简处理" . PHP_EOL;
        $t2sMap = [];
        return $t2sMap;
    }

    $t2sMap = $map;
    echo "[信息] 已加载繁转简映射，共 " . count($map) . " 条规则" . PHP_EOL;
    return $t2sMap;
}

/**
 * 文本繁转简
 * @param string $text 原始繁体文本
 * @return string 转换后的简体文本
 */
function t2s_convert(string $text): string
{
    $map = load_t2s_map();
    if (empty($map)) return $text;
    return strtr($text, $map);
}

/**
 * 频道名称标准化：繁转简 + 内置别名替换
 * @param string $originName 原始频道名
 * @return string 处理后的频道名
 */
function normalize_channel_name(string $originName): string
{
    $simpleName = t2s_convert(trim($originName));
    $aliasMap = get_channel_alias_map();
    return $aliasMap[$simpleName] ?? $simpleName;
}

/**
 * 获取台北时区的 YYYYMMDD 格式日期
 * @param int $offsetDays 天数偏移，正向后、负向前
 */
function taipei_ymd(int $offsetDays = 0): string
{
    $date = new DateTime('now', TIMEZONE);
    $date->modify($offsetDays . ' day');
    return $date->format('Ymd');
}

/**
 * YYYYMMDD 转换为 YYYY-MM-DD 格式
 */
function dash_ymd(string $ymd): string
{
    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

/**
 * 发起 HTTP 请求获取 JSON 数据（内置 gzip 解压、重试机制）
 * @param string $url 请求地址
 * @param int $retries 重试次数
 * @throws Exception 请求/解析失败时抛出异常
 */
function fetch_json(string $url, int $retries = 2): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => 'gzip,deflate',
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . USER_AGENT,
            'Referer: ' . PAGE_URL,
            'Accept: application/json'
        ]
    ]);

    $lastError = '';
    for ($i = 0; $i <= $retries; $i++) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response !== false && $httpCode === 200) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                curl_close($ch);
                return $data;
            }
            $lastError = 'JSON解析失败: ' . json_last_error_msg();
        } else {
            $lastError = curl_error($ch) ?: "HTTP {$httpCode}";
        }
        if ($i < $retries) usleep(500000);
    }

    curl_close($ch);
    throw new Exception("请求失败 [{$url}]: {$lastError}");
}

/**
 * 获取过滤后的有效频道列表（完整复现三态过滤逻辑）
 * @return array 频道数组，每项包含 sourceId/name/num/order/desc
 */
function fetch_channel_list(): array
{
    global $channelCache;
    if ($channelCache !== null) return $channelCache;

    $list = fetch_json(BASE_URL . '/channel/channel_list.json');
    if (!is_array($list)) throw new Exception('频道列表格式异常：非数组');

    $now = time();
    $out = [];

    foreach ($list as $item) {
        if ((int)($item['cl_status'] ?? 0) !== 1) continue;
        if ((int)($item['cl_display'] ?? 0) !== 1) continue;

        $showType = (int)($item['cp_show_type'] ?? 0);
        $stStr = (string)($item['cp_schedule_from_datetime'] ?? '');
        $etStr = (string)($item['cp_schedule_to_datetime'] ?? '');

        $inWindow = true;
        if ($stStr && $etStr && strpos($stStr, '0000') !== 0) {
            // 核心修改：实例化 DateTimeZone 对象作为第三个参数
            $st = DateTime::createFromFormat('Y-m-d H:i:s', $stStr, new DateTimeZone(TIMEZONE));
            $et = DateTime::createFromFormat('Y-m-d H:i:s', $etStr, new DateTimeZone(TIMEZONE));
            if ($st && $et) {
                $inWindow = ($st->getTimestamp() <= $now && $et->getTimestamp() > $now);
            }
        }


        if (!($showType === 1 || ($showType === 2 && $inWindow))) continue;

        $out[] = [
            'sourceId' => (string)$item['cl_num'],
            'name'     => trim((string)($item['cl_title'] ?? '')),
            'num'      => (string)$item['cl_num'],
            'order'    => (int)($item['cl_order'] ?? 0),
            'desc'     => trim((string)($item['cl_dec'] ?? ''))
        ];
    }

    usort($out, fn($a, $b) => $a['order'] - $b['order']);
    $channelCache = $out;
    return $out;
}

/**
 * 主函数：生成 XMLTV 格式 EPG 文件
 */
function generate_epg(): void
{
    $channels = fetch_channel_list();
    if (empty($channels)) throw new Exception('未获取到任何有效频道');

    $dayList = [];
    for ($i = -PROBE_BACK; $i <= PROBE_AHEAD; $i++) $dayList[] = taipei_ymd($i);
    $todayYmd = taipei_ymd(0);

    // 预加载全量日期数据
    $perDayData = [];
    $dayTotalCount = [];
    foreach ($dayList as $ymd) {
        try {
            $url = BASE_URL . '/program_list/program_list_' . dash_ymd($ymd) . '.json';
            $data = fetch_json($url);
            $perDayData[$ymd] = is_array($data) ? $data : [];
        } catch (Exception $e) {
            echo "[警告] 日期 {$ymd} 数据获取失败：" . $e->getMessage() . PHP_EOL;
            $perDayData[$ymd] = [];
        }

        $total = 0;
        foreach ($perDayData[$ymd] as $chPrograms) {
            if (is_array($chPrograms)) $total += count($chPrograms);
        }
        $dayTotalCount[$ymd] = $total;
    }
    $todayTotal = $dayTotalCount[$todayYmd] ?? 1;

    // 确保输出目录存在
    $outputDir = dirname(OUTPUT_FILE);
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

    // 初始化 XML 写入器
    $xml = new XMLWriter();
    $xml->openUri(OUTPUT_FILE);
    $xml->startDocument('1.0', 'UTF-8');
    $xml->startElement('tv');
    $xml->writeAttribute('source-info-url', PAGE_URL);
    $xml->writeAttribute('source-info-name', 'ELTA OTT');
    $xml->writeAttribute('generator-info-name', 'elta-epg-php');

    // 写入频道元数据（名称标准化）
    foreach ($channels as $ch) {
        $xml->startElement('channel');
        $xml->writeAttribute('id', $ch['sourceId']);
        $xml->startElement('display-name');
        $xml->writeAttribute('lang', 'zh');
        $xml->text(normalize_channel_name($ch['name']));
        $xml->endElement();
        $xml->endElement();
    }

    $validChannelCount = 0;
    $totalProgramCount = 0;

    // 写入节目数据
    foreach ($channels as $ch) {
        $sid = $ch['sourceId'];
        $seenKeys = [];
        $programList = [];

        foreach ($dayList as $ymd) {
            if ($ymd < $todayYmd) continue;
            if ($dayTotalCount[$ymd] < $todayTotal * 0.6) continue;

            $dayPrograms = $perDayData[$ymd][$sid] ?? [];
            if (!is_array($dayPrograms) || empty($dayPrograms)) continue;

            $todayChPrograms = $perDayData[$todayYmd][$sid] ?? [];
            $chBaseline = count($todayChPrograms) ?: 1;
            if (count($dayPrograms) < $chBaseline * 0.6) continue;

            foreach ($dayPrograms as $p) {
                $st = (int)($p['start_time'] ?? 0);
                $et = (int)($p['end_time'] ?? 0);
                $title = t2s_convert(trim((string)($p['program_desc'] ?? '')));

                if ($st <= 0 || $et <= 0 || $et <= $st) continue;
                if (empty($title)) continue;

                $uniqueKey = $st . '|' . $title;
                if (isset($seenKeys[$uniqueKey])) continue;
                $seenKeys[$uniqueKey] = true;

                $programList[] = ['start' => $st, 'stop' => $et, 'title' => $title];
            }
        }

        if (empty($programList)) continue;
        $validChannelCount++;
        $totalProgramCount += count($programList);

        usort($programList, fn($a, $b) => $a['start'] - $b['start']);

        foreach ($programList as $prog) {
            $startDt = (new DateTime())->setTimestamp($prog['start'])->setTimezone(TIMEZONE);
            $stopDt  = (new DateTime())->setTimestamp($prog['stop'])->setTimezone(TIMEZONE);

            $xml->startElement('programme');
            $xml->writeAttribute('start', $startDt->format('YmdHis') . ' +0800');
            $xml->writeAttribute('stop',  $stopDt->format('YmdHis')  . ' +0800');
            $xml->writeAttribute('channel', $sid);

            $xml->startElement('title');
            $xml->writeAttribute('lang', 'zh');
            $xml->text($prog['title']);
            $xml->endElement();

            $xml->startElement('desc');
            $xml->writeAttribute('lang', 'zh');
            $xml->text('');
            $xml->endElement();

            $xml->endElement();
        }
    }

    $xml->endElement();
    $xml->endDocument();
    $xml->flush();

    echo PHP_EOL . "=== 生成完成 ===" . PHP_EOL;
    echo "有效频道：{$validChannelCount}/" . count($channels) . " 个" . PHP_EOL;
    echo "节目总数：{$totalProgramCount} 条（本源无简介）" . PHP_EOL;
    echo "输出路径：" . OUTPUT_FILE . PHP_EOL;
}

// 入口执行
try {
    generate_epg();
} catch (Exception $e) {
    echo "[致命错误] " . $e->getMessage() . PHP_EOL;
    exit(1);
}
