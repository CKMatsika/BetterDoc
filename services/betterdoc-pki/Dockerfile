FROM python:3.12-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    openssl \
    softhsm2 \
    libengine-pkcs11-openssl \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/pki

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY bootstrap-pki.sh /usr/local/bin/bootstrap-pki.sh
RUN chmod +x /usr/local/bin/bootstrap-pki.sh

COPY app ./app

EXPOSE 8004

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8004"]
