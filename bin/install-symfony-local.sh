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
database_check='\$url = parse_url(getenv("DATABASE_URL"));
\$pdo = new PDO(
    "mysql:host={\$url["host"]};port=" . (\$url["port"] ?? 3306) . ";dbname=" . ltrim(\$url["path"], "/") . ";charset=utf8mb4",
    rawurldecode(\$url["user"]),
    rawurldecode(\$url["pass"]),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
\$database = \$pdo->query("SELECT DATABASE() AS database_name, @@hostname AS database_host")->fetch(PDO::FETCH_ASSOC);
\$hasUserTable = (int) \$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \"app_user\"")->fetchColumn();
if (\$hasUserTable !== 1) {
    fwrite(STDERR, "La table app_user est absente de la base " . \$database["database_name"] . PHP_EOL);
    exit(1);
}
\$userCount = (int) \$pdo->query("SELECT COUNT(*) FROM app_user")->fetchColumn();
echo json_encode(\$database + ["app_user_rows" => \$userCount], JSON_THROW_ON_ERROR) . PHP_EOL;'
echo "Vérification de la base utilisée par le conteneur CLI :"
docker run --rm --network "$symfoerp_network" \
    -e APP_ENV=dev -e APP_DEBUG=1 -e APP_SECRET="$APP_SECRET" \
    -e DATABASE_URL="$symfoerp_database_url" \
    "$symfoerp_image" php -r "$database_check"
echo "Vérification de la même base depuis le conteneur HTTP Symfony :"
docker compose --env-file .env.docker exec --no-TTY symfony php -r "$database_check"
console doctrine:schema:validate

read -r -p "E-mail du premier administrateur : " admin_email
read -r -s -p "Mot de passe administrateur (12 caractères minimum) : " admin_password
printf '\n'
if [[ -z "$admin_email" || ${#admin_password} -lt 12 ]]; then
    echo "E-mail requis et mot de passe d'au moins 12 caractères." >&2
    exit 1
fi
admin_env_file="$(mktemp)"
chmod 600 "$admin_env_file"
cleanup_admin_env() {
    rm -f "$admin_env_file"
}
trap cleanup_admin_env EXIT
{
    printf 'SYMFOERP_ADMIN_EMAIL=%s\n' "$admin_email"
    printf 'SYMFOERP_ADMIN_PASSWORD=%s\n' "$admin_password"
} > "$admin_env_file"
unset admin_password
docker run --rm --network "$symfoerp_network" --env-file "$admin_env_file" \
    -e APP_ENV=dev -e APP_DEBUG=1 -e APP_SECRET="$APP_SECRET" \
    -e DATABASE_URL="$symfoerp_database_url" \
    "$symfoerp_image" php /app/bin/console app:user:create
rm -f "$admin_env_file"
trap - EXIT

echo
echo "Installation SYMFOERP terminée : http://localhost:8000/login"
echo "Les données sont conservées dans les volumes Docker. Pour arrêter :"
echo "  docker compose --env-file .env.docker down"
