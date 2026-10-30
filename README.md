# SYMFOERP

Projet ERP dont l'objectif est de faire évoluer progressivement Dolibarr vers une architecture Symfony. **La priorité actuelle est une installation neuve et fonctionnelle de Dolibarr 24.0.2.** La reprise des données et la migration d'anciennes installations sont reportées.

## État actuel

- Symfony 7.4 : socle applicatif initial.
- Dolibarr 24.0.2 : code source officiel référencé dans le sous-module Git `dolibarr/`.
- Docker Compose : installation neuve de Dolibarr avec sa propre base MariaDB et volumes persistants.
- GitHub Actions : validation de Compose, construction et vérification de Symfony, et démarrage HTTP des deux applications.
- Aucune ancienne base n'est importée ou modifiée.

Pendant cette première phase, Dolibarr démarre avec son runtime officiel dans un conteneur dédié. Cela permet de valider une installation propre avant de porter progressivement ses fonctions dans Symfony. Le code Dolibarr n'est pas chargé directement comme un bundle Symfony.

## Prérequis

- Docker Engine
- Docker Compose v2
- Git

## Installation neuve

```bash
git clone https://github.com/claudeia206/SYMFOERP.git
cd SYMFOERP
git submodule update --init --recursive
cp .env.docker.example .env.docker
```

Éditez `.env.docker` et remplacez impérativement tous les secrets d'exemple, notamment le mot de passe administrateur Dolibarr et les mots de passe MariaDB. Vous pouvez générer des valeurs aléatoires avec `openssl rand -hex 32`. Pour éviter d'exposer une instance de développement sur le réseau local, les ports HTTP sont liés à `127.0.0.1` : ils sont accessibles uniquement depuis la machine hôte. Puis lancez :

```bash
docker compose --env-file .env.docker config
docker compose --env-file .env.docker pull
docker compose --env-file .env.docker up -d --build
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs -f dolibarr
```

Accès :

- Dolibarr 24.0.2 : http://localhost:8080
- Symfony : http://localhost:8000

Cette configuration est destinée au développement local, pas à une exposition directe sur Internet. Pour un déploiement public, prévoir un reverse proxy HTTPS, des secrets gérés hors du dépôt, des sauvegardes testées et une configuration Symfony en production.

**Sécurité Symfony :** les routes applicatives exigent désormais une session authentifiée (`ROLE_USER`). La connexion utilise le hachage de mots de passe Symfony, un jeton CSRF, une limitation à cinq tentatives par période de quinze minutes et une entité utilisateur distincte des comptes Dolibarr. La création du premier administrateur se fait exclusivement en ligne de commande ; aucune inscription publique n'est exposée. Le port 8000 reste réservé au développement local : une mise en production nécessite encore un reverse proxy HTTPS, une gestion des secrets hors du dépôt, des sauvegardes testées et une revue de sécurité.

L'image officielle Dolibarr est configurée pour initialiser automatiquement une nouvelle instance au premier démarrage. Les paramètres `DOLI_ADMIN_LOGIN` et `DOLI_ADMIN_PASSWORD` définissent le compte administrateur initial. Le chargement des données de démonstration est désactivé.

## Intégration continue

Le workflow [Validate application](.github/workflows/validate.yml) s'exécute sur chaque push de branche, sur les Pull Requests vers `main` et manuellement. Il vérifie la configuration Compose, construit l'image Symfony, contrôle la syntaxe PHP et les invariants métier, applique les migrations, crée un administrateur temporaire, vérifie la connexion avec session et CSRF ainsi que la protection des routes, puis démarre MariaDB et Dolibarr pour contrôler la réponse HTTP d'une instance neuve.

## Premier modèle métier Symfony

Le modèle initial des tiers (clients, prospects et fournisseurs) et sa migration sont en place. La migration concerne uniquement la base Symfony ; elle n’importe ni ne modifie les données Dolibarr. La liste est paginée par 25 fiches, avec recherche sur le nom, l’e-mail et le téléphone et filtrage par catégorie ; les filtres sont conservés pendant la navigation.

## Module ventes : devis

Le premier périmètre du module Ventes permet de créer et consulter des devis rattachés aux fiches clients Symfony. Chaque devis reçoit un numéro de la forme `DEV-AAAA-000001`, une date et une date limite de validité. Les lignes enregistrent une désignation, une quantité, un prix unitaire HT et un taux de TVA ; les montants HT, TVA et TTC sont calculés ligne par ligne et agrégés au niveau du devis.

Le cycle de vie initial est volontairement contrôlé : `Brouillon → Envoyé → Accepté` ou `Refusé`. Les transitions sont protégées par CSRF et une transition terminale ne peut pas être réouverte depuis l'interface. Les devis peuvent être exportés en PDF A4 avec Dompdf ; le chargement de ressources distantes et l'exécution de PHP dans les documents sont désactivés.

Un devis accepté peut être converti en commande client. La commande reçoit un numéro `CMD-AAAA-000001` et conserve une copie indépendante des lignes, quantités, prix et taux de TVA du devis accepté. Une contrainte unique en base et un verrouillage transactionnel garantissent qu'un devis ne produit qu'une seule commande, y compris lors de conversions concurrentes. Une commande peut ensuite être terminée ou annulée ; ces transitions sont protégées par CSRF et les statuts terminaux ne sont pas réouverts depuis l'interface. Les vues de devis et de commandes sont accessibles depuis la navigation principale.

Une commande peut produire une facture brouillon unique dans la V1. La facture copie indépendamment le client, les lignes, les quantités, les prix et les taux de TVA. Les adresses de facturation sont gérées sur les fiches tiers. Le délai de paiement et la date d’échéance sont configurables dans les paramètres de l’entreprise. La création est idempotente et protégée par CSRF et verrouillage transactionnel.

L’identité légale du vendeur se configure dans **Entreprise** : raison sociale, adresse, SIREN/SIRET, informations complémentaires et conditions de paiement. L’émission est bloquée tant que les champs requis du vendeur et l’adresse de facturation du client ne sont pas renseignés. Au moment de l’émission, SYMFOERP attribue un numéro annuel `FAC-AAAA-000001` au moyen d’un compteur verrouillé en base et fige les coordonnées du vendeur et du client dans la facture. Le PDF de facture n’est disponible qu’après émission. La mention de régime de TVA, les conditions de paiement, le taux de pénalité de retard et l’indemnité de recouvrement doivent être saisis et vérifiés par l’entreprise.

Le modèle de règlements accepte virement, carte, espèces, chèque ou autre, avec cumul encaissé, solde restant, historique et statuts partiellement réglé/payé/en retard. Les paiements sont refusés sur les brouillons, s’ils sont nuls/négatifs, s’ils dépassent le solde ou s’ils sont associés à une autre facture. Les paiements partiels et complets sont protégés par verrouillage transactionnel.

**Important :** ces fonctions constituent un socle technique et ne valent pas certification de conformité fiscale ou comptable. Avant une utilisation réelle, faites valider les mentions et paramètres applicables à votre activité (notamment régime de TVA, forme juridique, RCS, pénalités et cas particuliers) par votre expert-comptable ou conseil. Une facture émise ne doit pas être modifiée ni supprimée par les opérations ordinaires de l’application.

Les migrations `Version20261030140000` à `Version20261030181200` créent les tables de devis, commandes, factures, règlements, profil légal et compteur annuel dans la base Symfony. Elles ne touchent pas à la base Dolibarr. Les commandes de migration existantes restent la méthode d'installation du schéma.

Après le démarrage Docker, initialisez le schéma Symfony avec :

```bash
docker compose --env-file .env.docker exec symfony php bin/console doctrine:migrations:migrate --no-interaction
```

Créez ensuite le premier administrateur. Le mot de passe est transmis uniquement à l'environnement du processus de commande, jamais comme argument visible dans l'historique shell :

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

Connectez-vous ensuite sur http://localhost:8000/login. Les comptes et rôles Symfony sont stockés dans `app_user`, dans la base Symfony ; ils ne sont pas synchronisés avec les utilisateurs Dolibarr.

## Persistance

Les données sont conservées dans des volumes Docker :

- `dolibarr_database` : base de données neuve Dolibarr ;
- `dolibarr_documents` : documents et fichiers déposés ;
- `dolibarr_custom` : modules personnalisés ;
- `symfony_database` : base de développement Symfony ;
- `symfony_var` : cache et fichiers temporaires Symfony.

Arrêter les conteneurs sans supprimer les données :

```bash
docker compose --env-file .env.docker down
```

**Attention :** supprimer les volumes détruit les bases et les documents :

```bash
docker compose --env-file .env.docker down -v
```

## Installation locale Symfony sans Docker

```bash
composer install
cp .env .env.local
# Configurer DATABASE_URL et APP_SECRET dans .env.local
php bin/console cache:clear
symfony server:start
```

## Feuille de route

1. Démarrer et valider l'installation neuve Dolibarr 24.0.2.
2. Vérifier les modules natifs, les permissions, les documents et les tâches planifiées.
3. Faire évoluer progressivement l'interface et les services métier dans Symfony, module par module.
4. Organiser ultérieurement la reprise des données des anciennes versions, après sauvegardes et tests dédiés.

## Licence

Dolibarr est distribué sous **GNU GPL-3.0-or-later**. Le sous-module officiel conserve ses notices. Consulter `dolibarr/COPYRIGHT` et `dolibarr/COPYING` pour les licences des composants embarqués.


## Catalogue produits et stock

Le module **Produits** permet de gérer les références SKU uniques, désignations, descriptions, unités, prix d’achat et de vente HT, taux de TVA et activation/archivage. La liste propose recherche, pagination, filtre actif/archivé et alertes de seuil de stock. Le suivi de stock est optionnel par produit. Les entrées et sorties sont enregistrées comme mouvements horodatés (réception achat, sortie vente, retour, perte/casse, correction) avec motif et commentaire. Chaque mouvement est protégé par CSRF et verrouillage transactionnel ; une sortie qui rendrait le stock négatif est refusée. Les produits archivés et leur historique sont conservés. La migration `Version20261030181300` crée les tables `app_product` et `app_product_stock_movement`. La migration `Version20261030181400` relie facultativement les lignes de devis, commandes et factures aux références du catalogue. Lors de la création d’un devis, un produit actif peut préremplir désignation, prix de vente HT et TVA ; les montants de ligne restent copiés dans les documents commerciaux afin de préserver l’historique tarifaire.


## Achats fournisseurs et réceptions

Le module **Achats** permet de créer des commandes fournisseurs à partir des fiches tiers de catégorie fournisseur et du catalogue de produits actifs. Les lignes conservent un instantané de la désignation, du prix d’achat HT et du taux de TVA au moment de la commande. Les commandes reçoivent un numéro `ACH-AAAA-000001` et suivent les états envoyée, partiellement réceptionnée ou réceptionnée. La réception peut être fractionnée sur plusieurs livraisons : chaque quantité saisie est vérifiée par rapport au reliquat restant, sous verrou transactionnel. Pour les produits avec suivi de stock activé, chaque réception augmente le stock et crée un mouvement horodaté de motif « achat ». Les produits sans suivi de stock conservent le suivi des quantités reçues sans modifier le stock. Les migrations `Version20261030181500` créent les commandes et lignes d’achat. Le workflow CI inclut un test des invariants de commande et de réception ; son exécution doit être vérifiée dans GitHub Actions après push.


## Sorties de stock sur livraison client

Lorsqu'une commande client passe à l'état **Terminée**, les quantités des lignes liées à des produits avec suivi de stock sont déduites automatiquement. Chaque sortie crée un mouvement négatif de motif « vente ». Les lignes libres et les produits sans suivi de stock ne modifient pas les quantités disponibles. La transition et les mouvements sont enregistrés dans une transaction ; la commande et les produits sont verrouillés, puis l'état de la commande est relu après verrouillage pour éviter une double déduction lors de requêtes concurrentes. Si le stock est insuffisant, la transaction est annulée et la commande reste en cours. Le test métier des devis/commandes couvre également la règle de décrémentation et le mouvement de vente.


## Factures fournisseurs et règlements sortants

La V1 du module **Factures fournisseurs** rattache une facture à une commande fournisseur déjà partiellement ou totalement réceptionnée. La référence de facture du fournisseur est obligatoire et unique pour ce fournisseur ; une seule facture est autorisée par commande dans cette première version. Les lignes sont générées depuis les quantités effectivement réceptionnées et reprennent les désignations, prix d'achat HT et taux de TVA enregistrés sur la commande. La facture ne crée aucun mouvement de stock : celui-ci est déjà enregistré à la réception de la commande. Après validation de la facture, les règlements sortants (virement, carte, espèces, chèque ou autre) sont suivis avec cumul payé, solde restant et statut partiellement réglé/payé/en retard. La migration `Version20261030181600` ajoute les tables de factures fournisseurs, lignes et règlements. Les règles d'entité sont couvertes par un test smoke dédié dans le workflow de validation. Cette V1 ne constitue pas un rapprochement comptable certifié et ne remplace pas le contrôle de la facture originale du fournisseur.
