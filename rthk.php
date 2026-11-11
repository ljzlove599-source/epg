<?php
/**
 * RTHK 香港电台 EPG 采集器 电视+广播分离版【修复版】
 * 修复说明：
 * 1. 修正时间表 URL 日期参数格式为 YYYYMMDD，匹配官网接口规范
 * 2. 重构节目解析逻辑，按 slideBlock 日期块分组过滤，彻底解决多日节目混同一天的问题
 * 3. 对齐时间节点提取规则（第0个为开始、第2个为结束），无结束时间默认 30 分钟
 * 覆盖：港台电视31~35 + 香港电台8个广播频道
 * 输出：电视 epg_sources/epg_rthk_tv.xml / 广播 radio/epg_rthk_radio.xml
 * 适配环境：GitHub Actions / CLI / 群晖 PHP7+
 */

set_time_limit(0);
ignore_user_abort(true);
date_default_timezone_set('Asia/Hong_Kong');

// ====================== 配置区 ======================
$config = [
    // 采集天数
    'days_past'     => 1,    // 采集过去几天
    'days_future'   => 7,    // 采集未来几天

    // 输出路径（相对脚本所在目录）
    'tv_xml_path'   => __DIR__ . '/epg_sources/epg_rthk_tv.xml',
    'radio_xml_path'=> __DIR__ . '/radio/epg_rthk_radio.xml',

    'generate_xml'  => true,

    // 采集配置
    'radio_home'    => 'https://www.rthk.hk/radio',
    'timetable_base' => 'https://www.rthk.hk/timetable/',
    'enable_online' => true,
    'save_cache'    => false, // GitHub 运行建议关闭缓存
    'debug'         => false,

    'retry_times'   => 2,
    'retry_delay'   => 2,

    // 代理配置（GitHub / 境内环境按需开启）
    'proxy' => [
        'enable'   => false,
        'host'     => '127.0.0.1',
        'port'     => 7890,
        'type'     => 'http',
        'username' => '',
        'password' => '',
    ],

    'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
    'timeout'       => 20,
    'connect_timeout' => 10,
    'cache_dir'     => __DIR__ . '/cache',
];

// ====================== 全频道配置（电视+广播） ======================
$channelMap = [
    // ====== 港台电视频道 ======
    'tv31' => [
        'name'   => '港台電視31',
        'group'  => '香港电台电视',
        'logo'   => 'https://webstatic.rthk.hk/img/tv_icon/Player_Logo/TV31_cNam_01.svg',
        'stream' => 'https://rthk9.rthk.hk/live/tv31/playlist.m3u8',
    ],
    'tv32' => [
        'name'   => '港台電視32',
        'group'  => '香港电台电视',
        'logo'   => 'https://webstatic.rthk.hk/img/tv_icon/Player_Logo/TV32_cNam_01.svg',
        'stream' => 'https://rthk9.rthk.hk/live/tv32/playlist.m3u8',
    ],
    'tv33' => [
        'name'   => '港台電視33',
        'group'  => '香港电台电视',
        'logo'   => 'https://webstatic.rthk.hk/img/tv_icon/Player_Logo/TV33_cNam_01.svg',
        'stream' => 'https://rthk9.rthk.hk/live/tv33/playlist.m3u8',
    ],
    'tv34' => [
        'name'   => '港台電視34',
        'group'  => '香港电台电视',
        'logo'   => 'https://webstatic.rthk.hk/img/tv_icon/Player_Logo/TV34_cNam_01.svg',
        'stream' => 'https://rthk9.rthk.hk/live/tv34/playlist.m3u8',
    ],
    'tv35' => [
        'name'   => '港台電視35',
        'group'  => '香港电台电视',
        'logo'   => 'https://webstatic.rthk.hk/img/tv_icon/Player_Logo/TV35_cNam_01.svg',
        'stream' => 'https://rthk9.rthk.hk/live/tv35/playlist.m3u8',
    ],

    // ====== 香港电台广播频道 ======
    'radio1' => [
        'name'   => '香港電台第一台',
        'group'  => '香港电台广播',
        'logo'   => 'https://webstatic.rthk.hk/img/radio_icon/Player_Logo/R1_cNam_01.svg',
        'stream' => 'https://rthkradio1-live.akamaized.net/hls/live/2035313/radio1/master.m3u8',
    ],
    'radio2' => [
        'name'   => '香港電台第二台',
        'group'  => '香港电台广播',
        'logo'   => 'https://webstatic.rthk.hk/img/radio_icon/Player_Logo/R2_cNam_01.svg',
        'stream' => 'https://rthkradio2-live.akamaized.net/hls/live/2040078/radio2/master.m3u8',
    ],
    'radio3' => [
        'name'   => '香港電台第三台',
        'group'  => '香港电台广播',
        'logo'   => 'https://webstatic.rthk.hk/img/radio_icon/Player_Logo/R3_cNam_01.svg',
        'stream' => 'https://rthkradio3-live.akamaized.net/hls/live/2040079/radio3/master.m3u8',
    ],
    'radio4' => [
        'name'   => '香港電台第四台',
        'group'  => '香港电台广播',
        'logo'   => 'https://webstatic.rthk.hk/img/radio_icon/Player_Logo/R4_cNam_01.svg',
        'stream' => 'https://rthkradio4-live.akamaized.net/hls/live/2040080/radio4/master.m3u8',
    ],
    'radio5' => [
        'name'   => '香港電台第五台',
        'group'  => '香港电台广播',
        'logo'   => 'https://webstatic.rthk.hk/img/radio_icon/Player_Logo/R5_cNam_01.svg',
        'stream' => 'https://rthkradio5-live.akamaized.net/hls/live/2040081/radio5/master.m3u8',
    ],
    'pth' => [
        'name'   => '香港電台普通話台',
        'group'  => '香港电台广播',
        'logo'   => 'https://webstatic.rthk.hk/img/radio_icon/Player_Logo/PTH_cNam_01.svg',
        'stream' => 'https://rthkradiopth-live.akamaized.net/hls/live/2040082/radiopth/master.m3u8',
    ],
    'radiocnrhk' => [
        'name'   => '中央人民廣播電台香港之聲',
        'group'  => '香港电台广播',
        'logo'   => 'https://webstatic.rthk.hk/img/radio_06_logo.svg',
        'stream' => 'https://rthkradiocnrhk-live.akamaized.net/hls/live/2046111/radiocnrhk/master.m3u8',
    ],
    'radiocmgrgb' => [
        'name'   => '中央廣播電視總台粵港澳大灣區之聲',
        'group'  => '香港电台广播',
        'logo'   => 'https://webstatic.rthk.hk/img/radio_CMG_logo.svg',
        'stream' => 'https://rthkradiocmgrgb-live.akamaized.net/hls/live/2046112/radiocmgrgb/master.m3u8',
    ],
];

// ====================== CLI 参数 ======================
$isCli = php_sapi_name() === 'cli';
if ($isCli) {
    $argv = $GLOBALS['argv'] ?? $_SERVER['argv'] ?? [];
    if (is_array($argv)) {
        foreach (array_slice($argv, 1) as $arg) {
            if (preg_match('/^--([a-z0-9_-]+)(?:=(.+))?$/', $arg, $matches)) {
                $key = $matches[1];
                $val = $matches[2] ?? true;
                if ($val === 'true')  $val = true;
                if ($val === 'false') $val = false;
                if (is_numeric($val)) $val = (int)$val;

                if ($key === 'proxy')         $config['proxy']['enable'] = true;
                elseif ($key === 'no-proxy')  $config['proxy']['enable'] = false;
                elseif ($key === 'no-xml')    $config['generate_xml'] = false;
                elseif ($key === 'days-past') $config['days_past'] = $val;
                elseif ($key === 'days-future') $config['days_future'] = $val;
                elseif ($key === 'tv-xml-path')  $config['tv_xml_path'] = $val;
                elseif ($key === 'radio-xml-path') $config['radio_xml_path'] = $val;
                elseif ($key === 'debug')     $config['debug'] = true;
                elseif ($key === 'help') {
                    echo "用法: php rthk_epg.php [选项]\n";
                    echo "  --days-past=N       过去N天\n";
                    echo "  --days-future=N     未来N天\n";
                    echo "  --tv-xml-path=PATH  电视XML输出路径\n";
                    echo "  --radio-xml-path=PATH 广播XML输出路径\n";
                    echo "  --proxy / --no-proxy 开关代理\n";
                    echo "  --debug             开启调试输出\n";
                    exit(0);
                }
                elseif (isset($config[$key])) {
                    $config[$key] = $val;
                }
            }
        }
    }
}

$tvXmlPath = $config['tv_xml_path'];
$radioXmlPath = $config['radio_xml_path'];

// ====================== 工具函数 ======================
function log_msg(string $level, string $msg)
{
    $time = date('Y-m-d H:i:s');
    $line = "[{$time}] [{$level}] {$msg}\n";
    if (php_sapi_name() !== 'cli') {
        echo nl2br(htmlspecialchars($line));
    } else {
        echo $line;
    }
    flush();
}

function initDir(string $dir): bool
{
    if (!is_dir($dir)) {
        return mkdir($dir, 0755, true);
    }
    return true;
}

function generateDateRange(int $pastDays, int $futureDays): array
{
    $dates = [];
    $today = strtotime('today');
    for ($i = -$pastDays; $i <= $futureDays; $i++) {
        $dates[] = date('Y-m-d', strtotime("{$i} day", $today));
    }
    return $dates;
}

// 判断是否为广播频道
function isRadioChannel(string $channelId): bool
{
    return strpos($channelId, 'radio') === 0
        || $channelId === 'pth'
        || strpos($channelId, 'radioc') === 0;
}

// ====================== 页面抓取 ======================
function fetchPage(string $url, array $config)
{
    $retry = $config['retry_times'];
    $delay = $config['retry_delay'];

    for ($i = 0; $i <= $retry; $i++) {
        if ($i > 0) {
            log_msg('INFO', "第 {$i} 次重试...");
            sleep($delay);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => $config['user_agent'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => $config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $config['connect_timeout'],
            CURLOPT_ENCODING       => 'gzip,deflate',
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-TW,zh;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
                'Connection: keep-alive',
                'Referer: https://www.rthk.hk/',
            ],
        ]);

        if (!empty($config['proxy']['enable'])) {
            $proxyStr = $config['proxy']['host'] . ':' . $config['proxy']['port'];
            curl_setopt($ch, CURLOPT_PROXY, $proxyStr);
            switch (strtolower($config['proxy']['type'])) {
                case 'socks5':
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                    break;
                case 'http':
                default:
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                    break;
            }
            if (!empty($config['proxy']['username']) && !empty($config['proxy']['password'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD,
                    $config['proxy']['username'] . ':' . $config['proxy']['password']);
            }
        }

        $html  = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$errno && $httpCode == 200 && !empty(trim($html))) {
            return $html;
        }

        log_msg('WARN', "请求失败 [{$errno}] HTTP {$httpCode}: {$error}");
    }

    log_msg('ERROR', "经过 {$retry} 次重试后，页面抓取仍然失败: {$url}");
    return false;
}

// ====================== 电台首页解析（当日广播频道） ======================
function parseHomePage(string $html, string $channelId, string $date): array
{
    $programmes = [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $scrollId = 'scroll' . $channelId;
    $nodes = $xpath->query("//div[@id='{$scrollId}']//div[contains(@class, 'radListBlock')]");

    if ($nodes->length === 0) return [];

    foreach ($nodes as $node) {
        $timeNode = $xpath->query(".//div[contains(@class, 'radTime')]", $node)->item(0);
        if (!$timeNode) continue;

        $timeText = trim($timeNode->nodeValue);
        if (!preg_match('/(\d{1,2}:\d{2})\s*[-~]\s*(\d{1,2}:\d{2})/', $timeText, $m)) continue;

        $startTime = $m[1];
        $endTime   = $m[2];

        $aNode = $xpath->query(".//a", $node)->item(0);
        if (!$aNode) continue;

        $title = trim($aNode->getAttribute('title'));
        if (!$title) $title = trim($aNode->nodeValue);
        $url   = trim($aNode->getAttribute('href'));

        if (!$title) continue;

        if ($url && strpos($url, 'http') !== 0) {
            $url = 'https://www.rthk.hk' . $url;
        }

        $startFull = "{$date} {$startTime}";
        $endFull   = "{$date} {$endTime}";
        if (strtotime($endTime) < strtotime($startTime)) {
            $endFull = date('Y-m-d H:i:s', strtotime($endFull . ' +1 day'));
        }

        $programmes[] = [
            'channel' => $channelId,
            'start'   => date('YmdHis O', strtotime($startFull)),
            'stop'    => date('YmdHis O', strtotime($endFull)),
            'title'   => $title,
            'url'     => $url,
            'desc'    => '',
            'date'    => $date,
        ];
    }
    return $programmes;
}

// ====================== 时间表页通用解析【修复版】 ======================
function parseTimetablePage(string $html, string $channelId, string $date, bool $debug = false): array
{
    $programmes = [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // 目标日期转 8 位数字格式，匹配 slideBlock 的 date 属性
    $targetDate = str_replace('-', '', $date);

    // 1. 先定位目标日期的 slideBlock 日期块，只解析该块内的节目
    $slideBlocks = $xpath->query("//div[contains(@class, 'slideBlock') and @date='{$targetDate}']");

    if ($debug) {
        log_msg('DEBUG', "{$channelId} 日期 {$date} 匹配到 slideBlock 数量: {$slideBlocks->length}");
    }

    if ($slideBlocks->length === 0) {
        return [];
    }

    foreach ($slideBlocks as $slideBlock) {
        // 2. 在当前日期块内查找所有节目块
        $nodes = $xpath->query(".//div[contains(@class, 'shdBlock')]", $slideBlock);

        if ($debug) {
            log_msg('DEBUG', "{$channelId} 日期 {$date} 匹配到 shdBlock 节点数量: {$nodes->length}");
        }

        if ($nodes->length === 0) continue;

        foreach ($nodes as $node) {
            // 3. 优先读取高精度时间戳属性
            $startTimestamp = trim($node->getAttribute('data-startTime'));

            // 4. 提取时间文本：第0个=开始时间，第2个=结束时间，第1个是分隔符 |
            $timeNodes = $xpath->query(".//div[contains(@class, 'shTimeBlock')]//p[contains(@class, 'timeDis')]", $node);
            $startTimeStr = '';
            $endTimeStr = '';

            if ($timeNodes->length >= 1) {
                $startTimeStr = trim($timeNodes->item(0)->nodeValue);
            }
            if ($timeNodes->length >= 3) {
                $endTimeStr = trim($timeNodes->item(2)->nodeValue);
            }

            // 5. 提取标题、副标题、详情链接
            $titleNode = $xpath->query(".//div[contains(@class, 'shTitle')]//a", $node)->item(0);
            $subTitleNode = $xpath->query(".//div[contains(@class, 'shSubTitle')]//a", $node)->item(0);

            $title = '';
            $url = '';
            $desc = '';

            if ($titleNode) {
                $title = trim($titleNode->getAttribute('title'));
                if (!$title) $title = trim($titleNode->nodeValue);
                $url = trim($titleNode->getAttribute('href'));
            }

            if ($subTitleNode) {
                $desc = trim($subTitleNode->getAttribute('title'));
                if (!$desc) $desc = trim($subTitleNode->nodeValue);
            }

            if (!$title) continue;

            if ($url && strpos($url, 'http') !== 0) {
                $url = 'https://www.rthk.hk' . $url;
            }

            // 6. 计算起止时间
            $startFull = '';
            $endFull = '';

            if ($startTimestamp && is_numeric($startTimestamp)) {
                $startFull = date('Y-m-d H:i:s', (int)$startTimestamp);
            } elseif ($startTimeStr && preg_match('/^\d{1,2}:\d{2}$/', $startTimeStr)) {
                $startFull = "{$date} {$startTimeStr}:00";
            }

            if (!$startFull) continue;

            if ($endTimeStr && preg_match('/^\d{1,2}:\d{2}$/', $endTimeStr)) {
                $endFull = "{$date} {$endTimeStr}:00";
                // 跨天处理
                $startTimeOnly = date('H:i', strtotime($startFull));
                $endTimeOnly = date('H:i', strtotime($endFull));
                if (strtotime($endTimeOnly) < strtotime($startTimeOnly)) {
                    $endFull = date('Y-m-d H:i:s', strtotime($endFull . ' +1 day'));
                }
            } else {
                // 无结束时间默认 30 分钟，与 Python 版规则对齐
                $endFull = date('Y-m-d H:i:s', strtotime($startFull . ' +30 minutes'));
            }

            $programmes[] = [
                'channel' => $channelId,
                'start'   => date('YmdHis O', strtotime($startFull)),
                'stop'    => date('YmdHis O', strtotime($endFull)),
                'title'   => $title,
                'url'     => $url,
                'desc'    => $desc,
                'date'    => $date,
            ];
        }
    }

    return $programmes;
}

// ====================== XMLTV 生成 ======================
function generateXmltv(array $channels, array $programmes, string $filePath): bool
{
    initDir(dirname($filePath));

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput       = true;
    $dom->preserveWhiteSpace = false;

    $tv = $dom->createElement('tv');
    $tv->setAttribute('source-info-url',     'https://www.rthk.hk/');
    $tv->setAttribute('source-info-name',    '香港電台網站');
    $tv->setAttribute('generator-info-name', 'RTHK EPG Grabber');
    $dom->appendChild($tv);

    foreach ($channels as $id => $info) {
        $ch = $dom->createElement('channel');
        $ch->setAttribute('id', $id);

        $name = $dom->createElement('display-name');
        $name->appendChild($dom->createTextNode($info['name']));
        $name->setAttribute('lang', 'zh-Hant');
        $ch->appendChild($name);

        if (!empty($info['logo'])) {
            $icon = $dom->createElement('icon');
            $icon->setAttribute('src', $info['logo']);
            $ch->appendChild($icon);
        }
        $tv->appendChild($ch);
    }

    foreach ($programmes as $prog) {
        $p = $dom->createElement('programme');
        $p->setAttribute('start',   $prog['start']);
        $p->setAttribute('stop',    $prog['stop']);
        $p->setAttribute('channel', $prog['channel']);

        $title = $dom->createElement('title');
        $title->appendChild($dom->createTextNode($prog['title']));
        $title->setAttribute('lang', 'zh-Hant');
        $p->appendChild($title);

        if (!empty($prog['desc'])) {
            $desc = $dom->createElement('desc');
            $desc->appendChild($dom->createTextNode($prog['desc']));
            $desc->setAttribute('lang', 'zh-Hant');
            $p->appendChild($desc);
        }

        if (!empty($prog['url'])) {
            $url = $dom->createElement('url');
            $url->appendChild($dom->createTextNode($prog['url']));
            $p->appendChild($url);
        }
        $tv->appendChild($p);
    }

    return $dom->save($filePath) !== false;
}

// ====================== 主执行流程 ======================
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
}

log_msg('INFO', '========== RTHK 全频道 EPG 采集开始 ==========');

// 初始化目录
initDir($config['cache_dir']);
initDir(dirname($tvXmlPath));
initDir(dirname($radioXmlPath));

$dates = generateDateRange($config['days_past'], $config['days_future']);
log_msg('INFO', '采集日期范围: ' . reset($dates) . ' ~ ' . end($dates) . ' (共' . count($dates) . '天)');
log_msg('INFO', '频道总数: ' . count($channelMap) . ' (电视5个 + 广播8个)');

$allProgrammes = [];
$today = date('Y-m-d');

// 1. 广播频道当日：优先走电台首页
$todayHtml = false;
if (!empty($config['enable_online'])) {
    $proxyStatus = !empty($config['proxy']['enable']) ? '（代理模式）' : '（直连模式）';
    log_msg('INFO', "抓取电台首页（当日广播数据）{$proxyStatus}");
    $todayHtml = fetchPage($config['radio_home'], $config);
    if ($todayHtml && !empty($config['save_cache'])) {
        file_put_contents(rtrim($config['cache_dir'], '/') . '/radio_home.html', $todayHtml);
    }
}

if ($todayHtml) {
    log_msg('INFO', "解析当日广播频道节目单...");
    $todayTotal = 0;
    foreach ($channelMap as $channelId => $ch) {
        if (!isRadioChannel($channelId)) continue;

        $progs = parseHomePage($todayHtml, $channelId, $today);
        $cnt = count($progs);
        log_msg('INFO', "  {$ch['name']}: {$cnt} 个节目");
        $todayTotal += $cnt;
        $allProgrammes = array_merge($allProgrammes, $progs);
    }
    log_msg('INFO', "当日广播解析完成，合计 {$todayTotal} 个节目");
}

// 2. 全频道全日期：走时间表页
log_msg('INFO', "开始时间表页全量采集...");
foreach ($dates as $date) {
    log_msg('INFO', "处理日期: {$date}");
    $dayTotal = 0;

    foreach ($channelMap as $channelId => $ch) {
        // 广播频道当日已用首页解析，跳过重复
        if (isRadioChannel($channelId) && $date === $today && $todayHtml) {
            continue;
        }

        $cacheFile = rtrim($config['cache_dir'], '/') . "/{$channelId}_{$date}.html";
        $html = false;

        if (file_exists($cacheFile)) {
            $html = file_get_contents($cacheFile);
        }

        if (!$html && !empty($config['enable_online'])) {
            // 【修复】日期参数转为 YYYYMMDD 8位格式，匹配官网要求
            $dateParam = str_replace('-', '', $date);
            $url = $config['timetable_base'] . $channelId . '?date=' . $dateParam . '&lang=zh-hant';
            $html = fetchPage($url, $config);
            if ($html && !empty($config['save_cache'])) {
                file_put_contents($cacheFile, $html);
            }
        }

        if (!$html) {
            log_msg('WARN', "  {$ch['name']} 页面获取失败，跳过");
            continue;
        }

        $progs = parseTimetablePage($html, $channelId, $date, $config['debug']);
        $cnt = count($progs);
        $dayTotal += $cnt;
        log_msg('INFO', "  {$ch['name']}: {$cnt} 个节目");
        $allProgrammes = array_merge($allProgrammes, $progs);
    }

    log_msg('INFO', "  {$date} 解析完成，共 {$dayTotal} 个节目");
}

// 3. 全局去重（避免首页与时间表页重复）
$uniqueProgs = [];
foreach ($allProgrammes as $p) {
    $key = $p['channel'] . '_' . $p['start'];
    $uniqueProgs[$key] = $p;
}
$uniqueProgs = array_values($uniqueProgs);
log_msg('INFO', '全部日期解析完成，共 ' . count($uniqueProgs) . ' 个有效节目');

// 4. 拆分电视/广播并生成 XML
if ($config['generate_xml']) {
    $tvChannels = [];
    $radioChannels = [];
    $tvProgs = [];
    $radioProgs = [];

    // 拆分频道
    foreach ($channelMap as $id => $info) {
        if (isRadioChannel($id)) {
            $radioChannels[$id] = $info;
        } else {
            $tvChannels[$id] = $info;
        }
    }

    // 拆分节目
    foreach ($uniqueProgs as $p) {
        if (isRadioChannel($p['channel'])) {
            $radioProgs[] = $p;
        } else {
            $tvProgs[] = $p;
        }
    }

    // 生成电视 EPG
    $tvOk = generateXmltv($tvChannels, $tvProgs, $tvXmlPath);
    log_msg($tvOk ? 'SUCCESS' : 'ERROR',
        $tvOk ? '电视 XMLTV 生成成功 → ' . $tvXmlPath : '电视 XMLTV 生成失败');

    // 生成广播 EPG
    $radioOk = generateXmltv($radioChannels, $radioProgs, $radioXmlPath);
    log_msg($radioOk ? 'SUCCESS' : 'ERROR',
        $radioOk ? '广播 XMLTV 生成成功 → ' . $radioXmlPath : '广播 XMLTV 生成失败');
}

log_msg('INFO', '========== 执行完成 ==========');
exit(0);
