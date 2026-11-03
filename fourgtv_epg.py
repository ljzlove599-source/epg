import os
import sys
import json
import asyncio
import random
import ssl
from datetime import datetime
from xml.etree import ElementTree as ET

import aiohttp
import pytz
from loguru import logger

# 繁简转换依赖检查与初始化
try:
    from opencc import OpenCC
    CC_T2S = OpenCC('t2s')  # 繁体转简体配置
except ImportError:
    print("依赖缺失：请先执行 pip install opencc-python-reimplemented 安装繁简转换库")
    sys.exit(1)

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUTPUT_DIR = os.path.join(BASE_DIR, "epg")

CHANNEL_API_URL = "https://api2.4gtv.tv/Channel/GetAllChannel/pc/L"
LOCAL_CHANNEL_FILE = os.path.join(OUTPUT_DIR, "fourgtv.json")
PROG_URL_TEMPLATE = "https://www.4gtv.tv/ProgList/{channel_id}.txt"
MAX_CONCURRENT_REQUESTS = 5

# 不抓取的频道ID
SKIP_CHANNEL_IDS = {
    "4gtv-4gtv038",
}

def create_ssl_context():
    ctx = ssl.create_default_context()
    ctx.minimum_version = ssl.TLSVersion.TLSv1_3
    ctx.check_hostname = True
    return ctx

# 解析频道数据（支持API或本地JSON），返回精简频道列表
def parse_channel_data(data):
    if isinstance(data, dict):
        items = data.get("Data", [data])
    elif isinstance(data, list):
        items = data
    else:
        return []

    channels = []
    for item in items:
        ch_id = item.get("fs4GTV_ID")
        if not ch_id or ch_id in SKIP_CHANNEL_IDS:
            continue
        name_raw = item.get("fsNAME")
        if not name_raw:
            continue
        # 执行繁体转简体
        name = CC_T2S.convert(name_raw)
        description = CC_T2S.convert(item.get("fsDESCRIPTION", ""))
        channels.append({
            "channelName": name,
            "channelId": ch_id,
            "logo": item.get("fsLOGO_MOBILE", ""),
            "description": description
        })
    return channels

async def fetch_channels_from_api(session):
    """
    从4GTV API获取所有频道，过滤指定不抓取的ID，并按fsNAME去重。
    成功时返回去重后的频道列表，失败则返回None。
    同时会将去重后的数据写入本地fourgtv.json缓存文件。
    """
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
        "Accept": "application/json, text/plain, */*",
    }
    try:
        async with session.get(CHANNEL_API_URL, headers=headers,
                               timeout=aiohttp.ClientTimeout(total=15)) as resp:
            if resp.status != 200:
                logger.error(f"API返回状态码 {resp.status}")
                return None
            text = await resp.text()
            data = json.loads(text)
            if not data.get("Success", False):
                logger.error("API返回 Success=false")
                return None

            all_items = data["Data"]

            # 第一步：过滤要跳过的频道ID
            filtered_items = [item for item in all_items
                              if item.get("fs4GTV_ID") not in SKIP_CHANNEL_IDS]

            # 第二步：根据fsNAME去重（保留先出现的，跳过缺少fsNAME的项目）
            seen_names = set()
            deduped_items = []
            for item in filtered_items:
                name = item.get("fsNAME")
                if name and name not in seen_names:
                    seen_names.add(name)
                    deduped_items.append(item)

            # 将去重后的清单写入本地fourgtv.json（结构与原API一致）
            try:
                with open(LOCAL_CHANNEL_FILE, "w", encoding="utf-8") as f:
                    json.dump({"Success": True, "Data": deduped_items},
                              f, ensure_ascii=False, indent=2)
                logger.success(f"已更新本地频道缓存文件，共保存 {len(deduped_items)} 个频道")
            except Exception as e:
                logger.error(f"写入本地频道缓存文件失败: {e}")

            # 解析为精简的频道列表
            channels = parse_channel_data({"Data": deduped_items})
            if not channels:
                logger.warning("过滤后频道列表为空")
                return None

            logger.success(
                f"从API获取到 {len(channels)} 个频道"
                f"（原始 {len(all_items)} 个，跳过ID过滤 {len(all_items) - len(filtered_items)} 个，"
                f"名称去重后剩余 {len(deduped_items)} 个）"
            )
            return channels

    except Exception as e:
        logger.error(f"通过API获取频道列表失败: {e}")
        return None

def load_channels_from_local():
    if not os.path.exists(LOCAL_CHANNEL_FILE):
        logger.error(f"本地频道缓存文件不存在: {LOCAL_CHANNEL_FILE}")
        return None
    try:
        with open(LOCAL_CHANNEL_FILE, "r", encoding="utf-8") as f:
            data = json.load(f)
        channels = parse_channel_data(data)
        if channels:
            logger.success(f"从本地文件读取到 {len(channels)} 个频道")
        else:
            logger.error("本地文件中无有效频道")
        return channels or None
    except Exception as e:
        logger.error(f"读取本地频道缓存文件失败: {e}")
        return None

async def fetch_single_program(session, sem, channel, ssl_context, retries=3):
    ch_id = channel["channelId"]
    ch_name = channel["channelName"]
    url = PROG_URL_TEMPLATE.format(channel_id=ch_id)
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
        "Referer": "https://www.4gtv.tv/",
        "Accept": "application/json, text/plain, */*",
        "Origin": "https://www.4gtv.tv",
    }

    async with sem:
        await asyncio.sleep(random.uniform(0.1, 0.5))
        for attempt in range(1, retries + 1):
            try:
                async with session.get(url, headers=headers, ssl=ssl_context,
                                       timeout=aiohttp.ClientTimeout(total=15)) as resp:
                    if resp.status != 200:
                        raise aiohttp.ClientError(f"状态码 {resp.status}")
                    text = await resp.text()
                    if not text.strip().startswith(('[', '{')):
                        raise ValueError("返回内容不是有效JSON格式")
                    data = json.loads(text)
                    return parse_programs(data, ch_id, ch_name)
            except Exception as e:
                logger.warning(f"{ch_name} 第 {attempt} 次请求失败: {e}")
                if attempt < retries:
                    await asyncio.sleep(1 * attempt)
                else:
                    logger.error(f"{ch_name} 多次重试后最终获取失败")
                    return None

def parse_programs(raw_data, channel_id, channel_name):
    programs = []
    tz = pytz.timezone("Asia/Taipei")
    for item in raw_data:
        start = tz.localize(datetime.strptime(
            f"{item['sdate']} {item['stime']}", "%Y-%m-%d %H:%M:%S"))
        end = tz.localize(datetime.strptime(
            f"{item['edate']} {item['etime']}", "%Y-%m-%d %H:%M:%S"))
        # 执行繁体转简体
        program_name = CC_T2S.convert(item.get("title", ""))
        description = CC_T2S.convert(item.get("content", ""))
        programs.append({
            "channelId": channel_id,
            "channelName": channel_name,
            "programName": program_name,
            "description": description,
            "start": start,
            "end": end
        })
    return programs

async def fetch_all_programs(channels):
    ssl_ctx = create_ssl_context()
    sem = asyncio.Semaphore(MAX_CONCURRENT_REQUESTS)
    connector = aiohttp.TCPConnector(ssl=ssl_ctx, limit=MAX_CONCURRENT_REQUESTS * 2)
    async with aiohttp.ClientSession(connector=connector) as session:
        tasks = [fetch_single_program(session, sem, ch, ssl_ctx) for ch in channels]
        results = await asyncio.gather(*tasks)
    programs = []
    for ch, prog_list in zip(channels, results):
        if prog_list:
            programs.extend(prog_list)
        else:
            logger.warning(f"未能获取 {ch['channelName']} 的节目单")
    return programs

def generate_xml(channels, programs, filename):
    tv = ET.Element("tv")
    tv.set("source-info-name", "4gtv EPG")
    tv.set("source-info-url", "https://www.4gtv.tv")
    tv.set("generator-info-name", "4gtv EPG Generator")

    prog_by_ch = {}
    for p in programs:
        ch = p["channelName"]
        prog_by_ch.setdefault(ch, []).append(p)

    for ch in channels:
        ch_name = ch["channelName"]
        ch_elem = ET.SubElement(tv, "channel", id=ch_name)
        dn = ET.SubElement(ch_elem, "display-name", lang="zh")
        dn.text = ch_name
        if ch.get("logo"):
            icon = ET.SubElement(ch_elem, "icon")
            icon.set("src", ch["logo"])
        if ch.get("description"):
            desc = ET.SubElement(ch_elem, "desc", lang="zh")
            desc.text = ch["description"]

        if ch_name in prog_by_ch:
            sorted_progs = sorted(prog_by_ch[ch_name], key=lambda x: x["start"])
            for prog in sorted_progs:
                start_str = prog["start"].strftime("%Y%m%d%H%M%S +0800")
                end_str = prog["end"].strftime("%Y%m%d%H%M%S +0800")
                pr_elem = ET.SubElement(tv, "programme",
                                        start=start_str,
                                        stop=end_str,
                                        channel=ch_name)
                title = ET.SubElement(pr_elem, "title", lang="zh")
                title.text = prog["programName"]
                if prog.get("description"):
                    desc = ET.SubElement(pr_elem, "desc", lang="zh")
                    desc.text = prog["description"]

    tree = ET.ElementTree(tv)
    tree.write(filename, encoding="utf-8", xml_declaration=True)
    logger.info(f"电子节目单已生成: {filename}")

async def main_async():
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    log_file = os.path.join(OUTPUT_DIR, "epg_generator.log")
    logger.add(log_file, rotation="1 day", retention="7 days", encoding="utf-8",
               format="{time:YYYY-MM-DD HH:mm:ss} | {level} | {message}")

    logger.info("=" * 50)
    logger.info("开始生成四季线上电子节目单")
    logger.info(f"输出目录: {OUTPUT_DIR}")

    # 1. 优先调用API，失败回退本地缓存
    channels = None
    async with aiohttp.ClientSession() as session:
        try:
            channels = await fetch_channels_from_api(session)
        except Exception:
            pass
    if not channels:
        logger.warning("API获取失败，尝试读取本地缓存文件")
        channels = load_channels_from_local()
    if not channels:
        logger.critical("无法获取任何频道信息，流程终止")
        sys.exit(1)

    logger.info(f"成功加载 {len(channels)} 个频道")

    # 2. 并发抓取节目表
    programs = await fetch_all_programs(channels)
    logger.info(f"共获取 {len(programs)} 个节目")

    # 3. 生成XML文件
    xml_file = os.path.join(OUTPUT_DIR, "4g.xml")
    generate_xml(channels, programs, xml_file)
    logger.success("EPG生成完成")

if __name__ == "__main__":
    asyncio.run(main_async())
