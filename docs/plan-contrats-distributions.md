# Plan — modèle de données contrats / groupes / distributions / congés

Récapitulatif du chantier de fond conçu en conversation (2026-08-01), à tenir à jour au fil des
étapes. Le fonctionnement métier détaillé est dans [metier-producteurs.md](metier-producteurs.md).

## Contexte

Le bloc "casquette producteur" de l'espace membre (badge "Producteur" dans `member-area.php`) n'affiche
aujourd'hui qu'un texte statique ("Vous êtes producteur."). Il doit à terme afficher les
contrats du producteur, ses groupes, la liste des produits à livrer pour la prochaine
distribution, et les infos des adhérents disponibles par groupe. Aucune de ces notions (groupe,
contrat, souscription, congé, distribution) n'existait en base avant ce chantier — seul
`wp_amap_users` (phone/address) existait. C'est un chantier de fond, tranché explicitement au
détriment d'une portée plus réduite envisagée initialement.

Précisions métier actées pendant la conception :
- Une distribution a une **plage horaire** propre (heure de début/fin), pas seulement un jour.
- Une distribution doit être tenue par **2 à 3 adhérents bénévoles**, présents 15 min avant le
  début et 15 min après la fin ; chaque adhérent doit assurer **au moins 3 distributions par
  an**. C'est un roster de présence, distinct des souscriptions (qui concernent la réception de
  produits, pas la tenue de la distribution).
- **Un producteur n'a pas de "congés"** — les congés (étape 8) concernent exclusivement
  l'adhérent et sa souscription au panier maraîcher, jamais le producteur lui-même.
- Si le bureau annule une distribution un jour où un adhérent avait déclaré un congé, **le congé
  n'est pas restitué/annulé automatiquement** — comportement à affiner plus tard si besoin.

Le projet avance par étapes courtes validées une à une (voir `CLAUDE.md`) : chaque étape ci-
dessous est implémentée et validée séparément, dans des sessions distinctes.

## Modèle de données retenu

Principe : une table mère `wp_amap_contracts` avec discriminant `contract_type`
(`basket_recurring` | `product_grid`), complétée par des tables filles pour les vraies
collections (tailles de panier vs grille produit×date) — pas de polymorphisme à plat, pas de
duplication de table par type. Les distributions ne sont **pas** stockées ligne par ligne à
l'année : la date normale est dérivée du jour fixe du groupe (`wp_amap_groups.weekday`), seules
les exceptions (annulation/déplacement décidées par le bureau) sont persistées.

Tables (convention reprise de l'existant : `bigint(20) unsigned` pour id/FK conceptuelles, pas
de vraie contrainte `FOREIGN KEY` SQL — cohérent avec `wp_amap_users.user_id` ; discriminants en
`varchar` contrôlés côté PHP, comme `wp_amap_magic_links.purpose`) :

- **`wp_amap_groups`** — groupes de distribution : `id`, `name`, `delivery_place`, `weekday`
  (0-6), `start_time`, `end_time` (plage horaire fixe hebdomadaire), `created_at`.
- **`wp_amap_group_producers`** — rattachement producteur↔groupe (décidé par le bureau) :
  `id`, `group_id`, `producer_user_id`, `created_at`, `UNIQUE(group_id, producer_user_id)`.
- **`wp_amap_contracts`** — table mère : `id`, `producer_user_id`, `contract_type`, `label`,
  `start_date`, `end_date`, `frequency_weeks` (NULL sauf `basket_recurring`), `is_active`,
  `created_at`.
- **`wp_amap_contract_basket_sizes`** — tailles+prix, `basket_recurring` uniquement : `id`,
  `contract_id`, `label`, `price`, `created_at`.
- **`wp_amap_contract_products`** — catalogue produits, `product_grid` uniquement.
- **`wp_amap_contract_delivery_dates`** — dates de livraison du trimestre, `product_grid`
  uniquement : `id`, `contract_id`, `group_id`, `delivery_date`, `created_at`,
  `UNIQUE(contract_id, group_id, delivery_date)`. `group_id` est nécessaire car un producteur
  peut livrer plusieurs groupes de distribution (`wp_amap_group_producers`), chacun avec son
  propre jour fixe (`wp_amap_groups.weekday`) : les dates diffèrent donc selon le groupe de
  l'adhérent, pas seulement selon le contrat.
- **`wp_amap_subscriptions`** — souscription adhérent↔contrat : `id`, `contract_id`,
  `member_user_id`, `group_id` (point de retrait choisi), `basket_size_id` (NULL sauf
  `basket_recurring`), `signed_at`, `created_at`, `UNIQUE(contract_id, member_user_id)`.
- **`wp_amap_subscription_items`** — grille figée à la signature, `product_grid` uniquement :
  `id`, `subscription_id`, `contract_product_id`, `contract_delivery_date_id`, `quantity`.
  Pas de handler `update` : insert-only, cohérent avec "figée pour toute la durée du contrat".
- **`wp_amap_leaves`** — congés maraîcher : `id`, `subscription_id`, `leave_date`,
  `declared_at`, `created_at`, `UNIQUE(subscription_id, leave_date)`. Règles "max 4" et "délai 1
  semaine" : vérifications applicatives, pas de contrainte SQL.
- **`wp_amap_distribution_exceptions`** — annulation/déplacement décidé par le bureau : `id`,
  `group_id`, `distribution_date`, `exception_type` (`cancelled`|`moved`), `new_date`,
  `new_start_time`, `new_end_time` (NULL si la plage horaire ne change pas), `new_place`,
  `reason`, `decided_by` (user_id bureau), `notified_at`, `created_at`,
  `UNIQUE(group_id, distribution_date)`.
- **`wp_amap_distribution_volunteers`** — roster des adhérents bénévoles tenant une
  distribution : `id`, `group_id`, `distribution_date`, `member_user_id`, `created_at`,
  `UNIQUE(group_id, distribution_date, member_user_id)`. Règles "2 à 3 par distribution" et
  "au moins 3 par an et par adhérent" : vérifications applicatives (COUNT), pas de contrainte
  SQL.

Point ouvert non tranché (hérité de `metier-producteurs.md`) : que se passe-t-il si un adhérent
n'a pas posé ses 4 congés avant la fin du contrat maraîcher ?

Point ouvert non tranché (soulevé en conversation le 2026-08-06, à l'étape 4b) : certains
produits du catalogue (`wp_amap_contract_products`) peuvent bénéficier d'une remise par
quantité (ex. 6 unités achetées, 5 facturées), mais pas systématiquement selon le produit. La
table `wp_amap_contract_products` créée en 4b ne modélise volontairement que le prix unitaire
(label + price), sans règle de remise : à traiter plus tard, probablement à l'étape 5/6
(souscriptions), quand les quantités réellement commandées par un adhérent seront connues.

Aucune de ces étapes n'introduit de fichiers séparés, namespaces, classes ou couche
Repository/Service : tout reste dans `association-manager.php`, fonctions procédurales
préfixées `amap_`, réutilisant le pattern CRUD déjà en place pour "Utilisateurs AMAP".

## Découpage en étapes

```
1. wp_amap_groups (CRUD groupes)                              ✅ fait (commit aa9b7a4)
2. wp_amap_group_producers (rattachement producteur↔groupe)   ✅ fait (commit 060f19e)
3. wp_amap_contracts (table mère seule)                       ✅ fait (commit 147e09d)
4. wp_amap_contract_basket_sizes (4a) / contract_products (4b) /
   contract_delivery_dates (4c) — tables filles selon         ✅ fait (commits df12d2e,
   contract_type                                                 b680604, 4412500)
5. wp_amap_subscriptions (dépend de 2 + 3/4a)                 ✅ fait (commit 081a008)
6. wp_amap_subscription_items (dépend de 4b/4c + 5)           ✅ fait
7. Espace adhérent : souscription en ligne (dépend de 5 + 6) — bascule de la création d'une
   souscription (signature + grille produits×dates) de l'admin vers le front, voir note        🔧 en cours
   ci-dessous                                                                                     (7.1 fait)
8. wp_amap_leaves (dépend de 5) — CRUD admin d'abord, self-service adhérent plus tard
9. wp_amap_distribution_exceptions (dépend de 1 seulement)
10. wp_amap_distribution_volunteers (dépend de 1 + 5, pour connaître les adhérents éligibles
    par groupe) — roster bénévoles, règles 2-3/distribution et 3/an/adhérent en PHP
11. Notification adhérents lors d'exception (dépend de 9 + 5, réutilise amap_send_email())
```

Point ouvert acté en conversation le 2026-08-08, à traiter à l'étape 7 : la page admin
"Souscriptions" (étapes 5+6) fait aujourd'hui saisir par le bureau, dans un simple menu déroulant,
*qui* souscrit et *quand*, y compris la grille produits×dates — ce qui revient à faire signer et
commander l'adhérent par procuration. La création d'une souscription a plus de sens comme action
de l'adhérent lui-même, depuis l'espace membre (authentifié par lien magique, déjà en place — voir
`includes/auth.php`), que comme saisie a posteriori par le bureau. Décision : l'admin (Ajouter/
Modifier/Supprimer une souscription et sa grille, étapes 5/6) reste en l'état, entièrement
éditable, comme outil de secours pour le bureau ("admin est root") ; l'étape 7 ajoute le vrai
parcours de souscription côté front, à concevoir en détail au moment de l'attaquer (formulaire,
UX adaptée à des adhérents non technophiles, notifications).

Le bloc producteur de l'espace membre ne pourra afficher des données réelles qu'à partir de la
fin de l'étape 4 (contrats+groupes), et complètement qu'après les étapes 1-6, 9 et 10. Cette
restitution finale sera elle-même scindée en plusieurs sous-étapes (contrats+groupes, prochaine
distribution, produits à livrer, adhérents par groupe) — pas livrée d'un bloc.

## Étape 1 (fait) — `wp_amap_groups`

Table `wp_amap_groups` (nom, lieu de livraison, jour, heure de début/fin), capability
`amap_manage_groups` (administrator + amap_board), sous-page admin "Groupes" (menu AMAP) avec
CRUD complet, sur le même squelette que la page "Utilisateurs AMAP". Commit `aa9b7a4`.

## Étape 2 (fait) — `wp_amap_group_producers`

Table `wp_amap_group_producers` (`group_id`, `producer_user_id`, `UNIQUE(group_id,
producer_user_id)`). Interface tranchée avec l'utilisateur : pas de nouvelle sous-page, gestion
intégrée à la page "Groupes" existante — en mode édition d'un groupe (`?action=edit&id=X`), une
section "Producteurs rattachés" liste tous les comptes `amap_producer` avec une case à cocher,
sauvegardée par synchronisation delete+insert (`amap_handle_update_group_producers()`).
Suppression d'un groupe : nettoyage explicite des rattachements orphelins (pas de contrainte
FOREIGN KEY SQL dans ce plugin). Commit `060f19e`.

## Étape 3 (fait) — `wp_amap_contracts`

Table mère des contrats (voir modèle de données ci-dessus). Nouvelle sous-page admin "Contrats"
(menu AMAP), capability `amap_manage_contracts` (administrator + amap_board), CRUD complet sur
le même squelette que "Groupes"/"Utilisateurs AMAP". `frequency_weeks` est obligatoire et
revalidé côté serveur uniquement pour `basket_recurring` (forcé à `NULL` pour `product_grid`),
le champ correspondant étant simplement masqué en JS selon le type sélectionné dans le
formulaire. `is_active` : case à cocher, décochée manuellement par le bureau pour un contrat
qu'on ne veut plus proposer à la souscription (pas de dérivation automatique depuis les dates).
Aucune table fille à ce stade (basket_sizes/products/delivery_dates prévues en 4a/4b/4c) : la
suppression d'un contrat reste donc simple, sans nettoyage de rattachements orphelins. Commit
`147e09d`.

## Étape 4 (fait) — tables filles des contrats

Trois tables filles de `wp_amap_contracts`, chacune réservée à un seul `contract_type` (voir
modèle de données ci-dessus). Interface tranchée avec l'utilisateur, sur le même principe que
"Producteurs rattachés" (étape 2) : pas de nouvelle sous-page, mini-CRUD nichée dans la page
"Contrats" existante, en mode édition d'un contrat du type concerné — mais ici un vrai CRUD
(ajout/modification/suppression) plutôt qu'une case à cocher, les tailles/produits/dates
n'existant nulle part ailleurs.

- **4a — `wp_amap_contract_basket_sizes`** (`basket_recurring` uniquement) : tailles+prix du
  panier maraîcher, section "Tailles de panier". Commit `df12d2e`.
- **4b — `wp_amap_contract_products`** (`product_grid` uniquement) : catalogue produits
  (label+prix), section "Produits". Commit `b680604`. Point ouvert soulevé à cette étape (voir
  plus haut) : remise par quantité non modélisée ici. Doc du point ouvert : commit `ba83e1c`.
- **4c — `wp_amap_contract_delivery_dates`** (`product_grid` uniquement) : dates de livraison
  du trimestre, section "Dates de livraison" (sous "Produits"). Validations serveur
  supplémentaires : date comprise entre `start_date` et `end_date` du contrat, unicité
  revérifiée côté PHP (`amap_contract_has_delivery_date()`) pour un message d'erreur clair avant
  la contrainte SQL. Commit `4412500`.

Suppression d'un contrat : nettoyage explicite des trois tables filles (une seule contient
effectivement des lignes selon `contract_type`, les deux autres suppressions ne font rien),
comme pour les rattachements producteurs orphelins à la suppression d'un groupe (étape 2).

### Complément à 4c — `group_id` et génération en masse (commit `b994b45`)

Point métier remonté après coup (2026-08-08) : un producteur peut livrer plusieurs groupes de
distribution, chacun avec son propre jour fixe — les dates de livraison d'un même contrat
`product_grid` diffèrent donc selon le groupe de l'adhérent (voir modèle de données ci-dessus,
`group_id` ajouté à `wp_amap_contract_delivery_dates`). Ajouts :

- `amap_get_producer_groups( $producer_user_id )` — sens inverse de
  `amap_get_group_producer_ids()`, limite les menus déroulants "Groupe" aux groupes réellement
  rattachés au producteur du contrat.
- Section "Générer des dates" : un bouton par groupe du producteur calcule toutes les
  occurrences de son jour de semaine sur la période du contrat (moins celles déjà enregistrées),
  affichées en cases à cocher (toutes cochées par défaut, décochage des exceptions). Un outil JS
  facultatif "cocher une date sur N" aide à précocher un rythme bimensuel/irrégulier, sans aucun
  paramètre de fréquence envoyé ou validé côté serveur — le serveur ne voit que la liste finale
  de dates cochées et revalide chacune (format, période, jour de semaine, doublon).
  `amap_handle_delete_group()` nettoie désormais aussi les dates de livraison orphelines d'un
  groupe supprimé.
- Migration destructive nécessaire pour ce changement de schéma (`DROP TABLE` puis
  réactivation du plugin) : `dbDelta()` ne modifie pas fiablement un index existant qui change
  de composition de colonnes. Sans impact réel : uniquement des données de test à ce stade.

## Étape 5 (fait) — `wp_amap_subscriptions`

Table de souscription adhérent↔contrat (voir modèle de données ci-dessus). Nouvelle capability
`amap_manage_subscriptions` (distincte de `amap_manage_contracts`, même logique que la
séparation `amap_manage_groups`/`amap_manage_contracts`) et nouvelle sous-page admin
"Souscriptions" (menu AMAP), CRUD complet sur le même squelette que "Groupes" — pas nichée en
onglet dans la page Contrats, une souscription étant une relation adhérent↔contrat listée à
plat plutôt qu'un élément appartenant à un seul contrat comme les tables filles de l'étape 4.

- Le menu déroulant "Contrat" du formulaire ne propose que les contrats `is_active = 1`
  ("ouverts à la souscription", au sens déjà défini par la case à cocher de la page Contrats) ;
  en édition, le contrat déjà choisi reste affiché même s'il a été désactivé depuis, pour ne pas
  casser une souscription existante.
- Le menu déroulant "Adhérent" est limité aux comptes `amap_member` (nouvel helper
  `amap_get_member_users()`, même principe que `amap_get_producer_users()`).
- Les menus déroulants "Groupe" et "Taille de panier" se repeuplent en JS selon le contrat
  choisi : "Groupe" est limité aux groupes réellement rattachés au producteur du contrat
  (réutilise `amap_get_producer_groups()`, déjà en place depuis le complément à 4c) ; "Taille de
  panier" n'apparaît que pour un contrat `basket_recurring` et liste les tailles propres à ce
  contrat. Les données nécessaires (groupes/tailles par contrat) sont précalculées côté PHP et
  injectées en JSON dans la page — pas d'appel Ajax, le volume de contrats actifs restant faible.
- Validations serveur : groupe revérifié comme rattaché au producteur du contrat, taille de
  panier revérifiée comme appartenant au contrat si `basket_recurring` (forcée à `NULL` sinon,
  même principe que `frequency_weeks` sur `wp_amap_contracts`), unicité `(contract_id,
  member_user_id)` revérifiée côté PHP (`amap_member_has_subscription()`, même principe que
  `amap_contract_has_delivery_date()`).
- `signed_at` est saisi manuellement par le bureau (pré-rempli à aujourd'hui, modifiable) : la
  signature papier peut avoir eu lieu avant la saisie informatique, contrairement à `created_at`
  qui reste l'horodatage technique automatique.
- Suppression d'une souscription : simple delete, aucune table fille à nettoyer pour l'instant
  (`wp_amap_subscription_items` et `wp_amap_leaves` n'existent pas encore, voir étapes 6/8).

Commit `081a008`.

## Étape 6 (fait) — `wp_amap_subscription_items`

Grille produit×date (voir modèle de données ci-dessus), `product_grid` uniquement. Pas de
nouvelle page ni de CRUD classique : la grille se remplit dans le même formulaire que "Ajouter"/
"Modifier une souscription" (page "Souscriptions"), plutôt que d'être une collection éditable
ligne à ligne comme les tables filles de l'étape 4.

- **Vue/édition en modification** : ouvrir une souscription existante affiche d'abord une vue en
  lecture seule (infos + grille produits×dates au même visuel que la saisie, cases sans commande
  affichées en `—`), avec un bouton "Modifier" qui bascule vers le formulaire éditable — même
  pattern que `amap-contract-view`/`amap-contract-edit-form` sur la page "Contrats". La création
  ("Ajouter une souscription") va directement au formulaire, rien à afficher en lecture seule
  avant qu'elle existe.
- **Formulaire d'ajout et de modification** : une fois Contrat (`product_grid`) et Groupe choisis,
  une section "Produits commandés" apparaît sous le formulaire, dans le même `<form>`. Grille avec
  **lignes = dates de livraison du groupe choisi**, **colonnes = produits du contrat** —
  orientation retenue pour limiter le nombre de colonnes (généralement moins de produits que de
  dates sur un trimestre), donc moins de scroll horizontal. Un bouton "Dupliquer la 1ère date sur
  toutes les autres" recopie toute la première ligne (cas fréquent : même commande chaque
  semaine). Grille creuse : une case vide/à 0 ne crée aucune ligne en base. Données précalculées
  en JSON par contrat (même principe que l'étape 5), étendues avec `products` et
  `delivery_dates_by_group` ; la grille elle-même est entièrement construite en JS (pas de rendu
  PHP initial), y compris au premier chargement de la page. En modification, préremplie depuis les
  `subscription_items` existants.
- **Pas de verrouillage, grille resynchronisée à chaque modification** : premier essai qui
  verrouillait Contrat/Groupe et gardait la grille insert-only, tranché en sens inverse par
  l'utilisateur — l'admin reste éditable sur tous les champs même après signature ("admin est
  root", le bureau garde la main pour corriger une erreur de saisie). `amap_handle_update_subscription()`
  resynchronise entièrement la grille à chaque enregistrement (delete puis réinsertion des cases
  > 0 via `amap_insert_subscription_items()`, réutilisée telle quelle depuis l'ajout) plutôt qu'un
  update ligne à ligne. Changer le contrat/groupe d'une souscription qui a déjà des
  `subscription_items` rebâtit donc la grille sur le nouveau contrat, au prix de perdre les
  quantités saisies pour l'ancien — assumé, à la charge du bureau. Si le contrat n'est plus
  `product_grid`, les `subscription_items` existants sont supprimés ; si la grille n'a pas été
  soumise du tout (JS non chargé), ils sont laissés intacts plutôt qu'effacés par erreur.
- **Validation serveur** (`amap_insert_subscription_items()`) : chaque couple
  (`contract_product_id`, `contract_delivery_date_id`) posté est revérifié comme appartenant
  réellement au contrat et au groupe choisis (jamais les IDs postés en confiance), quantité
  revalidée en entier positif.
- **Schéma** : `created_at` et `UNIQUE(subscription_id, contract_product_id,
  contract_delivery_date_id)` ajoutés en plus des colonnes du modèle de données, par cohérence
  avec les autres tables du plugin (confirmé avec l'utilisateur avant implémentation).
- Suppression d'une souscription : nettoyage explicite des `subscription_items` orphelins
  ajouté à `amap_handle_delete_subscription()`, même principe que les tables filles à la
  suppression d'un contrat (étape 4).

## Étape 7 (en cours) — Espace adhérent : souscription en ligne

Découpage acté en conversation (2026-08-08) : la page admin "Souscriptions" (étapes 5+6) reste
en l'état, éditable, comme outil de secours pour le bureau ("admin est root") ; l'étape 7 ajoute
le vrai parcours front, en plusieurs sous-étapes validées séparément :

```
7.1 Mes contrats (lecture seule)                    ✅ fait (commit 7ab1911)
7.2 Liste des contrats proposables à la souscription
7.3 Formulaire de souscription (front)
7.4 Email de confirmation de souscription
```

Menu à terme dans l'espace membre (`member-area.php`) : Espace adhérent / Espace producteur /
Espace bureau / Mes infos / Déconnexion — sections affichées selon les rôles cumulés de
l'utilisateur, cohérent avec les "casquettes cumulables" du projet. "Espace bureau" n'est pas
une section de contenu : lien direct vers wp-admin (`amap_manage_users`), le bureau restant
géré en admin, jamais côté front (voir `CLAUDE.md`).

### Étape 7.1 (fait) — "Mes contrats" (lecture seule)

- **Navigation à onglets**, construite en même temps que 7.1 plutôt qu'ajoutée plus tard : un
  paramètre `amap_tab` (`member`/`producer`/`profile`) sur la page `espace-adherent`, même
  principe que `amap_login_step`/`amap_member_action` déjà utilisés dans `auth.php`. La valeur
  reçue en requête n'est jamais utilisée telle quelle pour choisir un template : revalidée par
  `amap_maybe_render_member_area()` contre la liste des onglets réellement accessibles à
  l'utilisateur (calculée depuis ses rôles), onglet par défaut = premier onglet accessible dans
  l'ordre adhérent > producteur > profil. `member-area.php` (thème) devient une coquille
  (en-tête + badges + nav) qui charge le fragment du bon onglet :
  `member-area-nav.php`, `member-area-member.php` ("Mes contrats"), `member-area-producer.php`
  (reprend tel quel le placeholder existant), `member-area-profile.php` (reprend tel quel la
  carte "Mes informations" déplacée depuis l'ancien `member-area.php` à plat).
- **Contenu adhérent** : `amap_get_member_subscriptions( $member_user_id )` (nouvelle fonction,
  `subscriptions.php`) liste les souscriptions de l'adhérent connecté, enrichies en PHP (même
  principe de jointure que `amap_get_producer_groups()`) avec contrat, producteur, groupe,
  taille de panier le cas échéant, et un statut dérivé des dates du contrat
  (`amap_get_contract_period_status()`, nouveau, `contracts.php` : à venir/en cours/terminé,
  distinct de `is_active` qui ne dit qu'"ouvert à la souscription"). Un contrat supprimé
  entre-temps (pas de contrainte FOREIGN KEY SQL dans ce plugin) est simplement ignoré plutôt que
  de faire planter l'affichage.
- Pas encore de bouton "Souscrire" ni de détail ligne à ligne de la grille produits×dates à ce
  stade : scope volontairement limité à la lecture, le reste vient en 7.2/7.3.
- CSS : nouvelles classes `.amap-nav`/`.amap-nav-item` (scroll horizontal en mobile) et
  `.amap-subscription-list`/`.amap-status-badge`, sur les mêmes variables (`--space-*`,
  `--color-*`, `--radius`) que `.amap-badge`/`.amap-card` déjà en place.

Commit `7ab1911`.
