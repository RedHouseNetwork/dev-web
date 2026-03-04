#!/bin/sh
set -e

CADDY_SITES="${CADDY_SITES:-php83,php84}"
SITE_TLD="${SITE_TLD:-symf4}"
WEB_ROOT="${WEB_ROOT:?WEB_ROOT must be set}"

# Ensure openssl is available (not included in caddy:2-alpine)
if ! command -v openssl >/dev/null 2>&1; then
    apk add --no-cache openssl >/dev/null 2>&1
fi

# --- TLS certificate generation ---
CERT_DIR="/etc/caddy/certs"
CERT_FILE="$CERT_DIR/server.crt"
KEY_FILE="$CERT_DIR/server.key"
HASH_FILE="$CERT_DIR/.san-hash"

# Build SAN list from CADDY_SITES and CERT_EXTRA_SANS
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

# Check if certs need (re)generation
CURRENT_HASH=$(echo "$SAN_LIST" | sha256sum | cut -d' ' -f1)
EXISTING_HASH=""
[ -f "$HASH_FILE" ] && EXISTING_HASH=$(cat "$HASH_FILE")

if [ ! -f "$CERT_FILE" ] || [ "$CURRENT_HASH" != "$EXISTING_HASH" ]; then
    mkdir -p "$CERT_DIR"
    openssl req -x509 -nodes -days 3650 \
        -newkey rsa:2048 \
        -keyout "$KEY_FILE" \
        -out "$CERT_FILE" \
        -subj "/CN=${SITE_TLD}-dev" \
        -addext "subjectAltName=$SAN_LIST" \
        2>/dev/null
    echo "$CURRENT_HASH" > "$HASH_FILE"
    echo "TLS certificate generated with SANs: $SAN_LIST"
else
    echo "TLS certificate up to date (SANs unchanged)"
fi
# --- End TLS certificate generation ---

cat > /etc/caddy/Caddyfile <<STATIC
{
	auto_https off
}

(php_common) {
	encode gzip

	@notStatic {
		not path *.ico *.css *.js *.gif *.jpg *.jpeg *.png *.svg *.woff *.woff2
	}

	try_files {path} /index.php?{query}

	@phpFiles path *.php
	reverse_proxy @phpFiles {args[0]}:9000 {
		transport fastcgi {
			root {args[1]}
			split .php
			env SERVER_NAME {host}
		}
	}

	file_server
}

:80 {
	redir https://{host}{uri} permanent
}

:443 {
	log {
		output stderr
	}
	tls /etc/caddy/certs/server.crt /etc/caddy/certs/server.key

	import /etc/caddy/overrides/*.caddy

STATIC

# Generate a routing block for each CADDY_SITES entry.
# Format: domain[:backend[:subdir]]
# - domain: used in the Host regex (e.g. php83)
# - backend: PHP-FPM container name (defaults to domain)
# - subdir: optional subdirectory under WEB_ROOT (e.g. home-network)
i=0
IFS=','
for entry in $CADDY_SITES; do
    entry=$(echo "$entry" | xargs)
    [ -z "$entry" ] && continue

    domain=$(echo "$entry" | cut -d: -f1)
    backend=$(echo "$entry" | cut -d: -f2 -s)
    subdir=$(echo "$entry" | cut -d: -f3 -s)

    [ -z "$backend" ] && backend="$domain"

    if [ -n "$subdir" ]; then
        root_path="${WEB_ROOT}/${subdir}/{re.site${i}.1}/public"
    else
        root_path="${WEB_ROOT}/{re.site${i}.1}/public"
    fi

    cat >> /etc/caddy/Caddyfile <<SITE
	@site${i} header_regexp site${i} Host ^(?:.*\\.)?([^.]+)\\.${domain}\\.${SITE_TLD}(?::\\d+)?\$
	handle @site${i} {
		root * ${root_path}
		import php_common ${backend} ${root_path}
	}

SITE

    i=$((i + 1))
done

cat >> /etc/caddy/Caddyfile <<FALLBACK
	handle {
		respond "No matching site" 404
	}
}
FALLBACK

exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
