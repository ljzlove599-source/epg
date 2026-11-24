<?php
/**
 * NowTV EPG 抓取脚本（PHP 版本 + 外置繁简转换）
 * 输出标准 XMLTV 格式文件到 epg/epg_nowtv.xml
 * 依赖：php-curl, php-xmlwriter（PHP 7.4+ 默认内置）
 * 繁简字典：同目录下 t2s.json，格式为 {"繁体":"简体"} 键值对
 */

class NowTVEPG
{
    // 接口常量（与原 Python 代码完全一致）
    private const CATALOG_API = 'https://catalogapi.nowtv.now.com/CatalogEngine';
    private const PLATFORM = 'NPX';
    private const LANG = 'zh';
    private const APP_ID = '15';
    private const DETAIL_CHUNK_SIZE = 500; // 单批节目详情请求数量
    private const TIMEZONE = 'Asia/Shanghai';

    private string $outputFile;
    private DateTimeZone $tz;
    private array $charMap = []; // 繁简转换映射表

    public function __construct(string $outputFile = __DIR__ . '/epg_sources/epg_nowtv.xml')
    {
        $this->outputFile = $outputFile;
        $this->tz = new DateTimeZone(self::TIMEZONE);
        $this->loadCharMap(); // 加载外置繁简转换字典
    }

    /**
     * 加载同目录下的 t2s.json 繁简转换字典
     */
    private function loadCharMap(): void
    {
        $mapFile = __DIR__ . '/t2s.json';
        if (!file_exists($mapFile)) {
            $this->log("ℹ️ 未找到 t2s.json 字典，保持繁体输出");
            return;
        }

        $jsonContent = file_get_contents($mapFile);
        if ($jsonContent === false) {
            $this->log("⚠️ 读取 t2s.json 失败，保持繁体输出");
            return;
        }

        $map = json_decode($jsonContent, true);
        if (!is_array($map)) {
            $this->log("⚠️ t2s.json 格式错误（需为键值对对象），保持繁体输出");
            return;
        }

        $this->charMap = $map;
        $this->log("🔤 已加载繁简转换字典，共 " . count($map) . " 条映射");
    }

    /**
     * 繁体转简体（基于外置字典）
     */
    private function t2s(string $text): string
    {
        if (empty($this->charMap) || $text === '') {
            return $text;
        }
        // strtr 自动优先匹配最长词组，无需手动排序
        return strtr($text, $this->charMap);
    }

    /**
     * 生成接口调用的 callerReferenceNo（与 Python 逻辑一致）
     */
    private function generateRefNo(): string
    {
        return 'Ad449480d' . (int)(microtime(true) * 1000);
    }

    /**
     * 统一 POST 请求封装
     */
    private function postRequest(string $endpoint, array $payload): ?array
    {
        $url = self::CATALOG_API . '/' . $endpoint;
        $headers = [
            'Host: catalogapi.nowtv.now.com',
            'Content-Type: application/json; charset=utf-8',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            $this->log("请求失败 [{$endpoint}] HTTP:{$httpCode} 错误:{$error}");
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON 解析失败 [{$endpoint}]: " . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /**
     * 获取频道列表（自动繁简转换）
     */
    public function fetchChannels(): array
    {
        $this->log("📡 正在从 NowTV 获取频道列表");
        $payload = [
            'appId' => self::APP_ID,
            'lang' => self::LANG,
            'callerReferenceNo' => $this->generateRefNo(),
            'platform' => self::PLATFORM,
        ];

        $data = $this->postRequest('getLiveChannelList', $payload);
        if (!$data || !isset($data['channelList'])) {
            throw new Exception("频道列表获取失败");
        }

        $channels = [];
        foreach ($data['channelList'] as $item) {
            $channels[] = [
                'channel_id' => (string)$item['channelId'],
                'name' => $this->t2s($item['name']), // 频道名繁转简
                'logo' => $item['channelLogoLink'] ?? null,
            ];
        }

        $this->log("📺 从 NowTV 发现 " . count($channels) . " 个频道");
        return $channels;
    }

    /**
     * 批量获取节目详情（分块请求，与 Python 分块逻辑一致）
     */
    private function fetchProgramDetails(array $progIds): array
    {
        $chunks = array_chunk($progIds, self::DETAIL_CHUNK_SIZE);
        $totalChunks = count($chunks);
        $details = [];

        foreach ($chunks as $index => $chunk) {
            $this->log("  正在获取第 " . ($index + 1) . "/{$totalChunks} 批节目详情");
            $payload = [
                'lang' => self::LANG,
                'programIdList' => $chunk,
                'callerReferenceNo' => $this->generateRefNo(),
                'platform' => self::PLATFORM,
            ];

            $data = $this->postRequest('getEPGProgramDetailList', $payload);
            if (!$data || !isset($data['epgProgramList'])) {
                $this->log("  ⚠️  第 " . ($index + 1) . " 批详情获取失败，跳过");
                continue;
            }

            foreach ($data['epgProgramList'] as $item) {
                $details[(string)$item['programId']] = $item;
            }
        }

        return $details;
    }

    /**
     * 获取所有频道的节目单（未来7天，自动繁简转换）
     */
    public function fetchPrograms(array $channels): array
    {
        $channelCount = count($channels);
        $this->log("📡 正在抓取 {$channelCount} 个频道的节目数据");
        $channelIds = array_column($channels, 'channel_id');

        $payload = [
            'channelIdList' => $channelIds,
            'startDay' => 0,
            'endDay' => 6,
            'lang' => self::LANG,
            'callerReferenceNo' => $this->generateRefNo(),
            'platform' => self::PLATFORM,
        ];

        $data = $this->postRequest('getEPGDetail', $payload);
        if (!$data || !isset($data['epgDetail'])) {
            throw new Exception("EPG 节目列表获取失败");
        }

        // 收集原始节目和节目ID
        $rawPrograms = [];
        $progIds = [];
        foreach ($data['epgDetail'] as $channelEpg) {
            $cid = (string)$channelEpg['channelId'];
            foreach ($channelEpg['programs'] as $prog) {
                $rawPrograms[] = ['cid' => $cid, 'prog' => $prog];
                $progIds[] = (string)$prog['vimProgramId'];
            }
        }

        $rawCount = count($rawPrograms);
        $this->log("📊 共获取到 {$rawCount} 个节目，正在批量获取节目详情");
        $details = $this->fetchProgramDetails($progIds);

        // 组装最终节目数据（繁简转换）
        $programs = [];
        foreach ($rawPrograms as $item) {
            try {
                $cid = $item['cid'];
                $prog = $item['prog'];
                $progId = (string)$prog['vimProgramId'];
                $detail = $details[$progId] ?? [];

                // 优先取中文简介， fallback 英文
                $desc = $detail['chiSynopsis'] ?? $detail['synopsis'] ?? '';

                // 毫秒时间戳转上海时区时间（与 Python 逻辑完全一致）
                $start = DateTime::createFromFormat('U', (int)($prog['start'] / 1000));
                $start->setTimezone($this->tz);
                $end = DateTime::createFromFormat('U', (int)($prog['end'] / 1000));
                $end->setTimezone($this->tz);

                $programs[] = [
                    'channel_id' => $cid,
                    'title' => $this->t2s($prog['name'] ?? ''), // 标题繁转简
                    'start_time' => $start,
                    'end_time' => $end,
                    'description' => $this->t2s($desc), // 简介繁转简
                ];
            } catch (Exception $e) {
                $this->log("⚠️  解析节目数据失败: " . $e->getMessage());
            }
        }

        $this->log("✅ 共生成 " . count($programs) . " 个节目");
        return $programs;
    }

    /**
     * 生成标准 XMLTV 格式 EPG
     */
    private function generateEpgXml(array $channels, array $programs): string
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        // 根节点
        $xml->startElement('tv');
        $xml->writeAttribute('source-info-url', 'https://www.nowtv.now.com/');
        $xml->writeAttribute('source-info-name', 'NowTV EPG');

        // 写入频道列表
        foreach ($channels as $ch) {
            $xml->startElement('channel');
            $xml->writeAttribute('id', $ch['channel_id']);

            $xml->startElement('display-name');
            $xml->writeAttribute('lang', self::LANG);
            $xml->text($ch['name']);
            $xml->endElement();

            if ($ch['logo']) {
                $xml->startElement('icon');
                $xml->writeAttribute('src', $ch['logo']);
                $xml->endElement();
            }

            $xml->endElement(); // channel
        }

        // 写入节目列表
        foreach ($programs as $prog) {
            $xml->startElement('programme');
            $xml->writeAttribute('start', $prog['start_time']->format('YmdHis O'));
            $xml->writeAttribute('stop', $prog['end_time']->format('YmdHis O'));
            $xml->writeAttribute('channel', $prog['channel_id']);

            $xml->startElement('title');
            $xml->writeAttribute('lang', self::LANG);
            $xml->text($prog['title']);
            $xml->endElement();

            if (!empty($prog['description'])) {
                $xml->startElement('desc');
                $xml->writeAttribute('lang', self::LANG);
                $xml->text($prog['description']);
                $xml->endElement();
            }

            $xml->endElement(); // programme
        }

        $xml->endElement(); // tv
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * 主执行入口
     */
    public function run(): void
    {
        try {
            // 确保输出目录存在
            $outputDir = dirname($this->outputFile);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
                $this->log("📁 创建输出目录: {$outputDir}");
            }

            // 1. 获取频道
            $channels = $this->fetchChannels();
            if (empty($channels)) {
                throw new Exception("频道列表为空，终止执行");
            }

            // 2. 获取节目
            $programs = $this->fetchPrograms($channels);

            // 3. 生成 XML 并写入文件
            $xmlContent = $this->generateEpgXml($channels, $programs);
            file_put_contents($this->outputFile, $xmlContent);

            $channelCount = count($channels);
            $progCount = count($programs);
            $this->log("🎉 EPG 生成完成！已保存到 {$this->outputFile}");
            $this->log("📊 统计：{$channelCount} 个频道，{$progCount} 个节目");

        } catch (Exception $e) {
            $this->log("❌ 执行失败: " . $e->getMessage());
            exit(1);
        }
    }

    /**
     * 简单日志输出
     */
    private function log(string $message): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
}

// 执行脚本
$epg = new NowTVEPG();
$epg->run();
