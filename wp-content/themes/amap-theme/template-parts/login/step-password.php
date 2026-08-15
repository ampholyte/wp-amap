<?php
/**
 * Étape "mot de passe" du parcours de connexion (espace-adherent), pour un compte
 * producteur/bureau. $args['email'] et $args['has_error'] : cf. remarque dans step-email.php sur
 * get_template_part() qui ne déplie pas $args en variables individuelles.
 */
?>

<h1><?php esc_html_e( 'Connexion', 'association-manager' ); ?></h1>

<?php if ( $args['has_error'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Email ou mot de passe incorrect.', 'association-manager' ); ?></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="amap_login_password_step">
    <input type="hidden" name="email" value="<?php echo esc_attr( $args['email'] ); ?>">
    <div class="amap-field">
        <label for="amap_password"><?php esc_html_e( 'Mot de passe', 'association-manager' ); ?></label>
        <input type="password" id="amap_password" name="password" required>
    </div>
    <button type="submit" class="button-primary button-block"><?php esc_html_e( 'Se connecter', 'association-manager' ); ?></button>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="amap_request_password_reset">
    <input type="hidden" name="email" value="<?php echo esc_attr( $args['email'] ); ?>">
    <button type="submit" class="button-secondary button-block"><?php esc_html_e( 'Mot de passe oublié ?', 'association-manager' ); ?></button>
</form>
