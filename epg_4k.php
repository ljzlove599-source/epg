<?php
// ===================== 配置分离（修改这里即可，无需动核心逻辑） =====================
// 【多数据源配置区】支持多个epg gz源，enabled=false会跳过该源
$sourceList = [
    [
        'name' => 'mxdyeah',
        'url'  => 'https://tv.evorit.dpdns.org/epgphp/t.xml.gz',
        'enabled' => true
    ],
    [
        'name' => '山东有线',
        'url'  => 'https://raw.githubusercontent.com/z2392693938/sd-epg/main/sdyx.xml.gz',
        'enabled' => true
    ],
    [
        'name' => 'erw.cc',
        'url'  => 'https://raw.githubusercontent.com/kuke31/xmlgz/main/all.xml.gz',
        'enabled' => true
    ],
    [
        'name' => 'catvod',
        'url'  => 'https://epg.catvod.com/epg.xml.gz',
        'enabled' => true
    ],
    [
        'name' => 'catvod',
        'url'  => 'https://liliu.serv00.net/epg/download.php/cn.xml.gz',
        'enabled' => true
    ]
];

// 1. 路径配置（GitHub Actions 使用相对路径，仓库根目录输出）
$basePath = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$outputDir = $basePath . 'epg/';
$logDir = $basePath . 'logs/';

// 输出文件配置
$outputXmlGzFile = $outputDir . 'epg_4k.xml.gz';
$outputXmlRawFile = $outputDir . 'epg_4k.xml'; // GitHub版本默认开启原始xml输出

// 日志配置：按天自动生成
$logFilePrefix = 'epg_4k_';
$logFile = $logDir . $logFilePrefix . date('Ymd') . '.log';

// 2. 频道筛选规则（支持 * ? 通配符，忽略大小写）
$targetChannelPatterns = [
    '4K*', '家庭理财', '苏州4K', '淘4K', '凤凰电影'
];
// 黑名单规则（白名单命中后再排除，优先级更高）
$excludeChannelPatterns = [];

// 3. 网络&运行配置
$timeout = 30;
$httpRetry = 3; // 下载重试次数
$gzipCompressLevel = 6; // 压缩等级 1最快-9最小，6均衡
$maxDownloadSize = 200 * 1024 * 1024; // 单文件最大下载大小，防止异常占盘
$downloadBufferSize = 8192; // 下载缓冲区大小

// 4. 输出元数据配置
$generatorName = 'PHP EPG Filter - MultiSource Merge Programme (No Overlap)';
$sourceName = '4k Local Channels Filtered EPG - Multi Source Programme Merge';
// ===================== 配置结束 =====================

// --------------- 通用工具函数 ---------------
/**
 * 清理历史日志，仅保留当天的日志文件
 */
function cleanOldLogs(): void {
    global $logDir, $logFilePrefix;
    if (!is_dir($logDir)) return;
    $today = date('Ymd');
    $pattern = $logDir . $logFilePrefix . '*.log';
    $logFiles = glob($pattern);

    if (empty($logFiles)) return;

    foreach ($logFiles as $file) {
        $basename = basename($file, '.log');
        $dateStr = substr($basename, strlen($logFilePrefix));
        if ($dateStr !== $today) {
            @unlink($file);
        }
    }
}

/**
 * 日志写入：同时输出控制台 + 写入日志文件
 */
function writeLog($message, $level = 'INFO') {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logContent = "[$timestamp] [$level] $message" . PHP_EOL;
    echo $logContent;
    file_put_contents($logFile, $logContent, FILE_APPEND | LOCK_EX);
}

/**
 * 目录创建+权限校验
 */
function checkDirWritable($dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        writeLog("自动创建目录: $dir");
    }
    if (!is_writable($dir)) {
        writeLog("目录权限不足，不可写入: $dir", 'FATAL');
        exit(1);
    }
    return true;
}

/**
 * 校验文件是否为有效Gzip格式（检查魔数）
 */
function isValidGzip(string $filePath): bool {
    if (!file_exists($filePath) || filesize($filePath) < 2) return false;
    $fp = fopen($filePath, 'rb');
    if (!$fp) return false;
    $magic = fread($fp, 2);
    fclose($fp);
    return $magic === "\x1f\x8b";
}

/**
 * 流式下载远程gz文件（分段写入+大小限制+原子替换+格式校验）
 */
function downloadRemoteGz(string $url, string $savePath, int $retry, int $timeout, int $maxSize, int $bufferSize): bool {
    $tmpPath = $savePath . '.tmp';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'user_agent' => 'Mozilla/5.0 (Ubuntu; GitHub Actions) PHP-EPG-Filter',
            'follow_location' => 3,
            'max_redirects' => 5
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    for ($i = 1; $i <= $retry; $i++) {
        writeLog("第{$i}次尝试下载源文件: $url");
        $downloaded = 0;
        try {
            $remoteFp = fopen($url, 'rb', false, $ctx);
            if (!$remoteFp) throw new Exception("远程连接失败");
            $localFp = fopen($tmpPath, 'wb');
            if (!$localFp) throw new Exception("临时文件创建失败");

            while (!feof($remoteFp)) {
                $chunk = fread($remoteFp, $bufferSize);
                if ($chunk === false) break;
                $downloaded += strlen($chunk);
                if ($downloaded > $maxSize) {
                    fclose($remoteFp);
                    fclose($localFp);
                    @unlink($tmpPath);
                    throw new Exception("文件大小超过上限: {$maxSize}字节");
                }
                fwrite($localFp, $chunk);
            }
            fclose($remoteFp);
            fclose($localFp);

            if (!isValidGzip($tmpPath)) {
                @unlink($tmpPath);
                throw new Exception("下载文件不是有效Gzip格式，疑似CDN错误页");
            }
            rename($tmpPath, $savePath);
            writeLog("源gz文件下载完成，大小：{$downloaded}字节");
            return true;
        } catch (Exception $e) {
            @unlink($tmpPath);
            writeLog("第{$i}次下载失败: " . $e->getMessage(), 'WARN');
            sleep(2);
        }
    }
    writeLog("所有下载重试耗尽，拉取远程源失败", 'ERROR');
    return false;
}

/**
 * 通配符匹配频道名（支持多名称匹配+黑名单过滤）
 */
function isChannelMatch(string $channelName, array $includePatterns, array $excludePatterns): bool {
    $matched = false;
    foreach ($includePatterns as $pattern) {
        if (fnmatch($pattern, $channelName, FNM_CASEFOLD)) {
            $matched = true;
            break;
        }
    }
    if (!$matched) return false;
    foreach ($excludePatterns as $pattern) {
        if (fnmatch($pattern, $channelName, FNM_CASEFOLD)) {
            return false;
        }
    }
    return true;
}

/**
 * 读取gzip文件原始XML文本
 */
function readGzXml(string $gzPath): string {
    if (!file_exists($gzPath)) return '';
    $handle = gzopen($gzPath, 'rb');
    if (!$handle) {
        writeLog("解压gz文件失败: $gzPath", 'FATAL');
        exit(1);
    }
    $xmlRaw = '';
    while (!gzeof($handle)) {
        $xmlRaw .= gzread($handle, 4096 * 10);
    }
    gzclose($handle);
    $xmlRaw = preg_replace('/^\xEF\xBB\xBF/', '', $xmlRaw);
    $xmlRaw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xmlRaw);
    return $xmlRaw;
}

/**
 * 安全XML转义
 */
function xmlSafeStr($str): string {
    return htmlspecialchars((string)$str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * 递归复制全部子节点
 */
function copyAllXmlNodes(SimpleXMLElement $source, SimpleXMLElement $dest): void {
    foreach ($source->attributes() as $k => $v) {
        $dest->addAttribute((string)$k, xmlSafeStr((string)$v));
    }
    foreach ($source->children() as $child) {
        $newChild = $dest->addChild($child->getName(), xmlSafeStr((string)$child));
        copyAllXmlNodes($child, $newChild);
    }
}

/**
 * 将文本直接保存为 gz 压缩文件
 */
function saveStringToGz(string $content, string $gzPath, int $level = 6): int|false {
    $gzFp = gzopen($gzPath, 'wb' . $level);
    if (!$gzFp) return false;
    $writeLen = gzwrite($gzFp, $content);
    gzclose($gzFp);
    return $writeLen;
}

/**
 * 解析EPG标准时间 YYYYMMDDHHMMSS 转时间戳
 */
function parseEpgTime(string $t): ?int {
    $t = trim($t);
    if (strlen($t) < 14) return null;
    // 去掉时区部分，只解析前14位
    $dt = substr($t, 0, 14);
    $ts = DateTime::createFromFormat('YmdHis', $dt);
    if (!$ts) return null;
    return $ts->getTimestamp();
}

/**
 * 判断两个时间段是否重叠
 * @param int $s1 节目A开始
 * @param int $e1 节目A结束
 * @param int $s2 节目B开始
 * @param int $e2 节目B结束
 * @return bool true=重叠
 */
function timeRangeOverlap(int $s1, int $e1, int $s2, int $e2): bool {
    // 重叠判定：不是（A在B前面 或者 A在B后面）
    return !($e1 <= $s2 || $e2 <= $s1);
}

// --------------- 初始化流程 ---------------
$startTime = microtime(true);
checkDirWritable($outputDir);
checkDirWritable($logDir);
cleanOldLogs();
writeLog('====== EPG筛选脚本启动【多数据源，同ID节目合并-不重叠叠加版】 ======');

ini_set('default_socket_timeout', $timeout);
ini_set('memory_limit', '1024M');
libxml_use_internal_errors(true);
libxml_disable_entity_loader(false);

$newTv = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><tv></tv>');
$newTv->addAttribute('generator-info-name', $generatorName);
$newTv->addAttribute('source-info-name', $sourceName);

// 频道索引：cid => true （只存第一个源的channel定义）
$matchedChannelIds = [];
$foundChannelList = [];
$matchChannelTotal = 0;

// 关键：每个频道已存在的节目时间段列表
// $channelProgramRanges['cid'] = [ ['start'=>ts, 'stop'=>ts], ... ]
$channelProgramRanges = [];

$programTotal = 0;
$skipOverlapCount = 0;

// 循环遍历每一个数据源
foreach ($sourceList as $src) {
    if (!$src['enabled']) {
        writeLog("跳过数据源：{$src['name']}");
        continue;
    }
    writeLog("===== 开始处理数据源【{$src['name']}】 URL: {$src['url']} =====");

    $tmpGzFile = tempnam(sys_get_temp_dir(), 'epg_dl_multi_');
    $dlOk = downloadRemoteGz(
        $src['url'],
        $tmpGzFile,
        $httpRetry,
        $timeout,
        $maxDownloadSize,
        $downloadBufferSize
    );
    if (!$dlOk) {
        @unlink($tmpGzFile);
        writeLog("数据源【{$src['name']}】下载失败，继续下一个源", 'ERROR');
        continue;
    }

    $xmlRawText = readGzXml($tmpGzFile);
    if (empty($xmlRawText)) {
        @unlink($tmpGzFile);
        writeLog("数据源【{$src['name']}】解压后XML为空，跳过", 'ERROR');
        continue;
    }
    $sourceXml = simplexml_load_string($xmlRawText);
    if ($sourceXml === false) {
        $errs = libxml_get_errors();
        foreach ($errs as $e) writeLog("数据源【{$src['name']}】XML解析错误：{$e->message} 行{$e->line}", 'ERROR');
        @unlink($tmpGzFile);
        continue;
    }
    writeLog("数据源【{$src['name']}】加载成功，开始频道筛选");

    // 频道筛选：只新增还没收录的cid
    foreach ($sourceXml->channel as $chNode) {
        $cid = trim((string)$chNode['id']);
        $displayName = trim((string)$chNode->{'display-name'}[0] ?? '');
        if (empty($cid) || empty($displayName)) continue;
        if (!isChannelMatch($displayName, $targetChannelPatterns, $excludeChannelPatterns)) continue;
        if (isset($matchedChannelIds[$cid])) continue;

        $matchedChannelIds[$cid] = true;
        $channelProgramRanges[$cid] = []; // 初始化该频道的时间段存储
        $matchChannelTotal++;
        $foundChannelList[] = "$displayName (ID:$cid) [来源:{$src['name']}]";
        writeLog("匹配频道【{$src['name']}】：$displayName | ChannelID: $cid");
        $newCh = $newTv->addChild('channel');
        copyAllXmlNodes($chNode, $newCh);
    }

    // 节目筛选 + 重叠判断
    $srcAddProg = 0;
    $srcSkipProg = 0;
    foreach ($sourceXml->programme as $progNode) {
        $progCid = trim((string)$progNode['channel']);
        if (!isset($matchedChannelIds[$progCid])) continue;

        $startStr = trim((string)$progNode['start'] ?? '');
        $stopStr  = trim((string)$progNode['stop'] ?? '');
        $sTs = parseEpgTime($startStr);
        $eTs = parseEpgTime($stopStr);
        if ($sTs === null || $eTs === null || $eTs <= $sTs) {
            writeLog("节目时间解析失败，跳过 cid:$progCid start:$startStr stop:$stopStr", 'WARN');
            continue;
        }

        // 和已有时间段对比，检查冲突
        $hasOverlap = false;
        foreach ($channelProgramRanges[$progCid] as $range) {
            if (timeRangeOverlap($sTs, $eTs, $range['start'], $range['stop'])) {
                $hasOverlap = true;
                break;
            }
        }
        if ($hasOverlap) {
            $srcSkipProg++;
            $skipOverlapCount++;
            continue;
        }

        // 无重叠，加入结果
        $newProg = $newTv->addChild('programme');
        copyAllXmlNodes($progNode, $newProg);
        $channelProgramRanges[$progCid][] = [
            'start' => $sTs,
            'stop'  => $eTs
        ];
        $srcAddProg++;
        $programTotal++;
    }
    writeLog("数据源【{$src['name']}】节目：新增 $srcAddProg 条，重叠跳过 $srcSkipProg 条");
    @unlink($tmpGzFile);
}

writeLog("全部数据源处理完成，共匹配到 $matchChannelTotal 个唯一频道");
writeLog("节目统计：合计新增 $programTotal 条，因时间重叠丢弃 $skipOverlapCount 条");
if (!empty($foundChannelList)) {
    writeLog("匹配频道列表：" . implode(' | ', $foundChannelList));
} else {
    writeLog("未匹配到任何符合通配规则的频道，请检查频道名称规则", 'WARNING');
}

$finalXml = $newTv->asXML();
if (empty($finalXml)) {
    writeLog("生成目标XML为空", 'FATAL');
    exit(1);
}
$checkXml = simplexml_load_string($finalXml);
if ($checkXml === false) {
    writeLog("警告：生成的XML格式存在异常，终止保存", 'WARN');
    exit(1);
}

$writeGzSize = saveStringToGz($finalXml, $outputXmlGzFile, $gzipCompressLevel);
if ($writeGzSize === false) {
    writeLog("写入压缩EPG文件失败：$outputXmlGzFile", 'FATAL');
    exit(1);
}

// 原始xml输出
if (isset($outputXmlRawFile) && !empty($outputXmlRawFile)) {
    $writeRawSize = file_put_contents($outputXmlRawFile, $finalXml, LOCK_EX);
    if ($writeRawSize === false) {
        writeLog("写入原始XML文件失败", 'WARN');
    } else {
        writeLog("原始XML保存成功，大小：$writeRawSize 字节");
    }
}

// 统计收尾
$endTime = microtime(true);
$costTime = round($endTime - $startTime, 3);
$peakMemory = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
$outputSize = file_exists($outputXmlGzFile) ? filesize($outputXmlGzFile) : 0;

writeLog("任务执行成功！压缩EPG文件：$outputXmlGzFile");
writeLog("统计：匹配频道=$matchChannelTotal | 节目条数=$programTotal | 重叠丢弃=$skipOverlapCount");
writeLog("输出文件大小：{$outputSize} 字节 | 执行耗时：{$costTime} 秒 | 内存峰值：{$peakMemory} MB");
writeLog('====== 脚本正常结束 ======' . PHP_EOL);
exit(0);
?>
