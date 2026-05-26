#!/usr/bin/env bash
# install.sh — Lightbox Server installer
# Usage: bash install.sh

set -euo pipefail

# ── Colours ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; RESET='\033[0m'

info() { echo -e "${BLUE}  ▶${RESET}  $*"; }
ok()   { echo -e "${GREEN}  ✓${RESET}  $*"; }
warn() { echo -e "${YELLOW}  ⚠${RESET}  $*"; }
die()  { echo -e "${RED}  ✗${RESET}  $*" >&2; exit 1; }
hr()   { echo -e "${BOLD}────────────────────────────────────────────${RESET}"; }
step() { echo; hr; echo -e "${BOLD}  $*${RESET}"; hr; }

# ── Header ────────────────────────────────────────────────────────────────────
echo
echo -e "${BOLD}╔════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║        Lightbox Server — Installer         ║${RESET}"
echo -e "${BOLD}╚════════════════════════════════════════════╝${RESET}"
echo

# ── 1. Prerequisites ──────────────────────────────────────────────────────────
step "Checking prerequisites"

# Docker
if ! command -v docker &>/dev/null; then
    die "Docker not found. Install it from: https://docs.docker.com/engine/install/"
fi
DOCKER_VER=$(docker --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
ok "Docker ${DOCKER_VER}"

# Docker daemon
if ! docker info &>/dev/null 2>&1; then
    die "Docker daemon is not running. Try: sudo systemctl start docker"
fi
ok "Docker daemon is running"

# Docker Compose (prefer v2 plugin, fall back to standalone v1)
if docker compose version &>/dev/null 2>&1; then
    COMPOSE="docker compose"
    COMPOSE_VER=$(docker compose version --short 2>/dev/null || echo "v2")
    ok "Docker Compose ${COMPOSE_VER} (plugin)"
elif command -v docker-compose &>/dev/null; then
    COMPOSE="docker-compose"
    COMPOSE_VER=$(docker-compose --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
    warn "docker-compose v${COMPOSE_VER} (standalone). Consider upgrading to the Docker Compose v2 plugin."
else
    die "Docker Compose not found. Install it from: https://docs.docker.com/compose/install/"
fi

# OS — host networking services (DHCP, NTP, Samba, Syslog) require Linux
OS=$(uname -s)
if [[ "$OS" != "Linux" ]]; then
    warn "Running on ${OS}. Services using host networking (DHCP, NTP, Samba, Syslog) require Linux."
    warn "The web UI will still work for configuration, but network features will not be active."
fi

# ── 2. Locate project root ────────────────────────────────────────────────────
step "Locating project"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

[[ -f "docker-compose.yml" ]] || die "docker-compose.yml not found in ${SCRIPT_DIR}. Run this script from the project root."
ok "Project root: ${SCRIPT_DIR}"

# ── 3. Port availability warnings ─────────────────────────────────────────────
step "Checking ports"

check_port() {
    local port=$1 proto=${2:-tcp} name=$3
    if ss -lntu 2>/dev/null | grep -qE "${proto}.*:${port}\b" || \
       netstat -lntu 2>/dev/null | grep -qE "${proto}.*:${port}\b"; then
        warn "Port ${port}/${proto} is already in use — ${name} may fail to start."
        return 1
    fi
    ok "Port ${port}/${proto} is free (${name})"
}

check_port 8080 tcp "Web UI"       || true
check_port 53   tcp "DNS (bind9)"  || true
check_port 53   udp "DNS (bind9)"  || true

# systemd-resolved conflicts with port 53 on Ubuntu/Debian
if systemctl is-active --quiet systemd-resolved 2>/dev/null; then
    warn "systemd-resolved is running and may hold port 53."
    warn "To free it: sudo systemctl disable --now systemd-resolved"
    warn "Then edit /etc/resolv.conf to point to 8.8.8.8 as a fallback."
fi

# ── 4. Handle existing installation ──────────────────────────────────────────
step "Checking for existing installation"

RUNNING=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -c '^lightbox-' || true)
if [[ "$RUNNING" -gt 0 ]]; then
    warn "${RUNNING} Lightbox container(s) are already running."
    read -rp "  Stop them and reinstall? [y/N] " REPLY
    if [[ "${REPLY,,}" == "y" ]]; then
        info "Stopping existing containers..."
        $COMPOSE down --remove-orphans
        ok "Stopped"
    else
        echo "  Aborted."
        exit 0
    fi
else
    ok "No existing Lightbox containers found"
fi

# ── 5. Create data directories ────────────────────────────────────────────────
step "Preparing data directories"

DATA_DIRS=(
    data/db
    data/bind/zones
    data/bind/cache
    data/dnsmasq
    data/chrony
    data/samba
    data/syslog
    data/network
    data/pki
    data/shares/ShowFiles
    data/shares/tftp
)

for d in "${DATA_DIRS[@]}"; do
    if [[ ! -d "$d" ]]; then
        mkdir -p "$d"
        info "Created ${d}/"
    fi
done
ok "Data directories ready"

# bind9 uses file-level bind mounts for these two files.
# If they don't exist on the host, Docker creates them as directories instead,
# which causes bind9 to fail. Create them as empty files now; init.php will
# write the real content before bind9 starts.
for f in data/bind/named.conf.options data/bind/named.conf.local; do
    if [[ ! -f "$f" ]]; then
        touch "$f"
        info "Created placeholder: ${f}"
    fi
done
ok "bind9 config placeholders ready"

# ── 6. Build images ───────────────────────────────────────────────────────────
step "Building Docker images"
info "This may take several minutes on the first run..."
$COMPOSE build
ok "Images built"

# ── 7. Start services ─────────────────────────────────────────────────────────
step "Starting services"
$COMPOSE up -d
ok "Containers started"

# ── 8. Wait for web service health check ─────────────────────────────────────
step "Waiting for web service"
info "The web container runs database initialisation and generates all config"
info "files before signalling healthy. This can take up to 90 seconds..."

MAX_WAIT=90
WAITED=0
INTERVAL=5

printf "  Waiting"
while true; do
    STATUS=$(docker inspect --format='{{.State.Health.Status}}' lightbox-web 2>/dev/null || echo "unknown")
    case "$STATUS" in
        healthy)
            echo
            ok "Web service is healthy"
            break
            ;;
        unhealthy)
            echo
            echo
            warn "Web service reported unhealthy. Check the logs:"
            warn "  docker logs lightbox-web"
            break
            ;;
    esac
    if [[ "$WAITED" -ge "$MAX_WAIT" ]]; then
        echo
        warn "Timed out waiting. The service may still be starting — check with:"
        warn "  docker logs lightbox-web"
        break
    fi
    printf "."
    sleep "$INTERVAL"
    WAITED=$((WAITED + INTERVAL))
done

# ── 9. Service status ─────────────────────────────────────────────────────────
step "Service status"
$COMPOSE ps

# ── 10. Get host IP ────────────────────────────────────────────────────────────
HOST_IP=""
if command -v ip &>/dev/null; then
    HOST_IP=$(ip route get 1.1.1.1 2>/dev/null | grep -oP 'src \K[\d.]+' | head -1 || true)
fi
if [[ -z "$HOST_IP" ]] && command -v hostname &>/dev/null; then
    HOST_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || true)
fi

# ── Done ──────────────────────────────────────────────────────────────────────
echo
echo -e "${GREEN}${BOLD}╔════════════════════════════════════════════╗${RESET}"
echo -e "${GREEN}${BOLD}║        Lightbox Server is ready! 🎉        ║${RESET}"
echo -e "${GREEN}${BOLD}╚════════════════════════════════════════════╝${RESET}"
echo
echo -e "  ${BOLD}Web UI${RESET}"
echo    "    http://localhost:8080"
[[ -n "$HOST_IP" ]] && echo "    http://${HOST_IP}:8080"
echo
echo -e "  ${BOLD}First steps${RESET}"
echo    "    1. Open the web UI and go to Settings to create a user account."
echo    "    2. Set your domain name and DHCP interface in DNS / DHCP Settings."
echo    "    3. Click 'Apply Pending Changes' to write and activate the config."
echo
echo -e "  ${BOLD}Useful commands${RESET}"
echo    "    View logs:   docker compose logs -f"
echo    "    Stop:        docker compose down"
echo    "    Restart:     docker compose restart"
echo    "    Rebuild:     docker compose build && docker compose up -d"
echo
