import os
import sys
import json
import time
import random
import argparse
import requests
import datetime
import pytz
from bs4 import BeautifulSoup
from xml.etree import ElementTree as ET
from xml.dom import minidom

# 全局时区设置
TAIPEI_TZ = pytz.timezone('Asia/Taipei')
HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
}

# 初始化繁体转简体转换器（带容错降级）
try:
    from opencc import OpenCC
    cc = OpenCC('t2s')
    def t2s(text):
        """繁体转简体统一入口，自动处理空值"""
        if not text:
            return text
        return cc.convert(str(text))
    print("✅ 繁简转换模块已加载")
except ImportError:
    print("⚠️ 未检测到 opencc 库，将跳过繁简转换，直接输出原文")
    def t2s(text):
        return text


def human_like_delay(min_seconds=1, max_seconds=5):
    """人类仿真延迟，降低反爬拦截风险"""
    delay = random.uniform(min_seconds, max_seconds)
    print(f"⏱️ 随机延迟 {delay:.2f}秒")
    time.sleep(delay)


def human_like_typing_effect(text, delay=0.03):
    """人类仿真打字效果"""
    for char in text:
        print(char, end='', flush=True)
        time.sleep(delay)
    print()


def parse_channel_list():
    """解析频道清单（硬编码基础频道 + 批量生成 ofiii 频道）"""
    other_channels = [
        "4gtv-4gtv009", "4gtv-4gtv040", "4gtv-4gtv041", "4gtv-4gtv052",
        "4gtv-4gtv074", "4gtv-4gtv084", "4gtv-4gtv085", "4gtv-4gtv076",
        "4gtv-4gtv102", "4gtv-4gtv103", "4gtv-4gtv104", "4gtv-4gtv156",
        "4gtv-4gtv158", "litv-ftv16", "litv-ftv17", "litv-xinchuang01",
        "litv-xinchuang02", "litv-xinchuang03", "litv-xinchuang11",
        "litv-xinchuang12", "litv-longturn14", "litv-xinchuang18",
        "litv-xinchuang19", "litv-xinchuang20", "litv-xinchuang21",
        "litv-xinchuang22", "iNEWS", "daystar", "setnews"
    ]
    ofiii_channels = [f"ofiii{i}" for i in range(13, 256)]
    channel_list = other_channels + ofiii_channels
    print(f"📡 总共 {len(channel_list)} 个频道")
    print(f"   - 非ofiii频道: {len(other_channels)} 个")
    print(f"   - ofiii频道: {len(ofiii_channels)} 个 (ofiii13~ofiii255)")
    return channel_list


def fetch_epg_data(channel_id, max_retries=1):
    """获取指定频道节目表，解析页面 __NEXT_DATA__ JSON"""
    url = f"https://www.ofiii.com/channel/watch/{channel_id}"
    for attempt in range(max_retries):
        try:
            if attempt == 0:
                human_like_delay(0.5, 1.5)
            print(f"   🔍 尝试 {attempt+1}/{max_retries}: 获取 {channel_id}")
            response = requests.get(url, headers=HEADERS, timeout=30)
            response.raise_for_status()
            if not response.text.strip():
                print(f"   ⚠️ 响应内容为空: {channel_id}")
                return None
            soup = BeautifulSoup(response.text, 'html.parser')
            script_tag = soup.find('script', id='__NEXT_DATA__')
            if script_tag and script_tag.string:
                try:
                    data = json.loads(script_tag.string)
                    print(f"   ✅ 成功获取 {channel_id} 的数据")
                    return data
                except json.JSONDecodeError as e:
                    print(f"   ⚠️ JSON解析失败: {channel_id}, {str(e)}")
                    return None
            else:
                print(f"   ⚠️ 未找到__NEXT_DATA__标签: {channel_id}")
                return None
        except requests.RequestException as e:
            wait_time = random.uniform(1, 3) * (attempt + 1)
            print(f"   ⚠️ 请求失败 (尝试 {attempt+1}/{max_retries}), 等待 {wait_time:.2f}秒: {str(e)}")
            time.sleep(wait_time)
    print(f"   ❌ 无法获取电视节目表数据: {channel_id}")
    return None


def parse_timestamp(ts):
    """自动识别秒/毫秒时间戳，统一返回秒级浮点数"""
    if ts > 9999999999:  # 大于10位判定为毫秒
        return ts / 1000.0
    return float(ts)


def parse_schedule_data(json_data, channel_id):
    """解析直播频道 schedule 数据"""
    if not json_data:
        return []
    programs = []
    try:
        channel_data = json_data['props']['pageProps']['channel']
        schedule = channel_data.get('schedule', [])
        channel_name = t2s(channel_data.get('title', channel_id))
        for item in schedule:
            try:
                start_ts = item.get('startTime')
                end_ts = item.get('endTime')
                if not start_ts or not end_ts:
                    continue
                start_taipei = datetime.datetime.fromtimestamp(start_ts, TAIPEI_TZ)
                end_taipei = datetime.datetime.fromtimestamp(end_ts, TAIPEI_TZ)
                programs.append({
                    "channelId": channel_id,
                    "channelName": channel_name,
                    "programName": t2s(item.get('title', '未知节目')),
                    "description": t2s(item.get('description', '')),
                    "subtitle": t2s(item.get('subtitle', '')),
                    "start": start_taipei,
                    "end": end_taipei
                })
            except (KeyError, ValueError, TypeError) as e:
                print(f"   ⚠️ 跳过无效节目数据: {channel_id}, {str(e)}")
                continue
    except Exception as e:
        print(f"   ❌ 解析直播节目表失败: {str(e)}")
    return programs


def parse_playout_schedule_data(json_data, channel_id):
    """解析固定排程点播频道（节目自带精确起止时间戳）"""
    if not json_data:
        return []
    programs = []
    try:
        channel_data = json_data['props']['pageProps']['channel']
        vod_schedule = channel_data.get('vodChannelSchedule')
        if not vod_schedule:
            print(f"   ⚠️ 排程点播频道 {channel_id} 没有 vodChannelSchedule")
            return []
        vod_programs = vod_schedule.get('programs', [])
        if not vod_programs:
            return []
        channel_name = t2s(channel_data.get('title', channel_id))
        for item in vod_programs:
            try:
                start_ts = parse_timestamp(item.get('startTime', 0))
                end_ts = parse_timestamp(item.get('endTime', 0))
                if start_ts == 0 or end_ts == 0:
                    continue
                start_taipei = datetime.datetime.fromtimestamp(start_ts, TAIPEI_TZ)
                end_taipei = datetime.datetime.fromtimestamp(end_ts, TAIPEI_TZ)
                programs.append({
                    "channelId": channel_id,
                    "channelName": channel_name,
                    "programName": t2s(item.get('title', '未知节目')),
                    "description": t2s(item.get('description', '')),
                    "subtitle": t2s(item.get('subtitle', '')),
                    "start": start_taipei,
                    "end": end_taipei
                })
            except (KeyError, ValueError, TypeError) as e:
                print(f"   ⚠️ 跳过无效节目数据: {channel_id}, {str(e)}")
                continue
    except Exception as e:
        print(f"   ❌ 解析排程点播节目表失败: {str(e)}")
    return programs


def parse_vod_schedule_data(json_data, channel_id):
    """解析动态轮播点播频道（根据当前播放位置推算全量节目时间）"""
    if not json_data:
        return []
    programs = []
    try:
        channel_data = json_data['props']['pageProps']['channel']
        vod_schedule = channel_data.get('vodChannelSchedule')
        if not vod_schedule:
            print(f"   ⚠️ 动态点播频道 {channel_id} 没有 vodChannelSchedule")
            return []
        vod_programs = vod_schedule.get('programs', [])
        if not vod_programs:
            return []
        focus_index = vod_schedule.get('focusIndex', 0)
        elapsed_ms = vod_schedule.get('time', 0)
        if focus_index < 0 or focus_index >= len(vod_programs):
            focus_index = 0
        channel_name = t2s(channel_data.get('title', channel_id))
        now = datetime.datetime.now(TAIPEI_TZ)
        current_prog = vod_programs[focus_index]
        length_sec = current_prog.get('length', 0)
        start_current = now - datetime.timedelta(milliseconds=elapsed_ms)
        end_current = start_current + datetime.timedelta(seconds=length_sec)
        
        time_map = {}
        time_map[focus_index] = (start_current, end_current)
        # 向前推算历史节目时间
        prev_end = start_current
        for i in range(focus_index - 1, -1, -1):
            l_sec = vod_programs[i].get('length', 0)
            start_time = prev_end - datetime.timedelta(seconds=l_sec)
            time_map[i] = (start_time, prev_end)
            prev_end = start_time
        # 向后推算未来节目时间
        next_start = end_current
        for i in range(focus_index + 1, len(vod_programs)):
            l_sec = vod_programs[i].get('length', 0)
            end_time = next_start + datetime.timedelta(seconds=l_sec)
            time_map[i] = (next_start, end_time)
            next_start = end_time
        
        for i, item in enumerate(vod_programs):
            if i in time_map:
                start, end = time_map[i]
                programs.append({
                    "channelId": channel_id,
                    "channelName": channel_name,
                    "programName": t2s(item.get('title', '未知节目')),
                    "description": t2s(item.get('description', '')),
                    "subtitle": t2s(item.get('subtitle', '')),
                    "start": start,
                    "end": end
                })
    except Exception as e:
        print(f"   ❌ 解析动态点播节目表失败: {str(e)}")
    return programs


def parse_epg_data(json_data, channel_id):
    """自动识别频道类型，分派对应解析器"""
    if not json_data:
        return []
    try:
        channel_data = json_data['props']['pageProps']['channel']
        # 优先判定直播频道
        if 'schedule' in channel_data:
            print(f"   📺 检测到直播频道: {channel_id}")
            return parse_schedule_data(json_data, channel_id)
        # 判定点播频道
        vod_schedule = channel_data.get('vodChannelSchedule')
        if vod_schedule:
            programs = vod_schedule.get('programs', [])
            if programs and programs[0].get('startTime', 0) > 0:
                print(f"   🗓️ 检测到排程点播频道: {channel_id}")
                return parse_playout_schedule_data(json_data, channel_id)
            else:
                print(f"   📹 检测到动态点播频道: {channel_id}")
                return parse_vod_schedule_data(json_data, channel_id)
        else:
            print(f"   ⚠️ 频道 {channel_id} 无 schedule 或 vodChannelSchedule")
            return []
    except Exception as e:
        print(f"   ❌ 判断频道类型失败: {str(e)}")
        return []


def get_channel_info(json_data, channel_id):
    """提取频道基础信息，兼容新旧logo格式"""
    if not json_data:
        return None
    try:
        page_props = json_data.get('props', {}).get('pageProps', {})
        channel_data = page_props.get('channel', {})
        introduction = page_props.get('introduction') or {}
        channel_name = t2s(channel_data.get('title', channel_id))

        logo = channel_data.get('picture', '')
        if logo and not logo.startswith('http'):
            logo = f"https://p-cdnstatic.svc.litv.tv/{logo}"
            if '_tv' in logo:
                logo = logo.replace('_tv', '_mobile')

        description = t2s(introduction.get('description', ''))
        return {
            "channelName": channel_name,
            "id": channel_id,
            "logo": logo,
            "description": description
        }
    except Exception as e:
        print(f"   ❌ 提取频道信息失败: {channel_id}, {str(e)}")
        return None


def get_ofiii_epg():
    """主流程：批量抓取所有频道数据"""
    print("=" * 50)
    human_like_typing_effect("开始获取欧飞电视节目表")
    print("=" * 50)
    channels = parse_channel_list()
    if not channels:
        print("❌ 无法解析频道清单")
        return [], []
    
    all_channels_info = []
    all_programs = []
    failed_channels = []
    
    for idx, channel_id in enumerate(channels):
        print(f"\n📡 处理频道 [{idx+1}/{len(channels)}]: {channel_id}")
        json_data = fetch_epg_data(channel_id)
        if not json_data:
            failed_channels.append(channel_id)
            continue
        
        channel_info = get_channel_info(json_data, channel_id)
        if channel_info:
            all_channels_info.append(channel_info)
            print(f"   ✅ 成功提取频道信息: {channel_info['channelName']}")
        else:
            print(f"   ⚠️ 无法提取频道信息: {channel_id}")
        
        programs = parse_epg_data(json_data, channel_id)
        all_programs.extend(programs)
        print(f"   📺 解析到 {len(programs)} 个节目")
        
        if idx < len(channels) - 1:
            human_like_delay(1, 3)
    
    # 输出统计
    print("\n" + "=" * 50)
    human_like_typing_effect("数据获取完成，生成统计信息...")
    print(f"✅ 成功获取 {len(all_channels_info)} 个频道信息")
    print(f"✅ 成功获取 {len(all_programs)} 个节目")
    if failed_channels:
        print(f"⚠️ 失败频道 ({len(failed_channels)}): {', '.join(failed_channels[:10])}{'...' if len(failed_channels) > 10 else ''}")
    
    channel_counts = {}
    for program in all_programs:
        cn = program["channelName"]
        channel_counts[cn] = channel_counts.get(cn, 0) + 1
    print("\n📊 各频道节目统计:")
    for channel, count in list(channel_counts.items())[:10]:
        print(f"   📺 {channel}: {count} 个节目")
    if len(channel_counts) > 10:
        print(f"   ... 还有 {len(channel_counts) - 10} 个频道")
    print("=" * 50)
    return all_channels_info, all_programs


def generate_xmltv(channels_info, programs, output_file="ofiii.xml"):
    """生成标准 XMLTV 格式 EPG 文件"""
    print(f"\n📄 生成XMLTV文件: {output_file}")
    human_like_typing_effect("正在生成XML格式的节目表数据...")
    
    root = ET.Element("tv", generator="OFIII-EPG-Generator", source="www.ofiii.com")
    programs_by_channel = {}
    for program in programs:
        cn = program['channelName']
        programs_by_channel.setdefault(cn, []).append(program)
    
    sorted_channel_names = sorted(programs_by_channel.keys())
    program_count = 0
    channel_count = 0
    
    for channel_name in sorted_channel_names:
        channel_info = next((info for info in channels_info if info['channelName'] == channel_name), None)
        if not channel_info:
            continue
        # 写入频道信息
        channel_elem = ET.SubElement(root, "channel", id=channel_name)
        ET.SubElement(channel_elem, "display-name", lang="zh").text = channel_name
        if channel_info.get('logo'):
            ET.SubElement(channel_elem, "icon", src=channel_info['logo'])
        if channel_info.get('description'):
            ET.SubElement(channel_elem, "desc", lang="zh").text = channel_info['description']
        channel_count += 1
        
        # 写入节目列表
        channel_programs = sorted(programs_by_channel[channel_name], key=lambda x: x['start'])
        for prog in channel_programs:
            try:
                start_str = prog['start'].strftime('%Y%m%d%H%M%S %z')
                end_str = prog['end'].strftime('%Y%m%d%H%M%S %z')
                prog_elem = ET.SubElement(root, "programme", channel=channel_name, start=start_str, stop=end_str)
                ET.SubElement(prog_elem, "title", lang="zh").text = prog.get('programName', '未知节目')
                if prog.get('subtitle'):
                    ET.SubElement(prog_elem, "sub-title", lang="zh").text = prog['subtitle']
                if prog.get('description'):
                    ET.SubElement(prog_elem, "desc", lang="zh").text = prog['description']
                program_count += 1
            except Exception as e:
                print(f"⚠️ 跳过无效的节目数据: {str(e)}")
                continue
    
    # 格式化并保存
    xml_str = ET.tostring(root, encoding='utf-8').decode('utf-8')
    try:
        parsed = minidom.parseString(xml_str)
        pretty_xml = parsed.toprettyxml(indent="  ", encoding='utf-8')
    except Exception as e:
        print(f"⚠️ XML美化失败, 使用原始XML: {str(e)}")
        pretty_xml = xml_str.encode('utf-8')
    
    try:
        with open(output_file, 'wb') as f:
            f.write(pretty_xml)
        print(f"✅ XMLTV文件已生成: {output_file}")
        print(f"📺 频道数: {channel_count}")
        print(f"📺 节目数: {program_count}")
        print(f"💾 文件大小: {os.path.getsize(output_file) / 1024:.2f} KB")
        return True
    except Exception as e:
        print(f"❌ 保存XML文件失败: {str(e)}")
        return False


def generate_json_file(channels_info, output_file="ofiii.json"):
    """生成 JSON 格式频道信息备份"""
    print(f"\n📄 生成JSON文件: {output_file}")
    human_like_typing_effect("正在生成JSON格式的频道数据...")
    try:
        with open(output_file, 'w', encoding='utf-8') as f:
            json.dump(channels_info, f, ensure_ascii=False, indent=2)
        print(f"✅ JSON文件已生成: {output_file}")
        print(f"📺 频道数: {len(channels_info)}")
        print(f"💾 文件大小: {os.path.getsize(output_file) / 1024:.2f} KB")
        return True
    except Exception as e:
        print(f"❌ 保存JSON文件失败: {str(e)}")
        return False


def main():
    parser = argparse.ArgumentParser(description='欧飞电视节目表抓取工具')
    parser.add_argument('--output', type=str, default='output/ofiii.xml',
                        help='输出XML文件路径 (默认: output/ofiii.xml)')
    args = parser.parse_args()
    
    output_dir = os.path.dirname(args.output)
    if output_dir and not os.path.exists(output_dir):
        os.makedirs(output_dir, exist_ok=True)
        print(f"📁 创建输出目录: {output_dir}")
    
    try:
        channels_info, programs = get_ofiii_epg()
        if not channels_info:
            print("❌ 未获取到有效频道信息，无法生成文件")
            sys.exit(1)
        
        if not generate_xmltv(channels_info, programs, args.output):
            sys.exit(1)
        
        json_output = os.path.join(output_dir, "ofiii.json")
        if not generate_json_file(channels_info, json_output):
            print("⚠️ JSON文件生成失败，但XML已成功生成")
        
        print("\n🎉 所有操作完成！")
    except Exception as e:
        print(f"❌ 主程序错误: {str(e)}")
        import traceback
        traceback.print_exc()
        sys.exit(1)


if __name__ == "__main__":
    main()
