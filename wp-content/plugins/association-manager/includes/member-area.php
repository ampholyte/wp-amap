<?php
/**
 * Espace adhérent front-end : affichage des casquettes et édition du profil.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'show_admin_bar', 'amap_hide_admin_bar_on_member_area' );

/**
 * La coquille "app" de l'espace membre (header-app.php) ne laisse aucune place à la barre
 * d'outils WordPress : sa police/ses interlignes (propres au cœur WordPress, pas au thème) la
 * font ressortir au-dessus de la barre d'identité de l'espace membre. is_page() n'est pas encore
 * fiable ici (le filtre 'show_admin_bar' est appliqué une seule fois, mis en cache dès 'init' —
 * donc avant amap_maybe_render_member_area(), qui tourne sur template_redirect) : on regarde
 * directement l'URL plutôt que d'attendre que la requête principale soit résolue.
 */
function amap_hide_admin_bar_on_member_area( $show ) {
    if ( false !== strpos( $_SERVER['REQUEST_URI'] ?? '', '/espace-adherent' ) ) {
        return false;
    }

    return $show;
}

add_action( 'admin_bar_menu', 'amap_add_member_area_admin_bar_link', 100 );

/**
 * Lien retour vers l'espace membre front dans la barre d'outils WordPress, visible partout
 * (y compris en wp-admin) — pendant réciproque du lien "Espace bureau" (member-area-nav.php, qui
 * va de l'espace membre vers wp-admin) : un bureau cumule très souvent aussi la casquette
 * adhérent ou producteur, et navigue entre les deux univers. N'apparaît jamais sur la page
 * "Espace adhérent" elle-même, dont la barre d'outils est déjà entièrement masquée (voir
 * amap_hide_admin_bar_on_member_area() ci-dessus).
 */
function amap_add_member_area_admin_bar_link( $wp_admin_bar ) {
    $user = wp_get_current_user();
    if ( ! array_intersect( array( 'amap_member', 'amap_producer', 'amap_board' ), (array) $user->roles ) ) {
        return;
    }

    $wp_admin_bar->add_node(
        array(
            'id'    => 'amap-member-area',
            'title' => esc_html__( 'Mon espace membre', 'association-manager' ),
            'href'  => amap_get_member_area_url(),
        )
    );
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
    // La barre d'outils WordPress est entièrement masquée sur cette page (amap_hide_admin_bar_on_member_area()) :
    // un administrateur WordPress n'a donc plus aucun moyen de revenir vers wp-admin depuis ici,
    // d'où ce badge dédié dans la nav (member-area-nav.php), réservé à ce rôle précis.
    $is_wp_admin      = current_user_can( 'manage_options' );
    $action           = isset( $_GET['amap_member_action'] ) ? sanitize_key( wp_unslash( $_GET['amap_member_action'] ) ) : '';
    $notice           = isset( $_GET['amap_member_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_member_notice'] ) ) : '';

    // Un onglet par casquette portée (dans cet ordre de priorité par défaut), plus "profile"
    // toujours accessible dès qu'au moins une casquette AMAP est portée. "Espace bureau" migre
    // progressivement de wp-admin vers cet onglet, section par section (voir
    // member-area-board.php) : pour l'instant seule "Utilisateurs" y a du contenu réel, les 3
    // autres sections restent des liens directs vers wp-admin le temps d'être migrées à leur tour.
    $available_tabs = array();
    if ( $is_member ) {
        $available_tabs[] = 'member';
    }
    if ( $is_producer ) {
        $available_tabs[] = 'producer';
    }
    if ( $can_manage_users ) {
        $available_tabs[] = 'board';
    }
    if ( $is_amap_user ) {
        $available_tabs[] = 'profile';
    }
    $requested_tab = isset( $_GET['amap_tab'] ) ? sanitize_key( wp_unslash( $_GET['amap_tab'] ) ) : '';
    $tab           = in_array( $requested_tab, $available_tabs, true ) ? $requested_tab : reset( $available_tabs );

    $can_manage_subscriptions = current_user_can( 'amap_manage_subscriptions' );
    $can_manage_groups        = current_user_can( 'amap_manage_groups' );
    $can_manage_contracts     = current_user_can( 'amap_manage_contracts' );

    // Sous-section de l'onglet "Espace bureau" ("Utilisateurs"/"Souscriptions"/"Groupes"/"Contrats")
    // et action éventuelle dessus (ajout/modification/suppression, chacune sur sa propre page
    // plutôt qu'un panneau repliable — voir project_espace_bureau_design_consolide).
    $board_section = isset( $_GET['amap_board_section'] ) ? sanitize_key( wp_unslash( $_GET['amap_board_section'] ) ) : 'users';
    if ( ! in_array( $board_section, array( 'users', 'subscriptions', 'groups', 'contracts' ), true ) ) {
        $board_section = 'users';
    }
    $board_action = isset( $_GET['amap_board_action'] ) ? sanitize_key( wp_unslash( $_GET['amap_board_action'] ) ) : '';

    $board_user_form_data = null;
    if ( $can_manage_users && 'board' === $tab && 'users' === $board_section && in_array( $board_action, array( 'add_user', 'edit_user' ), true ) ) {
        $board_user_form_data = amap_get_board_user_form_data( 'edit_user' === $board_action ? absint( $_GET['id'] ?? 0 ) : 0 );
    }

    $board_user_delete_data = null;
    if ( $can_manage_users && 'board' === $tab && 'users' === $board_section && 'delete_user' === $board_action ) {
        $board_user_delete_data = amap_get_board_user_delete_data( absint( $_GET['id'] ?? 0 ) );
    }

    // Fiche producteur en lecture seule : rattachée à la section "Utilisateurs" (même capability),
    // mais atteignable aussi depuis un lien sur la fiche groupe (amap_get_board_producer_profile_url()) —
    // $board_section n'a donc pas besoin de valoir 'users' ici, contrairement aux autres actions de
    // cette section.
    $board_producer_profile_data = null;
    if ( $can_manage_users && 'board' === $tab && 'view_producer_profile' === $board_action ) {
        $board_producer_profile_data = amap_get_board_producer_profile_data( absint( $_GET['id'] ?? 0 ) );
    }

    $board_subscription_form_data = null;
    if ( $can_manage_subscriptions && 'board' === $tab && 'subscriptions' === $board_section && in_array( $board_action, array( 'add_subscription', 'edit_subscription' ), true ) ) {
        $board_subscription_form_data = amap_get_board_subscription_form_data( 'edit_subscription' === $board_action ? absint( $_GET['id'] ?? 0 ) : 0 );
    }

    $board_subscription_delete_data = null;
    if ( $can_manage_subscriptions && 'board' === $tab && 'subscriptions' === $board_section && 'delete_subscription' === $board_action ) {
        $board_subscription_delete_data = amap_get_board_subscription_delete_data( absint( $_GET['id'] ?? 0 ) );
    }

    $board_group_form_data = null;
    if ( $can_manage_groups && 'board' === $tab && 'groups' === $board_section && in_array( $board_action, array( 'add_group', 'edit_group' ), true ) ) {
        $board_group_form_data = amap_get_board_group_form_data( 'edit_group' === $board_action ? absint( $_GET['id'] ?? 0 ) : 0 );
    }

    $board_group_delete_data = null;
    if ( $can_manage_groups && 'board' === $tab && 'groups' === $board_section && 'delete_group' === $board_action ) {
        $board_group_delete_data = amap_get_board_group_delete_data( absint( $_GET['id'] ?? 0 ) );
    }

    $board_group_view_data = null;
    if ( $can_manage_groups && 'board' === $tab && 'groups' === $board_section && 'view_group' === $board_action ) {
        $board_group_view_data = amap_get_board_group_view_data( absint( $_GET['id'] ?? 0 ) );
    }

    $board_contract_form_data = null;
    if ( $can_manage_contracts && 'board' === $tab && 'contracts' === $board_section && in_array( $board_action, array( 'add_contract', 'edit_contract' ), true ) ) {
        $board_contract_form_data = amap_get_board_contract_form_data( 'edit_contract' === $board_action ? absint( $_GET['id'] ?? 0 ) : 0 );
    }

    $board_contract_delete_data = null;
    if ( $can_manage_contracts && 'board' === $tab && 'contracts' === $board_section && 'delete_contract' === $board_action ) {
        $board_contract_delete_data = amap_get_board_contract_delete_data( absint( $_GET['id'] ?? 0 ) );
    }

    $board_contract_view_data = null;
    if ( $can_manage_contracts && 'board' === $tab && 'contracts' === $board_section && 'view_contract' === $board_action ) {
        $board_contract_view_data = amap_get_board_contract_view_data( absint( $_GET['id'] ?? 0 ) );
    }

    // Le formulaire de souscription valide (et wp_die()/redirige si besoin) AVANT tout affichage
    // — même principe que amap_maybe_render_magic_link_confirmation() — pour ne jamais laisser
    // échapper un en-tête de page avant un wp_die() ou une redirection.
    $subscribe_form_data = null;
    if ( $is_member && 'subscribe' === $action ) {
        $subscribe_form_data = amap_get_member_subscribe_form_data( $user );
    }

    $leave_form_data = null;
    if ( $is_member && 'declare_leave' === $action ) {
        $leave_form_data = amap_get_member_leave_form_data( $user );
    }

    // Export CSV : jamais de page à rendre, amap_handle_export_contract_roster() /
    // amap_handle_export_contract_products() / amap_handle_export_contract_season_summary()
    // envoient le fichier et terminent la requête elles-mêmes — doit donc s'exécuter avant
    // get_header().
    if ( $is_producer && 'export_contract_roster' === $action ) {
        amap_handle_export_contract_roster( $user );
    }

    if ( $is_producer && 'export_contract_products' === $action ) {
        amap_handle_export_contract_products( $user );
    }

    if ( $is_producer && 'export_contract_season_summary' === $action ) {
        amap_handle_export_contract_season_summary( $user );
    }

    get_header( 'app' );
    ?>
    <main>
    <?php
    if ( $is_amap_user && 'edit_profile' === $action ) {
        amap_render_member_profile_edit_form( $user );
    } elseif ( $subscribe_form_data ) {
        get_template_part( 'template-parts/login/member-area-subscribe', null, $subscribe_form_data );
    } elseif ( $leave_form_data ) {
        get_template_part( 'template-parts/login/member-area-leave', null, $leave_form_data );
    } elseif ( $board_user_form_data ) {
        get_template_part( 'template-parts/login/member-area-board-user-form', null, $board_user_form_data );
    } elseif ( $board_user_delete_data ) {
        get_template_part( 'template-parts/login/member-area-board-user-delete', null, $board_user_delete_data );
    } elseif ( $board_producer_profile_data ) {
        get_template_part( 'template-parts/login/member-area-board-producer-profile', null, $board_producer_profile_data );
    } elseif ( $board_subscription_form_data ) {
        get_template_part( 'template-parts/login/member-area-board-subscription-form', null, $board_subscription_form_data );
    } elseif ( $board_subscription_delete_data ) {
        get_template_part( 'template-parts/login/member-area-board-subscription-delete', null, $board_subscription_delete_data );
    } elseif ( $board_group_form_data ) {
        get_template_part( 'template-parts/login/member-area-board-group-form', null, $board_group_form_data );
    } elseif ( $board_group_delete_data ) {
        get_template_part( 'template-parts/login/member-area-board-group-delete', null, $board_group_delete_data );
    } elseif ( $board_group_view_data ) {
        get_template_part( 'template-parts/login/member-area-board-group-view', null, $board_group_view_data );
    } elseif ( $board_contract_form_data ) {
        get_template_part( 'template-parts/login/member-area-board-contract-form', null, $board_contract_form_data );
    } elseif ( $board_contract_delete_data ) {
        get_template_part( 'template-parts/login/member-area-board-contract-delete', null, $board_contract_delete_data );
    } elseif ( $board_contract_view_data ) {
        get_template_part( 'template-parts/login/member-area-board-contract-view', null, $board_contract_view_data );
    } else {
        get_template_part(
            'template-parts/login/member-area',
            null,
            array(
                'is_member'            => $is_member,
                'is_producer'          => $is_producer,
                'is_board'             => $is_board,
                'is_amap_user'         => $is_amap_user,
                'can_manage_users'     => $can_manage_users,
                'can_manage_groups'    => $can_manage_groups,
                'can_manage_contracts' => $can_manage_contracts,
                'is_wp_admin'          => $is_wp_admin,
                'tab'                  => $tab,
                'board_section'        => $board_section,
                'notice'               => $notice,
            )
        );
    }
    ?>
    </main>
    <?php
    get_footer( 'app' );
    exit;
}

/**
 * Utilisateurs AMAP pour la section "Utilisateurs" de l'onglet "Espace bureau"
 * (member-area-board-users.php) — même requête que Amap_Users_List_Table::prepare_items() côté
 * wp-admin, réécrite sans WP_List_Table (réservée à wp-admin). Pas de tri par colonne cliquable
 * ici (la maquette n'en prévoit pas) : toujours trié par nom de famille.
 */
function amap_get_board_users_list_data() {
    $per_page     = 20;
    $current_page = max( 1, isset( $_GET['amap_board_page'] ) ? absint( $_GET['amap_board_page'] ) : 1 );
    $search       = isset( $_GET['amap_board_search'] ) ? sanitize_text_field( wp_unslash( $_GET['amap_board_search'] ) ) : '';

    $query_args = array(
        'role__in' => array( 'amap_member', 'amap_producer', 'amap_board' ),
        'number'   => $per_page,
        'paged'    => $current_page,
        'orderby'  => 'meta_value',
        'meta_key' => 'last_name',
        'order'    => 'ASC',
    );

    if ( '' !== $search ) {
        $query_args['search']         = '*' . $search . '*';
        $query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
    }

    $user_query = new WP_User_Query( $query_args );
    $total      = $user_query->get_total();

    return array(
        'users'        => $user_query->get_results(),
        'total'        => $total,
        'per_page'     => $per_page,
        'current_page' => $current_page,
        'total_pages'  => (int) ceil( $total / $per_page ),
        'search'       => $search,
        'notice'       => isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '',
    );
}

/**
 * Données du formulaire "Ajouter"/"Modifier un utilisateur" (member-area-board-user-form.php),
 * section "Utilisateurs" de l'espace bureau — même logique de préremplissage que
 * amap_render_users_page() côté wp-admin (transient d'erreur en priorité, sinon valeurs
 * actuelles en modification, sinon vide en création). $editing_id à 0 signifie "Ajouter".
 */
function amap_get_board_user_form_data( $editing_id ) {
    $editing_user = $editing_id ? amap_get_amap_user( $editing_id ) : null;
    // Un compte administrateur ne peut pas être modifié ici (voir amap_handle_update_user()) :
    // retombe sur le formulaire d'ajout plutôt que d'afficher un formulaire dont la soumission
    // serait de toute façon refusée côté serveur.
    if ( $editing_user && in_array( 'administrator', $editing_user->roles, true ) ) {
        $editing_user = null;
        $editing_id   = 0;
    }
    if ( $editing_id && ! $editing_user ) {
        $editing_id = 0;
    }

    $transient_key = 'amap_user_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_user ) {
        $contact      = amap_get_user_contact( $editing_user->ID );
        $member_group = amap_get_member_group( $editing_user->ID );
        $form_data    = array(
            'last_name'  => $editing_user->last_name,
            'first_name' => $editing_user->first_name,
            'email'      => $editing_user->user_email,
            'phone'      => $contact->phone ?? '',
            'address'    => $contact->address ?? '',
            'roles'      => array_intersect( $editing_user->roles, array_keys( amap_get_available_roles() ) ),
            'group_id'   => $member_group ? (string) $member_group->id : '',
        );
    } else {
        $form_data = array();
    }

    return array(
        'editing_id' => $editing_id,
        'form_data'  => $form_data,
        'groups'     => amap_get_groups(),
        'notice'     => isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '',
    );
}

/**
 * Données de la page de confirmation de suppression d'un utilisateur AMAP
 * (member-area-board-user-delete.php) — revalide les mêmes garde-fous que
 * amap_handle_delete_user() (compte administrateur, producteur avec contrats, adhérent avec
 * souscriptions) pour afficher le bon message avant de proposer le bouton de suppression, plutôt
 * que de découvrir le blocage seulement après avoir cliqué (page dédiée plutôt qu'un simple lien
 * confirm() JS, voir project_espace_bureau_design_consolide).
 */
function amap_get_board_user_delete_data( $id ) {
    $user = $id ? amap_get_amap_user( $id ) : null;
    if ( ! $user ) {
        wp_die( esc_html__( 'Utilisateur introuvable.', 'association-manager' ) );
    }

    $blocked_reason = null;
    if ( in_array( 'administrator', $user->roles, true ) ) {
        $blocked_reason = __( 'Ce compte porte le rôle administrateur WordPress : suppression indisponible depuis cet écran.', 'association-manager' );
    } elseif ( in_array( 'amap_producer', $user->roles, true ) && amap_get_producer_contracts( $id ) ) {
        $blocked_reason = __( 'Ce producteur a des contrats enregistrés. Supprimez-les d\'abord depuis « Contrats ».', 'association-manager' );
    } elseif ( in_array( 'amap_member', $user->roles, true ) && amap_member_has_subscriptions( $id ) ) {
        $blocked_reason = __( 'Cet adhérent a des souscriptions enregistrées. Supprimez-les d\'abord depuis « Souscriptions ».', 'association-manager' );
    }

    return array(
        'user'           => $user,
        'blocked_reason' => $blocked_reason,
    );
}

/**
 * Données de la fiche producteur en lecture seule (member-area-board-producer-profile.php) :
 * coordonnées + groupes de livraison rattachés + contrats — même agrégation que
 * amap_render_producer_profile_page() côté wp-admin, mais sans aucune action de modification :
 * cette page ne fait que rassembler et rediriger vers les vraies fiches (groupe/contrat) où ces
 * informations se gèrent réellement.
 */
function amap_get_board_producer_profile_data( $producer_user_id ) {
    $producer = $producer_user_id ? amap_get_amap_user( $producer_user_id ) : null;
    if ( ! $producer || ! in_array( 'amap_producer', $producer->roles, true ) ) {
        wp_die( esc_html__( 'Producteur introuvable.', 'association-manager' ) );
    }

    return array(
        'producer'  => $producer,
        'contact'   => amap_get_user_contact( $producer->ID ),
        'groups'    => amap_get_producer_groups( $producer->ID ),
        'contracts' => amap_get_producer_contracts( $producer->ID ),
    );
}

/**
 * Souscriptions pour la section "Souscriptions" de l'onglet "Espace bureau"
 * (member-area-board-subscriptions.php) — triée par date de signature récente par défaut, avec
 * recherche libre (adhérent/contrat/producteur) et filtre par contrat, contrairement à
 * Amap_Subscriptions_List_Table côté wp-admin (aucune colonne ne s'y prêtait dans ce tableau,
 * voir amap_render_subscriptions_page()) : filtrage fait en PHP après récupération de toutes les
 * souscriptions plutôt qu'en SQL (jointures member_user_id/contract_id/producer_user_id sur 3
 * tables), les volumes d'une AMAP restant faibles.
 */
function amap_get_board_subscriptions_list_data() {
    global $wpdb;

    $per_page        = 20;
    $current_page    = max( 1, isset( $_GET['amap_board_page'] ) ? absint( $_GET['amap_board_page'] ) : 1 );
    $search          = isset( $_GET['amap_board_search'] ) ? sanitize_text_field( wp_unslash( $_GET['amap_board_search'] ) ) : '';
    $contract_filter = isset( $_GET['amap_subscription_contract_id'] ) ? absint( $_GET['amap_subscription_contract_id'] ) : 0;
    $table           = $wpdb->prefix . 'amap_subscriptions';

    $subscriptions = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY signed_at DESC" );

    if ( $contract_filter ) {
        $subscriptions = array_values(
            array_filter(
                $subscriptions,
                static function ( $subscription ) use ( $contract_filter ) {
                    return (int) $subscription->contract_id === $contract_filter;
                }
            )
        );
    }

    if ( '' !== $search ) {
        $subscriptions = array_values(
            array_filter(
                $subscriptions,
                static function ( $subscription ) use ( $search ) {
                    $contract = amap_get_contract( $subscription->contract_id );
                    $member   = get_user_by( 'id', $subscription->member_user_id );
                    $producer = $contract ? get_user_by( 'id', $contract->producer_user_id ) : null;

                    $haystack = implode(
                        ' ',
                        array_filter(
                            array(
                                $contract ? $contract->label : '',
                                $member ? $member->display_name : '',
                                $producer ? $producer->display_name : '',
                            )
                        )
                    );

                    return false !== stripos( $haystack, $search );
                }
            )
        );
    }

    $total = count( $subscriptions );
    $page_subscriptions = array_slice( $subscriptions, ( $current_page - 1 ) * $per_page, $per_page );

    return array(
        'subscriptions'   => $page_subscriptions,
        'total'           => $total,
        'current_page'    => $current_page,
        'total_pages'     => (int) ceil( $total / $per_page ),
        'search'          => $search,
        'contract_filter' => $contract_filter,
        'contracts'       => amap_get_contracts(),
        'notice'          => isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '',
    );
}

/**
 * Données du formulaire "Ajouter"/"Modifier une souscription" (member-area-board-subscription-
 * form.php), section "Souscriptions" de l'espace bureau — même préparation que
 * amap_render_subscriptions_page() côté wp-admin (contrats sélectionnables, données JS pour le
 * filtrage dynamique groupe/taille/produits, congés si le contrat est basket_recurring).
 * $editing_id à 0 signifie "Ajouter".
 */
function amap_get_board_subscription_form_data( $editing_id ) {
    $editing_subscription = $editing_id ? amap_get_subscription( $editing_id ) : null;
    if ( $editing_id && ! $editing_subscription ) {
        $editing_id = 0;
    }

    $editing_subscription_items = $editing_subscription ? amap_get_subscription_items( $editing_subscription->id ) : array();

    $transient_key = 'amap_subscription_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_subscription ) {
        $prefill_quantities = array();
        foreach ( $editing_subscription_items as $item ) {
            $prefill_quantities[ (int) $item->contract_delivery_date_id ][ (int) $item->contract_product_id ] = (int) $item->quantity;
        }

        $form_data = array(
            'contract_id'    => (string) $editing_subscription->contract_id,
            'member_user_id' => (string) $editing_subscription->member_user_id,
            'group_id'       => (string) $editing_subscription->group_id,
            'basket_size_id' => null !== $editing_subscription->basket_size_id ? (string) $editing_subscription->basket_size_id : '',
            'signed_at'      => $editing_subscription->signed_at,
            'is_paid'        => (bool) $editing_subscription->is_paid,
            'paid_at'        => $editing_subscription->paid_at,
            'quantities'     => $prefill_quantities,
        );
    } else {
        $form_data = array( 'signed_at' => current_time( 'Y-m-d' ) );
        // Raccourci "Ajouter une souscription" depuis la ligne d'un adhérent (section
        // "Utilisateurs", amap_get_board_user_add_subscription...) : pré-remplit l'adhérent,
        // évite la recherche dans le champ Adhérent pour le cas le plus courant.
        if ( ! empty( $_GET['member_user_id'] ) ) {
            $form_data['member_user_id'] = (string) absint( $_GET['member_user_id'] );
        }
    }

    $members   = amap_get_member_users();
    $contracts = amap_get_contracts();

    // Seuls les contrats actifs sont proposés pour une nouvelle souscription ; en édition, le
    // contrat déjà choisi reste proposé même désactivé depuis, pour ne pas casser une
    // souscription existante.
    $selectable_contracts = array_values(
        array_filter(
            $contracts,
            static function ( $contract ) {
                return (bool) $contract->is_active;
            }
        )
    );
    if ( $editing_subscription ) {
        $selectable_contract_ids = array_map( 'intval', wp_list_pluck( $selectable_contracts, 'id' ) );
        if ( ! in_array( (int) $editing_subscription->contract_id, $selectable_contract_ids, true ) ) {
            $editing_subscription_contract = amap_get_contract( $editing_subscription->contract_id );
            if ( $editing_subscription_contract ) {
                $selectable_contracts[] = $editing_subscription_contract;
            }
        }
    }

    // Données nécessaires au filtrage JS des champs "Groupe"/"Taille de panier"/"Produits" selon
    // le contrat choisi, précalculées pour tous les contrats proposés plutôt qu'en Ajax (volumes
    // faibles) — même structure que côté wp-admin.
    $contracts_js_data = array();
    foreach ( $selectable_contracts as $contract ) {
        $producer_groups = amap_get_producer_groups( $contract->producer_user_id );
        $basket_sizes    = 'basket_recurring' === $contract->contract_type ? amap_get_contract_basket_sizes( $contract->id ) : array();
        $products        = 'product_grid' === $contract->contract_type ? amap_get_contract_products( $contract->id ) : array();

        $delivery_dates_by_group = array();
        if ( 'product_grid' === $contract->contract_type ) {
            foreach ( amap_get_contract_delivery_dates( $contract->id ) as $delivery_date_row ) {
                $delivery_dates_by_group[ (int) $delivery_date_row->group_id ][] = array(
                    'id'    => (int) $delivery_date_row->id,
                    'label' => date_i18n( 'j F Y', strtotime( $delivery_date_row->delivery_date ) ),
                );
            }
        }

        $contracts_js_data[ (int) $contract->id ] = array(
            'type'                    => $contract->contract_type,
            'groups'                  => array_map(
                static function ( $group ) {
                    return array(
                        'id'    => (int) $group->id,
                        'label' => $group->name,
                    );
                },
                $producer_groups
            ),
            'basket_sizes'            => array_map(
                static function ( $size ) {
                    return array(
                        'id'    => (int) $size->id,
                        'label' => $size->label . ' (' . number_format_i18n( (float) $size->price, 2 ) . ' €)',
                    );
                },
                $basket_sizes
            ),
            'products'                => array_map(
                static function ( $product ) {
                    return array(
                        'id'    => (int) $product->id,
                        'label' => $product->label . ' (' . number_format_i18n( (float) $product->price, 2 ) . ' €)',
                    );
                },
                $products
            ),
            'delivery_dates_by_group' => $delivery_dates_by_group,
        );
    }

    // Congés : uniquement pour une souscription déjà existante à un contrat basket_recurring
    // (rien à afficher tant que la souscription elle-même n'a pas été créée).
    $leaves_data = null;
    if ( $editing_subscription ) {
        $leaves_contract = amap_get_contract( $editing_subscription->contract_id );
        if ( $leaves_contract && 'basket_recurring' === $leaves_contract->contract_type ) {
            $leaves       = amap_get_leaves( $editing_id );
            $max_leaves   = (int) $leaves_contract->max_leaves;
            $leaves_full  = count( $leaves ) >= $max_leaves;
            $leaves_group = amap_get_group( $editing_subscription->group_id );

            $taken_dates            = wp_list_pluck( $leaves, 'leave_date' );
            $leaves_available_dates = array();
            if ( $leaves_group && ! $leaves_full ) {
                foreach ( amap_get_weekday_dates_in_range( $leaves_contract->start_date, $leaves_contract->end_date, (int) $leaves_group->weekday, (int) $leaves_contract->frequency_weeks ) as $candidate_date ) {
                    if ( ! in_array( $candidate_date, $taken_dates, true ) ) {
                        $leaves_available_dates[] = $candidate_date;
                    }
                }
            }

            $leaves_data = array(
                'leaves'          => $leaves,
                'max_leaves'      => $max_leaves,
                'leaves_full'     => $leaves_full,
                'available_dates' => $leaves_available_dates,
            );
        }
    }

    return array(
        'editing_id'             => $editing_id,
        'editing_subscription'   => $editing_subscription,
        'form_data'              => $form_data,
        'members'                => $members,
        'selectable_contracts'   => $selectable_contracts,
        'contracts_js_data'      => $contracts_js_data,
        'leaves_data'            => $leaves_data,
        'price_summary'          => $editing_subscription ? amap_get_subscription_price_summary( $editing_id ) : null,
        'notice'                 => isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '',
    );
}

/**
 * Données de la page de confirmation de suppression d'une souscription
 * (member-area-board-subscription-delete.php) — aucune règle métier ne bloque cette suppression
 * (contrairement à Utilisateurs/Groupes/Contrats), donc pas de $blocked_reason ici.
 */
function amap_get_board_subscription_delete_data( $id ) {
    $subscription = $id ? amap_get_subscription( $id ) : null;
    if ( ! $subscription ) {
        wp_die( esc_html__( 'Souscription introuvable.', 'association-manager' ) );
    }

    $contract = amap_get_contract( $subscription->contract_id );
    $member   = get_user_by( 'id', $subscription->member_user_id );

    return array(
        'subscription' => $subscription,
        'contract'     => $contract,
        'member'       => $member,
    );
}

/**
 * Groupes pour la section "Groupes" de l'onglet "Espace bureau"
 * (member-area-board-groups.php) — même requête que Amap_Groups_List_Table::prepare_items() côté
 * wp-admin (recherche sur nom/lieu de livraison, tri fixe par jour/horaire, pas de tri par colonne
 * cliquable ici), réécrite sans WP_List_Table. Compte des producteurs/adhérents rattachés en 2
 * requêtes groupées plutôt qu'une par ligne, même principe que
 * Amap_Groups_List_Table::load_related_counts().
 */
function amap_get_board_groups_list_data() {
    global $wpdb;

    $per_page     = 20;
    $current_page = max( 1, isset( $_GET['amap_board_page'] ) ? absint( $_GET['amap_board_page'] ) : 1 );
    $search       = isset( $_GET['amap_board_search'] ) ? sanitize_text_field( wp_unslash( $_GET['amap_board_search'] ) ) : '';
    $table        = $wpdb->prefix . 'amap_groups';

    $where        = '';
    $where_params = array();
    if ( '' !== $search ) {
        $where          = 'WHERE name LIKE %s OR delivery_place LIKE %s';
        $like           = '%' . $wpdb->esc_like( $search ) . '%';
        $where_params[] = $like;
        $where_params[] = $like;
    }

    $total = $where_params
        ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $where_params ) )
        : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

    $query_params   = $where_params;
    $query_params[] = $per_page;
    $query_params[] = ( $current_page - 1 ) * $per_page;

    $groups = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY weekday ASC, start_time ASC LIMIT %d OFFSET %d",
            $query_params
        )
    );

    $producer_counts = array();
    $member_counts    = array();
    $group_ids        = wp_list_pluck( $groups, 'id' );
    if ( $group_ids ) {
        $placeholders = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );

        $producer_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT group_id, COUNT(*) AS cnt FROM {$wpdb->prefix}amap_group_producers WHERE group_id IN ({$placeholders}) GROUP BY group_id",
                $group_ids
            )
        );
        foreach ( $producer_rows as $row ) {
            $producer_counts[ (int) $row->group_id ] = (int) $row->cnt;
        }

        $member_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT group_id, COUNT(*) AS cnt FROM {$wpdb->prefix}amap_group_members WHERE group_id IN ({$placeholders}) GROUP BY group_id",
                $group_ids
            )
        );
        foreach ( $member_rows as $row ) {
            $member_counts[ (int) $row->group_id ] = (int) $row->cnt;
        }
    }

    return array(
        'groups'          => $groups,
        'producer_counts' => $producer_counts,
        'member_counts'   => $member_counts,
        'total'           => $total,
        'current_page'    => $current_page,
        'total_pages'     => (int) ceil( $total / $per_page ),
        'search'          => $search,
        'notice'          => isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '',
    );
}

/**
 * Données du formulaire "Ajouter"/"Modifier les infos du groupe" (member-area-board-group-form.php)
 * — même logique de préremplissage que amap_render_groups_page() côté wp-admin (transient
 * d'erreur en priorité, sinon valeurs actuelles en modification, sinon vide en création).
 * $editing_id à 0 signifie "Ajouter".
 */
function amap_get_board_group_form_data( $editing_id ) {
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
            'name'               => $editing_group->name,
            'delivery_place'     => $editing_group->delivery_place,
            'weekday'            => (string) $editing_group->weekday,
            'start_time'         => amap_format_time( $editing_group->start_time ),
            'end_time'           => amap_format_time( $editing_group->end_time ),
            'notification_email' => (string) $editing_group->notification_email,
        );
    } else {
        $form_data = array();
    }

    return array(
        'editing_id' => $editing_id,
        'form_data'  => $form_data,
        'notice'     => isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '',
    );
}

/**
 * Données de la page de confirmation de suppression d'un groupe (member-area-board-group-delete.php)
 * — revalide la même règle que amap_handle_delete_group() (souscriptions ayant ce groupe comme
 * point de retrait) pour afficher le bon message avant de proposer le bouton de suppression.
 */
function amap_get_board_group_delete_data( $id ) {
    $group = $id ? amap_get_group( $id ) : null;
    if ( ! $group ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    $blocked_reason = null;
    if ( amap_group_has_subscriptions( $id ) ) {
        $blocked_reason = __( 'Des souscriptions ont ce groupe comme point de retrait. Supprimez-les d\'abord depuis la page « Souscriptions » si vous souhaitez tout de même supprimer ce groupe.', 'association-manager' );
    }

    return array(
        'group'          => $group,
        'blocked_reason' => $blocked_reason,
    );
}

/**
 * Données de la fiche d'un groupe (member-area-board-group-view.php) : infos + les 3 sections
 * nichées côté wp-admin (Producteurs rattachés / Exceptions de distribution / Bénévoles de
 * distribution) — même préparation que la branche "édition" de amap_render_groups_page().
 * Bénévoles regroupés par date de distribution (une "ligne" par distribution, pas par bénévole),
 * même principe que Amap_Distribution_Volunteers_List_Table::prepare_items().
 */
function amap_get_board_group_view_data( $id ) {
    $group = $id ? amap_get_group( $id ) : null;
    if ( ! $group ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    // Exceptions : mode édition ?exception_action=edit&exception_id=Y, même principe que la
    // souscription en édition sur la page "Souscriptions".
    $exception_editing_id = 0;
    if ( isset( $_GET['exception_action'], $_GET['exception_id'] ) && 'edit' === $_GET['exception_action'] ) {
        $exception_editing_id = absint( $_GET['exception_id'] );
    }
    $editing_exception = $exception_editing_id ? amap_get_distribution_exception( $exception_editing_id ) : null;
    if ( $editing_exception && (int) $editing_exception->group_id !== $id ) {
        $editing_exception    = null;
        $exception_editing_id = 0;
    }

    $exception_transient_key = 'amap_distribution_exception_form_' . get_current_user_id();
    $exception_form_data     = get_transient( $exception_transient_key );
    if ( false !== $exception_form_data ) {
        delete_transient( $exception_transient_key );
    } elseif ( $editing_exception ) {
        $exception_form_data = array(
            'distribution_date' => $editing_exception->distribution_date,
            'exception_type'    => $editing_exception->exception_type,
            'new_date'          => (string) $editing_exception->new_date,
            'new_start_time'    => $editing_exception->new_start_time ? amap_format_time( $editing_exception->new_start_time ) : '',
            'new_end_time'      => $editing_exception->new_end_time ? amap_format_time( $editing_exception->new_end_time ) : '',
            'new_place'         => (string) $editing_exception->new_place,
            'reason'            => (string) $editing_exception->reason,
        );
    } else {
        $exception_form_data = array();
    }

    $volunteer_transient_key = 'amap_distribution_volunteer_form_' . get_current_user_id();
    $volunteer_form_data     = get_transient( $volunteer_transient_key );
    if ( false !== $volunteer_form_data ) {
        delete_transient( $volunteer_transient_key );
    } else {
        $volunteer_form_data = array();
    }

    $volunteers_by_date = array();
    foreach ( amap_get_distribution_volunteers( $id ) as $volunteer ) {
        $volunteers_by_date[ $volunteer->distribution_date ][] = $volunteer;
    }
    $volunteer_groups = array();
    foreach ( $volunteers_by_date as $distribution_date => $date_volunteers ) {
        $volunteer_groups[] = array(
            'distribution_date' => $distribution_date,
            'volunteers'        => $date_volunteers,
        );
    }

    return array(
        'group'                 => $group,
        'producers'             => amap_get_producer_users(),
        'attached_producer_ids' => amap_get_group_producer_ids( $id ),
        'exceptions'            => amap_get_distribution_exceptions( $id ),
        'exception_editing_id'  => $exception_editing_id,
        'exception_form_data'   => $exception_form_data,
        'volunteer_groups'      => $volunteer_groups,
        'volunteer_form_data'   => $volunteer_form_data,
        'eligible_members'      => amap_get_group_member_users( $id ),
        'current_year'          => (int) current_time( 'Y' ),
        'notice'                => isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '',
    );
}

/**
 * Contrats pour la section "Contrats" de l'onglet "Espace bureau" (member-area-board-contracts.php)
 * — même requête que Amap_Contracts_List_Table::prepare_items() côté wp-admin (recherche sur le
 * libellé, tri par défaut is_active DESC puis start_date DESC), avec les mêmes compteurs
 * souscriptions/paiements préchargés en une seule requête groupée chacun (pas de N+1).
 */
function amap_get_board_contracts_list_data() {
    global $wpdb;

    $per_page     = 20;
    $current_page = max( 1, isset( $_GET['amap_board_page'] ) ? absint( $_GET['amap_board_page'] ) : 1 );
    $search       = isset( $_GET['amap_board_search'] ) ? sanitize_text_field( wp_unslash( $_GET['amap_board_search'] ) ) : '';
    $table        = $wpdb->prefix . 'amap_contracts';
    // JOIN sur wp_users : la recherche porte aussi bien sur le libellé du contrat que sur le nom
    // du producteur, plus simple pour le bureau qui pense souvent "au nom du producteur" plutôt
    // qu'au libellé exact du contrat. SELECT c.* (jamais *) pour ne récupérer que les colonnes du
    // contrat, sans collision avec les colonnes de wp_users.
    $from         = "{$table} c LEFT JOIN {$wpdb->users} u ON u.ID = c.producer_user_id";

    $where        = '';
    $where_params = array();
    if ( '' !== $search ) {
        $where          = 'WHERE c.label LIKE %s OR u.display_name LIKE %s';
        $like           = '%' . $wpdb->esc_like( $search ) . '%';
        $where_params[] = $like;
        $where_params[] = $like;
    }

    $total = $where_params
        ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} {$where}", $where_params ) )
        : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

    $query_params   = $where_params;
    $query_params[] = $per_page;
    $query_params[] = ( $current_page - 1 ) * $per_page;

    $contracts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.* FROM {$from} {$where} ORDER BY c.is_active DESC, c.start_date DESC LIMIT %d OFFSET %d",
            $query_params
        )
    );

    $subscription_counts      = array();
    $paid_subscription_counts = array();
    $contract_ids             = wp_list_pluck( $contracts, 'id' );
    if ( $contract_ids ) {
        $placeholders = implode( ',', array_fill( 0, count( $contract_ids ), '%d' ) );

        $subscription_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT contract_id, COUNT(*) AS cnt FROM {$wpdb->prefix}amap_subscriptions WHERE contract_id IN ({$placeholders}) GROUP BY contract_id",
                $contract_ids
            )
        );
        foreach ( $subscription_rows as $row ) {
            $subscription_counts[ (int) $row->contract_id ] = (int) $row->cnt;
        }

        $paid_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT contract_id, COUNT(*) AS cnt FROM {$wpdb->prefix}amap_subscriptions WHERE is_paid = 1 AND contract_id IN ({$placeholders}) GROUP BY contract_id",
                $contract_ids
            )
        );
        foreach ( $paid_rows as $row ) {
            $paid_subscription_counts[ (int) $row->contract_id ] = (int) $row->cnt;
        }
    }

    $producer_names = array();
    foreach ( amap_get_producer_users() as $producer ) {
        $producer_names[ $producer->ID ] = $producer->display_name;
    }

    return array(
        'contracts'                => $contracts,
        'producer_names'           => $producer_names,
        'subscription_counts'      => $subscription_counts,
        'paid_subscription_counts' => $paid_subscription_counts,
        'total'                    => $total,
        'current_page'             => $current_page,
        'total_pages'              => (int) ceil( $total / $per_page ),
        'search'                   => $search,
        'notice'                   => isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '',
    );
}

/**
 * Données du formulaire "Ajouter"/"Modifier les infos du contrat" (member-area-board-contract-form.php)
 * — même logique de préremplissage que amap_render_contracts_page() côté wp-admin.
 * $editing_id à 0 signifie "Ajouter". `producer_group_counts` sert au message d'avertissement JS
 * si le producteur choisi n'a encore aucun groupe de distribution rattaché (voir
 * amap_render_contracts_page()).
 */
function amap_get_board_contract_form_data( $editing_id ) {
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
            'max_leaves'       => null !== $editing_contract->max_leaves ? (string) $editing_contract->max_leaves : '',
            'is_active'        => (bool) $editing_contract->is_active,
        );
    } else {
        // Un nouveau contrat est ouvert à la souscription tant que le bureau ne l'a pas
        // explicitement fermé.
        $form_data = array( 'is_active' => true );
    }

    $producers              = amap_get_producer_users();
    $producer_group_counts = array();
    foreach ( $producers as $producer ) {
        $producer_group_counts[ $producer->ID ] = count( amap_get_producer_groups( $producer->ID ) );
    }

    return array(
        'editing_id'             => $editing_id,
        'producers'              => $producers,
        'producer_group_counts'  => $producer_group_counts,
        'form_data'              => $form_data,
        'notice'                 => isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '',
    );
}

/**
 * Données de la page de confirmation de suppression d'un contrat (member-area-board-contract-delete.php)
 * — revalide la même règle que amap_handle_delete_contract() (souscriptions enregistrées).
 */
function amap_get_board_contract_delete_data( $id ) {
    $contract = $id ? amap_get_contract( $id ) : null;
    if ( ! $contract ) {
        wp_die( esc_html__( 'Contrat introuvable.', 'association-manager' ) );
    }

    $blocked_reason = null;
    if ( amap_contract_has_subscriptions( $id ) ) {
        $blocked_reason = __( 'Ce contrat a des souscriptions enregistrées. Supprimez-les d\'abord depuis la page « Souscriptions » si vous souhaitez tout de même supprimer ce contrat.', 'association-manager' );
    }

    return array(
        'contract'       => $contract,
        'producer'       => get_user_by( 'id', $contract->producer_user_id ),
        'blocked_reason' => $blocked_reason,
    );
}

/**
 * Données de la fiche d'un contrat (member-area-board-contract-view.php) : infos + les sections
 * propres à son type — Tailles de panier pour "panier récurrent" ; Familles de remise, Catalogue
 * produits et Dates de livraison pour "grille produits". Même préparation que la branche
 * "édition" de amap_render_contracts_page(), mais en accordéons <details> plutôt qu'en onglets JS
 * (même principe que la fiche groupe, member-area-board-group-view.php) : pas besoin
 * d'`active_tab`, chaque section s'ouvre selon la notice ou l'édition en cours qui la concerne.
 */
function amap_get_board_contract_view_data( $id ) {
    $contract = $id ? amap_get_contract( $id ) : null;
    if ( ! $contract ) {
        wp_die( esc_html__( 'Contrat introuvable.', 'association-manager' ) );
    }

    // Taille de panier : mode édition ?size_action=edit&size_id=Y, même principe que les
    // exceptions de distribution sur la fiche groupe.
    $size_editing_id = 0;
    if ( isset( $_GET['size_action'], $_GET['size_id'] ) && 'edit' === $_GET['size_action'] ) {
        $size_editing_id = absint( $_GET['size_id'] );
    }
    $size_editing = $size_editing_id ? amap_get_contract_basket_size( $size_editing_id ) : null;
    if ( $size_editing && (int) $size_editing->contract_id !== $id ) {
        $size_editing    = null;
        $size_editing_id = 0;
    }

    $size_transient_key = 'amap_contract_basket_size_form_' . get_current_user_id();
    $size_form_data      = get_transient( $size_transient_key );
    if ( false !== $size_form_data ) {
        delete_transient( $size_transient_key );
    } elseif ( $size_editing ) {
        $size_form_data = array(
            'label' => $size_editing->label,
            'price' => (string) $size_editing->price,
        );
    } else {
        $size_form_data = array();
    }

    // Produit du catalogue : mode édition ?product_action=edit&product_id=Y.
    $product_editing_id = 0;
    if ( isset( $_GET['product_action'], $_GET['product_id'] ) && 'edit' === $_GET['product_action'] ) {
        $product_editing_id = absint( $_GET['product_id'] );
    }
    $product_editing = $product_editing_id ? amap_get_contract_product( $product_editing_id ) : null;
    if ( $product_editing && (int) $product_editing->contract_id !== $id ) {
        $product_editing    = null;
        $product_editing_id = 0;
    }

    $product_transient_key = 'amap_contract_product_form_' . get_current_user_id();
    $product_form_data      = get_transient( $product_transient_key );
    if ( false !== $product_form_data ) {
        delete_transient( $product_transient_key );
    } elseif ( $product_editing ) {
        $product_form_data = array(
            'label'             => $product_editing->label,
            'price'             => (string) $product_editing->price,
            'discount_group_id' => (string) $product_editing->discount_group_id,
        );
    } else {
        $product_form_data = array();
    }

    // Famille de remise : mode édition ?discount_action=edit&discount_id=Y.
    $discount_group_editing_id = 0;
    if ( isset( $_GET['discount_action'], $_GET['discount_id'] ) && 'edit' === $_GET['discount_action'] ) {
        $discount_group_editing_id = absint( $_GET['discount_id'] );
    }
    $discount_group_editing = $discount_group_editing_id ? amap_get_contract_discount_group( $discount_group_editing_id ) : null;
    if ( $discount_group_editing && (int) $discount_group_editing->contract_id !== $id ) {
        $discount_group_editing    = null;
        $discount_group_editing_id = 0;
    }

    $discount_group_transient_key = 'amap_contract_discount_group_form_' . get_current_user_id();
    $discount_group_form_data      = get_transient( $discount_group_transient_key );
    if ( false !== $discount_group_form_data ) {
        delete_transient( $discount_group_transient_key );
    } elseif ( $discount_group_editing ) {
        $discount_group_form_data = array(
            'label'           => $discount_group_editing->label,
            'price'           => (string) $discount_group_editing->price,
            'bought_quantity' => (string) $discount_group_editing->bought_quantity,
            'billed_quantity' => (string) $discount_group_editing->billed_quantity,
        );
    } else {
        $discount_group_form_data = array();
    }

    // Date de livraison : mode édition ?date_action=edit&date_id=Y.
    $delivery_date_editing_id = 0;
    if ( isset( $_GET['date_action'], $_GET['date_id'] ) && 'edit' === $_GET['date_action'] ) {
        $delivery_date_editing_id = absint( $_GET['date_id'] );
    }
    $delivery_date_editing = $delivery_date_editing_id ? amap_get_contract_delivery_date( $delivery_date_editing_id ) : null;
    if ( $delivery_date_editing && (int) $delivery_date_editing->contract_id !== $id ) {
        $delivery_date_editing    = null;
        $delivery_date_editing_id = 0;
    }

    $delivery_date_transient_key = 'amap_contract_delivery_date_form_' . get_current_user_id();
    $delivery_date_form_data      = get_transient( $delivery_date_transient_key );
    if ( false !== $delivery_date_form_data ) {
        delete_transient( $delivery_date_transient_key );
    } elseif ( $delivery_date_editing ) {
        $delivery_date_form_data = array(
            'group_id'      => (string) $delivery_date_editing->group_id,
            'delivery_date' => $delivery_date_editing->delivery_date,
        );
    } else {
        $delivery_date_form_data = array();
    }

    // Dates de livraison groupées par groupe de distribution du producteur — une section
    // d'accordéon par groupe, avec ses dates existantes et ses dates candidates à la génération en
    // masse (occurrences du jour fixe du groupe sur toute la période du contrat, moins celles déjà
    // enregistrées), même principe que amap_render_contracts_page().
    $delivery_date_groups = array();
    if ( 'product_grid' === $contract->contract_type ) {
        $dates_by_group = array();
        foreach ( amap_get_contract_delivery_dates( $id ) as $date_row ) {
            $dates_by_group[ (int) $date_row->group_id ][] = $date_row;
        }

        foreach ( amap_get_producer_groups( $contract->producer_user_id ) as $producer_group ) {
            $existing_dates   = $dates_by_group[ (int) $producer_group->id ] ?? array();
            $existing_strings = wp_list_pluck( $existing_dates, 'delivery_date' );
            $candidate_dates  = array_values(
                array_diff(
                    amap_get_weekday_dates_in_range( $contract->start_date, $contract->end_date, (int) $producer_group->weekday ),
                    $existing_strings
                )
            );

            $delivery_date_groups[] = array(
                'group'           => $producer_group,
                'dates'           => $existing_dates,
                'candidate_dates' => $candidate_dates,
            );
        }
    }

    return array(
        'contract'                 => $contract,
        'producer'                 => get_user_by( 'id', $contract->producer_user_id ),
        'basket_sizes'             => 'basket_recurring' === $contract->contract_type ? amap_get_contract_basket_sizes( $id ) : array(),
        'size_editing_id'          => $size_editing_id,
        'size_form_data'           => $size_form_data,
        'discount_groups'          => 'product_grid' === $contract->contract_type ? amap_get_contract_discount_groups( $id ) : array(),
        'discount_group_editing_id' => $discount_group_editing_id,
        'discount_group_form_data' => $discount_group_form_data,
        'products'                 => 'product_grid' === $contract->contract_type ? amap_get_contract_products( $id ) : array(),
        'product_editing_id'       => $product_editing_id,
        'product_form_data'        => $product_form_data,
        'delivery_date_groups'     => $delivery_date_groups,
        'delivery_date_editing_id' => $delivery_date_editing_id,
        'delivery_date_editing'    => $delivery_date_editing,
        'delivery_date_form_data'  => $delivery_date_form_data,
        'generate_group_id'        => isset( $_GET['generate_group_id'] ) ? absint( $_GET['generate_group_id'] ) : 0,
        'generated_count'          => isset( $_GET['generated_count'] ) ? absint( $_GET['generated_count'] ) : 0,
        'deleted_count'            => isset( $_GET['deleted_count'] ) ? absint( $_GET['deleted_count'] ) : 0,
        'notice'                   => isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '',
    );
}

/**
 * Valide le contrat visé par ?amap_member_action=subscribe&contract_id=X et prépare les données
 * du formulaire (member-area-subscribe.php). Un contrat inactif/inexistant, ou dont le
 * producteur ne livre pas le groupe de l'adhérent, ne peut venir que d'un lien périmé ou
 * trafiqué, vu que la liste des "contrats disponibles" (amap_get_available_contracts_for_member())
 * ne les propose jamais — wp_die() dans ces cas. Souscrire plusieurs fois au même contrat est
 * volontairement permis (voir amap_get_available_contracts_for_member()), donc pas de vérif de
 * doublon ici. L'absence de groupe rattaché redirige vers l'onglet adhérent, qui explique déjà la
 * marche à suivre. En revanche, une taille de panier / un produit / une date de livraison
 * manquants relèvent d'une configuration incomplète côté bureau, pas d'une tentative de trafiquer
 * la requête : un contrat proposé normalement à la souscription peut très bien y mener, donc ces
 * cas retournent un tableau avec une clé 'error' (affiché en notice par member-area-subscribe.php)
 * plutôt qu'un wp_die().
 */
function amap_get_member_subscribe_form_data( $user ) {
    $contract_id = isset( $_GET['contract_id'] ) ? absint( $_GET['contract_id'] ) : 0;
    $contract    = $contract_id ? amap_get_contract( $contract_id ) : null;

    if ( ! $contract || ! $contract->is_active ) {
        wp_die( esc_html__( "Ce contrat n'est pas ouvert à la souscription.", 'association-manager' ) );
    }

    // Point de retrait fixe de l'adhérent (fixé par le bureau, page "Utilisateurs AMAP"), jamais
    // un choix laissé au formulaire — voir amap_get_available_contracts_for_member().
    $member_group = amap_get_member_group( $user->ID );
    if ( ! $member_group ) {
        wp_safe_redirect( amap_get_member_area_tab_url( 'member' ) );
        exit;
    }

    $producer_group_ids = array_map( 'intval', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) );
    if ( ! in_array( (int) $member_group->id, $producer_group_ids, true ) ) {
        wp_die( esc_html__( "Ce contrat n'est pas disponible pour votre groupe.", 'association-manager' ) );
    }

    $basket_sizes    = array();
    $products        = array();
    $delivery_dates  = array();
    $discount_groups = array();

    if ( 'basket_recurring' === $contract->contract_type ) {
        $basket_sizes = array_map(
            static function ( $size ) {
                return array(
                    'id'    => (int) $size->id,
                    'label' => $size->label,
                    'price' => (float) $size->price,
                );
            },
            amap_get_contract_basket_sizes( $contract->id )
        );

        if ( empty( $basket_sizes ) ) {
            return array( 'error' => 'no_basket_sizes' );
        }
    } else {
        // price/discount_group_id (bruts, pas seulement le libellé déjà formaté) : nécessaires
        // pour calculer les totaux en direct côté front (member-area-subscribe.php), sans
        // dupliquer l'arrondi/la mise en forme monétaire déjà faite par number_format_i18n().
        $products = array_map(
            static function ( $product ) {
                return array(
                    'id'                => (int) $product->id,
                    'label'             => $product->label,
                    'price'             => (float) $product->price,
                    'discount_group_id' => $product->discount_group_id ? (int) $product->discount_group_id : null,
                );
            },
            amap_get_contract_products( $contract->id )
        );

        if ( empty( $products ) ) {
            return array( 'error' => 'no_products' );
        }

        // Même calcul de remise par palier que amap_get_subscription_price_summary() : appliquée
        // par date de livraison (voir son commentaire), puis sommée sur la saison — le total en
        // direct annonce donc le même montant que celui réellement facturé après confirmation.
        $discount_groups = array_map(
            static function ( $group ) {
                return array(
                    'id'              => (int) $group->id,
                    'label'           => $group->label,
                    'price'           => (float) $group->price,
                    'bought_quantity' => (int) $group->bought_quantity,
                    'billed_quantity' => (int) $group->billed_quantity,
                    'note'            => sprintf(
                        /* translators: 1: nom du groupe de remise. 2: quantité achetée déclenchant la remise. 3: quantité facturée correspondante. */
                        __( '%1$s : remise « %2$d achetés → %3$d facturés » appliquée par date de livraison, non reflétée dans les totaux ci-dessus par produit (détail exact à la confirmation).', 'association-manager' ),
                        $group->label,
                        (int) $group->bought_quantity,
                        (int) $group->billed_quantity
                    ),
                );
            },
            amap_get_contract_discount_groups( $contract->id )
        );

        // Groupe déjà fixé (contrairement à l'admin, qui construit les dates de tous les
        // groupes puisque le bureau choisit le groupe dans le même formulaire) : seules les
        // dates du groupe de l'adhérent sont nécessaires ici.
        foreach ( amap_get_contract_delivery_dates( $contract->id ) as $delivery_date_row ) {
            if ( (int) $delivery_date_row->group_id !== (int) $member_group->id ) {
                continue;
            }

            $delivery_dates[] = array(
                'id'          => (int) $delivery_date_row->id,
                'label'       => date_i18n( 'j F Y', strtotime( $delivery_date_row->delivery_date ) ),
                'short_label' => amap_get_short_date_label( $delivery_date_row->delivery_date ),
            );
        }

        if ( empty( $delivery_dates ) ) {
            return array( 'error' => 'no_delivery_dates' );
        }
    }

    return array(
        'contract'        => $contract,
        'producer'        => get_user_by( 'id', $contract->producer_user_id ),
        'group'           => $member_group,
        'basket_sizes'    => $basket_sizes,
        'products'        => $products,
        'delivery_dates'  => $delivery_dates,
        'discount_groups' => $discount_groups,
    );
}

/**
 * Valide la souscription visée par ?amap_member_action=declare_leave&subscription_id=X et
 * prépare les données du formulaire (member-area-leave.php). Contrairement à
 * amap_get_member_subscribe_form_data() (qui valide un contrat public), la souscription doit en
 * plus appartenir à l'utilisateur connecté — sinon wp_die(), un adhérent ne doit jamais pouvoir
 * agir sur la souscription d'un autre en changeant l'ID dans l'URL.
 *
 * Les dates proposées dans le formulaire sont calculées ici plutôt que saisies librement par
 * l'adhérent (contrairement à l'admin) : jour de semaine du groupe, période du contrat, délai
 * d'une semaine et non déjà déclarées — toutes les règles de amap_handle_add_leave() sont ainsi
 * satisfaites par construction, un parcours normal ne peut donc jamais déclencher les wp_die() de
 * amap_handle_add_member_leave().
 */
function amap_get_member_leave_form_data( $user ) {
    $subscription_id = isset( $_GET['subscription_id'] ) ? absint( $_GET['subscription_id'] ) : 0;
    $subscription    = $subscription_id ? amap_get_subscription( $subscription_id ) : null;
    if ( ! $subscription || (int) $subscription->member_user_id !== $user->ID ) {
        wp_die( esc_html__( 'Souscription introuvable.', 'association-manager' ) );
    }

    $contract = amap_get_contract( $subscription->contract_id );
    if ( ! $contract || 'basket_recurring' !== $contract->contract_type ) {
        wp_die( esc_html__( "Cette souscription n'est pas concernée par les congés.", 'association-manager' ) );
    }

    $group  = amap_get_group( $subscription->group_id );
    $leaves = amap_get_leaves( $subscription_id );

    $available_dates = array();
    if ( $group && count( $leaves ) < (int) $contract->max_leaves ) {
        $min_date    = ( new DateTime( current_time( 'Y-m-d' ) ) )->modify( '+7 days' )->format( 'Y-m-d' );
        $taken_dates = wp_list_pluck( $leaves, 'leave_date' );

        // Ancré sur start_date et espacé de frequency_weeks semaines : ne propose que les
        // vraies dates de distribution d'un contrat bimensuel/etc., pas toutes les occurrences
        // du jour de semaine (voir amap_get_weekday_dates_in_range()).
        foreach ( amap_get_weekday_dates_in_range( $contract->start_date, $contract->end_date, (int) $group->weekday, (int) $contract->frequency_weeks ) as $candidate_date ) {
            if ( $candidate_date < $min_date || in_array( $candidate_date, $taken_dates, true ) ) {
                continue;
            }

            $available_dates[] = array(
                'date'  => $candidate_date,
                'label' => date_i18n( 'l j F Y', strtotime( $candidate_date ) ),
            );
        }
    }

    return array(
        'subscription'     => $subscription,
        'contract'         => $contract,
        'producer'         => get_user_by( 'id', $contract->producer_user_id ),
        'group'            => $group,
        'leaves'           => $leaves,
        'available_dates'  => $available_dates,
    );
}

/**
 * Valide ?amap_member_action=export_contract_roster&contract_id=X&group_id=Y (bouton "Détail" de
 * la carte "Produits à livrer", onglet "Espace producteur") et envoie directement le fichier CSV
 * de pointage — jamais de page rendue, contrairement aux autres actions de ce fichier. wp_die()
 * sur un contrat/groupe trafiqué, non basket_recurring, ou n'appartenant pas au producteur
 * connecté : l'UI ne propose jamais un tel lien.
 *
 * Fenêtre glissante de 30 jours à partir d'aujourd'hui plutôt que le mois calendaire strict, pour
 * ne pas se retrouver avec seulement 2-3 jours utiles en fin de mois.
 */
function amap_handle_export_contract_roster( $producer ) {
    $contract_id = isset( $_GET['contract_id'] ) ? absint( $_GET['contract_id'] ) : 0;
    $group_id    = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;

    $contract = $contract_id ? amap_get_contract( $contract_id ) : null;
    $group    = $group_id ? amap_get_group( $group_id ) : null;

    if ( ! $contract || ! $group
        || 'basket_recurring' !== $contract->contract_type
        || (int) $contract->producer_user_id !== $producer->ID
        || ! in_array( $group_id, array_map( 'intval', wp_list_pluck( amap_get_producer_groups( $producer->ID ), 'id' ) ), true )
    ) {
        wp_die( esc_html__( 'Export non autorisé.', 'association-manager' ) );
    }

    $window_start = current_time( 'Y-m-d' );
    $window_end   = ( new DateTime( $window_start ) )->modify( '+29 days' )->format( 'Y-m-d' );
    $window_dates = amap_get_weekday_dates_in_range( $window_start, $window_end, (int) $group->weekday );
    $rows         = amap_get_contract_roster_rows( $contract, $group, $window_start, $window_end );

    nocache_headers();
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $contract->label . '-' . $group->name . '.csv' ) . '"' );

    $output = fopen( 'php://output', 'w' );
    // BOM UTF-8 : sans lui, Excel affiche les accents mal encodés à l'ouverture directe du fichier.
    fwrite( $output, "\xEF\xBB\xBF" );

    fputcsv(
        $output,
        array_merge(
            array(
                __( 'Nom Prénom', 'association-manager' ),
                __( 'Téléphone', 'association-manager' ),
                __( 'Congés déposés', 'association-manager' ),
                __( 'Distributions faites', 'association-manager' ),
            ),
            array_map(
                static function ( $date ) {
                    return date_i18n( 'd/m', strtotime( $date ) );
                },
                $window_dates
            ),
            array( __( 'Commentaire', 'association-manager' ) )
        ),
        ';'
    );

    foreach ( $rows as $row ) {
        fputcsv(
            $output,
            array_merge(
                array( $row['name'], $row['phone'], $row['leaves_count'], $row['done_count'] ),
                $row['statuses'],
                array( '' )
            ),
            ';'
        );
    }

    fclose( $output );
    exit;
}

/**
 * Valide ?amap_member_action=export_contract_products&contract_id=X&group_id=Y&distribution_date=Z
 * (bouton "Détail (CSV)" de la carte "Produits à livrer" pour un contrat product_grid) et envoie
 * directement le fichier CSV nominatif des commandes de cette distribution — même principe que
 * amap_handle_export_contract_roster(), mais un fichier par distribution (une seule date, pas de
 * fenêtre glissante) et des colonnes produits plutôt que des colonnes dates. wp_die() sur un
 * contrat/groupe/date trafiqué, non product_grid, n'appartenant pas au producteur connecté, ou ne
 * correspondant à aucune date de livraison enregistrée (amap_get_contract_product_subscribers()
 * retourne alors null) : l'UI ne propose jamais un tel lien.
 */
function amap_handle_export_contract_products( $producer ) {
    $contract_id       = isset( $_GET['contract_id'] ) ? absint( $_GET['contract_id'] ) : 0;
    $group_id          = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;
    $distribution_date = isset( $_GET['distribution_date'] ) ? sanitize_text_field( wp_unslash( $_GET['distribution_date'] ) ) : '';

    $contract = $contract_id ? amap_get_contract( $contract_id ) : null;
    $group    = $group_id ? amap_get_group( $group_id ) : null;

    if ( ! $contract || ! $group
        || 'product_grid' !== $contract->contract_type
        || (int) $contract->producer_user_id !== $producer->ID
        || ! in_array( $group_id, array_map( 'intval', wp_list_pluck( amap_get_producer_groups( $producer->ID ), 'id' ) ), true )
        || ! amap_is_valid_date( $distribution_date )
    ) {
        wp_die( esc_html__( 'Export non autorisé.', 'association-manager' ) );
    }

    $subscribers = amap_get_contract_product_subscribers( $contract, $group, $distribution_date );
    if ( null === $subscribers ) {
        wp_die( esc_html__( 'Export non autorisé.', 'association-manager' ) );
    }

    // Une colonne par produit effectivement commandé à cette date, pas tout le catalogue du
    // contrat — ordonnées par première apparition (même logique que
    // amap_get_contract_products_to_deliver()).
    $products = array();
    foreach ( $subscribers as $entry ) {
        foreach ( $entry['items'] as $item ) {
            $products[ $item['product']->id ] = $item['product'];
        }
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $contract->label . '-' . $group->name . '-' . $distribution_date . '.csv' ) . '"' );

    $output = fopen( 'php://output', 'w' );
    // BOM UTF-8 : sans lui, Excel affiche les accents mal encodés à l'ouverture directe du fichier.
    fwrite( $output, "\xEF\xBB\xBF" );

    fputcsv(
        $output,
        array_merge(
            array( __( 'Nom Prénom', 'association-manager' ), __( 'Téléphone', 'association-manager' ) ),
            wp_list_pluck( $products, 'label' ),
            array( __( 'Commentaire', 'association-manager' ) )
        ),
        ';'
    );

    foreach ( $subscribers as $entry ) {
        if ( ! $entry['member'] ) {
            continue;
        }

        $quantities = array();
        foreach ( $entry['items'] as $item ) {
            $quantities[ $item['product']->id ] = $item['quantity'];
        }

        $contact = amap_get_user_contact( $entry['member']->ID );

        fputcsv(
            $output,
            array_merge(
                array(
                    trim( $entry['member']->last_name . ' ' . $entry['member']->first_name ),
                    $contact->phone ?? '',
                ),
                array_map(
                    static function ( $product_id ) use ( $quantities ) {
                        return $quantities[ $product_id ] ?? '—';
                    },
                    array_keys( $products )
                ),
                array( '' )
            ),
            ';'
        );
    }

    fclose( $output );
    exit;
}

/**
 * Valide ?amap_member_action=export_contract_season_summary&contract_id=X&group_id=Y (lien
 * "Export saison" de la fiche contrat, onglet "Espace producteur") et envoie le fichier CSV
 * consolidé sur toute la durée du contrat — une ligne par adhérent, quantités/paniers facturés et
 * montant dû, contrairement aux exports "Feuille de présence"/"Commandes" limités à une fenêtre ou
 * une seule distribution. Disponible pour les deux types de contrat (contrairement aux deux autres
 * exports, chacun spécifique à un type). wp_die() sur un contrat/groupe trafiqué ou n'appartenant
 * pas au producteur connecté : l'UI ne propose jamais un tel lien.
 */
function amap_handle_export_contract_season_summary( $producer ) {
    $contract_id = isset( $_GET['contract_id'] ) ? absint( $_GET['contract_id'] ) : 0;
    $group_id    = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;

    $contract = $contract_id ? amap_get_contract( $contract_id ) : null;
    $group    = $group_id ? amap_get_group( $group_id ) : null;

    if ( ! $contract || ! $group
        || (int) $contract->producer_user_id !== $producer->ID
        || ! in_array( $group_id, array_map( 'intval', wp_list_pluck( amap_get_producer_groups( $producer->ID ), 'id' ) ), true )
    ) {
        wp_die( esc_html__( 'Export non autorisé.', 'association-manager' ) );
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $contract->label . '-' . $group->name . '-saison.csv' ) . '"' );

    $output = fopen( 'php://output', 'w' );
    // BOM UTF-8 : sans lui, Excel affiche les accents mal encodés à l'ouverture directe du fichier.
    fwrite( $output, "\xEF\xBB\xBF" );

    if ( 'basket_recurring' === $contract->contract_type ) {
        fputcsv(
            $output,
            array(
                __( 'Nom Prénom', 'association-manager' ),
                __( 'Téléphone', 'association-manager' ),
                __( 'Taille de panier', 'association-manager' ),
                __( 'Distributions prévues', 'association-manager' ),
                __( 'Congés pris', 'association-manager' ),
                __( 'Distributions facturées', 'association-manager' ),
                __( 'Montant dû (€)', 'association-manager' ),
            ),
            ';'
        );

        foreach ( amap_get_contract_basket_season_rows( $contract, $group ) as $row ) {
            fputcsv(
                $output,
                array(
                    $row['name'],
                    $row['phone'],
                    $row['basket_size'],
                    $row['total_distributions'],
                    $row['leaves_count'],
                    $row['billed_distributions'],
                    number_format( $row['amount'], 2, ',', '' ),
                ),
                ';'
            );
        }
    } else {
        // Le catalogue complet du contrat sert de colonnes (pas seulement les produits
        // effectivement commandés, contrairement à amap_handle_export_contract_products()) : les
        // colonnes restent ainsi identiques d'une ligne à l'autre sur toute la saison.
        $products = amap_get_contract_products( $contract->id );

        fputcsv(
            $output,
            array_merge(
                array( __( 'Nom Prénom', 'association-manager' ), __( 'Téléphone', 'association-manager' ) ),
                wp_list_pluck( $products, 'label' ),
                array( __( 'Montant dû (€)', 'association-manager' ) )
            ),
            ';'
        );

        foreach ( amap_get_contract_product_season_rows( $contract, $group ) as $row ) {
            fputcsv(
                $output,
                array_merge(
                    array( $row['name'], $row['phone'] ),
                    array_map(
                        static function ( $product ) use ( $row ) {
                            return $row['quantities'][ $product->id ] ?? 0;
                        },
                        $products
                    ),
                    array( number_format( $row['amount'], 2, ',', '' ) )
                ),
                ';'
            );
        }
    }

    fclose( $output );
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
