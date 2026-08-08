<?php
/**
 * Page d'admin "Souscriptions" : CRUD des souscriptions adhérent<->contrat.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function amap_get_member_users() {
    $user_query = new WP_User_Query(
        array(
            'role'    => 'amap_member',
            'orderby' => 'display_name',
            'order'   => 'ASC',
        )
    );

    return $user_query->get_results();
}

function amap_get_subscriptions() {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}amap_subscriptions ORDER BY signed_at DESC"
    );
}

function amap_get_subscription( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_subscriptions WHERE id = %d", $id )
    );
}

/**
 * Revérifie côté PHP la contrainte UNIQUE(contract_id, member_user_id), même principe que
 * amap_contract_has_delivery_date().
 */
function amap_member_has_subscription( $contract_id, $member_user_id, $exclude_id = 0 ) {
    global $wpdb;

    $sql    = "SELECT COUNT(*) FROM {$wpdb->prefix}amap_subscriptions WHERE contract_id = %d AND member_user_id = %d";
    $params = array( $contract_id, $member_user_id );

    if ( $exclude_id ) {
        $sql     .= ' AND id != %d';
        $params[] = $exclude_id;
    }

    return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
}

function amap_store_subscription_form_data( array $data ) {
    set_transient( 'amap_subscription_form_' . get_current_user_id(), $data, 60 );
}

function amap_get_subscription_items( $subscription_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_subscription_items WHERE subscription_id = %d",
            $subscription_id
        )
    );
}

function amap_render_subscriptions_page() {
    if ( ! current_user_can( 'amap_manage_subscriptions' ) ) {
        return;
    }

    $notice = isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '';

    // Mode édition : ?action=edit&id=X sur cette même page, même logique que "Groupes" et
    // "Contrats".
    $editing_id = 0;
    if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ) {
        $editing_id = absint( $_GET['id'] );
    }
    $editing_subscription = $editing_id ? amap_get_subscription( $editing_id ) : null;
    if ( $editing_id && ! $editing_subscription ) {
        $editing_id = 0;
    }

    // Tous les champs restent modifiables côté admin, y compris la grille produits×dates et le
    // contrat/groupe, même une fois la souscription signée : le bureau garde la main pour
    // corriger une erreur de saisie ("admin est root"). La modification resynchronise entièrement
    // les subscription_items à partir de la grille soumise (amap_handle_update_subscription()) —
    // changer le contrat/groupe rebâtit donc la grille sur les produits/dates du nouveau contrat,
    // au prix de perdre les quantités déjà saisies pour l'ancien.
    $editing_subscription_items = $editing_subscription ? amap_get_subscription_items( $editing_subscription->id ) : array();

    $transient_key = 'amap_subscription_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_subscription ) {
        // Préremplit la grille depuis les subscription_items déjà enregistrés (même structure
        // que celle postée par le formulaire : date_id => product_id => quantity), pour que la
        // grille de modification parte des quantités actuelles plutôt que d'une grille vide.
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
            'quantities'     => $prefill_quantities,
        );
    } else {
        // Une nouvelle souscription est très généralement saisie le jour même de la signature
        // papier : préremplie à aujourd'hui, mais modifiable (saisie a posteriori).
        $form_data = array( 'signed_at' => current_time( 'Y-m-d' ) );
    }

    $members   = amap_get_member_users();
    $contracts = amap_get_contracts();

    // Seuls les contrats actifs ("ouverts à la souscription", voir la case à cocher de
    // amap_render_contracts_page()) sont proposés pour une nouvelle souscription. En édition, le
    // contrat déjà choisi reste proposé même s'il a été désactivé depuis, pour ne pas casser une
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

    // Données nécessaires au filtrage JS des champs "Groupe"/"Taille de panier" selon le contrat
    // choisi : groupes réellement rattachés au producteur du contrat (amap_get_producer_groups(),
    // déjà utilisée pour les dates de livraison), tailles de panier propres au contrat si
    // basket_recurring. Précalculé pour tous les contrats proposés plutôt qu'en Ajax, ces volumes
    // restant faibles.
    $contracts_js_data = array();
    foreach ( $selectable_contracts as $contract ) {
        $producer_groups = amap_get_producer_groups( $contract->producer_user_id );
        $basket_sizes    = 'basket_recurring' === $contract->contract_type ? amap_get_contract_basket_sizes( $contract->id ) : array();
        $products        = 'product_grid' === $contract->contract_type ? amap_get_contract_products( $contract->id ) : array();

        // Dates de livraison groupées par groupe (une même liste de produits, mais des dates
        // différentes selon le groupe de retrait choisi, voir amap_get_contract_delivery_dates()).
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

    $selected_contract_id   = isset( $form_data['contract_id'] ) ? (int) $form_data['contract_id'] : 0;
    $selected_contract_data = $contracts_js_data[ $selected_contract_id ] ?? null;

    $subscriptions = amap_get_subscriptions();
    ?>
    <style>
        .amap-items-grid-wrapper {
            overflow-x: auto;
            margin-bottom: 12px;
        }
        table.amap-items-grid {
            border-collapse: collapse;
            box-shadow: none;
            margin-top: 4px;
        }
        table.amap-items-grid th,
        table.amap-items-grid td {
            border: 1px solid #dcdcde;
            padding: 6px 10px;
            text-align: center;
        }
        table.amap-items-grid thead th {
            background: #f0f0f1;
            white-space: normal;
            min-width: 90px;
        }
        table.amap-items-grid tbody th {
            background: #f6f7f7;
            white-space: nowrap;
            text-align: left;
        }
        table.amap-items-grid tbody tr:nth-child(even) td {
            background: #fafafa;
        }
        table.amap-items-grid .amap-item-quantity {
            width: 4em;
            text-align: center;
        }
    </style>
    <div class="wrap">
        <h1><?php esc_html_e( 'Souscriptions', 'association-manager' ); ?></h1>

        <?php if ( 'invalid' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Champs obligatoires manquants ou invalides.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_date' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Date de signature invalide.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'duplicate' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Cet adhérent a déjà souscrit à ce contrat.', 'association-manager' ); ?></p></div>
        <?php endif; ?>

        <?php if ( empty( $members ) || empty( $selectable_contracts ) ) : ?>
            <p><?php esc_html_e( "Il faut au moins un compte adhérent et un contrat actif pour créer une souscription.", 'association-manager' ); ?></p>
        <?php else : ?>
            <?php if ( ! $editing_id ) : ?>
                <p>
                    <button type="button" class="button button-primary" id="amap-subscription-add-toggle"><?php esc_html_e( '+ Ajouter une souscription', 'association-manager' ); ?></button>
                </p>
            <?php endif; ?>
            <div id="amap-subscription-form-wrapper"<?php echo $editing_id ? '' : ' hidden'; ?>>
            <?php if ( $editing_id && $editing_subscription ) : ?>
                <?php
                $view_contract    = amap_get_contract( $editing_subscription->contract_id );
                $view_producer    = $view_contract ? get_user_by( 'id', $view_contract->producer_user_id ) : null;
                $view_member      = get_user_by( 'id', $editing_subscription->member_user_id );
                $view_group       = amap_get_group( $editing_subscription->group_id );
                $view_basket_size = $editing_subscription->basket_size_id ? amap_get_contract_basket_size( $editing_subscription->basket_size_id ) : null;
                ?>
                <div id="amap-subscription-view">
                    <table class="widefat">
                        <tbody>
                            <tr>
                                <th><?php esc_html_e( 'Contrat', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $view_contract ? $view_contract->label : '—' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Producteur', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $view_producer ? $view_producer->display_name : '—' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Adhérent', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $view_member ? $view_member->display_name : '—' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Groupe (point de retrait)', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $view_group ? $view_group->name : '—' ); ?></td>
                            </tr>
                            <?php if ( $view_basket_size ) : ?>
                                <tr>
                                    <th><?php esc_html_e( 'Taille de panier', 'association-manager' ); ?></th>
                                    <td><?php echo esc_html( $view_basket_size->label ); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <th><?php esc_html_e( 'Date de signature', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $editing_subscription->signed_at ); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if ( $view_contract && 'product_grid' === $view_contract->contract_type ) : ?>
                        <h3><?php esc_html_e( 'Produits commandés', 'association-manager' ); ?></h3>
                        <?php
                        // Même affichage que la grille de saisie (produits en colonne, une date
                        // de livraison par ligne) plutôt qu'une liste plate — plus simple à
                        // relire. Toutes les dates du groupe et tous les produits du contrat sont
                        // affichés, pas seulement ceux ayant une quantité enregistrée.
                        $recap_products = amap_get_contract_products( $view_contract->id );
                        $recap_dates    = array_values(
                            array_filter(
                                amap_get_contract_delivery_dates( $view_contract->id ),
                                static function ( $delivery_date ) use ( $editing_subscription ) {
                                    return (int) $delivery_date->group_id === (int) $editing_subscription->group_id;
                                }
                            )
                        );

                        $recap_quantities = array();
                        foreach ( $editing_subscription_items as $item ) {
                            $recap_quantities[ (int) $item->contract_delivery_date_id ][ (int) $item->contract_product_id ] = (int) $item->quantity;
                        }
                        ?>
                        <?php if ( empty( $recap_products ) || empty( $recap_dates ) ) : ?>
                            <p><?php esc_html_e( 'Aucun produit enregistré pour cette souscription.', 'association-manager' ); ?></p>
                        <?php else : ?>
                            <div class="amap-items-grid-wrapper">
                                <table class="widefat amap-items-grid">
                                    <thead>
                                        <tr>
                                            <th></th>
                                            <?php foreach ( $recap_products as $recap_product ) : ?>
                                                <th><?php echo esc_html( $recap_product->label ); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $recap_dates as $recap_date ) : ?>
                                            <tr>
                                                <th><?php echo esc_html( $recap_date->delivery_date ); ?></th>
                                                <?php foreach ( $recap_products as $recap_product ) : ?>
                                                    <?php $recap_qty = $recap_quantities[ (int) $recap_date->id ][ (int) $recap_product->id ] ?? 0; ?>
                                                    <td><?php echo $recap_qty ? esc_html( $recap_qty ) : '—'; ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <p>
                        <button type="button" class="button button-primary" id="amap-subscription-edit-toggle"><?php esc_html_e( 'Modifier', 'association-manager' ); ?></button>
                    </p>
                </div>
            <?php endif; ?>
            <div id="amap-subscription-edit-form"<?php echo $editing_id ? ' hidden' : ''; ?>>
            <h2>
                <?php echo $editing_id
                    ? esc_html__( 'Modifier une souscription', 'association-manager' )
                    : esc_html__( 'Ajouter une souscription', 'association-manager' ); ?>
            </h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php if ( $editing_id ) : ?>
                    <?php wp_nonce_field( 'amap_edit_subscription_' . $editing_id ); ?>
                    <input type="hidden" name="action" value="amap_update_subscription">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_subscription' ); ?>
                    <input type="hidden" name="action" value="amap_add_subscription">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="amap-subscription-contract"><?php esc_html_e( 'Contrat', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-subscription-contract" name="contract_id" required>
                                <option value=""></option>
                                <?php foreach ( $selectable_contracts as $contract ) : ?>
                                    <?php $contract_producer = get_user_by( 'id', $contract->producer_user_id ); ?>
                                    <option value="<?php echo esc_attr( $contract->id ); ?>" <?php selected( (string) $contract->id, $form_data['contract_id'] ?? '' ); ?>>
                                        <?php echo esc_html( $contract->label . ' — ' . ( $contract_producer ? $contract_producer->display_name : '' ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amap-subscription-member"><?php esc_html_e( 'Adhérent', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-subscription-member" name="member_user_id" required>
                                <option value=""></option>
                                <?php foreach ( $members as $member ) : ?>
                                    <option value="<?php echo esc_attr( $member->ID ); ?>" <?php selected( (string) $member->ID, $form_data['member_user_id'] ?? '' ); ?>>
                                        <?php echo esc_html( $member->display_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amap-subscription-group"><?php esc_html_e( 'Groupe (point de retrait)', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-subscription-group" name="group_id" required>
                                <?php if ( $selected_contract_data ) : ?>
                                    <?php foreach ( $selected_contract_data['groups'] as $group_option ) : ?>
                                        <option value="<?php echo esc_attr( $group_option['id'] ); ?>" <?php selected( (string) $group_option['id'], $form_data['group_id'] ?? '' ); ?>>
                                            <?php echo esc_html( $group_option['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value=""><?php esc_html_e( '— choisir un contrat —', 'association-manager' ); ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr id="amap-subscription-basket-size-row"<?php echo ( $selected_contract_data && 'basket_recurring' === $selected_contract_data['type'] ) ? '' : ' hidden'; ?>>
                        <th><label for="amap-subscription-basket-size"><?php esc_html_e( 'Taille de panier', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-subscription-basket-size" name="basket_size_id">
                                <?php if ( $selected_contract_data ) : ?>
                                    <?php foreach ( $selected_contract_data['basket_sizes'] as $size_option ) : ?>
                                        <option value="<?php echo esc_attr( $size_option['id'] ); ?>" <?php selected( (string) $size_option['id'], $form_data['basket_size_id'] ?? '' ); ?>>
                                            <?php echo esc_html( $size_option['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amap-subscription-signed-at"><?php esc_html_e( 'Date de signature', 'association-manager' ); ?></label></th>
                        <td><input type="date" id="amap-subscription-signed-at" name="signed_at" value="<?php echo esc_attr( $form_data['signed_at'] ?? '' ); ?>" required></td>
                    </tr>
                </table>

                <div id="amap-subscription-items-wrapper" hidden>
                    <h3><?php esc_html_e( 'Produits commandés', 'association-manager' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Une quantité par produit et par date de livraison.', 'association-manager' ); ?></p>
                    <div id="amap-subscription-items-grid" class="amap-items-grid-wrapper"></div>
                </div>

                <p>
                    <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                    <?php if ( $editing_id ) : ?>
                        <button type="button" class="button" id="amap-subscription-edit-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php else : ?>
                        <button type="button" class="button" id="amap-subscription-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php endif; ?>
                </p>
            </form>
            </div>
            </div>
            <script>
            ( function () {
                var contractsData   = <?php echo wp_json_encode( $contracts_js_data ); ?>;
                var contractField   = document.getElementById( 'amap-subscription-contract' );
                var groupField      = document.getElementById( 'amap-subscription-group' );
                var basketSizeRow   = document.getElementById( 'amap-subscription-basket-size-row' );
                var basketSizeField = document.getElementById( 'amap-subscription-basket-size' );
                var noContractLabel = <?php echo wp_json_encode( __( '— choisir un contrat —', 'association-manager' ) ); ?>;

                function populateSelect( select, options, placeholderText ) {
                    select.innerHTML = '';
                    if ( ! options.length ) {
                        if ( placeholderText ) {
                            var placeholder = document.createElement( 'option' );
                            placeholder.value       = '';
                            placeholder.textContent = placeholderText;
                            select.appendChild( placeholder );
                        }
                        return;
                    }
                    options.forEach( function ( option ) {
                        var optionElement = document.createElement( 'option' );
                        optionElement.value       = option.id;
                        optionElement.textContent = option.label;
                        select.appendChild( optionElement );
                    } );
                }

                var itemsWrapper = document.getElementById( 'amap-subscription-items-wrapper' );
                var itemsGrid    = document.getElementById( 'amap-subscription-items-grid' );
                var noProductsLabel  = <?php echo wp_json_encode( __( "Ce contrat n'a aucun produit dans son catalogue.", 'association-manager' ) ); ?>;
                var chooseGroupLabel = <?php echo wp_json_encode( __( 'Choisissez un groupe pour afficher les dates de livraison.', 'association-manager' ) ); ?>;
                var noDatesLabel     = <?php echo wp_json_encode( __( 'Aucune date de livraison enregistrée pour ce groupe.', 'association-manager' ) ); ?>;
                var duplicateLabel   = <?php echo wp_json_encode( __( 'Dupliquer la 1ère date sur toutes les autres', 'association-manager' ) ); ?>;

                function buildItemsGrid( contractId, groupId, prefill ) {
                    if ( ! itemsWrapper ) {
                        return;
                    }

                    var data = contractsData[ contractId ];
                    itemsGrid.innerHTML = '';

                    if ( ! data || 'product_grid' !== data.type ) {
                        itemsWrapper.hidden = true;
                        return;
                    }
                    itemsWrapper.hidden = false;

                    var products = data.products || [];
                    var dates    = ( data.delivery_dates_by_group && data.delivery_dates_by_group[ groupId ] ) || [];

                    if ( ! products.length || ! dates.length ) {
                        var message = document.createElement( 'p' );
                        if ( ! products.length ) {
                            message.textContent = noProductsLabel;
                        } else if ( ! groupId ) {
                            message.textContent = chooseGroupLabel;
                        } else {
                            message.textContent = noDatesLabel;
                        }
                        itemsGrid.appendChild( message );
                        return;
                    }

                    if ( dates.length > 1 ) {
                        var duplicateButton = document.createElement( 'button' );
                        duplicateButton.type      = 'button';
                        duplicateButton.className = 'button';
                        duplicateButton.textContent = duplicateLabel;
                        duplicateButton.addEventListener( 'click', function () {
                            var firstRowValues = {};
                            itemsGrid.querySelectorAll( '[data-date-index="0"]' ).forEach( function ( input ) {
                                firstRowValues[ input.dataset.productId ] = input.value;
                            } );
                            itemsGrid.querySelectorAll( '.amap-item-quantity' ).forEach( function ( input ) {
                                if ( '0' !== input.dataset.dateIndex ) {
                                    input.value = firstRowValues[ input.dataset.productId ] || '';
                                }
                            } );
                        } );
                        itemsGrid.appendChild( duplicateButton );
                    }

                    var table = document.createElement( 'table' );
                    table.className = 'widefat amap-items-grid';

                    var thead    = document.createElement( 'thead' );
                    var headRow  = document.createElement( 'tr' );
                    headRow.appendChild( document.createElement( 'th' ) );
                    products.forEach( function ( product ) {
                        var th = document.createElement( 'th' );
                        th.textContent = product.label;
                        headRow.appendChild( th );
                    } );
                    thead.appendChild( headRow );
                    table.appendChild( thead );

                    var tbody = document.createElement( 'tbody' );
                    dates.forEach( function ( dateOption, dateIndex ) {
                        var row = document.createElement( 'tr' );
                        var rowHeader = document.createElement( 'th' );
                        rowHeader.textContent = dateOption.label;
                        row.appendChild( rowHeader );

                        products.forEach( function ( product ) {
                            var cell  = document.createElement( 'td' );
                            var input = document.createElement( 'input' );
                            input.type                 = 'number';
                            input.min                  = '0';
                            input.step                 = '1';
                            input.className            = 'amap-item-quantity small-text';
                            input.name                 = 'quantity[' + dateOption.id + '][' + product.id + ']';
                            input.dataset.dateIndex     = dateIndex;
                            input.dataset.productId     = product.id;
                            var prefillValue = prefill && prefill[ dateOption.id ] ? prefill[ dateOption.id ][ product.id ] : '';
                            if ( prefillValue ) {
                                input.value = prefillValue;
                            }
                            cell.appendChild( input );
                            row.appendChild( cell );
                        } );

                        tbody.appendChild( row );
                    } );
                    table.appendChild( tbody );
                    itemsGrid.appendChild( table );
                }

                contractField.addEventListener( 'change', function () {
                    var data = contractsData[ contractField.value ];
                    populateSelect( groupField, data ? data.groups : [], noContractLabel );

                    var isBasketRecurring = !! data && 'basket_recurring' === data.type;
                    basketSizeRow.hidden = ! isBasketRecurring;
                    populateSelect( basketSizeField, isBasketRecurring ? data.basket_sizes : [], '' );

                    buildItemsGrid( contractField.value, groupField.value, {} );
                } );

                groupField.addEventListener( 'change', function () {
                    buildItemsGrid( contractField.value, groupField.value, {} );
                } );

                buildItemsGrid( contractField.value, groupField.value, <?php echo wp_json_encode( $form_data['quantities'] ?? array() ); ?> );
            } )();
            </script>
            <script>
            ( function () {
                var toggle  = document.getElementById( 'amap-subscription-add-toggle' );
                var wrapper = document.getElementById( 'amap-subscription-form-wrapper' );
                var cancel  = document.getElementById( 'amap-subscription-add-cancel' );
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
                var viewBlock  = document.getElementById( 'amap-subscription-view' );
                var editForm   = document.getElementById( 'amap-subscription-edit-form' );
                var editToggle = document.getElementById( 'amap-subscription-edit-toggle' );
                var editCancel = document.getElementById( 'amap-subscription-edit-cancel' );
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
        <?php endif; ?>

        <?php if ( empty( $subscriptions ) ) : ?>
            <p><?php esc_html_e( 'Aucune souscription enregistrée pour le moment.', 'association-manager' ); ?></p>
        <?php else : ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Contrat', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Producteur', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Adhérent', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Groupe', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Taille de panier', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Signée le', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $subscriptions as $subscription ) : ?>
                        <?php
                        $row_contract    = amap_get_contract( $subscription->contract_id );
                        $row_producer    = $row_contract ? get_user_by( 'id', $row_contract->producer_user_id ) : null;
                        $row_member      = get_user_by( 'id', $subscription->member_user_id );
                        $row_group       = amap_get_group( $subscription->group_id );
                        $row_basket_size = $subscription->basket_size_id ? amap_get_contract_basket_size( $subscription->basket_size_id ) : null;
                        ?>
                        <tr>
                            <td><?php echo esc_html( $row_contract ? $row_contract->label : '—' ); ?></td>
                            <td><?php echo esc_html( $row_producer ? $row_producer->display_name : '—' ); ?></td>
                            <td><?php echo esc_html( $row_member ? $row_member->display_name : '—' ); ?></td>
                            <td><?php echo esc_html( $row_group ? $row_group->name : '—' ); ?></td>
                            <td><?php echo esc_html( $row_basket_size ? $row_basket_size->label : '—' ); ?></td>
                            <td><?php echo esc_html( $subscription->signed_at ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-subscriptions&action=edit&id=' . $subscription->id ) ); ?>">
                                    <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                </a>
                                |
                                <?php
                                $delete_url       = wp_nonce_url(
                                    admin_url( 'admin-post.php?action=amap_delete_subscription&id=' . $subscription->id ),
                                    'amap_delete_subscription_' . $subscription->id
                                );
                                $confirm_message = __( 'Supprimer définitivement cette souscription ?', 'association-manager' );
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

/**
 * Insère les subscription_items depuis la grille soumise ($_POST['quantity'][date_id][product_id]),
 * en ne retenant que les couples produit/date réellement rattachés à ce contrat et ce groupe —
 * jamais les IDs postés en confiance — et les quantités strictement positives (grille creuse :
 * une case vide ou à 0 ne crée aucune ligne). Appelée à la création comme à la modification d'une
 * souscription product_grid (amap_handle_update_subscription() supprime d'abord les lignes
 * existantes pour resynchroniser entièrement la grille).
 */
function amap_insert_subscription_items( $subscription_id, $contract_id, $group_id ) {
    if ( ! isset( $_POST['quantity'] ) || ! is_array( $_POST['quantity'] ) ) {
        return;
    }

    $valid_product_ids = array_map( 'absint', wp_list_pluck( amap_get_contract_products( $contract_id ), 'id' ) );

    $valid_date_ids = array();
    foreach ( amap_get_contract_delivery_dates( $contract_id ) as $delivery_date ) {
        if ( (int) $delivery_date->group_id === $group_id ) {
            $valid_date_ids[] = (int) $delivery_date->id;
        }
    }

    global $wpdb;
    $quantities = wp_unslash( $_POST['quantity'] );

    foreach ( $quantities as $delivery_date_id => $products_quantities ) {
        $delivery_date_id = absint( $delivery_date_id );
        if ( ! is_array( $products_quantities ) || ! in_array( $delivery_date_id, $valid_date_ids, true ) ) {
            continue;
        }

        foreach ( $products_quantities as $product_id => $quantity ) {
            $product_id = absint( $product_id );
            $quantity   = absint( $quantity );
            if ( ! $quantity || ! in_array( $product_id, $valid_product_ids, true ) ) {
                continue;
            }

            $wpdb->insert(
                $wpdb->prefix . 'amap_subscription_items',
                array(
                    'subscription_id'           => $subscription_id,
                    'contract_product_id'       => $product_id,
                    'contract_delivery_date_id' => $delivery_date_id,
                    'quantity'                  => $quantity,
                )
            );
        }
    }
}

add_action( 'admin_post_amap_add_subscription', 'amap_handle_add_subscription' );

function amap_handle_add_subscription() {
    if ( ! current_user_can( 'amap_manage_subscriptions' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_subscription' );

    $contract_id    = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $member_user_id = isset( $_POST['member_user_id'] ) ? absint( $_POST['member_user_id'] ) : 0;
    $group_id       = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $basket_size_id = isset( $_POST['basket_size_id'] ) ? absint( $_POST['basket_size_id'] ) : 0;
    $signed_at      = isset( $_POST['signed_at'] ) ? sanitize_text_field( wp_unslash( $_POST['signed_at'] ) ) : '';
    // quantities : grille produits×dates saisie pour un contrat product_grid, revalidée et
    // insérée après la création de la souscription (amap_insert_subscription_items()) ; stockée
    // dans $submitted comme les autres champs pour être restituée si le formulaire est invalide.
    $quantities     = isset( $_POST['quantity'] ) && is_array( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : array();
    $submitted      = compact( 'contract_id', 'member_user_id', 'group_id', 'basket_size_id', 'signed_at', 'quantities' );

    $contract           = $contract_id ? amap_get_contract( $contract_id ) : null;
    $valid_member_ids   = wp_list_pluck( amap_get_member_users(), 'ID' );
    $producer_group_ids = $contract ? array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) ) : array();

    if ( ! $contract || ! in_array( $member_user_id, $valid_member_ids, true )
        || ! $group_id || ! in_array( $group_id, $producer_group_ids, true ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions&amap_notice=invalid' ) );
        exit;
    }

    if ( ! amap_is_valid_date( $signed_at ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions&amap_notice=invalid_date' ) );
        exit;
    }

    // basket_size_id n'a de sens que pour un panier récurrent : obligatoire et revérifié comme
    // appartenant à ce contrat dans ce cas (même si le formulaire masque le champ en JS, on
    // revalide côté serveur), forcé à NULL sinon — même principe que
    // wp_amap_contracts.frequency_weeks.
    if ( 'basket_recurring' === $contract->contract_type ) {
        $contract_basket_size = $basket_size_id ? amap_get_contract_basket_size( $basket_size_id ) : null;
        if ( ! $contract_basket_size || (int) $contract_basket_size->contract_id !== $contract_id ) {
            amap_store_subscription_form_data( $submitted );
            wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions&amap_notice=invalid' ) );
            exit;
        }
    } else {
        $basket_size_id = null;
    }

    if ( amap_member_has_subscription( $contract_id, $member_user_id ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions&amap_notice=duplicate' ) );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_subscriptions',
        array(
            'contract_id'    => $contract_id,
            'member_user_id' => $member_user_id,
            'group_id'       => $group_id,
            'basket_size_id' => $basket_size_id,
            'signed_at'      => $signed_at,
        )
    );

    if ( 'product_grid' === $contract->contract_type ) {
        amap_insert_subscription_items( $wpdb->insert_id, $contract_id, $group_id );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions' ) );
    exit;
}

add_action( 'admin_post_amap_update_subscription', 'amap_handle_update_subscription' );

function amap_handle_update_subscription() {
    if ( ! current_user_can( 'amap_manage_subscriptions' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id           = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $subscription = $id ? amap_get_subscription( $id ) : null;
    if ( ! $subscription ) {
        wp_die( esc_html__( 'Souscription introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_edit_subscription_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-subscriptions&action=edit&id=' . $id );

    $contract_id    = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $member_user_id = isset( $_POST['member_user_id'] ) ? absint( $_POST['member_user_id'] ) : 0;
    $group_id       = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $basket_size_id = isset( $_POST['basket_size_id'] ) ? absint( $_POST['basket_size_id'] ) : 0;
    $signed_at      = isset( $_POST['signed_at'] ) ? sanitize_text_field( wp_unslash( $_POST['signed_at'] ) ) : '';
    // quantities : voir amap_handle_add_subscription(), même principe de restitution en cas
    // d'erreur de validation plus bas dans le formulaire.
    $quantities     = isset( $_POST['quantity'] ) && is_array( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : array();
    $submitted      = compact( 'contract_id', 'member_user_id', 'group_id', 'basket_size_id', 'signed_at', 'quantities' );

    $contract           = $contract_id ? amap_get_contract( $contract_id ) : null;
    $valid_member_ids   = wp_list_pluck( amap_get_member_users(), 'ID' );
    $producer_group_ids = $contract ? array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) ) : array();

    if ( ! $contract || ! in_array( $member_user_id, $valid_member_ids, true )
        || ! $group_id || ! in_array( $group_id, $producer_group_ids, true ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid' );
        exit;
    }

    if ( ! amap_is_valid_date( $signed_at ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid_date' );
        exit;
    }

    if ( 'basket_recurring' === $contract->contract_type ) {
        $contract_basket_size = $basket_size_id ? amap_get_contract_basket_size( $basket_size_id ) : null;
        if ( ! $contract_basket_size || (int) $contract_basket_size->contract_id !== $contract_id ) {
            amap_store_subscription_form_data( $submitted );
            wp_safe_redirect( $edit_url . '&amap_notice=invalid' );
            exit;
        }
    } else {
        $basket_size_id = null;
    }

    if ( amap_member_has_subscription( $contract_id, $member_user_id, $id ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=duplicate' );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_subscriptions',
        array(
            'contract_id'    => $contract_id,
            'member_user_id' => $member_user_id,
            'group_id'       => $group_id,
            'basket_size_id' => $basket_size_id,
            'signed_at'      => $signed_at,
        ),
        array( 'id' => $id )
    );

    // Resynchronise entièrement la grille depuis la soumission (delete + réinsertion des cases
    // > 0, même logique qu'à la création) plutôt qu'un update ligne à ligne — plus simple pour un
    // formulaire qui soumet la grille en bloc. Si le contrat n'est plus product_grid (changé en
    // basket_recurring) ou si la grille n'a pas été soumise (JS non chargé, ancien lien sans
    // champ quantity), les subscription_items existants ne sont supprimés que dans le premier cas
    // : on évite de les effacer par erreur simplement parce que la grille n'a pas pu être rebâtie.
    if ( 'product_grid' === $contract->contract_type ) {
        if ( isset( $_POST['quantity'] ) && is_array( $_POST['quantity'] ) ) {
            $wpdb->delete( $wpdb->prefix . 'amap_subscription_items', array( 'subscription_id' => $id ) );
            amap_insert_subscription_items( $id, $contract_id, $group_id );
        }
    } else {
        $wpdb->delete( $wpdb->prefix . 'amap_subscription_items', array( 'subscription_id' => $id ) );
    }

    wp_safe_redirect( $edit_url );
    exit;
}

add_action( 'admin_post_amap_delete_subscription', 'amap_handle_delete_subscription' );

function amap_handle_delete_subscription() {
    if ( ! current_user_can( 'amap_manage_subscriptions' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    if ( ! $id || ! amap_get_subscription( $id ) ) {
        wp_die( esc_html__( 'Souscription introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_subscription_' . $id );

    global $wpdb;
    // Pas de contrainte FOREIGN KEY SQL sur subscription_id (cohérent avec le reste du plugin) :
    // nettoyage explicite des subscription_items orphelins, comme les tables filles à la
    // suppression d'un contrat (wp_amap_leaves sera ajoutée à l'étape 8).
    $wpdb->delete( $wpdb->prefix . 'amap_subscription_items', array( 'subscription_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_subscriptions', array( 'id' => $id ) );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions' ) );
    exit;
}
