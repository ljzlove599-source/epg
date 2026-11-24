import sqlite3
import datetime
import os
import asyncio
import httpx
import zipfile
import io
import logging
import json
import gzip
import zlib
import xml.etree.ElementTree as ET
from xml.dom import minidom
from typing import List, Dict, Optional, Tuple
import time
from pathlib import Path
import sys
import tempfile
import shutil
import re
from abc import ABC, abstractmethod
from opencc import OpenCC

# ===================== 配置区 =====================
class Config:
    # 基础配置
    DAYS: int = 7                          # 获取节目天数
    OUTPUT_DIR: str = "epg_sources"                # 输出目录
    OUTPUT_FILE: str = "litv_epg.xml"      # 输出文件名
    DB_PATH: str = "litv_epg.db"           # SQLite 数据库本地路径
    
    # 数据源开关
    ENABLE_WEB_SOURCE: bool = True         # 启用Web主源（轻量、简介质量高）
    ENABLE_SQLITE_SOURCE: bool = True      # 启用SQLite备源（全量、兜底）
    PRIMARY_SOURCE: str = "web"            # 主源：web / sqlite
    
    # 数据处理配置
    ENABLE_T2S: bool = True                # 启用繁转简
    ENABLE_DESC_ENRICH: bool = True        # 启用跨源简介补全
    MIN_DESC_LENGTH: int = 12              # 有效简介最小长度
    CACHE_TTL_SEC: int = 1800              # 内存缓存有效期（30分钟）
    
    # 容灾阈值
    WEB_MIN_CHANNELS: int = 80             # Web源频道数低于该值则降级
    WEB_MIN_PROGRAMS: int = 5000           # Web源节目数低于该值则降级

# ===================== 日志初始化 =====================
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s",
    handlers=[logging.StreamHandler()]
)
logger = logging.getLogger("LiTV-EPG")

# ===================== 工具函数层 =====================
class TextUtils:
    """文本处理工具：归一化、繁转简、有效性判断"""
    _cc = None
    
    @classmethod
    def get_cc(cls) -> OpenCC:
        if cls._cc is None:
            cls._cc = OpenCC('t2s')
        return cls._cc
    
    @classmethod
    def t2s(cls, text: Optional[str]) -> str:
        if not text:
            return ""
        try:
            return cls.get_cc().convert(text.strip())
        except Exception as e:
            logger.warning(f"繁简转换失败: {e}")
            return text.strip()
    
    @staticmethod
    def normalize_title(title: str) -> str:
        """标题归一化：去除特殊符号、空白、统一格式，用于跨源匹配"""
        if not title:
            return ""
        # 转简体
        title = TextUtils.t2s(title)
        # 去除所有空白字符
        title = re.sub(r'\s+', '', title)
        # 去除常见特殊符号
        title = re.sub(r'[^\w\u4e00-\u9fa5]', '', title)
        # 统一集数格式（可选）
        title = re.sub(r'第(\d+)集', r'\1集', title)
        return title.lower()
    
    @staticmethod
    def is_valid_desc(desc: str, title: str) -> bool:
        """判断是否为有效简介：非空、长度达标、不复读标题"""
        if not desc or not desc.strip():
            return False
        desc = desc.strip()
        if len(desc) < Config.MIN_DESC_LENGTH:
            return False
        norm_desc = TextUtils.normalize_title(desc)
        norm_title = TextUtils.normalize_title(title)
        if norm_desc == norm_title:
            return False
        return True

class TimeUtils:
    """时间处理工具：统一时区、格式转换"""
    SHANGHAI_TZ = datetime.timezone(datetime.timedelta(hours=8))
    
    @staticmethod
    def timestamp_ms_to_dt(ts_ms: int) -> datetime.datetime:
        """毫秒时间戳转上海时区datetime"""
        utc_dt = datetime.datetime.utcfromtimestamp(ts_ms / 1000)
        return utc_dt.replace(tzinfo=datetime.timezone.utc).astimezone(TimeUtils.SHANGHAI_TZ)
    
    @staticmethod
    def utc_str_to_dt(utc_str: str) -> datetime.datetime:
        """UTC时间字符串转上海时区datetime"""
        try:
            # 兼容带Z和不带Z的格式
            utc_str = utc_str.replace('Z', '+00:00')
            utc_dt = datetime.datetime.fromisoformat(utc_str)
            return utc_dt.astimezone(TimeUtils.SHANGHAI_TZ)
        except Exception as e:
            logger.warning(f"时间字符串解析失败: {utc_str}, {e}")
            return None
    
    @staticmethod
    def dt_to_xmltv_str(dt: datetime.datetime) -> str:
        """datetime转XMLTV标准时间格式"""
        return dt.strftime('%Y%m%d%H%M%S +0800')

# ===================== 数据源抽象层 =====================
class BaseEPGSource(ABC):
    """数据源抽象基类，所有数据源必须实现此接口"""
    
    @abstractmethod
    async def fetch_channels(self) -> List[Dict]:
        """获取频道列表，统一返回格式：
        {
            'id': str,          # cdn编码，唯一主键
            'name': str,        # 频道原始名称
            'station_id': str,  # 台站ID
            'icon': str,        # 图标URL
            'source': str       # 来源标记
        }
        """
        pass
    
    @abstractmethod
    async def fetch_programs(self, channels: List[Dict], days: int) -> Dict[str, List[Dict]]:
        """获取所有频道的节目表，返回 {channel_id: [program_list]}
        program格式：
        {
            'starttime': datetime,
            'endtime': datetime,
            'title': str,
            'desc': str,
            'norm_title': str   # 归一化标题，用于匹配
        }
        """
        pass
    
    @abstractmethod
    async def is_available(self) -> bool:
        """检查数据源是否可用"""
        pass

# ===================== 数据源1：Web-Zlib 源（主源，对应Node版逻辑） =====================
class WebZlibSource(BaseEPGSource):
    BASE_URL = "https://fino.svc.litv.tv"
    SCHEDULE_ALL = f"{BASE_URL}/litvpc/ScheduleAllWithMediumPrograms.json.zlib"
    HEADERS = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36",
        "Referer": "https://www.litv.tv/",
        "Accept": "application/json, text/plain, */*"
    }
    
    def __init__(self):
        self._cache = None
        self._cache_time = 0
    
    async def _fetch_zlib_json(self, url: str) -> Optional[Dict]:
        """下载并解压zlib/gzip格式的JSON数据"""
        try:
            async with httpx.AsyncClient(timeout=60.0, follow_redirects=True) as client:
                response = await client.get(url, headers=self.HEADERS)
                response.raise_for_status()
                buf = response.content
            
            if not buf or len(buf) < 2:
                logger.error("Web源返回空数据")
                return None
            
            # 判断是gzip还是deflate
            if buf[0] == 0x1f and buf[1] == 0x8b:
                decompressed = gzip.decompress(buf)
            else:
                decompressed = zlib.decompress(buf)
            
            return json.loads(decompressed.decode('utf-8'))
        except Exception as e:
            logger.error(f"Web源下载解析失败: {e}")
            return None
    
    async def _get_data(self) -> Optional[Dict]:
        """带内存缓存的数据获取"""
        now = time.time()
        if self._cache and now - self._cache_time < Config.CACHE_TTL_SEC:
            logger.debug("命中Web源内存缓存")
            return self._cache
        
        logger.info("正在下载Web源节目包...")
        data = await self._fetch_zlib_json(self.SCHEDULE_ALL)
        if data and "Channels" in data and "Programs" in data:
            self._cache = data
            self._cache_time = now
            logger.info(f"Web源加载成功，共 {len(data['Channels'])} 个频道")
            return data
        return None
    
    async def is_available(self) -> bool:
        data = await self._get_data()
        return data is not None and len(data.get("Channels", [])) >= Config.WEB_MIN_CHANNELS
    
    async def fetch_channels(self) -> List[Dict]:
        data = await self._get_data()
        if not data:
            return []
        
        channels = []
        for ch in data.get("Channels", []):
            cdn_code = ch.get("CdnCode", "")
            name = ch.get("Name", "").strip()
            if not cdn_code or not name:
                continue
            channels.append({
                "id": cdn_code,
                "name": name,
                "station_id": ch.get("StationId", ""),
                "icon": "",  # Web源未直接提供图标，可从SQLite源补充
                "source": "web"
            })
        return channels
    
    async def fetch_programs(self, channels: List[Dict], days: int) -> Dict[str, List[Dict]]:
        data = await self._get_data()
        if not data:
            return {}
        
        programs_map = data.get("Programs", {})
        now_ts = time.time() * 1000
        end_ts = now_ts + days * 86400 * 1000
        
        result = {}
        channel_id_map = {ch["id"]: ch for ch in channels}
        
        for ch in data.get("Channels", []):
            cdn_code = ch.get("CdnCode", "")
            if cdn_code not in channel_id_map:
                continue
            
            schedule = ch.get("Schedule", [])
            prog_list = []
            for p in schedule:
                try:
                    air_time = p.get("AirDateTime", "")
                    duration = int(p.get("Duration", 0))
                    program_id = p.get("ProgramId", "")
                    
                    start_dt = TimeUtils.utc_str_to_dt(air_time)
                    if not start_dt:
                        continue
                    
                    start_ts = start_dt.timestamp() * 1000
                    if start_ts < now_ts or start_ts >= end_ts:
                        continue
                    if duration <= 0:
                        continue
                    
                    end_dt = start_dt + datetime.timedelta(seconds=duration)
                    meta = programs_map.get(program_id, {})
                    title = meta.get("Title", "").strip()
                    desc = meta.get("Description", "").strip()
                    
                    if not title:
                        continue
                    
                    norm_title = TextUtils.normalize_title(title)
                    prog_list.append({
                        "starttime": start_dt,
                        "endtime": end_dt,
                        "title": title,
                        "desc": desc,
                        "norm_title": norm_title
                    })
                except Exception as e:
                    logger.warning(f"Web源节目解析失败: {e}")
                    continue
            
            prog_list.sort(key=lambda x: x["starttime"])
            result[cdn_code] = prog_list
        
        total = sum(len(v) for v in result.values())
        logger.info(f"Web源节目获取完成，共 {len(result)} 个频道，{total} 条节目")
        return result

# ===================== 数据源2：RPC-SQLite 源（备源，对应原Python版逻辑） =====================
class RpcSqliteSource(BaseEPGSource):
    RPC_URL = "https://proxy.svc.litv.tv/cdi/v2/rpc"
    HEADERS = {
        "Content-Type": "application/json; charset=UTF-8",
        "Accept": "application/json",
        "User-Agent": "Dalvik/2.1.0 (Linux; U; Android 12; ABR-AL80 Build/4860264.0)",
        "Host": "proxy.svc.litv.tv",
        "Connection": "Keep-Alive"
    }
    
    def __init__(self, db_path: str = Config.DB_PATH):
        self.db_path = os.path.join(os.getcwd(), db_path)
        self._db_ready = False
    
    async def _download_db(self) -> bool:
        """下载并解压EPG SQLite数据库"""
        logger.info("开始下载SQLite EPG数据库...")
        try:
            payload = json.dumps({
                "jsonrpc": "2.0",
                "id": 9527,
                "method": "ConfigService.GetConfigNoAuth",
                "params": {
                    "device_id": "0",
                    "swver": "LTAGP0231140LTV20250623101220",
                    "services": ["epg"]
                }
            })
            
            async with httpx.AsyncClient(timeout=30.0) as client:
                resp = await client.post(self.RPC_URL, headers=self.HEADERS, data=payload)
                resp.raise_for_status()
                result = resp.json()
            
            epg_url = None
            if "result" in result and "epg_sqlite" in result["result"]:
                epg_url = result["result"]["epg_sqlite"][0]
            else:
                logger.error("RPC接口未返回数据库地址")
                return False
            
            # 下载压缩包
            async with httpx.AsyncClient(timeout=120.0) as client:
                resp = await client.get(epg_url)
                resp.raise_for_status()
            
            # 解压
            with zipfile.ZipFile(io.BytesIO(resp.content)) as zf:
                db_files = [f for f in zf.namelist() if f.endswith('.db')]
                if not db_files:
                    logger.error("压缩包中未找到.db文件")
                    return False
                
                with tempfile.TemporaryDirectory() as tmpdir:
                    db_name = db_files[0]
                    zf.extract(db_name, tmpdir)
                    temp_db = os.path.join(tmpdir, db_name)
                    
                    if os.path.exists(self.db_path):
                        os.remove(self.db_path)
                    shutil.move(temp_db, self.db_path)
            
            self._db_ready = os.path.exists(self.db_path)
            if self._db_ready:
                size = os.path.getsize(self.db_path)
                logger.info(f"数据库下载成功，大小: {size/1024/1024:.2f} MB")
            return self._db_ready
        
        except Exception as e:
            logger.error(f"下载数据库失败: {e}")
            return False
    
    async def is_available(self) -> bool:
        if not self._db_ready:
            await self._download_db()
        return os.path.exists(self.db_path)
    
    async def fetch_channels(self) -> List[Dict]:
        if not await self.is_available():
            return []
        
        channels = []
        try:
            conn = sqlite3.connect(self.db_path)
            conn.row_factory = sqlite3.Row
            cursor = conn.cursor()
            
            cursor.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='channel_lineup'")
            if not cursor.fetchone():
                logger.error("数据库中无channel_lineup表")
                return []
            
            cursor.execute("SELECT station_id, name, logo_mobile, cdn FROM channel_lineup ORDER BY station_id")
            rows = cursor.fetchall()
            
            for row in rows:
                station_id = row['station_id']
                name = row['name']
                logo = row['logo_mobile']
                cdn = row['cdn']
                
                icon_url = f"https://p-cdnstatic.svc.litv.tv/pics/{logo}" if logo else ""
                channels.append({
                    "id": cdn,
                    "name": name,
                    "station_id": station_id,
                    "icon": icon_url,
                    "source": "sqlite"
                })
            
            conn.close()
            logger.info(f"SQLite源获取到 {len(channels)} 个频道")
            return channels
        except Exception as e:
            logger.error(f"SQLite源获取频道失败: {e}")
            return channels
    
    async def fetch_programs(self, channels: List[Dict], days: int) -> Dict[str, List[Dict]]:
        if not await self.is_available():
            return {}
        
        result = {}
        now_ms = int(time.time() * 1000)
        end_ms = now_ms + days * 86400 * 1000
        
        try:
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            for ch in channels:
                station_id = ch.get('station_id')
                ch_id = ch['id']
                if not station_id:
                    continue
                
                table = f"ch{station_id}"
                cursor.execute(f"SELECT name FROM sqlite_master WHERE type='table' AND name='{table}'")
                if not cursor.fetchone():
                    continue
                
                cursor.execute(f"""
                    SELECT title, start_time, end_time, description
                    FROM {table}
                    WHERE start_time >= ? AND start_time < ?
                    ORDER BY start_time
                """, (now_ms, end_ms))
                
                rows = cursor.fetchall()
                prog_list = []
                for row in rows:
                    try:
                        title = row[0]
                        start_ms = row[1]
                        end_ms_row = row[2]
                        desc = row[3] or ""
                        
                        start_dt = TimeUtils.timestamp_ms_to_dt(start_ms)
                        end_dt = TimeUtils.timestamp_ms_to_dt(end_ms_row)
                        norm_title = TextUtils.normalize_title(title)
                        
                        prog_list.append({
                            "starttime": start_dt,
                            "endtime": end_dt,
                            "title": title,
                            "desc": desc,
                            "norm_title": norm_title
                        })
                    except Exception as e:
                        continue
                
                result[ch_id] = prog_list
            
            conn.close()
            total = sum(len(v) for v in result.values())
            logger.info(f"SQLite源节目获取完成，共 {len(result)} 个频道，{total} 条节目")
            return result
        
        except Exception as e:
            logger.error(f"SQLite源获取节目失败: {e}")
            return result

# ===================== 数据处理层 =====================
class EPGProcessor:
    """数据合并、补全、清洗处理器"""
    
    @staticmethod
    def merge_channels(channels_web: List[Dict], channels_sqlite: List[Dict]) -> List[Dict]:
        """合并两个源的频道，以cdn为主键，SQLite源的图标优先"""
        channel_map = {}
        
        # 先加Web源
        for ch in channels_web:
            channel_map[ch["id"]] = ch.copy()
        
        # 再合并SQLite源，补充图标和缺失频道
        for ch in channels_sqlite:
            cid = ch["id"]
            if cid in channel_map:
                # 补充图标
                if ch.get("icon") and not channel_map[cid].get("icon"):
                    channel_map[cid]["icon"] = ch["icon"]
                channel_map[cid]["source"] = "merged"
            else:
                channel_map[cid] = ch.copy()
        
        result = list(channel_map.values())
        logger.info(f"频道合并完成，共 {len(result)} 个频道（Web:{len(channels_web)} + SQLite:{len(channels_sqlite)}）")
        return result
    
    @staticmethod
    def enrich_descriptions(
        main_programs: Dict[str, List[Dict]],
        supplement_programs: Dict[str, List[Dict]]
    ) -> Tuple[Dict[str, List[Dict]], int]:
        """用补充源的简介填充主源的空白简介，返回填充后的数据和填充条数"""
        if not Config.ENABLE_DESC_ENRICH:
            return main_programs, 0
        
        filled_count = 0
        
        for ch_id, progs in main_programs.items():
            if ch_id not in supplement_programs:
                continue
            
            # 建立补充源的归一化标题->简介映射
            supp_map = {}
            for sp in supplement_programs[ch_id]:
                if TextUtils.is_valid_desc(sp["desc"], sp["title"]):
                    supp_map[sp["norm_title"]] = sp["desc"]
            
            if not supp_map:
                continue
            
            # 遍历主源节目，填空
            for prog in progs:
                if TextUtils.is_valid_desc(prog["desc"], prog["title"]):
                    continue  # 已有有效简介，跳过
                if prog["norm_title"] in supp_map:
                    prog["desc"] = supp_map[prog["norm_title"]]
                    filled_count += 1
        
        logger.info(f"简介补全完成，共填充 {filled_count} 条节目简介")
        return main_programs, filled_count
    
    @staticmethod
    def finalize_programs(programs: Dict[str, List[Dict]]) -> Dict[str, List[Dict]]:
        """最终清洗：繁转简、去重、排序"""
        result = {}
        for ch_id, progs in programs.items():
            # 繁转简
            if Config.ENABLE_T2S:
                for p in progs:
                    p["title"] = TextUtils.t2s(p["title"])
                    p["desc"] = TextUtils.t2s(p["desc"])
            
            # 按开始时间排序
            progs.sort(key=lambda x: x["starttime"])
            
            # 简单去重：相邻且标题、时间都相同的去掉
            cleaned = []
            last = None
            for p in progs:
                if last and last["starttime"] == p["starttime"] and last["norm_title"] == p["norm_title"]:
                    continue
                cleaned.append(p)
                last = p
            
            result[ch_id] = cleaned
        
        return result

# ===================== 输出层 =====================
class XMLTVWriter:
    """标准XMLTV格式生成器"""
    
    @staticmethod
    def generate(channels: List[Dict], programs: Dict[str, List[Dict]], output_path: str) -> bool:
        try:
            tv = ET.Element('tv')
            tv.set('source-info-url', 'https://www.litv.tv/')
            tv.set('source-info-name', 'LiTV EPG (Merged)')
            tv.set('generator-info-name', 'LiTV EPG Merged Crawler')
            
            # 写入频道
            for ch in channels:
                ch_elem = ET.SubElement(tv, 'channel')
                ch_elem.set('id', ch['id'])
                
                display = ET.SubElement(ch_elem, 'display-name')
                display.set('lang', 'zh')
                display.text = TextUtils.t2s(ch['name']) if Config.ENABLE_T2S else ch['name']
                
                if ch.get('icon'):
                    icon = ET.SubElement(ch_elem, 'icon')
                    icon.set('src', ch['icon'])
            
            # 写入节目
            total_progs = 0
            for ch_id, prog_list in programs.items():
                for p in prog_list:
                    prog_elem = ET.SubElement(tv, 'programme')
                    prog_elem.set('start', TimeUtils.dt_to_xmltv_str(p['starttime']))
                    prog_elem.set('stop', TimeUtils.dt_to_xmltv_str(p['endtime']))
                    prog_elem.set('channel', ch_id)
                    
                    title = ET.SubElement(prog_elem, 'title')
                    title.set('lang', 'zh')
                    title.text = p['title']
                    
                    if p['desc']:
                        desc = ET.SubElement(prog_elem, 'desc')
                        desc.set('lang', 'zh')
                        desc.text = p['desc']
                    
                    total_progs += 1
            
            # 格式化输出
            xml_bytes = ET.tostring(tv, encoding='utf-8', method='xml')
            dom = minidom.parseString(xml_bytes)
            pretty_xml = dom.toprettyxml(indent='  ', encoding='utf-8')
            
            os.makedirs(os.path.dirname(output_path), exist_ok=True)
            with open(output_path, 'wb') as f:
                f.write(pretty_xml)
            
            logger.info(f"XMLTV生成成功：{output_path}")
            logger.info(f"包含 {len(channels)} 个频道，{total_progs} 条节目")
            return True
        
        except Exception as e:
            logger.error(f"生成XMLTV失败: {e}")
            import traceback
            logger.error(traceback.format_exc())
            return False

# ===================== 主流程调度 =====================
async def main():
    logger.info("=" * 60)
    logger.info("LiTV 双源整合版 EPG 抓取器启动")
    logger.info(f"主源：{Config.PRIMARY_SOURCE}，获取天数：{Config.DAYS}")
    logger.info("=" * 60)
    
    # 1. 初始化数据源
    web_source = WebZlibSource() if Config.ENABLE_WEB_SOURCE else None
    sqlite_source = RpcSqliteSource() if Config.ENABLE_SQLITE_SOURCE else None
    
    # 2. 可用性检测与主备切换
    main_source = None
    supp_source = None
    
    if Config.PRIMARY_SOURCE == "web" and web_source:
        main_available = await web_source.is_available()
        if main_available:
            main_source = web_source
            supp_source = sqlite_source
            logger.info("主源（Web）可用，备源（SQLite）待命")
        else:
            logger.warning("主源（Web）不可用，降级到备源（SQLite）")
            main_source = sqlite_source
            supp_source = None
    else:
        main_source = sqlite_source
        supp_source = web_source
        logger.info("主源（SQLite）启用，备源（Web）待命")
    
    if not main_source:
        logger.error("无可用数据源，任务终止")
        return 1
    
    # 3. 获取频道
    logger.info("正在获取频道列表...")
    main_channels = await main_source.fetch_channels()
    supp_channels = await supp_source.fetch_channels() if supp_source else []
    
    if not main_channels:
        logger.error("主源频道获取失败，任务终止")
        return 1
    
    # 合并频道
    all_channels = EPGProcessor.merge_channels(main_channels, supp_channels)
    
    # 4. 获取节目
    logger.info("正在获取节目数据...")
    main_programs = await main_source.fetch_programs(main_channels, Config.DAYS)
    
    supp_programs = {}
    if supp_source and Config.ENABLE_DESC_ENRICH:
        supp_programs = await supp_source.fetch_programs(supp_channels, Config.DAYS)
    
    # 5. 数据处理：补全简介 + 最终清洗
    if supp_programs and Config.ENABLE_DESC_ENRICH:
        main_programs, filled = EPGProcessor.enrich_descriptions(main_programs, supp_programs)
    
    final_programs = EPGProcessor.finalize_programs(main_programs)
    
    # 6. 生成输出
    output_path = os.path.join(Config.OUTPUT_DIR, Config.OUTPUT_FILE)
    success = XMLTVWriter.generate(all_channels, final_programs, output_path)
    
    if success:
        file_size = os.path.getsize(output_path)
        logger.info("=" * 60)
        logger.info("任务执行完成")
        logger.info(f"输出文件：{output_path}")
        logger.info(f"文件大小：{file_size/1024/1024:.2f} MB")
        logger.info("=" * 60)
        return 0
    else:
        logger.error("任务执行失败")
        return 1

if __name__ == "__main__":
    exit_code = asyncio.run(main())
    sys.exit(exit_code)
