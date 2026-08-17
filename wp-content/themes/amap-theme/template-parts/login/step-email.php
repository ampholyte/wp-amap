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
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Merci de saisir une adresse email valide.', 'association-manager' ); ?></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="amap_login_email_step">
    <div class="amap-field">
        <label for="amap_email"><?php esc_html_e( 'Adresse email', 'association-manager' ); ?></label>
        <input type="email" id="amap_email" name="email" required>
    </div>
    <button type="submit" class="button-primary button-block"><?php esc_html_e( 'Continuer', 'association-manager' ); ?></button>
</form>

<?php if ( AMAP_DEMO_MODE ) : ?>
    <!-- Identifiants fixes d'un environnement de démo (Playground) : pas de __()/_e(), cette
         section ne s'affiche jamais en production et n'a pas vocation à être traduite. -->
    <div class="amap-notice amap-notice--info">
        <p><strong>Comptes de démonstration</strong></p>
        <ul>
            <li>Administrateur : <a href="<?php echo esc_url( admin_url() ); ?>">wp-admin</a>, identifiant <code>admin</code> / mot de passe <code>password</code></li>
            <li>Bureau : <code>bureau-demo@example.com</code> / mot de passe <code>demo1234</code></li>
            <li>Producteur : <code>maraicher-demo@example.com</code> / mot de passe <code>demo1234</code> (et 3 autres producteurs : <code>boulanger1-demo</code>, <code>boulanger2-demo</code>, <code>laitier-demo</code>)</li>
            <li>Adhérent : <code>camille.bernard@example.com</code> (pas de mot de passe, le lien de connexion s'affiche directement à l'écran une fois l'email saisi ; 9 autres adhérents créés sur le même modèle <code>prenom.nom@example.com</code>)</li>
        </ul>
    </div>
<?php endif; ?>
