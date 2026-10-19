FROM php:8.5-apache
ARG APP_UID=1000
ARG APP_GID=1000
COPY docker/setup-dev-container.sh /tmp/setup-dev-container.sh
RUN bash /tmp/setup-dev-container.sh && rm /tmp/setup-dev-container.sh
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
