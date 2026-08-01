<?php
/**
 * Étape "mot de passe" du parcours de connexion (espace-adherent), pour un compte
 * producteur/bureau. $args['email'] et $args['has_error'] : cf. remarque dans step-email.php sur
 * get_template_part() qui ne déplie pas $args en variables individuelles.
 */
?>

<h1><?php esc_html_e( 'Connexion', 'association-manager' ); ?></h1>

<?php if ( $args['has_error'] ) : ?>
    <p><?php esc_html_e( 'Email ou mot de passe incorrect.', 'association-manager' ); ?></p>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="amap_login_password_step">
    <input type="hidden" name="email" value="<?php echo esc_attr( $args['email'] ); ?>">
    <p>
        <label for="amap_password"><?php esc_html_e( 'Mot de passe', 'association-manager' ); ?></label><br>
        <input type="password" id="amap_password" name="password" required>
    </p>
    <p><button type="submit"><?php esc_html_e( 'Se connecter', 'association-manager' ); ?></button></p>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="amap_request_password_reset">
    <input type="hidden" name="email" value="<?php echo esc_attr( $args['email'] ); ?>">
    <p><button type="submit"><?php esc_html_e( 'Mot de passe oublié ?', 'association-manager' ); ?></button></p>
</form>
