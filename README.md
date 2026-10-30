# SYMFOERP

ERP autonome construit avec Symfony 7.4 LTS, Doctrine et MariaDB. **Dolibarr n'est pas requis** pour installer, démarrer ou utiliser SYMFOERP. Aucune ancienne base Dolibarr n'est importée.

## Installation locale (Docker)

Prérequis : Git, Docker Engine avec Docker Compose v2, OpenSSL et curl.

```bash
git clone https://github.com/claudeia206/SYMFOERP.git
cd SYMFOERP
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

## Installation manuelle

```bash
cp .env.docker.example .env.docker
# Remplacer les valeurs secrètes avant le démarrage.
docker compose --env-file .env.docker up -d --build
docker compose --env-file .env.docker exec symfony php bin/console doctrine:migrations:migrate --no-interaction
docker compose --env-file .env.docker exec symfony php bin/console doctrine:schema:validate
```

Créer le premier administrateur en transmettant les identifiants via l'environnement du processus :

```bash
read -r -p "E-mail administrateur : " SYMFOERP_ADMIN_EMAIL
read -r -s -p "Mot de passe (12 caractères minimum) : " SYMFOERP_ADMIN_PASSWORD
echo
docker compose --env-file .env.docker exec \
  -e SYMFOERP_ADMIN_EMAIL="$SYMFOERP_ADMIN_EMAIL" \
  -e SYMFOERP_ADMIN_PASSWORD="$SYMFOERP_ADMIN_PASSWORD" \
  symfony php bin/console app:user:create
unset SYMFOERP_ADMIN_EMAIL SYMFOERP_ADMIN_PASSWORD
```

## Périmètre fonctionnel présent

- Tiers : clients, prospects et fournisseurs.
- Ventes : devis, commandes, livraisons, factures, avoirs et règlements.
- Produits : catalogue, suivi des stocks et mouvements.
- Achats : commandes, réceptions, factures fournisseurs et paiements.
- Finance : rapprochement bancaire, trésorerie et rapports.
- Comptabilité : écritures automatiques, grand livre, TVA, balance âgée et périodes comptables.

## Validation

Le workflow GitHub Actions valide la configuration Docker Compose, construit l'image Symfony, contrôle la syntaxe PHP, exécute les tests smoke métier, applique les migrations et vérifie les parcours HTTP authentifiés.

## Sécurité et limites

Cette configuration vise le développement local, pas l'exposition directe sur Internet. Un déploiement public exige notamment HTTPS via reverse proxy, secrets externes, sauvegardes testées, supervision et revue de sécurité. Les fonctionnalités comptables et fiscales doivent être validées par un professionnel avant utilisation en production ; le projet ne revendique aucune certification de conformité réglementaire.

## Licence

Consulter les fichiers de licence et les notices présents dans le dépôt.
