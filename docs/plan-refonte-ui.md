# Plan — rattrapage UX/UI avant l'étape 5 (souscriptions)

Récapitulatif du chantier de rattrapage visuel décidé en conversation (2026-08-08), à tenir à
jour au fil des sous-étapes, sur le même principe que
[plan-contrats-distributions.md](plan-contrats-distributions.md).

## Contexte

Les étapes 1 à 4 du chantier contrats n'ont produit que des écrans d'admin (Groupes, Contrats),
qui héritent gratuitement du style natif de wp-admin. En revanche, tout le parcours **front
adhérent** (connexion, espace membre, modification de profil) construit avant ce chantier, ainsi
que la **vitrine publique**, sont restés en HTML quasi brut : `style.css` du thème ne fait que 29
lignes (reset + `body`/`header`/`footer`/`main`), sans variable CSS, sans palette, sans style de
formulaire/bouton/notice. Avant d'ouvrir l'étape 5 (`wp_amap_subscriptions`, qui va complexifier
encore le front adhérent), ce rattrapage comble cette dette.

Décisions actées :
- Direction visuelle : palette simple et sobre, esprit AMAP (verts/tons naturels), pas de charte
  graphique existante à respecter.
- Découpage en sous-étapes courtes, validées séparément (comme le reste du projet).
- La page admin "Contrats" (dense, 4 formulaires empilés) est incluse dans le rattrapage.

## Découpage en sous-étapes

```
A. Fondations du design system (variables + composants de base)   ✅ fait
B. Parcours de connexion + espace membre + profil adhérent        ✅ fait
C. Vitrine publique (accueil, articles, pages, menu, footer)       ✅ fait
D. Page admin "Contrats" : séparation visuelle des sous-sections   ✅ fait
```

Suite au retour utilisateur sur le rendu de D, trois points supplémentaires ont été identifiés et
sont traités en sous-étapes courtes séparées (même principe que A-D) :

```
E1. Formulaires admin en tableau natif (.form-table)               ✅ fait
E2. Masquer les formulaires de création derrière un bouton         ✅ fait
E3. Onglets sur la page admin "Contrats"                           ✅ fait
E4. Vue/Édition séparées + masquage du tableau en mode Voir        ✅ fait
E5. Accordéon + suppression en masse des dates de livraison        ✅ fait
E6. Création de dates intégrée à chaque accordéon                  ✅ fait
```

Le rattrapage UX/UI est maintenant terminé sur l'ensemble des sous-étapes (A-D, E1-E6).

## Sous-étape E1 (fait) — Formulaires admin en `.form-table`

Constat : les formulaires des 3 pages admin (`amap_render_users_page()`,
`amap_render_groups_page()`, `amap_render_contracts_page()`) utilisaient des `<p><label>Texte
<input></label></p>` empilés au lieu du tableau natif WordPress à deux colonnes (`.form-table`,
utilisé nativement par les écrans de réglages WordPress), d'où un rendu perçu comme "brut".

Fichiers modifiés : les 3 fonctions ci-dessus. Formulaires convertis en `.form-table`
(`<label for="id">`/`<input id="id">` associés) : formulaire principal Utilisateurs AMAP,
formulaire principal Groupes, et les 4 formulaires de la page Contrats (contrat, taille de
panier, produit, date de livraison). Volontairement **non convertis** : les sections qui sont de
simples listes de cases à cocher de longueur variable (rôles dans Utilisateurs — celui-là est
resté en une seule ligne de `.form-table` avec les cases à cocher en `<td>` —, "Producteurs
rattachés" sur Groupes, "Générer des dates" sur Contrats), `.form-table` étant pensé pour des
paires label/champ fixes, pas pour des checklists répétées.

## Sous-étape E2 (fait) — Masquer les formulaires de création derrière un bouton

Fichiers modifiés : les 3 mêmes fonctions. Sur chacun des 6 formulaires "création" (Utilisateurs,
Groupes, Contrat, Taille de panier, Produit, Date de livraison), quand on n'est **pas** en train
de modifier un élément existant, le formulaire est masqué (`hidden`) derrière un bouton
`+ Ajouter …` ; un clic le révèle et masque le bouton, un bouton "Annuler" à l'intérieur du
formulaire fait l'inverse. En mode modification (`?action=edit&id=X` ou équivalent), le
formulaire reste affiché directement comme avant (pas de bouton à cliquer). Choix tranché avec
l'utilisateur : affichage/masquage simple (`hidden` + `addEventListener`, quelques lignes de JS
par formulaire), pas de fenêtre modale — cohérent avec le principe "pas de complexité non
nécessaire" déjà suivi sur ce projet. Chaque formulaire garde son propre petit script
autonome (comme le reste du fichier), pas de fonction JS partagée globale.

## Sous-étape E3 (fait) — Onglets sur la page admin "Contrats"

Fichier modifié : `amap_render_contracts_page()`.

En mode édition d'un contrat (`$editing_id && $editing_contract`), une barre d'onglets native
WordPress (`nav-tab-wrapper`/`nav-tab`/`nav-tab-active`) apparaît au-dessus des sections : "Infos
du contrat" (toujours), puis "Tailles de panier" (`basket_recurring`) ou "Produits" + "Dates de
livraison" (`product_grid`). Un seul panneau (`.amap-tab-panel`) est visible à la fois (`hidden`
sur les autres), "Infos du contrat" étant l'onglet actif par défaut. Bascule gérée par un script
minimal (`event.preventDefault()` sur le lien d'onglet, masque tous les panneaux, affiche celui
ciblé par `data-amap-tab`). En mode ajout (pas encore de contrat créé), pas d'onglets : rien à
séparer puisqu'aucune sous-section (tailles/produits/dates) n'existe encore pour un contrat qui
n'a pas d'ID.

Les 3 sous-sections gardent leur habillage `.postbox` posé en sous-étape D (juste complété d'un
`id` et d'un `hidden` par défaut) : les onglets répondent au problème de lecture "empilement de
plusieurs formulaires/tableaux visibles en même temps", tandis que le `.postbox` continue
d'apporter la séparation visuelle (bordure, fond) à l'intérieur de chaque onglet.

## Sous-étape E4 (fait) — Vue/Édition séparées + masquage du tableau en mode Voir

Fichier modifié : `amap_render_contracts_page()`.

Retour utilisateur après E3 : cliquer "Modifier" plongeait directement dans un formulaire dense
(tous les champs + onglets), et le tableau complet de tous les contrats restait visible sous les
onglets pendant l'édition d'un contrat précis — source de confusion sur ce qu'on est en train de
regarder. Trois changements :

- Le tableau final listant tous les contrats est maintenant masqué dès qu'un contrat est chargé
  (`$editing_id` vrai), qu'il soit en cours de consultation ou de modification. Il reste visible
  en mode liste par défaut et pendant l'ajout d'un nouveau contrat (rien à côté de quoi se perdre
  dans ce cas, décision actée avec l'utilisateur).
- Le lien "Modifier" de ce tableau devient "Voir" (même URL cible, juste le libellé).
- Le premier onglet "Infos du contrat" est scindé en deux blocs : `#amap-contract-view` (résumé
  en lecture seule — libellé, producteur, type, période, fréquence si `basket_recurring`, statut —
  affiché par défaut) et `#amap-contract-edit-form` (le formulaire éditable existant, cf.
  form-table de E1, masqué par défaut). Un bouton "Modifier les infos" bascule vers le formulaire ;
  le bouton "Annuler" à l'intérieur du formulaire revient au résumé du même contrat plutôt que de
  quitter la page (choix acté avec l'utilisateur, pour rester dans le contexte du contrat en
  cours). En mode ajout d'un nouveau contrat, comportement inchangé : pas de résumé (rien à
  résumer), le formulaire s'affiche directement quand on clique "+ Ajouter un contrat" (E2).

Les onglets "Tailles de panier"/"Produits"/"Dates de livraison" et leurs formulaires masqués
(E2/E3) ne sont pas concernés par ce changement.

### Correctifs suite à un nouveau retour utilisateur sur E4

- **Redirection après enregistrement d'un contrat** : `amap_handle_update_contract()`
  redirigeait vers la liste complète (`admin.php?page=amap-contracts`) après un enregistrement
  réussi au lieu de revenir sur la page du contrat modifié (`$edit_url`, déjà utilisée pour tous
  les cas d'erreur de ce handler) — corrigé.
- **Titres `hndle` redondants** : les `<h2 class="hndle">` internes aux postbox "Tailles de
  panier", "Produits" et "Dates de livraison" faisaient doublon avec le libellé déjà affiché dans
  l'onglet correspondant — supprimés (le `.postbox`/`.inside` reste pour la séparation visuelle,
  seul le titre est retiré).
- **Onglet "Liste des contrats"** : ajouté en premier dans la barre d'onglets, pour revenir à la
  liste sans repasser par le menu AMAP. Contrairement aux autres onglets, c'est un vrai lien de
  navigation (`href` vers `admin.php?page=amap-contracts`, pas d'attribut `data-amap-tab`) : le
  script de bascule ne cible plus que `.nav-tab[data-amap-tab]`, ce qui laisse ce lien fonctionner
  normalement (pas de `event.preventDefault()` dessus).

**Correctif suite à un nouveau retour** : les liens "Modifier" d'une taille de panier/d'un
produit/d'une date de livraison (`?size_action=edit`, `?product_action=edit`, `?date_action=edit`)
rechargent la page entière — jusqu'ici l'onglet actif au chargement était toujours codé en dur sur
"Infos du contrat", quel que soit l'élément réellement en cours de modification, ramenant
l'utilisateur sur le mauvais onglet. Calcul d'un `$active_contract_tab` (déduit de
`$size_editing_id`/`$product_editing_id`/`$delivery_date_editing_id`/`$generate_group_id`) qui
détermine désormais la classe `nav-tab-active` et l'attribut `hidden` des 4 panneaux dès le rendu
PHP, avant toute interaction JS.

**Correctif complémentaire** : ce calcul ne couvrait que le cas "lien Modifier d'un élément
précis" — les boutons "Enregistrer" et "Annuler" de ces mêmes sous-formulaires ramenaient
pourtant aussi sur l'onglet "Infos du contrat", car leurs redirections/liens ne portaient aucune
information sur la sous-section d'origine une fois l'édition terminée (`$size_editing_id` etc.
retombent à 0 après un enregistrement ou une annulation). Ajout d'un paramètre `?active_tab=
sizes|products|dates` explicite, posé par les 3 liens "Annuler" du rendu et injecté dans
`$edit_url` de chacun des 9 handlers add/update/delete des trois sous-sections (donc propagé à
toutes leurs redirections, succès comme erreurs de validation, sans dupliquer le paramètre sur
chaque `wp_safe_redirect()`). `$active_contract_tab` lit désormais ce paramètre en plus des
`*_editing_id`.

**Essai visuel demandé par l'utilisateur** (fait, validé) : bordures des tableaux `.widefat`
(liste des contrats, tailles de panier, produits, dates de livraison, résumé "Voir") retirées via
un petit `<style>` scopé à cette page (pas de fichier CSS pour le plugin à ce stade, cohérent
avec l'absence d'assets déjà constatée — même logique que les `<script>` inline déjà utilisés
partout ailleurs dans ce fichier). Ajustement final retenu : pas de bordure de tableau ni d'ombre,
mais une fine séparation `border-bottom: 1px solid #e0e0e0` entre les lignes.

## Sous-étape E5 (fait) — Accordéon par groupe + suppression en masse des dates de livraison

Fichier modifié : `amap_render_contracts_page()` + nouveau handler `admin-post.php`.

Le tableau plat "Date / Groupe / Actions" (mélangeant toutes les dates de tous les groupes du
contrat) est remplacé par un accordéon natif HTML (`<details>`/`<summary>`, aucune librairie JS)
avec une section par groupe de distribution ayant des dates enregistrées (`$dates_by_group`,
construit en PHP à partir de `amap_get_contract_delivery_dates()`, déjà triée par `group_id`).
Chaque section affiche :
- Le tableau habituel de ce groupe (Date / Actions Modifier·Supprimer individuelles, inchangé) ;
- Un bouton "Modifier la liste" qui bascule (JS par délégation, un seul script pour tous les
  groupes via `data-group-id`) vers un mode "suppression en masse" : une case à cocher par date
  existante, **cochée par défaut = conservée**, décocher une date la marque pour suppression ;
  bouton "Enregistrer" (soumet `keep_ids[]`) ou "Annuler" (retour à la vue tableau, sans rien
  soumettre).

Nouveau handler `amap_handle_bulk_delete_contract_delivery_dates()` : supprime, pour un couple
(contrat, groupe) donné, toutes les dates dont l'ID n'apparaît pas dans `keep_ids[]` — défense en
profondeur identique à `amap_handle_generate_contract_delivery_dates()` (les ID reçus ne sont
utilisés que pour filtrer des lignes réellement rattachées à ce contrat et à ce groupe, jamais
appliqués tels quels). Redirection avec `&active_tab=dates` (cf. E4) pour rester sur le bon onglet,
et nouveau notice `contract_delivery_dates_bulk_deleted` (compteur de dates supprimées).

CSS ajouté au `<style>` de la page : bordure/padding léger sur `details.amap-dates-group` et
curseur/graisse sur son `summary`, pour un rendu plus soigné que le style natif du navigateur.

Non traité volontairement : pas d'auto-ouverture du groupe concerné après une action (ajout/
modification/génération/suppression) — tous les groupes restent repliés par défaut à chaque
chargement de page. Amélioration possible plus tard si besoin, pas demandée pour l'instant.

## Sous-étape E6 (fait) — Création de dates intégrée à chaque accordéon

Retour utilisateur après E5 : la création de dates (génération en masse + ajout manuel) restait
dans des sections globales séparées, en dessous des accordéons, ce qui rendait peu clair ce qui
se passait pour la création. Les deux outils sont désormais **intégrés dans chaque accordéon de
groupe**, à la place des anciennes sections globales "Générer des dates" (sélecteur de groupe par
lien) et "+ Ajouter une date de livraison" (formulaire avec liste déroulante Groupe) :

- Chaque groupe du producteur a désormais son propre accordéon (et plus seulement les groupes
  ayant déjà des dates) : "Aucune date enregistrée pour ce groupe." s'affiche pour un groupe vide,
  avec ses propres outils de création juste en dessous.
- Dans chaque accordéon : le tableau des dates existantes + "Modifier la liste" (inchangé, cf.
  E5), un bouton "Générer des dates pour ce groupe" (repli/dépli en JS, plus besoin de naviguer
  vers `?generate_group_id=X` pour "sélectionner" un groupe — chaque groupe a déjà ses dates
  candidates précalculées), et un bouton "+ Ajouter une date pour ce groupe" (formulaire simplifié
  : plus de liste déroulante Groupe, le groupe est fixé par un champ caché puisqu'on est déjà dans
  son accordéon).
- Édition d'une date existante (`?date_action=edit&date_id=Y`) : reste un formulaire autonome
  affiché sous les accordéons (inchangé structurellement, liste déroulante Groupe conservée — on
  peut toujours réaffecter une date à un autre groupe en modification, seule la création est
  désormais scopée par accordéon).
- Auto-ouverture ciblée de l'accordéon concerné : après une génération réussie
  (`generate_group_id` posé par le handler) ou après l'échec de validation d'un ajout
  (`$contract_delivery_date_form_data['group_id']` rechargé depuis le transient), pour ne pas
  perdre le fil après une redirection.
- JS mutualisé : les nombreux boutons afficher/masquer (bulk-edit, générer, ajouter — jusqu'à 3
  par groupe) utilisent désormais un seul script générique par attributs `data-amap-show`/
  `data-amap-hide` plutôt qu'un script dédié par bouton, pour éviter la duplication qui serait
  devenue excessive avec un nombre de groupes variable. Le calcul "cocher une date sur…" est scopé
  par groupe via `data-group-id` (les ID `amap-generate-frequency`/`amap-generate-apply-frequency`
  n'auraient pas pu être dupliqués tels quels sur plusieurs groupes sans collision d'ID HTML).
- Handler `amap_handle_generate_contract_delivery_dates()` : redirection complétée avec
  `&active_tab=dates` explicite (auparavant elle ne comptait implicitement que sur
  `generate_group_id` pour garder cet onglet actif).

## Sous-étape A (fait) — Fondations du design system

Fichier modifié : `wp-content/themes/amap-theme/style.css` uniquement, pas de changement de
structure HTML dans les templates.

Contenu ajouté : palette de couleurs (vert principal + variante foncée, accent terracotta,
gris neutres, couleurs sémantiques succès/erreur/avertissement/info), typographie (pile de
polices système, échelle de titres, `line-height`), échelle d'espacements, styles de base pour
`body`/titres/liens, `form`/`label`/`input`/`textarea`/`select`/`button` (avec `:focus` visible),
classes `.button-primary`/`.button-secondary`, classes `.amap-notice` (+ modificateurs
`--success`/`--error`/`--info`, pas encore utilisées dans les templates — prévu en B), style du
menu (`wp_nav_menu`), affinage `header`/`footer`/`main`, media query pour empiler le header en
mobile.

Comme les sélecteurs sont en grande partie sur des balises HTML natives (`form`, `input`,
`button`, `a`), une partie du rendu s'améliore déjà sur toutes les pages front sans toucher aux
templates PHP. Aucun changement côté admin (le CSS du thème n'est chargé que sur le front, via le
hook `wp_enqueue_scripts` dans `functions.php`).

**Correctif découvert pendant la validation** : les 6 fonctions `amap_maybe_render_*` /
`amap_maybe_render_member_area` du plugin (`association-manager.php`) court-circuitent la
hiérarchie de templates WordPress via `template_redirect` (`get_header(); get_template_part(...);
get_footer(); exit;`), sans jamais passer par `page.php` — le seul endroit où `<main>` est ouvert.
Résultat : tout le parcours de connexion et l'espace membre s'affichaient collés au bord gauche,
sans le `max-width`/padding/centrage défini sur `main` dans `style.css`. Corrigé en ajoutant
`<main>...</main>` autour du contenu dans ces 6 fonctions (pas de nouvelle abstraction : même
duplication ponctuelle que `get_header()`/`get_footer()`, déjà répétés à l'identique).

**Deuxième correctif découvert pendant la validation** : `header`/`main`/`footer` n'étaient que
des blocs empilés, donc sur une page au contenu court (ex. un simple formulaire de connexion), le
footer remontait juste après le contenu au lieu de rester en bas de la fenêtre ("sticky footer").
Corrigé en passant `body` en `display: flex; flex-direction: column; min-height: 100vh;` et
`main` en `flex: 1 0 auto;` (technique standard, ne casse pas le centrage `margin: 0 auto` déjà en
place sur `main`, qui continue de fonctionner comme marge automatique sur l'axe transversal du
flex).

## Sous-étape B (fait) — Parcours de connexion + espace membre + profil

Fichiers modifiés : les 7 templates de `wp-content/themes/amap-theme/template-parts/login/*`.

Contenu : messages d'erreur/succès (email invalide, mot de passe incorrect, lien envoyé, mot de
passe mis à jour, erreurs du formulaire de profil) passés en `.amap-notice` (`--error` ou
`--success` selon le cas), boutons secondaires différenciés visuellement des actions principales
(`.button-secondary` sur "Mot de passe oublié ?" et "Annuler"), liens de confirmation (lien
magique, écran de message) stylés en `.button-primary` pour bien marquer l'action attendue.
Espace membre (`member-area.php`) : les trois phrases "Vous êtes adhérent/producteur/membre du
bureau" remplacées par des badges de rôle (`.amap-badge`), section "Mes informations" mise dans
une carte (`.amap-card`). Formulaire de profil (`member-profile-edit.php`) : labels associés à
leur champ via `for`/`id` (au lieu du `<label>` englobant le champ, qui héritait à tort du
`font-weight: 600` du label) et remplacement de l'inline `style="color:#d63638;"` (dupliqué avec
la page admin "Utilisateurs AMAP") par la classe `.field-error`.

CSS ajouté dans `style.css` pour ces nouveaux éléments : `--color-primary-bg` (teinte des badges),
`.amap-badges`/`.amap-badge`, `.amap-card`, `.field-error`.

**Correctif découvert pendant la validation** : `.field-error { display: block; }` s'appliquait
même quand l'attribut HTML `hidden` était présent sur le `<span>` (affichage du message d'erreur
téléphone dès l'ouverture du formulaire, avant toute saisie) — une règle CSS d'auteur l'emporte
sur la règle par défaut du navigateur `[hidden] { display: none; }` même à spécificité égale.
Corrigé en ajoutant `.field-error[hidden] { display: none; }`.

## Sous-étape C (fait) — Vitrine publique

Fichiers modifiés : `style.css`, `single.php`, `page.php` (`home.php`/`index.php` inchangés,
déjà couverts par les règles génériques ci-dessous).

Contenu : `<article>` (accueil, page, article) mis en carte (fond blanc, bordure, padding),
premier enfant sans marge haute pour éviter le double espace. Ajout d'un reset `img { max-width:
100%; height: auto; }` (absent jusqu'ici, risque de débordement pour toute image de contenu) et
d'un style dédié à l'image à la une (`.wp-post-image`, classe posée automatiquement par
`the_post_thumbnail()`). `single.php`/`page.php` passent explicitement en taille `large` pour
cette image (au lieu de la taille par défaut, potentiellement minuscule si `post-thumbnail` n'est
pas enregistrée). Date de publication (`single.php`) en `.amap-post-meta` (texte atténué). Menu :
l'élément actif (`current-menu-item`/`current_page_item`, classes posées automatiquement par
`wp_nav_menu()`) est maintenant visuellement distingué. La réponsivité du header (empilement en
mobile) était déjà couverte par la sous-étape A.

## Sous-étape D (fait) — Page admin "Contrats" : séparation visuelle

Fichier modifié : `amap_render_contracts_page()` dans `association-manager.php`.

Les trois sous-sections conditionnelles (visibles seulement en mode édition d'un contrat, selon
son `contract_type`) sont chacune enveloppées dans un bloc `.postbox` natif WordPress
(`<div class="postbox"><h2 class="hndle"><span>...</span></h2><div class="inside">...</div>
</div>`) : "Tailles de panier" (`basket_recurring`), "Produits" et "Dates de livraison"
(`product_grid`, auparavant dans le même bloc conditionnel PHP — désormais deux `.postbox`
distincts au sein de la même condition). Le CSS `.postbox` fait partie du CSS core de wp-admin
déjà chargé sur toutes les pages d'admin : aucun enqueue supplémentaire n'a été nécessaire.
Volontairement pas de JS de collapse/drag (pas d'`add_meta_box()`/`postboxes.add_postbox_toggles()`)
: juste la classe visuelle, pour rester cohérent avec le principe "pas d'abstraction non
demandée". Le formulaire principal d'ajout/modification de contrat et le tableau final listant
tous les contrats restent inchangés (déjà des sections autonomes, pas concernées par
l'empilement).
