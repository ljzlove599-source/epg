import asyncio
import aiohttp
import gzip
import json
from datetime import datetime, timedelta
from typing import Optional, List, Dict, Any
import random
import os

# ===================== 配置区（和PHP完全对齐） =====================
HISTORY_DAYS = 0
FUTURE_DAYS = 7
MAX_CONCURRENT = 8
BATCH_SIZE = 40
MAX_RETRY_TIMES = 3
REQUEST_DELAY = 0.2
RETRY_DELAY = 0.8
BATCH_INTERVAL = 1.0
OUTPUT_DIR = "./epg"

UA_POOL = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    'Mozilla/5.0 (Android 14; Mobile; rv:126.0) Gecko/126.0 Firefox/126.0',
    'Mozilla/5.0 (Android 14; SM-G998B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36'
]

CHANNELS = {
    'cctv1': 'CCTV-1 综合',
    'cctv2': 'CCTV-2 财经',
    'cctv3': 'CCTV-3 综艺',
    'cctv4': 'CCTV-4 中文国际',
    'cctv5': 'CCTV-5 体育',
    'cctv5plus': 'CCTV-5+ 体育赛事',
    'cctv6': 'CCTV-6 电影',
    'cctv7': 'CCTV-7 国防军事',
    'cctv8': 'CCTV-8 电视剧',
    'cctvjilu': 'CCTV-9 纪录',
    'cctv10': 'CCTV-10 科教',
    'cctv11': 'CCTV-11 戏曲',
    'cctv12': 'CCTV-12 社会与法',
    'cctv13': 'CCTV-13 新闻',
    'cctvchild': 'CCTV-14 少儿',
    'cctv15': 'CCTV-15 音乐',
    'cctv16': 'CCTV-16 奥林匹克',
    'cctv17': 'CCTV-17 农业农村',
    'cctveurope': 'CCTV4欧洲',
    'cctvamerica': 'CCTV4美洲',
    'cctv4k': 'CCTV-4K 超高清',
    'cctv8k': 'CCTV-8K 超高清',
    'shijiedili': '世界地理',
    'dianshigouwu': '中视购物',
    'guofang': '国防军事',
    'taiqiu': '央视台球',
    'jingpin': '央视文化精品',
    'shishang': '女性时尚',
    'hjjc': '怀旧剧场',
    'zhinan': '电视指南',
    'diyijuchang': '第一剧场',
    'fyjc': '风云剧场',
    'cctvyule': 'CCTV娱乐',
    'cctvxiqu': 'CCTV戏曲',
    'cctvfyzq': '风云足球',
    'cctvgaowang': '高尔夫网球',
    'xinkedongman': '新科动漫',
    'cctvqixiang': 'CCTV气象',
    'cctvlaogushi': '老故事',
    'fyyy': '风云音乐',
    'wsjk': '卫生健康',
}

# ===================== 工具函数 =====================
def get_date_list(history_days: int, future_days: int) -> List[str]:
    base = datetime.now()
    dates = []
    for i in range(history_days, 0, -1):
        d = base - timedelta(days=i)
        dates.append(d.strftime("%Y%m%d"))
    dates.append(base.strftime("%Y%m%d"))
    for i in range(1, future_days + 1):
        d = base + timedelta(days=i)
        dates.append(d.strftime("%Y%m%d"))
    return dates

def format_timestamp(ts: int) -> str:
    if ts > 9999999999:
        ts = ts // 1000
    dt = datetime.fromtimestamp(ts)
    return dt.strftime("%Y%m%d%H%M%S") + " +0800"

def format_show_time(date_str: str, time_str: Optional[str]) -> str:
    if not time_str:
        return ""
    time_part = time_str.replace(":", "").strip()[:4]
    if len(time_part) != 4:
        return ""
    return f"{date_str}{time_part}00 +0800"

# ===================== 抓取类 =====================
class EpgFetcher:
    def __init__(self):
        self.cache: Dict[str, List[Dict]] = {}
        self.success_count = 0
        self.fail_count = 0
        self.program_count = 0
        self.sem = asyncio.Semaphore(MAX_CONCURRENT)

    def parse_interface_a(self, data: Dict, channel: str) -> Optional[List[Dict]]:
        plist = data.get(channel, {}).get("program")
        if not isinstance(plist, list):
            return None
        out = []
        for p in plist:
            st = int(p.get("st", 0))
            et = int(p.get("et", 0))
            title = p.get("t", "未知节目")
            if st <= 0 or et <= 0:
                continue
            s = format_timestamp(st)
            e = format_timestamp(et)
            if s > e:
                ts = et // 1000 if et > 9999999999 else et
                dt = datetime.fromtimestamp(ts) + timedelta(days=1)
                e = dt.strftime("%Y%m%d%H%M%S") + " +0800"
            out.append({"title": title, "start": s, "stop": e})
        return out if out else None

    def parse_interface_b(self, data: Dict, channel: str, date: str) -> Optional[List[Dict]]:
        plist = data.get("data", {}).get(channel, {}).get("list")
        if not isinstance(plist, list):
            return None
        temp = []
        for p in plist:
            title = p.get("title", "未知节目")
            st = format_show_time(date, p.get("showTime"))
            if not st:
                continue
            temp.append({"title": title, "start": st, "stop": ""})
        if not temp:
            return None
        temp.sort(key=lambda x: x["start"])
        for i in range(len(temp)-1):
            temp[i]["stop"] = temp[i+1]["start"]
        last = temp[-1]
        try:
            dt = datetime.strptime(last["start"], "%Y%m%d%H%M%S +0800")
            dt += timedelta(hours=2)
            last["stop"] = dt.strftime("%Y%m%d%H%M%S") + " +0800"
        except Exception:
            last["stop"] = last["start"]
        return temp

    async def fetch_one(self, session: aiohttp.ClientSession, channel: str, date: str) -> Optional[List[Dict]]:
        key = f"{channel}_{date}"
        if key in self.cache:
            return self.cache[key]
        headers = {"User-Agent": random.choice(UA_POOL), "Accept": "application/json"}
        url_a = f"http://api.cntv.cn/epg/epginfo?c={channel}&d={date}"
        url_b = f"https://api.cntv.cn/epg/getEpgInfoByChannelNew?c={channel}&serviceId=tvcctv&d={date}&t=json"

        for attempt in range(MAX_RETRY_TIMES+1):
            async with self.sem:
                try:
                    async with session.get(url_a, headers=headers, timeout=aiohttp.ClientTimeout(total=10)) as resp:
                        if resp.status == 200:
                            j = await resp.json()
                            res = self.parse_interface_a(j, channel)
                            if res:
                                self.cache[key] = res
                                self.success_count += 1
                                print(f"✅ {channel} {date} A成功")
                                return res
                except Exception:
                    pass
            await asyncio.sleep(RETRY_DELAY * (attempt+1))

        for attempt in range(MAX_RETRY_TIMES+1):
            async with self.sem:
                try:
                    async with session.get(url_b, headers=headers, timeout=aiohttp.ClientTimeout(total=10)) as resp:
                        if resp.status == 200:
                            j = await resp.json()
                            res = self.parse_interface_b(j, channel, date)
                            if res:
                                self.cache[key] = res
                                self.success_count +=1
                                print(f"✅ {channel} {date} B成功")
                                return res
                except Exception:
                    pass
            await asyncio.sleep(RETRY_DELAY * (attempt+1))
        self.fail_count +=1
        print(f"❌ {channel} {date} 全部重试失败")
        self.cache[key] = None
        return None

    async def fetch_all(self, channel_ids: List[str], date_list: List[str]):
        tasks = []
        for ch in channel_ids:
            for dt in date_list:
                k = f"{ch}_{dt}"
                if k not in self.cache:
                    tasks.append( (ch, dt) )
        total = len(tasks)
        if total ==0:
            print("ℹ️ 全部缓存，无需抓取")
            return
        print(f"📋 待抓取总任务：{total}")
        chunks = [tasks[i:i+BATCH_SIZE] for i in range(0, total, BATCH_SIZE)]
        async with aiohttp.ClientSession() as session:
            for idx, chunk in enumerate(chunks):
                print(f"\n===== 批次 {idx+1}/{len(chunks)} 本批 {len(chunk)} =====")
                coros = [self.fetch_one(session, ch, dt) for ch, dt in chunk]
                await asyncio.gather(*coros)
                await asyncio.sleep(BATCH_INTERVAL)

    def build_xml(self, channel_ids: List[str], date_list: List[str]) -> str:
        lines = ['<?xml version="1.0" encoding="UTF-8"?>',
                 '<tv generator-info-name="Final-Merge-CCTV-EPG-Generator" generator-info-url="https://api.cntv.cn">']
        for cid, cname in CHANNELS.items():
            lines.append(f'  <channel id="{cid}">')
            lines.append(f'    <display-name>{cname}</display-name>')
            lines.append('  </channel>')
        prog_cnt = 0
        for ch in channel_ids:
            for dt in date_list:
                k = f"{ch}_{dt}"
                plist = self.cache.get(k)
                if not plist:
                    continue
                for p in plist:
                    lines.append(f'  <programme start="{p["start"]}" stop="{p["stop"]}" channel="{ch}">')
                    lines.append(f'    <title>{p["title"]}</title>')
                    lines.append('  </programme>')
                    prog_cnt +=1
        self.program_count = prog_cnt
        lines.append("</tv>")
        return "\n".join(lines)

    def save_gz(self, gzpath: str, channel_ids: List[str], date_list: List[str]):
        xml = self.build_xml(channel_ids, date_list)
        with gzip.open(gzpath, 'wt', encoding="utf-8") as f:
            f.write(xml)

async def main():
    start = datetime.now()
    date_list = get_date_list(HISTORY_DAYS, FUTURE_DAYS)
    channel_ids = list(CHANNELS.keys())
    print(f"📅 日期：{len(date_list)}天，📺频道：{len(channel_ids)}")
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    fetcher = EpgFetcher()
    await fetcher.fetch_all(channel_ids, date_list)
    gz_path = os.path.join(OUTPUT_DIR, "cntv.xml.gz")
    fetcher.save_gz(gz_path, channel_ids, date_list)
    stat = os.stat(gz_path)
    print("\n==== 统计 ====")
    print(f"✅成功：{fetcher.success_count}")
    print(f"❌失败：{fetcher.fail_count}")
    print(f"📺节目：{fetcher.program_count}")
    print(f"📁文件：{gz_path} {stat.st_size / 1024:.1f} KB")
    print(f"⏱耗时：{(datetime.now()-start).total_seconds():.2f}s")
    if fetcher.program_count == 0:
        os.unlink(gz_path)
        print("⚠️无节目，删除文件")
        return
    print("🎉完成，输出 cntv.xml.gz")

if __name__ == "__main__":
    asyncio.run(main())

    main()
