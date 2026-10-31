# SYMFOERP

ERP autonome construit avec Symfony 7.4 LTS, Doctrine et MariaDB. **Dolibarr n'est pas requis** pour installer, démarrer ou utiliser SYMFOERP. Aucune ancienne base Dolibarr n'est importée.

## Première version de test — 0.1.0-alpha.1

Cette version est destinée à une **première installation et recette fonctionnelle en local**. Elle fournit un socle Symfony autonome, une authentification avec compte administrateur et les principaux parcours de gestion commerciale, achats et comptabilité.

La validation automatisée sur environnement vierge couvre :
- construction de l'image, configuration Docker Compose et syntaxe PHP ;
- installation depuis zéro, exécution des migrations Doctrine et validation des mappings ;
- création du premier administrateur, connexion, déconnexion et protection des pages privées ;
- gestion des utilisateurs et promotion d'un compte en administrateur ;
- création d'un tiers, d'un devis, acceptation et conversion idempotente en commande ;
- génération PDF des devis et factures, création et paiement d'une facture client, émission d'un avoir ;
- catalogue produits, commandes fournisseurs, réception, factures fournisseurs et paiements ;
- écritures automatiques liées aux factures et règlements clients/fournisseurs ;
- export FEC, calcul exact des dotations aux amortissements et contrôles des périodes comptables ;
- accès aux écrans de grand livre, états financiers, TVA, balance âgée, rapprochement bancaire, relances clients et reporting.

**Important :** cette alpha n'est pas une version de production ni une certification comptable/fiscale. Les parcours non couverts par les tests automatisés doivent être vérifiés pendant la recette. La première installation sur le poste de test doit encore être effectuée par l'utilisateur.

## Installation locale (Docker)

Prérequis : Git, Docker Engine avec Docker Compose v2, OpenSSL et curl.

```bash
git clone https://github.com/claudeia206/SYMFOERP.git
cd SYMFOERP
git switch main
git pull --ff-only origin main
bash bin/install-symfony-local.sh
```

Le script :
- génère un fichier local `.env.docker` avec des secrets aléatoires s'il n'existe pas ;
- démarre Symfony et une base MariaDB dédiée ;
- attend la disponibilité HTTP de Symfony ;
- applique les migrations et valide le schéma Doctrine ;
- crée le premier administrateur de façon interactive.

Accès : http://localhost:8000/login

Les ports sont liés à `127.0.0.1`. Les données persistantes sont conservées dans les volumes Docker `symfony_database` et `symfony_var`. Arrêter les conteneurs sans supprimer les données :

```bash
docker compose --env-file .env.docker down
```

**Ne lancez pas `docker compose down --volumes` sauf si vous souhaitez détruire les données locales.** Le fichier `.env.docker` contient des secrets et ne doit jamais être partagé.

## Recette rapide après installation

1. Se connecter avec le compte administrateur créé pendant l'installation.
2. Créer un client et un fournisseur dans **Tiers**.
3. Créer un produit et vérifier son prix et son suivi de stock.
4. Créer un devis client, le faire passer à l'état envoyé puis accepté, et le convertir en commande.
5. Générer le PDF du devis, créer la facture issue de la commande et enregistrer un règlement de test.
6. Créer une commande fournisseur, enregistrer la réception, valider la facture fournisseur et son paiement.
7. Consulter le grand livre et vérifier les écritures générées.
8. Vérifier les écrans de TVA, balance âgée, trésorerie et rapprochement bancaire.
9. Dans **Administration > Utilisateurs**, créer un compte utilisateur et vérifier ses droits.

Utiliser uniquement des données fictives pendant cette recette. Ne pas importer de données de production dans cette alpha.

## Installation manuelle

Après avoir créé `.env.docker` à partir de `.env.docker.example` et remplacé les secrets, démarrer les services :

```bash
docker compose --env-file .env.docker up -d --build
```

Les commandes console doivent être exécutées dans un conteneur PHP CLI séparé du serveur HTTP. Exemple pour appliquer les migrations et valider le schéma :

```bash
set -a
. ./.env.docker
set +a
NETWORK="$(docker inspect --format '{{range $name, $network := .NetworkSettings.Networks}}{{printf "%s" $name}}{{end}}' symfoerp-symfony)"
IMAGE="$(docker inspect --format '{{.Image}}' symfoerp-symfony)"
DATABASE_URL="mysql://${SYMFONY_DB_USER}:${SYMFONY_DB_PASSWORD}@db_symfony:3306/${SYMFONY_DB_NAME}?serverVersion=mariadb-11.4.2&charset=utf8mb4"
docker run --rm --network "$NETWORK" -e APP_ENV=dev -e APP_SECRET="$APP_SECRET" -e DATABASE_URL="$DATABASE_URL" "$IMAGE" php /app/bin/console doctrine:migrations:migrate --no-interaction
docker run --rm --network "$NETWORK" -e APP_ENV=dev -e APP_SECRET="$APP_SECRET" -e DATABASE_URL="$DATABASE_URL" "$IMAGE" php /app/bin/console doctrine:schema:validate --skip-sync
```

Pour créer le premier administrateur, transmettre les identifiants au conteneur CLI via l'environnement, sans les inscrire dans l'historique des commandes :

```bash
read -r -p "E-mail administrateur : " SYMFOERP_ADMIN_EMAIL
read -r -s -p "Mot de passe (12 caractères minimum) : " SYMFOERP_ADMIN_PASSWORD
echo
docker run --rm --network "$NETWORK" \
  -e APP_ENV=dev -e APP_SECRET="$APP_SECRET" -e DATABASE_URL="$DATABASE_URL" \
  -e SYMFOERP_ADMIN_EMAIL="$SYMFOERP_ADMIN_EMAIL" \
  -e SYMFOERP_ADMIN_PASSWORD="$SYMFOERP_ADMIN_PASSWORD" \
  "$IMAGE" php /app/bin/console app:user:create
unset SYMFOERP_ADMIN_EMAIL SYMFOERP_ADMIN_PASSWORD
```

## Périmètre fonctionnel présent

- Tiers : clients, prospects et fournisseurs.
- Ventes : devis, commandes, livraisons, factures, avoirs et règlements.
- Produits : catalogue, suivi des stocks et mouvements.
- Achats : commandes, réceptions, factures fournisseurs et paiements.
- Finance : rapprochement bancaire, trésorerie, relances clients et rapports.
- Comptabilité : écritures automatiques, grand livre, états financiers, TVA, balance âgée, immobilisations (acquisition, amortissement, cession), export FEC et périodes comptables.
- Administration : comptes utilisateurs et rôles.

## Validation

Le workflow GitHub Actions valide la configuration Docker Compose, construit l'image Symfony, contrôle la syntaxe PHP, exécute les tests smoke métier, applique les migrations et vérifie les parcours HTTP authentifiés. La validation du commit de cette version est consultable dans [GitHub Actions](https://github.com/claudeia206/SYMFOERP/actions).

## Sécurité et limites

Cette configuration vise le développement local, pas l'exposition directe sur Internet. Un déploiement public exige notamment HTTPS via reverse proxy, secrets externes, sauvegardes testées, supervision et revue de sécurité. Les fonctionnalités comptables et fiscales doivent être validées par un professionnel avant utilisation en production ; le projet ne revendique aucune certification de conformité réglementaire.

## Licence

Consulter les fichiers de licence et les notices présents dans le dépôt.
