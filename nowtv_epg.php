<?php
/**
 * 香港 Now TV 官方节目单采集（PHP版）
 * 数据源：https://nowplayer.now.com/tvguide
 * 输出：epg/epg_nowtv.xml（标准 XMLTV 格式）
 * 繁简转换：同目录 t2s.json（支持 // 和 /* */ 注释）
 * 运行方式：php nowtv_epg.php
 */

// 基础配置
define('BASE_URL', 'https://nowplayer.now.com');
define('TVGUIDE_URL', BASE_URL . '/tvguide');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36');
define('MAX_DAY', 7);          // 接口最多支持7天
define('BATCH_SIZE', 24);      // 单次请求频道数（保守值）
define('DESC_CONCURRENCY', 8); // 简介抓取并发数
define('T2S_FILE', __DIR__ . '/t2s.json'); // 外置繁简映射表
define('OUTPUT_FILE', __DIR__ . '/epg/epg_nowtv.xml'); // 输出路径
define('DAY_FILL_MIN_HOURS', 6); // 单日覆盖满6小时才算有效天

// 全局简介缓存（进程内有效，避免重复请求）
$_desc_cache = [];
// 全局繁简映射表
$_t2s_map = null;

/**
 * 移除 JSON 中的单行/多行注释，支持 t2s.json 带注释写法
 */
function strip_json_comments($json) {
    // 移除多行注释 /* ... */
    $json = preg_replace('/\/\*.*?\*\//s', '', $json);
    // 移除单行注释 // ...
    $json = preg_replace('/\/\/.*$/m', '', $json);
    return trim($json);
}

/**
 * 加载外置繁简映射表
 * 文件不存在或格式错误时返回空数组，不影响主流程
 */
function load_t2s_map() {
    global $_t2s_map;
    if ($_t2s_map !== null) return $_t2s_map;

    if (!file_exists(T2S_FILE)) {
        $_t2s_map = [];
        return $_t2s_map;
    }

    $content = file_get_contents(T2S_FILE);
    if ($content === false) {
        $_t2s_map = [];
        return $_t2s_map;
    }

    $clean = strip_json_comments($content);
    $map = json_decode($clean, true);
    if (!is_array($map)) {
        $_t2s_map = [];
        return $_t2s_map;
    }

    // 按键长度降序，长词优先替换（strtr 原生也支持，此处双重保险）
    uksort($map, function($a, $b) {
        return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
    });

    $_t2s_map = $map;
    return $_t2s_map;
}

/**
 * 繁体转简体
 * 无映射表时直接返回原字符串
 */
function t2s($str) {
    global $_t2s_map;
    if ($_t2s_map === null) load_t2s_map();
    if (empty($_t2s_map) || empty($str)) return $str;
    return strtr($str, $_t2s_map);
}

/**
 * 通用 GET 请求（支持 gzip、重试、自定义头）
 */
function http_get($url, $extra_headers = [], $timeout = 25, $retries = 2) {
    $default_headers = [
        'User-Agent: ' . USER_AGENT,
        'Referer: ' . TVGUIDE_URL . '?lang=zh',
        'Accept: */*',
        'X-Requested-With: XMLHttpRequest',
        'Accept-Language: zh-CN,zh;q=0.9',
    ];
    $headers = array_merge($default_headers, $extra_headers);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $last_error = '';
    for ($i = 0; $i <= $retries; $i++) {
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http_code === 200 && $response !== false) {
            curl_close($ch);
            return $response;
        }
        $last_error = curl_error($ch) . " (HTTP {$http_code})";
        usleep(500000); // 重试间隔0.5秒
    }
    curl_close($ch);
    throw new Exception("请求失败 [{$url}]: {$last_error}");
}

/**
 * 获取香港时区的 YYYYMMDD 日期
 */
function hk_ymd($offset_days = 0) {
    $dt = new DateTime('now', new DateTimeZone('Asia/Hong_Kong'));
    if ($offset_days !== 0) {
        $dt->modify("{$offset_days} day");
    }
    return $dt->format('Ymd');
}

/**
 * YYYYMMDD 转接口 day 序号（今天=1）
 */
function ymd_to_day_index($ymd, $today_ymd) {
    $d1 = DateTime::createFromFormat('Ymd', $ymd, new DateTimeZone('Asia/Hong_Kong'));
    $d2 = DateTime::createFromFormat('Ymd', $today_ymd, new DateTimeZone('Asia/Hong_Kong'));
    $diff = $d1->diff($d2)->days * ($d1 > $d2 ? 1 : -1);
    return $diff + 1;
}

/**
 * 判断简介是否为有效真实简介
 */
function has_real_desc($desc, $title) {
    $desc = trim($desc);
    if (mb_strlen($desc, 'UTF-8') < 20) return false;
    if ($desc === $title) return false;
    return true;
}

/**
 * 计算有效覆盖天数（单日覆盖时长≥阈值才算1天）
 */
function count_covered_ymds($rows, $all_ymds, $min_hours) {
    $count = 0;
    foreach ($all_ymds as $ymd) {
        $day_start = DateTime::createFromFormat('Ymd H:i:s', "{$ymd} 00:00:00", new DateTimeZone('Asia/Hong_Kong'))->getTimestamp() * 1000;
        $day_end = $day_start + 86400 * 1000;
        $covered_ms = 0;

        foreach ($rows as $row) {
            $s = max($row['startTs'], $day_start);
            $e = min($row['stopTs'], $day_end);
            if ($e > $s) $covered_ms += ($e - $s);
        }

        if ($covered_ms / 1000 >= $min_hours * 3600) {
            $count++;
        }
    }
    return $count;
}

/**
 * 获取频道列表（含频道中文名）
 * 坑点：频道名只能从详情页 <title> 提取，列表页只有编号
 */
function fetch_channel_list() {
    echo "[步骤1/5] 正在获取频道列表...\n";
    $html = http_get(TVGUIDE_URL . '?lang=zh');

    // 主匹配：频道号
    preg_match_all('/<span class="ch-num">(\d+)<\/span>/', $html, $matches);
    $ids = array_unique($matches[1] ?? []);

    // 兜底匹配：从详情链接提取
    if (empty($ids)) {
        preg_match_all('/\/tvguide\/channeldetail\/(\d+)\/\d+/', $html, $matches);
        $ids = array_unique($matches[1] ?? []);
    }

    if (empty($ids)) {
        throw new Exception("未提取到任何频道号");
    }

    sort($ids);
    $channels = [];
    foreach ($ids as $id) {
        $channels[] = [
            'sourceId' => $id,
            'name' => '',
            'num' => $id,
        ];
    }
    echo "  共发现 " . count($channels) . " 个频道，正在获取频道名称...\n";

    // 并发抓取详情页拿频道名
    $multi = curl_multi_init();
    $handles = [];

    foreach ($channels as $idx => $ch) {
        $url = BASE_URL . "/tvguide/channeldetail/{$ch['sourceId']}/1?lang=zh";
        $c = curl_init($url);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($c, CURLOPT_ENCODING, 'gzip,deflate');
        curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($c, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($c, CURLOPT_HTTPHEADER, [
            'User-Agent: ' . USER_AGENT,
            'Referer: ' . TVGUIDE_URL . '?lang=zh',
        ]);
        $handles[$idx] = $c;
        curl_multi_add_handle($multi, $c);
    }

    $active = null;
    do {
        curl_multi_exec($multi, $active);
        curl_multi_select($multi);
    } while ($active > 0);

    foreach ($handles as $idx => $c) {
        $html = curl_multi_getcontent($c);
        if (preg_match('/<title>([^<]*)<\/title>/', $html, $t)) {
            $title = trim($t[1]);
            $gt_pos = strrpos($title, '>');
            $name = $gt_pos !== false ? trim(mb_substr($title, $gt_pos + 1)) : $title;
            $channels[$idx]['name'] = t2s($name); // 频道名同步繁转简
        }
        curl_multi_remove_handle($multi, $c);
        curl_close($c);
    }
    curl_multi_close($multi);

    return $channels;
}

/**
 * 批量获取单日多频道节目
 * 坑点②：channelIdList 必须重复参数名，不能逗号拼接
 * 坑点①：必须带 lang=zh，否则全英文
 */
function fetch_day_batch($source_ids, $day) {
    $param_str = '';
    foreach ($source_ids as $id) {
        $param_str .= '&channelIdList=' . urlencode($id);
    }
    $url = BASE_URL . "/tvguide/epglist?day={$day}" . $param_str . '&lang=zh';

    try {
        $json = http_get($url);
        $data = json_decode($json, true);
        if (!is_array($data)) return [];

        $result = [];
        foreach ($source_ids as $i => $id) {
            $result[$id] = isset($data[$i]) && is_array($data[$i]) ? $data[$i] : [];
        }
        return $result;
    } catch (Exception $e) {
        fwrite(STDERR, "[警告] 第{$day}天批量抓取失败: " . $e->getMessage() . "\n");
        return [];
    }
}

/**
 * 原始节目转标准格式 + 源内去重
 * 坑点④：start/end 是毫秒时间戳
 * 坑点⑥：相邻天有重叠，按 start|title 去重
 */
function to_rows($arr) {
    $seen = [];
    $rows = [];

    foreach ($arr as $item) {
        $st = (int)($item['start'] ?? 0);
        $et = (int)($item['end'] ?? 0);
        $title = trim((string)($item['name'] ?? ''));

        if ($st <= 0 || $et <= 0 || $et <= $st) continue;
        if (empty($title)) continue;

        $key = "{$st}|{$title}";
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $rows[] = [
            'startTs'  => $st,
            'stopTs'   => $et,
            'title'    => $title,
            'desc'     => '',
            'category' => '',
            '_pid'     => (string)($item['vimProgramId'] ?? ''),
        ];
    }

    usort($rows, function($a, $b) {
        return $a['startTs'] - $b['startTs'];
    });

    return $rows;
}

/**
 * 获取单个节目简介（带全局缓存）
 * 坑点：必须带 lang=zh，否则 chiSynopsis 为空
 */
function fetch_desc($pid) {
    global $_desc_cache;
    if (empty($pid)) return '';
    if (isset($_desc_cache[$pid])) return $_desc_cache[$pid];

    $url = BASE_URL . '/tvguide/epgprogramdetail?programId=' . urlencode($pid) . '&lang=zh';

    try {
        $json = http_get($url);
        $d = json_decode($json, true);

        // 优先级：中文简介 > 通用 > 英文兜底
        $candidates = [
            $d['chiSynopsis'] ?? '',
            $d['synopsis'] ?? '',
            $d['engSynopsis'] ?? '',
        ];

        $desc = '';
        foreach ($candidates as $c) {
            $c = trim((string)$c);
            if (mb_strlen($c, 'UTF-8') > 20) {
                $desc = $c;
                break;
            }
        }
    } catch (Exception $e) {
        $desc = '';
    }

    $_desc_cache[$pid] = $desc;
    return $desc;
}

/**
 * 按唯一标题回填简介（大幅减少请求量）
 * 坑点③：epglist 不带简介，需逐条抓；但同标题节目重复率极高
 */
function fill_descs(&$rows) {
    $title_to_pid = [];
    foreach ($rows as $r) {
        if (!isset($title_to_pid[$r['title']]) && !empty($r['_pid'])) {
            $title_to_pid[$r['title']] = $r['_pid'];
        }
    }

    if (empty($title_to_pid)) return;

    $titles = array_keys($title_to_pid);
    $desc_map = [];
    $batches = array_chunk($titles, DESC_CONCURRENCY);

    foreach ($batches as $batch) {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($batch as $title) {
            $pid = $title_to_pid[$title];
            $url = BASE_URL . '/tvguide/epgprogramdetail?programId=' . urlencode($pid) . '&lang=zh';
            $c = curl_init($url);
            curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($c, CURLOPT_ENCODING, 'gzip,deflate');
            curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($c, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($c, CURLOPT_HTTPHEADER, [
                'User-Agent: ' . USER_AGENT,
                'Referer: ' . TVGUIDE_URL . '?lang=zh',
                'X-Requested-With: XMLHttpRequest',
            ]);
            $handles[$title] = $c;
            curl_multi_add_handle($multi, $c);
        }

        $active = null;
        do {
            curl_multi_exec($multi, $active);
            curl_multi_select($multi);
        } while ($active > 0);

        foreach ($handles as $title => $c) {
            $json = curl_multi_getcontent($c);
            $d = json_decode($json, true);
            $candidates = [
                $d['chiSynopsis'] ?? '',
                $d['synopsis'] ?? '',
                $d['engSynopsis'] ?? '',
            ];
            foreach ($candidates as $cand) {
                $cand = trim((string)$cand);
                if (mb_strlen($cand, 'UTF-8') > 20) {
                    $desc_map[$title] = $cand;
                    break;
                }
            }
            curl_multi_remove_handle($multi, $c);
            curl_close($c);
        }
        curl_multi_close($multi);
    }

    // 回填到所有节目
    foreach ($rows as &$row) {
        if (isset($desc_map[$row['title']])) {
            $row['desc'] = $desc_map[$row['title']];
        }
    }
    unset($row);
}

/**
 * 主函数：采集 + 生成 XMLTV
 */
function generate_epg_xml() {
    // 预加载繁简映射
    load_t2s_map();
    echo "[步骤0/5] 繁简映射表: " . (file_exists(T2S_FILE) ? '已加载，共 ' . count($GLOBALS['_t2s_map']) . ' 条映射' : '未启用（t2s.json 不存在）') . "\n";

    $channels = fetch_channel_list();
    $today_ymd = hk_ymd(0);

    // 生成日期范围
    $all_ymds = [];
    for ($d = 1; $d <= MAX_DAY; $d++) {
        $ymd = hk_ymd($d - 1);
        if ($ymd >= $today_ymd) $all_ymds[] = $ymd;
    }

    // 逐天批量抓取
    echo "[步骤2/5] 正在抓取7天节目数据...\n";
    $per_day = [];
    for ($day = 1; $day <= MAX_DAY; $day++) {
        echo "  正在抓取第 {$day} 天...\n";
        $day_data = [];
        $chunks = array_chunk($channels, BATCH_SIZE);
        foreach ($chunks as $chunk) {
            $ids = array_column($chunk, 'sourceId');
            $batch = fetch_day_batch($ids, $day);
            $day_data = array_merge($day_data, $batch);
        }
        $per_day[$day] = $day_data;
    }

    // 整理单频道全量节目 + 跨天去重 + 回填简介
    echo "[步骤3/5] 正在处理数据并回填节目简介...\n";
    $channel_progs = [];
    foreach ($channels as $ch) {
        $sid = $ch['sourceId'];
        $all_rows = [];

        for ($day = 1; $day <= MAX_DAY; $day++) {
            if (isset($per_day[$day][$sid])) {
                $rows = to_rows($per_day[$day][$sid]);
                $all_rows = array_merge($all_rows, $rows);
            }
        }

        // 跨天全局去重
        $seen = [];
        $unique = [];
        foreach ($all_rows as $r) {
            $key = "{$r['startTs']}|{$r['title']}";
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $r;
            }
        }
        usort($unique, function($a, $b) {
            return $a['startTs'] - $b['startTs'];
        });

        // 回填简介
        if (!empty($unique)) {
            fill_descs($unique);
        }

        $channel_progs[$sid] = [
            'channel' => $ch,
            'programmes' => $unique,
        ];
    }

    // 生成 XMLTV
    echo "[步骤4/5] 正在生成 XML 文件...\n";
    $xml = new SimpleXMLElement(
        '<?xml version="1.0" encoding="UTF-8"?>' .
        '<tv generator-info-name="NowTV-EPG-PHP" source-info-url="https://nowplayer.now.com/tvguide"></tv>'
    );

    // 写入频道信息
    foreach ($channel_progs as $sid => $data) {
        $ch_node = $xml->addChild('channel');
        $ch_node->addAttribute('id', "nowtv.{$sid}");

        $name = $data['channel']['name'] ?: "Now频道{$sid}";
        $dn = $ch_node->addChild('display-name', htmlspecialchars($name, ENT_XML1, 'UTF-8'));
        $dn->addAttribute('lang', 'zh');

        $ch_node->addChild('lcn', $sid);
    }

    // 写入节目单（标题、简介统一繁转简）
    foreach ($channel_progs as $sid => $data) {
        foreach ($data['programmes'] as $prog) {
            $p_node = $xml->addChild('programme');
            $p_node->addAttribute('channel', "nowtv.{$sid}");

            // 毫秒转 XMLTV 时间格式 YYYYMMDDHHmmss +0800
            $start = date('YmdHis', intval($prog['startTs'] / 1000)) . ' +0800';
            $stop  = date('YmdHis', intval($prog['stopTs'] / 1000)) . ' +0800';
            $p_node->addAttribute('start', $start);
            $p_node->addAttribute('stop', $stop);

            $title = t2s($prog['title']);
            $title_node = $p_node->addChild('title', htmlspecialchars($title, ENT_XML1, 'UTF-8'));
            $title_node->addAttribute('lang', 'zh');

            if (!empty($prog['desc'])) {
                $desc = t2s($prog['desc']);
                $desc_node = $p_node->addChild('desc', htmlspecialchars($desc, ENT_XML1, 'UTF-8'));
                $desc_node->addAttribute('lang', 'zh');
            }

            if (!empty($prog['category'])) {
                $cat = t2s($prog['category']);
                $cat_node = $p_node->addChild('category', htmlspecialchars($cat, ENT_XML1, 'UTF-8'));
                $cat_node->addAttribute('lang', 'zh');
            }
        }
    }

    // 格式化输出并保存
    $dom = dom_import_simplexml($xml)->ownerDocument;
    $dom->formatOutput = true;
    $dom->preserveWhiteSpace = false;
    $xml_str = $dom->saveXML();

    // 自动创建输出目录
    $output_dir = dirname(OUTPUT_FILE);
    if (!is_dir($output_dir)) {
        mkdir($output_dir, 0755, true);
    }

    file_put_contents(OUTPUT_FILE, $xml_str);

    // 统计输出
    $total_progs = 0;
    $total_desc = 0;
    foreach ($channel_progs as $d) {
        $total_progs += count($d['programmes']);
        foreach ($d['programmes'] as $p) {
            if (!empty($p['desc'])) $total_desc++;
        }
    }

    echo "\n✅ 生成完成！\n";
    echo "  输出文件: " . OUTPUT_FILE . "\n";
    echo "  频道数量: " . count($channel_progs) . "\n";
    echo "  节目总数: {$total_progs}\n";
    echo "  带简介数: {$total_desc} (" . round($total_desc / max(1, $total_progs) * 100, 1) . "%)\n";
}

// 入口
try {
    date_default_timezone_set('Asia/Hong_Kong');
    generate_epg_xml();
} catch (Exception $e) {
    echo "\n❌ 运行出错: " . $e->getMessage() . "\n";
    exit(1);
}
