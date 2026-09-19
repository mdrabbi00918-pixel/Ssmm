FROM php:8.3-apache

ENV PORT=10000

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/* \
 && sed -ri 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
 && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf \
 && a2enmod headers rewrite

COPY . /var/www/html/

RUN mkdir -p /var/www/html/data \
 && chown -R www-data:www-data /var/www/html/data \
 && chmod 775 /var/www/html/data

EXPOSE 10000
