#!/bin/bash
set -e

DB_PATH="/app/database/skonaguard.db"
HOSTS_FILE="/etc/dnsproxy/skonaguard.hosts"

wait_for_db() {
    local count=0
    while [ ! -f "$DB_PATH" ] && [ $count -lt 30 ]; do
        sleep 1
        count=$((count + 1))
    done
}

wait_for_db

if [ ! -f "$DB_PATH" ]; then
    echo "[dns] Database not found — DNS staying dormant"
    exec sleep infinity
fi

DNS_ENABLED=$(sqlite3 "$DB_PATH" "SELECT value FROM settings WHERE key='dns_enabled'" 2>/dev/null || echo "0")

if [ "$DNS_ENABLED" != "1" ]; then
    echo "[dns] DNS disabled — staying dormant"
    exec sleep infinity
fi

DNS_DOMAIN=$(sqlite3 "$DB_PATH" "SELECT value FROM settings WHERE key='dns_domain'" 2>/dev/null || echo "skona")
DNS_UPSTREAM=$(sqlite3 "$DB_PATH" "SELECT value FROM settings WHERE key='dns_upstream'" 2>/dev/null || echo "9.9.9.9")
WG_HUB_IP=$(ip -4 addr show wg0 2>/dev/null | grep -E 'inet ' | awk '{print $2}' | cut -d/ -f1 | head -1)
WG_HUB_IP="${WG_HUB_IP:-${WG_SUBNET_HUB:-172.16.0.1}}"

php /app/scripts/generate_dns_hosts.php 2>/dev/null || true

if [ ! -f "$HOSTS_FILE" ]; then
    touch "$HOSTS_FILE"
fi

echo "[dns] Starting dnsproxy — domain=${DNS_DOMAIN} upstream=${DNS_UPSTREAM} listen=${WG_HUB_IP}:53"

HOSTS_ARG=""
if [ -s "${HOSTS_FILE}" ]; then
    HOSTS_ARG="--hosts-files=${HOSTS_FILE}"
fi

exec dnsproxy \
    -l "${WG_HUB_IP}" \
    -p 53 \
    -u "${DNS_UPSTREAM}" \
    --bootstrap=9.9.9.9:53 \
    --bootstrap=8.8.8.8:53 \
    ${HOSTS_ARG} \
    --cache \
    --cache-size=4096
