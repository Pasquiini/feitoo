FROM ubuntu:26.04

# Disposable Linux test runtime; no production configuration or application data.
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    php-cli php-gd php-mbstring php-xml php-sqlite3 php-curl php-zip php-bcmath \
    bubblewrap util-linux ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
USER www-data
