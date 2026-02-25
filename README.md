# dev-web

Local development stack: Caddy reverse proxy, PHP-FPM (8.3 + 8.4), and databases.

## Prerequisites

- Docker & Docker Compose
- openssl (for certificate generation)

## First-time setup

1. **Generate TLS certificates:**

   ```
   ./scripts/generate-certs.sh
   ```

   Trust the generated `certs/server.crt` in your browser/OS if you want to avoid TLS warnings.

2. **Create your `.env` file:**

   ```
   cp .env.example .env
   ```

   Edit `.env` and set `UID` to your host user ID (`id -u`), `WEB_ROOT` to your web projects directory, and set your database passwords.

3. **Build and start:**

   ```
   ./build.sh
   ```

   This builds all images, restarts all services, and starts Caddy, PHP 8.3,
   PHP 8.4, and MySQL 8.0 by default.

## Services

| Service | Image | Host port | Default |
|---------|-------|-----------|---------|
| caddy | caddy:2-alpine | 80, 443 | Yes |
| php83 | php:8.3-fpm | - | Yes |
| php84 | php:8.4-fpm | - | Yes |
| mysql | mysql:8.0 | 3306 | Yes |
| mysql84 | mysql:8.4 | 3384 | No |
| mariadb | mariadb:11.1 | 3406 | No |
| mssql | mssql/server:2022 | 1433 | No |
| postgres | postgres | 5432 | No |
| samba | alpine + samba | 445 | No |

## Starting optional databases

Non-default services use [Compose profiles](https://docs.docker.com/compose/how-tos/profiles/). Enable them with `--profile`:

```
docker compose --profile postgres up -d
docker compose --profile mariadb --profile mssql up -d
```

Alternatively, set `COMPOSE_PROFILES` in your `.env` so the same `docker compose up -d`
(or `./build.sh`) command works everywhere:

```
COMPOSE_PROFILES=postgres,samba
```

## Accessing databases

From PHP containers, use the service name as hostname (e.g. `mysql`, `postgres`).
All databases use `root` as the username (except MSSQL which uses `sa`).

From the host, connect via the mapped ports listed above.

## Web projects

Sites are served from the `web` volume (bound to `~/web`). Caddy routing is
driven by the `CADDY_SITES` env var — a comma-separated list of entries in the
format `domain[:backend[:subdir]]`:

- **domain** — used in the Host regex (e.g. `php83` matches `*.php83.symf4`)
- **backend** — PHP-FPM container name (defaults to the domain name)
- **subdir** — optional subdirectory under `WEB_ROOT`

The default is `CADDY_SITES=php83,php84`, which creates:

- `*.php83.symf4` &rarr; PHP 8.3 (`~/web/<name>.symf/public/`)
- `*.php84.symf4` &rarr; PHP 8.4 (`~/web/<name>.symf/public/`)

To add a custom route (e.g. for projects in a subdirectory):

```
CADDY_SITES=php83,php84,home:php84:home-network
```

This adds `*.home.symf4` &rarr; PHP 8.4 (`~/web/home-network/<name>.symf/public/`).

The TLD defaults to `symf4` and can be changed with `SITE_TLD` in `.env`.

Pointing these hostnames at your machine is your responsibility — configure your
router, `/etc/hosts`, dnsmasq, or similar to resolve `*.symf4` to the host running
this stack.

## SQLCipher (optional)

The PHP containers can optionally be built with [SQLCipher](https://www.zetetic.net/sqlcipher/),
an encrypted drop-in replacement for SQLite. This recompiles SQLCipher from source and
rebuilds PHP's `sqlite3` and `pdo_sqlite` extensions against it.

To enable, add the version to your `.env` for the containers that need it:

```
PHP84_SQLCIPHER_VERSION=4.10.0
```

Then rebuild: `docker compose build php84`. Containers without the variable set are
unaffected.

Verify it works:

```
docker compose run --rm php84 php -r \
  'echo (new PDO("sqlite::memory:"))->query("PRAGMA cipher_version")->fetchColumn();'
```

## Xdebug (optional)

Xdebug is not installed by default. To enable it, set the build arg for the
containers that need it in your `.env`:

```
PHP83_XDEBUG=1
PHP84_XDEBUG=1
```

Then rebuild: `docker compose build php83 php84`.

## Chrome / Panther (E2E testing)

The PHP containers include Chrome and ChromeDriver by default for running
Symfony Panther (or other WebDriver-based) E2E tests. The containers are
configured with `shm_size: 2g` and `PANTHER_NO_SANDBOX=1` to avoid Chrome
crashes inside Docker.

To change the Chrome version, set it in your `.env`:

```
PHP84_CHROME_VERSION=128.0.6613.137
```

To disable Chrome entirely, set the version to empty:

```
PHP84_CHROME_VERSION=
```

Then rebuild the relevant container.

## ffmpeg + yt-dlp (optional)

The PHP containers can optionally include [ffmpeg](https://ffmpeg.org/) and
[yt-dlp](https://github.com/yt-dlp/yt-dlp) for media processing. To enable,
set the build arg in your `.env`:

```
PHP84_FFMPEG=1
```

Then rebuild: `docker compose build php84`.

yt-dlp is installed as an architecture-specific standalone binary (no Python
required). The containers set `TMPDIR=/tmp` so the binary can decompress at
runtime.

## nvm / Node.js (optional)

The PHP containers can optionally include [nvm](https://github.com/nvm-sh/nvm) and
one or more Node.js versions. To enable, set the versions in your `.env`:

```
PHP84_NODE_VERSIONS=18,20,22
```

Then rebuild: `docker compose build php84`.

The first version listed becomes the nvm default. Inside the container:

```
node --version   # default version
nvm use 18       # switch versions
nvm ls           # list installed versions
```

## Samba file sharing (optional)

The stack can expose a host directory as an SMB share for access from other machines
on the local network. Add the following to your `.env`:

```
COMPOSE_PROFILES=samba
SAMBA_ROOT=/home/wobble
SAMBA_PASSWORD=changeme
```

`SAMBA_USER` defaults to the last component of `SAMBA_ROOT` (e.g. `wobble`). Override
it explicitly if needed.

Then rebuild: `./build.sh` or `docker compose up -d`.

Connect from another machine using `smb://<host-ip>/home` with the configured
credentials.

## Per-container PHP overrides

Each PHP container has its own ini override file that is loaded after the shared
`config/php.ini`. Use these to set per-container values for settings like
`zend.assertions`:

| Container | Override file |
|-----------|--------------|
| php83 | `config/php83-overrides.ini` |
| php84 | `config/php84-overrides.ini` |

For example, to enable assertions in the PHP 8.4 container (useful for running
tests) while keeping them compiled out in PHP 8.3:

```ini
# config/php84-overrides.ini
zend.assertions = 1
```

## Claude Code in containers

The PHP containers mount the host's Claude Code binary and state at runtime, so
you can run `claude` inside a container without re-authenticating — and resume
previous conversations with `/resume`.

**Prerequisites:** Claude Code must be installed on the host (`~/.local/bin/claude`).

Two mounts are used:

- `~/.local/bin/claude` — the CLI binary (read-only)
- `~/.claude/` — credentials, config, and conversation history (read-write)

The `~/.claude.json` config file is also mounted so Claude skips the first-run
setup.

To use it, shell into a container and run `claude`:

```
php84-sh
claude
```

## Per-site header overrides

Some sites need custom request headers for local development (e.g. faking Google IAP
headers). The `overrides/` directory holds per-site `.caddy` files that are imported
into the Caddyfile before the wildcard routing rules.

1. Create the directory if it doesn't exist: `mkdir -p overrides`
2. Create a `.caddy` file for your site (e.g. `overrides/myproject.caddy`):

   ```caddy
   @myproject host myproject.php84.symf4
   handle @myproject {
       request_header X-Goog-Iap-Jwt-Assertion "foo"
       request_header X-Goog-Authenticated-User-Email "user@example.com"
       root * /srv/web/myproject.symf/public
       import php_common php84 /srv/web/myproject.symf/public
   }
   ```

3. Restart Caddy: `docker compose restart caddy`

The entire `overrides/` directory is gitignored so each developer can maintain their
own set.

## Helper scripts

The `bin/` directory contains shell shortcuts for opening a shell in each container
(`php83-sh`, `php84-sh`, `mysql-sh`, etc.). Add it to your `PATH` for easy access:

```
export PATH="$PATH:/path/to/dev/bin"
```

Add the line to your `~/.bashrc` or `~/.zshrc` to make it permanent. Then you can
run e.g. `php84-sh` from anywhere to get a shell in the PHP 8.4 container.

## File layout

```
build.sh            # Rebuild and restart everything
bin/                # Shell shortcuts for each container
compose.yaml        # Service definitions
caddy-entrypoint.sh # Generates Caddyfile from CADDY_SITES env and starts Caddy
Dockerfile.php      # PHP-FPM image build
Dockerfile.samba    # Samba file sharing image
config/my.cnf       # MySQL 8.0 config
config/smb.conf     # Samba share config
config/php83-overrides.ini  # Per-container PHP overrides (php83)
config/php84-overrides.ini  # Per-container PHP overrides (php84)
overrides/          # Per-site Caddy header overrides (not in git)
scripts/            # Helper scripts (cert generation)
.env                # Database passwords (not in git)
.env.example        # Password template
data/               # Database data directories (not in git)
certs/              # TLS certificates (not in git)
```
