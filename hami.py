import asyncio
import os
import pytz
import requests
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta
from loguru import logger
from opencc import OpenCC

# 初始化繁体转简体转换器
cc = OpenCC('t2s')

UA = "HamiVideo/7.12.806(Android 11;GM1910) OKHTTP/3.12.2"
headers = {
    'X-ClientSupport-UserProfile': '1',
    'User-Agent': UA
}

# 设置超时时间（秒）
REQUEST_TIMEOUT = 30
MAX_RETRIES = 3
RETRY_DELAY = 10

async def request_channel_list():
    params = {
        "appVersion": "7.12.806",
        "deviceType": "1",
        "appOS": "android",
        "menuId": "162"
    }

    url = "https://apl-hamivideo.cdn.hinet.net/HamiVideo/getUILayoutById.php"
    channel_list = []
    try:
        response = requests.get(url, params=params, headers=headers, timeout=REQUEST_TIMEOUT)
        if response.status_code == 200:
            data = response.json()
            elements = []

            for info in data.get("UIInfo", []):
                # 接口返回原始繁体标题，保留原字符串用于匹配
                if info.get("title") == "頻道一覽":
                    elements = info.get('elements', [])
                    break
            
            for element in elements:
                # 频道名称转换为简体
                channel_name = cc.convert(element.get('title', ''))
                channel_list.append({
                    "channelId": element.get('contentPk', ''), 
                    "channelName": channel_name,
                    "contentPk": element.get('contentPk', '')
                })
    except Exception as e:
        print(f"获取频道列表时出错: {e}")
    
    return channel_list

async def get_programs_with_retry(channel):
    retries = 0

    while retries < MAX_RETRIES:
        try:
            programs = await request_epg(channel['channelName'], channel['contentPk'])
            return programs
        except Exception as e:
            retries += 1
            print(f"请求 {channel['channelName']} 的EPG时出错: {e}")
            print(f"将在 {RETRY_DELAY} 秒后重试 ({retries}/{MAX_RETRIES})")
            await asyncio.sleep(RETRY_DELAY)
    
    logger.warning(f"{channel['channelName']} 达到最大重试次数，跳过...")
    return []

async def request_all_epg():
    print("开始获取频道列表...")
    rawChannels = await request_channel_list()
    print(f"找到 {len(rawChannels)} 个频道")
    
    all_programs = []
    
    # 使用asyncio.gather并行获取所有频道的节目
    tasks = []
    for channel in rawChannels:
        tasks.append(get_programs_with_retry(channel))
    
    results = await asyncio.gather(*tasks)
    
    for programs in results:
        if programs:
            all_programs.extend(programs)
    
    print(f"共获取 {len(all_programs)} 个节目")
    return rawChannels, all_programs

async def request_epg(channel_name: str, content_pk: str):
    url = "https://apl-hamivideo.cdn.hinet.net/HamiVideo/getEpgByContentIdAndDate.php"
    print(f"获取 {channel_name} 的节目表...")
    
    epgResult = []
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
            response = requests.get(url, params=params, headers=headers, timeout=REQUEST_TIMEOUT)
            if response.status_code == 200:
                data = response.json()
                ui_info = data.get('UIInfo', [])
                if ui_info:
                    elements = ui_info[0].get('elements', [])
                    for element in elements:
                        program_info_list = element.get('programInfo', [])
                        if program_info_list:
                            program_info = program_info_list[0]
                            start_time, end_time = hami_time_to_datetime(program_info['hintSE'])
                            
                            # 节目名称、描述转换为简体
                            program_name = cc.convert(program_info.get('programName', ''))
                            description = cc.convert(program_info.get('description', ''))
                            
                            epgResult.append({
                                "channelId": content_pk,
                                "channelName": cc.convert(element.get('title', '')),
                                "programName": program_name,
                                "description": description,
                                "start": start_time,
                                "end": end_time
                            })
        except Exception as e:
            print(f"获取 {channel_name} 在 {formatted_date} 的节目表时出错: {e}")
    
    return epgResult

def hami_time_to_datetime(time_range: str):
    start_time_str, end_time_str = time_range.split('~')
    start_time = datetime.strptime(start_time_str, "%Y-%m-%d %H:%M:%S")
    end_time = datetime.strptime(end_time_str, "%Y-%m-%d %H:%M:%S")
    taipei_tz = pytz.timezone('Asia/Taipei')
    start_time_taipei = taipei_tz.localize(start_time)
    end_time_taipei = taipei_tz.localize(end_time)
    return start_time_taipei, end_time_taipei

def generate_xml_epg(channels, programs):
    # 构建XML结构
    root = ET.Element("tv")
    root.set("info-name", "Hami电视节目表")
    root.set("info-url", "https://hamivideo.hinet.net/")
    
    channel_name_map = {}
    for channel in channels:
        channel_name_map[channel["contentPk"]] = channel["channelName"]
    
    # 按频道顺序处理
    for channel in channels:
        # 使用频道名称作为ID
        channel_id = channel["channelName"]
        
        # 添加频道元素
        channel_elem = ET.SubElement(root, "channel")
        channel_elem.set("id", channel_id)
        
        display_name = ET.SubElement(channel_elem, "display-name")
        display_name.text = channel["channelName"]
        
        channel_programs = [
            p for p in programs 
            if p["channelId"] == channel["contentPk"]
        ]
        
        # 按开始时间排序
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
    
    # 构建XML树
    tree = ET.ElementTree(root)
    return tree

async def main():
    print("开始生成Hami电视节目表...")
    
    # 创建输出目录
    current_dir = os.path.dirname(os.path.abspath(__file__))
    output_dir = os.path.join(current_dir, "epg")
    os.makedirs(output_dir, exist_ok=True)
    
    print(f"输出目录: {output_dir}")
    
    # 获取频道和节目数据
    channels, programs = await request_all_epg()
    
    # 生成XML EPG
    xml_tree = generate_xml_epg(channels, programs)
    output_file = os.path.join(output_dir, "hami.xml")
    
    # 写入XML文件
    xml_tree.write(output_file, encoding="utf-8", xml_declaration=True)
    
    print(f"电视节目表已成功生成: {output_file}")
    print(f"文件大小: {os.path.getsize(output_file) / 1024:.2f} KB")

if __name__ == '__main__':
    asyncio.run(main())
