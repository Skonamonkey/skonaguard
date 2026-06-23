#!/bin/sh
set -e

export WG_QUICK_USERSPACE_IMPLEMENTATION=wireguard-go
export WG_I_PREFER_BUGGY_USERSPACE_TO_POLISHED_KMOD=1

wg-quick down wg0 2>/dev/null || true

wg-quick up /etc/wireguard/wg0.conf

while ip link show wg0 > /dev/null 2>&1; do
    sleep 10
done
