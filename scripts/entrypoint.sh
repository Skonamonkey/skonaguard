#!/bin/bash
set -e

DATA_DIR=/app/database
DB_FILE=$DATA_DIR/skonaguard.db
ENV_FILE=/app/.env

if [ ! -f "$ENV_FILE" ]; then
    if [ -f /app/.env.example ]; then
        cp /app/.env.example "$ENV_FILE"
    else
        touch "$ENV_FILE"
    fi
fi

if [ ! -d "$DATA_DIR" ]; then
    mkdir -p "$DATA_DIR"
fi

if [ ! -f "$DB_FILE" ]; then
    php /app/scripts/migrate.php
fi

if [ ! -f /etc/wireguard/wg0.conf ]; then
    wg genkey | tee /tmp/wg_private | wg pubkey > /tmp/wg_public
    WG_PRIVATE=$(cat /tmp/wg_private)
    WG_PUBLIC=$(cat /tmp/wg_public)
    rm -f /tmp/wg_private /tmp/wg_public

    php /app/scripts/init_keys.php "$WG_PRIVATE" "$WG_PUBLIC"

    cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
PrivateKey = $WG_PRIVATE
Address = ${WG_SUBNET_HUB:-172.16.0.1}/16
ListenPort = ${WG_PORT:-51820}
EOF
    chmod 600 /etc/wireguard/wg0.conf
fi

exec /usr/bin/supervisord -c /etc/supervisord.conf
