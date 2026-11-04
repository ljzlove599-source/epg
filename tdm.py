#!/usr/bin/env python3
import os
import requests
from datetime import datetime, timedelta, timezone
from concurrent.futures import ThreadPoolExecutor, as_completed
from opencc import OpenCC

# 频道配置（与原Elixir版本完全一致）
CHANNELS = [
    {"id": 1, "xml_id": "tdm.oumun", "name_zh": "澳視澳門", "name_en": "TDM Ou Mun"},
    {"id": 2, "xml_id": "tdm.canal", "name_zh": "澳視葡文", "name_en": "Canal Macau"},
    {"id": 5, "xml_id": "tdm.info", "name_zh": "澳門資訊", "name_en": "TDM Informação"},
    {"id": 6, "xml_id": "tdm.sport", "name_zh": "澳視體育", "name_en": "TDM Desporto"},
    {"id": 7, "xml_id": "tdm.variety", "name_zh": "澳門綜藝", "name_en": "TDM Entretenimento"},
    {"id": 8, "xml_id": "tdm.macau", "name_zh": "澳門-Macau", "name_en": "Macau Satellite"},
]

# ========== 代理配置（境内访问需开启，取消注释并修改为你的代理地址） ==========
# PROXIES = {
#     "http": "http://127.0.0.1:7890",
#     "https": "http://127.0.0.1:7890"
# }
PROXIES = None


def escape_xml(s: str) -> str:
    """XML特殊字符转义，与原Elixir版本逻辑完全对齐"""
    if not s:
        return ""
    s = s.replace("&", "&amp;")
    s = s.replace("<", "&lt;")
    s = s.replace(">", "&gt;")
    s = s.replace("\"", "&quot;")
    return s


def format_epg_time(date_str: str) -> str:
    """将 "YYYY-MM-DD HH:MM:SS" 转为 XMLTV 标准格式 "YYYYMMDDHHMMSS +0800" """
    dt = datetime.strptime(date_str, "%Y-%m-%d %H:%M:%S")
    return dt.strftime("%Y%m%d%H%M%S") + " +0800"


def format_epg_time_plus_one_hour(date_str: str) -> str:
    """最后一个节目默认延长1小时，输出XMLTV时间格式"""
    dt = datetime.strptime(date_str, "%Y-%m-%d %H:%M:%S")
    dt += timedelta(hours=1)
    return dt.strftime("%Y%m%d%H%M%S") + " +0800"


def fetch_program(ch: dict, date_str: str) -> tuple:
    """抓取单个频道单日的节目单，失败返回空列表"""
    url = f"https://apps.tdm.com.mo/api/v1.0/program-list/{date_str}?type=tv&channelId={ch['id']}&date={date_str}"
    try:
        resp = requests.get(url, timeout=10, proxies=PROXIES)
        if resp.status_code == 200:
            data = resp.json()
            if isinstance(data.get("data"), list):
                return ch, data["data"]
    except Exception as e:
        print(f"[警告] {ch['name_zh']} {date_str} 请求失败: {str(e)}")
    return ch, []


def render_programme(xml_id: str, prog: dict, start_fmt: str, stop_fmt: str, cc: OpenCC) -> str:
    """渲染单条节目XML，自动繁转简"""
    title = escape_xml(cc.convert(prog.get("title", "")))
    lang = "zh-hans"  # 转简体后统一为简体中文标识
    slug = prog.get("slug", "")
    desc_tag = ""
    if slug and slug.strip():
        desc = escape_xml(cc.convert(slug))
        desc_tag = f"\n    <desc lang=\"{lang}\">{desc}</desc>"

    return f"""  <programme start="{start_fmt}" stop="{stop_fmt}" channel="{xml_id}">
    <title lang="{lang}">{title}</title>{desc_tag}
  </programme>"""


def build_channel_programmes(xml_id: str, programs: list, cc: OpenCC) -> str:
    """生成单个频道的所有节目XML，相邻节目首尾时间衔接，与原版逻辑一致"""
    if not programs:
        return ""

    programme_list = []
    # 非最后一个节目：结束时间 = 下一个节目的开始时间
    for i in range(len(programs) - 1):
        curr = programs[i]
        next_prog = programs[i + 1]
        start_fmt = format_epg_time(curr["date"])
        stop_fmt = format_epg_time(next_prog["date"])
        programme_list.append(render_programme(xml_id, curr, start_fmt, stop_fmt, cc))

    # 最后一个节目：默认延长1小时
    last_prog = programs[-1]
    last_start = format_epg_time(last_prog["date"])
    last_stop = format_epg_time_plus_one_hour(last_prog["date"])
    programme_list.append(render_programme(xml_id, last_prog, last_start, last_stop, cc))

    return "\n".join(programme_list)


def main():
    # 初始化 OpenCC 繁体转简体
    cc = OpenCC("t2s")

    # 计算澳门时间（UTC+8）的日期范围：昨天 ~ 7天后，与原版逻辑一致
    utc_now = datetime.now(timezone.utc)
    macau_now = utc_now + timedelta(hours=8)
    today = macau_now.date()
    start_date = today - timedelta(days=1)
    end_date = today + timedelta(days=7)
    date_range = [start_date + timedelta(days=x) for x in range((end_date - start_date).days + 1)]

    print(f"正在抓取日期范围: {start_date.isoformat()} 至 {end_date.isoformat()}")

    # 8线程并发抓取所有频道所有日期的节目
    raw_data = []
    with ThreadPoolExecutor(max_workers=8) as executor:
        futures = []
        for ch in CHANNELS:
            for date in date_range:
                date_str = date.strftime("%Y-%m-%d")
                futures.append(executor.submit(fetch_program, ch, date_str))

        for future in as_completed(futures, timeout=15):
            try:
                ch, progs = future.result()
                raw_data.append((ch, progs))
            except Exception as e:
                print(f"[警告] 任务执行异常: {str(e)}")

    # 生成频道信息XML（频道名自动繁转简）
    channels_xml = []
    for ch in CHANNELS:
        name_zh_s = cc.convert(ch["name_zh"])
        channel_item = f"""  <channel id="{ch['xml_id']}">
    <display-name lang="zh">{name_zh_s}</display-name>
    <display-name lang="en">{ch['name_en']}</display-name>
  </channel>"""
        channels_xml.append(channel_item)
    channels_xml_str = "\n".join(channels_xml)

    # 生成节目列表XML
    programmes_xml_list = []
    for ch in CHANNELS:
        # 聚合该频道所有日期的节目
        ch_all_progs = []
        for c, progs in raw_data:
            if c["id"] == ch["id"]:
                ch_all_progs.extend(progs)

        # 按「日期+标题」去重，与原版 uniq_by 逻辑一致
        seen = set()
        unique_progs = []
        for prog in ch_all_progs:
            key = (prog.get("date"), prog.get("title"))
            if key not in seen:
                seen.add(key)
                unique_progs.append(prog)

        # 按时间升序排序
        unique_progs.sort(key=lambda x: x.get("date", ""))

        # 生成该频道完整节目XML
        prog_xml = build_channel_programmes(ch["xml_id"], unique_progs, cc)
        programmes_xml_list.append(prog_xml)

    programmes_xml_str = "\n".join(programmes_xml_list)

    # 组装标准 XMLTV 文件
    xml_content = f"""<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE tv SYSTEM "xmltv.dtd">
<tv generator-info-name="TDM-EPG-Python-Generator">
{channels_xml_str}
{programmes_xml_str}
</tv>
"""

    # 自动创建输出目录，写入 epg/tdm.xml
    output_dir = "epg"
    os.makedirs(output_dir, exist_ok=True)
    output_path = os.path.join(output_dir, "tdm.xml")

    with open(output_path, "w", encoding="utf-8") as f:
        f.write(xml_content)

    file_size = os.path.getsize(output_path)
    print(f"成功生成: {output_path} (大小: {file_size} 字节)")


if __name__ == "__main__":
    main()

