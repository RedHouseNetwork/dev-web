#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CERT_DIR="$SCRIPT_DIR/../certs"

mkdir -p "$CERT_DIR"

openssl req -x509 -nodes -days 3650 \
  -newkey rsa:2048 \
  -keyout "$CERT_DIR/server.key" \
  -out "$CERT_DIR/server.crt" \
  -subj "/CN=symf4-dev" \
  -addext "subjectAltName=DNS:localhost,DNS:*.php83.symf4,DNS:*.php84.symf4,DNS:*.home.symf4,DNS:*.dft-rfs.php83.symf4,DNS:*.dft-rfs.php84.symf4,DNS:*.dft-ldap.php83.symf4,DNS:*.dft-ldap.php84.symf4,DNS:*.dft-nts-beta.php83.symf4,DNS:*.dft-nts-beta.php84.symf4"

echo "Certificates generated in $CERT_DIR"
