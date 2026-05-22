#!/bin/sh
set -e

# Apply persistent network interface configuration (static IPs, IPv6 server addresses)
if [ -f /data/network/apply-interfaces.sh ]; then
    echo "[lightbox] Applying network interface configuration..."
    sh /data/network/apply-interfaces.sh
fi

exec dnsmasq --no-daemon --conf-file=/etc/dnsmasq.conf
