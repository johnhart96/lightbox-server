FROM alpine:latest

# Install chrony
RUN apk add --no-cache chrony

# Create default configuration folder
RUN mkdir -p /etc/chrony

# Start chronyd in foreground, logging to stdout
ENTRYPOINT ["chronyd", "-d", "-f", "/etc/chrony/chrony.conf"]
