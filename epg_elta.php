<?php
/**
 * 台湾 ELTA OTT 节目单数据源（eltaott.tv）—— PHP 移植版
 * 输出标准 XMLTV 格式 EPG 文件：epg/epg_elta.xml
 * 
 * ⚠️ 运行环境要求：必须在海外网络（如 GitHub Actions 海外机房）执行
 * 境内 DNS 会污染 CDN 域名 piceltaott-elta.cdn.hinet.net，导致数据请求失败
 */

define('BASE_URL', 'https://piceltaott-elta.cdn.hinet.net/production/json');
define('PAGE_URL', 'https://eltaott.tv/channel/program_detail');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36');
define('PROBE_BACK', 3);   // 向前探测天数（仅用于边界校验）
define('PROBE_AHEAD', 7);  // 向后探测天数
define('OUTPUT_FILE', __DIR__ . '/epg/epg_elta.xml');
define('TIMEZONE', new DateTimeZone('Asia/Taipei'));

// 频道列表静态缓存（一天内仅请求一次）
static $channelCache = null;

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
        CURLOPT_ENCODING => 'gzip,deflate', // 自动解压 gzip，对应原 --compressed 参数
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
        if ($i < $retries) usleep(500000); // 重试前等待 0.5s
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
    if ($channelCache !== null) {
        return $channelCache;
    }

    $list = fetch_json(BASE_URL . '/channel/channel_list.json');
    if (!is_array($list)) {
        throw new Exception('频道列表格式异常：非数组');
    }

    $now = time();
    $out = [];

    foreach ($list as $item) {
        // 坑⑤：上架状态 + 显示状态双过滤
        if ((int)($item['cl_status'] ?? 0) !== 1) continue;
        if ((int)($item['cl_display'] ?? 0) !== 1) continue;

        // 档期类型判断：1=常驻上架，2=档期内显示
        $showType = (int)($item['cp_show_type'] ?? 0);
        $stStr = (string)($item['cp_schedule_from_datetime'] ?? '');
        $etStr = (string)($item['cp_schedule_to_datetime'] ?? '');

        $inWindow = true;
        if ($stStr && $etStr && strpos($stStr, '0000') !== 0) {
            $st = DateTime::createFromFormat('Y-m-d H:i:s', $stStr, TIMEZONE)->getTimestamp();
            $et = DateTime::createFromFormat('Y-m-d H:i:s', $etStr, TIMEZONE)->getTimestamp();
            $inWindow = ($st <= $now && $et > $now);
        }

        if (!($showType === 1 || ($showType === 2 && $inWindow))) {
            continue;
        }

        // 坑⑥：sourceId 使用 cl_num（节目 JSON 的 key 也是 cl_num）
        $out[] = [
            'sourceId' => (string)$item['cl_num'],
            'name'     => trim((string)($item['cl_title'] ?? '')),
            'num'      => (string)$item['cl_num'],
            'order'    => (int)($item['cl_order'] ?? 0),
            'desc'     => trim((string)($item['cl_dec'] ?? ''))
        ];
    }

    // 按前端排序号升序排列
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
    if (empty($channels)) {
        throw new Exception('未获取到任何有效频道');
    }

    // 1. 生成探测日期范围
    $dayList = [];
    for ($i = -PROBE_BACK; $i <= PROBE_AHEAD; $i++) {
        $dayList[] = taipei_ymd($i);
    }
    $todayYmd = taipei_ymd(0);

    // 2. 预加载所有日期的全量节目数据（同一天仅请求一次）
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

        // 统计当日全平台节目总数（用于半成品日期判定）
        $total = 0;
        foreach ($perDayData[$ymd] as $chPrograms) {
            if (is_array($chPrograms)) $total += count($chPrograms);
        }
        $dayTotalCount[$ymd] = $total;
    }

    $todayTotal = $dayTotalCount[$todayYmd] ?? 1;

    // 3. 确保输出目录存在
    $outputDir = dirname(OUTPUT_FILE);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    // 4. 初始化 XMLWriter（流式输出，低内存占用）
    $xml = new XMLWriter();
    $xml->openUri(OUTPUT_FILE);
    $xml->startDocument('1.0', 'UTF-8');
    $xml->startElement('tv');
    $xml->writeAttribute('source-info-url', PAGE_URL);
    $xml->writeAttribute('source-info-name', 'ELTA OTT');
    $xml->writeAttribute('generator-info-name', 'elta-epg-php');

    // 5. 写入频道元数据
    foreach ($channels as $ch) {
        $xml->startElement('channel');
        $xml->writeAttribute('id', $ch['sourceId']);
        $xml->startElement('display-name');
        $xml->writeAttribute('lang', 'zh');
        $xml->text($ch['name']);
        $xml->endElement();
        $xml->endElement();
    }

    $validChannelCount = 0;
    $totalProgramCount = 0;

    // 6. 写入每个频道的节目数据
    foreach ($channels as $ch) {
        $sid = $ch['sourceId'];
        $seenKeys = []; // 坑③：全局去重，解决广播日跨天重叠
        $programList = [];

        foreach ($dayList as $ymd) {
            // 仅统计今天及未来日期
            if ($ymd < $todayYmd) continue;

            // 坑④：跳过半成品日期（总条数不足当天 60%）
            if ($dayTotalCount[$ymd] < $todayTotal * 0.6) continue;

            $dayPrograms = $perDayData[$ymd][$sid] ?? [];
            if (!is_array($dayPrograms) || empty($dayPrograms)) continue;

            // 单台维度的半成品判定（该台当天条数不足该台当天基准 60%）
            $todayChPrograms = $perDayData[$todayYmd][$sid] ?? [];
            $chBaseline = count($todayChPrograms) ?: 1;
            if (count($dayPrograms) < $chBaseline * 0.6) continue;

            foreach ($dayPrograms as $p) {
                // 坑②：时间为 Unix 秒级时间戳
                $st = (int)($p['start_time'] ?? 0);
                $et = (int)($p['end_time'] ?? 0);
                // 坑①：program_desc 是节目标题，非简介
                $title = trim((string)($p['program_desc'] ?? ''));

                // 有效性校验
                if ($st <= 0 || $et <= 0 || $et <= $st) continue;
                if (empty($title)) continue;

                // 跨天重叠去重
                $uniqueKey = $st . '|' . $title;
                if (isset($seenKeys[$uniqueKey])) continue;
                $seenKeys[$uniqueKey] = true;

                $programList[] = [
                    'start' => $st,
                    'stop'  => $et,
                    'title' => $title
                ];
            }
        }

        if (empty($programList)) continue;
        $validChannelCount++;
        $totalProgramCount += count($programList);

        // 按开始时间升序排列
        usort($programList, fn($a, $b) => $a['start'] - $b['start']);

        // 写入单条节目
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

            // 坑①：本源无节目简介，desc 字段留空
            $xml->startElement('desc');
            $xml->writeAttribute('lang', 'zh');
            $xml->text('');
            $xml->endElement();

            $xml->endElement(); // programme
        }
    }

    $xml->endElement(); // tv
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
