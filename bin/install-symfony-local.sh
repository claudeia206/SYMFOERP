#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

for command in docker python3 openssl; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "Commande requise introuvable : $command" >&2
    exit 1
  fi
done

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose v2 est requis." >&2
  exit 1
fi

if [[ ! -f .env.docker ]]; then
  cp .env.docker.example .env.docker
  echo "Fichier .env.docker créé à partir du modèle."
fi

# The file contains application and database credentials.
chmod 600 .env.docker

python3 - <<'PY'
from pathlib import Path
import secrets

path = Path(".env.docker")
lines = path.read_text().splitlines()
generated = {
    "APP_SECRET": secrets.token_hex(32),
    "SYMFONY_DB_PASSWORD": secrets.token_urlsafe(32),
    "SYMFONY_DB_ROOT_PASSWORD": secrets.token_urlsafe(32),
}
updated = []
for line in lines:
    if "=" in line and not line.lstrip().startswith("#"):
        key, value = line.split("=", 1)
        if key in generated and (not value or "replace-with-" in value):
            line = f"{key}={generated[key]}"
    updated.append(line)
path.write_text("\n".join(updated) + "\n")
PY

read -r -p "E-mail du premier administrateur SYMFOERP : " SYMFOERP_ADMIN_EMAIL
if [[ ! "$SYMFOERP_ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; then
  echo "Adresse e-mail invalide." >&2
  exit 1
fi
read -r -s -p "Mot de passe administrateur (12 caractères minimum) : " SYMFOERP_ADMIN_PASSWORD
echo
if (( ${#SYMFOERP_ADMIN_PASSWORD} < 12 )); then
  echo "Le mot de passe doit contenir au moins 12 caractères." >&2
  exit 1
fi
export SYMFOERP_ADMIN_EMAIL SYMFOERP_ADMIN_PASSWORD

compose=(docker compose --env-file .env.docker)

echo "Construction de l'image Symfony..."
"${compose[@]}" build symfony

echo "Démarrage de la base MariaDB dédiée à Symfony..."
"${compose[@]}" up --detach db_symfony
for attempt in $(seq 1 60); do
  health="$(docker inspect --format '{{.State.Health.Status}}' symfoerp-db 2>/dev/null || true)"
  if [[ "$health" == "healthy" ]]; then
    break
  fi
  sleep 2
done
if [[ "${health:-}" != "healthy" ]]; then
  "${compose[@]}" logs --tail=100 db_symfony
  echo "La base Symfony n'est pas devenue healthy." >&2
  exit 1
fi

echo "Installation du schéma Symfony..."
"${compose[@]}" run --rm --no-deps symfony php bin/console doctrine:migrations:sync-metadata-storage
"${compose[@]}" run --rm --no-deps symfony php bin/console doctrine:migrations:migrate --no-interaction
"${compose[@]}" run --rm --no-deps symfony php bin/console doctrine:migrations:status
"${compose[@]}" run --rm --no-deps symfony php bin/console doctrine:query:sql "SELECT COUNT(*) AS user_count FROM app_user"
"${compose[@]}" run --rm --no-deps symfony php bin/console doctrine:schema:validate --skip-sync

echo "Création du compte administrateur..."
"${compose[@]}" run --rm --no-deps -e SYMFOERP_ADMIN_EMAIL -e SYMFOERP_ADMIN_PASSWORD symfony php bin/console app:user:create
unset SYMFOERP_ADMIN_EMAIL SYMFOERP_ADMIN_PASSWORD

echo "Démarrage de l'application Symfony..."
"${compose[@]}" up --detach symfony

echo
echo "Installation Symfony terminée."
echo "Connexion : http://127.0.0.1:8000/login"
echo "État des services : ${compose[*]} ps"
echo "Journaux Symfony : ${compose[*]} logs -f symfony"
echo "Dolibarr n'a pas été démarré et n'est pas requis par cette installation."
