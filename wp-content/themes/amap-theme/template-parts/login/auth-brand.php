<?php
/**
 * Bloc d'identité en haut de la carte de connexion (logo du site ou 🧺+nom en repli) — même
 * logique que header-app.php (topbar de l'espace membre connecté), extraite ici pour ne pas la
 * dupliquer dans les 5 écrans du parcours de connexion (auth.php).
 */
?>
<div class="amap-auth-brand">
    <?php if ( has_custom_logo() ) : ?>
        <?php the_custom_logo(); ?>
    <?php else : ?>
        <span class="amap-auth-brand__mark" aria-hidden="true">🧺</span>
        <span class="amap-auth-brand__name"><?php bloginfo( 'name' ); ?></span>
    <?php endif; ?>
</div>
