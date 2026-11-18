"""True destructive PDF redaction & offline HTML-to-PDF rendering using PyMuPDF."""

from __future__ import annotations

import hashlib
import html
import tempfile
from pathlib import Path
from typing import Any

import pymupdf


def redact_pdf(
    pdf_bytes: bytes,
    boxes: list[dict[str, Any]],
    scrub_metadata: bool = True,
) -> dict[str, Any]:
    """Physically destroy text glyphs, vector graphics, and image pixels inside normalized redaction boxes."""
    doc = pymupdf.open(stream=pdf_bytes, filetype="pdf")
    applied_count = 0

    try:
        for box in boxes:
            page_num = int(box.get("page_number", 1))
            if page_num < 1 or page_num > doc.page_count:
                continue

            page = doc.load_page(page_num - 1)
            rect = page.rect

            norm_x = max(0.0, min(1.0, float(box.get("x", 0.0))))
            norm_y = max(0.0, min(1.0, float(box.get("y", 0.0))))
            norm_w = max(0.005, min(1.0 - norm_x, float(box.get("width", 0.1))))
            norm_h = max(0.005, min(1.0 - norm_y, float(box.get("height", 0.05))))

            x0 = rect.x0 + norm_x * rect.width
            y0 = rect.y0 + norm_y * rect.height
            x1 = rect.x0 + (norm_x + norm_w) * rect.width
            y1 = rect.y0 + (norm_y + norm_h) * rect.height

            redact_rect = pymupdf.Rect(x0, y0, x1, y1)
            page.add_redact_annot(redact_rect, fill=(0, 0, 0), text="")
            applied_count += 1

        for page_idx in range(doc.page_count):
            page = doc.load_page(page_idx)
            page.apply_redactions(
                images=pymupdf.PDF_REDACT_IMAGE_PIXELS,
                graphics=pymupdf.PDF_REDACT_LINE_ART_REMOVE_IF_TOUCHED,
                text=pymupdf.PDF_REDACT_TEXT_REMOVE,
            )

        if scrub_metadata:
            doc.set_metadata({"producer": "BetterDoc DMS True Redaction Engine"})
            try:
                doc.del_xml_metadata()
            except Exception:
                pass

        redacted_bytes = doc.tobytes(garbage=4, deflate=True, clean=True)
        page_count = doc.page_count
    finally:
        doc.close()

    # Re-open sanitized bytes cleanly to verify post-redaction extracted text
    verify_doc = pymupdf.open(stream=redacted_bytes, filetype="pdf")
    try:
        extracted_pages = [verify_doc.load_page(i).get_text("text").strip() for i in range(verify_doc.page_count)]
    finally:
        verify_doc.close()

    extracted_text = "\n\n".join(p for p in extracted_pages if p)

    return {
        "redacted_pdf_bytes": redacted_bytes,
        "sha256_hash": hashlib.sha256(redacted_bytes).hexdigest(),
        "page_count": page_count,
        "applied_boxes": applied_count,
        "extracted_text": extracted_text,
    }


def render_html_to_pdf(
    html_body: str,
    title: str,
    classification_banner: str = "",
    doc_number: str = "",
) -> dict[str, Any]:
    """Render an offline HTML document template to PDF using PyMuPDF Story + DocumentWriter."""
    safe_banner = html.escape(classification_banner)
    safe_title = html.escape(title)
    safe_doc_no = html.escape(doc_number)

    styled_html = f"""
    <html>
    <head>
        <style>
            body {{ font-family: Helvetica, sans-serif; font-size: 11pt; line-height: 1.45; color: #0f172a; }}
            .banner {{ text-align: center; font-size: 9pt; font-weight: bold; letter-spacing: 1px; color: #b91c1c; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px; margin-bottom: 14px; }}
            .meta {{ font-size: 9pt; color: #475569; margin-bottom: 12px; }}
            h1 {{ font-size: 16pt; color: #0f172a; margin-bottom: 8px; }}
            table {{ width: 100%; border-collapse: collapse; margin: 10px 0; }}
            th, td {{ border: 1px solid #cbd5e1; padding: 5px 8px; font-size: 10pt; text-align: left; }}
        </style>
    </head>
    <body>
        {f'<div class="banner">{safe_banner}</div>' if safe_banner else ''}
        {f'<div class="meta">Reference: {safe_doc_no}</div>' if safe_doc_no else ''}
        <h1>{safe_title}</h1>
        {html_body}
    </body>
    </html>
    """

    story = pymupdf.Story(html=styled_html)
    mediabox = pymupdf.paper_rect("a4")
    where = mediabox + (48, 48, -48, -48)

    with tempfile.TemporaryDirectory() as tmpdir:
        tmp_path = Path(tmpdir) / "rendered.pdf"
        writer = pymupdf.DocumentWriter(str(tmp_path))
        more = True
        while more:
            dev = writer.begin_page(mediabox)
            more, _filled = story.place(where)
            story.draw(dev, None)
            writer.end_page()
        writer.close()
        del writer

        pdf_bytes = tmp_path.read_bytes()

    check_doc = pymupdf.open(stream=pdf_bytes, filetype="pdf")
    try:
        page_count = check_doc.page_count
        extracted_text = "\n\n".join(check_doc.load_page(i).get_text("text").strip() for i in range(page_count)).strip()
    finally:
        check_doc.close()

    return {
        "pdf_bytes": pdf_bytes,
        "sha256_hash": hashlib.sha256(pdf_bytes).hexdigest(),
        "page_count": page_count,
        "extracted_text": extracted_text,
    }
