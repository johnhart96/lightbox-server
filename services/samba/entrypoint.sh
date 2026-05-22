#!/bin/sh
set -e

USERS_CONF="/etc/samba/linux_users.conf"

# Recreate Linux users listed in the conf file (written by the web admin)
if [ -f "$USERS_CONF" ]; then
    while IFS= read -r username || [ -n "$username" ]; do
        username=$(echo "$username" | tr -d '[:space:]')
        [ -z "$username" ] && continue
        # adduser is idempotent — silently skip if already exists
        adduser -D -H "$username" 2>/dev/null || true
    done < "$USERS_CONF"
fi

exec smbd --foreground --no-process-group --debug-stdout --configfile=/etc/samba/smb.conf
