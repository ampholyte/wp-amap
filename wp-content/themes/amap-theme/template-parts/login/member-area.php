<?php
/**
 * Espace membre affiché sur "espace-adherent" à un utilisateur connecté (cf.
 * amap_maybe_render_member_area() dans le plugin). Coquille minimale pour l'instant : le contenu
 * métier (contrats, distributions, congés) viendra dans une étape ultérieure.
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

<p><a href="<?php echo esc_url( wp_logout_url( amap_get_member_area_url() ) ); ?>"><?php esc_html_e( 'Se déconnecter', 'association-manager' ); ?></a></p>
