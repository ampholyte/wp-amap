# wp-amap

Site web pour AMAP (WordPress).

## Tester le site sans rien installer (WordPress Playground)

[WordPress Playground](https://wordpress.github.io/wordpress-playground/) fait tourner un
WordPress complet directement dans le navigateur (compilé en WebAssembly), sans serveur ni
installation. Un [Blueprint](playground/blueprint.json) prépare automatiquement une instance de
démonstration à partir de ce dépôt : thème et plugin installés, et un compte de test pour chacun
des 4 profils de l'application.

Lien direct (aucune installation, aucun compte requis) :

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ampholyte/wp-amap/main/playground/blueprint.json
```

Comptes de démonstration disponibles une fois la page chargée :

| Profil                                          | Identifiant                 | Mot de passe                       |
| ------------------------------------------------ | ---------------------------- | ----------------------------------- |
| Administrateur (cumule aussi bureau et adhérent) | `admin`                      | `password`                          |
| Bureau                                            | `bureau-demo@example.com`    | `demo1234`                          |
| Producteur                                        | `producteur-demo@example.com`| `demo1234`                          |
| Adhérent                                          | `adherent-demo@example.com`  | (aucun, uniquement un lien de connexion) |

Playground se connecte automatiquement en administrateur : pour voir l'écran de connexion et
tester les autres profils, il faut d'abord se déconnecter (barre d'admin en haut de la page, ou
`/wp-login.php?action=logout`) puis retourner sur la page « Espace adhérent ».

Limites de cet environnement de démo, propres à Playground (pas au site réel) :

- Playground ne peut pas effectuer d'appel réseau sortant vers l'API Brevo. Le Blueprint active
  donc un « mode démo » (`AMAP_DEMO_MODE`) : aucun email n'est réellement envoyé, son contenu
  (notamment le lien de connexion des adhérents) s'affiche directement à l'écran à la place.
- Rien n'est sauvegardé d'une session à l'autre : fermer l'onglet réinitialise entièrement la
  démo au prochain lancement du lien.

## Démarrer l'environnement de développement local

### Installer Docker

- **Mac / Windows** : installer [Docker Desktop](https://www.docker.com/products/docker-desktop/)
  et le lancer (il doit rester ouvert en arrière-plan pendant le développement).
- **Linux** : installer le [moteur Docker et le plugin Compose](https://docs.docker.com/engine/install/)
  (Docker Desktop n'est pas nécessaire).

Vérifier que l'installation fonctionne :

```
docker --version
docker compose version
```

### Lancer le projet

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
