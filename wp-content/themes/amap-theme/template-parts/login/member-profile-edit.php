<?php
/**
 * Formulaire self-service de modification des informations d'un adhérent (nom, prénom, email,
 * téléphone, adresse). Rendu par amap_render_member_profile_edit_form() ; la soumission est
 * traitée par amap_handle_update_member_profile(), qui ne modifie jamais que le compte de
 * l'utilisateur actuellement connecté.
 *
 * Sous-page en dehors de la coquille à onglets (atteinte directement par
 * amap_maybe_render_member_area(), pas via member-area.php) : elle inclut donc elle-même les
 * symboles SVG (#amap-icon-*).
 */
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( amap_get_member_area_url() ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php esc_html_e( 'Retour à mes infos', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title"><?php esc_html_e( 'Modifier mes informations', 'association-manager' ); ?></h1>
</div>

<?php if ( 'invalid' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Merci de renseigner tous les champs obligatoires.', 'association-manager' ); ?></div>
<?php elseif ( 'invalid_phone' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Le téléphone doit être au format 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></div>
<?php elseif ( 'email_taken' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Cet email est déjà utilisé par un autre compte.', 'association-manager' ); ?></div>
<?php elseif ( 'account_error' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'La mise à jour de votre compte a échoué.', 'association-manager' ); ?></div>
<?php elseif ( 'contact_error' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( "Vos nom/prénom/email ont été mis à jour, mais l'enregistrement du téléphone/adresse a échoué.", 'association-manager' ); ?></div>
<?php endif; ?>

<form class="amap-profile-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-member-profile-form" novalidate>
    <?php wp_nonce_field( 'amap_update_member_profile' ); ?>
    <input type="hidden" name="action" value="amap_update_member_profile">

    <div class="amap-field-row">
        <div class="amap-field">
            <label for="amap-member-last-name"><?php esc_html_e( 'Nom', 'association-manager' ); ?></label>
            <input type="text" id="amap-member-last-name" name="last_name" value="<?php echo esc_attr( $args['form_data']['last_name'] ); ?>" required>
        </div>
        <div class="amap-field">
            <label for="amap-member-first-name"><?php esc_html_e( 'Prénom', 'association-manager' ); ?></label>
            <input type="text" id="amap-member-first-name" name="first_name" value="<?php echo esc_attr( $args['form_data']['first_name'] ); ?>" required>
        </div>
    </div>

    <div class="amap-field">
        <label for="amap-member-email"><?php esc_html_e( 'Email', 'association-manager' ); ?></label>
        <input type="email" id="amap-member-email" name="email" value="<?php echo esc_attr( $args['form_data']['email'] ); ?>" required>
    </div>

    <div class="amap-field">
        <label for="amap-member-phone"><?php esc_html_e( 'Téléphone', 'association-manager' ); ?></label>
        <input type="text" inputmode="tel" name="phone" id="amap-member-phone" value="<?php echo esc_attr( $args['form_data']['phone'] ); ?>" pattern="(0[1-9]|\+33[1-9])([\s.-]?\d{2}){4}" placeholder="0X XX XX XX XX" required aria-describedby="amap-member-phone-hint amap-member-phone-error">
        <span id="amap-member-phone-hint" class="amap-field__hint"><?php esc_html_e( 'Format attendu : 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></span>
        <span id="amap-member-phone-error" class="amap-field__error" hidden><?php esc_html_e( 'Ce numéro ne correspond pas au format attendu.', 'association-manager' ); ?></span>
    </div>

    <div class="amap-field">
        <label for="amap-member-address"><?php esc_html_e( 'Adresse', 'association-manager' ); ?> <span class="amap-field__optional">(<?php esc_html_e( 'facultatif', 'association-manager' ); ?>)</span></label>
        <input type="text" id="amap-member-address" name="address" value="<?php echo esc_attr( $args['form_data']['address'] ); ?>">
    </div>

    <div class="amap-form-actions">
        <button type="submit" class="button-primary"><?php esc_html_e( 'Enregistrer', 'association-manager' ); ?></button>
        <a class="button-secondary" href="<?php echo esc_url( amap_get_member_area_url() ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
    </div>
</form>
<script>
( function () {
    "use strict";
    var form           = document.getElementById( 'amap-member-profile-form' );
    var phoneField     = document.getElementById( 'amap-member-phone' );
    var phoneFieldWrap = phoneField.closest( '.amap-field' );
    var phoneError     = document.getElementById( 'amap-member-phone-error' );
    // Même règle que la validation serveur (amap_is_valid_phone).
    var phonePattern   = /^(0[1-9]\d{8}|\+33[1-9]\d{8})$/;

    function isPhoneValid( value ) {
        return phonePattern.test( value.replace( /[\s.-]/g, '' ) );
    }

    function setPhoneError( hasError ) {
        phoneFieldWrap.classList.toggle( 'has-error', hasError );
        phoneError.hidden = ! hasError;
    }

    phoneField.addEventListener( 'input', function () {
        if ( phoneFieldWrap.classList.contains( 'has-error' ) ) {
            setPhoneError( ! isPhoneValid( phoneField.value ) );
        }
    } );

    form.addEventListener( 'submit', function ( event ) {
        var valid = isPhoneValid( phoneField.value );
        setPhoneError( ! valid );
        if ( ! valid ) {
            event.preventDefault();
            phoneField.focus();
        }
    } );
} )();
</script>
