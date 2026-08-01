<?php
/**
 * Formulaire self-service de modification des informations d'un adhérent (nom, prénom, email,
 * téléphone, adresse). Rendu par amap_render_member_profile_edit_form() ; la soumission est
 * traitée par amap_handle_update_member_profile(), qui ne modifie jamais que le compte de
 * l'utilisateur actuellement connecté.
 */
?>

<h1><?php esc_html_e( 'Mes informations', 'association-manager' ); ?></h1>

<?php if ( 'invalid' === $args['notice'] ) : ?>
    <p><?php esc_html_e( 'Merci de renseigner tous les champs obligatoires.', 'association-manager' ); ?></p>
<?php elseif ( 'invalid_phone' === $args['notice'] ) : ?>
    <p><?php esc_html_e( 'Le téléphone doit être au format 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></p>
<?php elseif ( 'email_taken' === $args['notice'] ) : ?>
    <p><?php esc_html_e( 'Cet email est déjà utilisé par un autre compte.', 'association-manager' ); ?></p>
<?php elseif ( 'account_error' === $args['notice'] ) : ?>
    <p><?php esc_html_e( 'La mise à jour de votre compte a échoué.', 'association-manager' ); ?></p>
<?php elseif ( 'contact_error' === $args['notice'] ) : ?>
    <p><?php esc_html_e( "Vos nom/prénom/email ont été mis à jour, mais l'enregistrement du téléphone/adresse a échoué.", 'association-manager' ); ?></p>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-member-profile-form">
    <?php wp_nonce_field( 'amap_update_member_profile' ); ?>
    <input type="hidden" name="action" value="amap_update_member_profile">
    <p>
        <label>
            <?php esc_html_e( 'Nom', 'association-manager' ); ?>
            <input type="text" name="last_name" value="<?php echo esc_attr( $args['form_data']['last_name'] ); ?>" required>
        </label>
    </p>
    <p>
        <label>
            <?php esc_html_e( 'Prénom', 'association-manager' ); ?>
            <input type="text" name="first_name" value="<?php echo esc_attr( $args['form_data']['first_name'] ); ?>" required>
        </label>
    </p>
    <p>
        <label>
            <?php esc_html_e( 'Email', 'association-manager' ); ?>
            <input type="email" name="email" value="<?php echo esc_attr( $args['form_data']['email'] ); ?>" required>
        </label>
    </p>
    <p>
        <label>
            <?php esc_html_e( 'Téléphone', 'association-manager' ); ?>
            <input type="text" inputmode="tel" name="phone" id="amap-member-phone" value="<?php echo esc_attr( $args['form_data']['phone'] ); ?>" pattern="(0[1-9]|\+33[1-9])([\s.-]?\d{2}){4}" placeholder="0X XX XX XX XX" required>
            <span id="amap-member-phone-error" style="color:#d63638;" hidden><?php esc_html_e( 'Format attendu : 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></span>
        </label>
    </p>
    <p>
        <label>
            <?php esc_html_e( 'Adresse', 'association-manager' ); ?>
            <input type="text" name="address" value="<?php echo esc_attr( $args['form_data']['address'] ); ?>">
        </label>
    </p>
    <p>
        <button type="submit"><?php esc_html_e( 'Enregistrer', 'association-manager' ); ?></button>
        <a href="<?php echo esc_url( amap_get_member_area_url() ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
    </p>
</form>
<script>
( function () {
    var form         = document.getElementById( 'amap-member-profile-form' );
    var phoneField   = document.getElementById( 'amap-member-phone' );
    var phoneError   = document.getElementById( 'amap-member-phone-error' );
    // Même règle que la validation serveur (amap_is_valid_phone).
    var phonePattern = /^(0[1-9]\d{8}|\+33[1-9]\d{8})$/;

    function isPhoneValid( value ) {
        return phonePattern.test( value.replace( /[\s.-]/g, '' ) );
    }

    form.addEventListener( 'submit', function ( event ) {
        var valid = isPhoneValid( phoneField.value );
        phoneError.hidden = valid;
        if ( ! valid ) {
            event.preventDefault();
            phoneField.focus();
        }
    } );
} )();
</script>
