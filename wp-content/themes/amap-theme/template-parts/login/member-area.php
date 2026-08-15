<?php
/**
 * Espace membre affiché sur "espace-adherent" à un utilisateur connecté (cf.
 * amap_maybe_render_member_area() dans le plugin, qui calcule is_member/is_producer/is_board et
 * l'onglet actif). Coquille : notices + barre de nav (member-area-nav.php), puis le contenu de
 * l'onglet actif (member-area-member.php / -producer.php / -profile.php). Pas de "Bonjour X" ni de
 * badges de casquette ici (contrairement à avant la refonte UX/UI) : ils vivent désormais
 * uniquement dans l'onglet "Mes infos" (member-profile.php), l'identité du site étant déjà portée
 * par la barre du haut (header-app.php).
 */

$current_user = wp_get_current_user();
?>

<?php if ( 'profile_updated' === ( $args['notice'] ?? '' ) ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Vos informations ont été mises à jour.', 'association-manager' ); ?></div>
<?php elseif ( 'subscription_created' === ( $args['notice'] ?? '' ) ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Votre souscription a bien été enregistrée.', 'association-manager' ); ?></div>
<?php elseif ( 'leave_declared' === ( $args['notice'] ?? '' ) ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Votre congé a bien été déclaré.', 'association-manager' ); ?></div>
<?php endif; ?>

<?php if ( $args['is_amap_user'] ) : ?>
    <?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

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
        array(
            'current_user' => $current_user,
            'is_member'    => $args['is_member'],
            'is_producer'  => $args['is_producer'],
            'is_board'     => $args['is_board'],
        )
    );
    ?>
<?php endif; ?>
