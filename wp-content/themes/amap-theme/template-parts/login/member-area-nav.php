<?php
/**
 * Barre de navigation de l'espace membre : un onglet par casquette portée, plus "Mes infos"
 * (toujours) et "Déconnexion". "Espace bureau" n'est pas un onglet de contenu : lien direct vers
 * wp-admin, seul espace de gestion du bureau (voir docs/plan-contrats-distributions.md).
 */
?>
<nav class="amap-nav">
    <?php if ( $args['is_member'] ) : ?>
        <a class="amap-nav-item<?php echo ( 'member' === $args['active_tab'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( amap_get_member_area_tab_url( 'member' ) ); ?>">
            <?php esc_html_e( 'Espace adhérent', 'association-manager' ); ?>
        </a>
    <?php endif; ?>
    <?php if ( $args['is_producer'] ) : ?>
        <a class="amap-nav-item<?php echo ( 'producer' === $args['active_tab'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( amap_get_member_area_tab_url( 'producer' ) ); ?>">
            <?php esc_html_e( 'Espace producteur', 'association-manager' ); ?>
        </a>
    <?php endif; ?>
    <?php if ( $args['can_manage_users'] ) : ?>
        <a class="amap-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=amap-users' ) ); ?>">
            <?php esc_html_e( 'Espace bureau', 'association-manager' ); ?>
        </a>
    <?php endif; ?>
    <a class="amap-nav-item<?php echo ( 'profile' === $args['active_tab'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( amap_get_member_area_tab_url( 'profile' ) ); ?>">
        <?php esc_html_e( 'Mes infos', 'association-manager' ); ?>
    </a>
    <a class="amap-nav-item amap-nav-item--logout" href="<?php echo esc_url( wp_logout_url( amap_get_member_area_url() ) ); ?>">
        <?php esc_html_e( 'Déconnexion', 'association-manager' ); ?>
    </a>
</nav>
