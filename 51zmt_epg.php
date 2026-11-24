<?php
// ======================== 配置区（可自行修改） ========================
define('Z51_CACHE_DIR', __DIR__ . '/cache');    // 缓存目录
define('OUTPUT_GZ', __DIR__ . '/epg_sources/epg_51zmt.xml.gz'); // 最终输出GZ文件
define('T2S_JSON_PATH', __DIR__ . '/t2s.json'); // 繁简映射文件路径
define('Z51_TTL', 6 * 3600);                    // 数据缓存有效期（秒），默认6小时
define('TIMEZONE', 'Asia/Shanghai');            // 默认时区

// 数据源列表
$Z51_PACKS = [
    ['key' => 'e1', 'url' => 'http://epg.51zmt.top:8000/e1.xml.gz'],
    ['key' => 'e2', 'url' => 'http://epg.51zmt.top:8000/e2.xml.gz'],
];

// 需要删除的频道（支持原始ID、频道名称，自动适配繁简、全角半角、高清后缀）
$channelsToRemove = [
    '3D电视试验频道',
    'noepg',
    '东方购物-1'
];

// ======================== 初始化与环境检查 ========================
date_default_timezone_set(TIMEZONE);

// 必需扩展检查，缺失时给出明确提示
$requiredExtensions = ['curl', 'zlib', 'mbstring', 'dom', 'libxml'];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $msg = "缺少必需扩展：{$ext}，请在PHP环境中安装后再使用";
        if (PHP_SAPI === 'cli') {
            echo $msg . PHP_EOL;
            exit(1);
        } else {
            die($msg);
        }
    }
}

// 全局变量
$z51Memo = null;
$logMessages = [];
$globalHasError = false;

// ======================== 日志函数 ========================
function z51_log(string $level, string $msg): void {
    global $logMessages;
    $time = date('H:i:s');
    $logMessages[] = ['time' => $time, 'level' => $level, 'msg' => $msg];
}

// ======================== 繁简转换 ========================
function loadT2SMap(): array {
    static $map = null;
    if ($map !== null) return $map;

    if (!file_exists(T2S_JSON_PATH)) {
        throw new Exception('未找到繁简映射文件：' . T2S_JSON_PATH);
    }
    $json = file_get_contents(T2S_JSON_PATH);
    $map = json_decode($json, true);
    if (!is_array($map)) {
        throw new Exception('t2s.json 格式错误，必须为标准 JSON 对象');
    }
    return $map;
}

function toSimplified(string $str): string {
    $map = loadT2SMap();
    if (empty($map)) return $str;
    return strtr($str, $map);
}

// ======================== 工具函数（PCRE2兼容） ========================
function decodeEntities(?string $s): string {
    if ($s === null || $s === '') return '';
    $prev = '';
    while ($prev !== $s) {
        $prev = $s;
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $s;
}

function hasRealDesc(?string $desc, string $title): bool {
    if ($desc === null || trim($desc) === '') return false;

    $placeholders = [
        '暂无节目描述', '暂无简介', '暂无节目简介',
        '此阶段播放精彩电视剧内容', '精彩节目', '精彩内容',
        '节目以实际播出为准', '以实际播出为准', '暂无节目单',
        '节目內容', '精彩放送'
    ];
    foreach ($placeholders as $p) {
        if (mb_strpos($desc, $p) !== false) return false;
    }

    $normDesc = normTitleLoose($desc);
    $normTitle = normTitleLoose($title);
    if ($normDesc && $normTitle && $normDesc === $normTitle) return false;
    if (mb_strlen(trim($desc)) < 4) return false;

    return true;
}

function normTitleLoose(?string $s): string {
    if ($s === null) return '';
    $x = toSimplified((string)$s);
    $x = str_replace(['&', ' '], ['&', ' '], $x);

    // 全角转半角（PCRE2兼容，加/u修饰符）
    $x = preg_replace_callback('/[\x{ff01}-\x{ff5e}]/u', function ($m) {
        return mb_chr(mb_ord($m[0]) - 0xfee0);
    }, $x);

    // 移除括号及内容
    $x = preg_replace('/[（(][^（()）]*[）)]/u', '', $x);
    $x = preg_replace('/《[^》]*》/u', '', $x);

    // 移除期号/年份后缀
    $x = preg_replace('/[-–—]\s*\d{4}\s*[-–—]\s*\d+$/u', '', $x);
    $x = preg_replace('/\d{4}\s*[-–—]\s*\d+$/u', '', $x);
    $x = preg_replace('/[-–—]\s*\d{4}$/u', '', $x);

    // 移除尾部裸集数（兼容PCRE2，无后向断言）
    $x = preg_replace_callback('/([\x{4e00}-\x{9fa5}]{2,})(\d{1,3})$/u', function ($m) {
        return $m[1];
    }, $x);

    // 仅保留汉字、数字、字母
    $x = preg_replace('/[^\x{4e00}-\x{9fa5}a-z0-9]/ui', '', $x);
    return mb_strtolower($x);
}

function normalizeZ51Name(string $s): string {
    if (!$s) return '';
    $x = toSimplified($s);

    $x = preg_replace_callback('/[\x{ff01}-\x{ff5e}]/u', function ($m) {
        return mb_chr(mb_ord($m[0]) - 0xfee0);
    }, $x);

    $x = preg_replace('/[（(][^）)]*[）)]/u', '', $x);
    $x = preg_replace('/(高清|超清|标清|HD|频道|节目单)$/iu', '', $x);
    $x = preg_replace('/[^\x{4e00}-\x{9fa5}a-z0-9]/ui', '', $x);

    return mb_strtolower($x);
}

function parseZ51Time(?string $s): ?int {
    if ($s === null || trim($s) === '') return null;
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})?\s*([+-]\d{4})?/', trim($s), $m)) {
        return null;
    }

    $y = $m[1];
    $mo = $m[2];
    $d = $m[3];
    $hh = $m[4];
    $mi = $m[5] ?? '00';
    $ss = $m[6] ?? '00';
    $off = $m[7] ?? '+0800';

    $timeStr = "{$y}-{$mo}-{$d}T{$hh}:{$mi}:{$ss}" . substr($off, 0, 3) . ':' . substr($off, 3);
    $ts = strtotime($timeStr);
    return $ts === false ? null : $ts;
}

// ======================== 下载与缓存 ========================
function ensureCacheDir(): void {
    if (!is_dir(Z51_CACHE_DIR)) {
        if (!mkdir(Z51_CACHE_DIR, 0755, true)) {
            throw new Exception('缓存目录创建失败，请检查目录权限：' . Z51_CACHE_DIR);
        }
    }
    if (!is_writable(Z51_CACHE_DIR)) {
        throw new Exception('缓存目录不可写，请设置 755 权限：' . Z51_CACHE_DIR);
    }
}

function cachePath(string $key): string {
    // 安全过滤：防止路径穿越，仅保留合法文件名
    $key = basename($key);
    return Z51_CACHE_DIR . '/z51_' . $key . '.xml';
}

function isFresh(string $path): bool {
    if (!file_exists($path)) return false;
    return (time() - filemtime($path)) < Z51_TTL;
}

/**
 * 优化版下载函数：兼容跳转、防盗链、TLS版本，解决CDN下载失败问题
 */
function fetchBuffer(string $url, int $timeout = 30, int $retries = 3): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'buf' => null, 'error' => 'PHP 未安装 curl 扩展'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,          // 限制最大跳转次数，防止无限重定向
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, // 强制TLS 1.2，解决握手失败
        CURLOPT_REFERER => 'http://epg.51zmt.top:8000/', // 模拟来源页，绕过基础防盗链
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: application/octet-stream, */*',
            'Accept-Language: zh-CN,zh;q=0.9',
        ],
    ]);

    // --- 代理配置（如需代理访问，取消注释并填写地址）---
    // curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:7890');
    // curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);

    $attempt = 0;
    while ($attempt <= $retries) {
        $buf = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($buf !== false && $httpCode === 200 && strlen($buf) > 0) {
            curl_close($ch);
            return ['ok' => true, 'buf' => $buf, 'error' => null];
        }

        $attempt++;
        if ($attempt <= $retries) sleep(2);
    }

    curl_close($ch);
    return ['ok' => false, 'buf' => null, 'error' => $err ?: "HTTP {$httpCode}"];
}

function ensurePack(string $key, string $url, bool $force = false): string {
    ensureCacheDir();
    $out = cachePath($key);

    // 非强制模式下，先检查缓存有效性与完整性
    if (!$force && isFresh($out)) {
        // 快速校验文件是否损坏
        $header = file_get_contents($out, false, null, 0, 100);
        if (filesize($out) > 0 && $header !== false && strpos($header, '<tv') !== false) {
            return $out;
        }
        // 文件损坏则删除，重新下载
        @unlink($out);
        z51_log('warn', "{$key} 本地缓存损坏，将重新下载");
    }

    z51_log('info', "正在下载 {$key} 数据包...");
    $result = fetchBuffer($url);

    if (!$result['ok']) {
        // 下载失败兜底：存在有效旧缓存则继续使用
        if (file_exists($out) && filesize($out) > 0) {
            z51_log('warn', "{$key} 下载失败，使用本地缓存兜底: " . $result['error']);
            return $out;
        }
        throw new Exception("下载 {$key} 失败: " . $result['error']);
    }

    $buf = $result['buf'];

    // GZ解压
    if (ord($buf[0]) === 0x1f && ord($buf[1]) === 0x8b) {
        if (!function_exists('gzdecode')) {
            throw new Exception('PHP 未安装 zlib 扩展，无法解压 gzip 文件');
        }
        $xml = gzdecode($buf);
        if ($xml === false) {
            if (file_exists($out) && filesize($out) > 0) {
                z51_log('warn', "{$key} gzip解压失败，使用本地缓存兜底");
                return $out;
            }
            throw new Exception("{$key} gzip 解压失败");
        }
    } else {
        $xml = $buf;
    }

    // 校验XMLTV格式
    if (strpos($xml, '<tv') === false) {
        if (file_exists($out) && filesize($out) > 0) {
            z51_log('warn', "{$key} 返回内容非合法XMLTV格式，使用本地缓存兜底");
            return $out;
        }
        throw new Exception("{$key} 返回内容不是合法 XMLTV 格式");
    }

    // 原子写入 + 失败处理
    $tmp = $out . '.tmp';
    if (file_put_contents($tmp, $xml, LOCK_EX) === false) {
        throw new Exception("{$key} 缓存文件写入失败，请检查目录权限");
    }
    if (!rename($tmp, $out)) {
        @unlink($tmp);
        throw new Exception("{$key} 缓存文件更新失败，请检查目录权限");
    }

    $sizeMb = round(strlen($xml) / 1024 / 1024, 1);
    z51_log('ok', "{$key} 下载完成，解压后 {$sizeMb} MB");

    return $out;
}

// ======================== 输出目录保障 ========================
function ensureOutputDir(): void {
    $dir = dirname(OUTPUT_GZ);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            throw new Exception('输出目录创建失败，请检查权限：' . $dir);
        }
    }
    if (!is_writable($dir)) {
        throw new Exception('输出目录不可写，请设置 755 权限：' . $dir);
    }
}

// ======================== DOM解析 + 数据清洗 + 合并 ========================
function loadAllData(bool $force = false): array {
    global $z51Memo, $Z51_PACKS, $channelsToRemove, $globalHasError;

    if ($z51Memo !== null && !$force) return $z51Memo;

    // 主DOM容器
    $mainDom = new DOMDocument('1.0', 'UTF-8');
    $mainDom->preserveWhiteSpace = false;
    $mainDom->formatOutput = false;
    $root = $mainDom->createElement('tv');
    $mainDom->appendChild($root);

    $allChannels = [];
    $totalProgrammes = 0;
    $totalRealDescs = 0;
    $stats = [];
    $deletedChannelCount = 0;
    $deletedProgrammeCount = 0;

    libxml_use_internal_errors(true);

    foreach ($Z51_PACKS as $p) {
        $key = $p['key'];
        $url = $p['url'];

        try {
            $file = ensurePack($key, $url, $force);
        } catch (Exception $e) {
            z51_log('warn', $p['key'] . ' 不可用: ' . $e->getMessage());
            $stats[] = ['key' => $key, 'ok' => false, 'error' => $e->getMessage()];
            continue;
        }

        z51_log('info', "正在解析 {$key} 数据包...");

        // 加载XML
        $dom = new DOMDocument();
        $loaded = $dom->load($file, LIBXML_COMPACT | LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();

        if (!$loaded) {
            // 解析失败自动删除损坏缓存，下次自动重下
            @unlink($file);
            z51_log('warn', "{$key} XML解析失败，已清理损坏缓存");
            $stats[] = ['key' => $key, 'ok' => false, 'error' => 'XML解析失败'];
            continue;
        }

        $xp = new DOMXPath($dom);
        $channelCount = 0;
        $programmeCount = 0;
        $realDescCount = 0;

        // ========== 处理频道 ==========
        $channels = $xp->query('//channel');
        foreach ($channels as $ch) {
            $origId = trim($ch->getAttribute('id'));
            if ($origId === '') continue;

            $newId = "{$key}:{$origId}";
            $ch->setAttribute('id', $newId);

            // 清洗频道名称
            $nameNodes = $xp->query('./display-name', $ch);
            $name = $newId;
            if ($nameNodes->length > 0) {
                $nameNode = $nameNodes->item(0);
                $name = trim(toSimplified(decodeEntities($nameNode->nodeValue)));
                $nameNode->nodeValue = htmlspecialchars($name, ENT_XML1, 'UTF-8');
            }

            // 导入主DOM
            $imported = $mainDom->importNode($ch, true);
            $root->appendChild($imported);

            $allChannels[$newId] = [
                'file' => $key,
                'id' => $origId,
                'channel_id' => $newId,
                'name' => $name,
                'norm_name' => normalizeZ51Name($name),
            ];
            $channelCount++;
        }

        // ========== 处理节目 ==========
        $programmes = $xp->query('//programme');
        foreach ($programmes as $prog) {
            $origChannel = trim($prog->getAttribute('channel'));
            if ($origChannel === '') continue;

            // 修正频道关联
            $newChannel = "{$key}:{$origChannel}";
            $prog->setAttribute('channel', $newChannel);

            // 统一时间格式
            $start = $prog->getAttribute('start');
            $startTs = parseZ51Time($start);
            if ($startTs) {
                $prog->setAttribute('start', date('YmdHis O', $startTs));
            }

            $stop = $prog->getAttribute('stop');
            $stopTs = parseZ51Time($stop);
            if ($stopTs) {
                $prog->setAttribute('stop', date('YmdHis O', $stopTs));
            }

            // 清洗标题
            $titleNodes = $xp->query('./title', $prog);
            $title = '';
            if ($titleNodes->length > 0) {
                $titleNode = $titleNodes->item(0);
                $title = trim(toSimplified(decodeEntities($titleNode->nodeValue)));
                $titleNode->nodeValue = htmlspecialchars($title, ENT_XML1, 'UTF-8');
            }

            // 清洗简介 + 无效过滤
            $descNodes = $xp->query('./desc', $prog);
            if ($descNodes->length > 0) {
                $descNode = $descNodes->item(0);
                $desc = trim(preg_replace('/\s+/', ' ', toSimplified(decodeEntities($descNode->nodeValue))));

                if (hasRealDesc($desc, $title)) {
                    $descNode->nodeValue = htmlspecialchars($desc, ENT_XML1, 'UTF-8');
                    $realDescCount++;
                } else {
                    // 移除无效简介节点
                    $prog->removeChild($descNode);
                }
            }

            if ($title) {
                $programmeCount++;
                $imported = $mainDom->importNode($prog, true);
                $root->appendChild($imported);
            }
        }

        // 统计
        $rate = $programmeCount > 0 ? round(($realDescCount / $programmeCount) * 100, 1) : 0;
        z51_log('ok', sprintf(
            "%s: %d 频道 / %d 节目 / 真实简介 %d (%s%%)",
            $key, $channelCount, $programmeCount, $realDescCount, $rate
        ));

        $stats[] = [
            'key' => $key,
            'ok' => true,
            'channels' => $channelCount,
            'programmes' => $programmeCount,
            'realDescs' => $realDescCount,
            'rate' => $rate,
        ];

        $totalProgrammes += $programmeCount;
        $totalRealDescs += $realDescCount;
    }

    // ========== 修复：所有分包全部失败时，直接标记错误，不生成空文件 ==========
    if (empty($allChannels)) {
        $globalHasError = true;
        z51_log('error', '所有数据包均不可用，未获取到任何有效频道数据，终止生成');
        $z51Memo = [
            'channels' => [],
            'totalChannels' => 0,
            'totalProgrammes' => 0,
            'totalRealDescs' => 0,
            'stats' => $stats,
        ];
        return $z51Memo;
    }

    // ========== 删除指定频道（重写逻辑：修复前缀匹配、XPath注入、名称标准化） ==========
    if (!empty($channelsToRemove) && !empty($allChannels)) {
        z51_log('info', "正在清理指定频道...");
        $mainXp = new DOMXPath($mainDom);

        // 预标准化待删除关键词
        $normKeywords = array_map(function ($kw) {
            return normalizeZ51Name(trim($kw));
        }, $channelsToRemove);
        $normKeywords = array_filter($normKeywords);

        foreach ($allChannels as $channelId => $channelInfo) {
            $origId = $channelInfo['id'];
            $normName = $channelInfo['norm_name'];
            $match = false;

            // 匹配规则1：原始ID / 带前缀完整ID
            foreach ($channelsToRemove as $keyword) {
                $keyword = trim($keyword);
                if ($keyword === '') continue;
                if ($keyword === $origId || $keyword === $channelId) {
                    $match = true;
                    break;
                }
            }

            // 匹配规则2：标准化名称匹配（自动适配繁简、全角半角、高清后缀）
            if (!$match && in_array($normName, $normKeywords, true)) {
                $match = true;
            }

            if ($match) {
                // 删除频道节点
                $chNodes = $mainXp->query("//channel[@id='{$channelId}']");
                foreach ($chNodes as $node) {
                    $node->parentNode->removeChild($node);
                    $deletedChannelCount++;
                }
                // 连带删除对应节目
                $progNodes = $mainXp->query("//programme[@channel='{$channelId}']");
                foreach ($progNodes as $node) {
                    $node->parentNode->removeChild($node);
                    $deletedProgrammeCount++;
                }
                unset($allChannels[$channelId]);
            }
        }

        z51_log('ok', "已清理 {$deletedChannelCount} 个频道，连带清理 {$deletedProgrammeCount} 条节目");
    }

    // ========== 生成最终GZ输出 ==========
    try {
        ensureOutputDir();
        $xmlContent = $mainDom->saveXML();

        if ($xmlContent === false) {
            throw new Exception('XML序列化失败');
        }

        // GZ最高压缩等级输出
        $fpOut = gzopen(OUTPUT_GZ, 'wb9');
        if (!$fpOut) {
            throw new Exception('无法创建输出GZ文件，请检查目录权限');
        }
        gzwrite($fpOut, $xmlContent);
        gzclose($fpOut);

        z51_log('ok', 'XMLTV 生成完成，压缩后大小：' . round(filesize(OUTPUT_GZ) / 1024 / 1024, 2) . ' MB');
    } catch (Exception $e) {
        $globalHasError = true;
        z51_log('error', '生成输出文件失败: ' . $e->getMessage());
    }

    libxml_clear_errors();

    $z51Memo = [
        'channels' => $allChannels,
        'totalChannels' => count($allChannels),
        'totalProgrammes' => $totalProgrammes - $deletedProgrammeCount,
        'totalRealDescs' => $totalRealDescs,
        'stats' => $stats,
    ];

    return $z51Memo;
}

// ======================== CLI 命令行模式处理 ========================
if (PHP_SAPI === 'cli') {
    $cliAction = $argv[1] ?? 'build'; // 默认直接执行生成，无需加build参数
    $cliForce = in_array('--force', $argv, true);

    if ($cliAction === 'build') {
        try {
            echo "=== 51zmt EPG 生成工具 (CLI模式) ===\n";
            echo "模式: " . ($cliForce ? "强制重新下载" : "优先使用缓存") . "\n\n";
            
            $data = loadAllData($cliForce);

            // 输出执行日志
            foreach ($logMessages as $log) {
                $levelTag = '[' . str_pad(strtoupper($log['level']), 5, ' ', STR_PAD_RIGHT) . ']';
                echo "[{$log['time']}] {$levelTag} {$log['msg']}\n";
            }

            echo "\n=== 执行结果 ===\n";
            echo "总频道数: {$data['totalChannels']}\n";
            echo "总节目数: {$data['totalProgrammes']}\n";
            echo "真实简介数: {$data['totalRealDescs']}\n";
            
            if (file_exists(OUTPUT_GZ)) {
                $size = round(filesize(OUTPUT_GZ) / 1024 / 1024, 2);
                echo "输出文件: " . OUTPUT_GZ . "\n";
                echo "文件大小: {$size} MB\n";
            }

            // 全局错误则返回非0退出码，方便定时任务判断
            exit($globalHasError ? 1 : 0);
        } catch (Exception $e) {
            echo "\n[ERROR] 执行失败: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        echo "用法:\n";
        echo "  php " . basename(__FILE__) . "                # 生成XML，优先使用本地缓存\n";
        echo "  php " . basename(__FILE__) . " build          # 生成XML，优先使用本地缓存\n";
        echo "  php " . basename(__FILE__) . " build --force  # 强制重新下载并生成\n";
        echo "  php " . basename(__FILE__) . " help           # 查看帮助\n";
        exit(0);
    }
}

// ======================== Web 请求处理（浏览器访问） ========================
$action = $_GET['action'] ?? 'home';
$force = isset($_GET['force']) ? (bool)$_GET['force'] : false;
$hasError = false;
$data = null;

// 计算输出文件的Web相对路径，修复下载链接404问题
$outputWebPath = ltrim(str_replace(__DIR__, '', OUTPUT_GZ), '/\\');
$outputName = basename(OUTPUT_GZ);
$outputExists = file_exists(OUTPUT_GZ);

if ($action === 'build') {
    try {
        $data = loadAllData($force);
        // 合并输出阶段的错误标记
        if ($globalHasError) {
            $hasError = true;
        }
    } catch (Exception $e) {
        $hasError = true;
        z51_log('error', $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>51zmt EPG 全量输出工具</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 800px;
            margin: 30px auto;
            padding: 0 20px;
            background: #f5f7fa;
            color: #333;
        }
        h1 { font-size: 22px; margin-bottom: 20px; color: #1a73e8; }
        .tip {
            background: #e8f0fe;
            border-left: 4px solid #1a73e8;
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.6;
        }
        .tip code {
            background: #fff;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
        }
        .btn-group { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #1a73e8;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn:hover { background: #1557b0; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #545b62; }
        .download-box {
            background: #e6f4ea;
            border: 1px solid #ceead6;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .download-box p { margin-bottom: 12px; color: #137333; font-size: 14px; }
        .log-box {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 16px;
            border-radius: 6px;
            font-family: Consolas, Monaco, monospace;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        .log-info { color: #569cd6; }
        .log-ok { color: #4ec9b0; }
        .log-warn { color: #dcdcaa; }
        .log-error { color: #f14c4c; }
        .error-box {
            background: #fce8e6;
            border: 1px solid #fad2cf;
            color: #c5221f;
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-item {
            background: #fff;
            padding: 16px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .stat-num { font-size: 24px; font-weight: 600; color: #1a73e8; margin-bottom: 4px; }
        .stat-label { font-size: 13px; color: #666; }
        h3 { font-size: 16px; margin-bottom: 12px; color: #333; }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }
        th { background: #f8f9fa; font-weight: 500; color: #555; }
        tr:last-child td { border-bottom: none; }

        @media (max-width: 600px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
            .btn-group { flex-direction: column; }
            .btn { text-align: center; }
        }
    </style>
</head>
<body>
    <h1>51zmt EPG 全量输出工具</h1>

    <div class="tip">
        <strong>说明：</strong>自动从 51zmt 下载 e1（国内）和 e2（海外/港澳台）数据包，
        基于标准DOM解析，完成繁转简、无效简介过滤、频道清理后输出标准 XMLTV（GZ压缩）。
        数据缓存 6 小时，可强制刷新。同目录需放置 <code>t2s.json</code> 繁简映射文件。
    </div>

    <div class="btn-group">
        <a href="?action=build" class="btn">生成 XML（使用缓存）</a>
        <a href="?action=build&force=1" class="btn btn-secondary">强制重新下载生成</a>
    </div>

    <?php if ($outputExists && $action !== 'build'): ?>
    <div class="download-box">
        <p style="margin-bottom: 10px; color: #137333;">
            📄 已有生成文件（更新时间：<?php echo date('Y-m-d H:i:s', filemtime(OUTPUT_GZ)); ?>）
        </p>
        <a href="<?php echo htmlspecialchars($outputWebPath); ?>" download class="btn">立即下载 <?php echo $outputName; ?></a>
    </div>
    <?php endif; ?>

    <?php if ($action === 'build'): ?>
    <div class="log-box">
        <?php foreach ($logMessages as $log): ?>
            <div class="log-<?php echo $log['level']; ?>">
                [<?php echo $log['time']; ?>] <?php echo htmlspecialchars($log['msg']); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($action === 'build' && !$hasError && $data): ?>
        <div class="stat-grid">
            <div class="stat-item">
                <div class="stat-num"><?php echo $data['totalChannels']; ?></div>
                <div class="stat-label">总频道数</div>
            </div>
            <div class="stat-item">
                <div class="stat-num"><?php echo $data['totalProgrammes']; ?></div>
                <div class="stat-label">总节目数</div>
            </div>
            <div class="stat-item">
                <div class="stat-num"><?php echo $data['totalRealDescs']; ?></div>
                <div class="stat-label">真实简介数</div>
            </div>
            <div class="stat-item">
                <div class="stat-num"><?php echo $outputExists ? round(filesize(OUTPUT_GZ) / 1024 / 1024, 2) . 'M' : '-'; ?></div>
                <div class="stat-label">输出文件大小</div>
            </div>
        </div>

        <h3>各分包详情</h3>
        <table>
            <thead>
                <tr>
                    <th>分包</th>
                    <th>频道数</th>
                    <th>节目数</th>
                    <th>真实简介</th>
                    <th>有效率</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['stats'] as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['key']); ?></td>
                    <td><?php echo $s['channels'] ?? '-'; ?></td>
                    <td><?php echo $s['programmes'] ?? '-'; ?></td>
                    <td><?php echo $s['realDescs'] ?? '-'; ?></td>
                    <td><?php echo isset($s['rate']) ? $s['rate'] . '%' : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="download-box">
            <p style="margin-bottom: 12px; color: #137333;">✅ XMLTV 文件生成成功</p>
            <a href="<?php echo htmlspecialchars($outputWebPath); ?>" download class="btn">下载 <?php echo $outputName; ?></a>
        </div>
    <?php elseif ($action === 'build' && $hasError): ?>
        <div class="error-box">
            <strong>生成失败：</strong>请查看上方日志排查。
            常见原因：目录无写入权限、缺少 curl/zlib 扩展、网络无法访问源站、缺少 t2s.json 文件。
        </div>
    <?php endif; ?>

    <div style="margin-top: 10px;">
        <a href="?action=home" class="btn btn-secondary">返回首页</a>
    </div>
</body>
</html>
