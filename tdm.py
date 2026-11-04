#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
TDM EPG 抓取器
✅ 移除 pytz，使用标准库 zoneinfo
✅ 支持 HTTP_PROXY, HTTPS_PROXY, SOCKS5_PROXY 环境变量
✅ 双重重试、并发控制、随机延迟，输出 XMLTV 格式文件到 epg 目录
✅ 繁体转简体 + OpenCC 加载容错，适配 GitHub Actions 跨境网络
"""
import asyncio
import datetime
import html
import os
import re
import sys
import random
from datetime import datetime, timedelta
from functools import wraps
from zoneinfo import ZoneInfo

import requests
from bs4 import BeautifulSoup as bs
from loguru import logger
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# ---------- OpenCC 繁转简，增加降级容错 ----------
CC = None
try:
    from opencc import OpenCC
    CC = OpenCC('t2s')
    logger.info("OpenCC 加载成功，启用繁体转简体")
except ImportError:
    logger.warning("未安装 opencc-python-reimplemented，繁转简功能关闭，原样输出繁体")

# ---------- 全局配置 ----------
TAIPEI_TZ = ZoneInfo("Asia/Taipei")

# 输出目录 (由环境变量或默认值 epg)
OUTPUT_DIR = os.environ.get("OUTPUT_DIR", "epg")
os.makedirs(OUTPUT_DIR, exist_ok=True)

# ---------- 代理配置 (支持 HTTP_PROXY, HTTPS_PROXY, SOCKS5_PROXY) ----------
PROXIES = {}

http_proxy = os.environ.get("HTTP_PROXY") or os.environ.get("http_proxy")
if http_proxy:
    PROXIES["http"] = http_proxy

https_proxy = os.environ.get("HTTPS_PROXY") or os.environ.get("https_proxy")
if https_proxy:
    PROXIES["https"] = https_proxy
elif http_proxy and not https_proxy:
    PROXIES["https"] = http_proxy

socks5_proxy = os.environ.get("SOCKS5_PROXY") or os.environ.get("socks5_proxy")
if socks5_proxy:
    PROXIES["socks5"] = socks5_proxy
    PROXIES["socks5h"] = socks5_proxy

if PROXIES:
    logger.info(f"使用代理: {PROXIES}")
else:
    logger.info("未设置代理环境变量，使用直连")

HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
    'Accept-Language': 'zh-CN,zh;q=0.9,zh-TW;q=0.8',
    'Referer': 'https://www.tbc.net.tw/EPG/'
}

# 并发控制：同时最多 8 个请求（跨境环境降低并发减少超时）
SEMAPHORE = asyncio.Semaphore(8)

# 重试配置（增强版，适配跨境网络）
RETRY_COUNT = 5
RETRY_BACKOFF = 3  # 指数退避基数（秒）
CONNECT_TIMEOUT = 30  # 连接超时（秒）
READ_TIMEOUT = 60     # 读取超时（秒）


def async_retry(max_retries=RETRY_COUNT, backoff=RETRY_BACKOFF):
    """异步函数重试装饰器"""
    def decorator(func):
        @wraps(func)
        async def wrapper(*args, **kwargs):
            last_exception = None
            for attempt in range(max_retries):
                try:
                    return await func(*args, **kwargs)
                except Exception as e:
                    last_exception = e
                    wait_time = backoff ** attempt + random.uniform(0, 2)
                    logger.warning(f"重试 {func.__name__} (尝试 {attempt+1}/{max_retries})，等待 {wait_time:.2f} 秒，错误: {str(e)}")
                    await asyncio.sleep(wait_time)
            raise last_exception
        return wrapper
    return decorator


def create_retry_session():
    """创建带重试机制的 requests 会话"""
    session = requests.Session()
    retries = Retry(
        total=RETRY_COUNT,
        backoff_factor=RETRY_BACKOFF,
        status_forcelist=[500, 502, 503, 504, 429],
        allowed_methods=["GET"]
    )
    adapter = HTTPAdapter(max_retries=retries, pool_connections=20, pool_maxsize=20)
    session.mount("http://", adapter)
    session.mount("https://", adapter)
    session.proxies.update(PROXIES)
    session.headers.update(HEADERS)
    return session


# ---------- 工具函数【增强繁转简】 ----------
def t2s(text):
    """繁体文本转简体，空值/None安全；CC不存在时原样返回"""
    if text is None:
        return ""
    text = str(text).strip()
    if not text or CC is None:
        return text
    return CC.convert(text)


def clean_invalid_xml_chars(text):
    """移除 XML 非法字符"""
    return re.sub(r'[^\x09\x0A\x0D\x20-\uD7FF\uE000-\uFFFD]', '', text)


def time_stamp_to_timezone_str(dt, target_tz):
    """将带时区的 datetime 转换为 XMLTV 要求的格式"""
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=ZoneInfo("UTC"))
    return dt.astimezone(target_tz).strftime('%Y%m%d%H%M%S %z')


@async_retry(max_retries=RETRY_COUNT)
async def fetch_url(session, url, timeout=(CONNECT_TIMEOUT, READ_TIMEOUT)):
    """异步 GET 请求，带双重重试，超时分离"""
    response = await asyncio.to_thread(session.get, url, timeout=timeout)
    response.raise_for_status()
    return response


async def get_channels_tbc():
    """获取 TBC 所有频道列表，频道名自动转简体"""
    channels = []
    try:
        session = create_retry_session()
        init_url = "https://www.tbc.net.tw/EPG/Epg/IndexV2/0/1"
        response = await fetch_url(session, init_url)
        response.encoding = "utf-8"
        soup = bs(response.text, "html.parser")

        channel_list = soup.select("ul.list_tv > li")
        if not channel_list:
            logger.error("频道列表解析失败，未找到列表元素")
            return []

        for li in channel_list:
            raw_name = li.get("title", "").strip()
            name = t2s(raw_name)
            if not name:
                continue
            channel_id = li.get("id", "")
            img = li.find("img")
            img_src = img["src"] if img and "src" in img.attrs else ""

            channels.append({
                "name": name,
                "channelName": name,
                "id": [channel_id],
                "url": li.find("a")["href"] if li.find("a") else "",
                "source": "tbc",
                "logo": img_src,
                "desc": "",
                "sort": "海外",
            })

        logger.info(f"成功获取 {len(channels)} 个频道")
    except Exception as e:
        logger.error(f"获取频道列表失败: {str(e)}")
    return channels


@async_retry(max_retries=RETRY_COUNT)
async def get_epgs_tbc(channel_id, date_str, channel_name):
    """获取指定频道和日期的节目表；修复data-name读取BUG，全部字段繁转简"""
    url = f"https://www.tbc.net.tw/EPG/epg/ChannelV2?channelId={channel_id}"
    programs = []
    special_channel_ids = [str(i) for i in range(404, 421)]

    async with SEMAPHORE:  # 控制并发
        try:
            session = create_retry_session()
            response = await fetch_url(session, url)
            response.encoding = "utf-8"
            soup = bs(response.text, "html.parser")
            uls = soup.find_all("ul", class_="list_program2")
            if not uls:
                logger.error(f"频道 {channel_name} 无节目表")
                return programs

            for ul in uls:
                raw_actual_name = ul.get("channelname", channel_name)
                actual_channel_name = t2s(raw_actual_name)
                for li in ul.find_all("li"):
                    program_date = li.get("date", "").strip()
                    if program_date != date_str:
                        continue

                    time_range = li.get("time", "").strip()
                    time_match = re.search(r"(\d+:\d+)~(\d+:\d+)", time_range)
                    if not time_match:
                        continue

                    start_str, end_str = time_match.groups()
                    try:
                        start_time = datetime.strptime(f"{program_date} {start_str}", "%Y/%m/%d %H:%M").replace(tzinfo=TAIPEI_TZ)
                        end_time = datetime.strptime(f"{program_date} {end_str}", "%Y/%m/%d %H:%M").replace(tzinfo=TAIPEI_TZ)
                        if end_time <= start_time:
                            end_time += timedelta(days=1)

                        # ✅ 修复原来BUG：li.get("data.name") → li.get("data-name")
                        if channel_id in special_channel_ids:
                            raw_title = li.get("data-name", "").strip()
                        else:
                            raw_title = li.get("title", "").strip()

                        if not raw_title:
                            p_tag = li.find("p")
                            if p_tag:
                                raw_title = p_tag.get_text(strip=True)

                        title = t2s(raw_title)
                        raw_desc = li.get("desc", "").strip()
                        desc = t2s(raw_desc)

                        if title:
                            programs.append({
                                "channelName": actual_channel_name,
                                "programName": title,
                                "description": desc,
                                "start": start_time,
                                "end": end_time
                            })
                        else:
                            logger.warning(f"频道 {actual_channel_name} 发现无名称节目: {time_range}")

                    except Exception as e:
                        logger.error(f"处理节目时间出错: {program_date} {time_range} - {str(e)}")

            # 随机延迟，降低请求速率
            await asyncio.sleep(random.uniform(1, 3))

        except Exception as e:
            logger.error(f"解析频道 {channel_id} ({channel_name}) 失败: {str(e)}")
            return programs

    return programs


async def get_tbc_epg(total_days=6):
    """获取多日 EPG 数据，跳过 300-329 号频道"""
    logger.info("正在获取TBC EPG")
    channels = await get_channels_tbc()
    if not channels:
        logger.error("无法获取频道列表，终止")
        return [], []

    skip_ids = [str(i) for i in range(300, 330)]  # 跳过 300~329
    all_programs = []

    for day_offset in range(total_days):
        dt = datetime.now(TAIPEI_TZ) + timedelta(days=day_offset)
        date_str = dt.strftime('%Y/%m/%d')
        logger.info(f"正在获取 {date_str} 节目表")

        # 过滤出需要抓取的频道
        tasks = []
        for channel in channels:
            cid = channel["id"][0]
            if cid in skip_ids:
                continue
            tasks.append(get_epgs_tbc(cid, date_str, channel["name"]))

        if not tasks:
            logger.warning(f"{date_str} 没有可获取的频道")
            continue

        # 并行执行所有任务
        results = await asyncio.gather(*tasks, return_exceptions=True)

        for res in results:
            if isinstance(res, Exception):
                logger.error(f"抓取节目表异常: {str(res)}")
            elif res:
                all_programs.extend(res)

        # 两天之间增加较长延迟，避免触发速率限制
        if day_offset < total_days - 1:
            await asyncio.sleep(random.uniform(5, 10))

    # 统计
    channel_counts = {}
    for prog in all_programs:
        ch = prog["channelName"]
        channel_counts[ch] = channel_counts.get(ch, 0) + 1
    for ch, cnt in channel_counts.items():
        logger.info(f"频道 {ch} 获取 {cnt} 个节目")
    logger.info(f"总计获取 {len(all_programs)} 个节目")
    return channels, all_programs


def generate_epg_xml(channels, programs):
    """生成 XMLTV 格式的 EPG 数据，输出前二次兜底繁转简"""
    import xml.etree.ElementTree as ET
    from xml.dom import minidom

    tv = ET.Element("tv", {"generator-info-name": "Taiwan-Broadband-EPG"})

    # 收集每个频道的节目
    channel_programs = {}
    for prog in programs:
        ch_name = prog["channelName"]
        channel_programs.setdefault(ch_name, []).append(prog)

    # 输出 channel 元素
    for channel_info in channels:
        ch_name = t2s(channel_info["channelName"])
        channel_elem = ET.SubElement(tv, "channel", id=ch_name)
        ET.SubElement(channel_elem, "display-name", lang="zh").text = ch_name

    # 输出 programme 元素，按开始时间排序
    all_progs = []
    for ch_name, progs in channel_programs.items():
        for prog in progs:
            all_progs.append((prog["start"], prog, ch_name))

    all_progs.sort(key=lambda x: x[0])

    for _, prog, ch_name in all_progs:
        start_str = time_stamp_to_timezone_str(prog["start"], TAIPEI_TZ)
        end_str = time_stamp_to_timezone_str(prog["end"], TAIPEI_TZ)

        programme = ET.SubElement(
            tv, "programme",
            channel=ch_name,
            start=start_str,
            stop=end_str
        )
        title_text = t2s(prog["programName"])
        ET.SubElement(programme, "title", lang="zh").text = title_text
        if prog["description"]:
            desc_text = t2s(prog["description"])
            desc_clean = clean_invalid_xml_chars(desc_text)
            desc_clean = html.escape(desc_clean)
            ET.SubElement(programme, "desc", lang="zh").text = desc_clean

    # 美化 XML 输出
    rough_string = ET.tostring(tv, encoding='utf-8')
    reparsed = minidom.parseString(rough_string)
    pretty_xml = reparsed.toprettyxml(indent="  ", encoding='utf-8')
    return pretty_xml


async def main():
    logger.add(os.path.join(OUTPUT_DIR, "epg_generator.log"), rotation="1 day", retention="7 days")
    logger.info("========== 开始抓取 TBC EPG ==========")

    channels, programs = await get_tbc_epg(total_days=6)
    if not channels or not programs:
        logger.error("没有获取到任何 EPG 数据")
        sys.exit(1)

    xml_data = generate_epg_xml(channels, programs)
    output_file = os.path.join(OUTPUT_DIR, "epg_tbc.xml")
    with open(output_file, "wb") as f:
        f.write(xml_data)
    logger.info(f"EPG 已保存到 {output_file}")

    logger.info("========== 抓取完成 ==========")


if __name__ == "__main__":
    # 依赖：pip install requests beautifulsoup4 loguru urllib3 opencc-python-reimplemented tzdata
    # SOCKS5代理额外：pip install 'requests[socks]'
    asyncio.run(main())
