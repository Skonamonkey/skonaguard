FROM golang:1.23-alpine AS wggo-builder
RUN apk add --no-cache git
RUN git clone https://github.com/WireGuard/wireguard-go.git /tmp/wireguard-go \
    && cd /tmp/wireguard-go \
    && go build -o /usr/local/bin/wireguard-go .

FROM golang:1.23-alpine AS dnsproxy-builder
ARG TARGETARCH=amd64
RUN apk add --no-cache git
RUN DNSPROXY_VER=0.81.2 && \
    ARCH="${TARGETARCH}" && \
    wget -qO /tmp/dnsproxy.tar.gz \
      "https://github.com/AdguardTeam/dnsproxy/releases/download/v${DNSPROXY_VER}/dnsproxy-linux-${ARCH}-v${DNSPROXY_VER}.tar.gz" && \
    tar -xzf /tmp/dnsproxy.tar.gz -C /tmp && \
    mv /tmp/linux-${ARCH}/dnsproxy /usr/local/bin/dnsproxy && \
    chmod +x /usr/local/bin/dnsproxy

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
    sqlite-dev \
    && docker-php-ext-install pdo pdo_sqlite

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY --from=wggo-builder /usr/local/bin/wireguard-go /usr/local/bin/wireguard-go
COPY --from=dnsproxy-builder /usr/local/bin/dnsproxy /usr/local/bin/dnsproxy

WORKDIR /app

COPY app/composer.json app/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || \
    composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

COPY app/ .
COPY .env.example /app/.env.example

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY scripts/ /app/scripts/
COPY scripts/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh /app/scripts/wg-start.sh /app/scripts/dns-start.sh \
    && mkdir -p /var/run/wireguard /etc/wireguard /app/database \
    && chmod 700 /etc/wireguard

EXPOSE 51820/udp
EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
