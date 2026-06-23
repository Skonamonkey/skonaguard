FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    bash \
    iptables \
    iproute2 \
    wireguard-tools-wg \
    sqlite \
    && docker-php-ext-install pdo pdo_sqlite

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

ENV WGGO_VERSION=0.0.20230223
RUN curl -sL "https://github.com/WireGuard/wireguard-go/releases/download/${WGGO_VERSION}/wireguard-go-${WGGO_VERSION}-linux-amd64.tar.gz" \
    | tar -xz -C /usr/local/bin --strip-components=1 \
    && chmod +x /usr/local/bin/wireguard-go

WORKDIR /app

COPY app/composer.json app/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

COPY app/ .

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /etc/php82/php-fpm.d/www.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY scripts/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && mkdir -p /var/run/wireguard /etc/wireguard /app/database \
    && chmod 700 /etc/wireguard

EXPOSE 51820/udp
EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
