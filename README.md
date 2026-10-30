# SYMFOERP

SYMFOERP est un ERP natif construit sur Symfony 7.4 et MariaDB. **Symfony constitue le cœur métier autonome** : authentification, tiers, ventes, achats, stocks et comptabilité vivent dans leur propre application et leur propre base. Dolibarr est facultatif et n'est ni une bibliothèque, ni un service requis au démarrage de SYMFOERP.

La première étape est une installation locale neuve de Symfony, vérifiée par migrations et parcours HTTP. La reprise de données historiques reste hors périmètre de cette première installation.

## État actuel

- Symfony 7.4 : application ERP autonome avec authentification.
- MariaDB dédiée : schéma créé exclusivement par les migrations Doctrine.
- Modules métier : tiers, produits, ventes, achats, trésorerie et comptabilité en cours de consolidation.
- Docker Compose : démarrage indépendant du service Symfony et de sa base.
- Dolibarr 24.0.2 : environnement facultatif de comparaison, activé uniquement avec le profil `comparison`, base et réseau séparés. Les anciennes versions ne sont pas une cible de compatibilité prioritaire.
- GitHub Actions : contrôles PHP, invariants métier, installation Symfony neuve et tests HTTP authentifiés.

Aucun code Dolibarr n'est chargé dans le runtime Symfony et aucune donnée Dolibarr n'est nécessaire à son fonctionnement.

## Prérequis

- Docker Engine
- Docker Compose v2
- Git

## Première installation locale Symfony (sans Dolibarr)

Prérequis : Docker Engine, Docker Compose v2, Git, Python 3 et OpenSSL.

```bash
git clone https://github.com/claudeia206/SYMFOERP.git
cd SYMFOERP
bash bin/install-symfony-local.sh
```

Le script construit l'image Symfony, génère les secrets Symfony manquants dans `.env.docker`, démarre uniquement MariaDB Symfony, applique et vérifie les migrations, crée le premier administrateur puis démarre l'application. Il demande l'adresse e-mail et le mot de passe administrateur de façon interactive. **Dolibarr n'est ni démarré ni nécessaire.**

Ouvrez ensuite http://127.0.0.1:8000/login. L'application et sa base sont accessibles uniquement en local. Pour consulter les journaux :

```bash
docker compose --env-file .env.docker logs -f symfony
```

Pour arrêter les services sans supprimer les données :

```bash
docker compose --env-file .env.docker down
```

Pour une réinitialisation complète de la base Symfony (destruction irréversible des données locales) :

```bash
docker compose --env-file .env.docker down --volumes
```

## Dolibarr (environnement séparé et facultatif)

Dolibarr reste disponible pour des essais de comparaison, mais il n'est pas une dépendance de SYMFOERP et son démarrage n'est pas requis. Pour démarrer l’instance de comparaison neuve (version de référence 24.0.2 uniquement), configurez d’abord les variables `DOLI_*` dans `.env.docker`, puis activez explicitement le profil facultatif :

```bash
docker compose --profile comparison --env-file .env.docker up --detach db_dolibarr dolibarr
```

Accès Dolibarr : http://127.0.0.1:8080. Les deux applications utilisent des bases et des réseaux Docker distincts ; aucune donnée Dolibarr n'est importée dans Symfony.

Cette configuration est destinée au développement local, pas à une exposition directe sur Internet. Pour un déploiement public, prévoir un reverse proxy HTTPS, des secrets gérés hors du dépôt, des sauvegardes testées et une configuration Symfony en production.

**Sécurité Symfony :** les routes applicatives exigent une session authentifiée (`ROLE_USER`). La connexion utilise le hachage de mots de passe Symfony, un jeton CSRF, une limitation à cinq tentatives par période de quinze minutes et une entité utilisateur distincte des comptes Dolibarr. La création du premier administrateur se fait exclusivement en ligne de commande ; aucune inscription publique n'est exposée.

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

## Améliorations de conception par rapport au socle Dolibarr

**Compteur actuel : 4 axes d'amélioration traités ou renforcés dans SYMFOERP.**

Ce compteur mesure les axes de conception adressés par le projet ; il ne prétend pas recenser quatre bogues officiellement reconnus dans le code amont de Dolibarr.

1. **Séparation des responsabilités et des données** — Dolibarr conserve son runtime et sa base dédiés ; les fonctions portées dans Symfony disposent de leur propre architecture et de leur propre base. Aucune modification directe des données Dolibarr.
2. **Authentification et contrôle d'accès Symfony** — comptes et rôles indépendants, routes protégées, mots de passe hachés, protection CSRF, limitation des tentatives et création contrôlée du premier administrateur.
3. **Règles métier explicites et validations renforcées** — contrôles des transitions de documents, des dates, des quantités, des soldes, des références et des données obligatoires avant émission ou traitement.
4. **Fiabilité des opérations et traçabilité** — transactions et verrouillages sur les opérations sensibles, contraintes d'unicité, numérotation protégée, conservation des instantanés commerciaux et historique des mouvements de stock et des opérations comptables.

Le compteur évoluera uniquement lorsqu'un nouvel axe sera effectivement implémenté et documenté. Les fonctions restent en cours de développement ; elles ne constituent pas une preuve de conformité complète ni une comparaison exhaustive avec toutes les éditions et configurations de Dolibarr.

## Feuille de route

1. Stabiliser l’installation neuve et la première mise en service locale de SYMFOERP.
2. Consolider les modules natifs Symfony : comptabilité, ventes, achats, stocks et trésorerie.
3. Maintenir Dolibarr 24.0.2 uniquement comme environnement facultatif de comparaison, sans en faire une dépendance.
4. Reporter la compatibilité et la reprise des données des anciennes versions de Dolibarr : elles ne sont pas prioritaires et feront l’objet d’un chantier distinct si nécessaire.

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
