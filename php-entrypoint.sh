#!/bin/sh
# Append the dev Caddy self-signed cert to the system CA bundle
# so PHP/curl trust it for inter-container HTTPS requests.
DEV_CERT=/usr/local/share/ca-certificates/dev-caddy.crt
if [ -f "$DEV_CERT" ]; then
    cat /etc/ssl/certs/ca-certificates.crt "$DEV_CERT" > /tmp/ca-bundle.crt
fi

exec docker-php-entrypoint "$@"
