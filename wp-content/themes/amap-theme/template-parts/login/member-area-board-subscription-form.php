<?php
/**
 * Formulaire "Ajouter"/"Modifier une souscription", section "Souscriptions" de l'espace bureau —
 * page dédiée, reprise de amap_render_subscriptions_page() côté wp-admin. La soumission est
 * traitée par les mêmes handlers que wp-admin (amap_handle_add_subscription()/
 * amap_handle_update_subscription()/amap_handle_add_leave()/amap_handle_delete_leave()), le champ
 * caché "redirect_to" leur indiquant de revenir ici plutôt que sur la page wp-admin. $args : voir
 * amap_get_board_subscription_form_data() (plugin, member-area.php).
 *
 * Sous-page en dehors de la coquille à onglets (atteinte directement par
 * amap_maybe_render_member_area(), pas via member-area.php) : elle inclut donc elle-même les
 * symboles SVG (#amap-icon-*), comme member-profile-edit.php.
 */
$editing_id        = $args['editing_id'];
$form_data         = $args['form_data'];
$contracts_js_data = $args['contracts_js_data'];

$selected_contract_id   = isset( $form_data['contract_id'] ) ? (int) $form_data['contract_id'] : 0;
$selected_contract_data = $contracts_js_data[ $selected_contract_id ] ?? null;

$selected_member_id = isset( $form_data['member_user_id'] ) ? (int) $form_data['member_user_id'] : 0;
$selected_member    = $selected_member_id ? get_user_by( 'id', $selected_member_id ) : null;
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( amap_get_board_subscriptions_url() ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php esc_html_e( 'Retour à la liste', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title">
        <?php echo $editing_id
            ? esc_html__( 'Modifier une souscription', 'association-manager' )
            : esc_html__( 'Ajouter une souscription', 'association-manager' ); ?>
    </h1>
</div>

<?php if ( 'updated' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Souscription mise à jour.', 'association-manager' ); ?></div>
<?php elseif ( 'invalid' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Champs obligatoires manquants ou invalides.', 'association-manager' ); ?></div>
<?php elseif ( 'invalid_date' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Date de signature invalide.', 'association-manager' ); ?></div>
<?php elseif ( 'leave_saved' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Congé ajouté.', 'association-manager' ); ?></div>
<?php elseif ( 'leave_deleted' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Congé supprimé.', 'association-manager' ); ?></div>
<?php elseif ( 'leave_invalid' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Date de congé invalide.', 'association-manager' ); ?></div>
<?php elseif ( 'leave_not_a_distribution_date' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( "Cette date ne correspond pas à une distribution réelle de ce contrat (jour de la semaine, période ou fréquence incorrecte).", 'association-manager' ); ?></div>
<?php elseif ( 'leave_duplicate' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Ce congé est déjà déclaré.', 'association-manager' ); ?></div>
<?php elseif ( 'leave_max_reached' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Le maximum de congés autorisés pour cette souscription est déjà atteint.', 'association-manager' ); ?></div>
<?php endif; ?>

<?php if ( empty( $args['members'] ) || empty( $args['selectable_contracts'] ) ) : ?>
    <p><?php esc_html_e( 'Il faut au moins un compte adhérent et un contrat actif pour créer une souscription.', 'association-manager' ); ?></p>
<?php else : ?>

    <form class="amap-profile-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-board-subscription-form" novalidate>
        <?php if ( $editing_id ) : ?>
            <?php wp_nonce_field( 'amap_edit_subscription_' . $editing_id ); ?>
            <input type="hidden" name="action" value="amap_update_subscription">
            <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
        <?php else : ?>
            <?php wp_nonce_field( 'amap_add_subscription' ); ?>
            <input type="hidden" name="action" value="amap_add_subscription">
        <?php endif; ?>
        <input type="hidden" name="redirect_to" value="<?php echo esc_url( amap_get_board_subscriptions_url() ); ?>">

        <div class="amap-field-row">
            <div class="amap-field">
                <label for="amap-board-subscription-contract"><?php esc_html_e( 'Contrat', 'association-manager' ); ?></label>
                <select id="amap-board-subscription-contract" name="contract_id" required>
                    <option value=""></option>
                    <?php foreach ( $args['selectable_contracts'] as $contract ) : ?>
                        <?php $contract_producer = get_user_by( 'id', $contract->producer_user_id ); ?>
                        <option value="<?php echo esc_attr( $contract->id ); ?>" <?php selected( (string) $contract->id, $form_data['contract_id'] ?? '' ); ?>>
                            <?php echo esc_html( $contract->label . ' — ' . ( $contract_producer ? $contract_producer->display_name : '' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="amap-field">
                <label for="amap-board-subscription-member"><?php esc_html_e( 'Adhérent', 'association-manager' ); ?></label>
                <?php if ( $editing_id ) : ?>
                    <?php /* L'adhérent d'une souscription existante ne se change pas ici : réassigner une souscription à quelqu'un d'autre n'est pas une opération normale (contrairement au contrat/groupe/dates, corrigibles à tout moment). Champ figé, valeur postée via le hidden ci-dessous. */ ?>
                    <input type="text" id="amap-board-subscription-member" value="<?php echo esc_attr( $selected_member ? $selected_member->display_name : '' ); ?>" disabled>
                    <input type="hidden" name="member_user_id" value="<?php echo esc_attr( $selected_member_id ?: '' ); ?>">
                <?php else : ?>
                    <div class="amap-combo">
                        <input
                            type="text"
                            id="amap-board-subscription-member"
                            placeholder="<?php esc_attr_e( 'Rechercher un nom…', 'association-manager' ); ?>"
                            value="<?php echo esc_attr( $selected_member ? $selected_member->display_name : '' ); ?>"
                            autocomplete="off"
                            role="combobox"
                            aria-expanded="false"
                            aria-autocomplete="list"
                            aria-controls="amap-board-subscription-member-list"
                            aria-describedby="amap-board-subscription-member-error"
                            required
                        >
                        <input type="hidden" name="member_user_id" id="amap-board-subscription-member-id" value="<?php echo esc_attr( $selected_member_id ?: '' ); ?>">
                        <ul class="amap-combo__list" id="amap-board-subscription-member-list" role="listbox" hidden></ul>
                    </div>
                    <span id="amap-board-subscription-member-error" class="amap-field__error" hidden><?php esc_html_e( 'Sélectionnez un adhérent dans la liste proposée.', 'association-manager' ); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="amap-field">
            <label for="amap-board-subscription-group"><?php esc_html_e( 'Groupe (point de retrait)', 'association-manager' ); ?></label>
            <select id="amap-board-subscription-group" name="group_id" required>
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
            <p class="amap-field__hint"><?php esc_html_e( 'Limité aux groupes réellement livrés par le producteur de ce contrat.', 'association-manager' ); ?></p>
        </div>

        <div class="amap-field" id="amap-board-subscription-basket-size-row"<?php echo ( $selected_contract_data && 'basket_recurring' === $selected_contract_data['type'] ) ? '' : ' hidden'; ?>>
            <label for="amap-board-subscription-basket-size"><?php esc_html_e( 'Taille de panier', 'association-manager' ); ?></label>
            <select id="amap-board-subscription-basket-size" name="basket_size_id">
                <?php if ( $selected_contract_data ) : ?>
                    <?php foreach ( $selected_contract_data['basket_sizes'] as $size_option ) : ?>
                        <option value="<?php echo esc_attr( $size_option['id'] ); ?>" <?php selected( (string) $size_option['id'], $form_data['basket_size_id'] ?? '' ); ?>>
                            <?php echo esc_html( $size_option['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>

        <div class="amap-field-row">
            <div class="amap-field">
                <label for="amap-board-subscription-signed-at"><?php esc_html_e( 'Date de signature', 'association-manager' ); ?></label>
                <input type="date" id="amap-board-subscription-signed-at" name="signed_at" value="<?php echo esc_attr( $form_data['signed_at'] ?? '' ); ?>" required>
            </div>
            <div class="amap-field amap-field--inline">
                <label class="amap-checkbox-field">
                    <input type="checkbox" id="amap-board-subscription-is-paid" name="is_paid" value="1" <?php checked( ! empty( $form_data['is_paid'] ) ); ?>>
                    <?php esc_html_e( 'Souscription payée', 'association-manager' ); ?>
                </label>
            </div>
        </div>

        <div class="amap-field">
            <label for="amap-board-subscription-paid-at"><?php esc_html_e( 'Payé le', 'association-manager' ); ?> <span class="amap-field__optional">(<?php esc_html_e( 'facultatif', 'association-manager' ); ?>)</span></label>
            <input type="date" id="amap-board-subscription-paid-at" name="paid_at" value="<?php echo esc_attr( $form_data['paid_at'] ?? '' ); ?>">
        </div>

        <div class="amap-stack" id="amap-board-subscription-items-wrapper" hidden>
            <h3><?php esc_html_e( 'Produits commandés', 'association-manager' ); ?></h3>
            <p class="amap-field__hint"><?php esc_html_e( 'Une quantité par produit et par date de livraison.', 'association-manager' ); ?></p>
            <div id="amap-board-subscription-items-grid"></div>
        </div>

        <div class="amap-form-actions">
            <button type="submit" class="button-primary">
                <?php echo $editing_id ? esc_html__( 'Enregistrer', 'association-manager' ) : esc_html__( 'Ajouter', 'association-manager' ); ?>
            </button>
            <a class="button-secondary" href="<?php echo esc_url( amap_get_board_subscriptions_url() ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
        </div>
    </form>

    <?php if ( $args['price_summary'] && ! empty( $args['price_summary']['lines'] ) ) : ?>
        <div class="amap-summary-card">
            <h3><?php esc_html_e( 'Montant dû', 'association-manager' ); ?></h3>
            <table class="amap-detail-table">
                <tbody>
                    <?php foreach ( $args['price_summary']['lines'] as $line ) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html( $line['label'] ); ?><br>
                                <small>
                                    <?php if ( $line['bought_quantity'] !== $line['billed_quantity'] ) : ?>
                                        <?php
                                        printf(
                                            /* translators: 1: quantité achetée. 2: quantité facturée. */
                                            esc_html__( '%1$d achetés → %2$d facturés', 'association-manager' ),
                                            $line['bought_quantity'],
                                            $line['billed_quantity']
                                        );
                                        ?>
                                    <?php else : ?>
                                        <?php
                                        printf(
                                            /* translators: 1: quantité commandée. 2: prix unitaire. */
                                            esc_html__( '%1$d × %2$s €', 'association-manager' ),
                                            $line['bought_quantity'],
                                            esc_html( number_format_i18n( $line['unit_price'], 2 ) )
                                        );
                                        ?>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td class="amap-detail-table__num"><?php echo esc_html( number_format_i18n( $line['amount'], 2 ) ); ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="amap-detail-table__total">
                        <td><?php esc_html_e( 'Total', 'association-manager' ); ?></td>
                        <td class="amap-detail-table__num"><?php echo esc_html( number_format_i18n( $args['price_summary']['total'], 2 ) ); ?> €</td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ( $args['leaves_data'] ) : ?>
        <?php $leaves_data = $args['leaves_data']; ?>
        <div class="amap-leave-section">
            <h3><?php esc_html_e( 'Congés', 'association-manager' ); ?></h3>

            <?php if ( empty( $leaves_data['leaves'] ) ) : ?>
                <p><?php esc_html_e( 'Aucun congé déclaré pour le moment.', 'association-manager' ); ?></p>
            <?php else : ?>
                <ul class="amap-leave-list">
                    <?php foreach ( $leaves_data['leaves'] as $leave ) : ?>
                        <?php
                        $leave_delete_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'action'      => 'amap_delete_leave',
                                    'id'          => $leave->id,
                                    'redirect_to' => rawurlencode( amap_get_board_subscription_edit_url( $editing_id ) ),
                                ),
                                admin_url( 'admin-post.php' )
                            ),
                            'amap_delete_leave_' . $leave->id
                        );
                        ?>
                        <li>
                            <span><?php echo esc_html( date_i18n( 'l j F Y', strtotime( $leave->leave_date ) ) ); ?></span>
                            <a href="<?php echo esc_url( $leave_delete_url ); ?>" onclick="return confirm( '<?php echo esc_js( __( 'Supprimer ce congé ?', 'association-manager' ) ); ?>' );">
                                <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="amap-leave-count">
                <?php
                printf(
                    /* translators: 1: nombre de congés déjà déclarés. 2: nombre de congés autorisés pour ce contrat. */
                    esc_html__( '%1$d congé(s) déclaré(s) sur %2$d autorisés.', 'association-manager' ),
                    count( $leaves_data['leaves'] ),
                    $leaves_data['max_leaves']
                );
                ?>
            </p>

            <?php if ( $leaves_data['leaves_full'] ) : ?>
                <p><?php esc_html_e( 'Le maximum de congés a été atteint pour cette souscription.', 'association-manager' ); ?></p>
            <?php elseif ( empty( $leaves_data['available_dates'] ) ) : ?>
                <p><?php esc_html_e( 'Aucune date de distribution disponible pour ce contrat et ce groupe.', 'association-manager' ); ?></p>
            <?php else : ?>
                <?php
                // Même composant que "Déclarer un congé" côté adhérent (member-area-leave.php) :
                // cases jour/date plutôt qu'un <select>, jour identique pour toutes les dates
                // proposées (jour fixe du groupe).
                $leaves_group        = amap_get_group( $args['editing_subscription']->group_id );
                $weekday_labels      = amap_get_weekday_labels();
                $leaves_weekday_short = $leaves_group ? mb_substr( $weekday_labels[ (int) $leaves_group->weekday ] ?? '', 0, 3 ) : '';
                ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-leave-add-form">
                    <?php wp_nonce_field( 'amap_add_leave_' . $editing_id ); ?>
                    <input type="hidden" name="action" value="amap_add_leave">
                    <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $editing_id ); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url( amap_get_board_subscription_edit_url( $editing_id ) ); ?>">
                    <div class="amap-field">
                        <span class="amap-field__hint" id="amap-board-leave-date-label"><?php esc_html_e( 'Date de congé', 'association-manager' ); ?></span>
                        <div class="amap-date-options" role="radiogroup" aria-labelledby="amap-board-leave-date-label">
                            <?php foreach ( $leaves_data['available_dates'] as $candidate_date ) : ?>
                                <label class="amap-date-option">
                                    <input class="sr-only" type="radio" name="leave_date" value="<?php echo esc_attr( $candidate_date ); ?>" required>
                                    <span class="amap-date-option__box">
                                        <span class="amap-date-option__day"><?php echo esc_html( $leaves_weekday_short ); ?></span>
                                        <span class="amap-date-option__date"><?php echo esc_html( amap_get_short_date_label( $candidate_date ) ); ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" class="button-secondary"><?php esc_html_e( 'Ajouter le congé', 'association-manager' ); ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <script>
    ( function () {
        "use strict";

        var contractsData = <?php echo wp_json_encode( $contracts_js_data ); ?>;
        var members        = <?php echo wp_json_encode( array_map( static function ( $member ) { return array( 'id' => $member->ID, 'label' => $member->display_name ); }, $args['members'] ) ); ?>;

        var contractField  = document.getElementById( 'amap-board-subscription-contract' );
        var groupField      = document.getElementById( 'amap-board-subscription-group' );
        var basketSizeRow   = document.getElementById( 'amap-board-subscription-basket-size-row' );
        var basketSizeField = document.getElementById( 'amap-board-subscription-basket-size' );
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

        var itemsWrapper = document.getElementById( 'amap-board-subscription-items-wrapper' );
        var itemsGrid    = document.getElementById( 'amap-board-subscription-items-grid' );
        var noProductsLabel  = <?php echo wp_json_encode( __( "Ce contrat n'a aucun produit dans son catalogue.", 'association-manager' ) ); ?>;
        var chooseGroupLabel = <?php echo wp_json_encode( __( 'Choisissez un groupe pour afficher les dates de livraison.', 'association-manager' ) ); ?>;
        var noDatesLabel     = <?php echo wp_json_encode( __( 'Aucune date de livraison enregistrée pour ce groupe.', 'association-manager' ) ); ?>;
        var duplicateLabel   = <?php echo wp_json_encode( __( 'Dupliquer la 1ère date sur toutes les autres', 'association-manager' ) ); ?>;

        function buildItemsGrid( contractId, groupId, prefill ) {
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
                duplicateButton.type        = 'button';
                duplicateButton.className   = 'button-secondary';
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

            var gridWrap = document.createElement( 'div' );
            gridWrap.className = 'amap-items-grid-wrapper';

            var table = document.createElement( 'table' );
            table.className = 'amap-items-grid';

            var thead   = document.createElement( 'thead' );
            var headRow = document.createElement( 'tr' );
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
                var row       = document.createElement( 'tr' );
                var rowHeader = document.createElement( 'th' );
                rowHeader.textContent = dateOption.label;
                row.appendChild( rowHeader );

                products.forEach( function ( product ) {
                    var cell  = document.createElement( 'td' );
                    var input = document.createElement( 'input' );
                    input.type              = 'number';
                    input.min               = '0';
                    input.step              = '1';
                    input.className         = 'amap-item-quantity';
                    input.name              = 'quantity[' + dateOption.id + '][' + product.id + ']';
                    input.dataset.dateIndex = dateIndex;
                    input.dataset.productId = product.id;
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
            gridWrap.appendChild( table );
            itemsGrid.appendChild( gridWrap );
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

        // Champ "Adhérent" cherchable : filtre members[] en direct plutôt qu'un <select> d'environ
        // 200 noms. Navigation clavier (flèches/Entrée/Échap) en plus du clic, comme un <select>
        // natif. La sélection doit venir de la liste (member_user_id caché) : la validation au
        // submit refuse un texte tapé sans correspondance choisie. N'existe qu'en "Ajouter" : en
        // "Modifier", l'adhérent est un champ figé (disabled) sans liste ni JS associé.
        var form          = document.getElementById( 'amap-board-subscription-form' );
        var memberList    = document.getElementById( 'amap-board-subscription-member-list' );
        var memberInput   = memberList ? document.getElementById( 'amap-board-subscription-member' ) : null;
        if ( ! memberInput ) {
            return;
        }
        var memberIdField  = document.getElementById( 'amap-board-subscription-member-id' );
        var memberFieldWrap = memberInput.closest( '.amap-field' );
        var memberError     = document.getElementById( 'amap-board-subscription-member-error' );
        var activeIndex     = -1;
        var matches         = [];

        function setMemberError( hasError ) {
            memberFieldWrap.classList.toggle( 'has-error', hasError );
            memberError.hidden = ! hasError;
        }

        function closeList() {
            memberList.hidden = true;
            memberList.innerHTML = '';
            memberInput.setAttribute( 'aria-expanded', 'false' );
            activeIndex = -1;
            matches = [];
        }

        function selectMember( member ) {
            memberInput.value   = member.label;
            memberIdField.value = member.id;
            setMemberError( false );
            closeList();
        }

        function highlight( label, query ) {
            var index = label.toLowerCase().indexOf( query.toLowerCase() );
            if ( -1 === index ) {
                return document.createTextNode( label );
            }
            var fragment = document.createDocumentFragment();
            fragment.appendChild( document.createTextNode( label.slice( 0, index ) ) );
            var mark = document.createElement( 'mark' );
            mark.textContent = label.slice( index, index + query.length );
            fragment.appendChild( mark );
            fragment.appendChild( document.createTextNode( label.slice( index + query.length ) ) );
            return fragment;
        }

        function renderMatches( query ) {
            memberList.innerHTML = '';
            matches.forEach( function ( member, index ) {
                var li = document.createElement( 'li' );
                li.setAttribute( 'role', 'option' );
                li.appendChild( highlight( member.label, query ) );
                li.className = ( index === activeIndex ) ? 'is-active' : '';
                li.addEventListener( 'mousedown', function ( event ) {
                    event.preventDefault();
                    selectMember( member );
                } );
                memberList.appendChild( li );
            } );
            memberList.hidden = ! matches.length;
            memberInput.setAttribute( 'aria-expanded', matches.length ? 'true' : 'false' );
        }

        memberInput.addEventListener( 'input', function () {
            memberIdField.value = '';
            setMemberError( false );
            var query = memberInput.value.trim();
            if ( ! query ) {
                closeList();
                return;
            }
            matches = members.filter( function ( member ) {
                return -1 !== member.label.toLowerCase().indexOf( query.toLowerCase() );
            } ).slice( 0, 20 );
            activeIndex = -1;
            renderMatches( query );
        } );

        memberInput.addEventListener( 'keydown', function ( event ) {
            if ( memberList.hidden || ! matches.length ) {
                return;
            }
            if ( 'ArrowDown' === event.key ) {
                event.preventDefault();
                activeIndex = ( activeIndex + 1 ) % matches.length;
                renderMatches( memberInput.value.trim() );
            } else if ( 'ArrowUp' === event.key ) {
                event.preventDefault();
                activeIndex = ( activeIndex - 1 + matches.length ) % matches.length;
                renderMatches( memberInput.value.trim() );
            } else if ( 'Enter' === event.key ) {
                if ( activeIndex >= 0 ) {
                    event.preventDefault();
                    selectMember( matches[ activeIndex ] );
                }
            } else if ( 'Escape' === event.key ) {
                closeList();
            }
        } );

        memberInput.addEventListener( 'blur', function () {
            window.setTimeout( closeList, 150 );
        } );

        form.addEventListener( 'submit', function ( event ) {
            if ( ! memberIdField.value ) {
                event.preventDefault();
                setMemberError( true );
                memberInput.focus();
            }
        } );
    } )();
    </script>

<?php endif; ?>
