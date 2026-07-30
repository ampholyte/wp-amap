# wp-amap

Site web pour AMAP (WordPress).

## Démarrer l'environnement de développement local

Prérequis : [Docker Desktop](https://www.docker.com/products/docker-desktop/) installé et lancé.

1. Copier le fichier d'exemple des variables d'environnement :

   ```
   cp .env.example .env
   ```

   Puis éditer `.env` pour y mettre des identifiants de base de données locaux (valeurs libres, uniquement utilisées en local).

2. Démarrer les conteneurs :

   ```
   docker compose up -d
   ```

3. Ouvrir le site dans le navigateur :

   - WordPress : http://localhost:8080
   - Adminer (interface de la base de données) : http://localhost:8081
     (serveur : `db`, identifiant/mot de passe : ceux définis dans `.env`)

4. Pour arrêter les conteneurs :

   ```
   docker compose down
   ```

   (les données de la base de données sont conservées d'un démarrage à l'autre)
