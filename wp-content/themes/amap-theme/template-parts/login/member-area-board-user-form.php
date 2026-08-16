<?php
/**
 * Formulaire "Ajouter"/"Modifier un utilisateur", section "Utilisateurs" de l'espace bureau —
 * page dédiée (pas de modale, voir project_espace_bureau_design_consolide), reprise de
 * amap_render_users_page() côté wp-admin. La soumission est traitée par les mêmes handlers que
 * wp-admin (amap_handle_add_user()/amap_handle_update_user()), le champ caché "redirect_to" leur
 * indiquant de revenir ici plutôt que sur la page wp-admin. $args : voir
 * amap_get_board_user_form_data() (plugin, member-area.php).
 *
 * Sous-page en dehors de la coquille à onglets (atteinte directement par
 * amap_maybe_render_member_area(), pas via member-area.php) : elle inclut donc elle-même les
 * symboles SVG (#amap-icon-*), comme member-profile-edit.php.
 */
$editing_id     = $args['editing_id'];
$form_data      = $args['form_data'];
$selected_roles = $form_data['roles'] ?? array();
$is_member_role = in_array( 'amap_member', $selected_roles, true );
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( amap_get_board_users_url() ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php esc_html_e( 'Retour à la liste', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title">
        <?php echo $editing_id
            ? esc_html__( 'Modifier un utilisateur', 'association-manager' )
            : esc_html__( 'Ajouter un utilisateur', 'association-manager' ); ?>
    </h1>
</div>

<?php if ( 'invalid' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Champs obligatoires manquants ou aucun rôle sélectionné.', 'association-manager' ); ?></div>
<?php elseif ( 'invalid_phone' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Le téléphone doit être au format 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></div>
<?php elseif ( 'account_error' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Impossible de créer le compte WordPress associé à cet email.', 'association-manager' ); ?></div>
<?php elseif ( 'contact_error' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( "Le compte a été créé ou mis à jour mais l'enregistrement du téléphone/adresse a échoué.", 'association-manager' ); ?></div>
<?php elseif ( 'email_taken' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Cet email est déjà utilisé par un autre compte WordPress.', 'association-manager' ); ?></div>
<?php endif; ?>

<form class="amap-profile-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-board-user-form" novalidate>
    <?php if ( $editing_id ) : ?>
        <?php wp_nonce_field( 'amap_edit_user_' . $editing_id ); ?>
        <input type="hidden" name="action" value="amap_update_user">
        <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
    <?php else : ?>
        <?php wp_nonce_field( 'amap_add_user' ); ?>
        <input type="hidden" name="action" value="amap_add_user">
    <?php endif; ?>
    <input type="hidden" name="redirect_to" value="<?php echo esc_url( amap_get_board_users_url() ); ?>">

    <div class="amap-field-row">
        <div class="amap-field">
            <label for="amap-board-user-last-name"><?php esc_html_e( 'Nom', 'association-manager' ); ?></label>
            <input type="text" id="amap-board-user-last-name" name="last_name" value="<?php echo esc_attr( $form_data['last_name'] ?? '' ); ?>" required>
        </div>
        <div class="amap-field">
            <label for="amap-board-user-first-name"><?php esc_html_e( 'Prénom', 'association-manager' ); ?></label>
            <input type="text" id="amap-board-user-first-name" name="first_name" value="<?php echo esc_attr( $form_data['first_name'] ?? '' ); ?>" required>
        </div>
    </div>

    <div class="amap-field">
        <label for="amap-board-user-email"><?php esc_html_e( 'Email', 'association-manager' ); ?></label>
        <input type="email" id="amap-board-user-email" name="email" value="<?php echo esc_attr( $form_data['email'] ?? '' ); ?>" required>
        <?php if ( ! $editing_id ) : ?>
            <p class="amap-field__hint">
                <?php esc_html_e( "Si un compte WordPress existe déjà avec cet email, il est réutilisé (identité inchangée) et les rôles cochés ci-dessous lui sont simplement ajoutés — utile pour faire cumuler une nouvelle casquette à un utilisateur existant.", 'association-manager' ); ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="amap-field-row">
        <div class="amap-field">
            <label for="amap-board-user-phone"><?php esc_html_e( 'Téléphone', 'association-manager' ); ?></label>
            <input type="text" inputmode="tel" name="phone" id="amap-board-user-phone" value="<?php echo esc_attr( $form_data['phone'] ?? '' ); ?>" pattern="(0[1-9]|\+33[1-9])([\s.-]?\d{2}){4}" placeholder="0X XX XX XX XX" required aria-describedby="amap-board-user-phone-hint amap-board-user-phone-error">
            <span id="amap-board-user-phone-hint" class="amap-field__hint"><?php esc_html_e( 'Format attendu : 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></span>
            <span id="amap-board-user-phone-error" class="amap-field__error" hidden><?php esc_html_e( 'Ce numéro ne correspond pas au format attendu.', 'association-manager' ); ?></span>
        </div>
        <div class="amap-field">
            <label for="amap-board-user-address"><?php esc_html_e( 'Adresse', 'association-manager' ); ?> <span class="amap-field__optional">(<?php esc_html_e( 'facultatif', 'association-manager' ); ?>)</span></label>
            <input type="text" id="amap-board-user-address" name="address" value="<?php echo esc_attr( $form_data['address'] ?? '' ); ?>">
        </div>
    </div>

    <div class="amap-field">
        <span class="amap-field__hint" id="amap-board-user-roles-label"><?php esc_html_e( 'Rôles', 'association-manager' ); ?></span>
        <div class="amap-role-toggle" role="group" aria-labelledby="amap-board-user-roles-label">
            <?php foreach ( amap_get_available_roles() as $role_slug => $role_label ) : ?>
                <label class="amap-role-toggle__option<?php echo in_array( $role_slug, $selected_roles, true ) ? ' is-checked' : ''; ?>">
                    <input class="sr-only" type="checkbox" name="roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $selected_roles, true ) ); ?> id="amap-board-user-role-<?php echo esc_attr( $role_slug ); ?>">
                    <?php echo esc_html( $role_label ); ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="amap-field" id="amap-board-user-group-row"<?php echo $is_member_role ? '' : ' hidden'; ?>>
        <label for="amap-board-user-group"><?php esc_html_e( 'Groupe (point de retrait)', 'association-manager' ); ?></label>
        <select id="amap-board-user-group" name="group_id">
            <option value=""><?php esc_html_e( '— aucun pour l\'instant —', 'association-manager' ); ?></option>
            <?php foreach ( $args['groups'] as $group ) : ?>
                <option value="<?php echo esc_attr( $group->id ); ?>" <?php selected( (string) $group->id, $form_data['group_id'] ?? '' ); ?>>
                    <?php echo esc_html( $group->name ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="amap-field__hint"><?php esc_html_e( "Point de retrait de l'adhérent : détermine les contrats qu'il pourra voir et souscrire.", 'association-manager' ); ?></p>
    </div>

    <div class="amap-form-actions">
        <button type="submit" class="button-primary">
            <?php echo $editing_id ? esc_html__( 'Enregistrer', 'association-manager' ) : esc_html__( 'Ajouter', 'association-manager' ); ?>
        </button>
        <a class="button-secondary" href="<?php echo esc_url( amap_get_board_users_url() ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
    </div>
</form>
<script>
( function () {
    "use strict";
    var form           = document.getElementById( 'amap-board-user-form' );
    var phoneField     = document.getElementById( 'amap-board-user-phone' );
    var phoneFieldWrap = phoneField.closest( '.amap-field' );
    var phoneError     = document.getElementById( 'amap-board-user-phone-error' );
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

    var memberCheckbox = document.getElementById( 'amap-board-user-role-amap_member' );
    var groupRow        = document.getElementById( 'amap-board-user-group-row' );
    if ( memberCheckbox && groupRow ) {
        memberCheckbox.addEventListener( 'change', function () {
            groupRow.hidden = ! memberCheckbox.checked;
        } );
    }

    form.querySelectorAll( '.amap-role-toggle__option' ).forEach( function ( option ) {
        var checkbox = option.querySelector( 'input' );
        checkbox.addEventListener( 'change', function () {
            option.classList.toggle( 'is-checked', checkbox.checked );
        } );
    } );
} )();
</script>
