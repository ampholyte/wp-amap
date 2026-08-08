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
 * Statut d'un contrat par rapport à aujourd'hui, dérivé de ses dates — distinct de
 * `is_active` (qui ne dit qu'"ouvert à la souscription", indépendamment des dates). Utilisé
 * pour l'affichage de "Mes contrats" côté adhérent.
 */
function amap_get_contract_period_status( $contract ) {
    $today = current_time( 'Y-m-d' );

    if ( $contract->start_date > $today ) {
        return 'upcoming';
    }

    if ( $contract->end_date < $today ) {
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
            <div class="notice notice-error"><p><?php esc_html_e( 'Le nombre de congés maximum est obligatoire et doit être un nombre positif pour un contrat de type panier récurrent.', 'association-manager' ); ?></p></div>
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
                            <input type="number" id="amap-contract-max-leaves" name="max_leaves" min="1" max="52" value="<?php echo esc_attr( $form_data['max_leaves'] ?? '' ); ?>">
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
            $delivery_dates  = amap_get_contract_delivery_dates( $editing_id );
            $weekday_labels  = amap_get_weekday_labels();
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
                            <div id="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-view">
                                <table class="widefat">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Date', 'association-manager' ); ?></th>
                                            <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $group_dates as $delivery_date_row ) : ?>
                                            <tr>
                                                <td><?php echo esc_html( $delivery_date_row->delivery_date ); ?></td>
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
                                <p>
                                    <button type="button" class="button" data-amap-show="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-bulk" data-amap-hide="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-view"><?php esc_html_e( 'Modifier la liste', 'association-manager' ); ?></button>
                                </p>
                            </div>
                            <div id="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-bulk" hidden>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <?php wp_nonce_field( 'amap_bulk_delete_contract_delivery_dates_' . $editing_id . '_' . $group_id_key ); ?>
                                    <input type="hidden" name="action" value="amap_bulk_delete_contract_delivery_dates">
                                    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $editing_id ); ?>">
                                    <input type="hidden" name="group_id" value="<?php echo esc_attr( $group_id_key ); ?>">
                                    <p class="description"><?php esc_html_e( 'Décochez les dates à supprimer, puis enregistrez.', 'association-manager' ); ?></p>
                                    <?php foreach ( $group_dates as $delivery_date_row ) : ?>
                                        <p>
                                            <label>
                                                <input type="checkbox" name="keep_ids[]" value="<?php echo esc_attr( $delivery_date_row->id ); ?>" checked>
                                                <?php echo esc_html( $delivery_date_row->delivery_date ); ?>
                                            </label>
                                        </p>
                                    <?php endforeach; ?>
                                    <p>
                                        <?php submit_button( __( 'Enregistrer', 'association-manager' ), 'primary', 'submit', false ); ?>
                                        <button type="button" class="button" data-amap-show="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-view" data-amap-hide="amap-dates-group-<?php echo esc_attr( $group_id_key ); ?>-bulk"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                                    </p>
                                </form>
                            </div>
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
                            <th><?php esc_html_e( 'Congés max', 'association-manager' ); ?></th>
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
                                <td><?php echo esc_html( null !== $contract->max_leaves ? $contract->max_leaves : '—' ); ?></td>
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
        if ( '' === $max_leaves || ! ctype_digit( $max_leaves ) || (int) $max_leaves < 1 ) {
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
        if ( '' === $max_leaves || ! ctype_digit( $max_leaves ) || (int) $max_leaves < 1 ) {
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

    wp_safe_redirect( $edit_url . '&active_tab=dates&generate_group_id=' . $group_id . '&amap_notice=contract_delivery_dates_generated&generated_count=' . $inserted_count );
    exit;
}

add_action( 'admin_post_amap_bulk_delete_contract_delivery_dates', 'amap_handle_bulk_delete_contract_delivery_dates' );

/**
 * Suppression en masse depuis l'accordéon "Dates de livraison" (amap_render_contracts_page()),
 * regroupé par groupe de distribution : les dates dont la case est restée cochée (keep_ids[])
 * sont conservées, toutes les autres dates de ce contrat et de ce groupe sont supprimées.
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

    $keep_ids = isset( $_POST['keep_ids'] ) ? array_map( 'absint', (array) $_POST['keep_ids'] ) : array();

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
        if ( in_array( (int) $existing_row->id, $keep_ids, true ) ) {
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
