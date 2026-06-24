#!/bin/sh
set -e

wg-quick down wg0 2>/dev/null || true

if [ -f /sys/module/wireguard/version ] || modprobe wireguard 2>/dev/null; then
    wg-quick up /etc/wireguard/wg0.conf
else
    export WG_QUICK_USERSPACE_IMPLEMENTATION=wireguard-go
    export WG_I_PREFER_BUGGY_USERSPACE_TO_POLISHED_KMOD=1
    wg-quick up /etc/wireguard/wg0.conf
fi

WG_HOST_IP="${WG_HOST_IP:-172.16.0.2}"
DOCKER_GW=$(ip route | awk '/default/ {print $3; exit}')

if [ -n "$DOCKER_GW" ]; then
    echo "$DOCKER_GW" > /tmp/docker_gw
    ip addr add "${WG_HOST_IP}/32" dev wg0 2>/dev/null || true
    iptables -t nat -A PREROUTING -i wg0 -d "${WG_HOST_IP}" -j LOG --log-prefix "SKONAHOST-ACCESS: " --log-level 4
    iptables -t nat -A PREROUTING -i wg0 -d "${WG_HOST_IP}" -j DNAT --to-destination "${DOCKER_GW}"
    iptables -t nat -A POSTROUTING -d "${DOCKER_GW}" -j MASQUERADE
fi

php /app/scripts/sync_acl.php 2>/dev/null || true

while ip link show wg0 > /dev/null 2>&1; do
    sleep 10
done
