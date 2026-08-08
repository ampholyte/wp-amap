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
E2. Masquer les formulaires de création derrière un bouton
E3. Onglets sur la page admin "Contrats"
```

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
