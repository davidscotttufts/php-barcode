"""Decode barcodes in an image with zxing-cpp. Usage: decode.py IMAGE [IMAGE...]
Prints: <file>\t<format>\t<text as JSON>\t<bytes hex>  (or NONE)"""
import sys, json
import zxingcpp
from PIL import Image
for path in sys.argv[1:]:
    img = Image.open(path).convert('L')
    # pad with white so tight quiet zones still decode
    pad = Image.new('L', (img.width + 40, img.height + 40), 255); pad.paste(img, (20, 20))
    res = zxingcpp.read_barcodes(pad, formats=zxingcpp.BarcodeFormat.All, try_rotate=True, try_downscale=True)
    if not res:
        print(f"{path}\tNONE"); continue
    for r in res:
        print(f"{path}\t{r.format.name}\t{json.dumps(r.text, ensure_ascii=False)}\t{bytes(r.bytes).hex()}\t{r.symbology_identifier}")
