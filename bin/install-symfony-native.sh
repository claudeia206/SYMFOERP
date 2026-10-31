#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ ! -f composer.json || ! -f bin/console || ! -d public ]]; then
  echo "Lancez ce script depuis un checkout SYMFOERP complet." >&2
  exit 1
fi

for command in sudo apt-get curl openssl git; do
  command -v "$command" >/dev/null 2>&1 || { echo "Prérequis manquant : $command" >&2; exit 1; }
done

. /etc/os-release
UBUNTU_CODENAME="${UBUNTU_CODENAME:-}"
if [[ "${ID:-}" != "linuxmint" || -z "$UBUNTU_CODENAME" ]]; then
  echo "Ce programme cible Linux Mint basé sur Ubuntu (pas LMDE)." >&2
  echo "Pour LMDE, utiliser la procédure Debian dédiée." >&2
  exit 1
fi

if [[ "$EUID" -eq 0 ]]; then
  SUDO=""
else
  SUDO="sudo"
fi

echo "==> Installation des outils de base"
$SUDO apt-get update
$SUDO apt-get install -y software-properties-common ca-certificates curl git unzip acl   apache2 mariadb-server mariadb-client

PHP_VERSION="$(apt-cache policy php8.3-cli 2>/dev/null | awk '/Candidate:/ {print $2; exit}')"
if [[ -z "$PHP_VERSION" || "$PHP_VERSION" == "(none)" ]]; then
  echo "PHP 8.3 absent des dépôts configurés ; activation du PPA PHP pour Ubuntu $UBUNTU_CODENAME."
  $SUDO add-apt-repository -y ppa:ondrej/php
  $SUDO apt-get update
fi

PHP_VERSION="$(apt-cache policy php8.3-cli 2>/dev/null | awk '/Candidate:/ {print $2; exit}')"
if [[ -z "$PHP_VERSION" || "$PHP_VERSION" == "(none)" ]]; then
  echo "Impossible de trouver PHP 8.3. Aucun déploiement applicatif n'a été effectué." >&2
  exit 1
fi

echo "==> Installation de PHP 8.3 et des extensions Symfony"
$SUDO apt-get install -y php8.3-cli php8.3-common php8.3-fpm libapache2-mod-php8.3 \
  php8.3-mysql php8.3-xml php8.3-mbstring php8.3-intl php8.3-curl \
  php8.3-zip php8.3-gd php8.3-bcmath php8.3-opcache composer

if ! command -v composer >/dev/null 2>&1; then
  echo "Composer est introuvable après installation." >&2
  exit 1
fi

echo "==> Préparation de MariaDB"
$SUDO systemctl enable --now mariadb
$SUDO systemctl enable --now apache2

if [[ ! -f .env.local ]]; then
  APP_SECRET="$(openssl rand -hex 32)"
  DB_PASSWORD="$(openssl rand -hex 24)"
  DB_VERSION="$($SUDO mariadb --batch --skip-column-names -e 'SELECT VERSION()' | cut -d- -f1)"
  [[ -n "$DB_VERSION" ]] || { echo "Impossible de déterminer la version MariaDB." >&2; exit 1; }

  $SUDO mariadb <<SQL
CREATE DATABASE IF NOT EXISTS symfoerp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'symfoerp'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'symfoerp'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON symfoerp.* TO 'symfoerp'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

  umask 027
  cat > .env.local <<EOF
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=$APP_SECRET
DATABASE_URL="mysql://symfoerp:$DB_PASSWORD@127.0.0.1:3306/symfoerp?serverVersion=mariadb-$DB_VERSION&charset=utf8mb4"
EOF
  chmod 640 .env.local
  $SUDO chown "$(id -un):www-data" .env.local
  echo "Configuration locale .env.local créée."
else
  echo "Configuration .env.local existante conservée."
fi

echo "==> Installation des dépendances PHP"
composer install --no-interaction --prefer-dist --optimize-autoloader

echo "==> Permissions des répertoires Symfony"
# Apache doit pouvoir traverser les dossiers parents, sans obtenir le droit de les lister.
parent="$(dirname "$ROOT_DIR")"
while [[ "$parent" != "/" && "$parent" != "." ]]; do
  $SUDO setfacl -m u:www-data:--x "$parent"
  parent="$(dirname "$parent")"
done
$SUDO setfacl -R -m u:www-data:rwX -m u:"$(id -un)":rwX var
$SUDO setfacl -dR -m u:www-data:rwX -m u:"$(id -un)":rwX var

echo "==> Configuration Apache locale sur http://localhost:8080"
if ! grep -qE '^Listen 127\.0\.0\.1:8080$' /etc/apache2/ports.conf; then
  echo 'Listen 127.0.0.1:8080' | $SUDO tee -a /etc/apache2/ports.conf >/dev/null
fi

$SUDO tee /etc/apache2/sites-available/symfoerp.conf >/dev/null <<EOF
<VirtualHost 127.0.0.1:8080>
    ServerName localhost
    DocumentRoot "$ROOT_DIR/public"

    <Directory "$ROOT_DIR/public">
        Options FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/symfoerp-error.log
    CustomLog ${APACHE_LOG_DIR}/symfoerp-access.log combined
</VirtualHost>
EOF

$SUDO a2enmod rewrite
$SUDO a2dissite 000-default >/dev/null 2>&1 || true
$SUDO a2ensite symfoerp
$SUDO apache2ctl configtest
$SUDO systemctl reload apache2

echo "==> Vérification Symfony et migrations"
php bin/console --version
php bin/console doctrine:migrations:list --no-ansi
php bin/console doctrine:migrations:migrate --no-interaction --no-ansi
php bin/console doctrine:schema:validate --skip-sync --no-ansi

echo "==> Vérification HTTP locale"
if ! curl --connect-timeout 3 --max-time 10 --fail --silent http://127.0.0.1:8080/healthz >/dev/null; then
  $SUDO journalctl -u apache2 -n 60 --no-pager
  echo "Le endpoint /healthz ne répond pas. Vérifiez les logs Apache." >&2
  exit 1
fi

read -r -p "E-mail du premier administrateur : " admin_email
read -r -s -p "Mot de passe administrateur (12 caractères minimum) : " admin_password
printf '\n'
if [[ -z "$admin_email" || ${#admin_password} -lt 12 ]]; then
  unset admin_password
  echo "E-mail requis et mot de passe d'au moins 12 caractères." >&2
  exit 1
fi
SYMFOERP_ADMIN_EMAIL="$admin_email" SYMFOERP_ADMIN_PASSWORD="$admin_password" php bin/console app:user:create
unset admin_password

echo
echo "Installation native de SYMFOERP terminée."
echo "Connexion : http://localhost:8080/login"
echo "Logs Apache : sudo journalctl -u apache2 -f"
echo "Base MariaDB : symfoerp (utilisateur applicatif : symfoerp)"
echo "Sauvegarde SQL : sudo mariadb-dump symfoerp > symfoerp-backup.sql"
