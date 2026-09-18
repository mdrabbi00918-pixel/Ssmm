FROM php:8.3-apache
RUN docker-php-ext-install curl
COPY . /var/www/html/
EXPOSE 80
