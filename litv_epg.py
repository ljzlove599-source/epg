"""
LiTV EPG 抓取器 - GitHub Actions 专用版本
每日自动执行，生成 XMLTV 格式的节目表
"""

import sqlite3
import datetime
import os
import asyncio
import httpx
import zipfile
import io
import logging
import json
import xml.etree.ElementTree as ET
from xml.dom import minidom
from typing import List, Dict, Optional
import time
from pathlib import Path
import sys
import tempfile
import shutil
from opencc import OpenCC

# 配置日志
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.StreamHandler()  # GitHub Actions 仅需控制台输出
    ]
)


class LiTVEPGCrawler:
    def __init__(self, db_path: str = 'epg.db'):
        # 使用绝对路径确保文件位置正确
        self.db_path = os.path.join(os.getcwd(), db_path)
        self.epg_url = None
        self.headers = {
            "Content-Type": "application/json; charset=UTF-8",
            "Accept": "application/json",
            "User-Agent": "Dalvik/2.1.0 (Linux; U; Android 12; ABR-AL80 Build/4860264.0)",
            "Host": "proxy.svc.litv.tv",
            "Connection": "Keep-Alive"
        }
        # 设置上海时区 (UTC+8)
        self.shanghai_tz = datetime.timezone(datetime.timedelta(hours=8))
        # 初始化繁体转简体转换器
        self.cc = OpenCC('t2s')

    def t2s(self, text: Optional[str]) -> str:
        """繁体文本转简体，空值安全处理"""
        if not text:
            return ""
        try:
            return self.cc.convert(text)
        except Exception as e:
            logging.warning(f"繁简转换失败: {e}")
            return text

    async def download_litv_epgs(self, force_update: bool = True) -> bool:
        """下载 LiTV EPG 数据库（GitHub Actions 默认强制更新）"""

        logging.info("开始下载 LiTV EPG 数据库...")
        logging.info(f"数据库将保存到: {self.db_path}")

        try:
            # 获取 EPG SQLite 下载地址
            data = json.dumps({
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
                response = await client.post(
                    "https://proxy.svc.litv.tv/cdi/v2/rpc",
                    headers=self.headers,
                    data=data
                )
                response.raise_for_status()

                result = response.json()
                if "result" in result and "epg_sqlite" in result["result"]:
                    self.epg_url = result["result"]["epg_sqlite"][0]
                    logging.info(f"获取到 EPG 下载地址: {self.epg_url}")
                else:
                    logging.error("无法获取 EPG 下载地址")
                    return False

            # 下载 EPG 数据库压缩包
            async with httpx.AsyncClient(timeout=120.0) as client:
                logging.info("正在下载 EPG 数据库...")
                response = await client.get(self.epg_url)
                response.raise_for_status()

                # 解压数据库到临时目录
                with zipfile.ZipFile(io.BytesIO(response.content)) as zip_file:
                    # 列出 ZIP 中的文件
                    file_list = zip_file.namelist()
                    logging.info(f"ZIP 中包含的文件: {file_list}")

                    # 查找 .db 数据库文件
                    db_files = [f for f in file_list if f.endswith('.db')]
                    if not db_files:
                        logging.error("ZIP 中未找到 .db 数据库文件")
                        return False

                    # 使用临时目录解压
                    with tempfile.TemporaryDirectory() as tmpdir:
                        db_file_name = db_files[0]
                        logging.info(f"正在解压 {db_file_name} 到临时目录")

                        # 解压到临时目录
                        zip_file.extract(db_file_name, tmpdir)
                        temp_db_path = os.path.join(tmpdir, db_file_name)

                        # 检查解压是否成功
                        if not os.path.exists(temp_db_path):
                            logging.error(f"解压失败: {temp_db_path} 文件不存在")
                            return False

                        # 移动文件到目标位置
                        if os.path.exists(self.db_path):
                            os.remove(self.db_path)
                            logging.info(f"已删除旧数据库文件: {self.db_path}")

                        shutil.move(temp_db_path, self.db_path)
                        logging.info(f"已移动数据库文件到: {self.db_path}")

            # 检查最终文件
            if os.path.exists(self.db_path):
                file_size = os.path.getsize(self.db_path)
                logging.info(f"EPG数据库下载成功: {self.db_path} (大小: {file_size:,} bytes)")
                return True
            else:
                logging.error("数据库文件不存在，下载失败")
                return False

        except httpx.RequestError as e:
            logging.error(f"网络请求失败: {e}")
            return False
        except zipfile.BadZipFile:
            logging.error("下载的文件不是有效的ZIP文件")
            return False
        except Exception as e:
            logging.error(f"下载EPG数据库时发生错误: {e}")
            import traceback
            logging.error(traceback.format_exc())
            return False

    def get_channels_litv(self) -> List[Dict]:
        """获取所有频道清单"""
        channels = []

        if not os.path.exists(self.db_path):
            logging.error(f"数据库文件不存在: {self.db_path}")
            return channels

        try:
            conn = sqlite3.connect(self.db_path)
            conn.row_factory = sqlite3.Row  # 使用字典格式的行数据
            cursor = conn.cursor()

            # 检查是否存在 channel_lineup 表
            cursor.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='channel_lineup';")
            if not cursor.fetchone():
                logging.error("数据库中不存在 channel_lineup 表")
                return channels

            # 从 channel_lineup 表获取频道信息
            cursor.execute("SELECT station_id, name, logo_mobile, cdn FROM channel_lineup ORDER BY station_id")
            rows = cursor.fetchall()

            logging.info(f"找到 {len(rows)} 个频道")

            for row in rows:
                try:
                    station_id = row['station_id']
                    channel_name = self.t2s(row['name'])  # 频道名繁转简
                    logo_mobile = row['logo_mobile']
                    cdn = row['cdn']

                    # 拼接完整的图标 URL
                    if logo_mobile:
                        icon_url = f"https://p-cdnstatic.svc.litv.tv/pics/{logo_mobile}"
                    else:
                        icon_url = ""

                    # 使用 cdn 作为频道 ID
                    channel = {
                        'id': cdn,
                        'name': channel_name,
                        'station_id': station_id,
                        'cdn': cdn,
                        'icon': icon_url,
                        'source': 'litv'
                    }

                    channels.append(channel)

                except Exception as e:
                    logging.warning(f"解析频道信息失败: {e}")
                    continue

            conn.close()
            logging.info(f"成功获取 {len(channels)} 个频道")

        except Exception as e:
            logging.error(f"获取频道清单时发生错误: {e}")
            import traceback
            logging.error(traceback.format_exc())

        return channels

    def get_epgs_for_channel(self, channel: Dict, days: int = 7) -> Dict:
        """获取指定频道的节目表，默认获取7天数据"""
        epgs = []
        msg = ''
        success = 0

        if not os.path.exists(self.db_path):
            return {
                'success': 0,
                'epgs': [],
                'msg': f"数据库文件不存在: {self.db_path}",
                'ban': 0
            }

        try:
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()

            # 使用 station_id 拼接表名
            station_id = channel.get('station_id')
            if not station_id:
                logging.warning(f"频道 {channel.get('id')} 缺少 station_id 信息")
                return {
                    'success': 0,
                    'epgs': [],
                    'msg': "频道缺少 station_id 信息",
                    'ban': 0
                }

            table_name = f"ch{station_id}"

            # 检查表是否存在
            cursor.execute(f"SELECT name FROM sqlite_master WHERE type='table' AND name='{table_name}';")
            if not cursor.fetchone():
                logging.warning(f"频道 {channel['name']} 对应的数据表 {table_name} 不存在")
                return {
                    'success': 0,
                    'epgs': [],
                    'msg': f"频道表 {table_name} 不存在",
                    'ban': 0
                }

            # 计算时间范围，从当前时间到指定天数后
            now = int(time.time() * 1000)
            start_time = now

            # 计算指定天数后的时间戳
            end_time = now + (days * 24 * 3600 * 1000)

            # 查询指定天数内的节目数据
            cursor.execute(f"""
                SELECT station_id, title, start_time, end_time, description 
                FROM {table_name} 
                WHERE start_time >= ? AND start_time < ?
                ORDER BY start_time
            """, (start_time, end_time))

            rows = cursor.fetchall()

            for row in rows:
                try:
                    # 解析字段并执行繁转简
                    station_id = row[0]
                    title = self.t2s(row[1])  # 节目标题繁转简
                    start_time_ms = row[2]
                    end_time_ms = row[3]
                    description = self.t2s(row[4] or "")  # 节目描述繁转简

                    # 转换时间格式，使用上海时区
                    try:
                        # 先转换为 UTC 时间，再切换到上海时区
                        utc_dt = datetime.datetime.utcfromtimestamp(start_time_ms / 1000)
                        start_dt = utc_dt.replace(tzinfo=datetime.timezone.utc).astimezone(self.shanghai_tz)

                        utc_dt = datetime.datetime.utcfromtimestamp(end_time_ms / 1000)
                        end_dt = utc_dt.replace(tzinfo=datetime.timezone.utc).astimezone(self.shanghai_tz)
                    except Exception as e:
                        logging.warning(f"时间转换失败: {e}")
                        continue

                    epg = {
                        'channel_id': channel['id'],
                        'starttime': start_dt,
                        'endtime': end_dt,
                        'title': title,
                        'desc': description
                    }

                    epgs.append(epg)

                except Exception as e:
                    logging.warning(f"解析节目数据时发生错误: {e}")
                    continue

            conn.close()
            success = 1
            msg = f"成功获取 {len(epgs)} 个节目（{days}天）"

        except Exception as e:
            success = 0
            msg = f"获取频道 {channel.get('name')} 节目表时发生错误: {e}"
            logging.error(msg)

        return {
            'success': success,
            'epgs': epgs,
            'msg': msg,
            'ban': 0
        }

    def convert_to_xmltv(self, channels: List[Dict], epg_data: Dict, output_file: str = 'litv_epg.xml') -> bool:
        """将 EPG 数据转换为 XMLTV 格式"""

        try:
            # 创建根元素
            tv = ET.Element('tv')
            tv.set('source-info-url', 'https://www.litv.tv/')
            tv.set('source-info-name', 'LiTV EPG')
            tv.set('generator-info-name', 'LiTV EPG Crawler')
            tv.set('generator-info-url', '')

            # 添加频道信息
            for channel in channels:
                channel_elem = ET.SubElement(tv, 'channel')
                channel_elem.set('id', channel['id'])

                # 显示名称
                display_name = ET.SubElement(channel_elem, 'display-name')
                display_name.set('lang', 'zh')
                display_name.text = channel['name']

                # 图标（如果存在）
                if channel.get('icon'):
                    icon = ET.SubElement(channel_elem, 'icon')
                    icon.set('src', channel['icon'])

            # 添加节目信息
            program_count = 0
            for channel_id, programs in epg_data.items():
                if not programs:
                    continue

                for program in programs:
                    programme = ET.SubElement(tv, 'programme')

                    # 设置时间格式: YYYYMMDDHHMMSS +0800
                    start_str = program['starttime'].strftime('%Y%m%d%H%M%S +0800')
                    end_str = program['endtime'].strftime('%Y%m%d%H%M%S +0800')

                    programme.set('start', start_str)
                    programme.set('stop', end_str)
                    programme.set('channel', channel_id)

                    # 标题
                    title_elem = ET.SubElement(programme, 'title')
                    title_elem.set('lang', 'zh')
                    title_elem.text = program['title']

                    # 描述
                    if program['desc']:
                        desc_elem = ET.SubElement(programme, 'desc')
                        desc_elem.set('lang', 'zh')
                        desc_elem.text = program['desc']

                    program_count += 1

            # 格式化 XML 输出
            xml_str = ET.tostring(tv, encoding='utf-8', method='xml')
            dom = minidom.parseString(xml_str)
            pretty_xml = dom.toprettyxml(indent='  ', encoding='utf-8')

            # 写入文件
            with open(output_file, 'wb') as f:
                f.write(pretty_xml)

            logging.info(f"XMLTV 文件生成成功: {output_file}")
            logging.info(f"包含 {len(channels)} 个频道, {program_count} 个节目")

            return True

        except Exception as e:
            logging.error(f"生成 XMLTV 文件时发生错误: {e}")
            import traceback
            logging.error(traceback.format_exc())
            return False

    async def run_full_crawl(self, output_file: str = 'litv_epg.xml', days: int = 7) -> bool:
        """运行完整抓取流程，默认获取7天数据"""

        logging.info(f"开始获取 {days} 天的 LiTV EPG 数据...")

        # 1. 下载 EPG 数据库
        if not await self.download_litv_epgs():
            logging.error("下载 EPG 数据库失败")
            return False

        # 2. 获取频道清单
        channels = self.get_channels_litv()
        if not channels:
            logging.error("无法获取频道清单")
            return False

        logging.info(f"开始获取 {len(channels)} 个频道的节目表...")

        # 3. 获取每个频道的节目表
        epg_data = {}
        total_programs = 0

        for i, channel in enumerate(channels, 1):
            if i % 10 == 0:
                logging.info(f"已处理 {i}/{len(channels)} 个频道...")

            result = self.get_epgs_for_channel(channel, days)

            if result['success']:
                epg_data[channel['id']] = result['epgs']
                total_programs += len(result['epgs'])
            else:
                logging.warning(f"频道 {channel['name']}: {result['msg']}")

            # 短延迟避免过快请求
            await asyncio.sleep(0.01)

        logging.info(f"总共获取 {total_programs} 个节目")

        # 4. 转换为 XMLTV 格式
        if self.convert_to_xmltv(channels, epg_data, output_file):
            logging.info("EPG 抓取和转换完成!")
            return True
        else:
            logging.error("EPG 转换失败")
            return False


async def main():
    """主函数 - GitHub Actions 专用"""

    # 创建输出目录
    output_dir = Path('epg')
    output_dir.mkdir(exist_ok=True)

    # 创建抓取器实例
    crawler = LiTVEPGCrawler()

    # 设置输出文件路径
    output_file = output_dir / 'litv.xml'

    # 运行完整抓取流程
    logging.info("开始运行 LiTV EPG 抓取器 (GitHub Actions)...")
    logging.info(f"将获取7天节目表，输出到 {output_file}")

    success = await crawler.run_full_crawl(
        output_file=str(output_file),
        days=7
    )

    if success:
        # 检查输出文件大小
        if output_file.exists():
            file_size = output_file.stat().st_size
            logging.info(f"EPG 数据已保存到 {output_file}")
            logging.info(f"文件大小: {file_size:,} bytes ({file_size/1024/1024:.2f} MB)")

            # 读取前几行确认内容
            try:
                with open(output_file, 'r', encoding='utf-8') as f:
                    lines = [next(f).strip() for _ in range(5)]
                    logging.info("XML 文件前5行:")
                    for line in lines:
                        logging.info(f"  {line}")
            except Exception as e:
                logging.warning(f"读取XML文件失败: {e}")
        else:
            logging.error("输出文件不存在!")
            return 1

        # 获取频道数量
        channels = crawler.get_channels_litv()
        logging.info(f"频道数量: {len(channels)} 个")

        return 0
    else:
        logging.error("EPG 抓取失败")
        return 1


if __name__ == '__main__':
    # 运行主程序
    exit_code = asyncio.run(main())
    sys.exit(exit_code)
