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
            'max_leaves'       => null !== $editing_contract->max_leaves ? (string) $editing_contract->max_leaves : '',
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
            'label'             => $product_editing->label,
            'price'             => (string) $product_editing->price,
            'discount_group_id' => $product_editing->discount_group_id ? (string) $product_editing->discount_group_id : '',
        );
    } else {
        $contract_product_form_data = array();
    }

    // Mode édition d'une famille de remise : ?discount_action=edit&discount_id=Y en plus de
    // ?action=edit&id=X sur cette même page (X = contrat, Y = famille de ce contrat).
    $discount_group_editing_id = 0;
    if ( isset( $_GET['discount_action'], $_GET['discount_id'] ) && 'edit' === $_GET['discount_action'] ) {
        $discount_group_editing_id = absint( $_GET['discount_id'] );
    }
    $discount_group_editing = $discount_group_editing_id ? amap_get_contract_discount_group( $discount_group_editing_id ) : null;
    if ( $discount_group_editing_id && ( ! $discount_group_editing || (int) $discount_group_editing->contract_id !== $editing_id ) ) {
        $discount_group_editing_id = 0;
        $discount_group_editing    = null;
    }

    $contract_discount_group_transient_key = 'amap_contract_discount_group_form_' . get_current_user_id();
    $contract_discount_group_form_data     = get_transient( $contract_discount_group_transient_key );
    if ( false !== $contract_discount_group_form_data ) {
        delete_transient( $contract_discount_group_transient_key );
    } elseif ( $discount_group_editing ) {
        $contract_discount_group_form_data = array(
            'label'           => $discount_group_editing->label,
            'price'           => (string) $discount_group_editing->price,
            'bought_quantity' => (string) $discount_group_editing->bought_quantity,
            'billed_quantity' => (string) $discount_group_editing->billed_quantity,
        );
    } else {
        $contract_discount_group_form_data = array();
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
    $weekday_labels = amap_get_weekday_labels();

    // Groupes déjà rattachés à chaque producteur (amap_get_producer_groups()), précalculés pour
    // tous les producteurs et injectés en JSON : affichés sous le champ "Producteur" du
    // formulaire (ajout et modification) pour repérer tout de suite un producteur pas encore
    // rattaché à un groupe, plutôt que de le découvrir plus tard via un onglet vide (dates de
    // livraison, souscriptions) qui ressemble à un bug.
    $producer_groups_js_data = array();
    foreach ( $producers as $producer ) {
        $producer_groups_js_data[ $producer->ID ] = array_map(
            static function ( $group ) use ( $weekday_labels ) {
                return sprintf(
                    '%1$s (%2$s %3$s-%4$s)',
                    $group->name,
                    $weekday_labels[ (int) $group->weekday ] ?? '',
                    amap_format_time( $group->start_time ),
                    amap_format_time( $group->end_time )
                );
            },
            amap_get_producer_groups( $producer->ID )
        );
    }

    // Lien cliquable vers la page "Groupes", réutilisé dans les messages d'avertissement
    // "producteur non rattaché" ci-dessous — ouvert dans un nouvel onglet pour ne pas perdre la
    // saisie en cours (formulaire "Ajouter un contrat", ou onglet "Dates de livraison") en cas de
    // navigation. Après rattachement dans cet autre onglet, un rechargement de la page Contrats
    // reste nécessaire pour voir la liste de dates se débloquer (les onglets Produits/Dates sont
    // basculés en JS sans rechargement, donc sans nouvel appel à amap_get_producer_groups()).
    $groups_page_link_html = '<a href="' . esc_url( admin_url( 'admin.php?page=amap-groups' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'la page Groupes', 'association-manager' ) . '</a>';

    $no_producer_groups_html = sprintf(
        /* translators: %s: lien vers la page d'administration "Groupes". */
        __( "Ce producteur n'est rattaché à aucun groupe de distribution : rattachez-le d'abord depuis %s, sinon les adhérents ne pourront pas souscrire à ses contrats.", 'association-manager' ),
        $groups_page_link_html
    );

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
    } elseif ( 'products' === $requested_tab || $product_editing_id || $discount_group_editing_id ) {
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
        details.amap-dates-group {
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 12px;
            background: #fff;
        }
        details.amap-dates-group summary {
            cursor: pointer;
            font-weight: 600;
            padding: 4px 0;
        }
        details.amap-dates-group[open] summary {
            margin-bottom: 8px;
        }
        #amap-contract-producer-groups.amap-hint-warning {
            color: #b32d2e;
            font-weight: 600;
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
        <?php elseif ( 'invalid_max_leaves' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Le nombre de congés maximum est obligatoire et doit être un nombre entier positif ou nul (0 = aucun congé autorisé) pour un contrat de type panier récurrent.', 'association-manager' ); ?></p></div>
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
                    <a href="#" class="nav-tab<?php echo ( 'amap-contract-form-wrapper' === $active_contract_tab ) ? ' nav-tab-active' : ''; ?>" data-amap-tab="amap-contract-form-wrapper" data-amap-tab-query=""><?php esc_html_e( 'Infos du contrat', 'association-manager' ); ?></a>
                    <?php if ( 'basket_recurring' === $editing_contract->contract_type ) : ?>
                        <a href="#" class="nav-tab<?php echo ( 'amap-tab-sizes' === $active_contract_tab ) ? ' nav-tab-active' : ''; ?>" data-amap-tab="amap-tab-sizes" data-amap-tab-query="sizes"><?php esc_html_e( 'Tailles de panier', 'association-manager' ); ?></a>
                    <?php elseif ( 'product_grid' === $editing_contract->contract_type ) : ?>
                        <a href="#" class="nav-tab<?php echo ( 'amap-tab-products' === $active_contract_tab ) ? ' nav-tab-active' : ''; ?>" data-amap-tab="amap-tab-products" data-amap-tab-query="products"><?php esc_html_e( 'Produits', 'association-manager' ); ?></a>
                        <a href="#" class="nav-tab<?php echo ( 'amap-tab-dates' === $active_contract_tab ) ? ' nav-tab-active' : ''; ?>" data-amap-tab="amap-tab-dates" data-amap-tab-query="dates"><?php esc_html_e( 'Dates de livraison', 'association-manager' ); ?></a>
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
                            <?php if ( null !== $editing_contract->max_leaves ) : ?>
                                <tr>
                                    <th><?php esc_html_e( 'Congés maximum autorisés', 'association-manager' ); ?></th>
                                    <td><?php echo esc_html( $editing_contract->max_leaves ); ?></td>
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
                            <p class="description" id="amap-contract-producer-groups"></p>
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
                    <tr id="amap-contract-max-leaves-row">
                        <th><label for="amap-contract-max-leaves"><?php esc_html_e( 'Congés maximum autorisés', 'association-manager' ); ?></label></th>
                        <td>
                            <input type="number" id="amap-contract-max-leaves" name="max_leaves" min="0" max="52" value="<?php echo esc_attr( $form_data['max_leaves'] ?? '' ); ?>">
                            <p class="description"><?php esc_html_e( 'Nombre de congés maraîcher qu\'un adhérent peut poser sur la durée de ce contrat. Uniquement pour un panier récurrent.', 'association-manager' ); ?></p>
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
                var maxLeavesRow  = document.getElementById( 'amap-contract-max-leaves-row' );

                function toggleFrequencyRow() {
                    var isBasketRecurring = ( 'basket_recurring' === typeField.value );
                    frequencyRow.hidden = ! isBasketRecurring;
                    maxLeavesRow.hidden = ! isBasketRecurring;
                }

                typeField.addEventListener( 'change', toggleFrequencyRow );
                toggleFrequencyRow();
            } )();
            </script>
            <script>
            ( function () {
                var producerField       = document.getElementById( 'amap-contract-producer' );
                var producerGroupsHint  = document.getElementById( 'amap-contract-producer-groups' );
                var producerGroupsData  = <?php echo wp_json_encode( $producer_groups_js_data ); ?>;
                var groupsPrefixLabel   = <?php echo wp_json_encode( __( 'Groupes livrés : ', 'association-manager' ) ); ?>;
                var noGroupsHtml        = <?php echo wp_json_encode( $no_producer_groups_html ); ?>;

                function updateProducerGroupsHint() {
                    var groups = producerGroupsData[ producerField.value ] || [];
                    producerGroupsHint.classList.remove( 'amap-hint-warning' );
                    if ( ! producerField.value ) {
                        producerGroupsHint.textContent = '';
                    } else if ( 0 === groups.length ) {
                        producerGroupsHint.innerHTML = noGroupsHtml;
                        producerGroupsHint.classList.add( 'amap-hint-warning' );
                    } else {
                        producerGroupsHint.textContent = groupsPrefixLabel + groups.join( ', ' );
                    }
                }

                producerField.addEventListener( 'change', updateProducerGroupsHint );
                updateProducerGroupsHint();
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
            <div class="postbox amap-tab-panel" id="amap-tab-sizes"<?php echo ( 'amap-tab-sizes' === $active_contract_tab ) ? '' : ' hidden'; ?>>
            <div class="inside">
            <?php if ( 'basket_size_invalid' === $notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Libellé ou prix invalide.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'basket_size_saved' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Taille de panier enregistrée.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'basket_size_deleted' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Taille de panier supprimée.', 'association-manager' ); ?></p></div>
            <?php endif; ?>

            <?php
            $basket_sizes_list_table = new Amap_Contract_Basket_Sizes_List_Table();
            $basket_sizes_list_table->prepare_items( $editing_id );
            $basket_sizes_list_table->display();
            ?>

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
            <?php $discount_groups = amap_get_contract_discount_groups( $editing_id ); ?>
            <div class="postbox amap-tab-panel" id="amap-tab-products"<?php echo ( 'amap-tab-products' === $active_contract_tab ) ? '' : ' hidden'; ?>>
            <div class="inside">
            <h2><?php esc_html_e( 'Familles de remise', 'association-manager' ); ?></h2>
            <p class="description">
                <?php esc_html_e( "Un groupe de produits qui partagent un même prix et une remise par quantité : ex. « 6 achetés, 5 facturés », tous produits de la famille confondus sur toute la souscription.", 'association-manager' ); ?>
            </p>
            <?php if ( 'contract_discount_group_invalid' === $notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Libellé, prix ou seuils invalides : la quantité facturée doit être strictement inférieure à la quantité achetée.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'contract_discount_group_saved' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Famille de remise enregistrée.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'contract_discount_group_deleted' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Famille de remise supprimée.', 'association-manager' ); ?></p></div>
            <?php endif; ?>

            <?php
            $discount_groups_list_table = new Amap_Contract_Discount_Groups_List_Table();
            $discount_groups_list_table->prepare_items( $editing_id );
            $discount_groups_list_table->display();
            ?>

            <?php if ( ! $discount_group_editing_id ) : ?>
                <p>
                    <button type="button" class="button button-primary" id="amap-discount-group-add-toggle"><?php esc_html_e( '+ Ajouter une famille de remise', 'association-manager' ); ?></button>
                </p>
            <?php endif; ?>
            <div id="amap-discount-group-form-wrapper"<?php echo $discount_group_editing_id ? '' : ' hidden'; ?>>
            <h3>
                <?php echo $discount_group_editing_id
                    ? esc_html__( 'Modifier une famille de remise', 'association-manager' )
                    : esc_html__( 'Ajouter une famille de remise', 'association-manager' ); ?>
            </h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php if ( $discount_group_editing_id ) : ?>
                    <?php wp_nonce_field( 'amap_edit_contract_discount_group_' . $discount_group_editing_id ); ?>
                    <input type="hidden" name="action" value="amap_update_contract_discount_group">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $discount_group_editing_id ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_contract_discount_group_' . $editing_id ); ?>
                    <input type="hidden" name="action" value="amap_add_contract_discount_group">
                    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $editing_id ); ?>">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="amap-discount-group-label"><?php esc_html_e( 'Libellé', 'association-manager' ); ?></label></th>
                        <td><input type="text" id="amap-discount-group-label" name="label" value="<?php echo esc_attr( $contract_discount_group_form_data['label'] ?? '' ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="amap-discount-group-price"><?php esc_html_e( 'Prix unitaire (€)', 'association-manager' ); ?></label></th>
                        <td><input type="number" id="amap-discount-group-price" name="price" min="0.01" step="0.01" value="<?php echo esc_attr( $contract_discount_group_form_data['price'] ?? '' ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="amap-discount-group-bought-quantity"><?php esc_html_e( 'Quantité achetée', 'association-manager' ); ?></label></th>
                        <td><input type="number" id="amap-discount-group-bought-quantity" name="bought_quantity" min="2" step="1" value="<?php echo esc_attr( $contract_discount_group_form_data['bought_quantity'] ?? '' ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="amap-discount-group-billed-quantity"><?php esc_html_e( 'Quantité facturée', 'association-manager' ); ?></label></th>
                        <td><input type="number" id="amap-discount-group-billed-quantity" name="billed_quantity" min="1" step="1" value="<?php echo esc_attr( $contract_discount_group_form_data['billed_quantity'] ?? '' ); ?>" required></td>
                    </tr>
                </table>
                <p>
                    <?php submit_button( $discount_group_editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                    <?php if ( $discount_group_editing_id ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $editing_id . '&active_tab=products' ) ); ?>" class="button">
                            <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                        </a>
                    <?php else : ?>
                        <button type="button" class="button" id="amap-discount-group-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php endif; ?>
                </p>
            </form>
            </div>
            <script>
            ( function () {
                var toggle  = document.getElementById( 'amap-discount-group-add-toggle' );
                var wrapper = document.getElementById( 'amap-discount-group-form-wrapper' );
                var cancel  = document.getElementById( 'amap-discount-group-add-cancel' );
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

            <hr>

            <h2><?php esc_html_e( 'Catalogue produits', 'association-manager' ); ?></h2>
            <?php if ( 'contract_product_invalid' === $notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Libellé ou prix invalide.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'contract_product_saved' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Produit enregistré.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'contract_product_deleted' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Produit supprimé.', 'association-manager' ); ?></p></div>
            <?php endif; ?>

            <?php
            $contract_products_list_table = new Amap_Contract_Products_List_Table();
            $contract_products_list_table->prepare_items( $editing_id );
            $contract_products_list_table->display();
            ?>

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
                        <th><label for="amap-contract-product-discount-group"><?php esc_html_e( 'Famille de remise', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-contract-product-discount-group" name="discount_group_id">
                                <option value=""><?php esc_html_e( 'Aucune (prix libre)', 'association-manager' ); ?></option>
                                <?php foreach ( $discount_groups as $discount_group ) : ?>
                                    <option
                                        value="<?php echo esc_attr( $discount_group->id ); ?>"
                                        data-price="<?php echo esc_attr( number_format( (float) $discount_group->price, 2, '.', '' ) ); ?>"
                                        <?php selected( $contract_product_form_data['discount_group_id'] ?? '', (string) $discount_group->id ); ?>
                                    ><?php echo esc_html( $discount_group->label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amap-contract-product-price"><?php esc_html_e( 'Prix (€)', 'association-manager' ); ?></label></th>
                        <td>
                            <input type="number" id="amap-contract-product-price" name="price" min="0.01" step="0.01" value="<?php echo esc_attr( $contract_product_form_data['price'] ?? '' ); ?>">
                            <p class="description" id="amap-contract-product-price-hint" hidden>
                                <?php esc_html_e( 'Prix défini par la famille :', 'association-manager' ); ?>
                                <strong id="amap-contract-product-price-hint-value"></strong> €
                            </p>
                        </td>
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

                // Le prix d'un produit rattaché à une famille de remise est celui de la famille
                // (voir amap_handle_add_contract_product()) : le champ prix est alors masqué et
                // remplacé par un rappel de ce prix, plutôt que de laisser saisir une valeur
                // ignorée à l'enregistrement.
                var groupSelect    = document.getElementById( 'amap-contract-product-discount-group' );
                var priceInput     = document.getElementById( 'amap-contract-product-price' );
                var priceHint      = document.getElementById( 'amap-contract-product-price-hint' );
                var priceHintValue = document.getElementById( 'amap-contract-product-price-hint-value' );

                function updateProductPriceField() {
                    var option  = groupSelect.options[ groupSelect.selectedIndex ];
                    var grouped = !! ( option && option.value !== '' );

                    priceInput.hidden   = grouped;
                    priceInput.required = ! grouped;
                    priceHint.hidden    = ! grouped;

                    if ( grouped ) {
                        priceHintValue.textContent = option.dataset.price;
                    }
                }

                if ( groupSelect ) {
                    groupSelect.addEventListener( 'change', updateProductPriceField );
                    updateProductPriceField();
                }
            } )();
            </script>

            <?php
            $delivery_dates  = amap_get_contract_delivery_dates( $editing_id );
            $producer_groups = amap_get_producer_groups( $editing_contract->producer_user_id );

            $dates_by_group = array();
            foreach ( $delivery_dates as $delivery_date_row ) {
                $dates_by_group[ (int) $delivery_date_row->group_id ][] = $delivery_date_row;
            }

            // Accordéon à ouvrir automatiquement à l'affichage : celui qu'on vient d'utiliser
            // pour générer des dates en masse (generate_group_id, posé par
            // amap_handle_generate_contract_delivery_dates()), ou celui dont la soumission
            // "+ Ajouter une date" vient d'échouer (le groupe soumis est alors dans
            // $contract_delivery_date_form_data, rechargé depuis le transient plus haut).
            $add_error_notices   = array( 'contract_delivery_date_invalid', 'contract_delivery_date_out_of_range', 'contract_delivery_date_duplicate' );
            $reopen_add_group_id = ( ! $delivery_date_editing_id && in_array( $notice, $add_error_notices, true ) )
                ? (int) ( $contract_delivery_date_form_data['group_id'] ?? 0 )
                : 0;
            $open_group_id = $reopen_add_group_id ?: $generate_group_id;
            ?>
            <div class="postbox amap-tab-panel" id="amap-tab-dates"<?php echo ( 'amap-tab-dates' === $active_contract_tab ) ? '' : ' hidden'; ?>>
            <div class="inside">

            <?php if ( empty( $producer_groups ) ) : ?>
                <p>
                    <?php
                    printf(
                        /* translators: %s: lien vers la page d'administration "Groupes". */
                        esc_html__( "Ce producteur n'est rattaché à aucun groupe de distribution. Rattachez-le d'abord à un groupe depuis %s avant d'ajouter des dates de livraison, puis rechargez cette page.", 'association-manager' ),
                        $groups_page_link_html
                    );
                    ?>
                </p>
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
                <?php elseif ( 'contract_delivery_dates_bulk_deleted' === $notice ) : ?>
                    <?php $bulk_deleted_count = isset( $_GET['deleted_count'] ) ? absint( $_GET['deleted_count'] ) : 0; ?>
                    <div class="notice notice-success"><p>
                        <?php
                        printf(
                            esc_html(
                                /* translators: %d: nombre de dates supprimées. */
                                _n( '%d date de livraison supprimée.', '%d dates de livraison supprimées.', $bulk_deleted_count, 'association-manager' )
                            ),
                            $bulk_deleted_count
                        );
                        ?>
                    </p></div>
                <?php endif; ?>

                <?php foreach ( $producer_groups as $group_option ) : ?>
                    <?php
                    $group_id_key         = (int) $group_option->id;
                    $group_dates          = $dates_by_group[ $group_id_key ] ?? array();
                    $all_weekday_dates    = amap_get_weekday_dates_in_range( $editing_contract->start_date, $editing_contract->end_date, (int) $group_option->weekday );
                    $existing_group_dates = amap_get_contract_delivery_dates_for_group( $editing_id, $group_id_key );
                    $candidate_dates      = array_values( array_diff( $all_weekday_dates, $existing_group_dates ) );
                    $show_add_form        = ( $reopen_add_group_id === $group_id_key );
                    ?>
                    <details class="amap-dates-group"<?php echo ( $open_group_id === $group_id_key ) ? ' open' : ''; ?>>
                        <summary>
                            <?php
                            printf(
                                esc_html(
                                    /* translators: 1: nom du groupe, 2: jour de la semaine, 3: nombre de dates. */
                                    _n( '%1$s — %2$s (%3$d date)', '%1$s — %2$s (%3$d dates)', count( $group_dates ), 'association-manager' )
                                ),
                                esc_html( $group_option->name ),
                                esc_html( $weekday_labels[ (int) $group_option->weekday ] ),
                                count( $group_dates )
                            );
                            ?>
                        </summary>

                        <?php if ( empty( $group_dates ) ) : ?>
                            <p><?php esc_html_e( 'Aucune date enregistrée pour ce groupe.', 'association-manager' ); ?></p>
                        <?php else : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-bulk-delete-dates-form">
                                <?php wp_nonce_field( 'amap_bulk_delete_contract_delivery_dates_' . $editing_id . '_' . $group_id_key ); ?>
                                <input type="hidden" name="action" value="amap_bulk_delete_contract_delivery_dates">
                                <input type="hidden" name="contract_id" value="<?php echo esc_attr( $editing_id ); ?>">
                                <input type="hidden" name="group_id" value="<?php echo esc_attr( $group_id_key ); ?>">
                                <?php
                                $delivery_dates_list_table = new Amap_Contract_Delivery_Dates_List_Table();
                                $delivery_dates_list_table->prepare_items( $group_dates );
                                $delivery_dates_list_table->display();
                                ?>
                                <p>
                                    <?php submit_button( __( 'Supprimer les dates sélectionnées', 'association-manager' ), 'secondary', 'submit', false ); ?>
                                </p>
                            </form>
                        <?php endif; ?>

                        <?php if ( ! empty( $candidate_dates ) ) : ?>
                            <p>
                                <button type="button" class="button" data-amap-show="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-generate"><?php esc_html_e( 'Générer des dates pour ce groupe', 'association-manager' ); ?></button>
                            </p>
                            <div id="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-generate" hidden>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <?php wp_nonce_field( 'amap_generate_contract_delivery_dates_' . $editing_id . '_' . $group_id_key ); ?>
                                    <input type="hidden" name="action" value="amap_generate_contract_delivery_dates">
                                    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $editing_id ); ?>">
                                    <input type="hidden" name="group_id" value="<?php echo esc_attr( $group_id_key ); ?>">
                                    <p>
                                        <label>
                                            <?php esc_html_e( 'Cocher une date sur…', 'association-manager' ); ?>
                                            <input type="number" class="amap-generate-frequency" data-group-id="<?php echo esc_attr( $group_id_key ); ?>" min="1" max="52" value="1">
                                        </label>
                                        <button type="button" class="button amap-generate-apply-frequency" data-group-id="<?php echo esc_attr( $group_id_key ); ?>"><?php esc_html_e( 'Appliquer', 'association-manager' ); ?></button>
                                        <br><span class="description"><?php esc_html_e( '1 = toutes les dates (défaut), 2 = une sur deux, etc. Purement indicatif : décochez/recochez librement avant de valider.', 'association-manager' ); ?></span>
                                    </p>
                                    <?php foreach ( $candidate_dates as $candidate_index => $candidate_date ) : ?>
                                        <p>
                                            <label>
                                                <input type="checkbox" class="amap-generate-date-checkbox" data-group-id="<?php echo esc_attr( $group_id_key ); ?>" data-index="<?php echo esc_attr( $candidate_index ); ?>" name="delivery_dates[]" value="<?php echo esc_attr( $candidate_date ); ?>" checked>
                                                <?php echo esc_html( date_i18n( 'l j F Y', strtotime( $candidate_date ) ) ); ?>
                                            </label>
                                        </p>
                                    <?php endforeach; ?>
                                    <p>
                                        <?php submit_button( __( 'Générer les dates cochées', 'association-manager' ), 'primary', 'submit', false ); ?>
                                        <button type="button" class="button" data-amap-hide="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-generate"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                                    </p>
                                </form>
                            </div>
                        <?php endif; ?>

                        <p>
                            <button type="button" class="button button-primary" data-amap-show="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-add"><?php esc_html_e( '+ Ajouter une date pour ce groupe', 'association-manager' ); ?></button>
                        </p>
                        <div id="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-add"<?php echo $show_add_form ? '' : ' hidden'; ?>>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'amap_add_contract_delivery_date_' . $editing_id ); ?>
                                <input type="hidden" name="action" value="amap_add_contract_delivery_date">
                                <input type="hidden" name="contract_id" value="<?php echo esc_attr( $editing_id ); ?>">
                                <input type="hidden" name="group_id" value="<?php echo esc_attr( $group_id_key ); ?>">
                                <table class="form-table">
                                    <tr>
                                        <th><label for="amap-delivery-date-date-<?php echo esc_attr( $group_id_key ); ?>"><?php esc_html_e( 'Date de livraison', 'association-manager' ); ?></label></th>
                                        <td>
                                            <input type="date" id="amap-delivery-date-date-<?php echo esc_attr( $group_id_key ); ?>" name="delivery_date" min="<?php echo esc_attr( $editing_contract->start_date ); ?>" max="<?php echo esc_attr( $editing_contract->end_date ); ?>" value="<?php echo esc_attr( $show_add_form ? ( $contract_delivery_date_form_data['delivery_date'] ?? '' ) : '' ); ?>" required>
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
                                    <?php submit_button( __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                                    <button type="button" class="button" data-amap-hide="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-add"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                                </p>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
                <script>
                ( function () {
                    document.querySelectorAll( '[data-amap-show], [data-amap-hide]' ).forEach( function ( btn ) {
                        btn.addEventListener( 'click', function () {
                            if ( btn.dataset.amapShow ) {
                                var showEl = document.getElementById( btn.dataset.amapShow );
                                if ( showEl ) {
                                    showEl.hidden = false;
                                }
                            }
                            if ( btn.dataset.amapHide ) {
                                var hideEl = document.getElementById( btn.dataset.amapHide );
                                if ( hideEl ) {
                                    hideEl.hidden = true;
                                }
                            }
                        } );
                    } );
                    document.querySelectorAll( '.amap-generate-apply-frequency' ).forEach( function ( btn ) {
                        btn.addEventListener( 'click', function () {
                            var groupId    = btn.dataset.groupId;
                            var freqInput  = document.querySelector( '.amap-generate-frequency[data-group-id="' + groupId + '"]' );
                            var frequency  = freqInput ? ( parseInt( freqInput.value, 10 ) || 1 ) : 1;
                            var checkboxes = document.querySelectorAll( '.amap-generate-date-checkbox[data-group-id="' + groupId + '"]' );
                            checkboxes.forEach( function ( checkbox ) {
                                checkbox.checked = ( parseInt( checkbox.dataset.index, 10 ) % frequency === 0 );
                            } );
                        } );
                    } );

                    // Confirmation avant suppression en masse des dates cochées, avec leur nombre
                    // affiché : une case "tout cocher" rend une suppression involontaire de toutes
                    // les dates d'un groupe plus facile qu'avec l'ancien panneau "Modifier la
                    // liste", d'où l'intérêt d'afficher clairement l'ampleur de l'action avant de
                    // valider plutôt qu'un message générique.
                    var confirmSingular = <?php echo wp_json_encode( __( 'Supprimer la date sélectionnée ?', 'association-manager' ) ); ?>;
                    var confirmPlural   = <?php echo wp_json_encode( __( 'Supprimer les %d dates sélectionnées ?', 'association-manager' ) ); ?>;
                    document.querySelectorAll( '.amap-bulk-delete-dates-form' ).forEach( function ( form ) {
                        form.addEventListener( 'submit', function ( event ) {
                            var checkedCount = form.querySelectorAll( 'input[name="delivery_date_ids[]"]:checked' ).length;
                            if ( ! checkedCount ) {
                                return;
                            }
                            var message = ( checkedCount > 1 ) ? confirmPlural.replace( '%d', checkedCount ) : confirmSingular;
                            if ( ! window.confirm( message ) ) {
                                event.preventDefault();
                            }
                        } );
                    } );
                } )();
                </script>

                <?php if ( $delivery_date_editing_id ) : ?>
                    <h3><?php esc_html_e( 'Modifier une date de livraison', 'association-manager' ); ?></h3>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'amap_edit_contract_delivery_date_' . $delivery_date_editing_id ); ?>
                        <input type="hidden" name="action" value="amap_update_contract_delivery_date">
                        <input type="hidden" name="id" value="<?php echo esc_attr( $delivery_date_editing_id ); ?>">
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
                            <?php submit_button( __( 'Enregistrer', 'association-manager' ), 'primary', 'submit', false ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $editing_id . '&active_tab=dates' ) ); ?>" class="button">
                                <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                            </a>
                        </p>
                    </form>
                <?php endif; ?>
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

                        // Bascule d'onglet purement en JS (pas de rechargement) : sans ceci,
                        // l'URL ne reflète jamais l'onglet affiché et un simple F5 (par ex. après
                        // avoir rattaché le producteur à un groupe dans un autre onglet du
                        // navigateur, voir le message d'avertissement de "Dates de livraison")
                        // ramène toujours sur "Infos du contrat".
                        var url = new URL( window.location.href );
                        if ( tab.dataset.amapTabQuery ) {
                            url.searchParams.set( 'active_tab', tab.dataset.amapTabQuery );
                        } else {
                            url.searchParams.delete( 'active_tab' );
                        }
                        history.replaceState( null, '', url );
                    } );
                } );
            } )();
            </script>
        <?php endif; ?>

        <?php if ( ! $editing_id ) : ?>
            <?php
            $contracts_list_table = new Amap_Contracts_List_Table();
            $contracts_list_table->prepare_items();
            ?>
            <form method="get">
                <input type="hidden" name="page" value="amap-contracts">
                <?php
                $contracts_list_table->search_box( __( 'Rechercher', 'association-manager' ), 'amap-contract' );
                $contracts_list_table->display();
                ?>
            </form>
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
    $max_leaves       = isset( $_POST['max_leaves'] ) ? sanitize_text_field( wp_unslash( $_POST['max_leaves'] ) ) : '';
    $is_active        = isset( $_POST['is_active'] );
    $submitted        = compact( 'label', 'producer_user_id', 'contract_type', 'start_date', 'end_date', 'frequency_weeks', 'max_leaves', 'is_active' );

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

    // frequency_weeks et max_leaves n'ont de sens que pour un panier récurrent : obligatoires
    // dans ce cas, forcés à NULL sinon (même si le formulaire masque les champs en JS, on
    // revalide côté serveur).
    if ( 'basket_recurring' === $contract_type ) {
        if ( '' === $frequency_weeks || ! ctype_digit( $frequency_weeks ) || (int) $frequency_weeks < 1 ) {
            amap_store_contract_form_data( $submitted );
            wp_safe_redirect( admin_url( 'admin.php?page=amap-contracts&amap_notice=invalid_frequency' ) );
            exit;
        }
        if ( '' === $max_leaves || ! ctype_digit( $max_leaves ) ) {
            amap_store_contract_form_data( $submitted );
            wp_safe_redirect( admin_url( 'admin.php?page=amap-contracts&amap_notice=invalid_max_leaves' ) );
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

    // Redirige directement vers la page du contrat créé, sur l'onglet propre à son type (tailles
    // de panier / produits), plutôt que vers la liste plate : le bureau enchaîne naturellement sur
    // la saisie du contenu du contrat sans avoir à le rechercher pour cliquer "Voir le contrat".
    $edit_url = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $wpdb->insert_id );
    if ( 'basket_recurring' === $contract_type ) {
        $edit_url .= '&active_tab=sizes';
    } elseif ( 'product_grid' === $contract_type ) {
        $edit_url .= '&active_tab=products';
    }

    wp_safe_redirect( $edit_url );
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
    $max_leaves       = isset( $_POST['max_leaves'] ) ? sanitize_text_field( wp_unslash( $_POST['max_leaves'] ) ) : '';
    $is_active        = isset( $_POST['is_active'] );
    $submitted        = compact( 'label', 'producer_user_id', 'contract_type', 'start_date', 'end_date', 'frequency_weeks', 'max_leaves', 'is_active' );

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
        if ( '' === $max_leaves || ! ctype_digit( $max_leaves ) ) {
            amap_store_contract_form_data( $submitted );
            wp_safe_redirect( $edit_url . '&amap_notice=invalid_max_leaves' );
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
        wp_safe_redirect( $edit_url . '&amap_notice=contract_product_invalid' );
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
        wp_safe_redirect( $edit_url . '&product_action=edit&product_id=' . $id . '&amap_notice=contract_product_invalid' );
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

    $edit_url = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=products' );

    $label           = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price           = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $bought_quantity = isset( $_POST['bought_quantity'] ) ? absint( $_POST['bought_quantity'] ) : 0;
    $billed_quantity = isset( $_POST['billed_quantity'] ) ? absint( $_POST['billed_quantity'] ) : 0;
    $submitted       = compact( 'label', 'price', 'bought_quantity', 'billed_quantity' );

    if ( '' === $label || ! amap_is_valid_price( $price ) || ! amap_is_valid_discount_ratio( $bought_quantity, $billed_quantity ) ) {
        amap_store_contract_discount_group_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=contract_discount_group_invalid' );
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

    wp_safe_redirect( $edit_url . '&amap_notice=contract_discount_group_saved' );
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

    $edit_url = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $discount_group->contract_id . '&active_tab=products' );

    $label           = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $price           = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
    $bought_quantity = isset( $_POST['bought_quantity'] ) ? absint( $_POST['bought_quantity'] ) : 0;
    $billed_quantity = isset( $_POST['billed_quantity'] ) ? absint( $_POST['billed_quantity'] ) : 0;
    $submitted       = compact( 'label', 'price', 'bought_quantity', 'billed_quantity' );

    if ( '' === $label || ! amap_is_valid_price( $price ) || ! amap_is_valid_discount_ratio( $bought_quantity, $billed_quantity ) ) {
        amap_store_contract_discount_group_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&discount_action=edit&discount_id=' . $id . '&amap_notice=contract_discount_group_invalid' );
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

    wp_safe_redirect( $edit_url . '&amap_notice=contract_discount_group_saved' );
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

    wp_safe_redirect(
        admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $discount_group->contract_id . '&active_tab=products&amap_notice=contract_discount_group_deleted' )
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

    wp_safe_redirect( $edit_url . '&active_tab=dates&generate_group_id=' . $group_id . '&amap_notice=contract_delivery_dates_generated&generated_count=' . $inserted_count );
    exit;
}

add_action( 'admin_post_amap_bulk_delete_contract_delivery_dates', 'amap_handle_bulk_delete_contract_delivery_dates' );

/**
 * Suppression en masse depuis l'accordéon "Dates de livraison" (amap_render_contracts_page()),
 * regroupé par groupe de distribution : les dates dont la case est cochée (delivery_date_ids[],
 * convention WP_List_Table standard — cochée = sélectionnée pour l'action) sont supprimées.
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
        admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract_id . '&active_tab=dates&amap_notice=contract_delivery_dates_bulk_deleted&deleted_count=' . $deleted_count )
    );
    exit;
}
