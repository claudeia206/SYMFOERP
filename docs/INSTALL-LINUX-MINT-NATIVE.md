# Installation native de SYMFOERP sur Linux Mint (sans Docker)

Cette procédure installe directement sur l'hôte Linux Mint : PHP 8.3, Composer, Apache et MariaDB. Aucun conteneur ni Docker n'est utilisé. Dolibarr n'est pas requis.

> **Systèmes pris en charge par ce script :** Linux Mint basé sur Ubuntu (éditions Cinnamon, MATE et Xfce). **LMDE n'est pas pris en charge par ce script** : LMDE est basé sur Debian et nécessite une procédure séparée.

## 1. Identifier la base Linux Mint

Ouvrir un terminal :

```bash
cat /etc/os-release
. /etc/os-release
printf 'Mint: %s\nUbuntu base: %s\n' "$VERSION_CODENAME" "${UBUNTU_CODENAME:-non détectée}"
```

Le script exige que `UBUNTU_CODENAME` soit renseigné. Il utilise le dépôt Ubuntu correspondant, et non le nom de code Linux Mint.

## 2. Récupérer le code

```bash
git clone https://github.com/claudeia206/SYMFOERP.git
cd SYMFOERP
git switch main
git pull --ff-only origin main
```

Si le dépôt est déjà présent, entrez dans le répertoire puis mettez `main` à jour avec les deux dernières commandes.

## 3. Lancer l'installation native

```bash
bash bin/install-symfony-native.sh
```

Le script demande le mot de passe sudo et effectue les opérations suivantes :

- installe Apache, MariaDB, ACL et les outils système ;
- installe PHP 8.3 et les extensions nécessaires à Symfony/Doctrine ;
- active le PPA PHP pour la base Ubuntu uniquement si PHP 8.3 n'est pas disponible dans les dépôts configurés ;
- démarre Apache et MariaDB ;
- crée la base `symfoerp` et un compte SQL applicatif dédié ;
- génère `.env.local` avec un secret Symfony et un mot de passe SQL aléatoires si le fichier n'existe pas ;
- installe les dépendances Composer ;
- configure les permissions du cache et des logs ;
- configure Apache sur l'adresse locale `127.0.0.1:8080`, avec `public/` comme racine web ;
- applique les migrations Doctrine et valide les mappings ;
- vérifie le endpoint de santé et demande les identifiants du premier administrateur.

L'installation ne remplace pas un fichier `.env.local` déjà présent et ne réinitialise pas une base existante.

## 4. Se connecter

Ouvrir :

**http://localhost:8080/login**

Le virtualhost SYMFOERP écoute uniquement sur `127.0.0.1:8080` : l'application n'est pas exposée au réseau local ou à Internet. Le service Apache peut conserver d'autres ports ou virtualhosts déjà configurés sur la machine.

## 5. Commandes utiles

État des services :

```bash
sudo systemctl status apache2 mariadb --no-pager
```

Journaux Apache :

```bash
sudo tail -f /var/log/apache2/symfoerp-error.log
```

Journaux Symfony :

```bash
tail -f var/log/dev.log
```

Vérifier la santé HTTP :

```bash
curl -i http://127.0.0.1:8080/healthz
```

Appliquer les migrations après une mise à jour du code :

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

Vider le cache :

```bash
php bin/console cache:clear
```

**Important — gestion du schéma :** les tables comptables et de séquençage utilisées directement par les services DBAL sont volontairement exclues du périmètre de Doctrine ORM. Les migrations Doctrine restent leur source de vérité. N'utilisez jamais `doctrine:schema:update --force` pour synchroniser la base ; appliquez les migrations versionnées après sauvegarde.

### Récupérer l'accès administrateur

Si le mot de passe administrateur est perdu, il peut être remplacé depuis le terminal local, sans supprimer le compte ni toucher à ses rôles. Depuis la racine du projet :

~~~bash
read -r -p "E-mail du compte existant : " SYMFOERP_ADMIN_EMAIL
read -r -s -p "Nouveau mot de passe (12 caractères minimum) : " SYMFOERP_ADMIN_PASSWORD
printf '\\n'
SYMFOERP_ADMIN_EMAIL="$SYMFOERP_ADMIN_EMAIL" \\
SYMFOERP_ADMIN_PASSWORD="$SYMFOERP_ADMIN_PASSWORD" \\
php bin/console app:user:password-reset
unset SYMFOERP_ADMIN_EMAIL SYMFOERP_ADMIN_PASSWORD
~~~

Cette commande ne crée aucun compte : l'adresse doit déjà exister. Elle doit être exécutée par une personne ayant un accès shell de confiance à la machine et au projet. Le mot de passe n'est pas passé en argument de commande ni enregistré dans l'historique du shell.

## 6. Sauvegarde de la base

Créer une sauvegarde SQL :

```bash
sudo mariadb-dump --single-transaction symfoerp > "$HOME/symfoerp-$(date +%F-%H%M).sql"
```

Restaurer une sauvegarde (attention : les données actuelles de la base seront remplacées par le contenu restauré) :

```bash
sudo mariadb symfoerp < "$HOME/symfoerp-backup.sql"
```

Sauvegarder également le fichier `.env.local` dans un emplacement privé et chiffré : il contient les secrets nécessaires à l'application. Ne le commitez jamais et ne le partagez pas.

## 7. Dépannage

- **PHP 8.3 introuvable** : vérifier le dépôt PPA PHP et relancer `sudo apt update`.
- **LMDE** : ne pas lancer ce script ; demander la procédure Debian dédiée.
- **Erreur d'accès Apache** : vérifier les ACL des dossiers parents et les permissions de `var/`.
- **Erreur SQL** : vérifier `sudo systemctl status mariadb` et le contenu local de `.env.local`.
- **Port 8080 occupé** : identifier le processus avec `sudo ss -ltnp | grep 8080` avant de modifier la configuration Apache.
- **Migration échouée** : consulter l'erreur complète, sauvegarder la base si elle contient des données, puis corriger la cause avant de relancer. Ne supprimez pas la base pour contourner l'erreur.

## Limites et sécurité

Cette installation est prévue pour le développement et la recette sur une machine locale. Elle utilise `APP_ENV=dev` et Apache/PHP directement sur l'hôte. Ne l'exposez pas sur Internet en l'état. Un déploiement de production nécessite notamment une configuration Symfony de production, HTTPS, une gestion dédiée des secrets, des sauvegardes testées, une supervision et une revue de sécurité.

Le script installe des paquets système et configure Apache/MariaDB : lisez-le avant exécution. Il ne lance pas `mariadb-secure-installation` et ne modifie pas le compte root SQL administré par socket sur les distributions Ubuntu récentes.
