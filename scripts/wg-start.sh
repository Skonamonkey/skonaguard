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

while ip link show wg0 > /dev/null 2>&1; do
    sleep 10
done
