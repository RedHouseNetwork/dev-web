#!/usr/bin/env bash
set -euo pipefail

# Generate TLS certificates locally (without starting containers).
# SANs are derived from CADDY_SITES, SITE_TLD, and CERT_EXTRA_SANS in .env.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$SCRIPT_DIR/.."
CERT_DIR="$PROJECT_DIR/certs"

# Source .env
if [ -f "$PROJECT_DIR/.env" ]; then
    set -a
    . "$PROJECT_DIR/.env"
    set +a
fi

CADDY_SITES="${CADDY_SITES:-php83,php84}"
SITE_TLD="${SITE_TLD:-symf4}"

# Build SAN list
SAN_LIST="DNS:localhost"
IFS=','
for entry in $CADDY_SITES; do
    entry=$(echo "$entry" | xargs)
    [ -z "$entry" ] && continue
    domain=$(echo "$entry" | cut -d: -f1)
    SAN_LIST="$SAN_LIST,DNS:*.${domain}.${SITE_TLD}"
done
if [ -n "${CERT_EXTRA_SANS:-}" ]; then
    for extra in $CERT_EXTRA_SANS; do
        extra=$(echo "$extra" | xargs)
        [ -z "$extra" ] && continue
        SAN_LIST="$SAN_LIST,DNS:${extra}"
    done
fi
unset IFS

mkdir -p "$CERT_DIR"

openssl req -x509 -nodes -days 3650 \
    -newkey rsa:2048 \
    -keyout "$CERT_DIR/server.key" \
    -out "$CERT_DIR/server.crt" \
    -subj "/CN=${SITE_TLD}-dev" \
    -addext "subjectAltName=$SAN_LIST"

# Update hash so the entrypoint won't regenerate on next startup
echo "$SAN_LIST" | sha256sum | cut -d' ' -f1 > "$CERT_DIR/.san-hash"

echo "Certificates generated in $CERT_DIR"
echo "SANs: $SAN_LIST"
