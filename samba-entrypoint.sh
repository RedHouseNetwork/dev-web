#!/bin/sh
set -e

SAMBA_ROOT="${SAMBA_ROOT:?SAMBA_ROOT must be set}"
SAMBA_USER="${SAMBA_USER:-$(basename "$SAMBA_ROOT")}"
SAMBA_PASSWORD="${SAMBA_PASSWORD:?SAMBA_PASSWORD must be set}"
HOST_UID="${HOST_UID:-1000}"

# Create system user with matching UID
adduser -D -u "$HOST_UID" "$SAMBA_USER"

# Set Samba password
printf '%s\n%s\n' "$SAMBA_PASSWORD" "$SAMBA_PASSWORD" | smbpasswd -a -s "$SAMBA_USER"

exec smbd --foreground --no-process-group
