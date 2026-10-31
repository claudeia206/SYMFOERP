#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

for command in docker openssl curl; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Prérequis manquant : $command" >&2
        exit 1
    fi
done
if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose v2 est requis." >&2
    exit 1
fi

if [[ ! -f .env.docker ]]; then
    umask 077
    cat > .env.docker <<EOF
APP_SECRET=$(openssl rand -hex 32)
SYMFONY_DB_NAME=symfoerp
SYMFONY_DB_USER=symfoerp
SYMFONY_DB_PASSWORD=$(openssl rand -hex 24)
SYMFONY_DB_ROOT_PASSWORD=$(openssl rand -hex 24)
EOF
    echo "Fichier .env.docker créé avec des secrets aléatoires."
else
    echo "Fichier .env.docker existant conservé."
fi

docker compose --env-file .env.docker config --quiet
docker compose --env-file .env.docker up --detach --build

echo "Attente de la disponibilité de Symfony..."
ready=0
for attempt in $(seq 1 60); do
    if curl --connect-timeout 2 --max-time 5 --fail --silent http://127.0.0.1:8000/healthz >/dev/null; then
        ready=1
        break
    fi
    sleep 2
done
if [[ "$ready" -ne 1 ]]; then
    docker compose --env-file .env.docker logs --tail=100 symfony db_symfony
    echo "Symfony n'est pas devenu disponible." >&2
    exit 1
fi

# Run the exact application image in PHP CLI mode on the existing Compose network.
set -a
. ./.env.docker
set +a
symfoerp_network="$(docker inspect --format '{{range $name, $network := .NetworkSettings.Networks}}{{printf "%s" $name}}{{end}}' symfoerp-symfony)"
symfoerp_image="$(docker inspect --format '{{.Image}}' symfoerp-symfony)"
symfoerp_database_url="mysql://${SYMFONY_DB_USER}:${SYMFONY_DB_PASSWORD}@db_symfony:3306/${SYMFONY_DB_NAME}?serverVersion=mariadb-11.4.2&charset=utf8mb4"
console() {
    docker run --rm --network "$symfoerp_network" \
        -e APP_ENV=dev -e APP_DEBUG=1 -e APP_SECRET="$APP_SECRET" \
        -e DATABASE_URL="$symfoerp_database_url" \
        "$symfoerp_image" php /app/bin/console "$@"
}
console doctrine:migrations:migrate --no-interaction
console doctrine:schema:validate

read -r -p "E-mail du premier administrateur : " admin_email
read -r -s -p "Mot de passe administrateur (12 caractères minimum) : " admin_password
printf '\n'
if [[ -z "$admin_email" || ${#admin_password} -lt 12 ]]; then
    echo "E-mail requis et mot de passe d'au moins 12 caractères." >&2
    exit 1
fi
docker run --rm --network "$symfoerp_network" \
    -e APP_ENV=dev -e APP_DEBUG=1 -e APP_SECRET="$APP_SECRET" \
    -e DATABASE_URL="$symfoerp_database_url" \
    -e SYMFOERP_ADMIN_EMAIL="$admin_email" \
    -e SYMFOERP_ADMIN_PASSWORD="$admin_password" \
    "$symfoerp_image" php /app/bin/console app:user:create
unset admin_password

echo
echo "Installation SYMFOERP terminée : http://localhost:8000/login"
echo "Les données sont conservées dans les volumes Docker. Pour arrêter :"
echo "  docker compose --env-file .env.docker down"
