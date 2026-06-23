FROM golang:1.23-alpine AS wggo-builder
RUN apk add --no-cache git
RUN go install golang.zx2c4.com/wireguard-go@latest

FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    bash \
    iptables \
    iproute2 \
    wireguard-tools \
    sqlite \
    && docker-php-ext-install pdo pdo_sqlite

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY --from=wggo-builder /go/bin/wireguard-go /usr/local/bin/wireguard-go

WORKDIR /app

COPY app/composer.json app/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || \
    composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

COPY app/ .

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /etc/php82/php-fpm.d/www.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY scripts/ /app/scripts/
COPY scripts/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && mkdir -p /var/run/wireguard /etc/wireguard /app/database \
    && chmod 700 /etc/wireguard

EXPOSE 51820/udp
EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
