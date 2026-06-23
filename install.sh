#!/bin/bash
set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

clear
echo -e "${CYAN}${BOLD}"
echo "  ╔═══════════════════════════════════════╗"
echo "  ║           SkonaGuard Setup            ║"
echo "  ║    Self-hosted WireGuard VPN Manager  ║"
echo "  ╚═══════════════════════════════════════╝"
echo -e "${NC}"

if ! command -v docker &>/dev/null; then
    echo -e "${RED}Docker is not installed. Please install Docker first.${NC}"
    echo "  https://docs.docker.com/engine/install/"
    exit 1
fi

if ! command -v docker compose &>/dev/null && ! docker compose version &>/dev/null 2>&1; then
    echo -e "${RED}Docker Compose is not installed.${NC}"
    exit 1
fi

echo -e "${BLUE}Detecting your server's public IP...${NC}"
DETECTED_IP=$(curl -s --max-time 5 ifconfig.me || curl -s --max-time 5 api.ipify.org || echo "")

if [ -n "$DETECTED_IP" ]; then
    echo -e "  Detected: ${BOLD}$DETECTED_IP${NC}"
    read -p "  Is this correct? [Y/n]: " CONFIRM_IP
    if [[ "$CONFIRM_IP" =~ ^[Nn]$ ]]; then
        read -p "  Enter your server's public IP: " SERVER_IP
    else
        SERVER_IP=$DETECTED_IP
    fi
else
    read -p "  Could not detect IP. Enter your server's public IP: " SERVER_IP
fi

echo ""
read -p "  WireGuard port [51820]: " WG_PORT
WG_PORT=${WG_PORT:-51820}

read -p "  VPN subnet [172.16.0.0/16]: " WG_SUBNET
WG_SUBNET=${WG_SUBNET:-172.16.0.0/16}

read -p "  UI port [8080]: " UI_PORT
UI_PORT=${UI_PORT:-8080}

APP_SECRET=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 64 | head -n 1)

cat > .env <<EOF
APP_ENV=production
APP_URL=http://${SERVER_IP}:${UI_PORT}
APP_SECRET=${APP_SECRET}

WG_PORT=${WG_PORT}
WG_SUBNET=${WG_SUBNET}
WG_SUBNET_HUB=$(echo $WG_SUBNET | sed 's/\.[0-9]*\/[0-9]*/\.1/')
SERVER_PUBLIC_IP=${SERVER_IP}

UI_PORT=${UI_PORT}
UI_BIND=0.0.0.0

SETUP_COMPLETE=false
EOF

mkdir -p data/wireguard data/database

echo ""
echo -e "${BLUE}Starting SkonaGuard...${NC}"
docker compose up -d --build

echo ""
echo -e "${GREEN}${BOLD}SkonaGuard is running!${NC}"
echo ""
echo -e "  Open ${CYAN}http://${SERVER_IP}:${UI_PORT}${NC} to complete setup"
echo ""
echo -e "${BOLD}Note:${NC} Make sure port ${WG_PORT}/udp and ${UI_PORT}/tcp are open in your firewall."
echo ""
