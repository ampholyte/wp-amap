<?php
/**
 * Espace membre affiché sur "espace-adherent" à un utilisateur connecté (cf.
 * amap_maybe_render_member_area() dans le plugin, qui calcule is_member/is_producer/is_board).
 * Coquille minimale pour l'instant : une ligne par casquette détectée, cumulables. Le contenu
 * métier de chaque casquette (contrats, distributions, congés, gestion producteurs...) viendra
 * dans une étape ultérieure.
 */

$current_user = wp_get_current_user();
$display_name = $current_user->display_name ? $current_user->display_name : $current_user->user_email;
?>

<h1><?php esc_html_e( 'Espace adhérent', 'association-manager' ); ?></h1>

<p>
    <?php
    printf(
        /* translators: %s: nom ou email de l'utilisateur connecté */
        esc_html__( 'Bonjour %s.', 'association-manager' ),
        esc_html( $display_name )
    );
    ?>
</p>

<?php if ( $args['is_member'] ) : ?>
    <p><?php esc_html_e( 'Vous êtes adhérent.', 'association-manager' ); ?></p>
<?php endif; ?>

<?php if ( $args['is_producer'] ) : ?>
    <p><?php esc_html_e( 'Vous êtes producteur.', 'association-manager' ); ?></p>
<?php endif; ?>

<?php if ( $args['is_board'] ) : ?>
    <p><?php esc_html_e( 'Vous êtes membre du bureau.', 'association-manager' ); ?></p>
<?php endif; ?>

<p><a href="<?php echo esc_url( wp_logout_url( amap_get_member_area_url() ) ); ?>"><?php esc_html_e( 'Se déconnecter', 'association-manager' ); ?></a></p>
