<?php
/**
 * Étape "email" du parcours de connexion (espace-adherent).
 *
 * get_template_part() ne "déplie" pas $args en variables individuelles (seules les query vars de
 * $wp_query le sont, cf. load_template() dans le cœur WordPress) : on lit donc $args['has_error']
 * directement, plutôt qu'une variable $has_error qui n'existerait jamais.
 */
?>

<h1><?php esc_html_e( 'Connexion', 'association-manager' ); ?></h1>

<?php if ( $args['has_error'] ) : ?>
    <p><?php esc_html_e( 'Merci de saisir une adresse email valide.', 'association-manager' ); ?></p>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="amap_login_email_step">
    <p>
        <label for="amap_email"><?php esc_html_e( 'Adresse email', 'association-manager' ); ?></label><br>
        <input type="email" id="amap_email" name="email" required>
    </p>
    <p><button type="submit"><?php esc_html_e( 'Continuer', 'association-manager' ); ?></button></p>
</form>
