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

## File layout

```
compose.yaml        # Service definitions
Caddyfile           # Reverse proxy routing
Dockerfile.php      # PHP-FPM image build
config/my.cnf       # MySQL 8.0 config
scripts/            # Helper scripts (cert generation)
.env                # Database passwords (not in git)
.env.example        # Password template
data/               # Database data directories (not in git)
certs/              # TLS certificates (not in git)
```
