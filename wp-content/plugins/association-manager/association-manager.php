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
    update_option( 'amap_db_version', '3.11' );
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
        $administrator->add_cap( 'amap_manage_groups' );
        $administrator->add_cap( 'amap_manage_contracts' );
        $administrator->remove_cap( 'amap_manage_producers' );
    }

    // Un membre du bureau doit pouvoir gérer les utilisateurs AMAP au même titre qu'un
    // administrateur (page d'admin "Utilisateurs AMAP" existante, amap_render_users_page()).
    // amap_manage_groups et amap_manage_contracts sont des capabilities distinctes (pages
    // "Groupes" et "Contrats" séparées) : le rattachement producteur↔groupe, les contrats et la
    // gestion des distributions sont décidés par le bureau, mais restent conceptuellement
    // différents de la gestion des comptes.
    $board = get_role( 'amap_board' );
    if ( $board ) {
        $board->add_cap( 'amap_manage_users' );
        $board->add_cap( 'amap_manage_groups' );
        $board->add_cap( 'amap_manage_contracts' );
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

    $groups_table = $wpdb->prefix . 'amap_groups';

    // weekday : 0=lundi ... 6=dimanche (voir amap_get_weekday_labels()), jour fixe de la
    // distribution hebdomadaire du groupe. start_time/end_time : plage horaire fixe de cette
    // même distribution (ex. les adhérents doivent être présents 15 min avant/après, mais ce
    // délai est une règle appliquée à l'usage, pas stockée ici).
    $sql_groups = "CREATE TABLE $groups_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(120) NOT NULL,
        delivery_place varchar(255) NOT NULL,
        weekday tinyint(1) unsigned NOT NULL,
        start_time time NOT NULL,
        end_time time NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    dbDelta( $sql_groups );

    $group_producers_table = $wpdb->prefix . 'amap_group_producers';

    // Rattachement producteur↔groupe décidé par le bureau : un groupe n'a pas accès à tous
    // les producteurs automatiquement, un producteur peut être rattaché à plusieurs groupes.
    // UNIQUE(group_id, producer_user_id) empêche un doublon de rattachement.
    $sql_group_producers = "CREATE TABLE $group_producers_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        group_id bigint(20) unsigned NOT NULL,
        producer_user_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY group_producer (group_id, producer_user_id)
    ) $charset_collate;";

    dbDelta( $sql_group_producers );

    $contracts_table = $wpdb->prefix . 'amap_contracts';

    // Table mère des contrats, discriminée par contract_type : 'basket_recurring' (maraîcher,
    // panier à fréquence fixe) ou 'product_grid' (laitière/boulangers, grille produit×date
    // remplie une fois à la signature — tables filles prévues aux étapes 4b/4c). frequency_weeks
    // n'a de sens que pour basket_recurring (1 = hebdo, 2 = toutes les 2 semaines) ; NULL sinon,
    // contrôlé côté PHP comme les autres discriminants du plugin.
    $sql_contracts = "CREATE TABLE $contracts_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        producer_user_id bigint(20) unsigned NOT NULL,
        contract_type varchar(20) NOT NULL,
        label varchar(120) NOT NULL,
        start_date date NOT NULL,
        end_date date NOT NULL,
        frequency_weeks tinyint(2) unsigned DEFAULT NULL,
        is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    dbDelta( $sql_contracts );

    $contract_basket_sizes_table = $wpdb->prefix . 'amap_contract_basket_sizes';

    // Table fille des tailles+prix, uniquement pour un contrat basket_recurring (ex. petit/
    // moyen/grand pour le maraîcher, prix fixe par taille). Un contrat product_grid n'a aucune
    // ligne ici. Pas de contrainte FOREIGN KEY SQL sur contract_id, comme le reste du plugin.
    $sql_contract_basket_sizes = "CREATE TABLE $contract_basket_sizes_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        contract_id bigint(20) unsigned NOT NULL,
        label varchar(60) NOT NULL,
        price decimal(6,2) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY contract_id (contract_id)
    ) $charset_collate;";

    dbDelta( $sql_contract_basket_sizes );

    $contract_products_table = $wpdb->prefix . 'amap_contract_products';

    // Table fille du catalogue produits, uniquement pour un contrat product_grid (ex. yaourt,
    // lait, fromage blanc pour la productrice laitière). Un contrat basket_recurring n'a aucune
    // ligne ici. Même structure que wp_amap_contract_basket_sizes (label+prix) : pas de
    // contrainte FOREIGN KEY SQL sur contract_id, comme le reste du plugin.
    $sql_contract_products = "CREATE TABLE $contract_products_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        contract_id bigint(20) unsigned NOT NULL,
        label varchar(60) NOT NULL,
        price decimal(6,2) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY contract_id (contract_id)
    ) $charset_collate;";

    dbDelta( $sql_contract_products );

    $contract_delivery_dates_table = $wpdb->prefix . 'amap_contract_delivery_dates';

    // Table fille des dates de livraison du trimestre, uniquement pour un contrat product_grid.
    // group_id : un producteur peut livrer plusieurs groupes de distribution
    // (wp_amap_group_producers), chacun avec son propre jour fixe (wp_amap_groups.weekday) — les
    // dates de livraison d'un même contrat diffèrent donc selon le groupe de l'adhérent.
    // UNIQUE(contract_id, group_id, delivery_date) : deux groupes différents peuvent tomber sur
    // le même jour calendaire si leurs weekday coïncident, ce n'est donc plus un doublon dans ce
    // cas. Revérifié côté PHP avant insert/update pour afficher un message clair (voir
    // amap_contract_has_delivery_date()), la contrainte SQL restant le garde-fou final.
    $sql_contract_delivery_dates = "CREATE TABLE $contract_delivery_dates_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        contract_id bigint(20) unsigned NOT NULL,
        group_id bigint(20) unsigned NOT NULL,
        delivery_date date NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY contract_group_delivery_date (contract_id, group_id, delivery_date)
    ) $charset_collate;";

    dbDelta( $sql_contract_delivery_dates );
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
/**
 * URL du formulaire self-service de modification des informations d'un adhérent (nom, prénom,
 * email, téléphone, adresse), sur la page "Espace adhérent".
 */
function amap_get_member_profile_edit_url() {
    return add_query_arg( 'amap_member_action', 'edit_profile', amap_get_member_area_url() );
}

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
    ?>
    <main>
    <?php
    get_template_part(
        'template-parts/login/step',
        'magic-link-confirm',
        array(
            'confirm_url'       => $confirm_url,
            'is_password_reset' => $is_password_reset,
        )
    );
    ?>
    </main>
    <?php
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
    ?>
    <main>
    <?php
    get_template_part(
        'template-parts/login/step',
        'new-password',
        array(
            'token'     => $token,
            'has_error' => $has_error,
        )
    );
    ?>
    </main>
    <?php
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
    ?>
    <main>
    <?php
    get_template_part( 'template-parts/login/step', 'email', array( 'has_error' => ( 'invalid_email' === $step ) ) );
    ?>
    </main>
    <?php
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
    ?>
    <main>
    <?php
    get_template_part(
        'template-parts/login/step',
        'password',
        array(
            'email'     => $email,
            'has_error' => isset( $_GET['amap_login_error'] ),
        )
    );
    ?>
    </main>
    <?php
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
    ?>
    <main>
    <?php
    get_template_part(
        'template-parts/login/step',
        'message',
        array(
            'message'          => $messages[ $step ],
            'show_login_link'  => ( 'password_reset_done' === $step ),
        )
    );
    ?>
    </main>
    <?php
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

    $user        = wp_get_current_user();
    $is_member   = in_array( 'amap_member', $user->roles, true );
    $is_producer = in_array( 'amap_producer', $user->roles, true );
    $is_board    = in_array( 'amap_board', $user->roles, true );
    // Nom/prénom/email/téléphone/adresse sont liés au compte (user_id), pas à une casquette
    // particulière : accessibles dès qu'au moins une casquette AMAP est portée.
    $is_amap_user = $is_member || $is_producer || $is_board;
    $action       = isset( $_GET['amap_member_action'] ) ? sanitize_key( wp_unslash( $_GET['amap_member_action'] ) ) : '';
    $notice       = isset( $_GET['amap_member_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_member_notice'] ) ) : '';

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
                'is_member'       => $is_member,
                'is_producer'     => $is_producer,
                'is_board'        => $is_board,
                'is_amap_user'    => $is_amap_user,
                'profile_updated' => ( 'profile_updated' === $notice ),
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

    add_submenu_page(
        'amap-users',
        __( 'Groupes', 'association-manager' ),
        __( 'Groupes', 'association-manager' ),
        'amap_manage_groups',
        'amap-groups',
        'amap_render_groups_page'
    );

    add_submenu_page(
        'amap-users',
        __( 'Contrats', 'association-manager' ),
        __( 'Contrats', 'association-manager' ),
        'amap_manage_contracts',
        'amap-contracts',
        'amap_render_contracts_page'
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

        <?php if ( ! $editing_id ) : ?>
            <p>
                <button type="button" class="button button-primary" id="amap-user-add-toggle"><?php esc_html_e( '+ Ajouter un utilisateur', 'association-manager' ); ?></button>
            </p>
        <?php endif; ?>
        <div id="amap-user-form-wrapper"<?php echo $editing_id ? '' : ' hidden'; ?>>
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
            <table class="form-table">
                <tr>
                    <th><label for="amap-user-last-name"><?php esc_html_e( 'Nom', 'association-manager' ); ?></label></th>
                    <td><input type="text" id="amap-user-last-name" name="last_name" value="<?php echo esc_attr( $form_data['last_name'] ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="amap-user-first-name"><?php esc_html_e( 'Prénom', 'association-manager' ); ?></label></th>
                    <td><input type="text" id="amap-user-first-name" name="first_name" value="<?php echo esc_attr( $form_data['first_name'] ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="amap-user-email"><?php esc_html_e( 'Email', 'association-manager' ); ?></label></th>
                    <td>
                        <input type="email" id="amap-user-email" name="email" value="<?php echo esc_attr( $form_data['email'] ?? '' ); ?>" required>
                        <?php if ( ! $editing_id ) : ?>
                            <p class="description">
                                <?php esc_html_e( "Si un compte WordPress existe déjà avec cet email, il est réutilisé (identité inchangée) et les rôles cochés ci-dessous lui sont simplement ajoutés — utile pour faire cumuler une nouvelle casquette à un utilisateur existant.", 'association-manager' ); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="amap-user-phone"><?php esc_html_e( 'Téléphone', 'association-manager' ); ?></label></th>
                    <td>
                        <input type="text" inputmode="tel" name="phone" id="amap-user-phone" value="<?php echo esc_attr( $form_data['phone'] ?? '' ); ?>" pattern="(0[1-9]|\+33[1-9])([\s.-]?\d{2}){4}" placeholder="0X XX XX XX XX" required>
                        <span id="amap-user-phone-error" style="color:#d63638;" hidden><?php esc_html_e( 'Format attendu : 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></span>
                    </td>
                </tr>
                <tr>
                    <th><label for="amap-user-address"><?php esc_html_e( 'Adresse', 'association-manager' ); ?></label></th>
                    <td><input type="text" id="amap-user-address" name="address" value="<?php echo esc_attr( $form_data['address'] ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Rôles', 'association-manager' ); ?></th>
                    <td>
                        <?php foreach ( amap_get_available_roles() as $role_slug => $role_label ) : ?>
                            <label>
                                <input type="checkbox" name="roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $selected_roles, true ) ); ?>>
                                <?php echo esc_html( $role_label ); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </table>
            <p>
                <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                <?php if ( $editing_id ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-users' ) ); ?>" class="button">
                        <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                    </a>
                <?php else : ?>
                    <button type="button" class="button" id="amap-user-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                <?php endif; ?>
            </p>
        </form>
        </div>
        <script>
        ( function () {
            var toggle  = document.getElementById( 'amap-user-add-toggle' );
            var wrapper = document.getElementById( 'amap-user-form-wrapper' );
            var cancel  = document.getElementById( 'amap-user-add-cancel' );
            if ( toggle ) {
                toggle.addEventListener( 'click', function () {
                    wrapper.hidden = false;
                    toggle.hidden  = true;
                } );
            }
            if ( cancel ) {
                cancel.addEventListener( 'click', function () {
                    wrapper.hidden = true;
                    toggle.hidden  = false;
                } );
            }
        } )();
        </script>
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

/**
 * 0=lundi ... 6=dimanche, convention partagée avec la colonne wp_amap_groups.weekday.
 */
function amap_get_weekday_labels() {
    return array(
        0 => __( 'Lundi', 'association-manager' ),
        1 => __( 'Mardi', 'association-manager' ),
        2 => __( 'Mercredi', 'association-manager' ),
        3 => __( 'Jeudi', 'association-manager' ),
        4 => __( 'Vendredi', 'association-manager' ),
        5 => __( 'Samedi', 'association-manager' ),
        6 => __( 'Dimanche', 'association-manager' ),
    );
}

function amap_get_groups() {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}amap_groups ORDER BY weekday ASC, start_time ASC"
    );
}

function amap_get_group( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_groups WHERE id = %d", $id )
    );
}

function amap_get_producer_users() {
    $user_query = new WP_User_Query(
        array(
            'role'    => 'amap_producer',
            'orderby' => 'display_name',
            'order'   => 'ASC',
        )
    );

    return $user_query->get_results();
}

function amap_get_group_producer_ids( $group_id ) {
    global $wpdb;

    return $wpdb->get_col(
        $wpdb->prepare(
            "SELECT producer_user_id FROM {$wpdb->prefix}amap_group_producers WHERE group_id = %d",
            $group_id
        )
    );
}

/**
 * Sens inverse de amap_get_group_producer_ids() : tous les groupes auxquels un producteur est
 * rattaché. Sert à limiter les menus déroulants "Groupe" des dates de livraison d'un contrat, et
 * à revalider côté serveur qu'un group_id soumis appartient bien au producteur du contrat.
 */
function amap_get_producer_groups( $producer_user_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT g.* FROM {$wpdb->prefix}amap_groups g
             INNER JOIN {$wpdb->prefix}amap_group_producers gp ON gp.group_id = g.id
             WHERE gp.producer_user_id = %d
             ORDER BY g.weekday ASC, g.start_time ASC",
            $producer_user_id
        )
    );
}

/**
 * Les colonnes TIME de MySQL sont lues sous la forme "HH:MM:SS" par $wpdb : on ne garde que
 * "HH:MM", à la fois pour l'affichage dans le tableau et pour préremplir un <input type="time">.
 */
function amap_format_time( $time ) {
    return substr( $time, 0, 5 );
}

function amap_is_valid_time( $time ) {
    return (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time );
}

function amap_store_group_form_data( array $data ) {
    set_transient( 'amap_group_form_' . get_current_user_id(), $data, 60 );
}

function amap_render_groups_page() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        return;
    }

    $notice = isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '';

    // Mode édition : ?action=edit&id=X sur cette même page. Si l'ID ne correspond à aucun
    // groupe, on retombe silencieusement sur le formulaire d'ajout (même logique que la page
    // "Utilisateurs AMAP").
    $editing_id = 0;
    if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ) {
        $editing_id = absint( $_GET['id'] );
    }
    $editing_group = $editing_id ? amap_get_group( $editing_id ) : null;
    if ( $editing_id && ! $editing_group ) {
        $editing_id = 0;
    }

    $transient_key = 'amap_group_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_group ) {
        $form_data = array(
            'name'           => $editing_group->name,
            'delivery_place' => $editing_group->delivery_place,
            'weekday'        => (string) $editing_group->weekday,
            'start_time'     => amap_format_time( $editing_group->start_time ),
            'end_time'       => amap_format_time( $editing_group->end_time ),
        );
    } else {
        $form_data = array();
    }

    $groups = amap_get_groups();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Groupes de distribution', 'association-manager' ); ?></h1>

        <?php if ( 'invalid' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Champs obligatoires manquants.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_time' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( "L'heure de fin doit être après l'heure de début.", 'association-manager' ); ?></p></div>
        <?php endif; ?>

        <?php if ( ! $editing_id ) : ?>
            <p>
                <button type="button" class="button button-primary" id="amap-group-add-toggle"><?php esc_html_e( '+ Ajouter un groupe', 'association-manager' ); ?></button>
            </p>
        <?php endif; ?>
        <div id="amap-group-form-wrapper"<?php echo $editing_id ? '' : ' hidden'; ?>>
        <h2>
            <?php echo $editing_id
                ? esc_html__( 'Modifier un groupe', 'association-manager' )
                : esc_html__( 'Ajouter un groupe', 'association-manager' ); ?>
        </h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php if ( $editing_id ) : ?>
                <?php wp_nonce_field( 'amap_edit_group_' . $editing_id ); ?>
                <input type="hidden" name="action" value="amap_update_group">
                <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
            <?php else : ?>
                <?php wp_nonce_field( 'amap_add_group' ); ?>
                <input type="hidden" name="action" value="amap_add_group">
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><label for="amap-group-name"><?php esc_html_e( 'Nom', 'association-manager' ); ?></label></th>
                    <td><input type="text" id="amap-group-name" name="name" value="<?php echo esc_attr( $form_data['name'] ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="amap-group-delivery-place"><?php esc_html_e( 'Lieu de livraison', 'association-manager' ); ?></label></th>
                    <td><input type="text" id="amap-group-delivery-place" name="delivery_place" value="<?php echo esc_attr( $form_data['delivery_place'] ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="amap-group-weekday"><?php esc_html_e( 'Jour de la semaine', 'association-manager' ); ?></label></th>
                    <td>
                        <select id="amap-group-weekday" name="weekday" required>
                            <?php foreach ( amap_get_weekday_labels() as $weekday => $weekday_label ) : ?>
                                <option value="<?php echo esc_attr( $weekday ); ?>" <?php selected( (string) $weekday, $form_data['weekday'] ?? '' ); ?>>
                                    <?php echo esc_html( $weekday_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="amap-group-start-time"><?php esc_html_e( 'Heure de début', 'association-manager' ); ?></label></th>
                    <td><input type="time" id="amap-group-start-time" name="start_time" value="<?php echo esc_attr( $form_data['start_time'] ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="amap-group-end-time"><?php esc_html_e( 'Heure de fin', 'association-manager' ); ?></label></th>
                    <td><input type="time" id="amap-group-end-time" name="end_time" value="<?php echo esc_attr( $form_data['end_time'] ?? '' ); ?>" required></td>
                </tr>
            </table>
            <p>
                <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                <?php if ( $editing_id ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-groups' ) ); ?>" class="button">
                        <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                    </a>
                <?php else : ?>
                    <button type="button" class="button" id="amap-group-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                <?php endif; ?>
            </p>
        </form>
        </div>
        <script>
        ( function () {
            var toggle  = document.getElementById( 'amap-group-add-toggle' );
            var wrapper = document.getElementById( 'amap-group-form-wrapper' );
            var cancel  = document.getElementById( 'amap-group-add-cancel' );
            if ( toggle ) {
                toggle.addEventListener( 'click', function () {
                    wrapper.hidden = false;
                    toggle.hidden  = true;
                } );
            }
            if ( cancel ) {
                cancel.addEventListener( 'click', function () {
                    wrapper.hidden = true;
                    toggle.hidden  = false;
                } );
            }
        } )();
        </script>

        <?php if ( $editing_id ) : ?>
            <?php
            $producers              = amap_get_producer_users();
            $attached_producer_ids  = amap_get_group_producer_ids( $editing_id );
            ?>
            <h2><?php esc_html_e( 'Producteurs rattachés', 'association-manager' ); ?></h2>
            <?php if ( 'producers_updated' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Producteurs rattachés mis à jour.', 'association-manager' ); ?></p></div>
            <?php endif; ?>
            <?php if ( empty( $producers ) ) : ?>
                <p><?php esc_html_e( "Aucun compte producteur pour le moment.", 'association-manager' ); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'amap_update_group_producers_' . $editing_id ); ?>
                    <input type="hidden" name="action" value="amap_update_group_producers">
                    <input type="hidden" name="group_id" value="<?php echo esc_attr( $editing_id ); ?>">
                    <?php foreach ( $producers as $producer ) : ?>
                        <p>
                            <label>
                                <input
                                    type="checkbox"
                                    name="producer_ids[]"
                                    value="<?php echo esc_attr( $producer->ID ); ?>"
                                    <?php checked( in_array( (string) $producer->ID, $attached_producer_ids, true ) ); ?>
                                >
                                <?php echo esc_html( $producer->display_name ); ?>
                            </label>
                        </p>
                    <?php endforeach; ?>
                    <p>
                        <?php submit_button( __( 'Enregistrer les producteurs', 'association-manager' ), 'primary', 'submit', false ); ?>
                    </p>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( empty( $groups ) ) : ?>
            <p><?php esc_html_e( 'Aucun groupe enregistré pour le moment.', 'association-manager' ); ?></p>
        <?php else : ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Nom', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Lieu de livraison', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Jour', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Horaire', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $groups as $group ) : ?>
                        <?php $weekday_labels = amap_get_weekday_labels(); ?>
                        <tr>
                            <td><?php echo esc_html( $group->name ); ?></td>
                            <td><?php echo esc_html( $group->delivery_place ); ?></td>
                            <td><?php echo esc_html( $weekday_labels[ (int) $group->weekday ] ?? '' ); ?></td>
                            <td><?php echo esc_html( amap_format_time( $group->start_time ) . ' - ' . amap_format_time( $group->end_time ) ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group->id ) ); ?>">
                                    <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                </a>
                                |
                                <?php
                                $delete_url = wp_nonce_url(
                                    admin_url( 'admin-post.php?action=amap_delete_group&id=' . $group->id ),
                                    'amap_delete_group_' . $group->id
                                );
                                // translators: %s: nom du groupe.
                                $confirm_message = sprintf( __( 'Supprimer définitivement le groupe %s ?', 'association-manager' ), $group->name );
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

add_action( 'admin_post_amap_add_group', 'amap_handle_add_group' );

function amap_handle_add_group() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_group' );

    $name           = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $delivery_place = isset( $_POST['delivery_place'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_place'] ) ) : '';
    $weekday        = isset( $_POST['weekday'] ) ? sanitize_key( wp_unslash( $_POST['weekday'] ) ) : '';
    $start_time     = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
    $end_time       = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
    $submitted      = compact( 'name', 'delivery_place', 'weekday', 'start_time', 'end_time' );

    if ( '' === $name || '' === $delivery_place || ! array_key_exists( (int) $weekday, amap_get_weekday_labels() )
        || ! amap_is_valid_time( $start_time ) || ! amap_is_valid_time( $end_time ) ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-groups&amap_notice=invalid' ) );
        exit;
    }

    if ( $start_time >= $end_time ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-groups&amap_notice=invalid_time' ) );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_groups',
        array(
            'name'           => $name,
            'delivery_place' => $delivery_place,
            'weekday'        => (int) $weekday,
            'start_time'     => $start_time,
            'end_time'       => $end_time,
        )
    );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-groups' ) );
    exit;
}

add_action( 'admin_post_amap_update_group', 'amap_handle_update_group' );

function amap_handle_update_group() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    if ( ! $id || ! amap_get_group( $id ) ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_edit_group_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $id );

    $name           = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $delivery_place = isset( $_POST['delivery_place'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_place'] ) ) : '';
    $weekday        = isset( $_POST['weekday'] ) ? sanitize_key( wp_unslash( $_POST['weekday'] ) ) : '';
    $start_time     = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
    $end_time       = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
    $submitted      = compact( 'name', 'delivery_place', 'weekday', 'start_time', 'end_time' );

    if ( '' === $name || '' === $delivery_place || ! array_key_exists( (int) $weekday, amap_get_weekday_labels() )
        || ! amap_is_valid_time( $start_time ) || ! amap_is_valid_time( $end_time ) ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid' );
        exit;
    }

    if ( $start_time >= $end_time ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid_time' );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_groups',
        array(
            'name'           => $name,
            'delivery_place' => $delivery_place,
            'weekday'        => (int) $weekday,
            'start_time'     => $start_time,
            'end_time'       => $end_time,
        ),
        array( 'id' => $id )
    );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-groups' ) );
    exit;
}

add_action( 'admin_post_amap_delete_group', 'amap_handle_delete_group' );

function amap_handle_delete_group() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    if ( ! $id || ! amap_get_group( $id ) ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_group_' . $id );

    global $wpdb;
    // Pas de contrainte FOREIGN KEY SQL sur group_id (cohérent avec le reste du plugin) : le
    // nettoyage des rattachements producteurs orphelins, ainsi que des dates de livraison de
    // contrats déjà générées pour ce groupe, se fait explicitement ici.
    $wpdb->delete( $wpdb->prefix . 'amap_group_producers', array( 'group_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_contract_delivery_dates', array( 'group_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_groups', array( 'id' => $id ) );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-groups' ) );
    exit;
}

add_action( 'admin_post_amap_update_group_producers', 'amap_handle_update_group_producers' );

function amap_handle_update_group_producers() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    if ( ! $group_id || ! amap_get_group( $group_id ) ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_update_group_producers_' . $group_id );

    // Défense en profondeur : on ne garde que des ID correspondant réellement à un compte
    // portant la casquette amap_producer, même si le HTML du formulaire ne propose que ça.
    $valid_producer_ids = wp_list_pluck( amap_get_producer_users(), 'ID' );
    $submitted_ids      = isset( $_POST['producer_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['producer_ids'] ) ) : array();
    $producer_ids       = array_intersect( $submitted_ids, $valid_producer_ids );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_group_producers', array( 'group_id' => $group_id ) );
    foreach ( $producer_ids as $producer_id ) {
        $wpdb->insert(
            $wpdb->prefix . 'amap_group_producers',
            array(
                'group_id'         => $group_id,
                'producer_user_id' => $producer_id,
            )
        );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group_id . '&amap_notice=producers_updated' ) );
    exit;
}

function amap_get_contract_types() {
    return array(
        'basket_recurring' => __( 'Panier récurrent', 'association-manager' ),
        'product_grid'     => __( 'Grille produits', 'association-manager' ),
    );
}

function amap_get_contracts() {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}amap_contracts ORDER BY is_active DESC, start_date DESC"
    );
}

function amap_get_contract( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_contracts WHERE id = %d", $id )
    );
}

/**
 * Valide une date au format "YYYY-MM-DD", celui renvoyé par un <input type="date">.
 */
function amap_is_valid_date( $date ) {
    $parts = explode( '-', $date );
    if ( 3 !== count( $parts ) ) {
        return false;
    }

    list( $year, $month, $day ) = $parts;
    return ctype_digit( $year ) && ctype_digit( $month ) && ctype_digit( $day )
        && checkdate( (int) $month, (int) $day, (int) $year );
}

function amap_store_contract_form_data( array $data ) {
    set_transient( 'amap_contract_form_' . get_current_user_id(), $data, 60 );
}

function amap_get_contract_basket_sizes( $contract_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_contract_basket_sizes WHERE contract_id = %d ORDER BY id ASC",
            $contract_id
        )
    );
}

function amap_get_contract_basket_size( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_contract_basket_sizes WHERE id = %d", $id )
    );
}

function amap_is_valid_price( $price ) {
    return is_numeric( $price ) && (float) $price > 0;
}

function amap_store_contract_basket_size_form_data( array $data ) {
    set_transient( 'amap_contract_basket_size_form_' . get_current_user_id(), $data, 60 );
}

function amap_get_contract_products( $contract_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_contract_products WHERE contract_id = %d ORDER BY id ASC",
            $contract_id
        )
    );
}

function amap_get_contract_product( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_contract_products WHERE id = %d", $id )
    );
}

function amap_store_contract_product_form_data( array $data ) {
    set_transient( 'amap_contract_product_form_' . get_current_user_id(), $data, 60 );
}

function amap_get_contract_delivery_dates( $contract_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_contract_delivery_dates WHERE contract_id = %d ORDER BY group_id ASC, delivery_date ASC",
            $contract_id
        )
    );
}

function amap_get_contract_delivery_date( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_contract_delivery_dates WHERE id = %d", $id )
    );
}

/**
 * Dates déjà enregistrées pour un couple (contrat, groupe) donné, sous forme de tableau de
 * chaînes "YYYY-MM-DD". Sert à exclure les dates déjà ajoutées de la liste des dates candidates
 * proposées lors d'une génération en masse (amap_handle_generate_contract_delivery_dates()).
 */
function amap_get_contract_delivery_dates_for_group( $contract_id, $group_id ) {
    global $wpdb;

    return $wpdb->get_col(
        $wpdb->prepare(
            "SELECT delivery_date FROM {$wpdb->prefix}amap_contract_delivery_dates WHERE contract_id = %d AND group_id = %d ORDER BY delivery_date ASC",
            $contract_id,
            $group_id
        )
    );
}

/**
 * Revérifie côté PHP la contrainte UNIQUE(contract_id, group_id, delivery_date), pour afficher
 * un message d'erreur clair plutôt que de laisser échouer silencieusement le
 * $wpdb->insert()/update(). $exclude_id : ID à ignorer, pour ne pas se comparer à soi-même lors
 * d'une modification.
 */
function amap_contract_has_delivery_date( $contract_id, $group_id, $delivery_date, $exclude_id = 0 ) {
    global $wpdb;

    $sql    = "SELECT COUNT(*) FROM {$wpdb->prefix}amap_contract_delivery_dates WHERE contract_id = %d AND group_id = %d AND delivery_date = %s";
    $params = array( $contract_id, $group_id, $delivery_date );

    if ( $exclude_id ) {
        $sql     .= ' AND id != %d';
        $params[] = $exclude_id;
    }

    return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
}

/**
 * Toutes les occurrences calendaires d'un jour de semaine donné (convention 0=lundi..6=dimanche,
 * comme amap_get_weekday_labels()) entre deux dates incluses. Sert uniquement à proposer les
 * dates candidates d'une génération en masse — jamais utilisée pour valider le formulaire manuel,
 * qui reste volontairement permissif sur le jour de semaine (dates exceptionnelles).
 */
function amap_get_weekday_dates_in_range( $start_date, $end_date, $weekday ) {
    $dates = array();

    try {
        $current = new DateTime( $start_date );
        $end     = new DateTime( $end_date );
    } catch ( Exception $e ) {
        return $dates;
    }

    $target_iso_weekday = $weekday + 1; // DateTime::format('N') : 1=lundi..7=dimanche.

    while ( (int) $current->format( 'N' ) !== $target_iso_weekday ) {
        $current->modify( '+1 day' );
    }

    $interval = new DateInterval( 'P7D' );
    while ( $current <= $end ) {
        $dates[] = $current->format( 'Y-m-d' );
        $current->add( $interval );
    }

    return $dates;
}

function amap_store_contract_delivery_date_form_data( array $data ) {
    set_transient( 'amap_contract_delivery_date_form_' . get_current_user_id(), $data, 60 );
}

function amap_render_contracts_page() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        return;
    }

    $notice = isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '';

    // Mode édition : ?action=edit&id=X sur cette même page, même logique que "Groupes" et
    // "Utilisateurs AMAP".
    $editing_id = 0;
    if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ) {
        $editing_id = absint( $_GET['id'] );
    }
    $editing_contract = $editing_id ? amap_get_contract( $editing_id ) : null;
    if ( $editing_id && ! $editing_contract ) {
        $editing_id = 0;
    }

    $transient_key = 'amap_contract_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_contract ) {
        $form_data = array(
            'label'            => $editing_contract->label,
            'producer_user_id' => (string) $editing_contract->producer_user_id,
            'contract_type'    => $editing_contract->contract_type,
            'start_date'       => $editing_contract->start_date,
            'end_date'         => $editing_contract->end_date,
            'frequency_weeks'  => null !== $editing_contract->frequency_weeks ? (string) $editing_contract->frequency_weeks : '',
            'is_active'        => (bool) $editing_contract->is_active,
        );
    } else {
        // Une case cochée par défaut à la création : un nouveau contrat est ouvert à la
        // souscription tant que le bureau ne l'a pas explicitement fermé.
        $form_data = array( 'is_active' => true );
    }

    // Mode édition d'une taille de panier : ?size_action=edit&size_id=Y en plus de
    // ?action=edit&id=X sur cette même page (X = contrat, Y = taille de ce contrat).
    $size_editing_id = 0;
    if ( isset( $_GET['size_action'], $_GET['size_id'] ) && 'edit' === $_GET['size_action'] ) {
        $size_editing_id = absint( $_GET['size_id'] );
    }
    $size_editing = $size_editing_id ? amap_get_contract_basket_size( $size_editing_id ) : null;
    if ( $size_editing_id && ( ! $size_editing || (int) $size_editing->contract_id !== $editing_id ) ) {
        $size_editing_id = 0;
        $size_editing     = null;
    }

    $basket_size_transient_key = 'amap_contract_basket_size_form_' . get_current_user_id();
    $basket_size_form_data     = get_transient( $basket_size_transient_key );
    if ( false !== $basket_size_form_data ) {
        delete_transient( $basket_size_transient_key );
    } elseif ( $size_editing ) {
        $basket_size_form_data = array(
            'label' => $size_editing->label,
            'price' => (string) $size_editing->price,
        );
    } else {
        $basket_size_form_data = array();
    }

    // Mode édition d'un produit du catalogue : ?product_action=edit&product_id=Y en plus de
    // ?action=edit&id=X sur cette même page (X = contrat, Y = produit de ce contrat).
    $product_editing_id = 0;
    if ( isset( $_GET['product_action'], $_GET['product_id'] ) && 'edit' === $_GET['product_action'] ) {
        $product_editing_id = absint( $_GET['product_id'] );
    }
    $product_editing = $product_editing_id ? amap_get_contract_product( $product_editing_id ) : null;
    if ( $product_editing_id && ( ! $product_editing || (int) $product_editing->contract_id !== $editing_id ) ) {
        $product_editing_id = 0;
        $product_editing    = null;
    }

    $contract_product_transient_key = 'amap_contract_product_form_' . get_current_user_id();
    $contract_product_form_data     = get_transient( $contract_product_transient_key );
    if ( false !== $contract_product_form_data ) {
        delete_transient( $contract_product_transient_key );
    } elseif ( $product_editing ) {
        $contract_product_form_data = array(
            'label' => $product_editing->label,
            'price' => (string) $product_editing->price,
        );
    } else {
        $contract_product_form_data = array();
    }

    // Mode édition d'une date de livraison : ?date_action=edit&date_id=Y en plus de
    // ?action=edit&id=X sur cette même page (X = contrat, Y = date de ce contrat).
    $delivery_date_editing_id = 0;
    if ( isset( $_GET['date_action'], $_GET['date_id'] ) && 'edit' === $_GET['date_action'] ) {
        $delivery_date_editing_id = absint( $_GET['date_id'] );
    }
    $delivery_date_editing = $delivery_date_editing_id ? amap_get_contract_delivery_date( $delivery_date_editing_id ) : null;
    if ( $delivery_date_editing_id && ( ! $delivery_date_editing || (int) $delivery_date_editing->contract_id !== $editing_id ) ) {
        $delivery_date_editing_id = 0;
        $delivery_date_editing    = null;
    }

    $contract_delivery_date_transient_key = 'amap_contract_delivery_date_form_' . get_current_user_id();
    $contract_delivery_date_form_data     = get_transient( $contract_delivery_date_transient_key );
    if ( false !== $contract_delivery_date_form_data ) {
        delete_transient( $contract_delivery_date_transient_key );
    } elseif ( $delivery_date_editing ) {
        $contract_delivery_date_form_data = array(
            'group_id'      => (string) $delivery_date_editing->group_id,
            'delivery_date' => $delivery_date_editing->delivery_date,
        );
    } else {
        $contract_delivery_date_form_data = array();
    }

    // Mode génération en masse : ?generate_group_id=G en plus de ?action=edit&id=X. Le groupe
    // choisi n'est revalidé contre les groupes du producteur qu'une fois $editing_contract
    // confirmé plus bas (voir bloc product_grid).
    $generate_group_id = isset( $_GET['generate_group_id'] ) ? absint( $_GET['generate_group_id'] ) : 0;

    $producers      = amap_get_producer_users();
    $contract_types = amap_get_contract_types();
    $contracts      = amap_get_contracts();

    // Onglet actif à l'affichage : par défaut "Infos du contrat", sauf si l'URL cible
    // explicitement la modification d'un élément d'une autre sous-section (lien "Modifier"
    // d'une taille/d'un produit/d'une date, ou génération en masse en cours), ou si l'URL porte
    // explicitement ?active_tab=... (posé par les boutons Annuler et par les redirections des
    // handlers add/update/delete de ces sous-sections, pour rester sur le bon onglet après un
    // enregistrement/annulation/suppression).
    $requested_tab        = isset( $_GET['active_tab'] ) ? sanitize_key( wp_unslash( $_GET['active_tab'] ) ) : '';
    $active_contract_tab  = 'amap-contract-form-wrapper';
    if ( 'sizes' === $requested_tab || $size_editing_id ) {
        $active_contract_tab = 'amap-tab-sizes';
    } elseif ( 'products' === $requested_tab || $product_editing_id ) {
        $active_contract_tab = 'amap-tab-products';
    } elseif ( 'dates' === $requested_tab || $delivery_date_editing_id || $generate_group_id ) {
        $active_contract_tab = 'amap-tab-dates';
    }
    ?>
    <style>
        table.widefat {
            border: none;
            box-shadow: none;
        }
        table.widefat th,
        table.widefat td {
            border: none;
            border-bottom: 1px solid #e0e0e0;
        }
    </style>
    <div class="wrap">
        <h1><?php esc_html_e( 'Contrats', 'association-manager' ); ?></h1>

        <?php if ( 'invalid' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Champs obligatoires manquants ou invalides.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_dates' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Dates invalides : la date de fin doit être après la date de début.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_frequency' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'La fréquence (en semaines) est obligatoire et doit être un nombre positif pour un contrat de type panier récurrent.', 'association-manager' ); ?></p></div>
        <?php endif; ?>

        <?php if ( empty( $producers ) ) : ?>
            <p><?php esc_html_e( "Aucun compte producteur pour le moment : créez d'abord un producteur depuis la page Utilisateurs AMAP.", 'association-manager' ); ?></p>
        <?php else : ?>
            <?php if ( ! $editing_id ) : ?>
                <p>
                    <button type="button" class="button button-primary" id="amap-contract-add-toggle"><?php esc_html_e( '+ Ajouter un contrat', 'association-manager' ); ?></button>
                </p>
            <?php endif; ?>
            <?php if ( $editing_id && $editing_contract ) : ?>
                <h2 class="nav-tab-wrapper" id="amap-contract-tabs">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts' ) ); ?>" class="nav-tab"><?php esc_html_e( 'Liste des contrats', 'association-manager' ); ?></a>
                    <a href="#" class="nav-tab<?php echo ( 'amap-contract-form-wrapper' === $active_contract_tab ) ? ' nav-tab-active' : ''; ?>" data-amap-tab="amap-contract-form-wrapper"><?php esc_html_e( 'Infos du contrat', 'association-manager' ); ?></a>
                    <?php if ( 'basket_recurring' === $editing_contract->contract_type ) : ?>
                        <a href="#" class="nav-tab<?php echo ( 'amap-tab-sizes' === $active_contract_tab ) ? ' nav-tab-active' : ''; ?>" data-amap-tab="amap-tab-sizes"><?php esc_html_e( 'Tailles de panier', 'association-manager' ); ?></a>
                    <?php elseif ( 'product_grid' === $editing_contract->contract_type ) : ?>
                        <a href="#" class="nav-tab<?php echo ( 'amap-tab-products' === $active_contract_tab ) ? ' nav-tab-active' : ''; ?>" data-amap-tab="amap-tab-products"><?php esc_html_e( 'Produits', 'association-manager' ); ?></a>
                        <a href="#" class="nav-tab<?php echo ( 'amap-tab-dates' === $active_contract_tab ) ? ' nav-tab-active' : ''; ?>" data-amap-tab="amap-tab-dates"><?php esc_html_e( 'Dates de livraison', 'association-manager' ); ?></a>
                    <?php endif; ?>
                </h2>
            <?php endif; ?>
            <div id="amap-contract-form-wrapper" class="amap-tab-panel"<?php echo ( $editing_id && 'amap-contract-form-wrapper' === $active_contract_tab ) ? '' : ' hidden'; ?>>
            <?php if ( $editing_id && $editing_contract ) : ?>
                <?php $editing_contract_producer = get_user_by( 'id', $editing_contract->producer_user_id ); ?>
                <div id="amap-contract-view">
                    <table class="widefat">
                        <tbody>
                            <tr>
                                <th><?php esc_html_e( 'Libellé', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $editing_contract->label ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Producteur', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $editing_contract_producer ? $editing_contract_producer->display_name : '—' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Type de contrat', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $contract_types[ $editing_contract->contract_type ] ?? $editing_contract->contract_type ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Période', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $editing_contract->start_date . ' → ' . $editing_contract->end_date ); ?></td>
                            </tr>
                            <?php if ( null !== $editing_contract->frequency_weeks ) : ?>
                                <tr>
                                    <th><?php esc_html_e( 'Fréquence (en semaines)', 'association-manager' ); ?></th>
                                    <td><?php echo esc_html( $editing_contract->frequency_weeks ); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <th><?php esc_html_e( 'Statut', 'association-manager' ); ?></th>
                                <td><?php echo $editing_contract->is_active ? esc_html__( 'Actif', 'association-manager' ) : esc_html__( 'Inactif', 'association-manager' ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="button button-primary" id="amap-contract-edit-toggle"><?php esc_html_e( 'Modifier les infos', 'association-manager' ); ?></button>
                    </p>
                </div>
            <?php endif; ?>
            <div id="amap-contract-edit-form"<?php echo $editing_id ? ' hidden' : ''; ?>>
            <h2>
                <?php echo $editing_id
                    ? esc_html__( 'Modifier un contrat', 'association-manager' )
                    : esc_html__( 'Ajouter un contrat', 'association-manager' ); ?>
            </h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-contract-form">
                <?php if ( $editing_id ) : ?>
                    <?php wp_nonce_field( 'amap_edit_contract_' . $editing_id ); ?>
                    <input type="hidden" name="action" value="amap_update_contract">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_contract' ); ?>
                    <input type="hidden" name="action" value="amap_add_contract">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="amap-contract-label"><?php esc_html_e( 'Libellé', 'association-manager' ); ?></label></th>
                        <td><input type="text" id="amap-contract-label" name="label" value="<?php echo esc_attr( $form_data['label'] ?? '' ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="amap-contract-producer"><?php esc_html_e( 'Producteur', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-contract-producer" name="producer_user_id" required>
                                <option value=""></option>
                                <?php foreach ( $producers as $producer ) : ?>
                                    <option value="<?php echo esc_attr( $producer->ID ); ?>" <?php selected( (string) $producer->ID, $form_data['producer_user_id'] ?? '' ); ?>>
                                        <?php echo esc_html( $producer->display_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amap-contract-type"><?php esc_html_e( 'Type de contrat', 'association-manager' ); ?></label></th>
                        <td>
                            <select name="contract_type" id="amap-contract-type" required>
                                <?php foreach ( $contract_types as $type_slug => $type_label ) : ?>
                                    <option value="<?php echo esc_attr( $type_slug ); ?>" <?php selected( $type_slug, $form_data['contract_type'] ?? '' ); ?>>
                                        <?php echo esc_html( $type_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amap-contract-start-date"><?php esc_html_e( 'Date de début', 'association-manager' ); ?></label></th>
                        <td><input type="date" id="amap-contract-start-date" name="start_date" value="<?php echo esc_attr( $form_data['start_date'] ?? '' ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="amap-contract-end-date"><?php esc_html_e( 'Date de fin', 'association-manager' ); ?></label></th>
                        <td><input type="date" id="amap-contract-end-date" name="end_date" value="<?php echo esc_attr( $form_data['end_date'] ?? '' ); ?>" required></td>
                    </tr>
                    <tr id="amap-contract-frequency-row">
                        <th><label for="amap-contract-frequency"><?php esc_html_e( 'Fréquence (en semaines)', 'association-manager' ); ?></label></th>
                        <td>
                            <input type="number" id="amap-contract-frequency" name="frequency_weeks" min="1" max="52" value="<?php echo esc_attr( $form_data['frequency_weeks'] ?? '' ); ?>">
                            <p class="description"><?php esc_html_e( '1 = livraison chaque semaine, 2 = toutes les deux semaines, etc. Uniquement pour un panier récurrent.', 'association-manager' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Statut', 'association-manager' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_active" value="1" <?php checked( $form_data['is_active'] ?? false ); ?>>
                                <?php esc_html_e( 'Contrat actif (ouvert à la souscription)', 'association-manager' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <p>
                    <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                    <?php if ( $editing_id ) : ?>
                        <button type="button" class="button" id="amap-contract-edit-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php else : ?>
                        <button type="button" class="button" id="amap-contract-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php endif; ?>
                </p>
            </form>
            </div>
            </div>
            <script>
            ( function () {
                var viewBlock  = document.getElementById( 'amap-contract-view' );
                var editForm   = document.getElementById( 'amap-contract-edit-form' );
                var editToggle = document.getElementById( 'amap-contract-edit-toggle' );
                var editCancel = document.getElementById( 'amap-contract-edit-cancel' );
                if ( editToggle ) {
                    editToggle.addEventListener( 'click', function () {
                        viewBlock.hidden = true;
                        editForm.hidden  = false;
                    } );
                }
                if ( editCancel ) {
                    editCancel.addEventListener( 'click', function () {
                        editForm.hidden  = true;
                        viewBlock.hidden = false;
                    } );
                }
            } )();
            </script>
            <script>
            ( function () {
                var typeField     = document.getElementById( 'amap-contract-type' );
                var frequencyRow  = document.getElementById( 'amap-contract-frequency-row' );

                function toggleFrequencyRow() {
                    frequencyRow.hidden = ( 'basket_recurring' !== typeField.value );
                }

                typeField.addEventListener( 'change', toggleFrequencyRow );
                toggleFrequencyRow();
            } )();
            </script>
            <script>
            ( function () {
                var toggle  = document.getElementById( 'amap-contract-add-toggle' );
                var wrapper = document.getElementById( 'amap-contract-form-wrapper' );
                var cancel  = document.getElementById( 'amap-contract-add-cancel' );
                if ( toggle ) {
                    toggle.addEventListener( 'click', function () {
                        wrapper.hidden = false;
                        toggle.hidden  = true;
                    } );
                }
                if ( cancel ) {
                    cancel.addEventListener( 'click', function () {
                        wrapper.hidden = true;
                        toggle.hidden  = false;
                    } );
                }
            } )();
            </script>
        <?php endif; ?>

        <?php if ( $editing_id && $editing_contract && 'basket_recurring' === $editing_contract->contract_type ) : ?>
            <?php $basket_sizes = amap_get_contract_basket_sizes( $editing_id ); ?>
            <div class="postbox amap-tab-panel" id="amap-tab-sizes"<?php echo ( 'amap-tab-sizes' === $active_contract_tab ) ? '' : ' hidden'; ?>>
            <div class="inside">
            <?php if ( 'basket_size_invalid' === $notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Libellé ou prix invalide.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'basket_size_saved' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Taille de panier enregistrée.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'basket_size_deleted' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Taille de panier supprimée.', 'association-manager' ); ?></p></div>
            <?php endif; ?>

            <?php if ( empty( $basket_sizes ) ) : ?>
                <p><?php esc_html_e( 'Aucune taille de panier pour le moment.', 'association-manager' ); ?></p>
            <?php else : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Libellé', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Prix', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $basket_sizes as $basket_size ) : ?>
                            <tr>
                                <td><?php echo esc_html( $basket_size->label ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( (float) $basket_size->price, 2 ) ); ?> €</td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $editing_id . '&size_action=edit&size_id=' . $basket_size->id ) ); ?>">
                                        <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                    </a>
                                    |
                                    <?php
                                    $delete_size_url = wp_nonce_url(
                                        admin_url( 'admin-post.php?action=amap_delete_contract_basket_size&id=' . $basket_size->id ),
                                        'amap_delete_contract_basket_size_' . $basket_size->id
                                    );
                                    // translators: %s: libellé de la taille de panier.
                                    $confirm_size_message = sprintf( __( 'Supprimer définitivement la taille %s ?', 'association-manager' ), $basket_size->label );
                                    ?>
                                    <a href="<?php echo esc_url( $delete_size_url ); ?>" onclick="return confirm( '<?php echo esc_js( $confirm_size_message ); ?>' );">
                                        <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( ! $size_editing_id ) : ?>
                <p>
                    <button type="button" class="button button-primary" id="amap-basket-size-add-toggle"><?php esc_html_e( '+ Ajouter une taille de panier', 'association-manager' ); ?></button>
                </p>
            <?php endif; ?>
            <div id="amap-basket-size-form-wrapper"<?php echo $size_editing_id ? '' : ' hidden'; ?>>
            <h3>
                <?php echo $size_editing_id
                    ? esc_html__( 'Modifier une taille de panier', 'association-manager' )
                    : esc_html__( 'Ajouter une taille de panier', 'association-manager' ); ?>
            </h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php if ( $size_editing_id ) : ?>
                    <?php wp_nonce_field( 'amap_edit_contract_basket_size_' . $size_editing_id ); ?>
                    <input type="hidden" name="action" value="amap_update_contract_basket_size">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $size_editing_id ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_contract_basket_size_' . $editing_id ); ?>
                    <input type="hidden" name="action" value="amap_add_contract_basket_size">
                    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $editing_id ); ?>">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="amap-basket-size-label"><?php esc_html_e( 'Libellé', 'association-manager' ); ?></label></th>
                        <td><input type="text" id="amap-basket-size-label" name="label" value="<?php echo esc_attr( $basket_size_form_data['label'] ?? '' ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="amap-basket-size-price"><?php esc_html_e( 'Prix (€)', 'association-manager' ); ?></label></th>
                        <td><input type="number" id="amap-basket-size-price" name="price" min="0.01" step="0.01" value="<?php echo esc_attr( $basket_size_form_data['price'] ?? '' ); ?>" required></td>
                    </tr>
                </table>
                <p>
                    <?php submit_button( $size_editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                    <?php if ( $size_editing_id ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $editing_id . '&active_tab=sizes' ) ); ?>" class="button">
                            <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                        </a>
                    <?php else : ?>
                        <button type="button" class="button" id="amap-basket-size-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php endif; ?>
                </p>
            </form>
            </div>
            </div>
            </div>
            <script>
            ( function () {
                var toggle  = document.getElementById( 'amap-basket-size-add-toggle' );
                var wrapper = document.getElementById( 'amap-basket-size-form-wrapper' );
                var cancel  = document.getElementById( 'amap-basket-size-add-cancel' );
                if ( toggle ) {
                    toggle.addEventListener( 'click', function () {
                        wrapper.hidden = false;
                        toggle.hidden  = true;
                    } );
                }
                if ( cancel ) {
                    cancel.addEventListener( 'click', function () {
                        wrapper.hidden = true;
                        toggle.hidden  = false;
                    } );
                }
            } )();
            </script>
        <?php endif; ?>

        <?php if ( $editing_id && $editing_contract && 'product_grid' === $editing_contract->contract_type ) : ?>
            <?php $contract_products = amap_get_contract_products( $editing_id ); ?>
            <div class="postbox amap-tab-panel" id="amap-tab-products"<?php echo ( 'amap-tab-products' === $active_contract_tab ) ? '' : ' hidden'; ?>>
            <div class="inside">
            <?php if ( 'contract_product_invalid' === $notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Libellé ou prix invalide.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'contract_product_saved' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Produit enregistré.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'contract_product_deleted' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Produit supprimé.', 'association-manager' ); ?></p></div>
            <?php endif; ?>

            <?php if ( empty( $contract_products ) ) : ?>
                <p><?php esc_html_e( 'Aucun produit pour le moment.', 'association-manager' ); ?></p>
            <?php else : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Libellé', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Prix', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $contract_products as $contract_product ) : ?>
                            <tr>
                                <td><?php echo esc_html( $contract_product->label ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( (float) $contract_product->price, 2 ) ); ?> €</td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $editing_id . '&product_action=edit&product_id=' . $contract_product->id ) ); ?>">
                                        <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                    </a>
                                    |
                                    <?php
                                    $delete_product_url = wp_nonce_url(
                                        admin_url( 'admin-post.php?action=amap_delete_contract_product&id=' . $contract_product->id ),
                                        'amap_delete_contract_product_' . $contract_product->id
                                    );
                                    // translators: %s: libellé du produit.
                                    $confirm_product_message = sprintf( __( 'Supprimer définitivement le produit %s ?', 'association-manager' ), $contract_product->label );
                                    ?>
                                    <a href="<?php echo esc_url( $delete_product_url ); ?>" onclick="return confirm( '<?php echo esc_js( $confirm_product_message ); ?>' );">
                                        <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( ! $product_editing_id ) : ?>
                <p>
                    <button type="button" class="button button-primary" id="amap-contract-product-add-toggle"><?php esc_html_e( '+ Ajouter un produit', 'association-manager' ); ?></button>
                </p>
            <?php endif; ?>
            <div id="amap-contract-product-form-wrapper"<?php echo $product_editing_id ? '' : ' hidden'; ?>>
            <h3>
                <?php echo $product_editing_id
                    ? esc_html__( 'Modifier un produit', 'association-manager' )
                    : esc_html__( 'Ajouter un produit', 'association-manager' ); ?>
            </h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php if ( $product_editing_id ) : ?>
                    <?php wp_nonce_field( 'amap_edit_contract_product_' . $product_editing_id ); ?>
                    <input type="hidden" name="action" value="amap_update_contract_product">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $product_editing_id ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_contract_product_' . $editing_id ); ?>
                    <input type="hidden" name="action" value="amap_add_contract_product">
                    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $editing_id ); ?>">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="amap-contract-product-label"><?php esc_html_e( 'Libellé', 'association-manager' ); ?></label></th>
                        <td><input type="text" id="amap-contract-product-label" name="label" value="<?php echo esc_attr( $contract_product_form_data['label'] ?? '' ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="amap-contract-product-price"><?php esc_html_e( 'Prix (€)', 'association-manager' ); ?></label></th>
                        <td><input type="number" id="amap-contract-product-price" name="price" min="0.01" step="0.01" value="<?php echo esc_attr( $contract_product_form_data['price'] ?? '' ); ?>" required></td>
                    </tr>
                </table>
                <p>
                    <?php submit_button( $product_editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                    <?php if ( $product_editing_id ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $editing_id . '&active_tab=products' ) ); ?>" class="button">
                            <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                        </a>
                    <?php else : ?>
                        <button type="button" class="button" id="amap-contract-product-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php endif; ?>
                </p>
            </form>
            </div>
            </div>
            </div>
            <script>
            ( function () {
                var toggle  = document.getElementById( 'amap-contract-product-add-toggle' );
                var wrapper = document.getElementById( 'amap-contract-product-form-wrapper' );
                var cancel  = document.getElementById( 'amap-contract-product-add-cancel' );
                if ( toggle ) {
                    toggle.addEventListener( 'click', function () {
                        wrapper.hidden = false;
                        toggle.hidden  = true;
                    } );
                }
                if ( cancel ) {
                    cancel.addEventListener( 'click', function () {
                        wrapper.hidden = true;
                        toggle.hidden  = false;
                    } );
                }
            } )();
            </script>

            <?php
            $delivery_dates     = amap_get_contract_delivery_dates( $editing_id );
            $weekday_labels     = amap_get_weekday_labels();
            $producer_groups    = amap_get_producer_groups( $editing_contract->producer_user_id );
            $producer_group_ids = array_map( 'absint', wp_list_pluck( $producer_groups, 'id' ) );

            if ( $generate_group_id && ! in_array( $generate_group_id, $producer_group_ids, true ) ) {
                $generate_group_id = 0;
            }
            $generate_group           = $generate_group_id ? amap_get_group( $generate_group_id ) : null;
            $generate_candidate_dates = array();
            if ( $generate_group ) {
                $all_weekday_dates        = amap_get_weekday_dates_in_range( $editing_contract->start_date, $editing_contract->end_date, (int) $generate_group->weekday );
                $existing_group_dates     = amap_get_contract_delivery_dates_for_group( $editing_id, $generate_group_id );
                $generate_candidate_dates = array_values( array_diff( $all_weekday_dates, $existing_group_dates ) );
            }
            ?>
            <div class="postbox amap-tab-panel" id="amap-tab-dates"<?php echo ( 'amap-tab-dates' === $active_contract_tab ) ? '' : ' hidden'; ?>>
            <div class="inside">

            <?php if ( empty( $producer_groups ) ) : ?>
                <p><?php esc_html_e( "Ce producteur n'est rattaché à aucun groupe de distribution. Rattachez-le d'abord à un groupe depuis la page Groupes avant d'ajouter des dates de livraison.", 'association-manager' ); ?></p>
            <?php else : ?>
                <?php if ( 'contract_delivery_date_invalid' === $notice ) : ?>
                    <div class="notice notice-error"><p><?php esc_html_e( 'Groupe ou date invalide.', 'association-manager' ); ?></p></div>
                <?php elseif ( 'contract_delivery_date_out_of_range' === $notice ) : ?>
                    <div class="notice notice-error"><p><?php esc_html_e( 'La date doit être comprise dans la période du contrat.', 'association-manager' ); ?></p></div>
                <?php elseif ( 'contract_delivery_date_duplicate' === $notice ) : ?>
                    <div class="notice notice-error"><p><?php esc_html_e( 'Cette date de livraison est déjà enregistrée pour ce groupe.', 'association-manager' ); ?></p></div>
                <?php elseif ( 'contract_delivery_date_saved' === $notice ) : ?>
                    <div class="notice notice-success"><p><?php esc_html_e( 'Date de livraison enregistrée.', 'association-manager' ); ?></p></div>
                <?php elseif ( 'contract_delivery_date_deleted' === $notice ) : ?>
                    <div class="notice notice-success"><p><?php esc_html_e( 'Date de livraison supprimée.', 'association-manager' ); ?></p></div>
                <?php elseif ( 'contract_delivery_dates_generated' === $notice ) : ?>
                    <?php $generated_count = isset( $_GET['generated_count'] ) ? absint( $_GET['generated_count'] ) : 0; ?>
                    <div class="notice notice-success"><p>
                        <?php
                        printf(
                            esc_html(
                                /* translators: %d: nombre de dates ajoutées. */
                                _n( '%d date de livraison ajoutée.', '%d dates de livraison ajoutées.', $generated_count, 'association-manager' )
                            ),
                            $generated_count
                        );
                        ?>
                    </p></div>
                <?php endif; ?>

                <?php if ( empty( $delivery_dates ) ) : ?>
                    <p><?php esc_html_e( 'Aucune date de livraison pour le moment.', 'association-manager' ); ?></p>
                <?php else : ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Date', 'association-manager' ); ?></th>
                                <th><?php esc_html_e( 'Groupe', 'association-manager' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $delivery_dates as $delivery_date_row ) : ?>
                                <?php $delivery_date_group = amap_get_group( $delivery_date_row->group_id ); ?>
                                <tr>
                                    <td><?php echo esc_html( $delivery_date_row->delivery_date ); ?></td>
                                    <td><?php echo esc_html( $delivery_date_group ? $delivery_date_group->name : '—' ); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $editing_id . '&date_action=edit&date_id=' . $delivery_date_row->id ) ); ?>">
                                            <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                        </a>
                                        |
                                        <?php
                                        $delete_date_url = wp_nonce_url(
                                            admin_url( 'admin-post.php?action=amap_delete_contract_delivery_date&id=' . $delivery_date_row->id ),
                                            'amap_delete_contract_delivery_date_' . $delivery_date_row->id
                                        );
                                        // translators: %s: date de livraison.
                                        $confirm_date_message = sprintf( __( 'Supprimer définitivement la date %s ?', 'association-manager' ), $delivery_date_row->delivery_date );
                                        ?>
                                        <a href="<?php echo esc_url( $delete_date_url ); ?>" onclick="return confirm( '<?php echo esc_js( $confirm_date_message ); ?>' );">
                                            <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h3><?php esc_html_e( 'Générer des dates', 'association-manager' ); ?></h3>
                <p><?php esc_html_e( 'Choisissez un groupe pour générer automatiquement toutes ses dates hebdomadaires sur la période du contrat :', 'association-manager' ); ?></p>
                <p>
                    <?php foreach ( $producer_groups as $group_option ) : ?>
                        <a class="button<?php echo ( (int) $group_option->id === $generate_group_id ) ? ' button-primary' : ''; ?>"
                           href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $editing_id . '&generate_group_id=' . $group_option->id ) ); ?>">
                            <?php echo esc_html( $group_option->name . ' — ' . $weekday_labels[ (int) $group_option->weekday ] ); ?>
                        </a>
                    <?php endforeach; ?>
                </p>

                <?php if ( $generate_group ) : ?>
                    <?php if ( empty( $generate_candidate_dates ) ) : ?>
                        <p><?php esc_html_e( 'Toutes les dates hebdomadaires de ce groupe sur la période du contrat sont déjà enregistrées.', 'association-manager' ); ?></p>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <?php wp_nonce_field( 'amap_generate_contract_delivery_dates_' . $editing_id . '_' . $generate_group_id ); ?>
                            <input type="hidden" name="action" value="amap_generate_contract_delivery_dates">
                            <input type="hidden" name="contract_id" value="<?php echo esc_attr( $editing_id ); ?>">
                            <input type="hidden" name="group_id" value="<?php echo esc_attr( $generate_group_id ); ?>">

                            <p>
                                <label>
                                    <?php esc_html_e( 'Cocher une date sur…', 'association-manager' ); ?>
                                    <input type="number" id="amap-generate-frequency" min="1" max="52" value="1">
                                </label>
                                <button type="button" class="button" id="amap-generate-apply-frequency"><?php esc_html_e( 'Appliquer', 'association-manager' ); ?></button>
                                <br><span class="description"><?php esc_html_e( '1 = toutes les dates (défaut), 2 = une sur deux, etc. Purement indicatif : décochez/recochez librement avant de valider.', 'association-manager' ); ?></span>
                            </p>

                            <?php foreach ( $generate_candidate_dates as $candidate_index => $candidate_date ) : ?>
                                <p>
                                    <label>
                                        <input type="checkbox" class="amap-generate-date-checkbox" data-index="<?php echo esc_attr( $candidate_index ); ?>" name="delivery_dates[]" value="<?php echo esc_attr( $candidate_date ); ?>" checked>
                                        <?php echo esc_html( date_i18n( 'l j F Y', strtotime( $candidate_date ) ) ); ?>
                                    </label>
                                </p>
                            <?php endforeach; ?>

                            <p><?php submit_button( __( 'Générer les dates cochées', 'association-manager' ), 'primary', 'submit', false ); ?></p>
                        </form>
                        <script>
                        ( function () {
                            var freqInput = document.getElementById( 'amap-generate-frequency' );
                            var applyBtn  = document.getElementById( 'amap-generate-apply-frequency' );
                            if ( ! freqInput || ! applyBtn ) {
                                return;
                            }
                            applyBtn.addEventListener( 'click', function () {
                                var frequency  = parseInt( freqInput.value, 10 ) || 1;
                                var checkboxes = document.querySelectorAll( '.amap-generate-date-checkbox' );
                                checkboxes.forEach( function ( checkbox ) {
                                    checkbox.checked = ( parseInt( checkbox.dataset.index, 10 ) % frequency === 0 );
                                } );
                            } );
                        } )();
                        </script>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ( ! $delivery_date_editing_id ) : ?>
                    <p>
                        <button type="button" class="button button-primary" id="amap-delivery-date-add-toggle"><?php esc_html_e( '+ Ajouter une date de livraison', 'association-manager' ); ?></button>
                    </p>
                <?php endif; ?>
                <div id="amap-delivery-date-form-wrapper"<?php echo $delivery_date_editing_id ? '' : ' hidden'; ?>>
                <h3>
                    <?php echo $delivery_date_editing_id
                        ? esc_html__( 'Modifier une date de livraison', 'association-manager' )
                        : esc_html__( 'Ajouter une date de livraison', 'association-manager' ); ?>
                </h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php if ( $delivery_date_editing_id ) : ?>
                        <?php wp_nonce_field( 'amap_edit_contract_delivery_date_' . $delivery_date_editing_id ); ?>
                        <input type="hidden" name="action" value="amap_update_contract_delivery_date">
                        <input type="hidden" name="id" value="<?php echo esc_attr( $delivery_date_editing_id ); ?>">
                    <?php else : ?>
                        <?php wp_nonce_field( 'amap_add_contract_delivery_date_' . $editing_id ); ?>
                        <input type="hidden" name="action" value="amap_add_contract_delivery_date">
                        <input type="hidden" name="contract_id" value="<?php echo esc_attr( $editing_id ); ?>">
                    <?php endif; ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="amap-delivery-date-group"><?php esc_html_e( 'Groupe', 'association-manager' ); ?></label></th>
                            <td>
                                <select id="amap-delivery-date-group" name="group_id" required>
                                    <option value=""></option>
                                    <?php foreach ( $producer_groups as $group_option ) : ?>
                                        <option value="<?php echo esc_attr( $group_option->id ); ?>" <?php selected( (string) $group_option->id, $contract_delivery_date_form_data['group_id'] ?? '' ); ?>>
                                            <?php echo esc_html( $group_option->name . ' — ' . $weekday_labels[ (int) $group_option->weekday ] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="amap-delivery-date-date"><?php esc_html_e( 'Date de livraison', 'association-manager' ); ?></label></th>
                            <td>
                                <input type="date" id="amap-delivery-date-date" name="delivery_date" min="<?php echo esc_attr( $editing_contract->start_date ); ?>" max="<?php echo esc_attr( $editing_contract->end_date ); ?>" value="<?php echo esc_attr( $contract_delivery_date_form_data['delivery_date'] ?? '' ); ?>" required>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: 1: date de début du contrat, 2: date de fin du contrat. */
                                        esc_html__( 'Doit être comprise entre le %1$s et le %2$s (période du contrat). Utile pour une date exceptionnelle qui ne correspond pas au jour habituel du groupe.', 'association-manager' ),
                                        esc_html( $editing_contract->start_date ),
                                        esc_html( $editing_contract->end_date )
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <?php submit_button( $delivery_date_editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                        <?php if ( $delivery_date_editing_id ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $editing_id . '&active_tab=dates' ) ); ?>" class="button">
                                <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                            </a>
                        <?php else : ?>
                            <button type="button" class="button" id="amap-delivery-date-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                        <?php endif; ?>
                    </p>
                </form>
                </div>
                <script>
                ( function () {
                    var toggle  = document.getElementById( 'amap-delivery-date-add-toggle' );
                    var wrapper = document.getElementById( 'amap-delivery-date-form-wrapper' );
                    var cancel  = document.getElementById( 'amap-delivery-date-add-cancel' );
                    if ( toggle ) {
                        toggle.addEventListener( 'click', function () {
                            wrapper.hidden = false;
                            toggle.hidden  = true;
                        } );
                    }
                    if ( cancel ) {
                        cancel.addEventListener( 'click', function () {
                            wrapper.hidden = true;
                            toggle.hidden  = false;
                        } );
                    }
                } )();
                </script>
            <?php endif; ?>
            </div>
            </div>
        <?php endif; ?>

        <?php if ( $editing_id && $editing_contract ) : ?>
            <script>
            ( function () {
                var tabWrapper = document.getElementById( 'amap-contract-tabs' );
                if ( ! tabWrapper ) {
                    return;
                }
                var tabs = tabWrapper.querySelectorAll( '.nav-tab[data-amap-tab]' );
                tabs.forEach( function ( tab ) {
                    tab.addEventListener( 'click', function ( event ) {
                        event.preventDefault();
                        tabs.forEach( function ( t ) {
                            t.classList.remove( 'nav-tab-active' );
                        } );
                        document.querySelectorAll( '.amap-tab-panel' ).forEach( function ( panel ) {
                            panel.hidden = true;
                        } );
                        tab.classList.add( 'nav-tab-active' );
                        document.getElementById( tab.dataset.amapTab ).hidden = false;
                    } );
                } );
            } )();
            </script>
        <?php endif; ?>

        <?php if ( ! $editing_id ) : ?>
            <?php if ( empty( $contracts ) ) : ?>
                <p><?php esc_html_e( 'Aucun contrat enregistré pour le moment.', 'association-manager' ); ?></p>
            <?php else : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Libellé', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Producteur', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Période', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Fréquence', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Actif', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $contracts as $contract ) : ?>
                            <?php $producer = get_user_by( 'id', $contract->producer_user_id ); ?>
                            <tr>
                                <td><?php echo esc_html( $contract->label ); ?></td>
                                <td><?php echo esc_html( $producer ? $producer->display_name : '—' ); ?></td>
                                <td><?php echo esc_html( $contract_types[ $contract->contract_type ] ?? $contract->contract_type ); ?></td>
                                <td><?php echo esc_html( $contract->start_date . ' → ' . $contract->end_date ); ?></td>
                                <td><?php echo esc_html( null !== $contract->frequency_weeks ? $contract->frequency_weeks : '—' ); ?></td>
                                <td><?php echo $contract->is_active ? esc_html__( 'Oui', 'association-manager' ) : esc_html__( 'Non', 'association-manager' ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract->id ) ); ?>">
                                        <?php esc_html_e( 'Voir', 'association-manager' ); ?>
                                    </a>
                                    |
                                    <?php
                                    $delete_url = wp_nonce_url(
                                        admin_url( 'admin-post.php?action=amap_delete_contract&id=' . $contract->id ),
                                        'amap_delete_contract_' . $contract->id
                                    );
                                    // translators: %s: libellé du contrat.
                                    $confirm_message = sprintf( __( 'Supprimer définitivement le contrat %s ?', 'association-manager' ), $contract->label );
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
        <?php endif; ?>
    </div>
    <?php
}

add_action( 'admin_post_amap_add_contract', 'amap_handle_add_contract' );

function amap_handle_add_contract() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_contract' );

    $label            = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $producer_user_id = isset( $_POST['producer_user_id'] ) ? absint( $_POST['producer_user_id'] ) : 0;
    $contract_type    = isset( $_POST['contract_type'] ) ? sanitize_key( wp_unslash( $_POST['contract_type'] ) ) : '';
    $start_date       = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
    $end_date         = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
    $frequency_weeks  = isset( $_POST['frequency_weeks'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency_weeks'] ) ) : '';
    $is_active        = isset( $_POST['is_active'] );
    $submitted        = compact( 'label', 'producer_user_id', 'contract_type', 'start_date', 'end_date', 'frequency_weeks', 'is_active' );

    $valid_producer_ids = wp_list_pluck( amap_get_producer_users(), 'ID' );

    if ( '' === $label || ! in_array( $producer_user_id, $valid_producer_ids, true )
        || ! array_key_exists( $contract_type, amap_get_contract_types() ) ) {
        amap_store_contract_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-contracts&amap_notice=invalid' ) );
        exit;
    }

    if ( ! amap_is_valid_date( $start_date ) || ! amap_is_valid_date( $end_date ) || $start_date >= $end_date ) {
        amap_store_contract_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-contracts&amap_notice=invalid_dates' ) );
        exit;
    }

    // frequency_weeks n'a de sens que pour un panier récurrent : obligatoire dans ce cas,
    // forcé à NULL sinon (même si le formulaire masque le champ en JS, on revalide côté serveur).
    if ( 'basket_recurring' === $contract_type ) {
        if ( '' === $frequency_weeks || ! ctype_digit( $frequency_weeks ) || (int) $frequency_weeks < 1 ) {
            amap_store_contract_form_data( $submitted );
            wp_safe_redirect( admin_url( 'admin.php?page=amap-contracts&amap_notice=invalid_frequency' ) );
            exit;
        }
        $frequency_weeks = (int) $frequency_weeks;
    } else {
        $frequency_weeks = null;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_contracts',
        array(
            'producer_user_id' => $producer_user_id,
            'contract_type'    => $contract_type,
            'label'            => $label,
            'start_date'       => $start_date,
            'end_date'         => $end_date,
            'frequency_weeks'  => $frequency_weeks,
            'is_active'        => $is_active ? 1 : 0,
        )
    );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-contracts' ) );
    exit;
}

add_action( 'admin_post_amap_update_contract', 'amap_handle_update_contract' );

function amap_handle_update_contract() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    if ( ! $id || ! amap_get_contract( $id ) ) {
        wp_die( esc_html__( 'Contrat introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_edit_contract_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $id );

    $label            = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $producer_user_id = isset( $_POST['producer_user_id'] ) ? absint( $_POST['producer_user_id'] ) : 0;
    $contract_type    = isset( $_POST['contract_type'] ) ? sanitize_key( wp_unslash( $_POST['contract_type'] ) ) : '';
    $start_date       = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
    $end_date         = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
    $frequency_weeks  = isset( $_POST['frequency_weeks'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency_weeks'] ) ) : '';
    $is_active        = isset( $_POST['is_active'] );
    $submitted        = compact( 'label', 'producer_user_id', 'contract_type', 'start_date', 'end_date', 'frequency_weeks', 'is_active' );

    $valid_producer_ids = wp_list_pluck( amap_get_producer_users(), 'ID' );

    if ( '' === $label || ! in_array( $producer_user_id, $valid_producer_ids, true )
        || ! array_key_exists( $contract_type, amap_get_contract_types() ) ) {
        amap_store_contract_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid' );
        exit;
    }

    if ( ! amap_is_valid_date( $start_date ) || ! amap_is_valid_date( $end_date ) || $start_date >= $end_date ) {
        amap_store_contract_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid_dates' );
        exit;
    }

    if ( 'basket_recurring' === $contract_type ) {
        if ( '' === $frequency_weeks || ! ctype_digit( $frequency_weeks ) || (int) $frequency_weeks < 1 ) {
            amap_store_contract_form_data( $submitted );
            wp_safe_redirect( $edit_url . '&amap_notice=invalid_frequency' );
            exit;
        }
        $frequency_weeks = (int) $frequency_weeks;
    } else {
        $frequency_weeks = null;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_contracts',
        array(
            'producer_user_id' => $producer_user_id,
            'contract_type'    => $contract_type,
            'label'            => $label,
            'start_date'       => $start_date,
            'end_date'         => $end_date,
            'frequency_weeks'  => $frequency_weeks,
            'is_active'        => $is_active ? 1 : 0,
        ),
        array( 'id' => $id )
    );

    wp_safe_redirect( $edit_url );
    exit;
}

add_action( 'admin_post_amap_delete_contract', 'amap_handle_delete_contract' );

function amap_handle_delete_contract() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    if ( ! $id || ! amap_get_contract( $id ) ) {
        wp_die( esc_html__( 'Contrat introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_contract_' . $id );

    global $wpdb;
    // Pas de contrainte FOREIGN KEY SQL sur contract_id (cohérent avec le reste du plugin) :
    // nettoyage explicite des tables filles orphelines (seules celles correspondant au
    // contract_type de ce contrat contiennent effectivement des lignes, les autres suppressions
    // ne font rien), comme les rattachements producteurs orphelins à la suppression d'un groupe.
    $wpdb->delete( $wpdb->prefix . 'amap_contract_basket_sizes', array( 'contract_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_contract_products', array( 'contract_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_contract_delivery_dates', array( 'contract_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_contracts', array( 'id' => $id ) );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-contracts' ) );
    exit;
}

add_action( 'admin_post_amap_add_contract_basket_size', 'amap_handle_add_contract_basket_size' );

function amap_handle_add_contract_basket_size() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $contract_id = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $contract    = $contract_id ? amap_get_contract( $contract_id ) : null;
    if ( ! $contract || 'basket_recurring' !== $contract->contract_type ) {
        wp_die( esc_html__( 'Contrat introuvable ou non concerné par les tailles de panier.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_contract_basket_size_' . $contract_id );

    $edit_url = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=sizes' );

    $label     = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price     = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $submitted = compact( 'label', 'price' );

    if ( '' === $label || ! amap_is_valid_price( $price ) ) {
        amap_store_contract_basket_size_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=basket_size_invalid' );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_contract_basket_sizes',
        array(
            'contract_id' => $contract_id,
            'label'       => $label,
            'price'       => (float) $price,
        )
    );

    wp_safe_redirect( $edit_url . '&amap_notice=basket_size_saved' );
    exit;
}

add_action( 'admin_post_amap_update_contract_basket_size', 'amap_handle_update_contract_basket_size' );

function amap_handle_update_contract_basket_size() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id          = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $basket_size = $id ? amap_get_contract_basket_size( $id ) : null;
    if ( ! $basket_size ) {
        wp_die( esc_html__( 'Taille de panier introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_edit_contract_basket_size_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $basket_size->contract_id . '&active_tab=sizes' );

    $label     = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price     = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $submitted = compact( 'label', 'price' );

    if ( '' === $label || ! amap_is_valid_price( $price ) ) {
        amap_store_contract_basket_size_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&size_action=edit&size_id=' . $id . '&amap_notice=basket_size_invalid' );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_contract_basket_sizes',
        array(
            'label' => $label,
            'price' => (float) $price,
        ),
        array( 'id' => $id )
    );

    wp_safe_redirect( $edit_url . '&amap_notice=basket_size_saved' );
    exit;
}

add_action( 'admin_post_amap_delete_contract_basket_size', 'amap_handle_delete_contract_basket_size' );

function amap_handle_delete_contract_basket_size() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id          = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $basket_size = $id ? amap_get_contract_basket_size( $id ) : null;
    if ( ! $basket_size ) {
        wp_die( esc_html__( 'Taille de panier introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_contract_basket_size_' . $id );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_contract_basket_sizes', array( 'id' => $id ) );

    wp_safe_redirect(
        admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $basket_size->contract_id . '&active_tab=sizes&amap_notice=basket_size_deleted' )
    );
    exit;
}

add_action( 'admin_post_amap_add_contract_product', 'amap_handle_add_contract_product' );

function amap_handle_add_contract_product() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $contract_id = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $contract    = $contract_id ? amap_get_contract( $contract_id ) : null;
    if ( ! $contract || 'product_grid' !== $contract->contract_type ) {
        wp_die( esc_html__( 'Contrat introuvable ou non concerné par le catalogue produits.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_contract_product_' . $contract_id );

    $edit_url = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=products' );

    $label     = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price     = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $submitted = compact( 'label', 'price' );

    if ( '' === $label || ! amap_is_valid_price( $price ) ) {
        amap_store_contract_product_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=contract_product_invalid' );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_contract_products',
        array(
            'contract_id' => $contract_id,
            'label'       => $label,
            'price'       => (float) $price,
        )
    );

    wp_safe_redirect( $edit_url . '&amap_notice=contract_product_saved' );
    exit;
}

add_action( 'admin_post_amap_update_contract_product', 'amap_handle_update_contract_product' );

function amap_handle_update_contract_product() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $product = $id ? amap_get_contract_product( $id ) : null;
    if ( ! $product ) {
        wp_die( esc_html__( 'Produit introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_edit_contract_product_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $product->contract_id . '&active_tab=products' );

    $label     = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price     = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $submitted = compact( 'label', 'price' );

    if ( '' === $label || ! amap_is_valid_price( $price ) ) {
        amap_store_contract_product_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&product_action=edit&product_id=' . $id . '&amap_notice=contract_product_invalid' );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_contract_products',
        array(
            'label' => $label,
            'price' => (float) $price,
        ),
        array( 'id' => $id )
    );

    wp_safe_redirect( $edit_url . '&amap_notice=contract_product_saved' );
    exit;
}

add_action( 'admin_post_amap_delete_contract_product', 'amap_handle_delete_contract_product' );

function amap_handle_delete_contract_product() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $product = $id ? amap_get_contract_product( $id ) : null;
    if ( ! $product ) {
        wp_die( esc_html__( 'Produit introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_contract_product_' . $id );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_contract_products', array( 'id' => $id ) );

    wp_safe_redirect(
        admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $product->contract_id . '&active_tab=products&amap_notice=contract_product_deleted' )
    );
    exit;
}

add_action( 'admin_post_amap_add_contract_delivery_date', 'amap_handle_add_contract_delivery_date' );

function amap_handle_add_contract_delivery_date() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $contract_id = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $contract    = $contract_id ? amap_get_contract( $contract_id ) : null;
    if ( ! $contract || 'product_grid' !== $contract->contract_type ) {
        wp_die( esc_html__( 'Contrat introuvable ou non concerné par les dates de livraison.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_contract_delivery_date_' . $contract_id );

    $edit_url           = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=dates' );
    $group_id           = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $delivery_date      = isset( $_POST['delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_date'] ) ) : '';
    $producer_group_ids = array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) );

    if ( ! $group_id || ! in_array( $group_id, $producer_group_ids, true ) || ! amap_is_valid_date( $delivery_date ) ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( $edit_url . '&amap_notice=contract_delivery_date_invalid' );
        exit;
    }

    if ( $delivery_date < $contract->start_date || $delivery_date > $contract->end_date ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( $edit_url . '&amap_notice=contract_delivery_date_out_of_range' );
        exit;
    }

    if ( amap_contract_has_delivery_date( $contract_id, $group_id, $delivery_date ) ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( $edit_url . '&amap_notice=contract_delivery_date_duplicate' );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_contract_delivery_dates',
        array(
            'contract_id'   => $contract_id,
            'group_id'      => $group_id,
            'delivery_date' => $delivery_date,
        )
    );

    wp_safe_redirect( $edit_url . '&amap_notice=contract_delivery_date_saved' );
    exit;
}

add_action( 'admin_post_amap_update_contract_delivery_date', 'amap_handle_update_contract_delivery_date' );

function amap_handle_update_contract_delivery_date() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id           = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $delivery_row = $id ? amap_get_contract_delivery_date( $id ) : null;
    if ( ! $delivery_row ) {
        wp_die( esc_html__( 'Date de livraison introuvable.', 'association-manager' ) );
    }

    $contract = amap_get_contract( $delivery_row->contract_id );
    if ( ! $contract ) {
        wp_die( esc_html__( 'Contrat introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_edit_contract_delivery_date_' . $id );

    $edit_url           = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $delivery_row->contract_id . '&active_tab=dates' );
    $group_id           = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $delivery_date      = isset( $_POST['delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_date'] ) ) : '';
    $producer_group_ids = array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) );

    if ( ! $group_id || ! in_array( $group_id, $producer_group_ids, true ) || ! amap_is_valid_date( $delivery_date ) ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( $edit_url . '&date_action=edit&date_id=' . $id . '&amap_notice=contract_delivery_date_invalid' );
        exit;
    }

    if ( $delivery_date < $contract->start_date || $delivery_date > $contract->end_date ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( $edit_url . '&date_action=edit&date_id=' . $id . '&amap_notice=contract_delivery_date_out_of_range' );
        exit;
    }

    if ( amap_contract_has_delivery_date( $delivery_row->contract_id, $group_id, $delivery_date, $id ) ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( $edit_url . '&date_action=edit&date_id=' . $id . '&amap_notice=contract_delivery_date_duplicate' );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_contract_delivery_dates',
        array(
            'group_id'      => $group_id,
            'delivery_date' => $delivery_date,
        ),
        array( 'id' => $id )
    );

    wp_safe_redirect( $edit_url . '&amap_notice=contract_delivery_date_saved' );
    exit;
}

add_action( 'admin_post_amap_delete_contract_delivery_date', 'amap_handle_delete_contract_delivery_date' );

function amap_handle_delete_contract_delivery_date() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id           = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $delivery_row = $id ? amap_get_contract_delivery_date( $id ) : null;
    if ( ! $delivery_row ) {
        wp_die( esc_html__( 'Date de livraison introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_contract_delivery_date_' . $id );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_contract_delivery_dates', array( 'id' => $id ) );

    wp_safe_redirect(
        admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $delivery_row->contract_id . '&active_tab=dates&amap_notice=contract_delivery_date_deleted' )
    );
    exit;
}

add_action( 'admin_post_amap_generate_contract_delivery_dates', 'amap_handle_generate_contract_delivery_dates' );

/**
 * Insertion en masse depuis la section "Générer des dates" (amap_render_contracts_page()).
 * Défense en profondeur : ne fait confiance à aucune date cochée soumise. Chaque date reçue est
 * revérifiée (format, période du contrat, jour de semaine du groupe, absence de doublon) — une
 * date qui échoue une de ces vérifications est simplement ignorée, un doublon n'étant pas une
 * erreur ici (l'utilisateur a pu régénérer après avoir déjà ajouté certaines dates à la main).
 */
function amap_handle_generate_contract_delivery_dates() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $contract_id = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $contract    = $contract_id ? amap_get_contract( $contract_id ) : null;
    if ( ! $contract || 'product_grid' !== $contract->contract_type ) {
        wp_die( esc_html__( 'Contrat introuvable ou non concerné par les dates de livraison.', 'association-manager' ) );
    }

    $group_id           = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $producer_group_ids = array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) );
    $group              = ( $group_id && in_array( $group_id, $producer_group_ids, true ) ) ? amap_get_group( $group_id ) : null;
    if ( ! $group ) {
        wp_die( esc_html__( 'Groupe introuvable ou non rattaché à ce producteur.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_generate_contract_delivery_dates_' . $contract_id . '_' . $group_id );

    $edit_url        = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id );
    $submitted_dates = isset( $_POST['delivery_dates'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['delivery_dates'] ) ) : array();
    $target_weekday  = (int) $group->weekday + 1; // amap : 0=lundi..6=dimanche ; DateTime::format('N') : 1=lundi..7=dimanche.

    global $wpdb;
    $inserted_count = 0;

    foreach ( $submitted_dates as $delivery_date ) {
        if ( ! amap_is_valid_date( $delivery_date ) ) {
            continue;
        }
        if ( $delivery_date < $contract->start_date || $delivery_date > $contract->end_date ) {
            continue;
        }
        if ( (int) ( new DateTime( $delivery_date ) )->format( 'N' ) !== $target_weekday ) {
            continue;
        }
        if ( amap_contract_has_delivery_date( $contract_id, $group_id, $delivery_date ) ) {
            continue;
        }

        $wpdb->insert(
            $wpdb->prefix . 'amap_contract_delivery_dates',
            array(
                'contract_id'   => $contract_id,
                'group_id'      => $group_id,
                'delivery_date' => $delivery_date,
            )
        );
        ++$inserted_count;
    }

    wp_safe_redirect( $edit_url . '&generate_group_id=' . $group_id . '&amap_notice=contract_delivery_dates_generated&generated_count=' . $inserted_count );
    exit;
}
