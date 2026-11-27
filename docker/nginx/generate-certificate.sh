#!/bin/sh
# Creates a local certificate authority and a localhost certificate signed by it,
# like mkcert. Trust only anivibe-ca.crt on your computer (see docs/authentication.md);
# the CA is name-constrained to localhost and 127.0.0.0/8, so it cannot vouch for
# any other site. To use your own certificate instead, save it as localhost.crt
# and localhost.key in docker/nginx/certs before starting the server.
set -eu

directory=/etc/nginx/certs
ca_certificate="$directory/anivibe-ca.crt"
ca_key="$directory/anivibe-ca.key"
certificate="$directory/localhost.crt"
key="$directory/localhost.key"

# Keep a certificate that is still valid for at least one more day.
if [ -s "$certificate" ] && [ -s "$key" ] \
    && openssl x509 -checkend 86400 -noout -in "$certificate" >/dev/null 2>&1; then
    exit 0
fi

mkdir -p "$directory"

if [ ! -s "$ca_certificate" ] || [ ! -s "$ca_key" ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 \
        -subj '/O=AniVibe/CN=AniVibe local development CA' \
        -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
        -addext 'keyUsage=critical,keyCertSign,cRLSign' \
        -addext 'nameConstraints=critical,permitted;DNS:localhost,permitted;IP:127.0.0.0/255.0.0.0' \
        -keyout "$ca_key" -out "$ca_certificate"
    chmod 600 "$ca_key"
    echo "AniVibe: generated a local certificate authority in $ca_certificate"
fi

extensions="$directory/localhost.ext"
request="$directory/localhost.csr"
cat > "$extensions" <<'EOF'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:localhost,IP:127.0.0.1
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid
EOF

openssl req -new -newkey rsa:2048 -nodes -sha256 -subj '/O=AniVibe/CN=localhost' \
    -keyout "$key" -out "$request"
openssl x509 -req -sha256 -days 397 -in "$request" \
    -CA "$ca_certificate" -CAkey "$ca_key" -set_serial "0x$(openssl rand -hex 16)" \
    -extfile "$extensions" -out "$certificate"
chmod 600 "$key"
rm -f "$request" "$extensions"
echo "AniVibe: generated a localhost certificate signed by the local CA"
