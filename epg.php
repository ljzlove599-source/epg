<?php
/**
 * EPG XML数据处理脚本（GitHub Actions 适配版）
 * 功能：拉取/读取多个EPG数据源 → 解析XML → 频道映射 → 节目去重/时间修正 → 生成压缩XML文件
 * 适配：全相对路径、CI环境兼容、保留全部原有功能
 */

// ====================== 全局配置常量（全部自动适配相对路径） ======================
define('ROOT_PATH', __DIR__);
define('LOG_LEVEL', 'INFO');
define('LOG_FILE', ROOT_PATH . '/logs/epg_' . date('Y-m-d') . '.log');
define('LOG_CLEAN_DAYS', 1);
define('TIME_ALIGN_INTERVAL', 1);
define('OVERLAP_THRESHOLD', 3);
define('MIN_PROGRAM_COUNT', 4);
define('MIN_PROGRAM_DURATION', 60);
define('KEEP_PAST_DAYS', 7);
define('KEEP_FUTURE_DAYS', 10);
define('BATCH_SIZE_SAVE', 500);
define('MAX_PROGRAMS_PER_CHANNEL', 2000);
define('CURL_TIMEOUT', 120);
define('CURL_CONNECT_TIMEOUT', 40);
define('CURL_RETRY_TIMES', 4);
define('CURL_LARGE_FILE_TIMEOUT', 600); // CI环境缩短大文件超时，避免超限
define('CURL_LOW_SPEED_LIMIT', 512);
define('CURL_LOW_SPEED_TIME', 50);
define('CURL_SSL_CIPHER_LIST', 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:AES128-GCM-SHA256');
define('GZIP_COMPRESS_LEVEL', 6);
define('MAX_CONTENT_SIZE', 1024 * 1024 * 150);
define('FORCE_MEMORY_LIMIT', 1024); // GitHub runner 内存充足，1GB足够
define('CONFIG_FILE', ROOT_PATH . '/config/config.txt');

// ----- 输出文件配置 -----
define('OUTPUT_FILE_GZ', ROOT_PATH . '/xml/e.xml.gz');
define('OUTPUT_FILE_GZ_BACKUP', ROOT_PATH . '/xml/e.xml.gz.last');
define('OUTPUT_FILE_TEMP', OUTPUT_FILE_GZ . '.tmp');
define('OUTPUT_FILE_DESC_GZ', ROOT_PATH . '/xml/e-desc.xml.gz');
define('OUTPUT_FILE_DESC_GZ_BACKUP', ROOT_PATH . '/xml/e-desc.xml.gz.last');
define('OUTPUT_FILE_DESC_TEMP', OUTPUT_FILE_DESC_GZ . '.tmp');

// ===== 含简介分片配置 =====
define('DESC_SPLIT_ENABLED', false);
define('DESC_SPLIT_TARGET_MB', 6);
define('DESC_SPLIT_EST_RATIO', 17.0);
define('LOCAL_EPG_DIR', ROOT_PATH . '/epg_sources');
define('DESC_DB_FILE', ROOT_PATH . '/db/desc_database.db');
define('DESC_DB_TABLE', 'program_desc');
define('MIN_DESC_LENGTH', 10);
define('DESC_MAX_LENGTH', 2000);
define('DESC_CLEAN_ENABLE', true);
define('DESC_INCREMENTAL_SAVE', true);
define('DESC_AUTO_CLEAN_DAY', 1);
define('DESC_EXPIRE_DAYS', 10);
define('DESC_SQLITE_WAL_MODE', true);
define('DESC_ENABLED', true);
define('DESC_BATCH_SIZE', 1000);

// ====================== Desc匹配规则链配置 ======================
define('DESC_MATCH_RULES', [
    ['name' => '人工精确匹配(数据库)', 'enabled' => true, 'priority' => 1, 'scope' => 'channel', 'match_type' => 'exact'],
    ['name' => '同频道精确匹配', 'enabled' => true, 'priority' => 2, 'scope' => 'channel', 'match_type' => 'exact'],
    ['name' => '全局标题模糊匹配', 'enabled' => true, 'priority' => 99, 'scope' => 'global', 'match_type' => 'fuzzy']
]);
define('DESC_MATCH_CACHE_MAX', 50000);
define('FUZZY_MIN_CORE_LEN', 2);

// ====================== 独立人工数据源配置 ======================
define('MAN_MADE_ENABLED', true);
define('MAN_MADE_FILE', ROOT_PATH . '/config/man-made.json');

// ====================== 原有配置常量 ======================
define('ENABLE_PROCESS_LOCK', true);
define('LOCK_FILE', ROOT_PATH . '/epg.lock');
define('LOCK_TIMEOUT_SECONDS', 3600);
define('MAX_EXECUTION_SECONDS', 3600); // CI环境限制1小时内完成
define('DEFAULT_TIMEZONE', 'Asia/Shanghai');
define('SOURCES_JSON_FILE', ROOT_PATH . '/config/epg_sources.json');
define('CLEAN_RULES_FILE', ROOT_PATH . '/config/channel_clean_rules.json');
define('CHANNEL_1905', ['1905', '1905环球经典', '1905热血影院', '1905App 环球经典']);
define('EXCLUSIVE_CHANNELS_FILE', ROOT_PATH . '/config/exclusive_channels.json');

// 加载外置专属频道配置
$exclusiveChannels = [];
if (is_file(EXCLUSIVE_CHANNELS_FILE)) {
    $exclusiveChannels = json_decode(file_get_contents(EXCLUSIVE_CHANNELS_FILE), true);
}
if (empty($exclusiveChannels) || !is_array($exclusiveChannels)) {
    $exclusiveChannels = [];
}

// 【无效节目过滤关键词】
define('INVALID_PROGRAM_KEYWORDS', [
    'https://epg.136605.xyz',
    '停机检修',
    '广宣时段',
    '广告',
    '宣传段',
    '晨光天气'
]);
define('INVALID_PROGRAM_HOUR', 0);
define('INVALID_PROGRAM_MINUTE', 0);
define('DEFAULT_LANG', 'zh');
define('USER_AGENT', 'Mozilla/5.0 (compatible; EPG Aggregator/1.0; GitHub Actions)');
define('LARGE_FILE_URLS', [
    'https://tv.evorit.dpdns.org/epgphp/t.xml.gz',
    'https://cdn.jsdelivr.net/gh/zzq1234567890/epg@main/swepg.xml.gz'
]);
define('GLOBAL_EXCLUDE_CHANNEL_NAMES', []);
define('GLOBAL_EXCLUDE_CHANNEL_PATTERNS', []);

// ====================== 全局状态 ======================
$GLOBALS['normalize_cache'] = [];
$GLOBALS['rule_cache'] = [];
$GLOBALS['clean_rules'] = null;
$GLOBALS['desc_match_cache'] = [];
$GLOBALS['man_made_index'] = [];
$GLOBALS['fuzzy_global_index'] = [];
$GLOBALS['desc_match_stats'] = [
    '人工精确匹配(内存)' => 0,
    '人工精确匹配(数据库)' => 0,
    '同频道精确匹配' => 0,
    '全局标题模糊匹配' => 0,
    '未匹配' => 0
];
$GLOBALS['prog_processed_count'] = 0;

// ====================== 信号处理（CI环境兼容，扩展不存在则跳过） ======================
function signalHandler($signal): void {
    logMessage('INFO', "收到中断信号 {$signal}，开始安全退出...");
    releaseProcessLock();
    flushDescBatch();
    closeDescDbConnection();
    logMessage('INFO', "安全退出完成");
    exit(0);
}
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, 'signalHandler');
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGHUP, 'signalHandler');
}

// ====================== 进程锁机制 ======================
function acquireProcessLock(): bool {
    if (!ENABLE_PROCESS_LOCK) return true;
    if (file_exists(LOCK_FILE)) {
        $lockTime = filemtime(LOCK_FILE);
        if ($lockTime && time() - $lockTime > LOCK_TIMEOUT_SECONDS) {
            @unlink(LOCK_FILE);
            logMessage('WARNING', "检测到过期锁文件，已自动清理");
        }
    }
    if (file_exists(LOCK_FILE)) {
        $pid = file_get_contents(LOCK_FILE);
        if (is_numeric($pid) && function_exists('posix_kill') && posix_kill((int)$pid, 0)) {
            logMessage('ERROR', "检测到另一个进程正在运行，退出执行");
            return false;
        } else {
            @unlink(LOCK_FILE);
        }
    }
    return file_put_contents(LOCK_FILE, getmypid()) !== false;
}
function releaseProcessLock(): void {
    if (ENABLE_PROCESS_LOCK && file_exists(LOCK_FILE)) {
        @unlink(LOCK_FILE);
    }
}

// ====================== 执行超时监控 ======================
function checkExecutionTimeout($startTime): bool {
    $elapsed = time() - $startTime;
    if ($elapsed > MAX_EXECUTION_SECONDS) {
        logMessage('ERROR', "执行超时（{$elapsed}s），强制安全退出");
        releaseProcessLock();
        flushDescBatch();
        closeDescDbConnection();
        exit(1);
    }
    return true;
}

// ====================== 自动清理过期日志 ======================
function cleanExpiredLogs(): void {
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) return;
    $expireTime = time() - (LOG_CLEAN_DAYS * 86400);
    $deletedCount = 0;
    $files = scandir($logDir);
    foreach ($files as $file) {
        $filePath = $logDir . DIRECTORY_SEPARATOR . $file;
        if (is_file($filePath) && str_contains($file, 'epg_') && str_ends_with($file, '.log')) {
            if (filemtime($filePath) < $expireTime) {
                @unlink($filePath);
                $deletedCount++;
            }
        }
    }
    if ($deletedCount > 0) {
        logMessage('INFO', "自动清理日志完成，删除 " . $deletedCount . " 个超过" . LOG_CLEAN_DAYS . "天的日志文件");
    }
}

// ====================== 环境配置 ======================
$scriptStartTime = time();
set_time_limit(0);
ignore_user_abort(true);
if (!acquireProcessLock()) {
    exit(1);
}
function setSafeMemoryLimit(): void {
    $memoryLimit = FORCE_MEMORY_LIMIT . 'M';
    ini_set('memory_limit', $memoryLimit);
    logMessage('INFO', "强制设置内存限制为 {$memoryLimit}");
    if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/meminfo')) {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo && preg_match('/MemAvailable:\s+(\d+)\s*kB/', (string)$meminfo, $match)) {
            $availableMb = (int)$match[1] / 1024;
            logMessage('INFO', "系统可用内存：{$availableMb} MB");
        }
    }
}
setSafeMemoryLimit();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('date.timezone', DEFAULT_TIMEZONE);

// ====================== 工具类封装 ======================
class EpgConfig {
    public static DateTimeZone $TIMEZONE;
    public static DateTimeZone $UTC_ZONE;
    public static function init(): void {
        self::$TIMEZONE = new DateTimeZone(DEFAULT_TIMEZONE);
        self::$UTC_ZONE = new DateTimeZone('UTC');
    }
}
EpgConfig::init();

// ====================== 兼容函数 ======================
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle): bool {
        return $needle === '' || strpos((string)$haystack, $needle) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle): bool {
        $length = strlen((string)$needle);
        if ($length === 0) return true;
        return substr((string)$haystack, -$length) === (string)$needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle): bool {
        return $needle === '' || strpos((string)$haystack, $needle) !== false;
    }
}

// ====================== 日志系统 ======================
function logMessage(string $level, string $message): void {
    static $levelMap = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];
    static $logHandle = null;
    $currentLevel = $levelMap[LOG_LEVEL] ?? 1;
    $msgLevel = $levelMap[$level] ?? 1;
    if ($msgLevel < $currentLevel) return;
    $time = date('Y-m-d H:i:s');
    $logLine = "[{$time}] [{$level}] {$message}" . PHP_EOL;
    echo $logLine;
    flush();
    if (defined('LOG_FILE') && !empty(LOG_FILE)) {
        if ($logHandle === null) {
            $logDir = dirname(LOG_FILE);
            if (!file_exists($logDir)) mkdir($logDir, 0755, true);
            $logHandle = fopen(LOG_FILE, 'a');
        }
        if ($logHandle) {
            fwrite($logHandle, $logLine);
            fflush($logHandle);
        }
    }
}

// ====================== 内存监控与释放 ======================
function checkMemoryUsage(string $context = ''): void {
    static $lastCheck = 0;
    $now = time();
    if ($now - $lastCheck < 30) return;
    
    $usage = memory_get_usage(true) / 1024 / 1024;
    $peak = memory_get_peak_usage(true) / 1024 / 1024;
    logMessage('DEBUG', "内存使用监控 [{$context}]：当前 {$usage} MB | 峰值 {$peak} MB");
    
    if ($usage > FORCE_MEMORY_LIMIT * 0.8) {
        logMessage('WARNING', "内存使用超过80%，强制触发垃圾回收");
        gc_collect_cycles();
        $newUsage = memory_get_usage(true) / 1024 / 1024;
        logMessage('DEBUG', "垃圾回收后内存使用：{$newUsage} MB");
    }
    $lastCheck = $now;
}
function freeMemory(&...$vars): void {
    foreach ($vars as &$var) {
        $var = null;
        unset($var);
    }
    static $gcCount = 0;
    $gcCount++;
    if ($gcCount >= 50 && function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
        $gcCount = 0;
        $memUsage = memory_get_usage(true) / 1024 / 1024;
        logMessage('DEBUG', "垃圾回收完成，当前内存使用：{$memUsage} MB");
    }
}

// ====================== 路径检查工具函数 ======================
function ensureDirExists(string $filePath): bool {
    $dir = dirname($filePath);
    if (!file_exists($dir)) {
        try {
            mkdir($dir, 0755, true);
            logMessage('INFO', "创建目录成功：{$dir}");
            return true;
        } catch (Exception $e) {
            logMessage('ERROR', "创建目录失败 {$dir}：{$e->getMessage()}");
            return false;
        }
    }
    if (!is_writable($dir)) {
        logMessage('ERROR', "目录不可写：{$dir}");
        return false;
    }
    return true;
}

// ====================== 安全工具函数 ======================
function xmlEscape(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8', false);
}

// ====================== 增强版清洗规则 + 标题归一化 ======================
function loadCleanRules() {
    if (!file_exists(CLEAN_RULES_FILE)) {
        logMessage('WARNING', '清洗规则文件不存在，使用默认规则');
        return [
            'channel_rules' => [],
            'default' => ['episode_patterns' => ['/\((\\d+)\)$/u']]
        ];
    }
    $json = file_get_contents(CLEAN_RULES_FILE);
    $rules = json_decode($json, true);
    logMessage('INFO', '加载频道清洗规则成功');
    return $rules ?: [
        'channel_rules' => [],
        'default' => ['episode_patterns' => ['/\((\\d+)\)$/u']]
    ];
}
function getChannelCleanRule($channelName) {
    static $cleanRules = null;
    if ($cleanRules === null) $cleanRules = loadCleanRules();
    $normChannel = normalizeTitleV2($channelName);
    if (isset($GLOBALS['rule_cache'][$normChannel])) {
        return $GLOBALS['rule_cache'][$normChannel];
    }
    $rule = $cleanRules['default'] ?? [];
    foreach ($cleanRules['channel_rules'] as $ch => $r) {
        if (fnmatch(normalizeTitleV2($ch), $normChannel)) {
            $rule = $r;
            break;
        }
    }
    if (isset($rule['inherit_from'])) {
        $rule = getChannelCleanRule($rule['inherit_from']);
    }
    $GLOBALS['rule_cache'][$normChannel] = $rule;
    return $rule;
}

/**
 * 提取标题中的集数/期数信息
 */
function extractEpisodeInfo(string $title): array {
    $episodeInfo = '';
    $pureTitle = $title;
    
    $episodePatterns = [
        '/(第[一二三四五六七八九十零百千万0-9]+[集期部回季])/u',
        '/([上下]集)/u',
        '/(\(第?\d+集\))/u',
        '/(【第?\d+集】)/u',
        '/(\d+\/\d+)/u',
    ];
    
    foreach ($episodePatterns as $pattern) {
        if (preg_match($pattern, $title, $match)) {
            $episodeInfo = $match[1];
            $pureTitle = preg_replace($pattern, '', $title);
            break;
        }
    }
    
    return [trim($episodeInfo), trim($pureTitle)];
}

function normalizeTitleV2(string $title): string {
    if (empty($title)) return '';
    if (isset($GLOBALS['normalize_cache'][$title])) {
        return $GLOBALS['normalize_cache'][$title];
    }
    $cleaned = preg_replace('/[\s\-_\+\|\(\)（）\[\]【】《》:：·"~～—\-]/u', '', $title);
    $result = strtolower(trim($cleaned));
    $GLOBALS['normalize_cache'][$title] = $result;
    return $result;
}
function fixHtmlEntities(string $text): string {
    if (empty($text)) return '';
    $fixed = $text;
    for ($i = 0; $i < 10; $i++) {
        $decoded = html_entity_decode($fixed, ENT_QUOTES, 'UTF-8');
        if ($decoded === $fixed) break;
        $fixed = $decoded;
    }
    $replace = [
        '&lt;' => '《', '&gt;' => '》', '&quot;' => '"',
        '&apos;' => "'", '&nbsp;' => ' ', '&amp;' => '&'
    ];
    $fixed = strtr($fixed, $replace);
    $fixed = preg_replace('/\s+/u', ' ', trim($fixed));
    return $fixed;
}
function cleanProgramTitle($title, $channelName) {
    if (empty($title)) return '';
    $rule = getChannelCleanRule($channelName);
    $cleaned = trim($title);
    
    [$episodeInfo, $pureTitle] = extractEpisodeInfo($cleaned);
    $cleaned = $pureTitle;
    
    if (!empty($rule['remove_prefix'])) {
        foreach ($rule['remove_prefix'] as $pre) {
            if (str_starts_with($cleaned, $pre)) {
                $cleaned = substr($cleaned, strlen($pre));
            }
        }
    }
    if (!empty($rule['title_transform'])) {
        foreach ($rule['title_transform'] as $t) {
            $cleaned = preg_replace($t['from'], $t['to'], $cleaned);
        }
    }
    if (!empty($rule['fraction_to_single'])) {
        $cleaned = preg_replace('/(\d+)\/(\d+)/u', '$1', $cleaned);
    }
    
    $patterns = [
        '/[\(（]?\d{4}[-./年]\d{1,2}[-./月]\d{1,2}[日]?[\)）]/u' => '',
        '/[\(（]?\d{8}[\)）]/u' => '',
        '/[重直]播|高清|标清|完整版|预告片|4K|1080P/u' => '',
        '/\s*\d{1,3}$/u' => '',
    ];
    foreach ($patterns as $p => $r) {
        $cleaned = preg_replace($p, $r, $cleaned);
    }
    
    $cleaned = trim($cleaned);
    
    if (!empty($episodeInfo)) {
        $cleaned = $cleaned . ' ' . $episodeInfo;
    }
    
    return $cleaned ?: $title;
}

// ====================== 核心标题提取（高性能缓存版） ======================
function extractCoreTitle(string $title): string {
    static $coreCache = [];
    if (isset($coreCache[$title])) {
        return $coreCache[$title];
    }
    $core = cleanProgramTitle($title, '');
    $core = preg_replace('/\d+/u', '', $core);
    $core = preg_replace('/第[一二三四五六七八九十百千万]+[季部篇]/u', '', $core);
    $core = preg_replace('/[\s\-_\+\|\(\)（）\[\]【】《》:：·"~～—\-·]/u', '', $core);
    $result = strtolower(trim($core));
    $coreCache[$title] = $result;
    if (count($coreCache) > 2000) {
        $coreCache = array_slice($coreCache, -10000, null, true);
    }
    return $result;
}

// ====================== SQLite Desc信息库核心函数 ======================
$GLOBALS['invalidDescPatterns'] = [
    '/^暂无节目描述$/iu', '/^暂无描述$/iu', '/^暂无简介$/iu', '/^暂无内容$/iu',
    '/^无节目描述$/iu', '/^无描述$/iu', '/^无简介$/iu', '/^测试描述$/iu', '/^频道介绍$/iu',
    '/10月1日e域名头关闭/iu',
    '/vip域名头不在支持xml下载/iu'
];
function cleanDescText(string $desc): string {
    if (empty($desc)) return '';
    $desc = fixHtmlEntities($desc);
    foreach ($GLOBALS['invalidDescPatterns'] as $pattern) {
        if (preg_match($pattern, trim($desc))) return '';
    }
    $len = mb_strlen($desc, 'UTF-8');
    if ($len < 5 || $len > DESC_MAX_LENGTH) return '';
    $adPatterns = [
        '/https?:\/\/[^\s]+/u' => '',
        '/【.*?】/u' => '',
        '/\s{2,}/u' => ' '
    ];
    foreach ($adPatterns as $p => $r) {
        $desc = preg_replace($p, $r, $desc);
    }
    $sentences = preg_split('/[。！？；]/u', $desc);
    $desc = implode('。', array_unique(array_filter($sentences, 'trim')));
    return trim($desc);
}
function generateProgramKey(string $channelId, string $title): array {
    $title = cleanProgramTitle($title, $channelId);
    $channelKey = normalizeTitleV2($channelId);
    $titleKey = normalizeTitleV2($title);
    return [$channelKey, $titleKey];
}
static $descBatchQueue = [];
static $descStats = [
    'total' => 0, 'new' => 0, 'invalid' => 0, 'html_fixed' => 0, 'filtered_channel' => 0
];
function addToDescBatch(array $data): void {
    global $descBatchQueue, $descStats;
    if (!DESC_ENABLED) return;
    $descBatchQueue[] = $data;
    if (count($descBatchQueue) >= DESC_BATCH_SIZE) {
        flushDescBatch();
    }
}
function flushDescBatch(): void {
    global $descBatchQueue, $descStats;
    if (!DESC_ENABLED || empty($descBatchQueue)) return;
    try {
        $db = getDescDbConnection();
        if (!$db) return;
        $db->exec('BEGIN TRANSACTION');
        foreach ($descBatchQueue as $item) {
            $saved = saveSingleDesc($item);
            if ($saved) $descStats['new']++;
        }
        $db->exec('COMMIT');
        $descBatchQueue = [];
    } catch (Throwable $e) {
        $db?->exec('ROLLBACK');
        logMessage('ERROR', "批量写入Desc失败：{$e->getMessage()}");
        $descBatchQueue = [];
    }
}
function saveSingleDesc(array $data): bool {
    $db = getDescDbConnection();
    if (!$db) return false;
    [$channelKey, $titleKey, $channelId, $title, $cleanDesc, $sourcePriority, $descLength] = $data;
    $stmt = $db->prepare("
        SELECT id, source_priority, desc_length 
        FROM " . DESC_DB_TABLE . " 
        WHERE channel_key = :channel_key AND title_key = :title_key
    ");
    $stmt->bindValue(':channel_key', $channelKey, SQLITE3_TEXT);
    $stmt->bindValue(':title_key', $titleKey, SQLITE3_TEXT);
    $result = $stmt->execute();
    $existing = $result->fetchArray(SQLITE3_ASSOC);
    if (!$existing) {
        $stmt = $db->prepare("
            INSERT INTO " . DESC_DB_TABLE . " 
            (channel_key, title_key, channel, title, desc, source_priority, desc_length)
            VALUES (:channel_key, :title_key, :channel, :title, :desc, :source_priority, :desc_length)
        ");
        $stmt->bindValue(':channel_key', $channelKey, SQLITE3_TEXT);
        $stmt->bindValue(':title_key', $titleKey, SQLITE3_TEXT);
        $stmt->bindValue(':channel', $channelId, SQLITE3_TEXT);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':desc', $cleanDesc, SQLITE3_TEXT);
        $stmt->bindValue(':source_priority', $sourcePriority, SQLITE3_INTEGER);
        $stmt->bindValue(':desc_length', $descLength, SQLITE3_INTEGER);
        $stmt->execute();
        return true;
    } else {
        $shouldUpdate = false;
        if ($sourcePriority < $existing['source_priority']) {
            $shouldUpdate = true;
        } elseif ($sourcePriority == $existing['source_priority'] && $descLength > $existing['desc_length']) {
            $shouldUpdate = true;
        }
        if ($shouldUpdate) {
            $stmt = $db->prepare("
                UPDATE " . DESC_DB_TABLE . " 
                SET desc = :desc, source_priority = :source_priority, 
                    desc_length = :desc_length, update_time = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->bindValue(':desc', $cleanDesc, SQLITE3_TEXT);
            $stmt->bindValue(':source_priority', $sourcePriority, SQLITE3_INTEGER);
            $stmt->bindValue(':desc_length', $descLength, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $existing['id'], SQLITE3_INTEGER);
            $stmt->execute();
            return true;
        }
    }
    return false;
}
function collectDescInfo(string $channelId, string $title, string $desc, int $sourcePriority): void {
    global $configNames, $descStats;
    if (!DESC_ENABLED) return;
    if (empty($channelId) || empty($title) || empty($desc)) {
        $descStats['invalid']++;
        return;
    }
    if (!isset($configNames[$channelId])) {
        $descStats['filtered_channel']++;
        return;
    }
    $cleanDesc = cleanDescText($desc);
    if (empty($cleanDesc)) {
        $descStats['invalid']++;
        return;
    }
    try {
        [$channelKey, $titleKey] = generateProgramKey($channelId, $title);
        if (empty($channelKey) || empty($titleKey)) return;
        $descLength = mb_strlen($cleanDesc, 'UTF-8');
        addToDescBatch([$channelKey, $titleKey, $channelId, $title, $cleanDesc, $sourcePriority, $descLength]);
    } catch (Throwable $e) {
        logMessage('WARNING', "收集Desc信息失败: {$channelId} - {$title} | {$e->getMessage()}");
    }
}

// ====================== 独立人工数据源加载 ======================
function loadManMadeData(): void {
    global $GLOBALS;
    if (!MAN_MADE_ENABLED) {
        return;
    }
    if (!file_exists(MAN_MADE_FILE)) {
        logMessage('WARNING', '独立人工数据源文件不存在：' . MAN_MADE_FILE . '，已自动跳过');
        return;
    }
    try {
        $json = file_get_contents(MAN_MADE_FILE);
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data)) {
            logMessage('WARNING', '人工数据源文件格式错误或内容为空');
            return;
        }
        $validCount = 0;
        foreach ($data as $item) {
            $channel = trim($item['channel'] ?? '');
            $title = trim($item['title'] ?? '');
            $desc = trim($item['desc'] ?? '');
            if (empty($channel) || empty($title) || empty($desc)) continue;
            $cleanDesc = cleanDescText($desc);
            if (empty($cleanDesc)) continue;
            [$channelKey, $titleKey] = generateProgramKey($channel, $title);
            $matchKey = $channelKey . '|||' . $titleKey;
            if (!isset($GLOBALS['man_made_index'][$matchKey]) 
                || mb_strlen($cleanDesc) > mb_strlen($GLOBALS['man_made_index'][$matchKey])) {
                $GLOBALS['man_made_index'][$matchKey] = $cleanDesc;
                $validCount++;
            }
        }
        logMessage('INFO', "独立人工数据源加载完成，有效记录：{$validCount} 条");
    } catch (Throwable $e) {
        logMessage('ERROR', '人工数据源加载失败：' . $e->getMessage());
    }
}

// ====================== 预构建全局模糊索引 ======================
function buildFuzzyGlobalIndex(): void {
    global $GLOBALS;
    if (!DESC_ENABLED) return;
    try {
        $db = getDescDbConnection();
        if (!$db) return;
        logMessage('INFO', '正在预构建全局模糊匹配索引...');
        $result = $db->query("
            SELECT title_key, desc 
            FROM " . DESC_DB_TABLE . " 
            ORDER BY source_priority ASC, desc_length DESC
        ");
        $count = 0;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $core = extractCoreTitle($row['title_key']);
            if (empty($core) || mb_strlen($core) < FUZZY_MIN_CORE_LEN) continue;
            if (!isset($GLOBALS['fuzzy_global_index'][$core])) {
                $GLOBALS['fuzzy_global_index'][$core] = $row['desc'];
                $count++;
            }
        }
        logMessage('INFO', "全局模糊索引构建完成，索引条目：{$count} 条");
    } catch (Throwable $e) {
        logMessage('WARNING', '构建全局模糊索引失败：' . $e->getMessage());
    }
}

// ====================== Desc回填 四级规则链 ======================
function fillProgramDesc(DOMElement $program): void {
    global $GLOBALS;
    if (!DESC_ENABLED) return;
    
    $xpath = new DOMXPath($program->ownerDocument);
    $titleElem = $xpath->query('.//title', $program)->item(0);
    $descElem = $xpath->query('.//desc', $program)->item(0);
    
    if (!$titleElem || empty(trim($titleElem->nodeValue))) return;
    $title = trim($titleElem->nodeValue);
    $channelId = $program->getAttribute('channel');
    if (empty($channelId) || empty($title)) return;
    
    $existingDesc = trim($descElem?->nodeValue ?? '');
    if (mb_strlen($existingDesc, 'UTF-8') >= MIN_DESC_LENGTH) {
        return;
    }
    
    $cacheKey = $channelId . '|||' . $title;
    if (isset($GLOBALS['desc_match_cache'][$cacheKey])) {
        $matchedDesc = $GLOBALS['desc_match_cache'][$cacheKey];
        if ($matchedDesc) {
            if (!$descElem) {
                $descElem = $program->ownerDocument->createElement('desc');
                $descElem->setAttribute('lang', DEFAULT_LANG);
                $program->appendChild($descElem);
            }
            $descElem->nodeValue = xmlEscape($matchedDesc);
        }
        return;
    }
    
    // 第1级：内存人工精确匹配
    if (MAN_MADE_ENABLED && !empty($GLOBALS['man_made_index'])) {
        [$channelKey, $titleKey] = generateProgramKey($channelId, $title);
        $matchKey = $channelKey . '|||' . $titleKey;
        if (isset($GLOBALS['man_made_index'][$matchKey])) {
            $matchedDesc = $GLOBALS['man_made_index'][$matchKey];
            $GLOBALS['desc_match_stats']['人工精确匹配(内存)']++;
            if (!$descElem) {
                $descElem = $program->ownerDocument->createElement('desc');
                $descElem->setAttribute('lang', DEFAULT_LANG);
                $program->appendChild($descElem);
            }
            $descElem->nodeValue = xmlEscape($matchedDesc);
            $GLOBALS['desc_match_cache'][$cacheKey] = $matchedDesc;
            checkDescCacheSize();
            return;
        }
    }
    
    // 第2~4级：数据库规则链
    try {
        $db = getDescDbConnection();
        if (!$db) {
            $GLOBALS['desc_match_cache'][$cacheKey] = false;
            return;
        }
        
        [$channelKey, $titleKey] = generateProgramKey($channelId, $title);
        $coreTitle = extractCoreTitle($title);
        $matchedDesc = null;
        $matchedRule = '';
        
        foreach (DESC_MATCH_RULES as $rule) {
            if (!$rule['enabled']) continue;
            $priority = $rule['priority'];
            $scope = $rule['scope'];
            $matchType = $rule['match_type'];
            
            if ($matchType === 'exact' && $scope === 'channel') {
                $stmt = $db->prepare("
                    SELECT desc 
                    FROM " . DESC_DB_TABLE . " 
                    WHERE source_priority = :priority 
                      AND channel_key = :channel_key 
                      AND title_key = :title_key
                    ORDER BY desc_length DESC
                    LIMIT 1
                ");
                $stmt->bindValue(':priority', $priority, SQLITE3_INTEGER);
                $stmt->bindValue(':channel_key', $channelKey, SQLITE3_TEXT);
                $stmt->bindValue(':title_key', $titleKey, SQLITE3_TEXT);
                $result = $stmt->execute();
                $row = $result->fetchArray(SQLITE3_ASSOC);
                if ($row && !empty($row['desc'])) {
                    $matchedDesc = $row['desc'];
                    $matchedRule = $rule['name'];
                    break;
                }
            }
            
            if ($matchType === 'fuzzy' && $scope === 'global') {
                if (empty($coreTitle) || mb_strlen($coreTitle) < FUZZY_MIN_CORE_LEN) {
                    continue;
                }
                if (isset($GLOBALS['fuzzy_global_index'][$coreTitle])) {
                    $matchedDesc = $GLOBALS['fuzzy_global_index'][$coreTitle];
                    $matchedRule = $rule['name'];
                    break;
                }
                foreach ($GLOBALS['fuzzy_global_index'] as $idxCore => $idxDesc) {
                    if (str_contains($coreTitle, $idxCore) || str_contains($idxCore, $coreTitle)) {
                        $matchedDesc = $idxDesc;
                        $matchedRule = $rule['name'];
                        break 2;
                    }
                }
            }
        }
        
        if ($matchedDesc) {
            $GLOBALS['desc_match_stats'][$matchedRule] = ($GLOBALS['desc_match_stats'][$matchedRule] ?? 0) + 1;
            if (!$descElem) {
                $descElem = $program->ownerDocument->createElement('desc');
                $descElem->setAttribute('lang', DEFAULT_LANG);
                $program->appendChild($descElem);
            }
            $descElem->nodeValue = xmlEscape($matchedDesc);
            $GLOBALS['desc_match_cache'][$cacheKey] = $matchedDesc;
        } else {
            $GLOBALS['desc_match_stats']['未匹配']++;
            $GLOBALS['desc_match_cache'][$cacheKey] = false;
        }
        
        checkDescCacheSize();
        
    } catch (Throwable $e) {
        $GLOBALS['desc_match_cache'][$cacheKey] = false;
    }
    
    $GLOBALS['prog_processed_count']++;
    if ($GLOBALS['prog_processed_count'] % 500 === 0) {
        $elapsed = round(time() - $GLOBALS['script_start_time'], 1);
        logMessage('DEBUG', "Desc处理进度：已处理 {$GLOBALS['prog_processed_count']} 条节目，耗时 {$elapsed} 秒");
    }
}
function checkDescCacheSize(): void {
    global $GLOBALS;
    if (count($GLOBALS['desc_match_cache']) > DESC_MATCH_CACHE_MAX) {
        $GLOBALS['desc_match_cache'] = array_slice(
            $GLOBALS['desc_match_cache'], 
            (int)(DESC_MATCH_CACHE_MAX * 0.5), 
            null, 
            true
        );
    }
}
function initDescDatabase(): ?SQLite3 {
    if (!DESC_ENABLED) return null;
    try {
        if (!extension_loaded('sqlite3')) {
            throw new Exception("SQLite3扩展未启用");
        }
        if (!ensureDirExists(DESC_DB_FILE)) {
            throw new Exception("数据库目录不可写：" . dirname(DESC_DB_FILE));
        }
        $db = new SQLite3(DESC_DB_FILE, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $db->exec('PRAGMA encoding = "UTF-8"');
        if (DESC_SQLITE_WAL_MODE) {
            $db->exec('PRAGMA journal_mode = WAL');
        }
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('PRAGMA cache_size = -20000');
        $db->exec('PRAGMA auto_vacuum = INCREMENTAL');
        $createTableSql = "
        CREATE TABLE IF NOT EXISTS " . DESC_DB_TABLE . " (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_key TEXT NOT NULL,
            title_key TEXT NOT NULL,
            channel TEXT NOT NULL,
            title TEXT NOT NULL,
            desc TEXT NOT NULL,
            source_priority INTEGER NOT NULL DEFAULT 99,
            desc_length INTEGER NOT NULL DEFAULT 0,
            update_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(channel_key, title_key)
        );
        CREATE INDEX IF NOT EXISTS idx_channel_key ON " . DESC_DB_TABLE . "(channel_key);
        CREATE INDEX IF NOT EXISTS idx_title_key ON " . DESC_DB_TABLE . "(title_key);
        CREATE INDEX IF NOT EXISTS idx_update_time ON " . DESC_DB_TABLE . "(update_time);
        ";
        $db->exec($createTableSql);
        logMessage('INFO', "SQLite Desc数据库初始化完成：" . DESC_DB_FILE);
        return $db;
    } catch (Throwable $e) {
        logMessage('ERROR', "初始化Desc数据库失败：{$e->getMessage()}");
        return null;
    }
}
function getDescDbConnection(): ?SQLite3 {
    if (!DESC_ENABLED) return null;
    static $db = null;
    if ($db === null) {
        $db = initDescDatabase();
    }
    return $db;
}
function closeDescDbConnection(): void {
    if (!DESC_ENABLED) return;
    static $db = null;
    if ($db !== null) {
        flushDescBatch();
        $db->exec('VACUUM');
        $db->close();
        $db = null;
        logMessage('DEBUG', "SQLite数据库连接已关闭（已执行VACUUM优化）");
    }
}
function cleanExpiredDescData(int $maxDays = DESC_EXPIRE_DAYS): void {
    if (!DESC_ENABLED) return;
    try {
        $db = getDescDbConnection();
        if (!$db) return;
        $db->exec('BEGIN TRANSACTION');
        $stmt = $db->prepare("
            DELETE FROM " . DESC_DB_TABLE . " 
            WHERE update_time < datetime('now', '-' || :days || ' days')
        ");
        $stmt->bindValue(':days', $maxDays, SQLITE3_INTEGER);
        $deletedCount = $stmt->execute()->changes();
        $db->exec('VACUUM');
        $db->exec('COMMIT');
        logMessage('INFO', "Desc数据库清理完成：删除 {$deletedCount} 条过期记录");
    } catch (Throwable $e) {
        $db?->exec('ROLLBACK');
        logMessage('ERROR', "清理Desc数据库失败：{$e->getMessage()}");
    }
}
function getDescDbStats(): void {
    global $descStats;
    if (!DESC_ENABLED) return;
    try {
        $db = getDescDbConnection();
        if (!$db) return;
        $total = $db->querySingle("SELECT COUNT(*) FROM " . DESC_DB_TABLE);
        $channels = $db->querySingle("SELECT COUNT(DISTINCT channel_key) FROM " . DESC_DB_TABLE);
        $fileSize = filesize(DESC_DB_FILE);
        $sizeMb = round($fileSize / 1024 / 1024, 2);
        
        logMessage('INFO', "===== Desc 数据库增强统计 =====");
        logMessage('INFO', "总记录数：{$total} 条");
        logMessage('INFO', "覆盖频道数：{$channels} 个");
        logMessage('INFO', "本次处理：{$descStats['total']} 条");
        logMessage('INFO', "本次新增：{$descStats['new']} 条");
        logMessage('INFO', "无效过滤：{$descStats['invalid']} 条");
        logMessage('INFO', "非目标频道过滤：{$descStats['filtered_channel']} 条");
        logMessage('INFO', "数据库大小：{$sizeMb} MB");
        
        logMessage('INFO', "");
        logMessage('INFO', "===== Desc 规则链匹配统计 =====");
        foreach ($GLOBALS['desc_match_stats'] as $rule => $count) {
            logMessage('INFO', "{$rule}：{$count} 条");
        }
    } catch (Throwable $e) {
        logMessage('ERROR', "获取Desc统计失败：{$e->getMessage()}");
    }
}
function collectProgramDescOnly(DOMElement $program, string $mappedId, int $sourcePriority): void {
    if (!DESC_ENABLED) return;
    $xpath = new DOMXPath($program->ownerDocument);
    $title = trim($xpath->query('.//title', $program)->item(0)?->nodeValue ?? '');
    $desc = trim($xpath->query('.//desc', $program)->item(0)?->nodeValue ?? '');
    if (!empty($title) && !empty($desc)) {
        collectDescInfo($mappedId, $title, $desc, $sourcePriority);
    }
}

// ====================== 标准化节目标题函数 ======================
function normalizeTitle(string $title): string {
    if (empty($title)) return '';
    $title = trim((string)$title);
    $episodeInfo = '';
    $episodePatterns = [
        '/(第[一二三四五六七八九十零百千万0-9]+[集期部回])/u',
        '/([上下]集)/u',
        '/(第?\d+[集期部回])/u',
        '/(\(第?\d+集\))/u',
        '/(【第?\d+集】)/u'
    ];
    foreach ($episodePatterns as $pattern) {
        if (preg_match($pattern, (string)$title, $match)) {
            $episodeInfo = $match[1];
            break;
        }
    }
    $removePatterns = [
        '/\s*\d{4}[-_]\d+$/u',
        '/[-_]\d{4}[-_]\d+$/u',
        '/[-_]\d+$/u',
        '/\s*\d+$/u',
        '/[-_]\d{4}年/u',
        '/字幕板[-_]\d{4}[-_]\d+$/u',
        '/-\d{4}-\d+$/u'
    ];
    $normalized = $title;
    foreach ($removePatterns as $pattern) {
        $normalized = preg_replace($pattern, '', (string)$normalized);
    }
    if (!empty($episodeInfo)) {
        $normalized = preg_replace($episodePatterns[0], '', (string)$normalized);
        $normalized = trim((string)$normalized);
        if (!empty($normalized)) $normalized .= ' ';
        $normalized .= $episodeInfo;
    }
    $normalized = preg_replace('/\s+/u', ' ', (string)$normalized);
    return !empty($normalized) ? $normalized : trim((string)$title);
}

// ====================== 频道过滤核心函数 ======================
function isChannelExcluded(string $channelName, array $filterRules): bool {
    if (empty($filterRules)) return false;
    if (!empty($filterRules['excludeChannelNames'])) {
        if (in_array(trim($channelName), $filterRules['excludeChannelNames'])) {
            logMessage('DEBUG', "频道【{$channelName}】被精准名称规则过滤");
            return true;
        }
    }
    if (!empty($filterRules['excludeChannelPatterns'])) {
        foreach ($filterRules['excludeChannelPatterns'] as $pattern) {
            if (fnmatch($pattern, $channelName)) {
                logMessage('DEBUG', "频道【{$channelName}】被通配符规则【{$pattern}】过滤");
                return true;
            }
        }
    }
    return false;
}

// ====================== 节目处理函数 ======================
function processProgramme(DOMElement $programme, string $mappedId, DOMDocument $targetDoc, int $sourcePriority): ?DOMElement {
    $invalidKeywords = INVALID_PROGRAM_KEYWORDS;
    $xpath = new DOMXPath($programme->ownerDocument);
    $titleElem = $xpath->query('.//title', $programme)->item(0);
    $title = $titleElem ? trim((string)$titleElem->nodeValue) : '';
    foreach ($invalidKeywords as $keyword) {
        if (str_contains($title, $keyword)) {
            logMessage('DEBUG', "过滤无效节目（命中关键词[{$keyword}]）：{$title}");
            return null;
        }
    }
    $newProg = $targetDoc->importNode($programme, true);
    $startTimeStr = $newProg->getAttribute('start');
    $startTime = parseEpgTime($startTimeStr);
    if (!$startTime) {
        logMessage('WARNING', "跳过非法时间格式节目【{$mappedId}】：{$startTimeStr}");
        return null;
    }
    $stopTimeStr = $newProg->getAttribute('stop');
    $stopTime = parseEpgTime($stopTimeStr);
    if (!$stopTime) {
        logMessage('WARNING', "跳过非法结束时间节目【{$mappedId}】：{$stopTimeStr}");
        return null;
    }
    $startHour = (int)$startTime->format('H');
    $stopHour = (int)$stopTime->format('H');
    $adjustedStopTime = clone $stopTime;
    if ($startHour > $stopHour) {
        $adjustedStopTime->add(new DateInterval('P1D'));
    }
    if ($startTime >= $adjustedStopTime) {
        $startFormatted = $startTime->format('Y-m-d H:i:s');
        $stopFormatted = $stopTime->format('Y-m-d H:i:s');
        logMessage('WARNING', "过滤时间顺序错误的节目：频道={$mappedId}，开始={$startFormatted}，结束={$stopFormatted}");
        return null;
    }
    $duration = $adjustedStopTime->getTimestamp() - $startTime->getTimestamp();
    if ($duration < MIN_PROGRAM_DURATION) {
        logMessage('WARNING', "过滤时长过短的节目：频道={$mappedId}，时长={$duration}秒（最小要求=".MIN_PROGRAM_DURATION."秒）");
        return null;
    }
    $newProg->setAttribute('data-source-priority', $sourcePriority);
    $targetXpath = new DOMXPath($targetDoc);
    $nodes = ['title', 'desc'];
    foreach ($nodes as $tag) {
        $elems = $targetXpath->query(".//{$tag}", $newProg);
        if ($elems instanceof DOMNodeList) {
            foreach ($elems as $elem) {
                if (!$elem->hasAttribute('lang')) {
                    $elem->setAttribute('lang', DEFAULT_LANG);
                }
                $nodeValue = $elem->nodeValue ?? '';
                $cleanValue = trim((string)$nodeValue);
                if (empty($cleanValue)) {
                    $elem->parentNode->removeChild($elem);
                    continue;
                }
                $elem->nodeValue = xmlEscape($cleanValue);
            }
        }
    }
    $newProg->setAttribute('channel', $mappedId);
    $fixTz = function($timeStr) {
        if (!str_contains((string)$timeStr, ' +') && !str_contains((string)$timeStr, ' -') && !str_ends_with((string)$timeStr, 'Z')) {
            return substr((string)$timeStr, 0, 14) . ' +0800';
        }
        return $timeStr;
    };
    if (!empty($startTimeStr)) {
        $fixedStart = $fixTz($startTimeStr);
        $normalizedStart = normalizeTime($fixedStart);
        $newProg->setAttribute('start', $normalizedStart);
    }
    $stopStr = $newProg->getAttribute('stop');
    if (!empty($stopStr)) {
        $fixedStop = $fixTz($stopStr);
        $normalizedStop = normalizeTime($fixedStop);
        $newProg->setAttribute('stop', $normalizedStop);
    }
    fillProgramDesc($newProg);
    return $newProg;
}

// ====================== 专属频道判断 ======================
function isExclusiveSourceForChannel(string $channelId, int $currentSourcePriority, array $programmes): bool {
    static $channelPriorityLock = [];
    static $channelFailedPriorities = [];
    global $exclusiveChannels;
    $matchedPriorities = null;
    if (isset($exclusiveChannels[$channelId])) {
        $matchedPriorities = $exclusiveChannels[$channelId];
    } else {
        foreach ($exclusiveChannels as $pattern => $allowed) {
            if (fnmatch($pattern, $channelId)) {
                $matchedPriorities = $allowed;
                break;
            }
        }
    }
    if ($matchedPriorities === null) {
        return true;
    }
    if (!is_array($matchedPriorities)) $matchedPriorities = [$matchedPriorities];
    $lockKey = $channelId;
    if (isset($channelPriorityLock[$lockKey])) {
        return $currentSourcePriority === $channelPriorityLock[$lockKey];
    }
    if (!isset($channelFailedPriorities[$lockKey])) {
        $channelFailedPriorities[$lockKey] = [];
    }
    $hasTodayProgram = false;
    if (isset($programmes[$channelId]) && !empty($programmes[$channelId])) {
        $todayZero = new DateTime('today', EpgConfig::$TIMEZONE);
        foreach ($programmes[$channelId] as $p) {
            $s = parseEpgTime($p->getAttribute('start'));
            if ($s && $s >= $todayZero) {
                $hasTodayProgram = true;
                break;
            }
        }
    }
    foreach ($matchedPriorities as $prio) {
        if (in_array($prio, $channelFailedPriorities[$lockKey])) continue;
        $hasData = isset($programmes[$channelId]) && count($programmes[$channelId]) >= MIN_PROGRAM_COUNT;
        if ($hasTodayProgram && $currentSourcePriority === $prio) {
            $channelPriorityLock[$lockKey] = $prio;
            return true;
        }
        if (!$hasData && $currentSourcePriority === $prio) {
            return true;
        }
        if ($hasData && $currentSourcePriority === $prio) {
            $channelPriorityLock[$lockKey] = $prio;
            return true;
        }
    }
    if (!$hasTodayProgram && in_array($currentSourcePriority, $matchedPriorities)) {
        $channelFailedPriorities[$lockKey][] = $currentSourcePriority;
    }
    return false;
}

// ====================== 核心工具函数 ======================
function loadConfigAndAlias(string $configFile): array {
    $configNames = [];
    $aliasMapping = [];
    $channelOrder = [];
    if (!file_exists($configFile)) {
        logMessage('ERROR', "配置文件不存在：{$configFile}");
        return [$configNames, $aliasMapping, $channelOrder];
    }
    try {
        $handle = fopen($configFile, 'r');
        if (!$handle) {
            throw new Exception("无法打开配置文件");
        }
        while (($line = fgets($handle)) !== false) {
            $cleanLine = trim((string)$line);
            if (empty($cleanLine) || str_starts_with($cleanLine, '#')) {
                continue;
            }
            $parts = array_filter(array_map('trim', explode(',', (string)$cleanLine)));
            if (empty($parts)) continue;
            $standardName = $parts[0];
            $configNames[$standardName] = true;
            $channelOrder[] = $standardName;
            if (count($parts) > 1) {
                foreach (array_slice($parts, 1) as $alias) {
                    $aliasMapping[$alias] = $standardName;
                }
            }
        }
        fclose($handle);
        logMessage('INFO', sprintf("加载 %d 个频道，%d 个别名映射", count($configNames), count($aliasMapping)));
        return [$configNames, $aliasMapping, $channelOrder];
    } catch (Throwable $e) {
        logMessage('ERROR', "加载配置失败：{$e->getMessage()}");
        return [[], [], []];
    }
}
function mapChannel(string $displayName, array $configNames, array $aliasMapping): ?string {
    if (isset($configNames[$displayName])) {
        logMessage('DEBUG', "直接匹配成功: {$displayName}");
        return $displayName;
    }
    if (isset($aliasMapping[$displayName])) {
        $mappedName = $aliasMapping[$displayName];
        if (isset($configNames[$mappedName])) {
            logMessage('DEBUG', "别名映射成功: {$displayName} -> {$mappedName}");
            return $mappedName;
        }
    }
    return null;
}
function parseEpgTime(string $timeStr): ?DateTime {
    if (empty($timeStr)) return null;
    $timeStr = trim($timeStr);
    $pureTime = preg_replace('/[^0-9]/', '', $timeStr);
    if (strlen($pureTime) >= 14) {
        $pureTime = substr($pureTime, 0, 14);
    }
    $parts = explode(' ', $timeStr);
    $tzPart = isset($parts[1]) ? trim($parts[1]) : '';
    try {
        $dt = DateTime::createFromFormat('YmdHis', $pureTime, EpgConfig::$TIMEZONE);
        if (!$dt instanceof DateTime) return null;
        if (str_ends_with($timeStr, 'Z') || $tzPart === 'Z') {
            $dt->setTimezone(EpgConfig::$UTC_ZONE);
        }
        $dt->setTimezone(EpgConfig::$TIMEZONE);
        return $dt;
    } catch (Throwable $e) {
        return null;
    }
}

function tryParseXml(string $content): ?DOMDocument {
    if (!is_string($content) || trim($content) === '') return null;
    libxml_use_internal_errors(true);
    libxml_clear_errors();
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    
    try {
        $content = preg_replace('/^\x{EF}\x{BB}\x{BF}/u', '', (string)$content);
        
        $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $content);
            if ($converted !== false) {
                $content = $converted;
                logMessage('DEBUG', "XML编码转换：{$encoding} -> UTF-8");
            }
        }
        
        if (!$encoding || $encoding === 'UTF-8') {
            $testDom = new DOMDocument();
            $loadResult = @$testDom->loadXML($content, LIBXML_NOENT | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$loadResult) {
                $tryEncodings = ['GBK', 'GB2312', 'BIG5'];
                foreach ($tryEncodings as $enc) {
                    $converted = @iconv($enc, 'UTF-8//IGNORE', $content);
                    if ($converted && @$testDom->loadXML($converted, LIBXML_NOENT | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                        $content = $converted;
                        logMessage('DEBUG', "XML编码兜底转换：{$enc} -> UTF-8");
                        break;
                    }
                }
            }
            unset($testDom);
        }
        
        $loadResult = @$dom->loadXML($content, LIBXML_NOENT | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$loadResult) {
            libxml_clear_errors();
            return null;
        }
        return $dom;
    } catch (Throwable $e) {
        libxml_clear_errors();
        return null;
    } finally {
        libxml_use_internal_errors(false);
    }
}

function normalizeTime(string $timeStr): string {
    if (empty($timeStr)) return $timeStr;
    $dt = parseEpgTime($timeStr);
    if (!$dt) return $timeStr;
    try {
        $minute = (int)$dt->format('i');
        $alignedMinute = intval(floor($minute / TIME_ALIGN_INTERVAL) * TIME_ALIGN_INTERVAL);
        $dt->setTime((int)$dt->format('H'), $alignedMinute, 0);
        return $dt->format('YmdHis') . ' +0800';
    } catch (Throwable $e) {
        return $timeStr;
    }
}
function getProgramQuality(DOMElement $program): int {
    $score = 0;
    $xpath = new DOMXPath($program->ownerDocument);
    $sourcePriority = (int)$program->getAttribute('data-source-priority');
    $priorityScores = [1=>90,2=>85,3=>80,4=>75,5=>70,6=>65,7=>60,8=>55,9=>50,10=>45,11=>40,12=>35,13=>30,14=>25,15=>20,16=>15,16=>10,17=>8,18=>6,19=>4,20=>2];
    $score += $priorityScores[$sourcePriority] ?? 1;
    $startTs = parseEpgTime($program->getAttribute('start'));
    $todayZero = new DateTime('today', EpgConfig::$TIMEZONE);
    if ($startTs >= $todayZero) {
        $score += 12;
    }
    $desc = $xpath->query('.//desc', $program)->item(0);
    if ($desc && !empty(trim($desc->nodeValue))) {
        $len = mb_strlen(trim($desc->nodeValue), 'UTF-8');
        $score += $len <=50 ? 2 : ($len <=200 ?5 :8);
    }
    return $score;
}
function programsOverlap(DOMElement $prog1, DOMElement $prog2): bool {
    $start1 = parseEpgTime($prog1->getAttribute('start'));
    $stop1 = parseEpgTime($prog1->getAttribute('stop'));
    $start2 = parseEpgTime($prog2->getAttribute('start'));
    $stop2 = parseEpgTime($prog2->getAttribute('stop'));
    if (!$start1 || !$stop1 || !$start2 || !$stop2) return false;
    $overlapStart = max($start1->getTimestamp(), $start2->getTimestamp());
    $overlapEnd = min($stop1->getTimestamp(), $stop2->getTimestamp());
    return ($overlapStart < $overlapEnd) && (($overlapEnd - $overlapStart)/60 >= OVERLAP_THRESHOLD);
}

// ====================== CURL相关函数 ======================
function createCurlHandle() {
    $ch = curl_init();
    if (!$ch) {
        logMessage('ERROR', "CURL初始化失败");
        return null;
    }
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9',
        'Accept-Encoding: gzip, deflate',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
    ];
    $curlOpts = [
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_LOW_SPEED_LIMIT => CURL_LOW_SPEED_LIMIT,
        CURLOPT_LOW_SPEED_TIME => CURL_LOW_SPEED_TIME,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if (defined('CURL_SSLVERSION_TLSv1_2')) {
        $curlOpts[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
    }
    if (defined('CURL_SSL_CIPHER_LIST') && !empty(CURL_SSL_CIPHER_LIST)) {
        $curlOpts[CURLOPT_SSL_CIPHER_LIST] = CURL_SSL_CIPHER_LIST;
    }
    curl_setopt_array($ch, $curlOpts);
    logMessage('DEBUG', "CURL句柄创建成功");
    return $ch;
}
function fetchRemoteContent($ch, array $source) {
    $url = $source['url'];
    $priority = $source['priority'];
    if (!str_starts_with(strtolower((string)$url), 'http')) {
        if (!file_exists($url)) return null;
        $content = file_get_contents($url);
        if ($content === false) return null;
        $uncompressed = @gzdecode((string)$content);
        return $uncompressed !== false ? [$uncompressed, $priority] : [$content, $priority];
    }
    $retryTimes = CURL_RETRY_TIMES;
    $success = false;
    $isLargeFile = in_array((string)$url, LARGE_FILE_URLS);
    while ($retryTimes >= 0 && !$success) {
        curl_setopt($ch, CURLOPT_TIMEOUT, $isLargeFile ? CURL_LARGE_FILE_TIMEOUT : CURL_TIMEOUT);
        curl_setopt($ch, CURLOPT_URL, $url);
        $content = curl_exec($ch);
        if (curl_errno($ch) === 0) {$success = true; break;}
        $retryTimes--; 
        usleep(rand(100000, 2500000));
    }
    if (!$success) return null;
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code <200 || $code >=300) return null;
    $uncompressed = @gzdecode((string)$content);
    return $uncompressed !== false ? [$uncompressed, $priority] : [$content, $priority];
}

// ====================== 节点处理函数 ======================
function processChannelNodes(DOMXPath $xpath, array $configNames, array $aliasMapping, DOMDocument $finalDoc, array &$channels, array $filterRules, string $sourceRemark = ''): array {
    $channelMap = [];
    $totalChannels = 0;
    $matchedCount = 0;
    $filteredCount = 0;
    $unmatchedNames = [];
    $channelNodes = $xpath->query('//channel');
    foreach ($channelNodes as $chan) {
        $totalChannels++;
        $origId = $chan->getAttribute('id');
        $displayNode = $xpath->query('.//display-name[@lang="zh"]', $chan)->item(0) ?? $xpath->query('.//display-name', $chan)->item(0);
        if (!$displayNode || empty(trim($displayNode->nodeValue))) continue;
        $displayName = trim($displayNode->nodeValue);
        if (isChannelExcluded($displayName, $filterRules)) {
            $filteredCount++;
            continue;
        }
        $mappedId = mapChannel($displayName, $configNames, $aliasMapping);
        if (!$mappedId) {
            if (!isset($unmatchedNames[$displayName])) {
                $unmatchedNames[$displayName] = true;
                logMessage('DEBUG', "频道未匹配：【{$displayName}】(原始ID: {$origId})");
            }
            continue;
        }
        $matchedCount++;
        $channelMap[$origId] = $mappedId;
        if (!isset($channels[$mappedId])) {
            $newChan = $finalDoc->importNode($chan, true);
            $newChan->setAttribute('data-source-remark', xmlEscape($sourceRemark));
            $newChan->setAttribute('id', $mappedId);
            $dnNodes = $newChan->getElementsByTagName('display-name');
            for ($i = $dnNodes->length - 1; $i >= 0; $i--) $newChan->removeChild($dnNodes->item($i));
            $newDn = $finalDoc->createElement('display-name', xmlEscape($mappedId));
            $newDn->setAttribute('lang', DEFAULT_LANG);
            $newChan->appendChild($newDn);
            $channels[$mappedId] = $newChan;
        }
    }
    logMessage('DEBUG', "本数据源频道统计：总数 {$totalChannels}，匹配成功 {$matchedCount}，过滤排除 {$filteredCount}，未匹配 " . count($unmatchedNames));
    return [
        'channelMap' => $channelMap,
        'total_channels' => $totalChannels,
        'matched_channels' => $matchedCount,
        'filtered_channels' => $filteredCount
    ];
}

function processProgramNodes(DOMXPath $xpath, array $channelMap, DOMDocument $finalDoc, array &$programmes, int $sourcePriority): int {
    $progCount = 0;
    $todayCount = 0;
    $todayZero = new DateTime('today', EpgConfig::$TIMEZONE);
    
    $pastDate = (clone $todayZero)->sub(new DateInterval("P".KEEP_PAST_DAYS."D"));
    $futureDate = (clone $todayZero)->add(new DateInterval("P".KEEP_FUTURE_DAYS."D"));
    
    $progNodes = $xpath->query('//programme');
    foreach ($progNodes as $prog) {
        $origChanId = $prog->getAttribute('channel');
        if (!isset($channelMap[$origChanId])) continue;
        $mappedId = $channelMap[$origChanId];
        
        $startTimeStr = $prog->getAttribute('start');
        $stopTimeStr = $prog->getAttribute('stop');
        $startTime = parseEpgTime($startTimeStr);
        $stopTime = parseEpgTime($stopTimeStr);
        
        if (!$startTime || !$stopTime) continue;
        
        if (in_array($mappedId, CHANNEL_1905)) {
            if ($stopTime < $todayZero) continue;
        } else {
            $inRange = false;
            if ($startTime >= $pastDate && $startTime <= $futureDate) $inRange = true;
            if ($stopTime >= $pastDate && $stopTime <= $futureDate) $inRange = true;
            if ($startTime < $pastDate && $stopTime > $futureDate) $inRange = true;
            if (!$inRange) continue;
        }
        
        collectProgramDescOnly($prog, $mappedId, $sourcePriority);
        
        if (!isExclusiveSourceForChannel($mappedId, $sourcePriority, $programmes)) continue;
        
        $processed = processProgramme($prog, $mappedId, $finalDoc, $sourcePriority);
        if ($processed) {
            if ($startTime >= $todayZero) $todayCount++;
            if (!isset($programmes[$mappedId])) $programmes[$mappedId] = [];
            if (count($programmes[$mappedId]) < MAX_PROGRAMS_PER_CHANNEL) {
                $programmes[$mappedId][] = $processed;
                $progCount++;
            }
        }
    }
    logMessage('DEBUG', "当前数据源优先级{$sourcePriority}，解析到今日节目：{$todayCount} 条");
    return $progCount;
}

// ====================== 日期过滤函数（二次兜底） ======================
function filterProgramsByDate(array $programmes): array {
    $filtered = [];
    $today = new DateTime('today', EpgConfig::$TIMEZONE);
    $pastDate = (clone $today)->sub(new DateInterval("P".KEEP_PAST_DAYS."D"));
    $futureDate = (clone $today)->add(new DateInterval("P".KEEP_FUTURE_DAYS."D"));
    foreach ($programmes as $channelId => $progs) {
        $channelProgs = [];
        foreach ($progs as $prog) {
            $startTime = parseEpgTime($prog->getAttribute('start'));
            $stopTime = parseEpgTime($prog->getAttribute('stop'));
            if (!$startTime || !$stopTime) continue;
            if (in_array($channelId, CHANNEL_1905)) {
                if ($stopTime >= $today) {
                    $channelProgs[] = $prog;
                }
                continue;
            }
            $inTimeRange = false;
            if ($startTime >= $pastDate && $startTime <= $futureDate) $inTimeRange = true;
            if ($stopTime >= $pastDate && $stopTime <= $futureDate) $inTimeRange = true;
            if ($startTime < $pastDate && $stopTime > $futureDate) $inTimeRange = true;
            if ($inTimeRange) {
                $channelProgs[] = $prog;
            }
        }
        if (!empty($channelProgs)) $filtered[$channelId] = $channelProgs;
    }
    return $filtered;
}

// ====================== 节目去重函数（分组式优化版） ======================
function deduplicatePrograms(array $progs): array {
    if (empty($progs)) return [];
    
    $timeGroups = [];
    foreach ($progs as $prog) {
        $startStr = $prog->getAttribute('start');
        $normalizedStart = normalizeTime($startStr);
        $timeKey = substr($normalizedStart, 0, 12);
        
        $xpath = new DOMXPath($prog->ownerDocument);
        $titleElem = $xpath->query('.//title', $prog)->item(0);
        $titleText = $titleElem ? trim($titleElem->nodeValue) : '';
        $normalizedTitle = normalizeTitleV2(cleanProgramTitle($titleText, $prog->getAttribute('channel')));
        
        $channelId = $prog->getAttribute('channel');
        $groupKey = "{$channelId}|{$timeKey}|{$normalizedTitle}";
        
        if (!isset($timeGroups[$groupKey])) {
            $timeGroups[$groupKey] = [];
        }
        $timeGroups[$groupKey][] = $prog;
    }
    
    $selected = [];
    foreach ($timeGroups as $group) {
        if (count($group) === 1) {
            $selected[] = $group[0];
            continue;
        }
        usort($group, fn($a, $b) => getProgramQuality($b) - getProgramQuality($a));
        $selected[] = $group[0];
    }
    
    usort($selected, fn($a, $b) => 
        parseEpgTime($a->getAttribute('start'))->getTimestamp() 
        - parseEpgTime($b->getAttribute('start'))->getTimestamp()
    );
    
    $final = [];
    foreach ($selected as $prog) {
        $overlap = false;
        $progStart = parseEpgTime($prog->getAttribute('start'))->getTimestamp();
        $progStop = parseEpgTime($prog->getAttribute('stop'))->getTimestamp();
        
        foreach ($final as $idx => $existProg) {
            $existStart = parseEpgTime($existProg->getAttribute('start'))->getTimestamp();
            $existStop = parseEpgTime($existProg->getAttribute('stop'))->getTimestamp();
            
            $overlapStart = max($progStart, $existStart);
            $overlapEnd = min($progStop, $existStop);
            $overlapMin = ($overlapEnd - $overlapStart) / 60;
            
            if ($overlapMin >= OVERLAP_THRESHOLD) {
                if (getProgramQuality($prog) > getProgramQuality($existProg)) {
                    $final[$idx] = $prog;
                }
                $overlap = true;
                break;
            }
        }
        
        if (!$overlap) {
            $final[] = $prog;
        }
    }
    
    $final = array_values($final);
    for ($i = 0; $i < count($final) - 1; $i++) {
        $current = $final[$i];
        $nextProg = $final[$i + 1];
        
        $currentStop = parseEpgTime($current->getAttribute('stop'));
        $nextStart = parseEpgTime($nextProg->getAttribute('start'));
        
        if ($currentStop > $nextStart) {
            $adjustedStop = (clone $nextStart)->sub(new DateInterval('PT1S'));
            $current->setAttribute('stop', $adjustedStop->format('YmdHis') . ' +0800');
        }
    }
    
    return array_slice($final, 0, MAX_PROGRAMS_PER_CHANNEL);
}

// ====================== 数据源加载 ======================
function loadSourcesFromJson(): array {
    if (!file_exists(SOURCES_JSON_FILE)) return [];
    $json = file_get_contents(SOURCES_JSON_FILE);
    $allSources = json_decode($json, true) ?: [];
    $enabledSources = [];
    $disabledCount = 0;
    foreach ($allSources as $source) {
        $isEnabled = $source['enabled'] ?? true;
        if ($isEnabled) {
            $enabledSources[] = $source;
        } else {
            $disabledCount++;
        }
    }
    if ($disabledCount > 0) {
        logMessage('INFO', "远程数据源配置共 " . count($allSources) . " 个，已禁用 {$disabledCount} 个，执行时自动跳过");
    }
    return $enabledSources;
}

// ====================== 主处理流程 ======================
function processSources(array $sources, array $aliasMapping, array $configNames, array $channelOrder, array $defaultFilter): array {
    global $scriptStartTime;
    $finalDoc = new DOMDocument('1.0', 'utf-8');
    $tvRoot = $finalDoc->createElement('tv');
    $finalDoc->appendChild($tvRoot);
    $channels = [];
    $programmes = [];
    $sourceSuccess = 0;
    $sourceFail = 0;
    $sourceStats = [];
    $ch = createCurlHandle();
    if (!$ch) return [0, 0, 0, 0, []];
    foreach ($sources as $idx => $source) {
        checkExecutionTimeout($scriptStartTime);
        checkMemoryUsage('数据源处理中');
        $url = $source['url'];
        $prio = $source['priority'];
        $remark = $source['remark'] ?? '未知源';
        $filter = array_merge_recursive($defaultFilter, $source['filter'] ?? []);
        logMessage('INFO', "处理数据源：{$url} ({$remark})");
        $statItem = [
            'remark' => $remark,
            'total_channels' => 0,
            'total_programs' => 0,
            'matched_channels' => 0
        ];
        $fetch = fetchRemoteContent($ch, $source);
        if (!$fetch) {
            logMessage('WARNING', "数据源获取失败：{$url}");
            $sourceFail++;
            $sourceStats[] = $statItem;
            continue;
        }
        [$content, $priority] = $fetch;
        $dom = tryParseXml($content);
        if (!$dom) {
            logMessage('WARNING', "XML解析失败：{$url}");
            $sourceFail++;
            $sourceStats[] = $statItem;
            continue;
        }
        $xpath = new DOMXPath($dom);
        $channelResult = processChannelNodes($xpath, $configNames, $aliasMapping, $finalDoc, $channels, $filter, $remark);
        $channelMap = $channelResult['channelMap'];
        $allChannelNodes = $xpath->query('//channel');
        $statItem['total_channels'] = $allChannelNodes->length;
        $statItem['matched_channels'] = $channelResult['matched_channels'];
        $cnt = processProgramNodes($xpath, $channelMap, $finalDoc, $programmes, $priority);
        $allProgNodes = $xpath->query('//programme');
        $statItem['total_programs'] = $allProgNodes->length;
        logMessage('INFO', "数据源完成，节目数：{$cnt}");
        $sourceSuccess++;
        $sourceStats[] = $statItem;
        freeMemory($dom, $xpath, $content);
    }
    curl_close($ch);
    $programmes = filterProgramsByDate($programmes);
    $finalProgrammes = [];
    foreach ($programmes as $cid => $ps) {
        if (count($ps) >= MIN_PROGRAM_COUNT) {
            $finalProgrammes[$cid] = deduplicatePrograms($ps);
        }
    }
    foreach ($channelOrder as $cid) {
        if (isset($channels[$cid], $finalProgrammes[$cid])) {
            $tvRoot->appendChild($channels[$cid]);
            foreach ($finalProgrammes[$cid] as $p) {
                $tvRoot->appendChild($p);
            }
        }
    }
    // ========== 输出含简介版本 ==========
    if (DESC_SPLIT_ENABLED) {
        $splitIndex = 1;
        $currentBuffer = '';
        $uncompressedThreshold = DESC_SPLIT_TARGET_MB * 1024 * 1024 * DESC_SPLIT_EST_RATIO;
        $xmlHeader = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . '<tv>' . PHP_EOL;
        $xmlFooter = PHP_EOL . '</tv>';
        $descDir = dirname(OUTPUT_FILE_DESC_GZ) . '/';
        $descBase = basename(OUTPUT_FILE_DESC_GZ, '.xml.gz');
        foreach ($channelOrder as $cid) {
            if (!isset($channels[$cid], $finalProgrammes[$cid])) continue;
            
            $chanXml = $finalDoc->saveXML($channels[$cid]) . PHP_EOL;
            $progsXml = '';
            foreach ($finalProgrammes[$cid] as $prog) {
                $progsXml .= $finalDoc->saveXML($prog) . PHP_EOL;
            }
            $fragment = $chanXml . $progsXml;
            
            if (strlen($currentBuffer) > 0 && strlen($currentBuffer) + strlen($fragment) > $uncompressedThreshold) {
                $fullXml = $xmlHeader . $currentBuffer . $xmlFooter;
                $fileName = $descDir . $descBase . '-' . $splitIndex . '.xml.gz';
                $tempName = $fileName . '.tmp';
                ensureDirExists($fileName);
                $gz = gzopen($tempName, 'wb' . GZIP_COMPRESS_LEVEL);
                gzwrite($gz, $fullXml);
                gzclose($gz);
                rename($tempName, $fileName);
                
                $sizeMb = round(filesize($fileName)/1024/1024, 2);
                logMessage('INFO', "生成简介分片：{$fileName}，压缩后大小：{$sizeMb} MB");
                
                $splitIndex++;
                $currentBuffer = $fragment;
            } else {
                $currentBuffer .= $fragment;
            }
        }
        
        if (strlen($currentBuffer) > 0) {
            $fullXml = $xmlHeader . $currentBuffer . $xmlFooter;
            $fileName = $descDir . $descBase . '-' . $splitIndex . '.xml.gz';
            $tempName = $fileName . '.tmp';
            ensureDirExists($fileName);
            $gz = gzopen($tempName, 'wb' . GZIP_COMPRESS_LEVEL);
            gzwrite($gz, $fullXml);
            gzclose($gz);
            rename($tempName, $fileName);
            
            $sizeMb = round(filesize($fileName)/1024/1024, 2);
            logMessage('INFO', "生成简介分片：{$fileName}，压缩后大小：{$sizeMb} MB");
        }
        
        unset($currentBuffer, $xmlHeader, $xmlFooter, $fullXml, $gz, $fragment);
        logMessage('INFO', "含简介版本分片完成，共生成 {$splitIndex} 个文件");
    } else {
        try {
            ensureDirExists(OUTPUT_FILE_DESC_GZ);
            $gzDesc = gzopen(OUTPUT_FILE_DESC_TEMP, 'wb' . GZIP_COMPRESS_LEVEL);
            if (!$gzDesc) throw new Exception("无法创建含简介输出文件");
            
            gzwrite($gzDesc, '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . '<tv>' . PHP_EOL);
            
            foreach ($channelOrder as $cid) {
                if (!isset($channels[$cid], $finalProgrammes[$cid])) continue;
                gzwrite($gzDesc, $finalDoc->saveXML($channels[$cid]) . PHP_EOL);
                foreach ($finalProgrammes[$cid] as $prog) {
                    gzwrite($gzDesc, $finalDoc->saveXML($prog) . PHP_EOL);
                }
            }
            
            gzwrite($gzDesc, '</tv>');
            gzclose($gzDesc);
            
            if (file_exists(OUTPUT_FILE_DESC_GZ)) {
                copy(OUTPUT_FILE_DESC_GZ, OUTPUT_FILE_DESC_GZ_BACKUP);
            }
            rename(OUTPUT_FILE_DESC_TEMP, OUTPUT_FILE_DESC_GZ);
            logMessage('INFO', "含简介单文件生成完成");
        } catch (Throwable $e) {
            logMessage('ERROR', "生成含简介单文件失败：{$e->getMessage()}");
            @gzclose($gzDesc);
            @unlink(OUTPUT_FILE_DESC_TEMP);
        }
    }
    // ========== 输出无简介版本 ==========
    try {
        ensureDirExists(OUTPUT_FILE_GZ);
        $gz = gzopen(OUTPUT_FILE_TEMP, 'wb' . GZIP_COMPRESS_LEVEL);
        if (!$gz) throw new Exception("无法创建无简介输出文件");
        
        gzwrite($gz, '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . '<tv>' . PHP_EOL);
        
        foreach ($channelOrder as $cid) {
            if (!isset($channels[$cid], $finalProgrammes[$cid])) continue;
            gzwrite($gz, $finalDoc->saveXML($channels[$cid]) . PHP_EOL);
            foreach ($finalProgrammes[$cid] as $prog) {
                $progClone = $prog->cloneNode(true);
                $descNodes = $progClone->getElementsByTagName('desc');
                while ($descNodes->length > 0) {
                    $descNodes->item(0)->parentNode->removeChild($descNodes->item(0));
                }
                gzwrite($gz, $finalDoc->saveXML($progClone) . PHP_EOL);
            }
        }
        
        gzwrite($gz, '</tv>');
        gzclose($gz);
        
        if (file_exists(OUTPUT_FILE_GZ)) {
            copy(OUTPUT_FILE_GZ, OUTPUT_FILE_GZ_BACKUP);
        }
        rename(OUTPUT_FILE_TEMP, OUTPUT_FILE_GZ);
        logMessage('INFO', "无简介单文件生成完成");
    } catch (Throwable $e) {
        logMessage('ERROR', "生成无简介单文件失败：{$e->getMessage()}");
        @gzclose($gz);
        @unlink(OUTPUT_FILE_TEMP);
    }

    $chanCnt = count($channels);
    $progCnt = array_sum(array_map('count', $finalProgrammes ?? []));
    
    unset($finalDoc);
    
    flushDescBatch();
    if (date('d') == DESC_AUTO_CLEAN_DAY) {
        cleanExpiredDescData();
    }
    getDescDbStats();
    closeDescDbConnection();
    
    unset($channels, $finalProgrammes);
    
    logMessage('INFO', "完成！频道：{$chanCnt}，节目：{$progCnt}");
    return [$chanCnt, $progCnt, $sourceSuccess, $sourceFail, $sourceStats];
}

// ==================== 入口执行 ====================
$defaultFilter = ['excludeChannelNames' => [], 'excludeChannelPatterns' => []];
$epgSources = loadSourcesFromJson();
$localDir = rtrim(LOCAL_EPG_DIR, '/') . '/';
$prio = 5;
foreach (glob($localDir . '*.xml') as $f) {
    $epgSources[] = [
        'url' => $f,
        'priority' => $prio++,
        'remark' => '本地' . basename($f),
        'filter' => []
    ];
}
foreach (glob($localDir . '*.xml.gz') as $f) {
    $epgSources[] = [
        'url' => $f,
        'priority' => $prio++,
        'remark' => '本地GZ' . basename($f),
        'filter' => []
    ];
}
logMessage('INFO', "===== EPG处理开始 =====");
cleanExpiredLogs();
[$configNames, $aliasMapping, $channelOrder] = loadConfigAndAlias(CONFIG_FILE);
if (empty($configNames)) {
    releaseProcessLock();
    exit(1);
}
getDescDbConnection();
loadManMadeData();
buildFuzzyGlobalIndex();
$GLOBALS['script_start_time'] = time();
[$chanCnt, $progCnt, $sourceSuccess, $sourceFail, $sourceStats] = processSources($epgSources, $aliasMapping, $configNames, $channelOrder, $defaultFilter);

// ====================== 最终统计输出 ======================
$useTime = round((microtime(true) - $scriptStartTime), 2);
$memoryPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
$backupPlain = file_exists(OUTPUT_FILE_GZ_BACKUP) ? "已生成" : "未生成";
logMessage('INFO', "============= 最终生成结果 =============");
logMessage('INFO', "输出文件（无简介）：" . OUTPUT_FILE_GZ);
if (file_exists(OUTPUT_FILE_GZ)) {
    $size = filesize(OUTPUT_FILE_GZ);
    $sizeMb = round($size / 1024 / 1024, 2);
    logMessage('INFO', "文件大小：{$sizeMb} MB");
}
if (DESC_SPLIT_ENABLED) {
    $descDir = dirname(OUTPUT_FILE_DESC_GZ) . '/';
    $descBase = basename(OUTPUT_FILE_DESC_GZ, '.xml.gz');
    $splitFiles = glob($descDir . $descBase . '-*.xml.gz');
    logMessage('INFO', "含简介分片文件目录：{$descDir}");
    logMessage('INFO', "含简介分片总数：" . count($splitFiles) . " 个");
    foreach ($splitFiles as $f) {
        $s = round(filesize($f)/1024/1024, 2);
        logMessage('INFO', "  - " . basename($f) . " : {$s} MB");
    }
} else {
    logMessage('INFO', "输出文件（含简介）：" . OUTPUT_FILE_DESC_GZ);
    if (file_exists(OUTPUT_FILE_DESC_GZ)) {
        $sizeDesc = filesize(OUTPUT_FILE_DESC_GZ);
        $sizeDescMb = round($sizeDesc / 1024 / 1024, 2);
        logMessage('INFO', "文件大小：{$sizeDescMb} MB");
    }
}
logMessage('INFO', "最终频道数量：{$chanCnt} 个");
logMessage('INFO', "最终节目总数：{$progCnt} 条");
logMessage('INFO', "生成耗时：{$useTime} 秒");
logMessage('INFO', "内存峰值使用：{$memoryPeak} MB");
logMessage('INFO', "数据源处理成功：{$sourceSuccess} 个");
logMessage('INFO', "数据源处理失败：{$sourceFail} 个");
logMessage('INFO', "无简介版备份：{$backupPlain}");
logMessage('INFO', "");
logMessage('INFO', "EPG 源统计");
logMessage('INFO', "--------------------------------------");
foreach ($sourceStats as $stat) {
    $line = sprintf(
        "[%s] 频道: %d, 节目: %d, 匹配: %d",
        $stat['remark'],
        $stat['total_channels'],
        $stat['total_programs'],
        $stat['matched_channels']
    );
    logMessage('INFO', $line);
}
logMessage('INFO', "========================================");
releaseProcessLock();
logMessage('INFO', "全部完成！");
