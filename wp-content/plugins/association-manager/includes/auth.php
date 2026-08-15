<?php
/**
 * Connexion par lien magique ou mot de passe, réinitialisation de mot de passe (front-end).
 */

if ( ! defined( 'ABSPATH' ) ) {
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
 * Limite le nombre d'envois d'email déclenchés par un visiteur non authentifié à partir d'une
 * simple adresse email saisie (amap_handle_login_email_step(),
 * amap_handle_request_password_reset()) : ces deux actions sont volontairement publiques, sans
 * nonce (voir leur documentation), donc sans autre garde-fou contre un envoi en masse vers une
 * adresse arbitraire. Compteur par fenêtre fixe (pas une fenêtre glissante exacte) : $key doit
 * déjà inclure l'action et l'email pour ne pas mélanger les deux compteurs. Volontairement par
 * email seul, pas par IP : protège chaque adhérent d'un spam ciblé sur sa propre adresse, sans
 * risquer de bloquer plusieurs adhérents partageant une même IP (réseau associatif, box
 * familiale).
 */
function amap_is_rate_limited( $key, $max_attempts, $window_seconds ) {
    $transient_key = 'amap_rate_limit_' . md5( $key );
    $attempts      = (int) get_transient( $transient_key );

    if ( $attempts >= $max_attempts ) {
        return true;
    }

    set_transient( $transient_key, $attempts + 1, $window_seconds );

    return false;
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

/**
 * URL d'un onglet de l'espace membre (member-area-nav.php). $tab n'est jamais affiché tel quel :
 * amap_maybe_render_member_area() le revalide contre la liste des onglets accessibles à
 * l'utilisateur avant de choisir le template-part à charger.
 */
function amap_get_member_area_tab_url( $tab ) {
    return add_query_arg( 'amap_tab', $tab, amap_get_member_area_url() );
}

/**
 * URL du formulaire de souscription à un contrat donné (member-area-subscribe.php), depuis le
 * bouton "Souscrire" de l'onglet "Espace adhérent".
 */
function amap_get_member_subscribe_url( $contract_id ) {
    return add_query_arg(
        array(
            'amap_member_action' => 'subscribe',
            'contract_id'        => $contract_id,
        ),
        amap_get_member_area_url()
    );
}

/**
 * URL du formulaire de déclaration d'un congé pour une souscription donnée
 * (member-area-leave.php), depuis le lien "Déclarer un congé" de l'onglet "Espace adhérent".
 */
function amap_get_member_leave_url( $subscription_id ) {
    return add_query_arg(
        array(
            'amap_member_action' => 'declare_leave',
            'subscription_id'    => $subscription_id,
        ),
        amap_get_member_area_url()
    );
}

/**
 * URL de l'export CSV du pointage des adhérents d'un contrat basket_recurring sur un groupe
 * donné (bouton "Détail" de la carte "Produits à livrer", onglet "Espace producteur", étape
 * 12.4) — amap_handle_export_contract_roster() envoie directement le fichier, jamais de page.
 */
function amap_get_contract_roster_export_url( $contract_id, $group_id ) {
    return add_query_arg(
        array(
            'amap_member_action' => 'export_contract_roster',
            'contract_id'        => $contract_id,
            'group_id'           => $group_id,
        ),
        amap_get_member_area_url()
    );
}

/**
 * URL de l'export CSV des commandes nominatives d'un contrat product_grid pour une distribution
 * donnée (bouton "Détail (CSV)" de la carte "Produits à livrer", onglet "Espace producteur") —
 * amap_handle_export_contract_products() envoie directement le fichier, jamais de page.
 */
function amap_get_contract_products_export_url( $contract_id, $group_id, $distribution_date ) {
    return add_query_arg(
        array(
            'amap_member_action' => 'export_contract_products',
            'contract_id'        => $contract_id,
            'group_id'           => $group_id,
            'distribution_date'  => $distribution_date,
        ),
        amap_get_member_area_url()
    );
}

/**
 * URL de l'export CSV du résumé de saison d'un contrat pour un groupe donné (nom, téléphone,
 * quantités/paniers facturés, montant dû sur toute la période) — contrairement aux exports
 * "Feuille de présence"/"Commandes" (fenêtre ou date unique), couvre toute la durée du contrat.
 * amap_handle_export_contract_season_summary() envoie directement le fichier, jamais de page.
 */
function amap_get_contract_season_summary_export_url( $contract_id, $group_id ) {
    return add_query_arg(
        array(
            'amap_member_action' => 'export_contract_season_summary',
            'contract_id'        => $contract_id,
            'group_id'           => $group_id,
        ),
        amap_get_member_area_url()
    );
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

    $subject   = __( 'Votre lien de connexion AMAP', 'association-manager' );
    $body_html = '<p>' . esc_html__( 'Cliquez sur le bouton ci-dessous pour vous connecter à votre espace.', 'association-manager' ) . '</p>';
    $html_body = amap_render_email( $subject, $body_html, $link, __( 'Se connecter', 'association-manager' ) );

    return amap_send_email( $user->user_email, $subject, $html_body );
}

/**
 * Envoie l'email de réinitialisation de mot de passe pour un compte producteur/bureau. Jeton de
 * purpose 'password_reset' : amap_handle_confirm_magic_link() devra distinguer ce cas de la
 * connexion normale avant d'ouvrir une session (étape suivante, pas encore traitée).
 */
function amap_send_password_reset_link( $user ) {
    $token = amap_create_magic_link_token( $user->ID, 'password_reset' );
    $link  = amap_get_magic_link_url( $token );

    $subject   = __( 'Réinitialisation de votre mot de passe AMAP', 'association-manager' );
    $body_html = '<p>' . esc_html__( 'Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe.', 'association-manager' ) . '</p>';
    $html_body = amap_render_email( $subject, $body_html, $link, __( 'Choisir un nouveau mot de passe', 'association-manager' ) );

    return amap_send_email( $user->user_email, $subject, $html_body );
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

    get_header( 'auth' );
    ?>
    <main class="amap-auth-main">
    <div class="amap-auth-card">
    <?php get_template_part( 'template-parts/login/auth-brand' ); ?>
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
    </div>
    </main>
    <?php
    get_footer( 'auth' );
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

    wp_safe_redirect( amap_get_member_area_url() );
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

    get_header( 'auth' );
    ?>
    <main class="amap-auth-main">
    <div class="amap-auth-card">
    <?php get_template_part( 'template-parts/login/auth-brand' ); ?>
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
    </div>
    </main>
    <?php
    get_footer( 'auth' );
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

    get_header( 'auth' );
    ?>
    <main class="amap-auth-main">
    <div class="amap-auth-card">
    <?php get_template_part( 'template-parts/login/auth-brand' ); ?>
    <?php
    get_template_part( 'template-parts/login/step', 'email', array( 'has_error' => ( 'invalid_email' === $step ) ) );
    ?>
    </div>
    </main>
    <?php
    get_footer( 'auth' );
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
        // Rate-limité par email (amap_is_rate_limited()) : au-delà du seuil, l'envoi est
        // silencieusement sauté mais la redirection reste identique, pour ne rien laisser
        // deviner côté visiteur.
        if ( ! amap_is_rate_limited( 'login_email:' . $email, 3, 15 * MINUTE_IN_SECONDS ) ) {
            $user = get_user_by( 'email', $email );
            amap_send_magic_link( $user );
        }
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

    get_header( 'auth' );
    ?>
    <main class="amap-auth-main">
    <div class="amap-auth-card">
    <?php get_template_part( 'template-parts/login/auth-brand' ); ?>
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
    </div>
    </main>
    <?php
    get_footer( 'auth' );
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

    $demo_steps = array( 'magic_link_sent', 'password_reset_sent' );
    $demo_email = in_array( $step, $demo_steps, true ) ? amap_get_demo_last_email() : false;

    get_header( 'auth' );
    ?>
    <main class="amap-auth-main">
    <div class="amap-auth-card">
    <?php get_template_part( 'template-parts/login/auth-brand' ); ?>
    <?php
    get_template_part(
        'template-parts/login/step',
        'message',
        array(
            'message'          => $messages[ $step ],
            'show_login_link'  => ( 'password_reset_done' === $step ),
            'demo_email'       => $demo_email,
        )
    );
    ?>
    </div>
    </main>
    <?php
    get_footer( 'auth' );
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

    // Rate-limité par email (amap_is_rate_limited()), même principe que
    // amap_handle_login_email_step() : au-delà du seuil, l'envoi est silencieusement sauté sans
    // changer la redirection.
    if ( '' !== $email && is_email( $email ) && ! amap_is_rate_limited( 'password_reset:' . $email, 3, 15 * MINUTE_IN_SECONDS ) ) {
        $user = get_user_by( 'email', $email );

        if ( $user && ! amap_user_uses_magic_link( $user ) ) {
            amap_send_password_reset_link( $user );
        }
    }

    wp_safe_redirect( add_query_arg( 'amap_login_step', 'password_reset_sent', amap_get_member_area_url() ) );
    exit;
}
