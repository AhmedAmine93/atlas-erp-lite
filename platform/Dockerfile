FROM python:3.12-slim-bookworm
WORKDIR /app
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY atlas_platform/ ./atlas_platform/
RUN useradd --system --uid 10001 platform
USER platform
CMD ["python", "-m", "atlas_platform.publisher"]
