<?php
/**
 * Page d'admin "Contrats" : CRUD des contrats et de leurs tables filles (tailles de panier, produits, dates de livraison).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function amap_get_contract_types() {
    return array(
        'basket_recurring' => __( 'Panier récurrent', 'association-manager' ),
        'product_grid'     => __( 'Grille produits', 'association-manager' ),
    );
}

/**
 * Statut d'un contrat par rapport à $reference_date (aujourd'hui par défaut), dérivé de ses
 * dates — distinct de `is_active` (qui ne dit qu'"ouvert à la souscription", indépendamment des
 * dates). Utilisé pour l'affichage de "Mes contrats" côté adhérent (référence : aujourd'hui), et
 * pour filtrer les livraisons à une date de distribution donnée (référence : cette date, qui peut
 * être dans le futur — voir amap_get_member_deliveries()).
 */
function amap_get_contract_period_status( $contract, $reference_date = null ) {
    $reference_date = $reference_date ?? current_time( 'Y-m-d' );

    if ( $contract->start_date > $reference_date ) {
        return 'upcoming';
    }

    if ( $contract->end_date < $reference_date ) {
        return 'ended';
    }

    return 'active';
}

function amap_get_contract_period_status_labels() {
    return array(
        'upcoming' => __( 'À venir', 'association-manager' ),
        'active'   => __( 'En cours', 'association-manager' ),
        'ended'    => __( 'Terminé', 'association-manager' ),
    );
}

function amap_get_contracts() {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}amap_contracts ORDER BY is_active DESC, start_date DESC"
    );
}

/**
 * Délai du bandeau de rappel de fin de contrat producteur (amap_get_contracts_ending_soon()),
 * affiché au bureau sur les pages d'admin AMAP.
 */
function amap_get_contract_renewal_reminder_days() {
    return 15;
}

/**
 * Contrats producteur dont la date de fin tombe dans les $days_ahead prochains jours (bornes
 * incluses) — is_active n'entre pas en compte ici : ce champ ne dit que si le contrat est encore
 * ouvert à la souscription, pas si la relation avec le producteur touche à sa fin. Sert de base au
 * bandeau de rappel affiché sur l'espace bureau (member-area-board.php, dans le thème).
 */
function amap_get_contracts_ending_soon( $days_ahead ) {
    global $wpdb;

    $today     = current_time( 'Y-m-d' );
    $last_date = ( new DateTime( $today ) )->modify( "+{$days_ahead} days" )->format( 'Y-m-d' );

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_contracts WHERE end_date BETWEEN %s AND %s ORDER BY end_date ASC",
            $today,
            $last_date
        )
    );
}

/**
 * Contrats d'un producteur donné, utilisés pour l'onglet "Espace producteur" de l'espace membre
 * (member-area-producer.php) — même tri que amap_get_contracts(). Contrairement à
 * amap_get_member_subscriptions(), aucune jointure n'est nécessaire : le producteur est déjà
 * connu, le template calcule lui-même le statut par contrat via
 * amap_get_contract_period_status().
 */
function amap_get_producer_contracts( $producer_user_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_contracts WHERE producer_user_id = %d ORDER BY is_active DESC, start_date DESC",
            $producer_user_id
        )
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

function amap_get_contract_discount_groups( $contract_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_contract_discount_groups WHERE contract_id = %d ORDER BY id ASC",
            $contract_id
        )
    );
}

function amap_get_contract_discount_group( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_contract_discount_groups WHERE id = %d", $id )
    );
}

// Le seuil doit représenter une vraie remise : la quantité facturée doit être strictement
// inférieure à la quantité achetée (voir le point ouvert dans docs/plan-contrats-distributions.md).
function amap_is_valid_discount_ratio( $bought_quantity, $billed_quantity ) {
    return $bought_quantity > 0 && $billed_quantity > 0 && $billed_quantity < $bought_quantity;
}

function amap_store_contract_discount_group_form_data( array $data ) {
    set_transient( 'amap_contract_discount_group_form_' . get_current_user_id(), $data, 60 );
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
 * Ligne de date de livraison pour un couple (contrat, groupe) à une date exacte, ou null. Utilisée
 * par amap_get_contract_products_to_deliver() pour retrouver les subscription_items rattachés à
 * la prochaine distribution d'un groupe (amap_get_group_next_distribution(), étape 12.2).
 */
function amap_get_contract_delivery_date_by_date( $contract_id, $group_id, $delivery_date ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_contract_delivery_dates WHERE contract_id = %d AND group_id = %d AND delivery_date = %s",
            $contract_id,
            $group_id,
            $delivery_date
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
 * comme amap_get_weekday_labels()) entre deux dates incluses, espacées de $step_weeks semaines
 * (1 par défaut = chaque semaine). Deux usages :
 * - $step_weeks = 1, pour proposer les dates candidates d'une génération en masse de dates de
 *   livraison product_grid — jamais utilisée pour valider le formulaire manuel de ces dates, qui
 *   reste volontairement permissif sur le jour de semaine (dates exceptionnelles).
 * - $step_weeks = frequency_weeks d'un contrat basket_recurring, pour ne lister QUE les vraies
 *   distributions d'un contrat bimensuel/etc. (amap_get_member_leave_form_data(),
 *   amap_handle_add_leave(), amap_handle_add_member_leave()) : ancré sur la première occurrence
 *   du jour de semaine à partir de $start_date, jamais sur une semaine de référence arbitraire.
 */
function amap_get_weekday_dates_in_range( $start_date, $end_date, $weekday, $step_weeks = 1 ) {
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

    $interval = new DateInterval( 'P' . ( 7 * max( 1, $step_weeks ) ) . 'D' );
    while ( $current <= $end ) {
        $dates[] = $current->format( 'Y-m-d' );
        $current->add( $interval );
    }

    return $dates;
}

function amap_store_contract_delivery_date_form_data( array $data ) {
    set_transient( 'amap_contract_delivery_date_form_' . get_current_user_id(), $data, 60 );
}

add_action( 'admin_post_amap_add_contract', 'amap_handle_add_contract' );

function amap_handle_add_contract() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_contract' );

    // Même principe que amap_handle_add_group() : le champ caché "redirect_to" indique un
    // affichage front plutôt que wp-admin. La page de retour en cas d'erreur (le formulaire
    // d'ajout) est déterministe des deux côtés ; celle de succès dépend de l'ID généré par
    // l'insertion ci-dessous, donc calculée après coup plutôt que reçue telle quelle du POST.
    $is_front_request = isset( $_POST['redirect_to'] );
    $add_url          = $is_front_request ? amap_get_board_contract_add_url() : admin_url( 'admin.php?page=amap-contracts' );

    $label            = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $producer_user_id = isset( $_POST['producer_user_id'] ) ? absint( $_POST['producer_user_id'] ) : 0;
    $contract_type    = isset( $_POST['contract_type'] ) ? sanitize_key( wp_unslash( $_POST['contract_type'] ) ) : '';
    $start_date       = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
    $end_date         = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
    $frequency_weeks  = isset( $_POST['frequency_weeks'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency_weeks'] ) ) : '';
    $max_leaves       = isset( $_POST['max_leaves'] ) ? sanitize_text_field( wp_unslash( $_POST['max_leaves'] ) ) : '';
    $is_active        = isset( $_POST['is_active'] );
    $submitted        = compact( 'label', 'producer_user_id', 'contract_type', 'start_date', 'end_date', 'frequency_weeks', 'max_leaves', 'is_active' );

    $valid_producer_ids = wp_list_pluck( amap_get_producer_users(), 'ID' );

    if ( '' === $label || ! in_array( $producer_user_id, $valid_producer_ids, true )
        || ! array_key_exists( $contract_type, amap_get_contract_types() ) ) {
        amap_store_contract_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $add_url ) );
        exit;
    }

    if ( ! amap_is_valid_date( $start_date ) || ! amap_is_valid_date( $end_date ) || $start_date >= $end_date ) {
        amap_store_contract_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_dates', $add_url ) );
        exit;
    }

    // frequency_weeks et max_leaves n'ont de sens que pour un panier récurrent : obligatoires
    // dans ce cas, forcés à NULL sinon (même si le formulaire masque les champs en JS, on
    // revalide côté serveur).
    if ( 'basket_recurring' === $contract_type ) {
        if ( '' === $frequency_weeks || ! ctype_digit( $frequency_weeks ) || (int) $frequency_weeks < 1 ) {
            amap_store_contract_form_data( $submitted );
            wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_frequency', $add_url ) );
            exit;
        }
        if ( '' === $max_leaves || ! ctype_digit( $max_leaves ) ) {
            amap_store_contract_form_data( $submitted );
            wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_max_leaves', $add_url ) );
            exit;
        }
        $frequency_weeks = (int) $frequency_weeks;
        $max_leaves      = (int) $max_leaves;
    } else {
        $frequency_weeks = null;
        $max_leaves      = null;
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
            'max_leaves'       => $max_leaves,
            'is_active'        => $is_active ? 1 : 0,
        )
    );

    // Redirige directement vers la fiche du contrat créé plutôt que vers la liste plate : le
    // bureau enchaîne naturellement sur la saisie du contenu du contrat (tailles de panier /
    // produits) sans avoir à le rechercher pour cliquer "Voir le contrat". Côté wp-admin,
    // l'onglet propre au type du contrat s'ouvre directement (&active_tab=...) ; côté front, la
    // section correspondante s'ouvre automatiquement grâce à la notice "created" (voir
    // amap_get_board_contract_view_data()).
    if ( $is_front_request ) {
        $view_url = amap_get_board_contract_view_url( $wpdb->insert_id );
    } else {
        $view_url = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $wpdb->insert_id );
        if ( 'basket_recurring' === $contract_type ) {
            $view_url .= '&active_tab=sizes';
        } elseif ( 'product_grid' === $contract_type ) {
            $view_url .= '&active_tab=products';
        }
    }

    wp_safe_redirect( add_query_arg( 'amap_notice', 'created', $view_url ) );
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

    // Même principe que amap_handle_update_group() : la présence de "redirect_to" distingue le
    // formulaire front (espace bureau) de celui de wp-admin. $edit_url reste la page "Modifier les
    // infos" (où rester en cas d'erreur) ; $view_url est la page de retour en cas de succès — la
    // fiche du contrat côté front, la même page côté wp-admin (pas de fiche séparée).
    $is_front_request = isset( $_POST['redirect_to'] );
    $edit_url          = $is_front_request ? amap_get_board_contract_edit_url( $id ) : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $id );
    $view_url          = $is_front_request ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $edit_url;

    $label            = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $producer_user_id = isset( $_POST['producer_user_id'] ) ? absint( $_POST['producer_user_id'] ) : 0;
    $contract_type    = isset( $_POST['contract_type'] ) ? sanitize_key( wp_unslash( $_POST['contract_type'] ) ) : '';
    $start_date       = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
    $end_date         = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
    $frequency_weeks  = isset( $_POST['frequency_weeks'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency_weeks'] ) ) : '';
    $max_leaves       = isset( $_POST['max_leaves'] ) ? sanitize_text_field( wp_unslash( $_POST['max_leaves'] ) ) : '';
    $is_active        = isset( $_POST['is_active'] );
    $submitted        = compact( 'label', 'producer_user_id', 'contract_type', 'start_date', 'end_date', 'frequency_weeks', 'max_leaves', 'is_active' );

    $valid_producer_ids = wp_list_pluck( amap_get_producer_users(), 'ID' );

    if ( '' === $label || ! in_array( $producer_user_id, $valid_producer_ids, true )
        || ! array_key_exists( $contract_type, amap_get_contract_types() ) ) {
        amap_store_contract_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $edit_url ) );
        exit;
    }

    if ( ! amap_is_valid_date( $start_date ) || ! amap_is_valid_date( $end_date ) || $start_date >= $end_date ) {
        amap_store_contract_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_dates', $edit_url ) );
        exit;
    }

    if ( 'basket_recurring' === $contract_type ) {
        if ( '' === $frequency_weeks || ! ctype_digit( $frequency_weeks ) || (int) $frequency_weeks < 1 ) {
            amap_store_contract_form_data( $submitted );
            wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_frequency', $edit_url ) );
            exit;
        }
        if ( '' === $max_leaves || ! ctype_digit( $max_leaves ) ) {
            amap_store_contract_form_data( $submitted );
            wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_max_leaves', $edit_url ) );
            exit;
        }
        $frequency_weeks = (int) $frequency_weeks;
        $max_leaves      = (int) $max_leaves;
    } else {
        $frequency_weeks = null;
        $max_leaves      = null;
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
            'max_leaves'       => $max_leaves,
            'is_active'        => $is_active ? 1 : 0,
        ),
        array( 'id' => $id )
    );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'updated', $view_url ) );
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

    // Même principe que amap_handle_delete_group() : lien, pas formulaire posté, la page de
    // retour arrive donc en paramètre d'URL. Le front prévalide déjà via
    // amap_get_board_contract_delete_data() ; ce blocage reste un filet de sécurité.
    $list_url = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-contracts' );

    // Bloque plutôt que de supprimer en cascade : un contrat ayant des souscriptions porte un
    // historique (et, depuis le suivi de paiement, des montants payés/impayés) qu'une suppression
    // silencieuse effacerait. Le bureau doit d'abord supprimer les souscriptions concernées depuis
    // la page "Souscriptions".
    if ( amap_contract_has_subscriptions( $id ) ) {
        wp_die( esc_html__( 'Suppression impossible : ce contrat a des souscriptions enregistrées. Supprimez-les d\'abord depuis la page "Souscriptions".', 'association-manager' ) );
    }

    global $wpdb;
    // Pas de contrainte FOREIGN KEY SQL sur contract_id (cohérent avec le reste du plugin) :
    // nettoyage explicite des tables filles orphelines (seules celles correspondant au
    // contract_type de ce contrat contiennent effectivement des lignes, les autres suppressions
    // ne font rien), comme les rattachements producteurs orphelins à la suppression d'un groupe.
    $wpdb->delete( $wpdb->prefix . 'amap_contract_basket_sizes', array( 'contract_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_contract_products', array( 'contract_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_contract_discount_groups', array( 'contract_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_contract_delivery_dates', array( 'contract_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_contracts', array( 'id' => $id ) );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'deleted', $list_url ) );
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

    // Même principe que amap_handle_add_distribution_exception() : "redirect_to" indique la page
    // de retour sur CE contrat (front ou wp-admin) ; erreur et succès reviennent tous les deux
    // dessus, seule la section "Tailles de panier" s'ouvre ou non selon la notice.
    $view_url = isset( $_POST['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=sizes' );

    $label     = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price     = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $submitted = compact( 'label', 'price' );

    if ( '' === $label || ! amap_is_valid_price( $price ) ) {
        amap_store_contract_basket_size_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'basket_size_invalid', $view_url ) );
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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'basket_size_saved', $view_url ) );
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

    $view_url = isset( $_POST['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $basket_size->contract_id . '&active_tab=sizes' );

    $label     = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price     = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $submitted = compact( 'label', 'price' );

    if ( '' === $label || ! amap_is_valid_price( $price ) ) {
        amap_store_contract_basket_size_form_data( $submitted );
        wp_safe_redirect( add_query_arg( array( 'size_action' => 'edit', 'size_id' => $id, 'amap_notice' => 'basket_size_invalid' ), $view_url ) );
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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'basket_size_saved', $view_url ) );
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

    $view_url = isset( $_GET['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $basket_size->contract_id . '&active_tab=sizes' );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_contract_basket_sizes', array( 'id' => $id ) );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'basket_size_deleted', $view_url ) );
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

    $view_url = isset( $_POST['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=products' );

    $label             = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price             = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $discount_group_id = isset( $_POST['discount_group_id'] ) ? absint( $_POST['discount_group_id'] ) : 0;
    $submitted         = compact( 'label', 'price', 'discount_group_id' );

    // Un produit rattaché à une famille de remise n'a pas de prix propre : celui de la famille
    // s'applique (voir amap_handle_update_contract_discount_group() pour le cas symétrique où
    // c'est le prix de la famille qui change).
    $discount_group       = $discount_group_id ? amap_get_contract_discount_group( $discount_group_id ) : null;
    $discount_group_valid = ! $discount_group_id || ( $discount_group && (int) $discount_group->contract_id === $contract_id );

    if ( $discount_group ) {
        $price = $discount_group->price;
    }

    if ( '' === $label || ! $discount_group_valid || ( ! $discount_group && ! amap_is_valid_price( $price ) ) ) {
        amap_store_contract_product_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_product_invalid', $view_url ) );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_contract_products',
        array(
            'contract_id'       => $contract_id,
            'label'             => $label,
            'price'             => (float) $price,
            'discount_group_id' => $discount_group_id ?: null,
        )
    );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_product_saved', $view_url ) );
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

    $view_url = isset( $_POST['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $product->contract_id . '&active_tab=products' );

    $label             = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price             = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $discount_group_id = isset( $_POST['discount_group_id'] ) ? absint( $_POST['discount_group_id'] ) : 0;
    $submitted         = compact( 'label', 'price', 'discount_group_id' );

    $discount_group       = $discount_group_id ? amap_get_contract_discount_group( $discount_group_id ) : null;
    $discount_group_valid = ! $discount_group_id || ( $discount_group && (int) $discount_group->contract_id === (int) $product->contract_id );

    if ( $discount_group ) {
        $price = $discount_group->price;
    }

    if ( '' === $label || ! $discount_group_valid || ( ! $discount_group && ! amap_is_valid_price( $price ) ) ) {
        amap_store_contract_product_form_data( $submitted );
        wp_safe_redirect( add_query_arg( array( 'product_action' => 'edit', 'product_id' => $id, 'amap_notice' => 'contract_product_invalid' ), $view_url ) );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_contract_products',
        array(
            'label'             => $label,
            'price'             => (float) $price,
            'discount_group_id' => $discount_group_id ?: null,
        ),
        array( 'id' => $id )
    );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_product_saved', $view_url ) );
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

    $view_url = isset( $_GET['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $product->contract_id . '&active_tab=products' );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_contract_products', array( 'id' => $id ) );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_product_deleted', $view_url ) );
    exit;
}

add_action( 'admin_post_amap_add_contract_discount_group', 'amap_handle_add_contract_discount_group' );

function amap_handle_add_contract_discount_group() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $contract_id = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $contract    = $contract_id ? amap_get_contract( $contract_id ) : null;
    if ( ! $contract || 'product_grid' !== $contract->contract_type ) {
        wp_die( esc_html__( 'Contrat introuvable ou non concerné par le catalogue produits.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_contract_discount_group_' . $contract_id );

    $view_url = isset( $_POST['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=products' );

    $label           = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price           = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $bought_quantity = isset( $_POST['bought_quantity'] ) ? absint( $_POST['bought_quantity'] ) : 0;
    $billed_quantity = isset( $_POST['billed_quantity'] ) ? absint( $_POST['billed_quantity'] ) : 0;
    $submitted       = compact( 'label', 'price', 'bought_quantity', 'billed_quantity' );

    if ( '' === $label || ! amap_is_valid_price( $price ) || ! amap_is_valid_discount_ratio( $bought_quantity, $billed_quantity ) ) {
        amap_store_contract_discount_group_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_discount_group_invalid', $view_url ) );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_contract_discount_groups',
        array(
            'contract_id'     => $contract_id,
            'label'           => $label,
            'price'           => (float) $price,
            'bought_quantity' => $bought_quantity,
            'billed_quantity' => $billed_quantity,
        )
    );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_discount_group_saved', $view_url ) );
    exit;
}

add_action( 'admin_post_amap_update_contract_discount_group', 'amap_handle_update_contract_discount_group' );

function amap_handle_update_contract_discount_group() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id             = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $discount_group = $id ? amap_get_contract_discount_group( $id ) : null;
    if ( ! $discount_group ) {
        wp_die( esc_html__( 'Famille de remise introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_edit_contract_discount_group_' . $id );

    $view_url = isset( $_POST['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $discount_group->contract_id . '&active_tab=products' );

    $label           = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price           = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $bought_quantity = isset( $_POST['bought_quantity'] ) ? absint( $_POST['bought_quantity'] ) : 0;
    $billed_quantity = isset( $_POST['billed_quantity'] ) ? absint( $_POST['billed_quantity'] ) : 0;
    $submitted       = compact( 'label', 'price', 'bought_quantity', 'billed_quantity' );

    if ( '' === $label || ! amap_is_valid_price( $price ) || ! amap_is_valid_discount_ratio( $bought_quantity, $billed_quantity ) ) {
        amap_store_contract_discount_group_form_data( $submitted );
        wp_safe_redirect( add_query_arg( array( 'discount_action' => 'edit', 'discount_id' => $id, 'amap_notice' => 'contract_discount_group_invalid' ), $view_url ) );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_contract_discount_groups',
        array(
            'label'           => $label,
            'price'           => (float) $price,
            'bought_quantity' => $bought_quantity,
            'billed_quantity' => $billed_quantity,
        ),
        array( 'id' => $id )
    );

    // Le prix d'un produit rattaché à cette famille n'est qu'un miroir du prix ci-dessus (voir
    // amap_handle_add_contract_product()) : le mettre à jour partout où il a été copié, plutôt
    // que d'exiger que le bureau modifie chaque produit un par un.
    $wpdb->update(
        $wpdb->prefix . 'amap_contract_products',
        array( 'price' => (float) $price ),
        array( 'discount_group_id' => $id )
    );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_discount_group_saved', $view_url ) );
    exit;
}

add_action( 'admin_post_amap_delete_contract_discount_group', 'amap_handle_delete_contract_discount_group' );

function amap_handle_delete_contract_discount_group() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id             = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $discount_group = $id ? amap_get_contract_discount_group( $id ) : null;
    if ( ! $discount_group ) {
        wp_die( esc_html__( 'Famille de remise introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_contract_discount_group_' . $id );

    $view_url = isset( $_GET['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $discount_group->contract_id . '&active_tab=products' );

    global $wpdb;
    // Les produits qui en faisaient partie repassent en prix libre (voir le message de
    // confirmation affiché avant suppression) plutôt que de rester rattachés à une famille qui
    // n'existe plus.
    $wpdb->update(
        $wpdb->prefix . 'amap_contract_products',
        array( 'discount_group_id' => null ),
        array( 'discount_group_id' => $id )
    );
    $wpdb->delete( $wpdb->prefix . 'amap_contract_discount_groups', array( 'id' => $id ) );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_discount_group_deleted', $view_url ) );
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

    $view_url = isset( $_POST['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=dates' );
    $group_id           = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $delivery_date      = isset( $_POST['delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_date'] ) ) : '';
    $producer_group_ids = array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) );

    if ( ! $group_id || ! in_array( $group_id, $producer_group_ids, true ) || ! amap_is_valid_date( $delivery_date ) ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_delivery_date_invalid', $view_url ) );
        exit;
    }

    if ( $delivery_date < $contract->start_date || $delivery_date > $contract->end_date ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_delivery_date_out_of_range', $view_url ) );
        exit;
    }

    if ( amap_contract_has_delivery_date( $contract_id, $group_id, $delivery_date ) ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_delivery_date_duplicate', $view_url ) );
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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_delivery_date_saved', $view_url ) );
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

    $view_url = isset( $_POST['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $delivery_row->contract_id . '&active_tab=dates' );
    $group_id           = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $delivery_date      = isset( $_POST['delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_date'] ) ) : '';
    $producer_group_ids = array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) );

    if ( ! $group_id || ! in_array( $group_id, $producer_group_ids, true ) || ! amap_is_valid_date( $delivery_date ) ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( add_query_arg( array( 'date_action' => 'edit', 'date_id' => $id, 'amap_notice' => 'contract_delivery_date_invalid' ), $view_url ) );
        exit;
    }

    if ( $delivery_date < $contract->start_date || $delivery_date > $contract->end_date ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( add_query_arg( array( 'date_action' => 'edit', 'date_id' => $id, 'amap_notice' => 'contract_delivery_date_out_of_range' ), $view_url ) );
        exit;
    }

    if ( amap_contract_has_delivery_date( $delivery_row->contract_id, $group_id, $delivery_date, $id ) ) {
        amap_store_contract_delivery_date_form_data( compact( 'group_id', 'delivery_date' ) );
        wp_safe_redirect( add_query_arg( array( 'date_action' => 'edit', 'date_id' => $id, 'amap_notice' => 'contract_delivery_date_duplicate' ), $view_url ) );
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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_delivery_date_saved', $view_url ) );
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

    $view_url = isset( $_GET['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $delivery_row->contract_id . '&active_tab=dates' );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_contract_delivery_dates', array( 'id' => $id ) );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'contract_delivery_date_deleted', $view_url ) );
    exit;
}

add_action( 'admin_post_amap_generate_contract_delivery_dates', 'amap_handle_generate_contract_delivery_dates' );

/**
 * Insertion en masse depuis la section "Générer des dates" (member-area-board-contract-view.php,
 * dans le thème).
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

    $view_url = isset( $_POST['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=dates' );
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

    wp_safe_redirect(
        add_query_arg(
            array(
                'generate_group_id' => $group_id,
                'amap_notice'       => 'contract_delivery_dates_generated',
                'generated_count'   => $inserted_count,
            ),
            $view_url
        )
    );
    exit;
}

add_action( 'admin_post_amap_bulk_delete_contract_delivery_dates', 'amap_handle_bulk_delete_contract_delivery_dates' );

/**
 * Suppression en masse depuis l'accordéon "Dates de livraison" (member-area-board-contract-view.php,
 * dans le thème), regroupé par groupe de distribution : les dates dont la case est cochée
 * (delivery_date_ids[], cochée = sélectionnée pour l'action) sont supprimées.
 * Défense en profondeur, comme amap_handle_generate_contract_delivery_dates() : la suppression
 * ne porte que sur les dates qui appartiennent réellement à ce couple (contrat, groupe), jamais
 * sur les ID reçus tels quels.
 */
function amap_handle_bulk_delete_contract_delivery_dates() {
    if ( ! current_user_can( 'amap_manage_contracts' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $contract_id = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $group_id    = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $contract    = $contract_id ? amap_get_contract( $contract_id ) : null;
    if ( ! $contract || 'product_grid' !== $contract->contract_type ) {
        wp_die( esc_html__( 'Contrat introuvable ou non concerné par les dates de livraison.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_bulk_delete_contract_delivery_dates_' . $contract_id . '_' . $group_id );

    $view_url = isset( $_POST['redirect_to'] )
        ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
        : admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=dates' );

    $delete_ids = isset( $_POST['delivery_date_ids'] ) ? array_map( 'absint', (array) $_POST['delivery_date_ids'] ) : array();

    global $wpdb;
    $existing_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}amap_contract_delivery_dates WHERE contract_id = %d AND group_id = %d",
            $contract_id,
            $group_id
        )
    );

    $deleted_count = 0;
    foreach ( $existing_rows as $existing_row ) {
        if ( ! in_array( (int) $existing_row->id, $delete_ids, true ) ) {
            continue;
        }
        $wpdb->delete( $wpdb->prefix . 'amap_contract_delivery_dates', array( 'id' => $existing_row->id ) );
        ++$deleted_count;
    }

    wp_safe_redirect(
        add_query_arg(
            array(
                'amap_notice'   => 'contract_delivery_dates_bulk_deleted',
                'deleted_count' => $deleted_count,
            ),
            $view_url
        )
    );
    exit;
}
