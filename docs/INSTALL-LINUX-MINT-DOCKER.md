# Installer SYMFOERP avec Docker sur Linux Mint

Ce guide concerne Linux Mint Cinnamon/MATE/Xfce basé sur Ubuntu. Pour LMDE (Linux Mint Debian Edition), utiliser les instructions Docker pour Debian correspondant à la version Debian de LMDE : le dépôt Ubuntu ci-dessous ne convient pas.

SYMFOERP utilise Docker Compose, Symfony/PHP 8.3 et MariaDB 11.4. Dolibarr n'est pas requis.

## 1. Identifier la base Ubuntu de Linux Mint

Dans un terminal :

```bash
cat /etc/os-release
. /etc/os-release
printf 'Mint: %s\nUbuntu base: %s\n' "$VERSION_CODENAME" "${UBUNTU_CODENAME:-non détectée}"
```

Sur Mint basé sur Ubuntu, `UBUNTU_CODENAME` doit être renseigné (par exemple `noble` ou `jammy`). Si cette variable est vide, arrêtez-vous et consultez la procédure Debian de Docker plutôt que d'utiliser le nom de code Mint.

## 2. Installer Docker Engine et le plugin Compose

Installer le dépôt APT officiel Docker :

```bash
sudo apt update
sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

. /etc/os-release
DOCKER_UBUNTU_CODENAME="${UBUNTU_CODENAME:-}"
if [ -z "$DOCKER_UBUNTU_CODENAME" ]; then
  echo "Base Ubuntu non détectée. Ne pas continuer avec ce dépôt." >&2
  exit 1
fi

sudo tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $DOCKER_UBUNTU_CODENAME
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
```

Si APT signale un conflit avec une ancienne installation Docker, identifiez les paquets concernés avant de les retirer. Ne supprimez pas `/var/lib/docker` : ce répertoire peut contenir des données existantes.

Vérifier le moteur et Compose :

```bash
sudo docker run --rm hello-world
docker compose version
sudo systemctl status docker --no-pager
```

Pour utiliser Docker sans `sudo`, on peut ajouter son compte au groupe `docker`, puis fermer et rouvrir la session :

```bash
sudo usermod -aG docker "$USER"
```

**Sécurité :** le groupe `docker` donne des privilèges équivalents à root sur la machine. Ne l'accordez qu'à un compte de confiance. Sinon, gardez simplement le préfixe `sudo` pour les commandes Docker.

## 3. Récupérer SYMFOERP

```bash
git clone https://github.com/claudeia206/SYMFOERP.git
cd SYMFOERP
git switch main
git pull --ff-only origin main
```

Si le dépôt est déjà présent, entrez dans son dossier et exécutez les deux dernières commandes.

Prérequis côté hôte pour le programme d'installation : Git, Docker Compose v2, OpenSSL et curl. PHP, Composer, Node.js et MariaDB ne doivent pas être installés sur l'hôte pour cette installation Docker.

## 4. Installer et créer le premier administrateur

Depuis la racine du dépôt :

```bash
bash bin/install-symfony-local.sh
```

Le script :
- crée `.env.docker` avec des secrets aléatoires et des permissions locales restrictives, sans écraser un fichier existant ;
- valide la configuration Compose ;
- construit l'image Symfony et démarre Symfony et MariaDB ;
- attend le endpoint de santé HTTP ;
- applique les migrations Doctrine et vérifie la connexion à la base ;
- demande l'adresse e-mail et le mot de passe du premier administrateur.

L'installation affiche ensuite l'adresse :

**http://localhost:8000/login**

Utilisez des données fictives pour la première recette.

## 5. Commandes utiles

Depuis le dossier du projet :

Voir l'état des services :

```bash
docker compose --env-file .env.docker ps
```

Voir les journaux :

```bash
docker compose --env-file .env.docker logs -f
```

Arrêter les conteneurs en conservant les données :

```bash
docker compose --env-file .env.docker down
```

Redémarrer :

```bash
docker compose --env-file .env.docker up -d
```

Reconstruire après une mise à jour du code :

```bash
git pull --ff-only origin main
docker compose --env-file .env.docker up -d --build
```

Les données MariaDB sont dans le volume Docker `symfony_database`. Les données Symfony du répertoire `var` sont dans `symfony_var`. Le fichier `.env.docker` contient les secrets de la base et de l'application.

**Ne lancez pas `docker compose down --volumes`** pour un simple arrêt ou redémarrage : cette commande supprime les volumes et donc les données persistantes de l'installation.

## 6. Dépannage

- **Docker permission denied** : utilisez `sudo docker ...`, ou reconnectez-vous après l'ajout au groupe `docker`.
- **Port 8000 déjà utilisé** : arrêtez le service qui l'occupe avant de relancer l'installation.
- **Symfony ne devient pas disponible** : consultez `docker compose --env-file .env.docker logs --tail=150 symfony db_symfony`.
- **Base encore en démarrage** : vérifiez `docker compose --env-file .env.docker ps` et les logs de `db_symfony`.
- **Mot de passe ou configuration modifiés après la création de la base** : les variables MariaDB ne réinitialisent pas un volume déjà initialisé. Ne supprimez pas le volume pour contourner le problème sans sauvegarde.

## Périmètre

Cette procédure vise une installation et une recette locales. Le port applicatif est publié uniquement sur `127.0.0.1:8000`. Cette configuration n'est pas un déploiement de production exposé à Internet. Avant toute mise en production, prévoir notamment HTTPS via un reverse proxy, une gestion adaptée des secrets, des sauvegardes restaurables et une revue de sécurité.
