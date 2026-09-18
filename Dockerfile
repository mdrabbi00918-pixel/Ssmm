FROM php:8.3-apache

RUN if ! php -m | grep -qi '^curl$'; then       apt-get update &&       apt-get install -y --no-install-recommends libcurl4-openssl-dev &&       docker-php-ext-install curl &&       rm -rf /var/lib/apt/lists/*;     fi

ENV PORT=10000

RUN sed -ri 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf &&     sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/

RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data

EXPOSE 10000
