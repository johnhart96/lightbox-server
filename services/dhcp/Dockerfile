FROM alpine:latest

# Install dnsmasq
RUN apk add --no-cache dnsmasq iproute2

# Create directories and configuration
RUN mkdir -p /etc/dnsmasq.d
COPY dnsmasq.conf /etc/dnsmasq.conf

# Start dnsmasq in foreground
ENTRYPOINT ["dnsmasq", "--no-daemon", "--conf-file=/etc/dnsmasq.conf"]
