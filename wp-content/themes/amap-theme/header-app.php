<?php
/**
 * Variante de header.php pour l'espace membre connecté. amap_maybe_render_member_area() (plugin)
 * appelle get_header( 'app' ) : WordPress cherche d'abord un fichier header-{nom}.php avant de
 * retomber sur header.php — c'est le mécanisme standard pour donner un habillage différent à
 * certaines pages sans dupliquer tout le thème (un peu comme une fonction surchargée par un
 * paramètre). Contrairement au site public, pas de logo ni de menu ici : l'espace membre se
 * présente comme un espace à part, avec sa propre barre d'identité + déconnexion, commune à tous
 * ses écrans (onglets comme sous-pages). wp_head()/wp_body_open() restent indispensables : ce sont
 * les points d'accroche où WordPress et les extensions injectent scripts/styles, même sur une page
 * qui n'affiche pas le design public.
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

<header class="amap-topbar">
    <div class="amap-topbar__brand">
        <?php if ( has_custom_logo() ) : ?>
            <?php the_custom_logo(); ?>
        <?php else : ?>
            <span class="amap-topbar__mark" aria-hidden="true">🧺</span>
            <span class="amap-topbar__name"><?php bloginfo( 'name' ); ?></span>
        <?php endif; ?>
    </div>
    <div class="amap-topbar__actions">
        <a class="amap-topbar__home" href="<?php echo esc_url( home_url( '/' ) ); ?>">
            <?php esc_html_e( 'Retour au site', 'association-manager' ); ?>
        </a>
        <a class="amap-topbar__logout" href="<?php echo esc_url( wp_logout_url( amap_get_member_area_url() ) ); ?>">
            <?php esc_html_e( 'Déconnexion', 'association-manager' ); ?>
        </a>
    </div>
</header>
