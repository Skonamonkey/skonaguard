#!/usr/bin/env bash
set -euo pipefail

if [ ! -t 0 ]; then
    echo ""
    echo "  ERROR: This installer requires an interactive terminal."
    echo "  Running via 'curl | bash' pipes stdin away from the terminal."
    echo ""
    echo "  Please run it like this instead:"
    echo ""
    echo "    curl -fsSL https://raw.githubusercontent.com/Skonamonkey/skonaguard/main/install.sh -o /tmp/skonaguard-install.sh"
    echo "    bash /tmp/skonaguard-install.sh"
    echo ""
    exit 1
fi

REPO="Skonamonkey/skonaguard"
INSTALL_DIR="/srv/skonaguard"
COMPOSE_URL="https://raw.githubusercontent.com/${REPO}/main/docker-compose.yml"

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

echo -e "${CYAN}${BOLD}"
echo "  ╔═══════════════════════════════════════╗"
echo "  ║           SkonaGuard Setup            ║"
echo "  ║    Self-hosted WireGuard VPN Manager  ║"
echo "  ╚═══════════════════════════════════════╝"
echo -e "${NC}"

info()  { echo -e "  ${GREEN}✓${NC}  $*"; }
warn()  { echo -e "  ${RED}⚠${NC}  $*"; }

# ── Prerequisites ──────────────────────────────────────────────────────────────

if [ "$EUID" -ne 0 ]; then
    warn "Please run as root: sudo bash install.sh"
    exit 1
fi

for cmd in docker curl; do
    if ! command -v "$cmd" &>/dev/null; then
        warn "$cmd is required but not installed."
        [ "$cmd" = "docker" ] && echo "      Install with: curl -fsSL https://get.docker.com | sh"
        [ "$cmd" = "curl" ]   && echo "      Install with: apt-get install -y curl"
        exit 1
    fi
done

if ! docker compose version &>/dev/null 2>&1; then
    warn "Docker Compose v2 not found. Please upgrade Docker."
    echo "      https://docs.docker.com/compose/migrate/"
    exit 1
fi

# ── Installation directory ─────────────────────────────────────────────────────

if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/.env" ]; then
    warn "Existing installation found at ${INSTALL_DIR}."
    read -rp "  Overwrite config? Data will NOT be deleted. (y/N): " overwrite
    [[ "$overwrite" =~ ^[Yy]$ ]] || { echo "  Aborted."; exit 0; }
fi

mkdir -p "$INSTALL_DIR/data/wireguard" "$INSTALL_DIR/data/database"
info "Directory ready: ${INSTALL_DIR}"

# ── Download compose file ──────────────────────────────────────────────────────

echo ""
echo -e "${BLUE}Downloading docker-compose.yml...${NC}"
curl -fsSL "$COMPOSE_URL" -o "$INSTALL_DIR/docker-compose.yml"
info "docker-compose.yml downloaded"

# ── Configuration ──────────────────────────────────────────────────────────────

echo ""
echo -e "${BLUE}Detecting your server's public IP...${NC}"
DETECTED_IP=$(curl -s --max-time 5 ifconfig.me || curl -s --max-time 5 api.ipify.org || echo "")

if [ -n "$DETECTED_IP" ]; then
    echo -e "  Detected: ${BOLD}$DETECTED_IP${NC}"
    read -rp "  Is this correct? [Y/n]: " CONFIRM_IP
    if [[ "$CONFIRM_IP" =~ ^[Nn]$ ]]; then
        read -rp "  Enter your server's public IP: " SERVER_IP
    else
        SERVER_IP=$DETECTED_IP
    fi
else
    read -rp "  Could not detect IP. Enter your server's public IP: " SERVER_IP
fi

echo ""
read -rp "  WireGuard port [51820]: " WG_PORT
WG_PORT=${WG_PORT:-51820}

read -rp "  VPN subnet [172.16.0.0/16]: " WG_SUBNET
WG_SUBNET=${WG_SUBNET:-172.16.0.0/16}

read -rp "  UI port [8080]: " UI_PORT
UI_PORT=${UI_PORT:-8080}

APP_SECRET=$(dd if=/dev/urandom bs=1 count=128 2>/dev/null | base64 | tr -dc 'a-zA-Z0-9' | cut -c1-64)
WG_SUBNET_HUB=$(echo "$WG_SUBNET" | sed 's/\.[0-9]*\/[0-9]*/\.1/')

cat > "$INSTALL_DIR/.env" <<EOF
APP_ENV=production
APP_URL=http://${SERVER_IP}:${UI_PORT}
APP_SECRET=${APP_SECRET}

WG_PORT=${WG_PORT}
WG_SUBNET=${WG_SUBNET}
WG_SUBNET_HUB=${WG_SUBNET_HUB}
SERVER_PUBLIC_IP=${SERVER_IP}

UI_PORT=${UI_PORT}
UI_BIND=0.0.0.0

SETUP_COMPLETE=false
EOF

info ".env written"

# ── Ensure skonaguard network exists for Nginx Proxy Manager integration ──────

if ! docker network inspect skonaguard &>/dev/null; then
    docker network create skonaguard &>/dev/null || true
    info "Created skonaguard Docker network"
else
    info "skonaguard Docker network already exists"
fi

# ── Pull image and start ───────────────────────────────────────────────────────

echo ""
echo -e "${BLUE}Pulling SkonaGuard image...${NC}"
cd "$INSTALL_DIR"
docker compose pull
info "Image pulled"

echo -e "${BLUE}Starting SkonaGuard...${NC}"
docker compose up -d
info "Container started"

# ── Host route for peer IP preservation ───────────────────────────────────────

echo ""
echo -e "${BLUE}Adding host route so VPN peer IPs appear in audit logs...${NC}"
CONTAINER_IP=$(docker inspect skonaguard --format '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' 2>/dev/null | tr ' ' '\n' | grep -v '^$' | head -1)
if [ -n "$CONTAINER_IP" ]; then
    ip route replace "${WG_SUBNET}" via "${CONTAINER_IP}" 2>/dev/null || true
    info "Route added: ${WG_SUBNET} via ${CONTAINER_IP}"
else
    warn "Could not detect container IP — host route not added"
fi

UNIT_FILE=/etc/systemd/system/skonaguard-route.service
cat > "$UNIT_FILE" << 'UNIT'
[Unit]
Description=SkonaGuard host route for VPN peer IP preservation
After=docker.service
Wants=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStartPre=/bin/sleep 3
ExecStart=/bin/bash -c 'WG_SUBNET=$(grep "^WG_SUBNET=" __INSTALL_DIR__/.env | cut -d= -f2); CIP=$(docker inspect skonaguard --format "{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}" 2>/dev/null | tr " " "\n" | grep -v "^$" | head -1); [ -n "$WG_SUBNET" ] && [ -n "$CIP" ] && ip route replace "$WG_SUBNET" via "$CIP"'
ExecStop=/bin/bash -c 'WG_SUBNET=$(grep "^WG_SUBNET=" __INSTALL_DIR__/.env | cut -d= -f2); [ -n "$WG_SUBNET" ] && ip route del "$WG_SUBNET" 2>/dev/null; true'

[Install]
WantedBy=multi-user.target
UNIT

sed -i "s|__INSTALL_DIR__|${INSTALL_DIR}|g" "$UNIT_FILE"
systemctl daemon-reload
systemctl enable skonaguard-route.service
info "Systemd unit enabled: skonaguard-route.service"

# ── Done ──────────────────────────────────────────────────────────────────────

echo ""
echo -e "${GREEN}${BOLD}SkonaGuard is running!${NC}"
echo ""
echo -e "  Open ${CYAN}http://${SERVER_IP}:${UI_PORT}${NC} to complete setup"
echo ""
echo -e "  ${BOLD}Note:${NC} Make sure port ${WG_PORT}/udp and ${UI_PORT}/tcp are open in your firewall."
echo ""
echo -e "  ${BOLD}Upgrading later:${NC}"
echo -e "    cd ${INSTALL_DIR} && docker compose pull && docker compose up -d"
echo ""
