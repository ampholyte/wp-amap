<?php
/**
 * Plugin Name: Association Manager
 * Description: Logique métier de l'AMAP (adhérents, groupes, producteurs, contrats, distributions).
 * Version: 0.1.0
 * Author: Association AMAP
 * Text Domain: association-manager
 */

// Empêche l'accès direct au fichier en dehors du contexte WordPress.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

register_activation_hook( __FILE__, 'amap_activate' );

function amap_activate() {
    // update_option() (et non plus add_option()) : la version doit refléter le schéma du
    // code à chaque activation. dbDelta() est idempotent, le rappeler ne pose pas de problème.
    update_option( 'amap_db_version', '3.0' );
    amap_create_tables();
    amap_drop_obsolete_tables();

    // add_role() ne fait rien si le rôle existe déjà : sûr à rappeler à chaque activation,
    // comme dbDelta() pour les tables. Les trois casquettes sont cumulables nativement par
    // WordPress (un utilisateur peut porter plusieurs rôles à la fois).
    add_role( 'amap_member', __( 'Adhérent', 'association-manager' ), array() );
    add_role( 'amap_producer', __( 'Producteur', 'association-manager' ), array() );
    add_role( 'amap_board', __( 'Bureau', 'association-manager' ), array() );

    // add_cap() est également idempotent : le rappeler à chaque activation ne duplique rien.
    $administrator = get_role( 'administrator' );
    if ( $administrator ) {
        $administrator->add_cap( 'amap_manage_users' );
        $administrator->remove_cap( 'amap_manage_producers' );
    }
}

function amap_create_tables() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'amap_users';
    $charset_collate = $wpdb->get_charset_collate();

    // Pas de nom/prénom/email ici : ce sont des doublons de wp_users (usermeta
    // first_name/last_name et colonne native user_email). user_id porte l'identité unique ;
    // phone/address sont des données structurées communes à tout utilisateur AMAP, quelle que
    // soit sa casquette, donc en table dédiée plutôt qu'en usermeta.
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        phone varchar(30) NOT NULL,
        address varchar(255) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY user_id (user_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

function amap_drop_obsolete_tables() {
    global $wpdb;

    // wp_amap_producers est remplacée par wp_amap_users (données communes à toutes les
    // casquettes, plus rôle amap_producer cumulable). dbDelta() ne supprime jamais de table,
    // il faut le faire explicitement.
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'amap_producers' );
}

add_action( 'admin_menu', 'amap_register_admin_menu' );

function amap_register_admin_menu() {
    add_menu_page(
        __( 'AMAP', 'association-manager' ),
        __( 'AMAP', 'association-manager' ),
        'amap_manage_users',
        'amap-users',
        'amap_render_users_page',
        'dashicons-groups',
        26
    );
}

/**
 * Un "utilisateur AMAP" est un compte WordPress portant au moins une des trois casquettes.
 * Les comptes WP sans aucune de ces casquettes (ex. un simple abonné) n'apparaissent pas ici.
 */
function amap_get_amap_users() {
    $user_query = new WP_User_Query(
        array(
            'role__in' => array( 'amap_member', 'amap_producer', 'amap_board' ),
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        )
    );

    return $user_query->get_results();
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

function amap_render_users_page() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        return;
    }

    $notice = isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '';

    // Mode édition : ?action=edit&id=X sur cette même page. Si l'ID ne correspond à aucun
    // utilisateur AMAP, on retombe silencieusement sur le formulaire d'ajout.
    $editing_id = 0;
    if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ) {
        $editing_id = absint( $_GET['id'] );
    }
    $editing_user = $editing_id ? amap_get_amap_user( $editing_id ) : null;
    if ( $editing_id && ! $editing_user ) {
        $editing_id = 0;
    }

    // Récupère les valeurs saisies avant la redirection en cas d'erreur (voir
    // amap_store_user_form_data()), pour ne pas faire ressaisir tout le formulaire.
    $transient_key = 'amap_user_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_user ) {
        // Pas d'erreur en attente : on préremplit avec les valeurs actuelles de l'utilisateur.
        $contact   = amap_get_user_contact( $editing_user->ID );
        $form_data = array(
            'last_name'  => $editing_user->last_name,
            'first_name' => $editing_user->first_name,
            'email'      => $editing_user->user_email,
            'phone'      => $contact->phone ?? '',
            'address'    => $contact->address ?? '',
            'roles'      => array_intersect( $editing_user->roles, array_keys( amap_get_available_roles() ) ),
        );
    } else {
        $form_data = array();
    }
    $selected_roles = $form_data['roles'] ?? array();

    $users = amap_get_amap_users();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Utilisateurs AMAP', 'association-manager' ); ?></h1>

        <?php if ( 'reused' === $notice ) : ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Compte WordPress existant réutilisé : rôle(s) et coordonnées mis à jour.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Champs obligatoires manquants ou aucun rôle sélectionné.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_phone' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Le téléphone doit être au format 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'account_error' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Impossible de créer le compte WordPress associé à cet email.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'contact_error' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( "Le compte a été créé ou mis à jour mais l'enregistrement du téléphone/adresse a échoué.", 'association-manager' ); ?></p></div>
        <?php elseif ( 'email_taken' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Cet email est déjà utilisé par un autre compte WordPress.', 'association-manager' ); ?></p></div>
        <?php endif; ?>

        <h2>
            <?php echo $editing_id
                ? esc_html__( 'Modifier un utilisateur', 'association-manager' )
                : esc_html__( 'Ajouter un utilisateur', 'association-manager' ); ?>
        </h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-user-form">
            <?php if ( $editing_id ) : ?>
                <?php wp_nonce_field( 'amap_edit_user_' . $editing_id ); ?>
                <input type="hidden" name="action" value="amap_update_user">
                <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
            <?php else : ?>
                <?php wp_nonce_field( 'amap_add_user' ); ?>
                <input type="hidden" name="action" value="amap_add_user">
            <?php endif; ?>
            <p>
                <label>
                    <?php esc_html_e( 'Nom', 'association-manager' ); ?>
                    <input type="text" name="last_name" value="<?php echo esc_attr( $form_data['last_name'] ?? '' ); ?>" required>
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e( 'Prénom', 'association-manager' ); ?>
                    <input type="text" name="first_name" value="<?php echo esc_attr( $form_data['first_name'] ?? '' ); ?>" required>
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e( 'Email', 'association-manager' ); ?>
                    <input type="email" name="email" value="<?php echo esc_attr( $form_data['email'] ?? '' ); ?>" required>
                </label><br>
                <?php if ( ! $editing_id ) : ?>
                    <span class="description">
                        <?php esc_html_e( "Si un compte WordPress existe déjà avec cet email, il est réutilisé (identité inchangée) et les rôles cochés ci-dessous lui sont simplement ajoutés — utile pour faire cumuler une nouvelle casquette à un utilisateur existant.", 'association-manager' ); ?>
                    </span>
                <?php endif; ?>
            </p>
            <p>
                <label>
                    <?php esc_html_e( 'Téléphone', 'association-manager' ); ?>
                    <input type="text" inputmode="tel" name="phone" id="amap-user-phone" value="<?php echo esc_attr( $form_data['phone'] ?? '' ); ?>" pattern="(0[1-9]|\+33[1-9])([\s.-]?\d{2}){4}" placeholder="0X XX XX XX XX" required>
                    <span id="amap-user-phone-error" style="color:#d63638;" hidden><?php esc_html_e( 'Format attendu : 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></span>
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e( 'Adresse', 'association-manager' ); ?>
                    <input type="text" name="address" value="<?php echo esc_attr( $form_data['address'] ?? '' ); ?>">
                </label>
            </p>
            <p>
                <strong><?php esc_html_e( 'Rôles', 'association-manager' ); ?></strong><br>
                <?php foreach ( amap_get_available_roles() as $role_slug => $role_label ) : ?>
                    <label>
                        <input type="checkbox" name="roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $selected_roles, true ) ); ?>>
                        <?php echo esc_html( $role_label ); ?>
                    </label><br>
                <?php endforeach; ?>
            </p>
            <p>
                <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                <?php if ( $editing_id ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-users' ) ); ?>" class="button">
                        <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        </form>
        <script>
        ( function () {
            var form        = document.getElementById( 'amap-user-form' );
            var phoneField  = document.getElementById( 'amap-user-phone' );
            var phoneError  = document.getElementById( 'amap-user-phone-error' );
            // Même règle que la validation serveur (amap_is_valid_phone) : on ne se fie pas
            // uniquement à l'attribut HTML "pattern", dont le comportement natif s'est révélé
            // peu fiable selon les navigateurs.
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

        <?php if ( empty( $users ) ) : ?>
            <p><?php esc_html_e( 'Aucun utilisateur AMAP enregistré pour le moment.', 'association-manager' ); ?></p>
        <?php else : ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Nom', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Prénom', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Téléphone', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Adresse', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Rôles', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $users as $user ) : ?>
                        <?php $contact = amap_get_user_contact( $user->ID ); ?>
                        <tr>
                            <td><?php echo esc_html( $user->last_name ); ?></td>
                            <td><?php echo esc_html( $user->first_name ); ?></td>
                            <td><?php echo esc_html( $user->user_email ); ?></td>
                            <td><?php echo esc_html( $contact->phone ?? '' ); ?></td>
                            <td><?php echo esc_html( $contact->address ?? '' ); ?></td>
                            <td><?php echo esc_html( amap_format_user_roles( $user->roles ) ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-users&action=edit&id=' . $user->ID ) ); ?>">
                                    <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                </a>
                                |
                                <?php
                                $delete_url = wp_nonce_url(
                                    admin_url( 'admin-post.php?action=amap_delete_user&id=' . $user->ID ),
                                    'amap_delete_user_' . $user->ID
                                );
                                // translators: 1: prénom de l'utilisateur, 2: nom de l'utilisateur.
                                $confirm_message = sprintf( __( 'Supprimer définitivement le compte WordPress de %1$s %2$s ?', 'association-manager' ), $user->first_name, $user->last_name );
                                ?>
                                <a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm( '<?php echo esc_js( $confirm_message ); ?>' );">
                                    <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
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

    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
    $roles      = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['roles'] ) ) : array();
    $roles      = array_values( array_intersect( $roles, array_keys( amap_get_available_roles() ) ) );
    $submitted  = compact( 'last_name', 'first_name', 'email', 'phone', 'address', 'roles' );

    if ( '' === $last_name || '' === $first_name || '' === $email || '' === $phone || empty( $roles ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=invalid' ) );
        exit;
    }

    if ( ! amap_is_valid_phone( $phone ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=invalid_phone' ) );
        exit;
    }

    // Vérifié avant amap_find_or_create_user() : une fois celle-ci appelée, le compte existe
    // forcément (créé ou préexistant), on ne pourrait plus distinguer les deux cas.
    $account_already_existed = (bool) get_user_by( 'email', $email );

    $user = amap_find_or_create_user( $first_name, $last_name, $email );
    if ( is_wp_error( $user ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=account_error' ) );
        exit;
    }

    // Cumul des casquettes : add_role() ajoute le rôle sans retirer les rôles déjà présents.
    // Soumettre à nouveau ce formulaire avec le même email permet donc d'ajouter une nouvelle
    // casquette (ex. producteur) à un compte déjà adhérent, sans dupliquer l'identité.
    foreach ( $roles as $role ) {
        $user->add_role( $role );
    }

    if ( ! amap_save_user_contact( $user->ID, $phone, $address ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=contact_error' ) );
        exit;
    }

    $redirect_notice = $account_already_existed ? '&amap_notice=reused' : '';
    wp_safe_redirect( admin_url( 'admin.php?page=amap-users' . $redirect_notice ) );
    exit;
}

add_action( 'admin_post_amap_update_user', 'amap_handle_update_user' );

function amap_handle_update_user() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    if ( ! $id || ! amap_get_amap_user( $id ) ) {
        wp_die( esc_html__( 'Utilisateur introuvable.', 'association-manager' ) );
    }

    // La chaîne d'action du nonce inclut l'ID : un nonce généré pour le formulaire de
    // l'utilisateur 5 est rejeté si le champ caché "id" a été modifié pour viser un autre ID.
    check_admin_referer( 'amap_edit_user_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-users&action=edit&id=' . $id );

    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
    $roles      = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['roles'] ) ) : array();
    $roles      = array_values( array_intersect( $roles, array_keys( amap_get_available_roles() ) ) );
    $submitted  = compact( 'last_name', 'first_name', 'email', 'phone', 'address', 'roles' );

    if ( '' === $last_name || '' === $first_name || '' === $email || '' === $phone || empty( $roles ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid' );
        exit;
    }

    if ( ! amap_is_valid_phone( $phone ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid_phone' );
        exit;
    }

    // Contrairement à l'ajout (qui réutilise un compte existant), ici l'email doit rester
    // celui de CE compte : s'il correspond à un AUTRE compte WordPress, c'est un conflit.
    $email_owner = get_user_by( 'email', $email );
    if ( $email_owner && $email_owner->ID !== $id ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=email_taken' );
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
        wp_safe_redirect( $edit_url . '&amap_notice=account_error' );
        exit;
    }

    // Contrairement à l'ajout (qui cumule sans jamais retirer de casquette), l'édition
    // applique exactement l'ensemble de rôles coché : une casquette décochée est retirée.
    $user = get_user_by( 'id', $id );
    foreach ( amap_get_available_roles() as $role_slug => $role_label ) {
        $has_role   = in_array( $role_slug, $user->roles, true );
        $wants_role = in_array( $role_slug, $roles, true );

        if ( $wants_role && ! $has_role ) {
            $user->add_role( $role_slug );
        } elseif ( ! $wants_role && $has_role ) {
            $user->remove_role( $role_slug );
        }
    }

    if ( ! amap_save_user_contact( $id, $phone, $address ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=contact_error' ) );
        exit;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-users' ) );
    exit;
}

add_action( 'admin_post_amap_delete_user', 'amap_handle_delete_user' );

function amap_handle_delete_user() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    if ( ! $id || ! amap_get_amap_user( $id ) ) {
        wp_die( esc_html__( 'Utilisateur introuvable.', 'association-manager' ) );
    }

    // check_admin_referer() lit aussi bien $_GET que $_POST : ici le nonce arrive en query
    // string via wp_nonce_url(), pas dans un champ de formulaire.
    check_admin_referer( 'amap_delete_user_' . $id );

    // Suppression complète du compte WordPress (identité + rôles), pas seulement des
    // casquettes AMAP : cette page est le point d'entrée unique de gestion des utilisateurs.
    require_once ABSPATH . 'wp-admin/includes/user.php';
    if ( ! wp_delete_user( $id ) ) {
        wp_die( esc_html__( 'La suppression du compte WordPress a échoué.', 'association-manager' ) );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_users', array( 'user_id' => $id ) );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-users' ) );
    exit;
}
