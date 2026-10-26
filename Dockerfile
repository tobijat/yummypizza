FROM php:8.5-apache

RUN docker-php-ext-install pdo_mysql \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/yummypizza.conf \
    && a2enconf yummypizza

COPY . /var/www/html
