#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
TBC EPG 抓取器
支持 HTTP_PROXY, HTTPS_PROXY, SOCKS5_PROXY 环境变量
包含重试、并发控制、随机延迟，输出 XMLTV 格式文件到 epg 目录
内置繁体转简体功能
"""

import asyncio
import datetime
import html
import os
import re
import sys
import random
import time
from datetime import datetime, timedelta
from functools import wraps

import pytz
import requests
from bs4 import BeautifulSoup as bs
from loguru import logger
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
from opencc import OpenCC

# ---------- 全局配置 ----------
TAIPEI_TZ = pytz.timezone('Asia/Taipei')
#SHANGHAI_TZ = pytz.timezone('Asia/Shanghai')

# 输出目录 (由环境变量或默认值 epg)
OUTPUT_DIR = os.environ.get("OUTPUT_DIR", "epg")
os.makedirs(OUTPUT_DIR, exist_ok=True)

# 繁简转换器：繁体转简体
CC = OpenCC('t2s')

# ---------- 代理配置 (支持 HTTP_PROXY, HTTPS_PROXY, SOCKS5_PROXY) ----------
PROXIES = {}

http_proxy = os.environ.get("HTTP_PROXY")
if http_proxy:
    PROXIES["http"] = http_proxy

https_proxy = os.environ.get("HTTPS_PROXY")
if https_proxy:
    PROXIES["https"] = https_proxy
elif http_proxy and not https_proxy:
    PROXIES["https"] = http_proxy

socks5_proxy = os.environ.get("SOCKS5_PROXY")
if socks5_proxy:
    PROXIES["socks5"] = socks5_proxy
    PROXIES["socks5h"] = socks5_proxy

if PROXIES:
    logger.info(f"使用代理: {PROXIES}")
else:
    logger.info("未设置代理环境变量，使用直连")

HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
}

# 并发控制：同时最多 10 个请求
SEMAPHORE = asyncio.Semaphore(10)

# 重试配置
RETRY_COUNT = 3
RETRY_BACKOFF = 2  # 指数退避基数（秒）


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
                    wait_time = backoff ** attempt + random.uniform(0, 1)
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
        status_forcelist=[500, 502, 503, 504],
        allowed_methods=["GET"]
    )
    adapter = HTTPAdapter(max_retries=retries)
    session.mount("http://", adapter)
    session.mount("https://", adapter)
    session.proxies.update(PROXIES)
    session.headers.update(HEADERS)
    return session


# ---------- 工具函数 ----------
def t2s(text):
    """繁体文本转简体"""
    if not text:
        return text
    return CC.convert(text)


def clean_invalid_xml_chars(text):
    """移除 XML 非法字符"""
    return re.sub(r'[^\x09\x0A\x0D\x20-\uD7FF\uE000-\uFFFD]', '', text)


def time_stamp_to_timezone_str(dt, target_tz):
    """将带时区的 datetime 转换为 XMLTV 要求的格式"""
    if dt.tzinfo is None:
        dt = pytz.utc.localize(dt)
    return dt.astimezone(target_tz).strftime('%Y%m%d%H%M%S %z')


@async_retry(max_retries=RETRY_COUNT)
async def fetch_url(session, url, timeout=30):
    """异步 GET 请求，带重试"""
    response = await asyncio.to_thread(session.get, url, timeout=timeout)
    response.raise_for_status()
    return response


async def get_channels_tbc():
    """获取 TBC 所有频道列表"""
    channels = []
    try:
        session = create_retry_session()
        init_url = "https://www.tbc.net.tw/EPG/Epg/IndexV2/0/1"
        response = await fetch_url(session, init_url, timeout=10)
        response.encoding = "utf-8"
        soup = bs(response.text, "html.parser")

        channel_list = soup.select("ul.list_tv > li")
        if not channel_list:
            logger.error("频道列表解析失败，未找到列表元素")
            return []

        for li in channel_list:
            name = t2s(li.get("title", "").strip())
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
    """获取指定频道和日期的节目表（已应用重试）"""
    url = f"https://www.tbc.net.tw/EPG/epg/ChannelV2?channelId={channel_id}"
    programs = []
    special_channel_ids = [str(i) for i in range(404, 421)]

    async with SEMAPHORE:  # 控制并发
        try:
            session = create_retry_session()
            response = await fetch_url(session, url, timeout=30)
            response.encoding = "utf-8"
            soup = bs(response.text, "html.parser")
            uls = soup.find_all("ul", class_="list_program2")
            if not uls:
                logger.error(f"频道 {channel_name} 无节目表")
                return programs

            for ul in uls:
                actual_channel_name = t2s(ul.get("channelname", channel_name))
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
                        start_time = datetime.strptime(f"{program_date} {start_str}", "%Y/%m/%d %H:%M")
                        end_time = datetime.strptime(f"{program_date} {end_str}", "%Y/%m/%d %H:%M")
                        if end_time <= start_time:
                            end_time += timedelta(days=1)

                        start_time = TAIPEI_TZ.localize(start_time)
                        end_time = TAIPEI_TZ.localize(end_time)

                        if channel_id in special_channel_ids:
                            title = t2s(li.get("data.name", "").strip())
                        else:
                            title = t2s(li.get("title", "").strip())

                        if not title:
                            p_tag = li.find("p")
                            if p_tag:
                                title = t2s(p_tag.get_text(strip=True))

                        desc = t2s(li.get("desc", "").strip())

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

            # 随机延迟，避免单一频道内请求过快（每个频道只请求一次，此延迟用于不同频道之间）
            await asyncio.sleep(random.uniform(0.5, 2.0))

        except Exception as e:
            logger.error(f"解析频道 {channel_id} ({channel_name}) 失败: {str(e)}")
            # 不中断其他频道，返回空列表
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
            await asyncio.sleep(random.uniform(3, 6))

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
    """生成 XMLTV 格式的 EPG 数据 (通用排序与美化)"""
    import xml.etree.ElementTree as ET
    from xml.dom import minidom

    # 创建根元素，使用较标准的属性名称
    tv = ET.Element("tv", {"generator-info-name": "Taiwan-Broadband-EPG"})

    # 收集每个频道的节目
    channel_programs = {}
    for prog in programs:
        ch_name = prog["channelName"]
        channel_programs.setdefault(ch_name, []).append(prog)

    # 1. 先输出所有 channel 元素
    for channel_info in channels:
        ch_name = channel_info["channelName"]
        channel_elem = ET.SubElement(tv, "channel", id=ch_name)
        ET.SubElement(channel_elem, "display-name", lang="zh").text = ch_name

    # 2. 再输出所有 programme 元素，并按开始时间排序（提升可读性）
    all_progs = []
    for ch_name, progs in channel_programs.items():
        for prog in progs:
            all_progs.append((prog["start"], prog, ch_name))

    # 按 start 时间排序
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
        ET.SubElement(programme, "title", lang="zh").text = prog["programName"]
        if prog["description"]:
            desc_clean = clean_invalid_xml_chars(prog["description"])
            desc_clean = html.escape(desc_clean)
            ET.SubElement(programme, "desc", lang="zh").text = desc_clean

    # 3. 美化 XML 输出（含缩进）
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
    # 依赖安装: pip install requests beautifulsoup4 pytz loguru urllib3 opencc-python-reimplemented
    # 若需 SOCKS5 代理支持: pip install 'requests[socks]'
    asyncio.run(main())
