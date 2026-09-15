# syntax=docker/dockerfile:1.7

FROM python:3.14-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    TZ=Europe/Moscow

RUN apt-get update \
    && apt-get install -y --no-install-recommends tzdata \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY . /app

# Webhook mode (BOT_MODE=webhook) serves the aiohttp receiver on WEBHOOK_PORT.
# Metadata only — publish/map it in compose when running in webhook mode.
EXPOSE 8080

CMD ["python", "main.py"]
