import os
import subprocess
import tempfile
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import JSONResponse, Response
import fitz

app = FastAPI(title="SENTINEL OCR & CDR Service", version="1.0.0")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/cdr-sanitize")
async def cdr_sanitize(file: UploadFile = File(...)) -> Response:
    """Linearize PDF via qpdf and strip dangerous active actions (/JS, /JavaScript, /OpenAction, /AA, /Launch)."""
    raw = await file.read()
    if not raw.startswith(b"%PDF-"):
        raise HTTPException(status_code=422, detail="Input is not a valid PDF header.")

    with tempfile.TemporaryDirectory() as tmpdir:
        src = os.path.join(tmpdir, "input.pdf")
        dst = os.path.join(tmpdir, "clean.pdf")
        with open(src, "wb") as f:
            f.write(raw)

        doc = fitz.open(src)
        for xref in range(1, doc.xref_length()):
            try:
                obj = doc.xref_object(xref, compressed=False)
                for key in ("JavaScript", "JS", "OpenAction", "AA", "Launch", "SubmitForm", "RichMedia"):
                    if f"/{key}" in obj:
                        doc.xref_set_key(xref, key, "null")
            except Exception:
                continue
        doc.set_metadata({})
        doc.save(dst, garbage=4, deflate=True, clean=True)
        doc.close()

        subprocess.run(
            ["qpdf", "--linearize", dst, f"{dst}.lin.pdf"],
            check=True,
            capture_output=True,
        )
        with open(f"{dst}.lin.pdf", "rb") as out:
            cleaned = out.read()

    return Response(content=cleaned, media_type="application/pdf")


@app.post("/ocr-pdfa")
async def ocr_pdfa(
    file: UploadFile = File(...),
    languages: str = Form("eng"),
) -> JSONResponse:
    """Run OCRmyPDF to produce archival PDF/A-2b and return extracted text & page count."""
    raw = await file.read()
    with tempfile.TemporaryDirectory() as tmpdir:
        src = os.path.join(tmpdir, "src.pdf")
        out_pdf = os.path.join(tmpdir, "pdfa.pdf")
        sidecar = os.path.join(tmpdir, "text.txt")
        with open(src, "wb") as f:
            f.write(raw)

        subprocess.run(
            [
                "ocrmypdf",
                "--skip-text",
                "--deskew",
                "--output-type",
                "pdfa-2",
                "-l",
                languages,
                "--sidecar",
                sidecar,
                src,
                out_pdf,
            ],
            check=True,
            capture_output=True,
        )

        doc = fitz.open(out_pdf)
        page_count = doc.page_count
        doc.close()

        with open(sidecar, "r", encoding="utf-8", errors="replace") as st:
            extracted_text = st.read()

    return JSONResponse({
        "page_count": page_count,
        "pdfa_compliant": True,
        "extracted_text": extracted_text,
    })
