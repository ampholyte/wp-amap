# Plan — modèle de données contrats / groupes / distributions / congés

Récapitulatif du chantier de fond conçu en conversation (2026-08-01), à tenir à jour au fil des
étapes. Le fonctionnement métier détaillé est dans [metier-producteurs.md](metier-producteurs.md).

## Contexte

Le bloc "casquette producteur" de l'espace membre (`member-area.php:48-50`) n'affiche
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
- **Un producteur n'a pas de "congés"** — les congés (étape 7) concernent exclusivement
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
- **`wp_amap_contract_products`** + **`wp_amap_contract_delivery_dates`** — catalogue produits
  et dates de livraison du trimestre, `product_grid` uniquement.
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

Aucune de ces étapes n'introduit de fichiers séparés, namespaces, classes ou couche
Repository/Service : tout reste dans `association-manager.php`, fonctions procédurales
préfixées `amap_`, réutilisant le pattern CRUD déjà en place pour "Utilisateurs AMAP".

## Découpage en étapes

```
1. wp_amap_groups (CRUD groupes)                              ✅ fait (commit aa9b7a4)
2. wp_amap_group_producers (rattachement producteur↔groupe)   ✅ fait (commit 060f19e)
3. wp_amap_contracts (table mère seule)                       ✅ fait (commit 147e09d)
   4a. wp_amap_contract_basket_sizes (si basket_recurring)
   4b. wp_amap_contract_products  4c. wp_amap_contract_delivery_dates (si product_grid)
5. wp_amap_subscriptions (dépend de 2 + 3/4a)
6. wp_amap_subscription_items (dépend de 4b/4c + 5)
7. wp_amap_leaves (dépend de 5) — CRUD admin d'abord, self-service adhérent plus tard
8. wp_amap_distribution_exceptions (dépend de 1 seulement)
9. wp_amap_distribution_volunteers (dépend de 1 + 5, pour connaître les adhérents éligibles
   par groupe) — roster bénévoles, règles 2-3/distribution et 3/an/adhérent en PHP
10. Notification adhérents lors d'exception (dépend de 8 + 5, réutilise amap_send_email())
```

Le bloc producteur de l'espace membre ne pourra afficher des données réelles qu'à partir de la
fin de l'étape 4 (contrats+groupes), et complètement qu'après les étapes 1-6, 8 et 9. Cette
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
