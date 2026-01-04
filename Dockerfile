FROM php:8.4-alpine

RUN apk add --no-cache git libxml2-dev
COPY . .

RUN rm -f /data/configurations/*

RUN docker-php-ext-install xml dom && \
     curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev
RUN composer clearcache

EXPOSE 8080

ENTRYPOINT [ "php", "-S", "0.0.0.0:8080", "/app/index.php" ]  
