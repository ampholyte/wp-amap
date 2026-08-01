# Fonctionnement métier — casquette producteur

Document de synthèse issu d'un point métier (voir conversation du 2026-08-01), à relire avant
toute conception technique (modèle de données contrats/distributions/congés) pour la casquette
`amap_producer`. Rien n'est encore implémenté en base à ce stade (seule `wp_amap_users`
phone/address existe) — ce document décrit uniquement le fonctionnement réel de l'AMAP.

## Producteurs actuels

- **1 maraîcher** : présent toute l'année, propose 2 contrats en parallèle :
  - un contrat à livraison hebdomadaire,
  - un contrat à livraison toutes les 2 semaines.
  Chaque contrat propose 3 tailles de panier : petit, moyen, grand (prix fixe par taille et par
  contrat). Le contrat papier est signé, couvre 48 semaines sur les 52 de l'année (voir
  "Congés" ci-dessous), paiement en une ou plusieurs fois par chèque/virement (hors outil).
  Une fois signé, non modifiable/annulable (sauf arrangement direct avec le producteur, hors
  outil).
- **1 productrice laitière** : contrat trimestriel, plusieurs produits (yaourt, lait, fromage
  blanc, etc.). Le contrat liste à l'avance les dates de livraison du trimestre. L'adhérent
  choisit, **une seule fois à la signature**, une quantité par produit et par date de livraison
  (grille produit × date, figée ensuite pour toute la durée du contrat). Aujourd'hui géré sur
  Google Sheet.
- **2 boulangers** : même fonctionnement que la productrice laitière (contrat trimestriel,
  grille produit × date remplie une fois, figée ensuite).

Un producteur peut avoir plusieurs contrats actifs en parallèle (ex. le maraîcher). Un même
adhérent peut souscrire à plusieurs contrats en parallèle, avec des producteurs différents ou
plusieurs formules du même producteur.

## Groupes et distribution

- Plusieurs groupes existent au sein de l'AMAP, chacun avec son propre point de distribution et
  son propre jour de la semaine, décidés au sein du groupe.
- Une seule distribution par semaine et par groupe : tous les producteurs qui livrent ce
  groupe-là livrent au même endroit, à la même heure, ce jour-là.
- Le **rattachement producteur ↔ groupe est décidé par le bureau** : un groupe n'a pas
  automatiquement accès à tous les producteurs ; un producteur peut être présent dans plusieurs
  groupes.

## Congés (côté adhérent, pas côté distribution)

- Concerne uniquement le contrat maraîcher (48 semaines livrées sur 52).
- C'est un choix **individuel de l'adhérent** : il pose 4 dates dans l'année où il ne récupère
  pas de panier maraîcher. Ce n'est pas une décision de groupe, et la distribution elle-même a
  toujours lieu ce jour-là (les autres adhérents du groupe sont livrés normalement).
- Un adhérent en congé maraîcher peut tout de même venir à la distribution récupérer ce qu'il a
  commandé chez d'autres producteurs (laitière, boulangers) ce jour-là.
- Les dates de congé peuvent être déclarées **au fil de l'année**, avec un délai d'une semaine
  avant la distribution concernée (pas besoin de tout fixer à la signature du contrat). En
  revanche, l'adhérent doit avoir posé la totalité de ses 4 congés avant la fin du contrat
  (sous peine, semble-t-il, de perdre ce droit — à confirmer si besoin plus tard).

## Annulation / déplacement ponctuel d'une distribution

- Cas rare : demande d'un producteur, aléas météo, problème sur le point de livraison, etc.
- **Décision du bureau**, jamais du producteur ou du groupe seuls.
- Doit être **visible et notifié aux adhérents via l'outil** (pas juste géré à la main/de bouche
  à oreille) — contrairement aux congés individuels qui n'affectent pas la distribution
  elle-même, ceci modifie un événement partagé par tout le groupe et doit donc être communiqué.

## Paiement

- Géré entièrement hors outil (chèque ou virement direct). Le suivi du paiement n'est pas
  nécessairement dans le périmètre de l'application à ce stade.

## Points encore ouverts / non tranchés

- Que se passe-t-il si un adhérent n'a pas posé ses 4 congés avant la fin du contrat maraîcher ?
- Portée exacte de ce chantier (contrats/distributions/congés) : conception complète du modèle
  de données maintenant, vs. amélioration ciblée de l'affichage de la casquette producteur dans
  l'espace membre en attendant un chantier séparé — question posée, pas encore tranchée par
  l'utilisateur au moment de la rédaction de ce document.
