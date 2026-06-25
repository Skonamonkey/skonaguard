<p align="center">
  <img src="app/public/assets/icon.svg" alt="SkonaGuard" width="120" />
</p>

<h1 align="center">SkonaGuard</h1>

<p align="center">
  <strong>Self-hosted WireGuard VPN manager. Zones, ACLs, DNS, multi-admin — one Docker container.</strong><br>
  Built on <a href="https://www.wireguard.com">WireGuard</a> and <a href="https://github.com/WireGuard/wireguard-go">wireguard-go</a>. Works with <strong>standard, unmodified WireGuard clients</strong> on
  <a href="https://www.wireguard.com/install/">Windows</a>,
  <a href="https://apps.apple.com/app/wireguard/id1451685025">macOS</a>,
  Linux (kernel 5.6+),
  <a href="https://apps.apple.com/app/wireguard/id1441195209">iOS</a>, and
  <a href="https://play.google.com/store/apps/details?id=com.wireguard.android">Android</a>.
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/Licence-AGPL--3.0-blue.svg" alt="Licence: AGPL-3.0"></a>
  <a href="https://www.skonamonkey.co.uk"><img src="https://img.shields.io/badge/made%20by-Skonamonkey-blueviolet" alt="Made by Skonamonkey"></a>
  <a href="https://buymeacoffee.com/skonamonkey"><img src="https://img.shields.io/badge/Buy%20Me%20a%20Coffee-support%20this%20project-FFDD00?style=flat&logo=buy-me-a-coffee&logoColor=black" alt="Buy Me a Coffee"></a>
</p>

---

## What is SkonaGuard?

WireGuard is one of the best VPN protocols ever written — fast, lean, cryptographically modern, and auditable. But managing it means editing config files by hand, running `wg syncconf` after every change, and juggling keys across multiple sites and devices. That gets unwieldy fast.

SkonaGuard is the management layer on top. It gives you a clean web UI to build and run structured WireGuard networks — with zones, access control rules, built-in DNS, gateway routing, and role-based admin access — all packaged in a single Docker container that runs on your own hardware.

No subscriptions. No cloud. No vendor lock-in. Your private keys never leave your server.

> Built by [Skonamonkey](https://www.skonamonkey.co.uk) — a collection of open source self-hosting tools for people who'd rather own their infrastructure.

---

## Quick install

```bash
git clone https://github.com/Skonamonkey/skonaguard.git
cd skonaguard
chmod +x install.sh
sudo ./install.sh
```

The script detects your public IP, prompts for subnet and port choices, writes `.env`, builds the container, and installs a systemd unit to keep routing persistent across reboots. Then open `http://<your-ip>:8080` and complete the 4-step setup wizard.

From zero to running VPN in under 5 minutes.

---

## What it does

| Feature | Detail |
|---|---|
| **Zones** | Logical network segments — Home, Office 1, Core Infra, Contractors, etc. Each zone gets its own subnet carved from your VPN address space |
| **Peers** | Per-device WireGuard configs with automatic key generation and IP allocation. Download as `.conf`, scan as QR code, or share via tokenised link |
| **Profiles** | Config templates — define zone, allowed IPs, gateway settings, and DNS once. Apply to multiple peers. Update the profile and all linked peers pick up changes on next config download |
| **Gateway peers** | Mark a peer as a site gateway to advertise a physical LAN over the VPN. Every device on that LAN gets VPN access without WireGuard installed on each one |
| **Conflict detection** | CIDR-aware subnet overlap checking — warns before saving if two gateways would advertise conflicting routes |
| **ACL engine** | iptables-backed access control between zones and individual IPs. Types: Full Access, Inbound Only, ICMP Only, Port Specific, Deny. Permissive or restrictive default policy |
| **Raw chain viewer** | Inspect live iptables rules and packet counters directly from the UI — no CLI required for troubleshooting |
| **Built-in DNS** | Optional internal DNS server (dnsproxy). Peers get names like `mikespc.home.skona`. Supports DoT and DoH upstreams. PTR records synthesised automatically — `ping hostname` resolves instantly |
| **Multi-admin RBAC** | Three roles: Super Admin (full access), Admin (peers and profiles), Zone Admin (own zones only) |
| **LAN / WAN endpoints** | Generate peer configs for the public IP or LAN IP — split download button when both are configured |
| **Host zone** | Protected system zone representing the VPN server. ACL rules control which peers can reach it. Cannot be deleted |
| **Live dashboard** | Connected peers, handshake age, endpoint, TX/RX — auto-refreshed every 10 seconds |
| **Setup wizard** | Guided first-run: server IP, subnet, port, admin credentials. Locked after completion |
| **Single SQLite file** | Everything in one `.db` file, volume-mounted outside the container. Backup = copy one file |

---

## Who is SkonaGuard for?

**SkonaGuard is a great fit if you are:**

- A homelab enthusiast who wants proper multi-site VPN management without a monthly bill
- A small business or IT team managing remote access across multiple offices, users, and devices
- Someone already running WireGuard who wants a UI and access control without moving to a managed platform
- A sysadmin or consultant who needs to hand off VPN management to someone who doesn't know the CLI
- Anyone who needs full control of their VPN infrastructure — air-gapped deployments, data privacy requirements, or simply not wanting a third party involved

**You should consider [Tailscale](https://tailscale.com) if you need:**

- Zero-config peer-to-peer networking with automatic NAT traversal and relay fallback
- OIDC / SSO / identity provider integration out of the box
- Mobile-first or BYOD deployments where you can't control the device
- A genuinely managed service where someone else handles the infrastructure

Tailscale is excellent — we have used it ourselves for some things in the past. The difference is: Tailscale runs your network on their infrastructure. SkonaGuard runs it on yours.

---

## Why not just use...

### WireGuard CLI directly

If you're comfortable editing `.conf` files by hand and running `wg syncconf` after every change, you don't need this. SkonaGuard is for when that becomes untenable — multiple sites, multiple users, multiple admins, and no time to babysit config files.

### wg-easy

[wg-easy](https://github.com/wg-easy/wg-easy) is excellent for simple single-network setups. If you need one flat network and a handful of peers, it's simpler than SkonaGuard and you should probably use it. SkonaGuard is built for the step beyond that: multiple zones with separate subnets, ACL rules between them, gateway peers routing entire LANs, built-in DNS with PTR records, and team-level admin access control.

### Tailscale

Tailscale is a genuinely impressive piece of engineering and the right answer for a lot of people. The distinction is ownership — your peer list, topology, and relay infrastructure live on Tailscale's servers. SkonaGuard uses standard WireGuard and your own server. No custom client, no coordination protocol, no dependency on a third-party service staying up and staying free.

### Headscale

[Headscale](https://github.com/juanfont/headscale) is the self-hosted Tailscale control plane — well-engineered and genuinely impressive. But it re-implements the Tailscale coordination protocol, which means you still need Tailscale clients on every device. SkonaGuard uses the standard WireGuard clients available for every major OS. No protocol lock-in, no custom client, no drift risk between client and server versions.

---

## Feature comparison

| | SkonaGuard | wg-easy | Tailscale | Headscale |
|---|---|---|---|---|
| Self-hosted | ✅ | ✅ | ❌ | ✅ |
| Always free | ✅ | ✅ | ❌ (limits) | ✅ |
| Standard WireGuard clients | ✅ | ✅ | ❌ | ❌ |
| No cloud account required | ✅ | ✅ | ❌ | Partial |
| Zone / subnet segmentation | ✅ | ❌ | Partial (tags) | Partial |
| Peer config templates (profiles) | ✅ | ❌ | ❌ | ❌ |
| ACL engine | ✅ | ❌ | ✅ (Tailscale ACL) | ✅ |
| Gateway peers (site-to-site) | ✅ | Limited | ✅ (subnet routes) | ✅ |
| Subnet conflict detection | ✅ | ❌ | ❌ | ❌ |
| Built-in DNS with PTR records | ✅ | ❌ | ✅ (MagicDNS) | ✅ |
| DNS over TLS / DNS over HTTPS | ✅ | ❌ | ❌ | ❌ |
| Multi-admin with role-based access | ✅ | ❌ | ✅ | Limited |
| Zone-scoped admin accounts | ✅ | ❌ | ❌ | ❌ |
| QR code peer setup | ✅ | ✅ | ✅ | ❌ |
| Tokenised peer download links | ✅ | ❌ | ❌ | ❌ |
| LAN + WAN dual endpoint | ✅ | ❌ | N/A | N/A |
| Live TX/RX + handshake stats | ✅ | ✅ | ✅ | ✅ |
| Raw iptables chain viewer | ✅ | ❌ | ❌ | ❌ |
| No kernel module required | ✅ (wireguard-go) | Depends on host | N/A | N/A |
| Single container | ✅ | ✅ | N/A | Multi-container |
| Database | SQLite (1 file) | JSON file | Managed cloud | SQLite/Postgres |

---

## What we've built on

SkonaGuard wouldn't exist without these projects. We're not affiliated with any of them — we just stand on their shoulders.

### [WireGuard](https://www.wireguard.com) — Jason A. Donenfeld (ZX2C4)

The VPN protocol at the heart of everything. WireGuard is one of the cleanest, most auditable pieces of network code ever written. If you find SkonaGuard useful, the best thing you can do is [support the WireGuard project](https://www.wireguard.com/donations/).

### [wireguard-go](https://github.com/WireGuard/wireguard-go) — WireGuard project

The official userspace Go implementation of WireGuard. Running wireguard-go inside Docker means SkonaGuard works without a kernel module — on LXC containers, older kernels, and VPS providers that don't expose `wireguard.ko`. On hosts where the kernel module is available, SkonaGuard prefers it automatically.

### [AdGuard dnsproxy](https://github.com/AdguardTeam/dnsproxy) — AdGuard

The DNS proxy that powers SkonaGuard's optional name resolution service. Supports plain DNS, DoT, and DoH upstreams, hosts-file resolution, and PTR synthesis. Lightweight, single binary, production-quality. If you find it useful, [support AdGuard](https://adguard.com).

### [Slim Framework](https://www.slimframework.com), [Twig](https://twig.symfony.com), [Composer](https://getcomposer.org)

The PHP stack underneath the UI and API. Slim is a micro-framework with no magic. Twig is clean server-side templating. Composer manages the (small) dependency tree.

---

## Why it's built this way

### wireguard-go inside Docker — portability without compromise

Standard WireGuard requires a kernel module that may not be present on LXC containers, older hosts, or certain VPS providers. wireguard-go gives us a portable userspace fallback with the same protocol. On hosts with the kernel module, SkonaGuard uses it for the small performance benefit. On hosts without it, wireguard-go takes over silently. You don't have to think about it.

**Performance:** wireguard-go carries a small overhead compared to the kernel module — typically single-digit percentage on modern hardware. For remote access and site-to-site management traffic, it's undetectable in practice.

### PHP 8.2 + Slim + Twig

PHP was a deliberate choice. PHP-FPM is extremely well-understood, has minimal runtime overhead, and its dependency model (Composer) is mature and auditable. The full PHP dependency tree for SkonaGuard is small enough to read in an afternoon. No build pipeline, no transpilation, no node_modules rabbit hole.

### SQLite — one file, no drama

No external database. All configuration — peers, zones, profiles, ACL rules, settings, sessions, and download tokens — lives in a single SQLite file at `./data/database/skonaguard.db`, volume-mounted outside the container. Backup is literally `cp skonaguard.db skonaguard.db.bak`. Migrations run automatically on container start.

### iptables for ACL

WireGuard doesn't control peer-to-peer access — it controls encryption and routing. SkonaGuard adds a dedicated `SKONAGUARD` iptables chain in `FORWARD` that rebuilds from the database on every peer sync and container start. It's cleanly removed when WireGuard stops. The raw chain is viewable from the UI, with live packet counters per rule, without touching the CLI.

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Docker Container                      │
│                                                          │
│  ┌─────────┐    ┌──────────┐    ┌─────────────────────┐ │
│  │  nginx  │───▶│ php-fpm  │───▶│   Slim / Twig app   │ │
│  │  :8080  │    │          │    │   /app/src/          │ │
│  └─────────┘    └──────────┘    └──────────┬──────────┘ │
│                                             │            │
│                               ┌─────────────▼──────────┐ │
│                               │  WireGuardService      │ │
│                               │  AclService / DNS      │ │
│                               └──────┬──────────┬──────┘ │
│                                      │          │        │
│                          ┌───────────▼──┐  ┌────▼──────┐ │
│                          │ wireguard-go │  │ dnsproxy  │ │
│                          │ wg0 TUN      │  │ :53 (opt) │ │
│                          └───────────┬──┘  └───────────┘ │
└──────────────────────────────────────┼────────────────────┘
                                       │
                               UDP :51820 (WireGuard)
```

**Volumes (survive container rebuilds):**
- `./data/wireguard` → `/etc/wireguard` — `wg0.conf` and peer keys
- `./data/database` → `/app/database` — `skonaguard.db`

---

## Deployment scenarios

### VPS as hub — recommended for multi-site

A public VPS acts as the WireGuard hub. All peers connect outbound — no inbound ports need to be open on any site beyond the WireGuard UDP port on the server.

```
Internet
    │
    ▼
┌──────────────────┐       WireGuard tunnels (UDP 51820)
│  VPS / SkonaGuard│◄─────────────────────────────────┐
│  172.16.0.1      │◄──────────────────────┐           │
└──────────────────┘◄──────────┐           │           │
                        ┌──────┴───┐  ┌────┴───┐  ┌───┴────┐
                        │ Home LAN │  │Office 1│  │  Road  │
                        │ Gateway  │  │Gateway │  │Warrior │
                        └──────────┘  └────────┘  └────────┘
```

### Proxmox LXC

Run SkonaGuard inside a Proxmox LXC container. The LXC needs `NET_ADMIN` and `/dev/net/tun` access — see the [Installation: Proxmox & LXC](https://github.com/Skonamonkey/skonaguard/wiki/Installation-Proxmox-LXC) wiki page for the exact config lines.

Alternatively, run just WireGuard on the Proxmox host as a gateway peer — the host advertises its LAN subnet and all VMs/containers get VPN access without WireGuard on each one.

### Behind Nginx Proxy Manager

SkonaGuard exposes its UI on port 8080. Place it behind NPM (or any reverse proxy) for HTTPS and a clean domain. SkonaGuard respects `X-Forwarded-Proto` and `X-Forwarded-Host` so token download links generate correctly behind the proxy.

---

## Requirements

| | |
|---|---|
| Docker 24+ with Compose V2 | `docker compose` (not `docker-compose`) |
| Linux host | `NET_ADMIN` capability and `/dev/net/tun` required |
| UDP port 51820 | Open in firewall for WireGuard — configurable |
| TCP port 8080 | UI — configurable, or reverse-proxy it |

---

## Upgrading

```bash
cd /srv/skonaguard
git pull origin main
docker compose up -d --build
```

The database and WireGuard config are volume-mounted and never touched by a rebuild. Migrations run automatically on container start.

---

## Project structure

```
skonaguard/
├── app/
│   ├── public/          # Web root (index.php, assets)
│   ├── src/
│   │   ├── Controllers/ # Peers, Zones, Profiles, ACL, Users, Dashboard, Settings
│   │   ├── Services/    # WireGuardService, AclService, DnsService
│   │   ├── Models/      # Database wrapper
│   │   ├── Middleware/  # Auth, RoleMiddleware
│   │   └── Routes/      # web.php
│   └── templates/       # Twig templates
├── docker/              # Dockerfile, nginx.conf, php-fpm.conf, supervisord.conf
├── scripts/             # entrypoint.sh, migrate.php, wg-start.sh, dns-start.sh
├── data/                # Runtime data — gitignored
│   ├── wireguard/       # wg0.conf and keys (volume-mounted)
│   └── database/        # skonaguard.db (volume-mounted)
├── docker-compose.yml
├── install.sh
└── .env.example
```

---

## Licence

**GNU Affero General Public License v3.0 (AGPL-3.0)**

You are free to use, modify, fork, and self-host SkonaGuard — personally, for your organisation, or for clients. If you distribute a modified version or run it as a network service, you must make the source available under the same licence.

You may **not** repackage and sell SkonaGuard as a commercial SaaS product without disclosing your source changes. The whole point of this project is that it stays free and open.

See [LICENSE](LICENSE) for the full text.

> SkonaGuard is an independent open source project. It is not affiliated with, endorsed by, or supported by the WireGuard project, Jason A. Donenfeld, AdGuard, or any of the upstream projects it builds on.

---

## Support the project

SkonaGuard is free and always will be. If it saves you a Tailscale subscription or a few hours of config file wrestling, a coffee is always appreciated.

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-skonamonkey-FFDD00?style=flat&logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/skonamonkey)

Bugs, ideas, and pull requests are welcome. If you find SkonaGuard useful, please also consider supporting the upstream projects it's built on — especially [WireGuard](https://www.wireguard.com/donations/) and [AdGuard](https://adguard.com).
