<?php
/**
 * Barre de navigation de l'espace membre : un onglet par casquette portée, plus "Mes infos"
 * (toujours). "Déconnexion" est dans la barre d'identité au-dessus (header-app.php), commune à
 * tous les écrans de l'espace membre. "Espace bureau" est un onglet de contenu réel depuis la
 * migration section par section de wp-admin (voir member-area-board.php) : encore un simple lien
 * wp-admin pour les utilisateurs qui n'ont pas la capability, mais ce cas ne devrait plus se
 * produire une fois la migration terminée.
 *
 * Le badge "Admin" (avant "Mes infos") est réservé aux administrateurs WordPress
 * (current_user_can('manage_options'), voir amap_maybe_render_member_area()) : la barre d'outils
 * WordPress est entièrement masquée sur cette page, ce badge est donc leur seul moyen de revenir
 * vers wp-admin depuis l'espace membre.
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
        <a class="amap-nav-item<?php echo ( 'board' === $args['active_tab'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( amap_get_board_users_url() ); ?>">
            <?php esc_html_e( 'Espace bureau', 'association-manager' ); ?>
        </a>
    <?php endif; ?>
    <?php if ( $args['is_wp_admin'] ) : ?>
        <a class="amap-nav-item amap-nav-item--admin" href="<?php echo esc_url( admin_url() ); ?>">
            <?php esc_html_e( 'Admin', 'association-manager' ); ?>
        </a>
    <?php endif; ?>
    <a class="amap-nav-item<?php echo ( 'profile' === $args['active_tab'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( amap_get_member_area_tab_url( 'profile' ) ); ?>">
        <?php esc_html_e( 'Mes infos', 'association-manager' ); ?>
    </a>
</nav>
