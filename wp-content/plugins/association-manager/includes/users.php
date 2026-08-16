<?php
/**
 * Page d'admin "Utilisateurs AMAP" : CRUD des comptes portant une casquette AMAP.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function amap_get_user_contact( $user_id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT phone, address FROM {$wpdb->prefix}amap_users WHERE user_id = %d",
            $user_id
        )
    );
}

function amap_get_available_roles() {
    return array(
        'amap_member'   => __( 'Adhérent', 'association-manager' ),
        'amap_producer' => __( 'Producteur', 'association-manager' ),
        'amap_board'    => __( 'Bureau', 'association-manager' ),
    );
}

function amap_format_user_roles( array $roles ) {
    // Ordre d'affichage fixe (adhérent, producteur, bureau) indépendant de l'ordre de
    // $roles, qui reflète l'ordre d'ajout des casquettes plutôt qu'un ordre voulu à l'affichage.
    return implode( ', ', array_intersect_key( amap_get_available_roles(), array_flip( $roles ) ) );
}

/**
 * Récupère un utilisateur AMAP par son ID (celui du compte WordPress). Retourne null si le
 * compte n'existe pas ou ne porte aucune des trois casquettes — un simple abonné WP par
 * exemple n'est pas un "utilisateur AMAP" éditable depuis cette page.
 */
function amap_get_amap_user( $user_id ) {
    $user = get_user_by( 'id', $user_id );
    if ( ! $user || ! array_intersect( $user->roles, array_keys( amap_get_available_roles() ) ) ) {
        return null;
    }

    return $user;
}

function amap_is_valid_phone( $phone ) {
    // On tolère espaces, points et tirets entre les chiffres, mais on valide sur le
    // numéro "nettoyé" : 10 chiffres commençant par 0, ou +33 suivi de 9 chiffres.
    $digits_only = preg_replace( '/[\s.\-]/', '', $phone );
    return (bool) preg_match( '/^(0[1-9]\d{8}|\+33[1-9]\d{8})$/', $digits_only );
}

function amap_store_user_form_data( array $data ) {
    // Durée de vie courte : cette donnée ne sert qu'à traverser la redirection qui suit
    // immédiatement une erreur de validation, pas à persister au-delà.
    set_transient( 'amap_user_form_' . get_current_user_id(), $data, 60 );
}

/**
 * Trouve le compte WordPress correspondant à cet email, ou en crée un nouveau. Aucun mot de
 * passe n'est communiqué à la création : l'authentification par casquette est une étape
 * ultérieure, ici on ne fait que poser l'identité (wp_users) et les rôles seront ajoutés par
 * l'appelant.
 */
function amap_find_or_create_user( $first_name, $last_name, $email ) {
    $user = get_user_by( 'email', $email );
    if ( $user ) {
        return $user;
    }

    $new_user_id = wp_insert_user(
        array(
            'user_login' => amap_generate_username( $first_name, $last_name ),
            'user_email' => $email,
            'user_pass'  => wp_generate_password( 32, true, true ),
            'first_name' => $first_name,
            'last_name'  => $last_name,
            // Chaîne vide : aucun rôle par défaut (ex. subscriber) n'est assigné à la
            // création, seuls les rôles cochés dans le formulaire seront ajoutés par l'appelant.
            'role'       => '',
        )
    );

    if ( is_wp_error( $new_user_id ) ) {
        return $new_user_id;
    }

    return get_user_by( 'id', $new_user_id );
}

/**
 * Construit un identifiant de connexion unique "prenom.nom", avec un suffixe numérique en cas
 * de collision (deux utilisateurs peuvent porter le même nom complet).
 */
function amap_generate_username( $first_name, $last_name ) {
    $base     = sanitize_user( remove_accents( $first_name . '.' . $last_name ), true );
    $username = $base;
    $suffix   = 1;

    while ( username_exists( $username ) ) {
        ++$suffix;
        $username = $base . $suffix;
    }

    return $username;
}

/**
 * Crée ou met à jour la ligne wp_amap_users (phone/address) d'un utilisateur. UPDATE plutôt
 * que $wpdb->replace() : replace() supprimerait puis réinsérerait la ligne, ce qui changerait
 * l'id et réinitialiserait created_at à chaque simple mise à jour du téléphone.
 */
function amap_save_user_contact( $user_id, $phone, $address ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'amap_users';
    $data       = array(
        'phone'   => $phone,
        'address' => '' !== $address ? $address : null,
    );

    $existing_id = $wpdb->get_var(
        $wpdb->prepare( "SELECT id FROM $table_name WHERE user_id = %d", $user_id )
    );

    if ( $existing_id ) {
        return false !== $wpdb->update( $table_name, $data, array( 'user_id' => $user_id ) );
    }

    $data['user_id'] = $user_id;
    return false !== $wpdb->insert( $table_name, $data );
}

add_action( 'admin_post_amap_add_user', 'amap_handle_add_user' );

function amap_handle_add_user() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_user' );

    // Le formulaire (wp-admin ou section "Utilisateurs" de l'espace bureau front) précise sa
    // page de retour via ce champ caché ; wp_safe_redirect() revalide de toute façon la
    // destination contre les hôtes autorisés, donc aucun risque à faire confiance à cette valeur
    // postée telle quelle.
    $redirect_base = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-users' );

    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
    $roles      = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['roles'] ) ) : array();
    $roles      = array_values( array_intersect( $roles, array_keys( amap_get_available_roles() ) ) );
    $group_id   = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $submitted  = compact( 'last_name', 'first_name', 'email', 'phone', 'address', 'roles', 'group_id' );

    if ( '' === $last_name || '' === $first_name || '' === $email || '' === $phone || empty( $roles )
        || ( $group_id && ! amap_get_group( $group_id ) ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $redirect_base ) );
        exit;
    }

    if ( ! amap_is_valid_phone( $phone ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_phone', $redirect_base ) );
        exit;
    }

    // Vérifié avant amap_find_or_create_user() : une fois celle-ci appelée, le compte existe
    // forcément (créé ou préexistant), on ne pourrait plus distinguer les deux cas.
    $account_already_existed = (bool) get_user_by( 'email', $email );

    $user = amap_find_or_create_user( $first_name, $last_name, $email );
    if ( is_wp_error( $user ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'account_error', $redirect_base ) );
        exit;
    }

    // Cumul des casquettes : add_role() ajoute le rôle sans retirer les rôles déjà présents.
    // Soumettre à nouveau ce formulaire avec le même email permet donc d'ajouter une nouvelle
    // casquette (ex. producteur) à un compte déjà adhérent, sans dupliquer l'identité.
    foreach ( $roles as $role ) {
        $user->add_role( $role );
    }

    // Comme les rôles, le groupe n'est fixé que si "Adhérent" est coché dans CETTE soumission :
    // un compte déjà adhérent, réutilisé ici seulement pour lui ajouter une autre casquette, ne
    // doit pas se voir modifier ou retirer son groupe existant.
    if ( in_array( 'amap_member', $roles, true ) ) {
        amap_set_member_group( $user->ID, $group_id );
    }

    if ( ! amap_save_user_contact( $user->ID, $phone, $address ) ) {
        wp_safe_redirect( add_query_arg( 'amap_notice', 'contact_error', $redirect_base ) );
        exit;
    }

    // Premier accès envoyé uniquement pour un compte réellement nouveau : réutiliser ce
    // formulaire pour ajouter une casquette à un compte déjà existant ne doit pas renvoyer de
    // lien à quelqu'un qui a déjà accès à son espace.
    if ( ! $account_already_existed ) {
        if ( amap_user_uses_magic_link( $user ) ) {
            amap_send_login_link( $user );
        } else {
            amap_send_password_reset_link( $user );
        }
    }

    wp_safe_redirect( add_query_arg( 'amap_notice', $account_already_existed ? 'reused' : 'created', $redirect_base ) );
    exit;
}

add_action( 'admin_post_amap_update_user', 'amap_handle_update_user' );

function amap_handle_update_user() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $user = $id ? amap_get_amap_user( $id ) : null;
    if ( ! $user ) {
        wp_die( esc_html__( 'Utilisateur introuvable.', 'association-manager' ) );
    }

    // Même garde-fou que amap_handle_delete_user(), suite au même incident réel : un compte
    // administrateur qui porte aussi une casquette AMAP ne doit pas pouvoir être modifié depuis
    // cette page (changer son email permettrait de prendre le contrôle du compte administrateur
    // via "mot de passe oublié" sur wp-login.php).
    if ( in_array( 'administrator', $user->roles, true ) ) {
        wp_die( esc_html__( 'Modification impossible : ce compte porte le rôle administrateur WordPress.', 'association-manager' ) );
    }

    // La chaîne d'action du nonce inclut l'ID : un nonce généré pour le formulaire de
    // l'utilisateur 5 est rejeté si le champ caché "id" a été modifié pour viser un autre ID.
    check_admin_referer( 'amap_edit_user_' . $id );

    // Le formulaire précise sa page de retour (liste) via ce champ cachée : présent, c'est le
    // formulaire de la section "Utilisateurs" de l'espace bureau front, absent c'est celui de
    // wp-admin — détermine aussi vers quelle URL revenir sur ce même utilisateur en cas d'erreur.
    // wp_safe_redirect() revalide de toute façon la destination contre les hôtes autorisés.
    $is_front_request = isset( $_POST['redirect_to'] );
    $redirect_list_url = $is_front_request ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-users' );
    $edit_url          = $is_front_request ? amap_get_board_user_edit_url( $id ) : admin_url( 'admin.php?page=amap-users&action=edit&id=' . $id );

    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
    $roles      = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['roles'] ) ) : array();
    $roles      = array_values( array_intersect( $roles, array_keys( amap_get_available_roles() ) ) );
    $group_id   = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $submitted  = compact( 'last_name', 'first_name', 'email', 'phone', 'address', 'roles', 'group_id' );

    if ( '' === $last_name || '' === $first_name || '' === $email || '' === $phone || empty( $roles )
        || ( $group_id && ! amap_get_group( $group_id ) ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $edit_url ) );
        exit;
    }

    if ( ! amap_is_valid_phone( $phone ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_phone', $edit_url ) );
        exit;
    }

    // Contrairement à l'ajout (qui réutilise un compte existant), ici l'email doit rester
    // celui de CE compte : s'il correspond à un AUTRE compte WordPress, c'est un conflit.
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

    // Contrairement à l'ajout (qui cumule sans jamais retirer de casquette), l'édition
    // applique exactement l'ensemble de rôles coché : une casquette décochée est retirée.
    foreach ( amap_get_available_roles() as $role_slug => $role_label ) {
        $has_role   = in_array( $role_slug, $user->roles, true );
        $wants_role = in_array( $role_slug, $roles, true );

        if ( $wants_role && ! $has_role ) {
            $user->add_role( $role_slug );
        } elseif ( ! $wants_role && $has_role ) {
            $user->remove_role( $role_slug );
        }
    }

    // Contrairement à l'ajout, l'édition applique ici aussi l'état exact de la casquette
    // adhérent : décocher "Adhérent" retire le groupe (amap_set_member_group( $id, 0 )), pour ne
    // pas laisser un rattachement orphelin sur un compte qui n'est plus adhérent.
    amap_set_member_group( $id, in_array( 'amap_member', $roles, true ) ? $group_id : 0 );

    if ( ! amap_save_user_contact( $id, $phone, $address ) ) {
        wp_safe_redirect( add_query_arg( 'amap_notice', 'contact_error', $redirect_list_url ) );
        exit;
    }

    wp_safe_redirect( $redirect_list_url );
    exit;
}

add_action( 'admin_post_amap_delete_user', 'amap_handle_delete_user' );

function amap_handle_delete_user() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $user = $id ? amap_get_amap_user( $id ) : null;
    if ( ! $user ) {
        wp_die( esc_html__( 'Utilisateur introuvable.', 'association-manager' ) );
    }

    // Garde-fou suite à un incident réel : un compte administrateur qui porte aussi une
    // casquette AMAP (ex. amap_board, pour tester cette page) apparaît dans cette liste comme
    // n'importe quel autre utilisateur AMAP. Le supprimer supprimerait le compte WordPress en
    // entier (amap_get_amap_user()/wp_delete_user() ci-dessous), pas seulement la casquette.
    if ( in_array( 'administrator', $user->roles, true ) ) {
        wp_die( esc_html__( 'Suppression impossible : ce compte porte le rôle administrateur WordPress.', 'association-manager' ) );
    }

    // Bloque plutôt que de supprimer en cascade : un producteur avec des contrats, ou un adhérent
    // avec des souscriptions, porte un historique (et, depuis le suivi de paiement, des montants
    // payés/impayés) qu'une suppression de compte effacerait ou laisserait orphelin.
    if ( in_array( 'amap_producer', $user->roles, true ) && amap_get_producer_contracts( $id ) ) {
        wp_die( esc_html__( 'Suppression impossible : ce producteur a des contrats enregistrés.', 'association-manager' ) );
    }

    if ( in_array( 'amap_member', $user->roles, true ) && amap_member_has_subscriptions( $id ) ) {
        wp_die( esc_html__( 'Suppression impossible : cet adhérent a des souscriptions enregistrées.', 'association-manager' ) );
    }

    // check_admin_referer() lit aussi bien $_GET que $_POST : ici le nonce arrive en query
    // string via wp_nonce_url(), pas dans un champ de formulaire.
    check_admin_referer( 'amap_delete_user_' . $id );

    // Même principe que amap_handle_add_user()/amap_handle_update_user() : la page de
    // confirmation (wp-admin ou espace bureau front) précise sa page de retour via ce paramètre,
    // présent dans l'URL puisque "Supprimer" est un lien, pas un formulaire posté.
    $redirect_url = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-users' );

    // Suppression complète du compte WordPress (identité + rôles), pas seulement des
    // casquettes AMAP : cette page est le point d'entrée unique de gestion des utilisateurs.
    // Réattribution de l'éventuel contenu (articles/pages) de ce compte à la personne qui
    // effectue la suppression : sans ce second paramètre, wp_delete_user() envoie ce contenu à
    // la corbeille par défaut — incident réel qui avait vidé la page d'accueil du site après la
    // suppression d'un compte qui en était l'auteur.
    require_once ABSPATH . 'wp-admin/includes/user.php';
    if ( ! wp_delete_user( $id, get_current_user_id() ) ) {
        wp_die( esc_html__( 'La suppression du compte WordPress a échoué.', 'association-manager' ) );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_users', array( 'user_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_group_members', array( 'member_user_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_magic_links', array( 'user_id' => $id ) );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'deleted', $redirect_url ) );
    exit;
}
