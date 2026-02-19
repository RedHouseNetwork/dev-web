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
   docker compose up -d --build
   ```

   This starts Caddy, PHP 8.3, PHP 8.4, and MySQL 8.0 by default.

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

## Starting optional databases

Non-default databases use [Compose profiles](https://docs.docker.com/compose/how-tos/profiles/). Enable them with `--profile`:

```
docker compose --profile postgres up -d
docker compose --profile mariadb --profile mssql up -d
```

## Accessing databases

From PHP containers, use the service name as hostname (e.g. `mysql`, `postgres`).
All databases use `root` as the username (except MSSQL which uses `sa`).

From the host, connect via the mapped ports listed above.

## Web projects

Sites are served from the `web` volume (bound to `~/web`). The Caddyfile routes
requests based on the Host header:

- `*.php83.symf4` &rarr; PHP 8.3
- `*.php84.symf4` &rarr; PHP 8.4
- `*.home.symf4` &rarr; PHP 8.4 (`~/web/home-network/`)

Each project is expected at `~/web/<name>.symf/public/`.

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

## File layout

```
compose.yaml        # Service definitions
Caddyfile           # Reverse proxy routing
Dockerfile.php      # PHP-FPM image build
config/my.cnf       # MySQL 8.0 config
overrides/          # Per-site Caddy header overrides (not in git)
scripts/            # Helper scripts (cert generation)
.env                # Database passwords (not in git)
.env.example        # Password template
data/               # Database data directories (not in git)
certs/              # TLS certificates (not in git)
```
