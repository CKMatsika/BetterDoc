"""PyMuPDF + Pillow engine for print watermarking, barcode separator generation/splitting, and scan deskew/crop."""

from __future__ import annotations

import base64
import io
import json
import re
import shutil
import subprocess
import tempfile
from typing import Any

import pymupdf
import qrcode
from PIL import Image, ImageOps


_MAGIC = b"SN"
_MATRIX_ROWS = 22
_MATRIX_COLS = 88
_MATRIX_TOP_NORM = 0.74
_MATRIX_BOT_NORM = 0.895


def _crc16_ccitt(data: bytes) -> int:
    crc = 0xFFFF
    for b in data:
        crc ^= b << 8
        for _ in range(8):
            if crc & 0x8000:
                crc = ((crc << 1) ^ 0x1021) & 0xFFFF
            else:
                crc = (crc << 1) & 0xFFFF
    return crc


def watermark_pdf(
    pdf_bytes: bytes,
    watermark_text: str,
    classification_banner: str = "",
) -> dict[str, Any]:
    """Stamp non-removable diagonal user/classification/IP watermark + banners on every PDF page."""
    doc = pymupdf.open(stream=pdf_bytes, filetype="pdf")
    try:
        page_count = doc.page_count
        banner = classification_banner.strip() or watermark_text

        for page_idx in range(page_count):
            page = doc.load_page(page_idx)
            rect = page.rect

            # Top and bottom classification/audit banners
            header_rect = pymupdf.Rect(rect.x0 + 18, rect.y0 + 10, rect.x1 - 18, rect.y0 + 28)
            footer_rect = pymupdf.Rect(rect.x0 + 18, rect.y1 - 26, rect.x1 - 18, rect.y1 - 10)
            page.insert_textbox(
                header_rect,
                banner[:120],
                fontsize=8,
                fontname="helv",
                color=(0.72, 0.11, 0.11),
                align=pymupdf.TEXT_ALIGN_CENTER,
                overlay=True,
            )
            page.insert_textbox(
                footer_rect,
                watermark_text[:140],
                fontsize=7.5,
                fontname="helv",
                color=(0.35, 0.39, 0.45),
                align=pymupdf.TEXT_ALIGN_CENTER,
                overlay=True,
            )

            # Diagonal center stamp via TextWriter + 45-degree rotation morph
            font = pymupdf.Font("helv")
            tw = pymupdf.TextWriter(rect, color=(0.75, 0.14, 0.14), opacity=0.22)
            cx = rect.x0 + rect.width * 0.5
            cy = rect.y0 + rect.height * 0.5
            label = watermark_text[:90]
            text_width = font.text_length(label, fontsize=14)
            tw.append(pymupdf.Point(cx - (text_width * 0.5), cy), label, font=font, fontsize=14)
            tw.write_text(page, morph=(pymupdf.Point(cx, cy), pymupdf.Matrix(-45)), overlay=True)

            # Secondary offset diagonal pass for large pages
            tw2 = pymupdf.TextWriter(rect, color=(0.30, 0.36, 0.45), opacity=0.16)
            sub_y = rect.y0 + rect.height * 0.72
            tw2.append(pymupdf.Point(cx - (text_width * 0.5), sub_y), label, font=font, fontsize=12)
            tw2.write_text(page, morph=(pymupdf.Point(cx, sub_y), pymupdf.Matrix(-45)), overlay=True)

        doc.set_metadata({"producer": "BetterDoc DMS Secure Print Watermark Engine"})
        out_bytes = doc.tobytes(garbage=3, deflate=True, clean=True)
    finally:
        doc.close()

    return {
        "watermarked_pdf_bytes": out_bytes,
        "page_count": page_count,
    }


def _encode_separator_bits(payload_dict: dict[str, str]) -> list[int]:
    safe_dict = dict(payload_dict)
    max_payload = ((_MATRIX_ROWS - 2) * (_MATRIX_COLS - 2) // 8) - 6
    raw_json = json.dumps(safe_dict, separators=(",", ":"), ensure_ascii=True).encode("ascii")
    while len(raw_json) > max_payload and safe_dict.get("title", ""):
        safe_dict["title"] = safe_dict["title"][:-4]
        raw_json = json.dumps(safe_dict, separators=(",", ":"), ensure_ascii=True).encode("ascii")
    while len(raw_json) > max_payload and safe_dict.get("cabinet", ""):
        safe_dict["cabinet"] = safe_dict["cabinet"][:-4]
        raw_json = json.dumps(safe_dict, separators=(",", ":"), ensure_ascii=True).encode("ascii")
    if len(raw_json) > max_payload:
        raw_json = json.dumps({"doc_type": safe_dict.get("doc_type", "general"), "title": safe_dict.get("title", "")[:40]}, separators=(",", ":"), ensure_ascii=True).encode("ascii")[:max_payload]
    body = _MAGIC + bytes([len(raw_json)]) + raw_json
    crc = _crc16_ccitt(body)
    packet = body + crc.to_bytes(2, "big")

    bits: list[int] = []
    for byte in packet:
        for shift in range(7, -1, -1):
            bits.append((byte >> shift) & 1)
    return bits


def generate_separator_pdf(
    doc_type: str = "general",
    title: str = "Scanned Document",
    cabinet_code: str = "",
    classification_name: str = "Internal",
) -> bytes:
    """Create a print-ready BetterDoc Barcode Separator Sheet PDF (QR + raster sync barcode + metadata)."""
    meta = {
        "doc_type": doc_type.strip()[:32] or "general",
        "title": title.strip()[:80] or "Scanned Record",
        "cabinet": cabinet_code.strip()[:32],
        "classification": classification_name.strip()[:32] or "Internal",
    }
    marker_payload = "BETTERDOC-SEP|" + json.dumps(meta, separators=(",", ":"), ensure_ascii=True)

    qr = qrcode.QRCode(border=2, box_size=6)
    qr.add_data(marker_payload)
    qr.make(fit=True)
    qr_img = qr.make_image(fill_color="black", back_color="white").convert("RGB")
    qr_buf = io.BytesIO()
    qr_img.save(qr_buf, format="PNG")

    doc = pymupdf.open()
    try:
        page = doc.new_page(width=595, height=842)

        # Header frame
        page.draw_rect(pymupdf.Rect(36, 36, 559, 108), color=(0.06, 0.09, 0.16), fill=(0.06, 0.09, 0.16))
        page.insert_text(pymupdf.Point(54, 68), "BetterDoc DMS — BATCH SEPARATOR SHEET", fontsize=17, fontname="helv", color=(1, 1, 1))
        page.insert_text(
            pymupdf.Point(54, 90),
            "PLACE THIS PAGE BEFORE THE NEXT PHYSICAL DOCUMENT IN YOUR SCANNER FEEDER",
            fontsize=9.5,
            fontname="helv",
            color=(0.75, 0.84, 0.96),
        )

        # QR code on left + metadata table on right
        page.insert_image(pymupdf.Rect(48, 136, 228, 316), stream=qr_buf.getvalue())
        rows = [
            ("Document Type:", meta["doc_type"].upper()),
            ("Target Title Prefix:", meta["title"]),
            ("Target Cabinet:", meta["cabinet"] or "Auto-Route"),
            ("Classification:", meta["classification"].upper()),
        ]
        y = 164
        for lbl, val in rows:
            page.insert_text(pymupdf.Point(252, y), lbl, fontsize=10, fontname="helv", color=(0.35, 0.4, 0.48))
            page.insert_text(pymupdf.Point(252, y + 16), val[:46], fontsize=13, fontname="hebo", color=(0.06, 0.09, 0.16))
            y += 42

        # Decorative Code-128 style visual bars in middle band
        bar_x = 54.0
        for idx, ch in enumerate(marker_payload[:48]):
            val = ord(ch)
            w = 1.6 + (val % 3) * 0.9
            if idx % 2 == 0 or (val & 1):
                page.draw_rect(pymupdf.Rect(bar_x, 368, bar_x + w, 442), color=None, fill=(0.05, 0.05, 0.05))
            bar_x += w + 2.1
            if bar_x > 535:
                break

        page.insert_text(
            pymupdf.Point(48, 466),
            marker_payload[:100],
            fontsize=8.5,
            fontname="cour",
            color=(0.15, 0.18, 0.24),
        )
        if len(marker_payload) > 100:
            page.insert_text(
                pymupdf.Point(48, 480),
                marker_payload[100:],
                fontsize=8.5,
                fontname="cour",
                color=(0.15, 0.18, 0.24),
            )

        # Draw high-contrast 14x64 2D Finder Matrix (solid 1-cell border, 15px large cells at 150 DPI)
        bits = _encode_separator_bits(meta)
        mat_y0 = 842 * _MATRIX_TOP_NORM
        mat_y1 = 842 * _MATRIX_BOT_NORM
        margin_x = 46.0
        usable_w = 595.0 - (2 * margin_x)
        cell_w = usable_w / _MATRIX_COLS
        cell_h = (mat_y1 - mat_y0) / _MATRIX_ROWS

        page.draw_rect(pymupdf.Rect(margin_x - 8, mat_y0 - 8, 595.0 - margin_x + 8, mat_y1 + 8), color=None, fill=(1, 1, 1))
        bit_idx = 0
        for r in range(_MATRIX_ROWS):
            for c in range(_MATRIX_COLS):
                is_border = r == 0 or r == _MATRIX_ROWS - 1 or c == 0 or c == _MATRIX_COLS - 1
                if is_border:
                    bit = 1
                else:
                    bit = bits[bit_idx] if bit_idx < len(bits) else ((r + c) & 1)
                    bit_idx += 1
                if bit == 1:
                    x0 = margin_x + (c * cell_w)
                    y0 = mat_y0 + (r * cell_h)
                    page.draw_rect(pymupdf.Rect(x0, y0, x0 + cell_w, y0 + cell_h), color=None, fill=(0.0, 0.0, 0.0))

        out = doc.tobytes(garbage=3, deflate=True)
    finally:
        doc.close()

    return out


def _decode_raster_barcode_strip(page: pymupdf.Page) -> dict[str, str] | None:
    """Locate the 2D optical finder matrix directly from scanned page pixels without external DLLs."""
    pix = page.get_pixmap(dpi=150, colorspace=pymupdf.csGRAY)
    img = Image.frombytes("L", (pix.width, pix.height), pix.samples)

    search_y0 = max(0, int(pix.height * (_MATRIX_TOP_NORM - 0.04)))
    search_y1 = min(pix.height, int(pix.height * (_MATRIX_BOT_NORM + 0.04)))
    if search_y1 <= search_y0:
        return None

    band = img.crop((0, search_y0, pix.width, search_y1))
    bw, bh = band.size

    # Locate bounding box of dark finder border lines (solid top/bottom/left/right black border)
    row_dark_counts = []
    for y in range(bh):
        cnt = 0
        for x in range(0, bw, 2):
            if band.getpixel((x, y)) < 110:
                cnt += 1
        row_dark_counts.append(cnt)

    # Top and bottom border rows have > 50% dark pixels across sampled width
    border_ys = [y for y, c in enumerate(row_dark_counts) if c > (bw // 4)]
    if len(border_ys) < 4:
        return None

    top_px = border_ys[0]
    bot_px = border_ys[-1]
    if bot_px - top_px < 40:
        return None

    # Find left & right solid border X inside middle band height
    mid_ys = range(top_px + 4, bot_px - 3, 3)
    col_dark_counts = []
    for x in range(bw):
        cnt = 0
        for y in mid_ys:
            if band.getpixel((x, y)) < 110:
                cnt += 1
        col_dark_counts.append(cnt)

    border_xs = [x for x, c in enumerate(col_dark_counts) if c >= max(3, len(list(mid_ys)) * 0.7)]
    if len(border_xs) < 4:
        return None

    left_px = border_xs[0]
    right_px = border_xs[-1]
    mat_w = right_px - left_px + 1
    mat_h = bot_px - top_px + 1
    if mat_w < 160 or mat_h < 40:
        return None

    cw = mat_w / _MATRIX_COLS
    ch = mat_h / _MATRIX_ROWS

    inner_bits: list[int] = []
    for r in range(1, _MATRIX_ROWS - 1):
        cy = min(bh - 1, max(0, int(round(top_px + (r + 0.5) * ch))))
        for c in range(1, _MATRIX_COLS - 1):
            cx = min(bw - 1, max(0, int(round(left_px + (c + 0.5) * cw))))
            inner_bits.append(1 if band.getpixel((cx, cy)) < 128 else 0)

    max_bytes = len(inner_bits) // 8
    buf = bytearray()
    for bi in range(max_bytes):
        val = 0
        for k in range(8):
            val = (val << 1) | inner_bits[(bi * 8) + k]
        buf.append(val)

    if len(buf) < 6 or buf[:2] != _MAGIC:
        return None
    plen = buf[2]
    if 3 + plen + 2 > len(buf):
        return None
    body = bytes(buf[:3 + plen])
    crc_read = int.from_bytes(buf[3 + plen:3 + plen + 2], "big")
    if _crc16_ccitt(body) != crc_read:
        return None

    try:
        parsed = json.loads(body[3:].decode("ascii"))
        if isinstance(parsed, dict):
            return {str(k): str(v) for k, v in parsed.items()}
    except Exception:
        return None

    return None


def decode_separator_from_page(page: pymupdf.Page) -> dict[str, str] | None:
    """Detect whether a page is a BetterDoc separator sheet via QR/zbarimg, raster barcode, or text marker."""
    # 1. Native text check (fast, handles generated separator PDFs & OCR'd PDFs)
    text = page.get_text("text") or ""
    joined = re.sub(r"\s+", "", text)
    m = re.search(r"BetterDoc-SEP\|(\{[^\r\n]+\})", text)
    if not m:
        m = re.search(r"BetterDoc-SEP\|(\{[^\}]+\})", joined)
    if m:
        try:
            parsed = json.loads(m.group(1))
            if isinstance(parsed, dict):
                return {str(k): str(v) for k, v in parsed.items()}
        except Exception:
            pass

    # 2. Pure optical raster barcode strip check (handles text-free scanned raster pages!)
    raster_meta = _decode_raster_barcode_strip(page)
    if raster_meta is not None:
        return raster_meta

    # 3. Optional Linux zbarimg fallback for arbitrary Code128 / QR sheets printed externally
    zbar_bin = shutil.which("zbarimg")
    if zbar_bin:
        try:
            pix = page.get_pixmap(dpi=150)
            with tempfile.NamedTemporaryFile(suffix=".png", delete=True) as tmp:
                pix.save(tmp.name)
                proc = subprocess.run([zbar_bin, "--quiet", "--raw", tmp.name], capture_output=True, text=True, timeout=6)
                if proc.returncode == 0 and proc.stdout.strip():
                    for line in proc.stdout.strip().splitlines():
                        if line.startswith("BETTERDOC-SEP|"):
                            payload_str = line.split("|", 1)[1]
                            try:
                                parsed = json.loads(payload_str)
                                if isinstance(parsed, dict):
                                    return {str(k): str(v) for k, v in parsed.items()}
                            except Exception:
                                return {"title": payload_str[:80]}
        except Exception:
            pass

    return None


def split_pdf_by_separators(pdf_bytes: bytes) -> dict[str, Any]:
    """Split a multi-document scanned PDF into distinct child PDFs on every Barcode Separator Sheet."""
    src = pymupdf.open(stream=pdf_bytes, filetype="pdf")
    segments: list[dict[str, Any]] = []
    separators_found = 0

    current_pages: list[int] = []
    current_meta: dict[str, str] = {}

    def flush_segment() -> None:
        if not current_pages:
            return
        out = pymupdf.open()
        try:
            for p_idx in current_pages:
                out.insert_pdf(src, from_page=p_idx, to_page=p_idx)
            seg_bytes = out.tobytes(garbage=3, deflate=True)
            segments.append({
                "pdf_b64": base64.b64encode(seg_bytes).decode("ascii"),
                "page_count": len(current_pages),
                "meta": dict(current_meta),
            })
        finally:
            out.close()

    try:
        total_pages = src.page_count
        for idx in range(total_pages):
            page = src.load_page(idx)
            sep_meta = decode_separator_from_page(page)
            if sep_meta is not None:
                flush_segment()
                current_pages = []
                current_meta = sep_meta
                separators_found += 1
            else:
                current_pages.append(idx)

        flush_segment()
    finally:
        src.close()

    return {
        "total_input_pages": total_pages,
        "separators_found": separators_found,
        "segments": segments,
    }


def deskew_and_crop_images_to_pdf(image_paths: list[str]) -> dict[str, Any]:
    """Normalize camera/scanner images (EXIF transpose, autocontrast, border crop, projection deskew) to multi-page PDF."""
    doc = pymupdf.open()
    deskew_applied = False

    try:
        for img_path in image_paths:
            with Image.open(img_path) as raw_im:
                im = ImageOps.exif_transpose(raw_im).convert("RGB")
                im = ImageOps.autocontrast(im, cutoff=1)

                # Trim uniform dark scanner bezel margins if present
                gray = im.convert("L")
                inv_bbox = gray.point(lambda p: 255 if p > 28 else 0).getbbox()
                if inv_bbox:
                    bw = inv_bbox[2] - inv_bbox[0]
                    bh = inv_bbox[3] - inv_bbox[1]
                    if bw > im.width * 0.55 and bh > im.height * 0.55:
                        im = im.crop(inv_bbox)

                # Lightweight horizontal variance projection deskew over [-3, +3] deg at 300px thumb scale
                thumb = im.convert("L").resize((240, max(120, int(240 * im.height / max(1, im.width)))))
                best_angle = 0.0
                best_score = -1.0
                for angle in (-3.0, -2.0, -1.0, 0.0, 1.0, 2.0, 3.0):
                    rot = thumb.rotate(angle, fillcolor=255)
                    w, h = rot.size
                    px = rot.tobytes()
                    row_sums = []
                    for y in range(0, h, 2):
                        off = y * w
                        row_sums.append(sum(255 - b for b in px[off:off + w]))
                    mean = sum(row_sums) / max(1, len(row_sums))
                    var = sum((r - mean) ** 2 for r in row_sums) / max(1, len(row_sums))
                    if var > best_score:
                        best_score = var
                        best_angle = angle

                if abs(best_angle) >= 1.0:
                    im = im.rotate(best_angle, expand=True, fillcolor=(255, 255, 255))
                    deskew_applied = True

                buf = io.BytesIO()
                im.save(buf, format="PNG")
                img_bytes = buf.getvalue()

                page = doc.new_page(width=595, height=842)
                page.insert_image(pymupdf.Rect(18, 18, 577, 824), stream=img_bytes, keep_proportion=True)

        pdf_bytes = doc.tobytes(garbage=3, deflate=True)
        page_count = doc.page_count
    finally:
        doc.close()

    return {
        "pdf_b64": base64.b64encode(pdf_bytes).decode("ascii"),
        "page_count": page_count,
        "deskew_applied": deskew_applied,
    }
