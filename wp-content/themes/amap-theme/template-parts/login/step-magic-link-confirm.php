<?php
/**
 * Écran de confirmation du lien magique (espace-adherent), atteint en cliquant sur le lien reçu
 * par email. $args['confirm_url'] et $args['is_password_reset'] : cf. remarque dans
 * step-email.php sur get_template_part() qui ne déplie pas $args en variables individuelles.
 */
?>

<h1><?php esc_html_e( 'Connexion', 'association-manager' ); ?></h1>

<?php if ( $args['is_password_reset'] ) : ?>
    <p class="amap-auth-step__text"><?php esc_html_e( 'Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe.', 'association-manager' ); ?></p>
    <a class="button-primary button-block" href="<?php echo esc_url( $args['confirm_url'] ); ?>"><?php esc_html_e( 'Cliquez ici pour choisir un nouveau mot de passe', 'association-manager' ); ?></a>
<?php else : ?>
    <p class="amap-auth-step__text"><?php esc_html_e( 'Cliquez sur le bouton ci-dessous pour finaliser votre connexion.', 'association-manager' ); ?></p>
    <a class="button-primary button-block" href="<?php echo esc_url( $args['confirm_url'] ); ?>"><?php esc_html_e( 'Cliquez ici pour vous connecter', 'association-manager' ); ?></a>
<?php endif; ?>
