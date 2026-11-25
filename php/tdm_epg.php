<?php
/**
 * 澳门 TDM 澳广视 EPG 采集脚本（PHP 版）
 * 数据源：https://appapi.tdm.com.mo
 * 输出标准 XMLTV 格式文件
 */

// ==================== 基础配置 ====================
define('TDM_BASE', 'https://appapi.tdm.com.mo');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36');
define('CACHE_TTL', 3600);        // 缓存有效期（秒）
define('CACHE_DIR', __DIR__ . '/cache/tdm');
define('T2S_MAP_FILE', __DIR__ . '/t2s.json'); // 繁转简映射，同目录
define('OUTPUT_FILE', __DIR__ . '/epg_sources/tdm_epg.xml');
define('FETCH_DAYS', 7);          // 采集天数（从今天算起，接口最多支持约6天未来数据）
define('DESC_CONCURRENCY', 5);    // 简介请求并发数

// 初始化目录
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}
$outputDir = dirname(OUTPUT_FILE);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// ==================== 频道定义（官方固定，极少变动） ====================
const TDM_CHANNELS = [
    ['id' => 1, 'slug' => 'ctvp', 'name' => '澳視澳門', 'number' => '91', 'group' => 'tv'],
    ['id' => 2, 'slug' => 'ptvp', 'name' => '澳視葡文', 'number' => '92', 'group' => 'tv'],
    ['id' => 6, 'slug' => 'sports', 'name' => '澳門體育', 'number' => '93', 'group' => 'tv'],
    ['id' => 5, 'slug' => 'info', 'name' => '澳門資訊', 'number' => '94', 'group' => 'tv'],
    ['id' => 7, 'slug' => 'hdctvp', 'name' => '澳門綜藝', 'number' => '95', 'group' => 'tv'],
    ['id' => 8, 'slug' => 'satellite', 'name' => '澳門-Macau', 'number' => '96', 'group' => 'tv'],
    ['id' => 52, 'slug' => 'cgtn71', 'name' => 'CCTV 綜合頻道', 'number' => '71', 'group' => 'tv'],
    ['id' => 49, 'slug' => 'cgtn', 'name' => 'CGTN', 'number' => '73', 'group' => 'tv'],
    ['id' => 46, 'slug' => 'cgtn74', 'name' => 'CGTN 紀錄頻道', 'number' => '74', 'group' => 'tv'],
    ['id' => 9, 'slug' => 'legislativec', 'name' => '立法會直播', 'number' => '', 'group' => 'tv'],
    ['id' => 11, 'slug' => 'legislativep', 'name' => 'Directo das Reuniões da Assembleia', 'number' => '', 'group' => 'tv'],
    ['id' => 3, 'slug' => 'fm1007', 'name' => '澳門電台 FM100.7', 'number' => '', 'group' => 'radio'],
    ['id' => 4, 'slug' => 'fm98', 'name' => 'Rádio Macau FM98', 'number' => '', 'group' => 'radio'],
];

// 实际有节目单的频道（其余返回空数据，跳过节省请求）
const TDM_EPG_IDS = [1, 2, 5, 6, 7, 8];

// 简介全局缓存（运行期内复用，同一节目跨天只请求一次）
$GLOBALS['_desc_cache'] = [];

// ==================== 核心工具函数 ====================

/**
 * 繁体转简体（无映射文件则原样返回）
 */
function toSimplified(string $text): string
{
    static $map = null;
    if ($map === null) {
        if (file_exists(T2S_MAP_FILE)) {
            $json = file_get_contents(T2S_MAP_FILE);
            $map = json_decode($json, true);
            if (!is_array($map)) {
                echo "[警告] t2s.json 解析失败\n";
                $map = [];
            } else {
                echo "[信息] 已加载 t2s.json，共 " . count($map) . " 条映射\n";
            }
        } else {
            echo "[提示] 未找到 t2s.json，保留繁体\n";
            $map = [];
        }
    }
    if (empty($map)) return $text;
    return strtr($text, $map);
}

/**
 * HTML 片段转纯文本（清洗简介里的标签与实体）
 */
function stripHtml(string $html): string
{
    $html = preg_replace('/<br\s*\/?>/i', ' ', $html);
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    $html = preg_replace('/\s+/u', ' ', $html);
    return trim($html);
}

/**
 * 判断是否为真实简介（过滤复读标题、过短内容）
 */
function hasRealDesc(string $desc, string $title): bool
{
    $desc = trim($desc);
    $title = trim($title);
    if ($desc === '') return false;
    if ($desc === $title) return false;
    if (mb_strlen($desc, 'UTF-8') < 10) return false;
    // 归一化后对比，避免空格/符号差异导致的误判
    $normDesc = preg_replace('/\s+/u', '', $desc);
    $normTitle = preg_replace('/\s+/u', '', $title);
    if ($normDesc === $normTitle) return false;
    return true;
}

/**
 * 单个 JSON 请求（带缓存、重试、请求头）
 * @throws Exception
 */
function fetchJson(string $url, bool $force = false): array
{
    $cacheFile = CACHE_DIR . '/' . md5($url) . '.json';

    // 命中有效缓存
    if (!$force && file_exists($cacheFile)) {
        $stat = stat($cacheFile);
        if (time() - $stat['mtime'] < CACHE_TTL && $stat['size'] > 100) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
    }

    $headers = [
        'Referer: ' . TDM_BASE . '/',
        'Accept: application/json',
        'User-Agent: ' . USER_AGENT,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($content)) {
        throw new Exception("HTTP {$httpCode}: {$error}，URL: {$url}");
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON 解析失败: " . json_last_error_msg());
    }

    // 接口业务码校验
    if (isset($data['code']) && $data['code'] !== 0) {
        throw new Exception("接口错误: " . ($data['message'] ?? 'unknown error'));
    }

    // 写入缓存（临时文件+重命名，避免并发损坏）
    $tmp = $cacheFile . '.tmp';
    file_put_contents($tmp, $content);
    rename($tmp, $cacheFile);

    return $data;
}

/**
 * 批量并发请求节目详情（curl_multi 实现并发限流）
 * @param array $items  待请求条目列表，每项含 ref（id/slug）、key（缓存key）
 * @return array        key => 简介文本
 */
function multiFetchDesc(array $items): array
{
    $result = [];
    $pending = [];

    // 先挑出未缓存的
    foreach ($items as $item) {
        $key = $item['key'];
        if (isset($GLOBALS['_desc_cache'][$key])) {
            $result[$key] = $GLOBALS['_desc_cache'][$key];
        } else {
            $pending[] = $item;
        }
    }

    if (empty($pending)) return $result;

    // 分批并发
    $batches = array_chunk($pending, DESC_CONCURRENCY);

    foreach ($batches as $batch) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($batch as $idx => $item) {
            $ref = urlencode($item['ref']);
            $url = TDM_BASE . "/api/v1.0/program/{$ref}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Referer: ' . TDM_BASE . '/',
                    'Accept: application/json',
                    'User-Agent: ' . USER_AGENT,
                ],
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $handles[$idx] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        // 执行并发
        $active = null;
        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active > 0);

        // 收集结果
        foreach ($batch as $idx => $item) {
            $key = $item['key'];
            $title = $item['title'];
            $content = curl_multi_getcontent($handles[$idx]);
            $httpCode = curl_getinfo($handles[$idx], CURLINFO_HTTP_CODE);

            $desc = '';
            if ($httpCode === 200 && !empty($content)) {
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && !empty($data['data']['content'])) {
                    $raw = stripHtml($data['data']['content']);
                    if (hasRealDesc($raw, $title)) {
                        $desc = $raw;
                    }
                }
            }

            $GLOBALS['_desc_cache'][$key] = $desc;
            $result[$key] = $desc;
            curl_multi_remove_handle($mh, $handles[$idx]);
            curl_close($handles[$idx]);
        }

        curl_multi_close($mh);
    }

    return $result;
}

/**
 * 解析 TDM 时间字符串为 UTC 时间戳（澳门时区 UTC+8）
 */
function parseTdmTime(string $dateStr): ?int
{
    // 格式：2026-10-28 07:00:00
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr, new DateTimeZone('+08:00'));
    if (!$dt) return null;
    return $dt->getTimestamp();
}

/**
 * 时间戳转 XMLTV 标准格式 (YYYYMMDDHHMMSS +0800)
 */
function formatXmltvTime(int $timestamp): string
{
    return gmdate('YmdHis', $timestamp + 8 * 3600) . ' +0800';
}

// ==================== 核心采集函数 ====================

/**
 * 获取指定频道、指定日期的节目单
 * @param int $channelId  频道ID
 * @param string $ymd     日期 YYYYMMDD
 * @return array          节目列表
 */
function getDayProgrammes(int $channelId, string $ymd): array
{
    // ⚠️ 日期必须走路径，不能放 query；channelId 必须放 query
    $datePath = substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
    $url = TDM_BASE . "/api/v1.0/program-list/{$datePath}?channelId={$channelId}";

    try {
        $data = fetchJson($url);
    } catch (Exception $e) {
        echo "  [警告] 频道 {$channelId} {$datePath} 获取失败: " . $e->getMessage() . "\n";
        return [];
    }

    $list = $data['data'] ?? [];
    if (!is_array($list) || empty($list)) return [];

    // 解析时间、初步过滤
    $items = [];
    foreach ($list as $item) {
        if (empty($item['date']) || empty($item['title'])) continue;
        $ts = parseTdmTime($item['date']);
        if ($ts === null) continue;

        $items[] = [
            'ts' => $ts,
            'title' => trim($item['title']),
            'id' => $item['id'] ?? null,
            'slug' => $item['slug'] ?? null,
        ];
    }

    // 按开始时间升序
    usort($items, function ($a, $b) {
        return $a['ts'] - $b['ts'];
    });

    // 收集需要补简介的条目
    $descTasks = [];
    foreach ($items as $idx => $item) {
        $ref = null;
        $key = '';
        if (!empty($item['slug'])) {
            $ref = $item['slug'];
            $key = 'slug:' . $item['slug'];
        } elseif (!empty($item['id'])) {
            $ref = $item['id'];
            $key = 'id:' . $item['id'];
        }
        if ($ref && $key) {
            $descTasks[] = [
                'idx' => $idx,
                'ref' => $ref,
                'key' => $key,
                'title' => $item['title'],
            ];
        }
    }

    // 批量并发补简介
    if (!empty($descTasks)) {
        $descResult = multiFetchDesc($descTasks);
        foreach ($descTasks as $task) {
            $idx = $task['idx'];
            $key = $task['key'];
            $items[$idx]['desc'] = $descResult[$key] ?? '';
        }
    }

    // 计算结束时间（下一条开始时间；最后一条+1小时兜底）
    $programmes = [];
    $count = count($items);
    for ($i = 0; $i < $count; $i++) {
        $start = $items[$i]['ts'];
        $stop = ($i + 1 < $count) ? $items[$i + 1]['ts'] : $start + 3600;
        $programmes[] = [
            'start' => $start,
            'stop' => $stop,
            'title' => $items[$i]['title'],
            'desc' => $items[$i]['desc'] ?? '',
        ];
    }

    return $programmes;
}

// ==================== 主流程：生成 XMLTV ====================

try {
    echo "=== TDM 澳广视 EPG 采集开始 ===\n";
    toSimplified(''); // 触发繁转简加载日志

    // 生成日期列表（今天起 N 天）
    $today = strtotime('today');
    $dates = [];
    for ($i = 0; $i < FETCH_DAYS; $i++) {
        $dates[] = date('Ymd', $today + $i * 86400);
    }

    // 筛选有EPG的频道
    $channels = [];
    foreach (TDM_CHANNELS as $ch) {
        if (in_array($ch['id'], TDM_EPG_IDS)) {
            $channels[] = $ch;
        }
    }
    echo "有效频道数: " . count($channels) . " 个\n";

    // 按频道采集所有日期节目
    $allProgrammes = []; // [channelId => [...programmes]]
    foreach ($channels as $ch) {
        $chId = $ch['id'];
        $chName = $ch['name'];
        echo "\n正在采集: {$chName} (ID: {$chId})\n";
        $allProgrammes[$chId] = [];

        foreach ($dates as $ymd) {
            $progs = getDayProgrammes($chId, $ymd);
            if (!empty($progs)) {
                $allProgrammes[$chId] = array_merge($allProgrammes[$chId], $progs);
                echo "  {$ymd}: " . count($progs) . " 条节目\n";
            } else {
                echo "  {$ymd}: 无数据\n";
            }
        }

        // 全量按时间排序（解决广播日跨天问题）
        usort($allProgrammes[$chId], function ($a, $b) {
            return $a['start'] - $b['start'];
        });
    }

    echo "\n正在生成 XMLTV 文件...\n";

    // 写入 XML
    $fp = fopen(OUTPUT_FILE, 'w');
    if (!$fp) throw new Exception("无法打开输出文件: " . OUTPUT_FILE);

    fwrite($fp, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
    fwrite($fp, '<tv generator-info-name="tdm-epg-php" generator-info-url="https://appapi.tdm.com.mo">' . "\n");

    // 输出频道节点
    foreach ($channels as $ch) {
        $id = htmlspecialchars($ch['id'], ENT_XML1);
        $name = htmlspecialchars(toSimplified($ch['name']), ENT_XML1);
        fwrite($fp, "  <channel id=\"{$id}\">\n");
        fwrite($fp, "    <display-name>{$name}</display-name>\n");
        if (!empty($ch['number'])) {
            $num = htmlspecialchars($ch['number'], ENT_XML1);
            fwrite($fp, "    <display-name>{$num}</display-name>\n");
        }
        fwrite($fp, "  </channel>\n");
    }

    // 输出节目节点
    $totalProg = 0;
    $totalDesc = 0;
    foreach ($allProgrammes as $chId => $progs) {
        $chIdEsc = htmlspecialchars($chId, ENT_XML1);
        foreach ($progs as $p) {
            $start = formatXmltvTime($p['start']);
            $stop = formatXmltvTime($p['stop']);
            $title = htmlspecialchars(toSimplified($p['title']), ENT_XML1);

            fwrite($fp, "  <programme start=\"{$start}\" stop=\"{$stop}\" channel=\"{$chIdEsc}\">\n");
            fwrite($fp, "    <title lang=\"zh-CN\">{$title}</title>\n");

            if (!empty($p['desc'])) {
                $desc = htmlspecialchars(toSimplified($p['desc']), ENT_XML1);
                fwrite($fp, "    <desc lang=\"zh-CN\">{$desc}</desc>\n");
                $totalDesc++;
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
    echo "带简介节目数: {$totalDesc}\n";
    echo "文件大小: " . round(filesize(OUTPUT_FILE) / 1024, 2) . " KB\n";

} catch (Exception $e) {
    die("运行错误: " . $e->getMessage() . "\n");
}
