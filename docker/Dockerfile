FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
		nginx \
		supervisor \
		curl \
		git \
		unzip \
		openssl \
	&& docker-php-ext-install \
		pdo_mysql \
		opcache \
	&& pecl install redis \
	&& docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/ ./
RUN composer dump-autoload --optimize

COPY docker/nginx.conf        /etc/nginx/nginx.conf
COPY docker/supervisord.conf  /etc/supervisord.conf
COPY docker/php-fpm.conf      /usr/local/etc/php-fpm.d/www.conf

RUN mkdir -p /app/var/log && chown -R www-data:www-data /app/var

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
