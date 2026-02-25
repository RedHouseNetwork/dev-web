#!/bin/sh
set -e

CADDY_SITES="${CADDY_SITES:-php83,php84}"
SITE_TLD="${SITE_TLD:-symf4}"
WEB_ROOT="${WEB_ROOT:?WEB_ROOT must be set}"

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
