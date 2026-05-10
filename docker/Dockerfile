FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
		nginx \
		supervisor \
		curl \
		git \
		unzip \
		openssl \
	&& apk add --no-cache --virtual .build-deps \
		$PHPIZE_DEPS \
		autoconf \
		gcc \
		g++ \
		make \
		pkgconfig \
	&& docker-php-ext-install \
		pdo_mysql \
		opcache \
	&& pecl install redis \
	&& docker-php-ext-enable redis \
	&& apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/ ./
RUN composer dump-autoload --optimize \
	&& cp config/config.example.php config/config.php

COPY docker/nginx.conf         /etc/nginx/nginx.conf
COPY docker/supervisord.conf   /etc/supervisord.conf
COPY docker/php-fpm.conf       /usr/local/etc/php-fpm.d/www.conf
COPY docker/entrypoint-init.sh /usr/local/bin/entrypoint-init.sh
RUN  chmod +x /usr/local/bin/entrypoint-init.sh

RUN mkdir -p /app/var/log && chown -R www-data:www-data /app/var

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint-init.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
