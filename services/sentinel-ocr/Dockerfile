FROM python:3.12-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    tesseract-ocr \
    tesseract-ocr-eng \
    ocrmypdf \
    qpdf \
    ghostscript \
    libzbar0 \
    poppler-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/ocr

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app

EXPOSE 8002

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8002"]
