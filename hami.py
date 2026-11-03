import asyncio
import os
import pytz
import aiohttp
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta
from loguru import logger
from opencc import OpenCC

# 繁体转简体
cc = OpenCC('t2s')

UA = "HamiVideo/7.12.806(Android 11;GM1910) OKHTTP/3.12.2"
HEADERS = {
    'X-ClientSupport-UserProfile': '1',
    'User-Agent': UA
}

# 请求配置
REQUEST_TIMEOUT = 30
MAX_RETRIES = 3
RETRY_DELAY = 10
CONCURRENT_LIMIT = 10  # 并发数限制，避免触发风控

async def fetch(session, url, params):
    """通用异步请求封装"""
    async with session.get(url, params=params, headers=HEADERS, timeout=REQUEST_TIMEOUT) as resp:
        resp.raise_for_status()
        return await resp.json()

async def request_channel_list(session):
    params = {
        "appVersion": "7.12.806",
        "deviceType": "1",
        "appOS": "android",
        "menuId": "162"
    }
    url = "https://apl-hamivideo.cdn.hinet.net/HamiVideo/getUILayoutById.php"
    channel_list = []
    
    try:
        data = await fetch(session, url, params)
        elements = []
        for info in data.get("UIInfo", []):
            if info.get("title") == "頻道一覽":
                elements = info.get('elements', [])
                break
        
        for element in elements:
            channel_name = cc.convert(element.get('title', ''))
            channel_list.append({
                "channelId": element.get('contentPk', ''), 
                "channelName": channel_name,
                "contentPk": element.get('contentPk', '')
            })
    except Exception as e:
        logger.error(f"获取频道列表失败: {e}")
    
    return channel_list

async def request_epg(session, channel_name: str, content_pk: str):
    url = "https://apl-hamivideo.cdn.hinet.net/HamiVideo/getEpgByContentIdAndDate.php"
    logger.info(f"正在获取 {channel_name} 节目表")
    
    epg_result = []
    today = datetime.now(pytz.timezone('Asia/Taipei'))
    
    for i in range(7):
        date = today + timedelta(days=i)
        formatted_date = date.strftime('%Y-%m-%d')
        params = {
            "deviceType": "1",
            "Date": formatted_date,
            "contentPk": content_pk,
        }
        
        try:
            data = await fetch(session, url, params)
            ui_info = data.get('UIInfo', [])
            if ui_info:
                elements = ui_info[0].get('elements', [])
                for element in elements:
                    program_info_list = element.get('programInfo', [])
                    if program_info_list:
                        program_info = program_info_list[0]
                        start_time, end_time = hami_time_to_datetime(program_info['hintSE'])
                        
                        program_name = cc.convert(program_info.get('programName', ''))
                        description = cc.convert(program_info.get('description', ''))
                        
                        epg_result.append({
                            "channelId": content_pk,
                            "channelName": cc.convert(element.get('title', '')),
                            "programName": program_name,
                            "description": description,
                            "start": start_time,
                            "end": end_time
                        })
        except Exception as e:
            logger.warning(f"获取 {channel_name} {formatted_date} 节目表失败: {e}")
    
    return epg_result

async def get_programs_with_retry(session, channel, semaphore):
    """带重试和并发限制的单频道节目获取"""
    async with semaphore:
        retries = 0
        while retries < MAX_RETRIES:
            try:
                return await request_epg(session, channel['channelName'], channel['contentPk'])
            except Exception as e:
                retries += 1
                logger.warning(f"{channel['channelName']} 请求失败，{RETRY_DELAY}s 后重试 ({retries}/{MAX_RETRIES})")
                await asyncio.sleep(RETRY_DELAY)
        
        logger.warning(f"{channel['channelName']} 达到最大重试次数，已跳过")
        return []

def hami_time_to_datetime(time_range: str):
    start_str, end_str = time_range.split('~')
    start = datetime.strptime(start_str, "%Y-%m-%d %H:%M:%S")
    end = datetime.strptime(end_str, "%Y-%m-%d %H:%M:%S")
    taipei_tz = pytz.timezone('Asia/Taipei')
    return taipei_tz.localize(start), taipei_tz.localize(end)

def generate_xml_epg(channels, programs):
    root = ET.Element("tv")
    root.set("info-name", "Hami电视节目表")
    root.set("info-url", "https://hamivideo.hinet.net/")
    
    for channel in channels:
        channel_id = channel["channelName"]
        channel_elem = ET.SubElement(root, "channel")
        channel_elem.set("id", channel_id)
        
        display_name = ET.SubElement(channel_elem, "display-name")
        display_name.text = channel["channelName"]
        
        channel_programs = [p for p in programs if p["channelId"] == channel["contentPk"]]
        channel_programs.sort(key=lambda p: p["start"])
        
        for program in channel_programs:
            programme = ET.SubElement(root, "programme")
            programme.set("start", program["start"].strftime("%Y%m%d%H%M%S %z"))
            programme.set("stop", program["end"].strftime("%Y%m%d%H%M%S %z"))
            programme.set("channel", channel_id)
            
            title = ET.SubElement(programme, "title")
            title.set("lang", "zh")
            title.text = program["programName"]
            
            if program["description"]:
                desc = ET.SubElement(programme, "desc")
                desc.set("lang", "zh")
                desc.text = program["description"]
    
    return ET.ElementTree(root)

async def main():
    logger.info("开始生成 Hami 电视节目表")
    
    current_dir = os.path.dirname(os.path.abspath(__file__))
    output_dir = os.path.join(current_dir, "epg")
    os.makedirs(output_dir, exist_ok=True)
    logger.info(f"输出目录: {output_dir}")
    
    semaphore = asyncio.Semaphore(CONCURRENT_LIMIT)
    timeout = aiohttp.ClientTimeout(total=REQUEST_TIMEOUT)
    
    async with aiohttp.ClientSession(timeout=timeout) as session:
        channels = await request_channel_list(session)
        logger.info(f"共找到 {len(channels)} 个频道")
        
        if not channels:
            logger.error("未获取到任何频道数据，任务终止")
            return
        
        tasks = [get_programs_with_retry(session, ch, semaphore) for ch in channels]
        results = await asyncio.gather(*tasks)
        
        all_programs = []
        for programs in results:
            if programs:
                all_programs.extend(programs)
        
        logger.info(f"共获取 {len(all_programs)} 条节目数据")
    
    # 生成并写入 XML
    xml_tree = generate_xml_epg(channels, all_programs)
    output_file = os.path.join(output_dir, "hami.xml")
    xml_tree.write(output_file, encoding="utf-8", xml_declaration=True)
    
    logger.info(f"节目表生成完成: {output_file}")
    logger.info(f"文件大小: {os.path.getsize(output_file) / 1024:.2f} KB")

if __name__ == '__main__':
    asyncio.run(main())
