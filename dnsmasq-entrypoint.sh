#!/bin/sh
set -e

CADDY_IP="${CADDY_IP:?CADDY_IP must be set}"
CADDY_SITES="${CADDY_SITES:-php83,php84}"
SITE_TLD="${SITE_TLD:-symf4}"

apk add --no-cache dnsmasq >/dev/null 2>&1

cat > /etc/dnsmasq.conf <<EOF
no-resolv
no-hosts
listen-address=172.19.0.2
server=127.0.0.11
EOF

# Wildcard rules from CADDY_SITES (domain is the first colon-delimited field)
IFS=','
for entry in $CADDY_SITES; do
    domain=$(echo "$entry" | cut -d: -f1 | xargs)
    [ -n "$domain" ] && echo "address=/.${domain}.${SITE_TLD}/${CADDY_IP}" >> /etc/dnsmasq.conf
done

# Additional rules from CERT_EXTRA_SANS (strip leading *.)
if [ -n "${CERT_EXTRA_SANS:-}" ]; then
    for san in $CERT_EXTRA_SANS; do
        base=$(echo "$san" | sed 's/^\*\.//' | xargs)
        [ -n "$base" ] && echo "address=/.${base}/${CADDY_IP}" >> /etc/dnsmasq.conf
    done
fi
unset IFS

cat /etc/dnsmasq.conf
exec dnsmasq --no-daemon --conf-file=/etc/dnsmasq.conf
