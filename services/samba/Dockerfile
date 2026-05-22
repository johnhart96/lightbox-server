FROM alpine:latest

# Install samba
RUN apk add --no-cache samba

# Create shares directory
RUN mkdir -p /shares

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
