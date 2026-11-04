from opencc import OpenCC
import sys

target_file = "epg/tdm.xml"

try:
    with open(target_file, "r", encoding="utf-8") as f:
        content_tc = f.read()
    
    cc = OpenCC('t2s')
    content_sc = cc.convert(content_tc)
    
    with open(target_file, "w", encoding="utf-8") as f:
        f.write(content_sc)
    
    print("转换完成，已生成简体版 epg/tdm.xml")
except FileNotFoundError:
    print(f"错误：未找到 {target_file}")
    sys.exit(1)
