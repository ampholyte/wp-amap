<?php
/**
 * Formulaire de nouveau mot de passe (espace-adherent), atteint après confirmation d'un lien de
 * réinitialisation. $args['token'] et $args['has_error'] : cf. remarque dans step-email.php sur
 * get_template_part() qui ne déplie pas $args en variables individuelles.
 */
?>

<h1><?php esc_html_e( 'Connexion', 'association-manager' ); ?></h1>

<?php if ( $args['has_error'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Les deux mots de passe ne correspondent pas, ou sont trop courts (8 caractères minimum).', 'association-manager' ); ?></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'amap_set_new_password_' . $args['token'] ); ?>
    <input type="hidden" name="action" value="amap_set_new_password">
    <input type="hidden" name="token" value="<?php echo esc_attr( $args['token'] ); ?>">
    <p>
        <label for="amap_password"><?php esc_html_e( 'Nouveau mot de passe', 'association-manager' ); ?></label>
        <input type="password" id="amap_password" name="password" required>
    </p>
    <p>
        <label for="amap_password_confirm"><?php esc_html_e( 'Confirmez le mot de passe', 'association-manager' ); ?></label>
        <input type="password" id="amap_password_confirm" name="password_confirm" required>
    </p>
    <p><button type="submit"><?php esc_html_e( 'Enregistrer le nouveau mot de passe', 'association-manager' ); ?></button></p>
</form>
