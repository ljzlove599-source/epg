<?php
/**
 * CCTV EPG 抓取生成器（GitHub Actions 适配版）
 * 输出：./epg/cntv.xml.gz
 */
declare(strict_types=1);

// ========== 全局环境适配 ==========
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1G');
date_default_timezone_set('Asia/Shanghai');

// 前置扩展检查
$requiredExtensions = ['curl', 'xmlwriter', 'zlib', 'json', 'mbstring'];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        die("❌ 运行缺少必要 PHP 扩展：{$ext}" . PHP_EOL);
    }
}

// ========== 配置区 ==========
const HISTORY_DAYS        = 0;
const FUTURE_DAYS         = 7;
const MAX_CONCURRENT      = 8;
const BATCH_SIZE          = 40;
const MAX_RETRY_TIMES     = 3;
const REQUEST_DELAY_US    = 200000;
const RETRY_DELAY_US      = 800000;
const BATCH_INTERVAL_US   = 1000000;
const GZIP_COMPRESS_LEVEL = 6;

// 输出目录：脚本同目录下的 epg 文件夹
const OUTPUT_DIR = __DIR__ . '/epg';

const UA_POOL = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
];

const CHANNELS = [
    'cctv1'        => 'CCTV-1 综合',
    'cctv2'        => 'CCTV-2 财经',
    'cctv3'        => 'CCTV-3 综艺',
    'cctv4'        => 'CCTV-4 中文国际',
    'cctv5'        => 'CCTV-5 体育',
    'cctv5plus'    => 'CCTV-5+ 体育赛事',
    'cctv6'        => 'CCTV-6 电影',
    'cctv7'        => 'CCTV-7 国防军事',
    'cctv8'        => 'CCTV-8 电视剧',
    'cctvjilu'     => 'CCTV-9 纪录',
    'cctv10'       => 'CCTV-10 科教',
    'cctv11'       => 'CCTV-11 戏曲',
    'cctv12'       => 'CCTV-12 社会与法',
    'cctv13'       => 'CCTV-13 新闻',
    'cctvchild'    => 'CCTV-14 少儿',
    'cctv15'       => 'CCTV-15 音乐',
    'cctv16'       => 'CCTV-16 奥林匹克',
    'cctv17'       => 'CCTV-17 农业农村',
    'cctveurope'   => 'CCTV4欧洲',
    'cctvamerica'  => 'CCTV4美洲',
    'cctv4k'       => 'CCTV-4K 超高清',
    'cctv8k'       => 'CCTV-8K 超高清',
    'shijiedili'   => '世界地理',
    'dianshigouwu' => '中视购物',
    'guofang'      => '国防军事',
    'taiqiu'       => '央视台球',
    'jingpin'      => '央视文化精品',
    'shishang'     => '女性时尚',
    'hjjc'         => '怀旧剧场',
    'zhinan'       => '电视指南',
    'diyijuchang'  => '第一剧场',
    'fyjc'         => '风云剧场',
    'cctvyule'     => 'CCTV娱乐',
    'cctvxiqu'     => 'CCTV戏曲',
    'cctvfyzq'     => '风云足球',
    'cctvgaowang'  => '高尔夫网球',
    'xinkedongman' => '新科动漫',
    'cctvqixiang'  => 'CCTV气象',
    'cctvlaogushi' => '老故事',
    'fyyy'         => '风云音乐',
    'wsjk'         => '卫生健康',
];

// ========== 工具函数 ==========
function getDateList(int $historyDays, int $futureDays): array
{
    $base = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
    $dates = [];
    for ($i = $historyDays; $i > 0; $i--) {
        $dates[] = (clone $base)->modify("-{$i} day")->format('Ymd');
    }
    $dates[] = $base->format('Ymd');
    for ($i = 1; $i <= $futureDays; $i++) {
        $dates[] = (clone $base)->modify("+{$i} day")->format('Ymd');
    }
    return $dates;
}

function formatTimestamp(int $ts): string
{
    if ($ts > 9999999999) {
        $ts = intval($ts / 1000);
    }
    $dt = new DateTime("@{$ts}", new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Asia/Shanghai'));
    return $dt->format('YmdHis') . ' +0800';
}

function formatShowTime(string $dateStr, ?string $timeStr): string
{
    if (empty($timeStr)) return '';
    $timePart = substr(str_replace(':', '', trim($timeStr)), 0, 4);
    if (strlen($timePart) !== 4) return '';
    return $dateStr . $timePart . '00 +0800';
}

function getCurlOpts(string $url): array
{
    return [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => UA_POOL[array_rand(UA_POOL)],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FORBID_REUSE   => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Referer: https://tv.cctv.com/',
        ],
    ];
}

// ========== 抓取主类 ==========
class EpgFetcher
{
    private array $cache = [];
    private int $successCount = 0;
    private int $failCount = 0;
    private int $programCount = 0;

    public function parseInterfaceA(array $data, string $channel): ?array
    {
        $programList = $data[$channel]['program'] ?? null;
        if (empty($programList) || !is_array($programList)) {
            return null;
        }
        $programs = [];
        foreach ($programList as $p) {
            $st = (int)($p['st'] ?? 0);
            $et = (int)($p['et'] ?? 0);
            $title = $p['t'] ?? '未知节目';
            if ($st <= 0 || $et <= 0) continue;
            $startStr = formatTimestamp($st);
            $endStr   = formatTimestamp($et);
            if ($startStr > $endStr) {
                $endTs = ($et > 9999999999) ? intval($et / 1000) : $et;
                $endDt = new DateTime("@{$endTs}", new DateTimeZone('UTC'));
                $endDt->setTimezone(new DateTimeZone('Asia/Shanghai'));
                $endDt->modify('+1 day');
                $endStr = $endDt->format('YmdHis') . ' +0800';
            }
            $programs[] = [
                'title' => $title,
                'start' => $startStr,
                'stop'  => $endStr
            ];
        }
        return $programs ?: null;
    }

    public function parseInterfaceB(array $data, string $channel, string $date): ?array
    {
        $programList = $data['data'][$channel]['list'] ?? null;
        if (empty($programList) || !is_array($programList)) {
            return null;
        }
        $temp = [];
        foreach ($programList as $p) {
            $title = $p['title'] ?? '未知节目';
            $startStr = formatShowTime($date, $p['showTime'] ?? null);
            if ($startStr === '') continue;
            $temp[] = ['title' => $title, 'start' => $startStr, 'stop' => ''];
        }
        if (empty($temp)) return null;
        usort($temp, fn(array $a, array $b) => strcmp($a['start'], $b['start']));
        for ($i = 0; $i < count($temp) - 1; $i++) {
            $temp[$i]['stop'] = $temp[$i + 1]['start'];
        }
        $last = &$temp[count($temp) - 1];
        try {
            $dt = DateTime::createFromFormat('YmdHis +0800', $last['start']);
            if ($dt !== false) {
                $dt->modify('+2 hours');
                $last['stop'] = $dt->format('YmdHis') . ' +0800';
            } else {
                $last['stop'] = $last['start'];
            }
        } catch (Throwable $e) {
            $last['stop'] = $last['start'];
        }
        return $temp;
    }

    public function fetchSingleTask(string $channel, string $date): ?array
    {
        $cacheKey = "{$channel}_{$date}";
        if (isset($this->cache[$cacheKey])) return $this->cache[$cacheKey];
        $urlA = "http://api.cntv.cn/epg/epginfo?c={$channel}&d={$date}";
        for ($attempt = 0; $attempt <= MAX_RETRY_TIMES; $attempt++) {
            $result = $this->doCurl($urlA);
            if ($result !== null) {
                $parsed = $this->parseInterfaceA($result, $channel);
                if (!empty($parsed)) {
                    $this->cache[$cacheKey] = $parsed;
                    return $parsed;
                }
            }
            usleep(RETRY_DELAY_US * ($attempt + 1));
        }
        $urlB = "https://api.cntv.cn/epg/getEpgInfoByChannelNew?c={$channel}&serviceId=tvcctv&d={$date}&t=json";
        for ($attempt = 0; $attempt <= MAX_RETRY_TIMES; $attempt++) {
            $result = $this->doCurl($urlB);
            if ($result !== null) {
                $parsed = $this->parseInterfaceB($result, $channel, $date);
                if (!empty($parsed)) {
                    $this->cache[$cacheKey] = $parsed;
                    return $parsed;
                }
            }
            usleep(RETRY_DELAY_US * ($attempt + 1));
        }
        $this->cache[$cacheKey] = null;
        return null;
    }

    private function doCurl(string $url): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, getCurlOpts($url));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code !== 200 || !empty($err) || empty($resp)) return null;
        $json = json_decode($resp, true);
        return json_last_error() === JSON_ERROR_NONE ? $json : null;
    }

    public function fetchAllTasks(array $channelIds, array $dateList): void
    {
        $allTasks = [];
        foreach ($channelIds as $ch) {
            foreach ($dateList as $dt) {
                $key = "{$ch}_{$dt}";
                if (!isset($this->cache[$key])) {
                    $allTasks[] = [
                        'channel'    => $ch,
                        'date'       => $dt,
                        'retry'      => 0,
                        'interface'  => 'A'
                    ];
                }
            }
        }
        $total = count($allTasks);
        if ($total === 0) {
            echo "ℹ️ 全部数据已缓存，无需请求接口\n";
            return;
        }
        echo "📋 待抓取总任务数：{$total}\n";
        $chunks = array_chunk($allTasks, BATCH_SIZE);
        $finished = 0;
        foreach ($chunks as $index => $chunk) {
            $batchNo = $index + 1;
            echo PHP_EOL . "===== 第 {$batchNo}/" . count($chunks) . " 批（本批" . count($chunk) . "个）=====\n";
            $this->processBatch($chunk, $total, $finished);
            echo "本批完成，当前总进度：{$finished}/{$total}\n";
            usleep(BATCH_INTERVAL_US);
        }
        echo PHP_EOL . "===== 全部抓取任务处理完毕 =====\n";
    }

    private function processBatch(array $chunk, int $total, int &$finished): void
    {
        $mh = curl_multi_init();
        $running = [];
        $retryQueue = [];
        $ptr = 0;
        while ($ptr < count($chunk) || count($running) > 0 || !empty($retryQueue)) {
            while (count($running) < MAX_CONCURRENT && $ptr < count($chunk)) {
                $task = $chunk[$ptr];
                $this->addCurlHandle($mh, $running, $task);
                $ptr++;
                usleep(REQUEST_DELAY_US);
            }
            while (count($running) < MAX_CONCURRENT && !empty($retryQueue)) {
                $task = array_shift($retryQueue);
                if ($task['retry'] >= MAX_RETRY_TIMES) {
                    if ($task['interface'] === 'A') {
                        $task['interface'] = 'B';
                        $task['retry'] = 0;
                    } else {
                        $this->failCount++;
                        $finished++;
                        echo "❌ {$task['channel']} {$task['date']} 重试耗尽，丢弃 ({$finished}/{$total})\n";
                        continue;
                    }
                } else {
                    $task['retry']++;
                }
                $this->addCurlHandle($mh, $running, $task);
                usleep(RETRY_DELAY_US);
            }
            $active = null;
            curl_multi_exec($mh, $active);
            curl_multi_select($mh, 0.3);
            while ($info = curl_multi_info_read($mh)) {
                $chHandle = $info['handle'];
                $key = (int)$chHandle;
                if (!isset($running[$key])) continue;
                $task = $running[$key];
                unset($running[$key]);
                $resp = curl_multi_getcontent($chHandle);
                $code = curl_getinfo($chHandle, CURLINFO_HTTP_CODE);
                $err = curl_error($chHandle);
                curl_multi_remove_handle($mh, $chHandle);
                curl_close($chHandle);
                $cacheKey = "{$task['channel']}_{$task['date']}";
                $success = false;
                if ($code === 200 && empty($err) && !empty($resp)) {
                    $json = json_decode($resp, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $parsed = null;
                        if ($task['interface'] === 'A') {
                            $parsed = $this->parseInterfaceA($json, $task['channel']);
                        } else {
                            $parsed = $this->parseInterfaceB($json, $task['channel'], $task['date']);
                        }
                        if (!empty($parsed)) {
                            $this->cache[$cacheKey] = $parsed;
                            $this->successCount++;
                            $finished++;
                            $success = true;
                            echo "✅ {$task['channel']} {$task['date']} 接口{$task['interface']} 成功 {$finished}/{$total}\n";
                        }
                    }
                }
                if (!$success) {
                    $retryQueue[] = $task;
                }
            }
        }
        curl_multi_close($mh);
    }

    private function addCurlHandle($mh, array &$running, array $task): void
    {
        if ($task['interface'] === 'A') {
            $url = "http://api.cntv.cn/epg/epginfo?c={$task['channel']}&d={$task['date']}";
        } else {
            $url = "https://api.cntv.cn/epg/getEpgInfoByChannelNew?c={$task['channel']}&serviceId=tvcctv&d={$task['date']}&t=json";
        }
        $ch = curl_init();
        curl_setopt_array($ch, getCurlOpts($url));
        curl_multi_add_handle($mh, $ch);
        $running[(int)$ch] = $task;
    }

    public function writeChannelNodes(XMLWriter $writer): void
    {
        foreach (CHANNELS as $id => $name) {
            $writer->startElement('channel');
            $writer->writeAttribute('id', $id);
            $writer->startElement('display-name');
            $writer->writeAttribute('lang', 'zh');
            $writer->text($name);
            $writer->endElement();
            $writer->endElement();
        }
    }

    public function writeProgrammeNodes(XMLWriter $writer, array $channelIds, array $dateList): void
    {
        foreach ($channelIds as $channel) {
            foreach ($dateList as $date) {
                $key = "{$channel}_{$date}";
                $programs = $this->cache[$key] ?? null;
                if (empty($programs) || !is_array($programs)) continue;
                foreach ($programs as $p) {
                    if (empty($p['start']) || empty($p['stop'])) continue;
                    $writer->startElement('programme');
                    $writer->writeAttribute('start', $p['start']);
                    $writer->writeAttribute('stop', $p['stop']);
                    $writer->writeAttribute('channel', $channel);
                    $writer->startElement('title');
                    $writer->writeAttribute('lang', 'zh');
                    $writer->text($p['title']);
                    $writer->endElement();
                    $writer->endElement();
                    $this->programCount++;
                }
            }
        }
    }

    public function writeXmlFiles(string $gzPath, array $channelIds, array $dateList): void
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('tv');
        $writer->writeAttribute('generator-info-name', 'CCTV-EPG-Generator');
        $writer->writeAttribute('generator-info-url', 'https://api.cntv.cn');
        $this->writeChannelNodes($writer);
        $this->writeProgrammeNodes($writer, $channelIds, $dateList);
        $writer->endElement();
        $writer->endDocument();
        $xmlContent = $writer->outputMemory(true);

        // 二进制模式写入 gzip，避免换行符转换导致文件损坏
        $gz = gzopen($gzPath, 'wb' . GZIP_COMPRESS_LEVEL);
        if (!$gz) {
            throw new RuntimeException("无法创建输出文件：{$gzPath}");
        }
        gzwrite($gz, $xmlContent);
        gzclose($gz);

        // 生成后完整性校验
        $checkHandle = @gzopen($gzPath, 'rb');
        if (!$checkHandle) {
            @unlink($gzPath);
            throw new RuntimeException("生成的 gz 文件损坏，无法解压");
        }
        $header = gzread($checkHandle, 100);
        gzclose($checkHandle);
        if (empty($header) || !str_starts_with(trim($header), '<?xml')) {
            @unlink($gzPath);
            throw new RuntimeException("生成的文件内容无效，非合法 XML");
        }
    }

    public function getSuccessCount(): int { return $this->successCount; }
    public function getFailCount(): int    { return $this->failCount; }
    public function getProgramCount(): int { return $this->programCount; }
}

// ========== 主入口 ==========
function main(): void
{
    $start = microtime(true);
    $dateList = getDateList(HISTORY_DAYS, FUTURE_DAYS);
    $channelIds = array_keys(CHANNELS);

    echo "📅 抓取日期范围：" . count($dateList) . " 天\n";
    echo "📺 频道数量：" . count($channelIds) . " 个\n";
    echo "🔀 最大并发：" . MAX_CONCURRENT . "\n\n";

    // 自动创建输出目录
    if (!is_dir(OUTPUT_DIR)) {
        if (!mkdir(OUTPUT_DIR, 0755, true) && !is_dir(OUTPUT_DIR)) {
            die("❌ 无法创建输出目录：" . OUTPUT_DIR . PHP_EOL);
        }
    }

    $gzPath = OUTPUT_DIR . '/cntv.xml.gz';
    // 清理旧文件
    if (file_exists($gzPath)) {
        @unlink($gzPath);
    }

    try {
        $fetcher = new EpgFetcher();
        $fetcher->fetchAllTasks($channelIds, $dateList);
        $fetcher->writeXmlFiles($gzPath, $channelIds, $dateList);
    } catch (Throwable $e) {
        die("❌ 执行失败：" . $e->getMessage() . PHP_EOL);
    }

    echo "\n==================== 执行结果 ====================\n";
    echo "✅ API 请求成功：" . $fetcher->getSuccessCount() . " 次\n";
    echo "❌ 最终失败：" . $fetcher->getFailCount() . " 次\n";
    echo "📺 有效节目：" . $fetcher->getProgramCount() . " 条\n";
    echo "📁 输出文件：{$gzPath} (" . round(filesize($gzPath) / 1024, 1) . " KB)\n";
    echo "⏱️ 总耗时：" . round(microtime(true) - $start, 2) . " 秒\n";

    if ($fetcher->getProgramCount() === 0) {
        @unlink($gzPath);
        die("⚠️ 未获取到任何节目数据，已删除空文件\n");
    }

    echo "\n🎉 生成完成！\n";
}

main();


