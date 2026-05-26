FROM alpine:latest

# Install samba and TFTP server
RUN apk add --no-cache samba tftp-hpa

# Create shares directory
RUN mkdir -p /shares

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
