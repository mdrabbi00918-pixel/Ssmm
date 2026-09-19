FROM php:8.3-apache

# Install only the build dependencies required by the PHP extensions.
# Retries make Render builds less sensitive to transient Debian mirror failures.
RUN set -eux; \
    apt-get update -o Acquire::Retries=5; \
    apt-get install -y --no-install-recommends libsqlite3-dev libcurl4-openssl-dev ca-certificates; \
    docker-php-ext-install -j"$(nproc)" pdo_sqlite sqlite3 curl; \
    rm -rf /var/lib/apt/lists/*

ENV PORT=10000

RUN sed -ri 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf && \
    sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/

RUN mkdir -p /var/www/html/data && \
    chown -R www-data:www-data /var/www/html/data && \
    chmod 775 /var/www/html/data

EXPOSE 10000
