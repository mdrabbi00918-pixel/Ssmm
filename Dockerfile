FROM php:8.3-apache

# curl is commonly already included in the official PHP image.
# Only try to build it when it is not already loaded.
RUN if ! php -m | grep -qi '^curl$'; then \
      apt-get update && \
      apt-get install -y --no-install-recommends libcurl4-openssl-dev && \
      docker-php-ext-install curl && \
      rm -rf /var/lib/apt/lists/*; \
    fi

# Render web services use PORT=10000 by default.
ENV PORT=10000

RUN sed -ri 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf && \
    sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/

EXPOSE 10000
