#!/bin/sh
set -e

USERS_CONF="/etc/samba/linux_users.conf"
TFTP_ROOT_FILE="/etc/samba/tftp-root"

# Recreate Linux users listed in the conf file (written by the web admin)
if [ -f "$USERS_CONF" ]; then
    while IFS= read -r username || [ -n "$username" ]; do
        username=$(echo "$username" | tr -d '[:space:]')
        [ -z "$username" ] && continue
        # adduser is idempotent — silently skip if already exists
        adduser -D -H "$username" 2>/dev/null || true
    done < "$USERS_CONF"
fi

# Start TFTP server if a share has been designated as the TFTP root
if [ -f "$TFTP_ROOT_FILE" ]; then
    TFTP_ROOT=$(tr -d '[:space:]' < "$TFTP_ROOT_FILE")
    if [ -n "$TFTP_ROOT" ]; then
        mkdir -p "$TFTP_ROOT"
        in.tftpd -L -v --secure "$TFTP_ROOT" &
        echo "TFTP server started, serving $TFTP_ROOT on UDP 69"
    fi
fi

exec smbd --foreground --no-process-group --debug-stdout --configfile=/etc/samba/smb.conf
