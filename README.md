# SkonaGuard

**Self-hosted WireGuard VPN manager with a web UI — fully contained in a single Docker image.**

SkonaGuard provides a clean management interface for WireGuard VPN networks. It handles peer creation, key generation, config distribution, zone segmentation, ACL enforcement, and gateway routing — without requiring you to touch the WireGuard CLI after initial setup.

> Built by [Skonamonkey](https://www.skonamonkey.co.uk) as an open alternative to tools like wg-easy, designed for structured multi-site deployments rather than single-network home use.

---

## Features

- **Guided setup wizard** — public IP detection, subnet config, and admin account creation on first run
- **Zones** — logical network segments (e.g. Home LAN, Office 1, Core Infra), each assigned their own VPN subnet
- **Peers** — per-device WireGuard configs with auto key generation, IP allocation, QR code, and one-time download tokens
- **Profiles** — peer config templates; select a profile when adding a peer to inherit zone, allowed IPs, gateway settings, and DNS automatically. Update a profile to cascade changes to all linked peers on their next config download
- **Gateway peers** — mark a peer as a site gateway to advertise a physical LAN subnet over the VPN. Subnet conflict detection warns (with full details) when two gateways would advertise overlapping routes
- **ACL engine** — iptables-backed access control between zones or individual IPs, with rule types covering full access, inbound-only, outbound-only, ICMP-only, and port-specific rules
- **Default policy** — global toggle between permissive (allow unless blocked) and restrictive (block unless allowed) stances
- **Raw chain viewer** — inspect live iptables packet counters per rule for troubleshooting
- **Live dashboard** — connected peer count, per-peer handshake age, endpoint, TX/RX updated every 10 seconds
- **LAN/WAN config endpoints** — generate peer configs pointing to either the public IP or a local LAN IP, for peers that may connect from either network
- **Tokenised download links** — create expiring share links so a peer can `curl` their config directly without logging into the UI
- **wg-quick auto-start** — WireGuard interface comes up automatically on container start with `wg-quick up wg0`

---

## Why it's designed this way

### wireguard-go (userspace) inside Docker

Standard WireGuard requires a kernel module (`wireguard.ko`). This works on most Linux servers but is unavailable in some environments — unprivileged LXC containers, certain VPS providers, older kernels, and non-Linux-native Docker hosts.

SkonaGuard builds `wireguard-go` from source inside the Docker image at build time. This is the official WireGuard userspace implementation in Go, maintained by the WireGuard project. It runs entirely in userspace via a TUN device, with no kernel module dependency.

**Trade-off:** wireguard-go has a small performance penalty vs the kernel module (typically single-digit % on modern hardware, negligible for most deployments). On hosts where the kernel module is available, `wg-quick` will prefer it automatically.

### PHP 8.2 + Slim + Twig

The UI and API are PHP — deliberately chosen over Python or Node for portability and self-hostability. PHP-FPM is mature, well-understood, and has minimal runtime overhead. Slim is a micro-framework with no magic; Twig handles templating. The entire dependency tree is small and auditable via Composer.

### SQLite

No external database service required. SkonaGuard stores all configuration (peers, zones, profiles, ACL rules, settings, download tokens) in a single SQLite file at `./data/database/skonaguard.db`, which is volume-mounted outside the container and survives rebuilds. This makes backup trivial (copy one file) and makes the project genuinely portable — no MySQL, no Redis, no extra containers.

### iptables for ACL enforcement

WireGuard itself has no concept of access control between peers — it only controls which traffic is encrypted and routed. ACL enforcement is implemented as a dedicated `SKONAGUARD` iptables chain, inserted into `FORWARD` before any permissive rules. The chain is rebuilt from the database on every peer sync and on container start, so the firewall state always reflects what's in the UI. The chain is cleanly removed on `wg-quick down`.

### Single Docker image

Everything — nginx, php-fpm, WireGuard, supervisord — runs in one container. This avoids inter-container networking complexity for what is fundamentally a system-level network tool, and means the entire stack can be installed with a single `install.sh` invocation.

---

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                  Docker Container                    │
│                                                      │
│  ┌─────────┐    ┌──────────┐    ┌─────────────────┐ │
│  │  nginx  │───▶│ php-fpm  │───▶│  Slim/Twig app  │ │
│  │ :8080   │    │          │    │  /app/src/       │ │
│  └─────────┘    └──────────┘    └────────┬────────┘ │
│                                           │          │
│                               ┌───────────▼────────┐ │
│                               │  WireGuardService  │ │
│                               │  wg syncconf       │ │
│                               │  iptables          │ │
│                               └───────────┬────────┘ │
│                                           │          │
│                               ┌───────────▼────────┐ │
│                               │   wireguard-go     │ │
│                               │   wg0 TUN device   │ │
│                               └───────────┬────────┘ │
└───────────────────────────────────────────┼──────────┘
                                            │
                                    UDP :51820
                                  (WireGuard tunnel)
```

**Volumes:**
- `./data/wireguard` → `/etc/wireguard` — `wg0.conf` and keys (survives rebuild)
- `./data/database` → `/app/database` — SQLite database (survives rebuild)

---

## Deployment scenarios

### Scenario 1 — VPS as hub (recommended for multi-site)

The most common setup. A public-facing VPS acts as the WireGuard hub. All peers connect outbound to the VPS — no inbound ports need to be opened on any site.

```
Internet
    │
    ▼
┌───────────┐         WireGuard tunnel (UDP 51820)
│ IONOS VPS │◄────────────────────────────────────┐
│ SkonaGuard│◄──────────────────────────┐         │
│ 172.16.0.1│◄──────────────┐           │         │
└───────────┘               │           │         │
                     ┌──────┴───┐  ┌────┴───┐  ┌──┴─────┐
                     │ Home LAN │  │Office 1│  │Office 2│
                     │ Gateway  │  │Gateway │  │Gateway │
                     │172.16.1.2│  │172.16.2│  │172.16.3│
                     └──────────┘  └────────┘  └────────┘
```

Each site gateway peer advertises its physical LAN subnet over the tunnel. Individual machines on each LAN can then route `172.16.0.0/16` via the gateway without having WireGuard installed themselves — add a static route on your router pointing the VPN subnet to the gateway machine's LAN IP.

### Scenario 2 — Proxmox host as LAN gateway

Install WireGuard directly on a Proxmox host (or a dedicated LXC/VM). Enable IP forwarding (`net.ipv4.ip_forward=1`). The Proxmox host acts as the gateway for everything on `vmbr0`. Individual VMs and containers gain VPN access without WireGuard installed on each one.

```bash
# On Proxmox host
echo "net.ipv4.ip_forward=1" >> /etc/sysctl.conf && sysctl -p
curl -o /etc/wireguard/wg0.conf https://your-skonaguard/dl/<token>
wg-quick up wg0
systemctl enable wg-quick@wg0
```

Add a static route on your LAN router: destination `172.16.0.0/16`, gateway = Proxmox host LAN IP.

### Scenario 3 — Per-device peers (no gateway)

Individual machines connect directly to the VPS. No gateway peer required. Each device downloads its own config (via QR code, tokenised link, or direct download), installs WireGuard, and connects. This is suitable for road warriors, remote workers, and any device you want on the VPN without routing an entire site through it.

### Scenario 4 — Behind Nginx Proxy Manager

SkonaGuard exposes its UI on port 8080. You can place it behind NPM (or any reverse proxy) for HTTPS and a clean domain:

```
https://skonaguard.yourdomain.com  →  http://skonaguard-container:8080
```

NPM can use the container name as the upstream if both are on the same Docker network. SkonaGuard respects `X-Forwarded-Proto` and `X-Forwarded-Host` headers for token URL generation.

---

## Requirements

| Requirement | Notes |
|---|---|
| Docker 24+ | With Compose V2 (`docker compose`) |
| Linux host | `NET_ADMIN` capability and `/dev/net/tun` device required |
| UDP port 51820 | Open in firewall/hardware firewall for WireGuard |
| TCP port 8080 | UI — can be changed via `UI_PORT`, or kept internal and proxied |

> **LXC containers:** Must be privileged, or have `lxc.cgroup2.devices.allow: c 10:200 rwm` and `lxc.mount.entry: /dev/net/tun dev/net/tun none bind,create=file` set in the container config.

---

## Quick start

```bash
git clone https://github.com/Skonamonkey/skonaguard.git
cd skonaguard
chmod +x install.sh
./install.sh
```

The install script:
1. Checks for Docker and Docker Compose
2. Auto-detects your server's public IP (with confirmation prompt)
3. Prompts for WireGuard port, VPN subnet, and UI port
4. Writes a `.env` file and generates a random `APP_SECRET`
5. Builds and starts the container

Then open `http://<your-server-ip>:8080` and complete the setup wizard.

---

## Manual setup

```bash
cp .env.example .env
# Edit .env — set SERVER_PUBLIC_IP, APP_SECRET, and adjust ports/subnet as needed
mkdir -p data/wireguard data/database
docker compose up -d --build
```

---

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `APP_URL` | `http://localhost:8080` | Public URL of the UI — used in token download links |
| `APP_SECRET` | *(required)* | Random string for session signing — generate with `openssl rand -hex 32` |
| `WG_PORT` | `51820` | UDP port WireGuard listens on |
| `WG_SUBNET` | `172.16.0.0/16` | VPN address space. Zones are carved out of this range |
| `WG_SUBNET_HUB` | `172.16.0.1` | VPN IP assigned to the server itself |
| `SERVER_PUBLIC_IP` | *(required)* | Public IP peers connect to — written into generated client configs |
| `UI_PORT` | `8080` | Host port the UI binds to |
| `UI_BIND` | `0.0.0.0` | Interface the UI binds to — set to `127.0.0.1` if behind a reverse proxy on the same host |

---

## Upgrading

```bash
cd /srv/skonaguard          # or wherever you cloned to
git pull origin main
docker compose build --no-cache
docker compose up -d
docker exec skonaguard php scripts/migrate.php
```

The database and WireGuard config are volume-mounted — they are never touched by a rebuild.

---

## Project structure

```
skonaguard/
├── app/
│   ├── public/          # Web root — assets, entry point (index.php)
│   ├── src/
│   │   ├── Controllers/ # Route handlers (Peers, Zones, Profiles, ACL, Settings, Dashboard...)
│   │   ├── Models/      # Database wrapper
│   │   ├── Middleware/  # Auth, flash messages
│   │   ├── Services/    # WireGuardService — key gen, config sync, ACL chain management
│   │   └── Routes/      # web.php — all route definitions
│   └── templates/       # Twig templates
├── docker/              # Dockerfile, nginx.conf, php-fpm config, supervisord
├── scripts/             # entrypoint.sh, migrate.php, wg-start.sh, init_keys.php
├── data/                # Runtime data — gitignored
│   ├── wireguard/       # wg0.conf and keys (volume-mounted)
│   └── database/        # skonaguard.db (volume-mounted)
├── docker-compose.yml
├── install.sh
└── .env.example
```

---

## Comparison with wg-easy

| | SkonaGuard | wg-easy |
|---|---|---|
| Language | PHP 8.2 / Slim / Twig | Node.js |
| Database | SQLite (file, no service) | JSON flat file |
| Zones / subnets | Yes — multiple zones with separate IP ranges | No |
| Profiles | Yes — peer config templates, mass-update on download | No |
| ACL engine | Yes — iptables chains, per-zone and per-IP rules, port rules | No |
| Gateway peers | Yes — with subnet conflict detection | Limited |
| Conflict detection | Yes — CIDR-aware, catches profile-inherited gateways | No |
| LAN/WAN endpoints | Yes — dual endpoint support per peer | No |
| Token download links | Yes — expiring, shareable | No |
| wireguard-go | Yes — built from source, no kernel module needed | Depends on host |
| Single container | Yes | Yes |

---

## Licence

MIT — see `LICENSE`.

> SkonaGuard is an independent project and is not affiliated with, endorsed by, or supported by the WireGuard project or ZX2C4.
