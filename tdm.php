#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
TDM 澳门广播电视 EPG 采集 + XMLTV 输出
保存到 epg/xmltv.xml
适合 GitHub Actions
API: https://www.tdm.com.mo/api/v1.0/program-list/{date}?channelId={id}
时区 UTC+8 Asia/Macau
"""
import requests
import time
import os
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
from xml.etree.ElementTree import Element, SubElement, ElementTree

HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; LaobaiEPG-GHA/1.0)",
    "Accept": "application/json"
}
BASE_URL = "https://www.tdm.com.mo/api/v1.0/program-list"
MACAU_TZ = ZoneInfo("Asia/Macau")
OUTPUT_DIR = "epg"
OUTPUT_XML = os.path.join(OUTPUT_DIR, "tdm.xml")

def get_channels_tdm():
    return [
        {"id": "1", "name": "澳视澳门", "xml_id": "tdm-1.tdm.com.mo"},
        {"id": "2", "name": "澳视葡文台", "xml_id": "tdm-2.tdm.com.mo"},
        {"id": "3", "name": "澳门资讯", "xml_id": "tdm-3.tdm.com.mo"},
        {"id": "4", "name": "澳门葡文资讯", "xml_id": "tdm-4.tdm.com.mo"},
        {"id": "5", "name": "澳视卫星", "xml_id": "tdm-5.tdm.com.mo"},
        {"id": "6", "name": "澳视体育", "xml_id": "tdm-6.tdm.com.mo"},
    ]

def fetch_with_retry(url, retry=2, timeout=10):
    attempt = 0
    while attempt <= retry:
        try:
            r = requests.get(url, headers=HEADERS, timeout=timeout)
            r.raise_for_status()
            return r.json()
        except Exception as e:
            attempt += 1
            time.sleep(1)
    return None

def get_epg_tdm(channel, date: datetime):
    epgs = []
    date_str = date.strftime("%Y-%m-%d")
    cid = channel["id"]
    url = f"{BASE_URL}/{date_str}?channelId={cid}"

    try:
        json_data = fetch_with_retry(url, retry=2, timeout=10)
        if not json_data or json_data.get("code") != 0 or "data" not in json_data:
            raise Exception(json_data.get("message", "empty response") if json_data else "no data")

        for prog in json_data["data"]:
            title = (prog.get("title") or "").strip()
            dt_str = prog.get("date", "")
            if not title or not dt_str:
                continue
            start = datetime.strptime(dt_str, "%Y-%m-%d %H:%M:%S").replace(tzinfo=MACAU_TZ)
            epgs.append({
                "start": start,
                "stop": None,
                "title": title,
                "desc": ""
            })

        for i in range(len(epgs)-1):
            epgs[i]["stop"] = epgs[i+1]["start"]
        if epgs and epgs[-1]["stop"] is None:
            end_dt = date.replace(hour=23, minute=59, second=59, tzinfo=MACAU_TZ)
            epgs[-1]["stop"] = end_dt

        print(f"[tdm] {channel['name']} ch={cid} {date_str}: {len(epgs)} 条节目")
    except Exception as err:
        print(f"[tdm ERROR] {channel['name']} ch={cid} {date_str}: {str(err)}")
    return epgs

def dt_to_xmltv_str(dt: datetime) -> str:
    """转 xmltv 标准时间格式: YYYYMMDDHHMMSS +0800"""
    return dt.strftime("%Y%m%d%H%M%S +0800")

def build_xmltv(all_epg_data):
    root = Element("tv")
    root.set("source-info-name", "TDM 澳门广播电视")
    root.set("generator-info-name", "LaobaiEPG GitHubAction")

    ch_map = {c["id"]: c for c in get_channels_tdm()}
    # 写入 channel 标签
    for ch in get_channels_tdm():
        ch_elem = SubElement(root, "channel", attrib={"id": ch["xml_id"]})
        SubElement(ch_elem, "display-name").text = ch["name"]

    # 写入 programmes
    for ch_id, prog_list in all_epg_data.items():
        ch = ch_map[ch_id]
        for p in prog_list:
            if not p["start"] or not p["stop"]:
                continue
            prog_elem = SubElement(root, "programme", attrib={
                "channel": ch["xml_id"],
                "start": dt_to_xmltv_str(p["start"]),
                "stop": dt_to_xmltv_str(p["stop"])
            })
            SubElement(prog_elem, "title").text = p["title"]
            if p["desc"].strip():
                SubElement(prog_elem, "desc").text = p["desc"]
    tree = ElementTree(root)
    return tree

def main():
    # ========= 配置采集天数 =========
    collect_days = 1
    today = datetime.now(MACAU_TZ).date()
    channels = get_channels_tdm()
    all_data = {ch["id"]: [] for ch in channels}

    # 自动创建 epg 目录
    if not os.path.exists(OUTPUT_DIR):
        os.makedirs(OUTPUT_DIR)

    for offset in range(collect_days):
        target_date = today + timedelta(days=offset)
        target_dt = datetime.combine(target_date, datetime.min.time(), tzinfo=MACAU_TZ)
        for ch in channels:
            epg_list = get_epg_tdm(ch, target_dt)
            all_data[ch["id"]].extend(epg_list)
            time.sleep(0.6) # 限流，防止被封

    tree = build_xmltv(all_data)
    tree.write(OUTPUT_XML, encoding="utf-8", xml_declaration=True)
    print(f"✅ 已生成 {OUTPUT_XML}")

if __name__ == "__main__":
    main()
