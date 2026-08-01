# wp-amap — guide pour Claude Code

Site WordPress pour une AMAP : vitrine publique (présentation, actualités, producteurs, SEO) +
application métier privée (adhérents, groupes, producteurs, contrats, distributions, congés,
notifications, emails, espace adhérent).

## Profil de l'utilisateur et méthode de travail

- Développeur expérimenté (C, programmation procédurale, conception logicielle, SQL, Git) mais
  **débutant complet sur WordPress** (thèmes, plugins, hooks, Gutenberg, admin, APIs WordPress).
  Ne jamais supposer une connaissance implicite de WordPress.
- Avant de créer un fichier ou d'écrire du code : expliquer son rôle, où il se place dans
  l'arborescence WordPress, et comment WordPress va l'utiliser. Faire des analogies avec des
  concepts connus d'un développeur C/procédural quand c'est pertinent.
- Avancer par étapes courtes. Après chaque étape : expliquer ce qui a été fait, comment le
  tester, et attendre validation avant de poursuivre. Ne pas générer un gros bloc de code ou
  plusieurs fonctionnalités d'un coup.
- Ne pas committer sans demande explicite (déjà couvert par les instructions générales de
  l'outil, rappelé ici car ce projet avance par petites étapes validées une à une).

## Gestion du contexte de session

- Utiliser `/clear` entre deux étapes une fois qu'une étape a été validée par l'utilisateur (et
  committée si demandé) : chaque étape est autonome, une session vierge évite d'accumuler du
  contexte devenu inutile et garde les explications ciblées sur l'étape en cours plutôt que sur
  l'historique des étapes précédentes.
- Utiliser `/compact` quand la conversation devient longue **au milieu d'une étape non encore
  validée** (débogage en cours, aller-retour sur une même fonctionnalité) : contrairement à
  `/clear`, `/compact` garde la continuité nécessaire pour terminer l'étape en cours tout en
  libérant de la place.
- Ne pas proposer `/clear` en cours d'étape non validée : cela ferait perdre le contexte
  nécessaire pour continuer le travail en cours.

## Langue

- Communication avec l'utilisateur : **français**.
- Commit messages : **français**, style impératif court (ex. "Ajoute...", "Corrige...").
- Code (noms de fonctions, variables, classes, hooks) : **anglais**, conformément aux
  conventions WordPress/PHP (`wp_`, `add_action`, etc.) et à l'écosystème des plugins/thèmes.
- Commentaires dans le code : français, uniquement quand le _pourquoi_ n'est pas évident (pas de
  commentaire qui reformule ce que fait déjà le code).

## Architecture cible

- **Cœur WordPress** : jamais modifié, jamais versionné. Fournit CMS, utilisateurs/rôles
  (capabilities), routage, `$wpdb`, hooks, API REST, SEO technique de base.
- **Thème** (`wp-content/themes/amap-theme`) : présentation uniquement (templates, CSS/JS,
  menus). Aucune logique métier, aucune table SQL custom.
- **Plugin métier** (`wp-content/plugins/association-manager`) : toute la logique applicative.
  Évolution progressive : fichier unique → découpage par fonctionnalité → namespaces/autoload →
  Services/Repositories → endpoints REST. Ne pas sauter d'étapes ni introduire ces patterns par
  anticipation.
- **Identité unique** : `wp_users` est la source unique d'identité pour toute personne liée à
  l'AMAP (producteur, adhérent, membre du bureau). Une même personne peut cumuler plusieurs
  « casquettes » sur un seul compte — jamais d'entité séparée dupliquant l'identité.
- **Casquettes cumulables** : chaque casquette (`amap_producer`, `amap_member`, `amap_board`)
  est un rôle WordPress additif (`add_role()`/`remove_role()`, cumulable nativement avec les
  autres rôles). Les capabilities propres à une casquette (ex. `amap_manage_producers`) sont
  portées par ces rôles plutôt que vérifiées via `manage_options`.
- **Données métier** : tables SQL dédiées (`wp_amap_*`) créées via `dbDelta()`, avec suivi de
  version de schéma. Les données propres à une casquette (ex. téléphone/adresse d'un
  producteur) vivent dans une table dédiée liée par `user_id` (clé étrangère vers
  `wp_users.ID`), jamais en usermeta — l'usermeta reste réservé aux flags/préférences simples,
  pas aux données structurées à interroger/joindre.
- L'authentification peut différer selon la casquette (ex. lien magique par email pour les
  adhérents, mot de passe + 2FA pour producteurs/bureau) sans remettre en cause l'identité
  unique `wp_users`.
- Ne jamais stocker de données métier importantes dans les articles/postmeta WordPress.

## Qualité et sécurité du code

- PHP moderne, namespaces, PSR-12.
- Composer uniquement si un vrai besoin de dépendance externe apparaît — ne pas l'introduire par
  défaut. Si utilisé, le dossier `vendor/` doit être committé ou généré et livré avec le
  déploiement (pas de `composer install` supposé possible en production).
- Toujours : validation des entrées, sanitization, escaping en sortie, nonces sur les
  formulaires/actions, vérification des permissions (`current_user_can()`) avant toute action
  sensible.
- Pas d'abstraction ni de gestion d'erreur pour des cas qui ne peuvent pas se produire ; pas de
  fonctionnalité non demandée.
- Pour le HTML généré en PHP (pages d'admin, templates) : ne pas construire le balisage par
  concaténation de chaînes dans des `echo`. Sortir du PHP (`?>`) pour écrire le HTML brut, et n'y
  rentrer que pour la logique (`<?php if ( ... ) : ?>` ... `<?php endif; ?>`, `foreach: ...
  endforeach;`), comme déjà fait dans les templates du thème (`page.php`, `single.php`). Objectif :
  code modulaire et lisible, facile à modifier ensuite.

## Déploiement et hébergement

- Cible : hébergement WordPress mutualisé classique (Apache/Nginx, PHP, MySQL/MariaDB), sans
  accès administrateur serveur garanti et sans Docker disponible.
- Docker : **local uniquement**, jamais une dépendance obligatoire du projet.
- Déploiement possible par ZIP WordPress, Git, ou SFTP — ne rien construire qui suppose un seul
  de ces trois modes.
- Ne pas versionner les plugins/thèmes tiers livrés par défaut avec WordPress (ex. Akismet,
  Hello Dolly, thèmes `twenty*`) : ils sont fournis par toute installation WordPress
  indépendamment de Git. Seul le code custom (thème + plugin) est suivi par Git.

## Environnement local

- `docker compose up -d` démarre WordPress (`localhost:8080`), MariaDB, et Adminer
  (`localhost:8081`) — voir le [README.md](README.md).
- `.env` (non versionné) contient les identifiants de base de données locaux ; `.env.example`
  documente les variables attendues.
- Le cœur WordPress est bind-monté à la racine du dépôt (`./:/var/www/html`) : les dossiers
  `wp-admin/`, `wp-includes/`, etc. apparaissent sur le disque mais restent ignorés par Git
  (`.gitignore`).
- PHP n'est pas installé sur la machine hôte, uniquement dans le conteneur Docker. Ne jamais
  lancer `php` (ex. `php -l` pour vérifier la syntaxe) directement en local : la commande
  échouera puisque le binaire n'existe pas hors conteneur.
- Claude Code ne doit jamais exécuter lui-même de commande `docker`, `docker compose` ou `php`
  (que ce soit sur l'hôte ou via `docker compose exec`), y compris pour s'auto-tester. Se
  contenter de fournir, dans la réponse, les instructions ou la commande à copier-coller pour que
  l'utilisateur la lance lui-même dans son propre terminal.
