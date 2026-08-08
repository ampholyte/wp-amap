<?php
/**
 * Espace membre affiché sur "espace-adherent" à un utilisateur connecté (cf.
 * amap_maybe_render_member_area() dans le plugin, qui calcule is_member/is_producer/is_board et
 * l'onglet actif). Coquille : en-tête + badges + barre de nav (member-area-nav.php), puis le
 * contenu de l'onglet actif (member-area-member.php / -producer.php / -profile.php).
 */

$current_user = wp_get_current_user();
$display_name = $current_user->display_name ? $current_user->display_name : $current_user->user_email;
?>

<h1><?php esc_html_e( 'Espace adhérent', 'association-manager' ); ?></h1>

<?php if ( ! empty( $args['profile_updated'] ) ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Vos informations ont été mises à jour.', 'association-manager' ); ?></div>
<?php endif; ?>

<p>
    <?php
    printf(
        /* translators: %s: nom ou email de l'utilisateur connecté */
        esc_html__( 'Bonjour %s.', 'association-manager' ),
        esc_html( $display_name )
    );
    ?>
</p>

<p class="amap-badges">
    <?php if ( $args['is_member'] ) : ?>
        <span class="amap-badge"><?php esc_html_e( 'Adhérent', 'association-manager' ); ?></span>
    <?php endif; ?>
    <?php if ( $args['is_producer'] ) : ?>
        <span class="amap-badge"><?php esc_html_e( 'Producteur', 'association-manager' ); ?></span>
    <?php endif; ?>
    <?php if ( $args['is_board'] ) : ?>
        <span class="amap-badge"><?php esc_html_e( 'Bureau', 'association-manager' ); ?></span>
    <?php endif; ?>
</p>

<?php if ( $args['is_amap_user'] ) : ?>
    <?php
    get_template_part(
        'template-parts/login/member-area-nav',
        null,
        array(
            'is_member'        => $args['is_member'],
            'is_producer'      => $args['is_producer'],
            'can_manage_users' => $args['can_manage_users'],
            'active_tab'       => $args['tab'],
        )
    );

    get_template_part(
        'template-parts/login/member-area-' . $args['tab'],
        null,
        array( 'current_user' => $current_user )
    );
    ?>
<?php endif; ?>
