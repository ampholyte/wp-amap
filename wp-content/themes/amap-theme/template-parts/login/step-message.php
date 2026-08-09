<?php
/**
 * Écran "message" du parcours de connexion (espace-adherent) : accusé de réception sans
 * formulaire (lien magique envoyé, réinitialisation envoyée, mot de passe mis à jour).
 * $args['message'], $args['show_login_link'] et $args['demo_email'] : cf. remarque dans
 * step-email.php sur get_template_part() qui ne déplie pas $args en variables individuelles.
 */
?>

<h1><?php esc_html_e( 'Connexion', 'association-manager' ); ?></h1>

<div class="amap-notice amap-notice--success"><?php echo esc_html( $args['message'] ); ?></div>

<?php if ( $args['show_login_link'] ) : ?>
    <p><a class="button-primary" href="<?php echo esc_url( amap_get_member_area_url() ); ?>"><?php esc_html_e( 'Se connecter', 'association-manager' ); ?></a></p>
<?php endif; ?>

<?php if ( $args['demo_email'] ) : ?>
    <div class="amap-notice amap-notice--info">
        <p><strong><?php esc_html_e( "Mode démo : l'email ci-dessous n'a pas été réellement envoyé.", 'association-manager' ); ?></strong></p>
        <p><?php echo esc_html( $args['demo_email']['subject'] ); ?></p>
        <?php
        // Contenu déjà échappé au moment de sa construction (amap_send_login_link() /
        // amap_send_password_reset_link()), comme il le serait dans un vrai email.
        echo $args['demo_email']['body'];
        ?>
    </div>
<?php endif; ?>
