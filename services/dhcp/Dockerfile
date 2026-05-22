FROM alpine:latest

# Install dnsmasq and iproute2 (for ip addr/route commands in entrypoint)
RUN apk add --no-cache dnsmasq iproute2

# Create directories and configuration
RUN mkdir -p /etc/dnsmasq.d /data/network

COPY dnsmasq.conf /etc/dnsmasq.conf
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
