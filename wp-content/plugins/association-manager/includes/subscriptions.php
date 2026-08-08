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

    $transient_key = 'amap_subscription_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_subscription ) {
        $form_data = array(
            'contract_id'    => (string) $editing_subscription->contract_id,
            'member_user_id' => (string) $editing_subscription->member_user_id,
            'group_id'       => (string) $editing_subscription->group_id,
            'basket_size_id' => null !== $editing_subscription->basket_size_id ? (string) $editing_subscription->basket_size_id : '',
            'signed_at'      => $editing_subscription->signed_at,
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

        $contracts_js_data[ (int) $contract->id ] = array(
            'type'         => $contract->contract_type,
            'groups'       => array_map(
                static function ( $group ) {
                    return array(
                        'id'    => (int) $group->id,
                        'label' => $group->name,
                    );
                },
                $producer_groups
            ),
            'basket_sizes' => array_map(
                static function ( $size ) {
                    return array(
                        'id'    => (int) $size->id,
                        'label' => $size->label . ' (' . number_format_i18n( (float) $size->price, 2 ) . ' €)',
                    );
                },
                $basket_sizes
            ),
        );
    }

    $selected_contract_id   = isset( $form_data['contract_id'] ) ? (int) $form_data['contract_id'] : 0;
    $selected_contract_data = $contracts_js_data[ $selected_contract_id ] ?? null;

    $subscriptions = amap_get_subscriptions();
    ?>
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
                <p>
                    <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                    <?php if ( $editing_id ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-subscriptions' ) ); ?>" class="button">
                            <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                        </a>
                    <?php else : ?>
                        <button type="button" class="button" id="amap-subscription-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php endif; ?>
                </p>
            </form>
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

                contractField.addEventListener( 'change', function () {
                    var data = contractsData[ contractField.value ];
                    populateSelect( groupField, data ? data.groups : [], noContractLabel );

                    var isBasketRecurring = !! data && 'basket_recurring' === data.type;
                    basketSizeRow.hidden = ! isBasketRecurring;
                    populateSelect( basketSizeField, isBasketRecurring ? data.basket_sizes : [], '' );
                } );
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
    $submitted      = compact( 'contract_id', 'member_user_id', 'group_id', 'basket_size_id', 'signed_at' );

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
    $submitted      = compact( 'contract_id', 'member_user_id', 'group_id', 'basket_size_id', 'signed_at' );

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
    // Pas de table fille dépendant de wp_amap_subscriptions pour l'instant
    // (wp_amap_subscription_items et wp_amap_leaves seront ajoutées aux étapes 6/7) : suppression
    // simple, sans nettoyage de rattachements orphelins.
    $wpdb->delete( $wpdb->prefix . 'amap_subscriptions', array( 'id' => $id ) );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions' ) );
    exit;
}
