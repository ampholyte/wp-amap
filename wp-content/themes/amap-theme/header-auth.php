<?php
/**
 * Variante de header.php pour le parcours de connexion (avant authentification) : appelée via
 * get_header( 'auth' ) depuis les 5 écrans de connexion (auth.php, plugin). Comme header-app.php
 * (espace membre connecté), pas de logo/menu public ici — mais pas de barre d'identité non plus
 * (topbar) : "Déconnexion" n'a pas de sens avant d'être connecté, et l'identité du site est déjà
 * portée par la carte de connexion elle-même (auth-brand.php). body_class('amap-app') réutilise
 * les mêmes tokens que l'espace membre (rayons, ombre, accent, fond uni) : la connexion fait
 * visuellement partie du même ensemble, pas du site vitrine public.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'amap-app' ); ?>>
<?php wp_body_open(); ?>
