FROM alpine:latest

# Install samba
RUN apk add --no-cache samba

# Create shares directory
RUN mkdir -p /shares

# Start smbd in the foreground, logging to stdout
ENTRYPOINT ["smbd", "--foreground", "--no-process-group", "--log-stdout", "--configfile=/etc/samba/smb.conf"]
