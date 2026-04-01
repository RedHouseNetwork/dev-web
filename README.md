# dev-web

Local development stack: Caddy reverse proxy, PHP-FPM (8.3 + 8.4), and databases.

## Prerequisites

- Docker & Docker Compose

## First-time setup

1. **Create your `.env` file:**

   ```
   cp .env.example .env
   ```

   Edit `.env` and set `HOST_UID` to your host user ID (`id -u`), `WEB_ROOT` to your web projects directory, and set your database passwords.

2. **Build and start:**

   ```
   ./build.sh
   ```

   This builds all images, restarts all services, and starts Caddy, PHP 8.3,
   PHP 8.4, and MySQL 8.0 by default.

## Services

| Service | Image | Host port | Default |
|---------|-------|-----------|---------|
| dnsmasq | alpine:3.21 | — | Yes |
| caddy | caddy:2-alpine | 80, 443 | Yes |
| php83 | php:8.3-fpm | 8300-8309* | Yes |
| php84 | php:8.4-fpm | 8400-8409* | Yes |
| mysql | mysql:8.0 | 3306 | Yes |
| mysql84 | mysql:8.4 | 3384 | No |
| mariadb | mariadb:11.1 | 3406 | No |
| mssql | mssql/server:2022 | 1433 | No |
| postgres | postgis/postgis | 5432 | No |
| samba | alpine + samba | 445 | No |
| cloud-sql-proxy | cloud-sql-proxy:2 | 3307 | No |

*The port ranges on the PHP containers are not used by the stack itself — they are
spare mappings available for any process you run inside the container that needs to
be reachable from the host or network (e.g. `symfony server:start --port=8400`,
Vite dev servers, or any other listener).

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

- `*.php83.symf4` &rarr; PHP 8.3 (`~/web/<name>/public/`)
- `*.php84.symf4` &rarr; PHP 8.4 (`~/web/<name>/public/`)

To add a custom route (e.g. for projects in a subdirectory):

```
CADDY_SITES=php83,php84,home:php84:home-network
```

This adds `*.home.symf4` &rarr; PHP 8.4 (`~/web/home-network/<name>/public/`).

The TLD defaults to `symf4` and can be changed with `SITE_TLD` in `.env`.

Pointing these hostnames at your machine is your responsibility — configure your
router, `/etc/hosts`, dnsmasq, or similar to resolve `*.symf4` to the host running
this stack. Inside the PHP containers, a built-in dnsmasq service automatically
resolves these domains to the Caddy container (see
[Inter-container DNS](#inter-container-dns) below).

## TLS certificates

TLS certificates are auto-generated when Caddy starts. The certificate's Subject
Alternative Names (SANs) are derived from your configuration:

- **Base SANs** — `*.{domain}.{SITE_TLD}` for each `CADDY_SITES` entry, plus `localhost`
- **Extra SANs** — additional entries from `CERT_EXTRA_SANS` (comma-separated DNS names)

Use `CERT_EXTRA_SANS` for multi-level subdomain wildcards that the base SANs don't
cover. For example, `*.php83.symf4` covers `myapp.php83.symf4` but not
`admin.dft-rfs.php83.symf4` — that needs an explicit `*.dft-rfs.php83.symf4` entry:

```
CERT_EXTRA_SANS=*.dft-rfs.php83.symf4,*.dft-rfs.php84.symf4
```

Certificates only regenerate when the SAN list changes — restarting Caddy with an
unchanged config reuses the existing cert. After the first generation (or any
regeneration), trust `certs/server.crt` in your browser/OS to avoid TLS warnings.

Manual generation without starting containers is still available via
`scripts/generate-certs.sh`.

## Inter-container DNS

On the host, wildcard domains like `*.home.laptop` typically resolve to `127.0.0.1`.
Inside a Docker container, `127.0.0.1` is the container itself — not the host — so
PHP code making HTTP requests to these domains would fail to reach Caddy.

A lightweight dnsmasq container solves this automatically. It intercepts DNS queries
for domains derived from `CADDY_SITES` and `CERT_EXTRA_SANS`, returning the Caddy
container's IP instead. All other queries (container names like `mysql`, internet
domains) are forwarded through Docker's internal DNS as normal.

No configuration is needed — the domains are derived from the same `CADDY_SITES` and
`SITE_TLD` variables that drive Caddy's routing.

To verify it's working:

```
docker exec php84 nslookup myapp.home.laptop
```

This should return `172.19.0.3` (Caddy's fixed IP on the internal network).

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

Xdebug is installed with `mode=off` by default (set in `config/php.ini`). To enable
a mode, edit the ini or use a per-container override:

```ini
# config/php84-overrides.ini
xdebug.mode = debug
```

Then restart: `docker compose restart php84`.

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

## Android SDK (optional)

The PHP containers can optionally include the Android SDK for building Android apps
(e.g. Capacitor/Cordova hybrid apps) from the command line. To enable, set the build
arg in your `.env`:

```
PHP84_ANDROID_SDK=1
```

Then rebuild: `docker compose build php84`.

This installs:

- OpenJDK 17 (required by Gradle/Kotlin)
- Gradle 8.12
- Android command-line tools (`sdkmanager`, `avdmanager`)
- `platform-tools` (adb, fastboot)
- `platforms;android-35`
- `build-tools;35.0.0`
- `libsqlite3-dev` (native SQLite library needed by Room's KSP annotation processor)

`ANDROID_HOME` is set to `/opt/android-sdk` and the SDK tools are on the `PATH`.
The SDK directory is owned by the container user, so you can install additional
components at runtime:

```
sdkmanager "platforms;android-34" "ndk;27.0.12077973"
```

If your project doesn't have a Gradle wrapper yet, bootstrap one with:

```
cd android && gradle wrapper
```

After that, `./gradlew` is self-contained and will download the Gradle version
specified in `gradle-wrapper.properties`.

With Node.js also enabled (`PHP84_NODE_VERSIONS`), a typical Capacitor workflow
inside the container looks like:

```
npx cap sync android
cd android && ./gradlew assembleDebug
```

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

## Cloud SQL Auth Proxy (optional)

The stack includes a [Cloud SQL Auth Proxy](https://cloud.google.com/sql/docs/mysql/connect-auth-proxy)
container for connecting to GCP Cloud SQL instances. Only one proxy runs at a time
(singleton mode) — starting a new instance automatically stops the previous one.

1. **Configure instances:**

   ```
   cp cloud-sql.json.example cloud-sql.json
   ```

   Add a top-level `port` and one entry per instance with `instance` (connection
   string from **GCP Console > SQL > Instance > Overview > Connection name**).
   Optionally include `credentials` pointing to a service account key file (must be
   under `WEB_ROOT` so the container can see it). Instances without `credentials`
   use application default credentials instead (see step 2).

   ```json
   {
     "port": 3307,
     "myapp-nonprod": {
       "instance": "my-project:europe-west1:nonprod-v8",
       "credentials": "/home/wobble/web/work/myapp/local/keys/nonprod.json"
     },
     "other-app": {
       "instance": "other-project:europe-west1:nonprod-v8"
     }
   }
   ```

2. **Authenticate** — choose one or both methods depending on the project:

   - **Application default credentials** (no key file needed): run
     `./bin/cloud-sql auth` and follow the prompts — it gives you a URL to open in
     any browser. Credentials are saved in `data/gcloud/` and shared with the proxy
     container.

   - **Service account key**: download a key from GCP Console (**IAM & Admin >
     Service Accounts** > select account with **Cloud SQL Client** role > **Keys** >
     **Add Key** > **JSON**) and save it to e.g.
     `~/web/work/<project>/local/keys/<env>.json`. Set the `credentials` field in
     `cloud-sql.json` to the file path.

3. **Start the container:**

   ```
   docker compose up -d cloud-sql-proxy
   ```

4. **Manage proxy connections:**

   ```
   ./bin/cloud-sql up myapp-nonprod   # start proxy (stops any running one first)
   ./bin/cloud-sql down               # stop the running proxy
   ./bin/cloud-sql ls                 # list instances and status
   ```

   Logs appear in `docker compose logs cloud-sql-proxy`.

   From other containers, connect to `cloud-sql-proxy:3307` (or whatever port you
   configured).

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
previous conversations with `/resume`. Plugins work too, since the container
user's home directory matches the host `$HOME` (paths in plugin configs resolve
correctly).

**Prerequisites:** Claude Code must be installed on the host (`~/.local/bin/claude`).

The following are mounted from the host:

- `~/.local/bin/claude` — the CLI binary (read-only)
- `~/.claude/` — credentials, config, plugins, and conversation history (read-write)
- `~/.claude.json` — config file so Claude skips first-run setup

The container user is created with the host's `$HOME` as its home directory
(passed via the `HOST_HOME` build arg). This ensures absolute paths in Claude's
config and plugin cache files resolve identically inside the container.

MCP servers are supported out of the box — `nodejs` and `npm` (providing `npx`)
are installed in the base image. If `NODE_VERSIONS` is also set, the nvm-managed
version takes precedence.

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
       root * /srv/web/myproject/public
       import php_common php84 /srv/web/myproject/public
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
dnsmasq-entrypoint.sh # Generates dnsmasq config from CADDY_SITES and starts dnsmasq
Dockerfile.php      # PHP-FPM image build
Dockerfile.cloud-sql-proxy  # Cloud SQL Auth Proxy image
Dockerfile.samba    # Samba file sharing image
config/my.cnf       # MySQL 8.0 config
config/smb.conf     # Samba share config
config/php83-overrides.ini  # Per-container PHP overrides (php83)
config/php84-overrides.ini  # Per-container PHP overrides (php84)
overrides/          # Per-site Caddy header overrides (not in git)
scripts/            # Helper scripts (cert generation)
cloud-sql.json      # Cloud SQL proxy instance config (not in git)
cloud-sql.json.example  # Cloud SQL proxy config template
.env                # Database passwords (not in git)
.env.example        # Password template
data/               # Database data directories (not in git)
certs/              # TLS certificates (not in git)
```
