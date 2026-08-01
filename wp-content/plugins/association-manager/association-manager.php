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
    update_option( 'amap_db_version', '3.4' );
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

    $magic_links_table = $wpdb->prefix . 'amap_magic_links';

    // token_hash stocke le hachage (sha256) du jeton, jamais le jeton en clair : seul le lien
    // envoyé par email contient le jeton réel, la base ne permet donc pas à elle seule de se
    // connecter à la place d'un adhérent. used_at NULL = jeton encore valide ; renseigné au
    // moment du clic sur le lien de confirmation (pas au simple chargement de la page), ce qui
    // rend le jeton à usage unique tout en résistant aux scanners anti-spam qui préchargent les
    // liens des emails. purpose distingue un jeton de connexion ('login') d'un jeton donnant
    // accès au formulaire de réinitialisation de mot de passe ('password_reset') pour les
    // comptes producteur/bureau : même mécanique de sécurité, deux usages.
    $sql_magic_links = "CREATE TABLE $magic_links_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        token_hash char(64) NOT NULL,
        purpose varchar(20) NOT NULL DEFAULT 'login',
        expires_at datetime NOT NULL,
        used_at datetime DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY token_hash (token_hash),
        KEY user_id (user_id)
    ) $charset_collate;";

    dbDelta( $sql_magic_links );
}

function amap_drop_obsolete_tables() {
    global $wpdb;

    // wp_amap_producers est remplacée par wp_amap_users (données communes à toutes les
    // casquettes, plus rôle amap_producer cumulable). dbDelta() ne supprime jamais de table,
    // il faut le faire explicitement.
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'amap_producers' );

    // wp_amap_totp_secrets : la 2FA par TOTP a été abandonnée au profit d'un second facteur par
    // lien magique (comme pour les adhérents), voir amap_send_login_link().
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'amap_totp_secrets' );
}

/**
 * Envoie un email transactionnel via l'API Brevo. Utilise wp_remote_post() (la "HTTP API" de
 * WordPress) plutôt qu'un appel curl direct : WordPress choisit lui-même le transport
 * disponible sur l'hébergement, ce qui compte puisqu'on ne maîtrise pas l'environnement du
 * mutualisé visé en production.
 */
function amap_send_email( $to, $subject, $html_body ) {
    if ( '' === AMAP_BREVO_API_KEY ) {
        return new WP_Error( 'amap_email_not_configured', __( 'Clé API Brevo non configurée.', 'association-manager' ) );
    }

    $response = wp_remote_post(
        'https://api.brevo.com/v3/smtp/email',
        array(
            'headers' => array(
                'accept'       => 'application/json',
                'api-key'      => AMAP_BREVO_API_KEY,
                'content-type' => 'application/json',
            ),
            'body'    => wp_json_encode(
                array(
                    'sender'      => array(
                        'name'  => AMAP_EMAIL_FROM_NAME,
                        'email' => AMAP_EMAIL_FROM_ADDRESS,
                    ),
                    'to'          => array( array( 'email' => $to ) ),
                    'subject'     => $subject,
                    'htmlContent' => $html_body,
                )
            ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    if ( $status_code < 200 || $status_code >= 300 ) {
        return new WP_Error(
            'amap_email_send_failed',
            sprintf( 'Brevo a répondu avec le code %d : %s', $status_code, wp_remote_retrieve_body( $response ) )
        );
    }

    return true;
}

add_action( 'admin_post_amap_send_test_email', 'amap_handle_send_test_email' );

function amap_handle_send_test_email() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_send_test_email' );

    $admin  = wp_get_current_user();
    $result = amap_send_email(
        $admin->user_email,
        __( 'Email de test AMAP', 'association-manager' ),
        '<p>' . esc_html__( "Cet email confirme que l'envoi via Brevo fonctionne.", 'association-manager' ) . '</p>'
    );

    if ( is_wp_error( $result ) ) {
        set_transient( 'amap_test_email_error_' . get_current_user_id(), $result->get_error_message(), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=test_email_failed' ) );
        exit;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=test_email_sent' ) );
    exit;
}

/**
 * Un adhérent qui ne cumule aucune autre casquette se connecte par lien magique ; dès qu'il
 * porte aussi producteur ou bureau, il passe par mot de passe + 2FA (étapes suivantes).
 */
function amap_user_uses_magic_link( $user ) {
    return in_array( 'amap_member', $user->roles, true )
        && ! in_array( 'amap_producer', $user->roles, true )
        && ! in_array( 'amap_board', $user->roles, true );
}

function amap_get_magic_link_ttl_seconds() {
    return 15 * MINUTE_IN_SECONDS;
}

/**
 * Génère un jeton de lien magique et l'enregistre en base. Seul le hachage est stocké (voir
 * amap_create_tables()) ; le jeton en clair n'existe qu'ici et dans l'email envoyé à l'adhérent.
 */
function amap_create_magic_link_token( $user_id, $purpose = 'login' ) {
    global $wpdb;

    // wp_generate_password( ..., false, false ) : uniquement alphanumérique, donc utilisable
    // tel quel dans une URL sans encodage particulier.
    $token      = wp_generate_password( 32, false, false );
    $token_hash = hash( 'sha256', $token );

    $wpdb->insert(
        $wpdb->prefix . 'amap_magic_links',
        array(
            'user_id'    => $user_id,
            'token_hash' => $token_hash,
            'purpose'    => $purpose,
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + amap_get_magic_link_ttl_seconds() ),
        )
    );

    return $token;
}

/**
 * Retrouve la ligne wp_amap_magic_links correspondant à un jeton reçu par email, en recalculant
 * son hachage (jamais l'inverse : le hachage stocké en base ne permet pas de retrouver le jeton).
 */
function amap_get_magic_link_by_token( $token ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_magic_links WHERE token_hash = %s",
            hash( 'sha256', $token )
        )
    );
}

/**
 * URL de la page "Espace adhérent" (slug espace-adherent), point d'entrée unique du parcours de
 * connexion : chaque étape (email, mot de passe, messages, lien magique, nouveau mot de passe)
 * reste sur cette URL via des paramètres de requête, plutôt que de rebondir sur la page d'accueil
 * (home.php, qui affiche les actualités et n'a rien à voir avec la connexion). Retombe sur
 * l'accueil si la page n'a pas encore été créée dans l'admin.
 */
function amap_get_member_area_url() {
    $page = get_page_by_path( 'espace-adherent' );

    return $page ? get_permalink( $page ) : home_url( '/' );
}

/**
 * URL de la page de confirmation : paramètre sur la page "Espace adhérent", interceptée par
 * amap_maybe_render_magic_link_confirmation() via le hook template_redirect.
 */
function amap_get_magic_link_url( $token ) {
    return add_query_arg(
        array(
            'amap_action' => 'magic_link',
            'token'       => $token,
        ),
        amap_get_member_area_url()
    );
}

function amap_send_magic_link( $user ) {
    if ( ! amap_user_uses_magic_link( $user ) ) {
        return new WP_Error(
            'amap_magic_link_not_applicable',
            __( "Cet utilisateur cumule plusieurs casquettes ou n'est pas adhérent : le lien magique ne s'applique pas.", 'association-manager' )
        );
    }

    return amap_send_login_link( $user );
}

/**
 * Envoie l'email de connexion par lien magique, sans condition de casquette. Utilisée à la fois
 * pour l'auto-connexion des adhérents seuls (amap_send_magic_link() vérifie le rôle avant
 * d'appeler cette fonction) et comme second facteur après mot de passe pour producteur/bureau
 * (amap_handle_login_password_step()) : dans les deux cas, le clic sur le lien est ce qui ouvre
 * la session (amap_handle_confirm_magic_link()), jamais l'envoi de l'email lui-même.
 */
function amap_send_login_link( $user ) {
    $token = amap_create_magic_link_token( $user->ID );
    $link  = amap_get_magic_link_url( $token );

    $html_body = sprintf(
        '<p>%s</p><p><a href="%s">%s</a></p>',
        esc_html__( 'Cliquez sur le lien ci-dessous pour vous connecter à votre espace.', 'association-manager' ),
        esc_url( $link ),
        esc_html__( 'Cliquez ici pour vous connecter', 'association-manager' )
    );

    return amap_send_email( $user->user_email, __( 'Votre lien de connexion AMAP', 'association-manager' ), $html_body );
}

/**
 * Envoie l'email de réinitialisation de mot de passe pour un compte producteur/bureau. Jeton de
 * purpose 'password_reset' : amap_handle_confirm_magic_link() devra distinguer ce cas de la
 * connexion normale avant d'ouvrir une session (étape suivante, pas encore traitée).
 */
function amap_send_password_reset_link( $user ) {
    $token = amap_create_magic_link_token( $user->ID, 'password_reset' );
    $link  = amap_get_magic_link_url( $token );

    $html_body = sprintf(
        '<p>%s</p><p><a href="%s">%s</a></p>',
        esc_html__( 'Cliquez sur le lien ci-dessous pour choisir un nouveau mot de passe.', 'association-manager' ),
        esc_url( $link ),
        esc_html__( 'Choisir un nouveau mot de passe', 'association-manager' )
    );

    return amap_send_email( $user->user_email, __( 'Réinitialisation de votre mot de passe AMAP', 'association-manager' ), $html_body );
}

add_action( 'admin_post_amap_send_magic_link', 'amap_handle_send_magic_link' );

function amap_handle_send_magic_link() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $user = $id ? amap_get_amap_user( $id ) : null;
    if ( ! $user ) {
        wp_die( esc_html__( 'Utilisateur introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_send_magic_link_' . $id );

    $result = amap_send_magic_link( $user );

    if ( is_wp_error( $result ) ) {
        set_transient( 'amap_magic_link_error_' . get_current_user_id(), $result->get_error_message(), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=magic_link_failed' ) );
        exit;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=magic_link_sent' ) );
    exit;
}

add_action( 'template_redirect', 'amap_maybe_render_magic_link_confirmation' );

/**
 * Intercepte ?amap_action=magic_link&token=... avant l'affichage normal de la page d'accueil.
 * Vérifie le jeton mais NE le marque PAS comme utilisé ici : ce simple chargement de page peut
 * être déclenché automatiquement par un scanner anti-spam qui préchargerait les liens de l'email
 * ; seul le clic explicite sur le bouton (amap_handle_confirm_magic_link) invalide le jeton.
 */
function amap_maybe_render_magic_link_confirmation() {
    if ( ! is_page( 'espace-adherent' ) || ! isset( $_GET['amap_action'], $_GET['token'] ) || 'magic_link' !== sanitize_key( wp_unslash( $_GET['amap_action'] ) ) ) {
        return;
    }

    $token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
    $link  = amap_get_magic_link_by_token( $token );

    if ( ! $link || null !== $link->used_at || $link->expires_at < current_time( 'mysql', true ) ) {
        wp_die( esc_html__( 'Ce lien de connexion est invalide ou a expiré. Demandez-en un nouveau.', 'association-manager' ) );
    }

    $confirm_url = wp_nonce_url(
        admin_url( 'admin-post.php?action=amap_confirm_magic_link&token=' . $token ),
        'amap_confirm_magic_link_' . $token
    );

    $is_password_reset = ( 'password_reset' === $link->purpose );

    get_header();
    get_template_part(
        'template-parts/login/step',
        'magic-link-confirm',
        array(
            'confirm_url'       => $confirm_url,
            'is_password_reset' => $is_password_reset,
        )
    );
    get_footer();
    exit;
}

add_action( 'admin_post_nopriv_amap_confirm_magic_link', 'amap_handle_confirm_magic_link' );
add_action( 'admin_post_amap_confirm_magic_link', 'amap_handle_confirm_magic_link' );

/**
 * Clic explicite sur le bouton de la page de confirmation. Pour un lien de connexion (purpose
 * 'login'), c'est ici, et seulement ici, que le jeton est invalidé (used_at) et que la session
 * WordPress s'ouvre. Pour un lien de réinitialisation (purpose 'password_reset'), le jeton reste
 * volontairement non consommé : on redirige vers le futur formulaire de nouveau mot de passe
 * (étape suivante), qui le revérifiera et l'invalidera lui-même au moment de l'enregistrement.
 */
function amap_handle_confirm_magic_link() {
    $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

    check_admin_referer( 'amap_confirm_magic_link_' . $token );

    $link = amap_get_magic_link_by_token( $token );
    $now  = current_time( 'mysql', true );

    if ( ! $link || null !== $link->used_at || $link->expires_at < $now ) {
        wp_die( esc_html__( 'Ce lien de connexion est invalide ou a expiré. Demandez-en un nouveau.', 'association-manager' ) );
    }

    if ( 'password_reset' === $link->purpose ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'amap_login_step' => 'new_password',
                    'token'            => $token,
                ),
                amap_get_member_area_url()
            )
        );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_magic_links',
        array( 'used_at' => $now ),
        array( 'id' => $link->id )
    );

    wp_set_current_user( $link->user_id );
    wp_set_auth_cookie( $link->user_id );

    wp_safe_redirect( home_url( '/' ) );
    exit;
}

add_action( 'template_redirect', 'amap_maybe_render_new_password_form' );

/**
 * Intercepte ?amap_login_step=new_password&token=..., destination du clic confirmé sur un lien
 * de réinitialisation (amap_handle_confirm_magic_link()). Revérifie le jeton (il n'a pas encore
 * été consommé à ce stade) et affiche un formulaire de nouveau mot de passe ; c'est
 * amap_handle_set_new_password() qui invalidera le jeton, au moment de l'enregistrement.
 */
function amap_maybe_render_new_password_form() {
    if ( ! is_page( 'espace-adherent' ) || ! isset( $_GET['amap_login_step'], $_GET['token'] ) || 'new_password' !== sanitize_key( wp_unslash( $_GET['amap_login_step'] ) ) ) {
        return;
    }

    $token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
    $link  = amap_get_magic_link_by_token( $token );

    if ( ! $link || 'password_reset' !== $link->purpose || null !== $link->used_at || $link->expires_at < current_time( 'mysql', true ) ) {
        wp_die( esc_html__( 'Ce lien de réinitialisation est invalide ou a expiré. Demandez-en un nouveau.', 'association-manager' ) );
    }

    $has_error = isset( $_GET['amap_login_error'] );

    get_header();
    get_template_part(
        'template-parts/login/step',
        'new-password',
        array(
            'token'     => $token,
            'has_error' => $has_error,
        )
    );
    get_footer();
    exit;
}

add_action( 'admin_post_nopriv_amap_set_new_password', 'amap_handle_set_new_password' );
add_action( 'admin_post_amap_set_new_password', 'amap_handle_set_new_password' );

/**
 * Enregistre le nouveau mot de passe : revérifie le jeton (même contrôle que
 * amap_maybe_render_new_password_form()), marque used_at puis appelle wp_set_password(), qui
 * invalide de lui-même toutes les sessions ouvertes de l'utilisateur.
 */
function amap_handle_set_new_password() {
    $token            = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
    $password         = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
    $password_confirm = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';

    check_admin_referer( 'amap_set_new_password_' . $token );

    $link = amap_get_magic_link_by_token( $token );
    $now  = current_time( 'mysql', true );

    if ( ! $link || 'password_reset' !== $link->purpose || null !== $link->used_at || $link->expires_at < $now ) {
        wp_die( esc_html__( 'Ce lien de réinitialisation est invalide ou a expiré. Demandez-en un nouveau.', 'association-manager' ) );
    }

    if ( strlen( $password ) < 8 || $password !== $password_confirm ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'amap_login_step'  => 'new_password',
                    'token'            => $token,
                    'amap_login_error' => 1,
                ),
                amap_get_member_area_url()
            )
        );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_magic_links',
        array( 'used_at' => $now ),
        array( 'id' => $link->id )
    );

    wp_set_password( $password, $link->user_id );

    wp_safe_redirect( add_query_arg( 'amap_login_step', 'password_reset_done', amap_get_member_area_url() ) );
    exit;
}

/**
 * Détermine, à partir d'un email saisi sur la page de connexion, quel parcours proposer.
 * Ne distingue pas "compte inconnu" de "compte producteur/bureau" : dans les deux cas on
 * retombe sur le mot de passe (qui échouera pour un compte inconnu), pour ne pas révéler par ce
 * seul indice qu'un email donné correspond ou non à un adhérent enregistré.
 */
function amap_get_login_mode_for_email( $email ) {
    $user = get_user_by( 'email', sanitize_email( $email ) );

    if ( $user && amap_user_uses_magic_link( $user ) ) {
        return 'magic_link';
    }

    return 'password';
}

add_action( 'template_redirect', 'amap_maybe_render_login_email_step' );

/**
 * Premier écran du parcours de connexion : la page "espace-adherent" sans paramètre, ou avec
 * amap_login_step=invalid_email pour réafficher le formulaire après une saisie invalide. Exclut
 * explicitement amap_action (confirmation de lien magique) plutôt que de compter sur l'ordre des
 * hooks template_redirect pour éviter tout conflit d'affichage.
 */
function amap_maybe_render_login_email_step() {
    if ( ! is_page( 'espace-adherent' ) || isset( $_GET['amap_action'] ) ) {
        return;
    }

    $step = isset( $_GET['amap_login_step'] ) ? sanitize_key( wp_unslash( $_GET['amap_login_step'] ) ) : '';

    if ( '' !== $step && 'invalid_email' !== $step ) {
        return;
    }

    get_header();
    get_template_part( 'template-parts/login/step', 'email', array( 'has_error' => ( 'invalid_email' === $step ) ) );
    get_footer();
    exit;
}

add_action( 'admin_post_nopriv_amap_login_email_step', 'amap_handle_login_email_step' );
add_action( 'admin_post_amap_login_email_step', 'amap_handle_login_email_step' );

/**
 * Première étape de la page de connexion (étape 8, pas encore construite) : reçoit l'email saisi
 * et aiguille selon amap_get_login_mode_for_email(). Pas de nonce ici, volontairement : c'est une
 * action publique par nature (comme "mot de passe oublié" sur n'importe quel site), accessible à
 * quiconque connaît une adresse email sans avoir besoin d'être passé par la page au préalable ; un
 * nonce anonyme n'apporterait pas de protection réelle (même valeur pour tout visiteur non connecté).
 */
function amap_handle_login_email_step() {
    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

    if ( '' === $email || ! is_email( $email ) ) {
        wp_safe_redirect( add_query_arg( 'amap_login_step', 'invalid_email', amap_get_member_area_url() ) );
        exit;
    }

    if ( 'magic_link' === amap_get_login_mode_for_email( $email ) ) {
        $user = get_user_by( 'email', $email );
        amap_send_magic_link( $user );
        wp_safe_redirect( add_query_arg( 'amap_login_step', 'magic_link_sent', amap_get_member_area_url() ) );
        exit;
    }

    wp_safe_redirect(
        add_query_arg(
            array(
                'amap_login_step' => 'password',
                'email'            => $email,
            ),
            amap_get_member_area_url()
        )
    );
    exit;
}

add_action( 'template_redirect', 'amap_maybe_render_login_password_step' );

/**
 * Deuxième écran du parcours de connexion : compte producteur/bureau, atteint après un email qui
 * nécessite un mot de passe (amap_get_login_mode_for_email()). Reprend l'email de l'URL pour ne
 * pas le faire ressaisir en cas de nouvelle erreur (amap_login_error).
 */
function amap_maybe_render_login_password_step() {
    if ( ! is_page( 'espace-adherent' ) || ! isset( $_GET['amap_login_step'] ) || 'password' !== sanitize_key( wp_unslash( $_GET['amap_login_step'] ) ) ) {
        return;
    }

    $email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';

    get_header();
    get_template_part(
        'template-parts/login/step',
        'password',
        array(
            'email'     => $email,
            'has_error' => isset( $_GET['amap_login_error'] ),
        )
    );
    get_footer();
    exit;
}

add_action( 'template_redirect', 'amap_maybe_render_login_message_step' );

/**
 * Écrans "message" du parcours de connexion : simple accusé de réception (lien magique envoyé,
 * demande de réinitialisation envoyée, mot de passe mis à jour), sans formulaire. Un seul
 * template-part partagé (step-message.php) pour les trois, le texte affiché dépendant de
 * amap_login_step.
 */
function amap_maybe_render_login_message_step() {
    if ( ! is_page( 'espace-adherent' ) || ! isset( $_GET['amap_login_step'] ) ) {
        return;
    }

    $step = sanitize_key( wp_unslash( $_GET['amap_login_step'] ) );

    $messages = array(
        'magic_link_sent'     => __( 'Un lien de connexion vous a été envoyé par email.', 'association-manager' ),
        'password_reset_sent' => __( 'Si un compte existe pour cette adresse, un email de réinitialisation vous a été envoyé.', 'association-manager' ),
        'password_reset_done' => __( 'Votre mot de passe a été mis à jour.', 'association-manager' ),
    );

    if ( ! isset( $messages[ $step ] ) ) {
        return;
    }

    get_header();
    get_template_part(
        'template-parts/login/step',
        'message',
        array(
            'message'          => $messages[ $step ],
            'show_login_link'  => ( 'password_reset_done' === $step ),
        )
    );
    get_footer();
    exit;
}

add_action( 'admin_post_nopriv_amap_login_password_step', 'amap_handle_login_password_step' );
add_action( 'admin_post_amap_login_password_step', 'amap_handle_login_password_step' );

/**
 * Deuxième étape pour un compte producteur/bureau : email + mot de passe. wp_authenticate()
 * (et non wp_signon()) vérifie les identifiants SANS ouvrir de session : le mot de passe n'est
 * que le premier facteur, la session ne s'ouvre qu'après le clic sur le lien de connexion envoyé
 * par email (second facteur), via amap_handle_confirm_magic_link() — même mécanique que
 * l'auto-connexion des adhérents, sans la restriction de casquette d'amap_send_magic_link().
 */
function amap_handle_login_password_step() {
    $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

    $user = wp_authenticate( $email, $password );

    if ( is_wp_error( $user ) ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'amap_login_step'  => 'password',
                    'email'            => $email,
                    'amap_login_error' => 1,
                ),
                amap_get_member_area_url()
            )
        );
        exit;
    }

    amap_send_login_link( $user );

    wp_safe_redirect( add_query_arg( 'amap_login_step', 'magic_link_sent', amap_get_member_area_url() ) );
    exit;
}

add_action( 'admin_post_nopriv_amap_request_password_reset', 'amap_handle_request_password_reset' );
add_action( 'admin_post_amap_request_password_reset', 'amap_handle_request_password_reset' );

/**
 * Demande de "mot de passe oublié" pour un compte producteur/bureau. Comme
 * amap_get_login_mode_for_email(), ne distingue jamais dans la redirection un email inconnu d'un
 * compte adhérent seul (sans mot de passe) : dans les deux cas aucun email n'est envoyé, sans que
 * cela se voie côté visiteur.
 */
function amap_handle_request_password_reset() {
    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

    if ( '' !== $email && is_email( $email ) ) {
        $user = get_user_by( 'email', $email );

        if ( $user && ! amap_user_uses_magic_link( $user ) ) {
            amap_send_password_reset_link( $user );
        }
    }

    wp_safe_redirect( add_query_arg( 'amap_login_step', 'password_reset_sent', amap_get_member_area_url() ) );
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

    get_header();
    get_template_part( 'template-parts/login/member-area' );
    get_footer();
    exit;
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

    // Détail de l'erreur d'envoi, posé par amap_handle_send_test_email() juste avant la
    // redirection qui a mené à cette page (même mécanisme que $form_data plus bas).
    $test_email_error_key = 'amap_test_email_error_' . get_current_user_id();
    $test_email_error     = get_transient( $test_email_error_key );
    if ( false !== $test_email_error ) {
        delete_transient( $test_email_error_key );
    }

    $magic_link_error_key = 'amap_magic_link_error_' . get_current_user_id();
    $magic_link_error     = get_transient( $magic_link_error_key );
    if ( false !== $magic_link_error ) {
        delete_transient( $magic_link_error_key );
    }

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
        <?php elseif ( 'test_email_sent' === $notice ) : ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Email de test envoyé.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'test_email_failed' === $notice ) : ?>
            <div class="notice notice-error">
                <p>
                    <?php esc_html_e( "Échec de l'envoi de l'email de test.", 'association-manager' ); ?>
                    <?php if ( $test_email_error ) : ?>
                        <?php echo esc_html( $test_email_error ); ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php elseif ( 'magic_link_sent' === $notice ) : ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Lien de connexion envoyé.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'magic_link_failed' === $notice ) : ?>
            <div class="notice notice-error">
                <p>
                    <?php esc_html_e( "Échec de l'envoi du lien de connexion.", 'association-manager' ); ?>
                    <?php if ( $magic_link_error ) : ?>
                        <?php echo esc_html( $magic_link_error ); ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'amap_send_test_email' ); ?>
                <input type="hidden" name="action" value="amap_send_test_email">
                <?php submit_button( __( 'Envoyer un email de test', 'association-manager' ), 'secondary', 'submit', false ); ?>
            </form>
        </p>

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
                                <?php if ( amap_user_uses_magic_link( $user ) ) : ?>
                                    |
                                    <?php
                                    $magic_link_action_url = wp_nonce_url(
                                        admin_url( 'admin-post.php?action=amap_send_magic_link&id=' . $user->ID ),
                                        'amap_send_magic_link_' . $user->ID
                                    );
                                    ?>
                                    <a href="<?php echo esc_url( $magic_link_action_url ); ?>">
                                        <?php esc_html_e( 'Envoyer un lien de connexion', 'association-manager' ); ?>
                                    </a>
                                <?php endif; ?>
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
