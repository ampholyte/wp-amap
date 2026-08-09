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

<?php if ( 'profile_updated' === ( $args['notice'] ?? '' ) ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Vos informations ont été mises à jour.', 'association-manager' ); ?></div>
<?php elseif ( 'subscription_created' === ( $args['notice'] ?? '' ) ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Votre souscription a bien été enregistrée.', 'association-manager' ); ?></div>
<?php elseif ( 'leave_declared' === ( $args['notice'] ?? '' ) ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Votre congé a bien été déclaré.', 'association-manager' ); ?></div>
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
    <svg class="amap-icon-sprite" aria-hidden="true">
        <defs>
            <symbol id="amap-icon-pin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 21s7-7.2 7-12.1A7 7 0 1 0 5 8.9C5 13.8 12 21 12 21Z"></path>
                <circle cx="12" cy="8.9" r="2.4"></circle>
            </symbol>
            <symbol id="amap-icon-calendar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3.5" y="5" width="17" height="15" rx="2"></rect>
                <path d="M3.5 9.5h17"></path>
                <path d="M8 3v4M16 3v4"></path>
            </symbol>
            <symbol id="amap-icon-basket" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 9h16l-1.4 9.1a2 2 0 0 1-2 1.7H7.4a2 2 0 0 1-2-1.7L4 9Z"></path>
                <path d="M8 9 9 4h6l1 5"></path>
                <path d="M9.5 12.2v4.6M12 12.2v4.6M14.5 12.2v4.6"></path>
            </symbol>
            <symbol id="amap-icon-grid" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <rect x="4" y="4" width="7" height="7" rx="1.2"></rect>
                <rect x="13" y="4" width="7" height="7" rx="1.2"></rect>
                <rect x="4" y="13" width="7" height="7" rx="1.2"></rect>
                <rect x="13" y="13" width="7" height="7" rx="1.2"></rect>
            </symbol>
            <symbol id="amap-icon-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 9.5 12 14.5 17 9.5"></path>
            </symbol>
        </defs>
    </svg>

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
