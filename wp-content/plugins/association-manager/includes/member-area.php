<?php
/**
 * Espace adhérent front-end : affichage des casquettes et édition du profil.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'template_redirect', 'amap_maybe_render_member_area', 5 );

/**
 * Espace membre minimal pour un utilisateur déjà connecté sur "espace-adherent". Priorité 5
 * (les écrans du parcours de connexion ci-dessus sont en priorité par défaut 10) pour s'exécuter
 * avant eux et court-circuiter systématiquement l'affichage d'un écran de connexion à un
 * utilisateur déjà connecté, quel que soit le paramètre de requête présent dans l'URL (ex. lien
 * de login gardé en favori après connexion).
 */
function amap_maybe_render_member_area() {
    if ( ! is_page( 'espace-adherent' ) || ! is_user_logged_in() ) {
        return;
    }

    $user             = wp_get_current_user();
    $is_member        = in_array( 'amap_member', $user->roles, true );
    $is_producer      = in_array( 'amap_producer', $user->roles, true );
    $is_board         = in_array( 'amap_board', $user->roles, true );
    // Nom/prénom/email/téléphone/adresse sont liés au compte (user_id), pas à une casquette
    // particulière : accessibles dès qu'au moins une casquette AMAP est portée.
    $is_amap_user     = $is_member || $is_producer || $is_board;
    $can_manage_users = current_user_can( 'amap_manage_users' );
    $action           = isset( $_GET['amap_member_action'] ) ? sanitize_key( wp_unslash( $_GET['amap_member_action'] ) ) : '';
    $notice           = isset( $_GET['amap_member_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_member_notice'] ) ) : '';

    // Un onglet par casquette portée (dans cet ordre de priorité par défaut), plus "profile"
    // toujours accessible dès qu'au moins une casquette AMAP est portée. "Espace bureau" n'est
    // pas un onglet : lien direct vers wp-admin (member-area-nav.php).
    $available_tabs = array();
    if ( $is_member ) {
        $available_tabs[] = 'member';
    }
    if ( $is_producer ) {
        $available_tabs[] = 'producer';
    }
    if ( $is_amap_user ) {
        $available_tabs[] = 'profile';
    }
    $requested_tab = isset( $_GET['amap_tab'] ) ? sanitize_key( wp_unslash( $_GET['amap_tab'] ) ) : '';
    $tab           = in_array( $requested_tab, $available_tabs, true ) ? $requested_tab : reset( $available_tabs );

    get_header();
    ?>
    <main>
    <?php
    if ( $is_amap_user && 'edit_profile' === $action ) {
        amap_render_member_profile_edit_form( $user );
    } else {
        get_template_part(
            'template-parts/login/member-area',
            null,
            array(
                'is_member'        => $is_member,
                'is_producer'      => $is_producer,
                'is_board'         => $is_board,
                'is_amap_user'     => $is_amap_user,
                'can_manage_users' => $can_manage_users,
                'tab'              => $tab,
                'profile_updated'  => ( 'profile_updated' === $notice ),
            )
        );
    }
    ?>
    </main>
    <?php
    get_footer();
    exit;
}

/**
 * Formulaire self-service permettant à un adhérent de modifier lui-même nom/prénom/email/
 * téléphone/adresse. Rendu par amap_maybe_render_member_area() ; la soumission est traitée par
 * amap_handle_update_member_profile().
 */
function amap_render_member_profile_edit_form( $user ) {
    $notice = isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '';

    // Comme amap_render_users_page() : en cas d'erreur de validation, on réaffiche les valeurs
    // saisies (transient) plutôt que les valeurs actuelles en base.
    $transient_key = 'amap_user_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } else {
        $contact   = amap_get_user_contact( $user->ID );
        $form_data = array(
            'last_name'  => $user->last_name,
            'first_name' => $user->first_name,
            'email'      => $user->user_email,
            'phone'      => $contact->phone ?? '',
            'address'    => $contact->address ?? '',
        );
    }

    get_template_part(
        'template-parts/login/member-profile-edit',
        null,
        array(
            'notice'    => $notice,
            'form_data' => $form_data,
        )
    );
}

add_action( 'admin_post_amap_update_member_profile', 'amap_handle_update_member_profile' );

/**
 * Traite le formulaire self-service (template-parts/login/member-profile-edit.php). Contrairement
 * à amap_handle_update_user() (admin, où l'ID cible vient de la requête), l'utilisateur modifié
 * est TOUJOURS get_current_user_id() : un adhérent ne peut ainsi jamais modifier un autre compte
 * en falsifiant un paramètre.
 */
function amap_handle_update_member_profile() {
    $user = wp_get_current_user();
    // Même vérification que amap_get_amap_user() : au moins une casquette AMAP, quelle qu'elle
    // soit — ces informations sont liées au compte, pas à un rôle particulier.
    if ( ! is_user_logged_in() || ! array_intersect( $user->roles, array_keys( amap_get_available_roles() ) ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_update_member_profile' );

    $id       = get_current_user_id();
    $edit_url = amap_get_member_profile_edit_url();

    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
    $submitted  = compact( 'last_name', 'first_name', 'email', 'phone', 'address' );

    if ( '' === $last_name || '' === $first_name || '' === $email || '' === $phone ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $edit_url ) );
        exit;
    }

    if ( ! amap_is_valid_phone( $phone ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_phone', $edit_url ) );
        exit;
    }

    // Comme amap_handle_update_user() : l'email doit rester libre, ou appartenir à CE compte.
    $email_owner = get_user_by( 'email', $email );
    if ( $email_owner && $email_owner->ID !== $id ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'email_taken', $edit_url ) );
        exit;
    }

    $updated = wp_update_user(
        array(
            'ID'         => $id,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'user_email' => $email,
        )
    );

    if ( is_wp_error( $updated ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'account_error', $edit_url ) );
        exit;
    }

    if ( ! amap_save_user_contact( $id, $phone, $address ) ) {
        wp_safe_redirect( add_query_arg( 'amap_notice', 'contact_error', $edit_url ) );
        exit;
    }

    wp_safe_redirect( add_query_arg( 'amap_member_notice', 'profile_updated', amap_get_member_area_url() ) );
    exit;
}
