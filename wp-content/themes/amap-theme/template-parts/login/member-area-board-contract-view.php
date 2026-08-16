<?php
/**
 * Fiche d'un contrat (infos + les sections propres à son type), section "Contrats" de l'espace
 * bureau — page dédiée, reprise de la branche "édition" de amap_render_contracts_page() côté
 * wp-admin. Les sections (Tailles de panier / Familles de remise / Catalogue produits / Dates de
 * livraison) restent des accordéons <details> repliés par défaut, comme la fiche groupe — sauf si
 * un message ou une édition en cours les concerne (jamais masquer un message pertinent derrière une
 * section repliée). $args : voir amap_get_board_contract_view_data() (plugin, member-area.php).
 *
 * Sous-page en dehors de la coquille à onglets (atteinte directement par
 * amap_maybe_render_member_area(), pas via member-area.php) : elle inclut donc elle-même les
 * symboles SVG (#amap-icon-*), comme member-profile-edit.php.
 */
$contract              = $args['contract'];
$producer              = $args['producer'];
$notice                = $args['notice'];
$contract_type_labels  = amap_get_contract_types();
$weekday_labels        = amap_get_weekday_labels();
$view_url              = amap_get_board_contract_view_url( $contract->id );
$is_basket_recurring   = ( 'basket_recurring' === $contract->contract_type );
$is_product_grid       = ( 'product_grid' === $contract->contract_type );

$sizes_open = ( 'created' === $notice && $is_basket_recurring )
    || $args['size_editing_id']
    || ( 0 === strpos( (string) $notice, 'basket_size_' ) );

$discount_groups_open = ( 'created' === $notice && $is_product_grid )
    || $args['discount_group_editing_id']
    || ( 0 === strpos( (string) $notice, 'contract_discount_group_' ) );

$catalog_open = ( 'created' === $notice && $is_product_grid )
    || $args['product_editing_id']
    || ( 0 === strpos( (string) $notice, 'contract_product_' ) );

$dates_open = $args['delivery_date_editing_id']
    || $args['generate_group_id']
    || ( 0 === strpos( (string) $notice, 'contract_delivery_date' ) );
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( amap_get_board_contracts_url() ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php esc_html_e( 'Retour à la liste', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title"><?php echo esc_html( $contract->label ); ?></h1>
</div>

<?php if ( 'updated' === $notice ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Contrat mis à jour.', 'association-manager' ); ?></div>
<?php elseif ( 'created' === $notice ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Contrat ajouté.', 'association-manager' ); ?></div>
<?php endif; ?>

<div class="amap-info-card">
    <dl class="amap-info-list">
        <div>
            <dt><?php esc_html_e( 'Producteur', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $producer ? $producer->display_name : '—' ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Type', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $contract_type_labels[ $contract->contract_type ] ?? $contract->contract_type ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Période', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $contract->start_date . ' → ' . $contract->end_date ); ?></dd>
        </div>
        <?php if ( $is_basket_recurring ) : ?>
            <div>
                <dt><?php esc_html_e( 'Fréquence', 'association-manager' ); ?></dt>
                <dd>
                    <?php
                    printf(
                        /* translators: %d: fréquence en semaines. */
                        esc_html__( 'Toutes les %d semaines', 'association-manager' ),
                        (int) $contract->frequency_weeks
                    );
                    ?>
                </dd>
            </div>
            <div>
                <dt><?php esc_html_e( 'Congés max', 'association-manager' ); ?></dt>
                <dd><?php echo esc_html( $contract->max_leaves ); ?></dd>
            </div>
        <?php endif; ?>
        <div>
            <dt><?php esc_html_e( 'Statut', 'association-manager' ); ?></dt>
            <dd>
                <span class="amap-status-pill amap-status-pill--<?php echo $contract->is_active ? 'active' : 'inactive'; ?>">
                    <?php echo $contract->is_active ? esc_html__( 'Actif', 'association-manager' ) : esc_html__( 'Inactif', 'association-manager' ); ?>
                </span>
            </dd>
        </div>
    </dl>
    <div class="amap-info-card__actions">
        <a class="button-primary" href="<?php echo esc_url( amap_get_board_contract_edit_url( $contract->id ) ); ?>">
            <?php esc_html_e( 'Modifier les infos', 'association-manager' ); ?>
        </a>
    </div>
</div>

<?php if ( $is_basket_recurring ) : ?>
    <details class="amap-disclosure"<?php echo $sizes_open ? ' open' : ''; ?>>
        <summary>
            <?php esc_html_e( 'Tailles de panier', 'association-manager' ); ?>
            <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
        </summary>
        <div class="amap-disclosure__body">
            <?php if ( 'basket_size_invalid' === $notice ) : ?>
                <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Libellé ou prix invalide.', 'association-manager' ); ?></div>
            <?php elseif ( 'basket_size_saved' === $notice ) : ?>
                <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Taille de panier enregistrée.', 'association-manager' ); ?></div>
            <?php elseif ( 'basket_size_deleted' === $notice ) : ?>
                <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Taille de panier supprimée.', 'association-manager' ); ?></div>
            <?php endif; ?>

            <?php if ( empty( $args['basket_sizes'] ) ) : ?>
                <p><?php esc_html_e( 'Aucune taille de panier pour le moment.', 'association-manager' ); ?></p>
            <?php else : ?>
                <div class="amap-mini-list">
                    <?php foreach ( $args['basket_sizes'] as $basket_size ) : ?>
                        <?php
                        $size_edit_url   = add_query_arg( array( 'size_action' => 'edit', 'size_id' => $basket_size->id ), $view_url );
                        $size_delete_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'action'      => 'amap_delete_contract_basket_size',
                                    'id'          => $basket_size->id,
                                    'redirect_to' => rawurlencode( $view_url ),
                                ),
                                admin_url( 'admin-post.php' )
                            ),
                            'amap_delete_contract_basket_size_' . $basket_size->id
                        );
                        ?>
                        <div class="amap-mini-row">
                            <span class="amap-mini-row__label"><?php echo esc_html( $basket_size->label ); ?></span>
                            <span class="amap-mini-row__value"><?php echo esc_html( number_format_i18n( $basket_size->price, 2 ) . ' €' ); ?></span>
                            <span class="amap-mini-row__actions">
                                <a href="<?php echo esc_url( $size_edit_url ); ?>"><?php esc_html_e( 'Modifier', 'association-manager' ); ?></a>
                                <a href="<?php echo esc_url( $size_delete_url ); ?>" class="is-danger" onclick="return confirm( '<?php echo esc_js( __( 'Supprimer définitivement cette taille de panier ?', 'association-manager' ) ); ?>' );">
                                    <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                </a>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3>
                <?php echo $args['size_editing_id']
                    ? esc_html__( 'Modifier une taille de panier', 'association-manager' )
                    : esc_html__( 'Ajouter une taille de panier', 'association-manager' ); ?>
            </h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-mini-form">
                <?php if ( $args['size_editing_id'] ) : ?>
                    <?php wp_nonce_field( 'amap_edit_contract_basket_size_' . $args['size_editing_id'] ); ?>
                    <input type="hidden" name="action" value="amap_update_contract_basket_size">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $args['size_editing_id'] ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_contract_basket_size_' . $contract->id ); ?>
                    <input type="hidden" name="action" value="amap_add_contract_basket_size">
                    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $contract->id ); ?>">
                <?php endif; ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $view_url ); ?>">

                <div class="amap-field-row">
                    <div class="amap-field">
                        <label for="amap-basket-size-label"><?php esc_html_e( 'Libellé', 'association-manager' ); ?></label>
                        <input type="text" id="amap-basket-size-label" name="label" value="<?php echo esc_attr( $args['size_form_data']['label'] ?? '' ); ?>" required>
                    </div>
                    <div class="amap-field">
                        <label for="amap-basket-size-price"><?php esc_html_e( 'Prix (€)', 'association-manager' ); ?></label>
                        <input type="number" id="amap-basket-size-price" name="price" min="0.01" step="0.01" value="<?php echo esc_attr( $args['size_form_data']['price'] ?? '' ); ?>" required>
                    </div>
                </div>

                <div class="amap-form-actions">
                    <button type="submit" class="button-primary">
                        <?php echo $args['size_editing_id'] ? esc_html__( 'Enregistrer', 'association-manager' ) : esc_html__( 'Ajouter', 'association-manager' ); ?>
                    </button>
                    <?php if ( $args['size_editing_id'] ) : ?>
                        <a class="button-secondary" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </details>
<?php endif; ?>

<?php if ( $is_product_grid ) : ?>
    <details class="amap-disclosure"<?php echo $discount_groups_open ? ' open' : ''; ?>>
        <summary>
            <?php esc_html_e( 'Familles de remise', 'association-manager' ); ?>
            <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
        </summary>
        <div class="amap-disclosure__body">
            <p class="amap-disclosure__hint">
                <?php esc_html_e( "Un produit rattaché à une famille de remise reprend automatiquement son prix : la quantité facturée reste inférieure à la quantité achetée (ex. 5 achetés, 4 facturés).", 'association-manager' ); ?>
            </p>

            <?php if ( 'contract_discount_group_invalid' === $notice ) : ?>
                <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Libellé, prix ou seuils invalides : la quantité facturée doit être strictement inférieure à la quantité achetée.', 'association-manager' ); ?></div>
            <?php elseif ( 'contract_discount_group_saved' === $notice ) : ?>
                <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Famille de remise enregistrée.', 'association-manager' ); ?></div>
            <?php elseif ( 'contract_discount_group_deleted' === $notice ) : ?>
                <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Famille de remise supprimée.', 'association-manager' ); ?></div>
            <?php endif; ?>

            <?php if ( empty( $args['discount_groups'] ) ) : ?>
                <p><?php esc_html_e( 'Aucune famille de remise pour le moment.', 'association-manager' ); ?></p>
            <?php else : ?>
                <div class="amap-mini-list">
                    <?php foreach ( $args['discount_groups'] as $discount_group ) : ?>
                        <?php
                        $discount_edit_url   = add_query_arg( array( 'discount_action' => 'edit', 'discount_id' => $discount_group->id ), $view_url );
                        $discount_delete_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'action'      => 'amap_delete_contract_discount_group',
                                    'id'          => $discount_group->id,
                                    'redirect_to' => rawurlencode( $view_url ),
                                ),
                                admin_url( 'admin-post.php' )
                            ),
                            'amap_delete_contract_discount_group_' . $discount_group->id
                        );
                        ?>
                        <div class="amap-mini-row">
                            <span class="amap-mini-row__label"><?php echo esc_html( $discount_group->label ); ?></span>
                            <span class="amap-mini-row__value">
                                <?php echo esc_html( number_format_i18n( $discount_group->price, 2 ) . ' € · ' . sprintf(
                                    /* translators: 1: quantité achetée. 2: quantité facturée. */
                                    __( '%1$d achetés → %2$d facturés', 'association-manager' ),
                                    $discount_group->bought_quantity,
                                    $discount_group->billed_quantity
                                ) ); ?>
                            </span>
                            <span class="amap-mini-row__actions">
                                <a href="<?php echo esc_url( $discount_edit_url ); ?>"><?php esc_html_e( 'Modifier', 'association-manager' ); ?></a>
                                <a href="<?php echo esc_url( $discount_delete_url ); ?>" class="is-danger" onclick="return confirm( '<?php echo esc_js( __( 'Supprimer cette famille de remise ? Les produits qui en dépendent repasseront en prix libre.', 'association-manager' ) ); ?>' );">
                                    <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                </a>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h4>
                <?php echo $args['discount_group_editing_id']
                    ? esc_html__( 'Modifier une famille de remise', 'association-manager' )
                    : esc_html__( 'Ajouter une famille de remise', 'association-manager' ); ?>
            </h4>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-mini-form">
                <?php if ( $args['discount_group_editing_id'] ) : ?>
                    <?php wp_nonce_field( 'amap_edit_contract_discount_group_' . $args['discount_group_editing_id'] ); ?>
                    <input type="hidden" name="action" value="amap_update_contract_discount_group">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $args['discount_group_editing_id'] ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_contract_discount_group_' . $contract->id ); ?>
                    <input type="hidden" name="action" value="amap_add_contract_discount_group">
                    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $contract->id ); ?>">
                <?php endif; ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $view_url ); ?>">

                <div class="amap-field-row">
                    <div class="amap-field">
                        <label for="amap-discount-group-label"><?php esc_html_e( 'Libellé', 'association-manager' ); ?></label>
                        <input type="text" id="amap-discount-group-label" name="label" value="<?php echo esc_attr( $args['discount_group_form_data']['label'] ?? '' ); ?>" required>
                    </div>
                    <div class="amap-field">
                        <label for="amap-discount-group-price"><?php esc_html_e( 'Prix (€)', 'association-manager' ); ?></label>
                        <input type="number" id="amap-discount-group-price" name="price" min="0.01" step="0.01" value="<?php echo esc_attr( $args['discount_group_form_data']['price'] ?? '' ); ?>" required>
                    </div>
                </div>
                <div class="amap-field-row">
                    <div class="amap-field">
                        <label for="amap-discount-group-bought"><?php esc_html_e( 'Quantité achetée', 'association-manager' ); ?></label>
                        <input type="number" id="amap-discount-group-bought" name="bought_quantity" min="1" value="<?php echo esc_attr( $args['discount_group_form_data']['bought_quantity'] ?? '' ); ?>" required>
                    </div>
                    <div class="amap-field">
                        <label for="amap-discount-group-billed"><?php esc_html_e( 'Quantité facturée', 'association-manager' ); ?></label>
                        <input type="number" id="amap-discount-group-billed" name="billed_quantity" min="1" value="<?php echo esc_attr( $args['discount_group_form_data']['billed_quantity'] ?? '' ); ?>" required>
                        <p class="amap-field__hint"><?php esc_html_e( 'Doit être strictement inférieure à la quantité achetée.', 'association-manager' ); ?></p>
                    </div>
                </div>

                <div class="amap-form-actions">
                    <button type="submit" class="button-primary">
                        <?php echo $args['discount_group_editing_id'] ? esc_html__( 'Enregistrer', 'association-manager' ) : esc_html__( 'Ajouter', 'association-manager' ); ?>
                    </button>
                    <?php if ( $args['discount_group_editing_id'] ) : ?>
                        <a class="button-secondary" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </details>

    <details class="amap-disclosure"<?php echo $catalog_open ? ' open' : ''; ?>>
        <summary>
            <?php esc_html_e( 'Catalogue produits', 'association-manager' ); ?>
            <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
        </summary>
        <div class="amap-disclosure__body">
            <?php if ( 'contract_product_invalid' === $notice ) : ?>
                <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Libellé ou prix invalide.', 'association-manager' ); ?></div>
            <?php elseif ( 'contract_product_saved' === $notice ) : ?>
                <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Produit enregistré.', 'association-manager' ); ?></div>
            <?php elseif ( 'contract_product_deleted' === $notice ) : ?>
                <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Produit supprimé.', 'association-manager' ); ?></div>
            <?php endif; ?>

            <?php
            $discount_group_labels = wp_list_pluck( $args['discount_groups'], 'label', 'id' );
            ?>
            <?php if ( empty( $args['products'] ) ) : ?>
                <p><?php esc_html_e( 'Aucun produit pour le moment.', 'association-manager' ); ?></p>
            <?php else : ?>
                <div class="amap-mini-list">
                    <?php foreach ( $args['products'] as $product ) : ?>
                        <?php
                        $product_edit_url   = add_query_arg( array( 'product_action' => 'edit', 'product_id' => $product->id ), $view_url );
                        $product_delete_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'action'      => 'amap_delete_contract_product',
                                    'id'          => $product->id,
                                    'redirect_to' => rawurlencode( $view_url ),
                                ),
                                admin_url( 'admin-post.php' )
                            ),
                            'amap_delete_contract_product_' . $product->id
                        );
                        ?>
                        <div class="amap-mini-row">
                            <span class="amap-mini-row__label"><?php echo esc_html( $product->label ); ?></span>
                            <span class="amap-mini-row__value">
                                <?php
                                echo esc_html( number_format_i18n( $product->price, 2 ) . ' €' );
                                if ( $product->discount_group_id && isset( $discount_group_labels[ $product->discount_group_id ] ) ) {
                                    echo ' · ' . esc_html( $discount_group_labels[ $product->discount_group_id ] );
                                }
                                ?>
                            </span>
                            <span class="amap-mini-row__actions">
                                <a href="<?php echo esc_url( $product_edit_url ); ?>"><?php esc_html_e( 'Modifier', 'association-manager' ); ?></a>
                                <a href="<?php echo esc_url( $product_delete_url ); ?>" class="is-danger" onclick="return confirm( '<?php echo esc_js( __( 'Supprimer définitivement ce produit ?', 'association-manager' ) ); ?>' );">
                                    <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                </a>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h4>
                <?php echo $args['product_editing_id']
                    ? esc_html__( 'Modifier un produit', 'association-manager' )
                    : esc_html__( 'Ajouter un produit', 'association-manager' ); ?>
            </h4>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-mini-form" id="amap-product-form">
                <?php if ( $args['product_editing_id'] ) : ?>
                    <?php wp_nonce_field( 'amap_edit_contract_product_' . $args['product_editing_id'] ); ?>
                    <input type="hidden" name="action" value="amap_update_contract_product">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $args['product_editing_id'] ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_contract_product_' . $contract->id ); ?>
                    <input type="hidden" name="action" value="amap_add_contract_product">
                    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $contract->id ); ?>">
                <?php endif; ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $view_url ); ?>">

                <div class="amap-field-row">
                    <div class="amap-field">
                        <label for="amap-product-label"><?php esc_html_e( 'Libellé', 'association-manager' ); ?></label>
                        <input type="text" id="amap-product-label" name="label" value="<?php echo esc_attr( $args['product_form_data']['label'] ?? '' ); ?>" required>
                    </div>
                    <div class="amap-field">
                        <label for="amap-product-discount-group"><?php esc_html_e( 'Famille de remise', 'association-manager' ); ?> <span class="amap-field__optional">(<?php esc_html_e( 'facultatif', 'association-manager' ); ?>)</span></label>
                        <select id="amap-product-discount-group" name="discount_group_id">
                            <option value=""><?php esc_html_e( '— Prix libre —', 'association-manager' ); ?></option>
                            <?php foreach ( $args['discount_groups'] as $discount_group ) : ?>
                                <option
                                    value="<?php echo esc_attr( $discount_group->id ); ?>"
                                    data-price="<?php echo esc_attr( $discount_group->price ); ?>"
                                    <?php selected( (string) $discount_group->id, $args['product_form_data']['discount_group_id'] ?? '' ); ?>
                                >
                                    <?php echo esc_html( $discount_group->label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="amap-field">
                        <label for="amap-product-price"><?php esc_html_e( 'Prix (€)', 'association-manager' ); ?></label>
                        <input type="number" id="amap-product-price" name="price" min="0.01" step="0.01" value="<?php echo esc_attr( $args['product_form_data']['price'] ?? '' ); ?>">
                        <p class="amap-field__hint" id="amap-product-price-hint" hidden><?php esc_html_e( 'Prix imposé par la famille de remise choisie.', 'association-manager' ); ?></p>
                    </div>
                </div>

                <div class="amap-form-actions">
                    <button type="submit" class="button-primary">
                        <?php echo $args['product_editing_id'] ? esc_html__( 'Enregistrer', 'association-manager' ) : esc_html__( 'Ajouter', 'association-manager' ); ?>
                    </button>
                    <?php if ( $args['product_editing_id'] ) : ?>
                        <a class="button-secondary" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
                    <?php endif; ?>
                </div>
            </form>
            <script>
            ( function () {
                "use strict";
                var discountField = document.getElementById( 'amap-product-discount-group' );
                var priceField    = document.getElementById( 'amap-product-price' );
                var priceHint     = document.getElementById( 'amap-product-price-hint' );

                function syncPriceField() {
                    var selected     = discountField.options[ discountField.selectedIndex ];
                    var lockedPrice  = selected ? selected.getAttribute( 'data-price' ) : null;

                    if ( lockedPrice ) {
                        priceField.value    = lockedPrice;
                        priceField.disabled = true;
                        priceField.required = false;
                        priceHint.hidden    = false;
                    } else {
                        priceField.disabled = false;
                        priceField.required = true;
                        priceHint.hidden     = true;
                    }
                }

                discountField.addEventListener( 'change', syncPriceField );
                syncPriceField();
            } )();
            </script>
        </div>
    </details>

    <details class="amap-disclosure"<?php echo $dates_open ? ' open' : ''; ?>>
        <summary>
            <?php esc_html_e( 'Dates de livraison', 'association-manager' ); ?>
            <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
        </summary>
        <div class="amap-disclosure__body">
            <?php if ( 'contract_delivery_date_invalid' === $notice ) : ?>
                <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Groupe ou date invalide.', 'association-manager' ); ?></div>
            <?php elseif ( 'contract_delivery_date_out_of_range' === $notice ) : ?>
                <div class="amap-notice amap-notice--error"><?php esc_html_e( 'La date doit être comprise dans la période du contrat.', 'association-manager' ); ?></div>
            <?php elseif ( 'contract_delivery_date_duplicate' === $notice ) : ?>
                <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Cette date de livraison est déjà enregistrée pour ce groupe.', 'association-manager' ); ?></div>
            <?php elseif ( 'contract_delivery_date_saved' === $notice ) : ?>
                <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Date de livraison enregistrée.', 'association-manager' ); ?></div>
            <?php elseif ( 'contract_delivery_date_deleted' === $notice ) : ?>
                <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Date de livraison supprimée.', 'association-manager' ); ?></div>
            <?php elseif ( 'contract_delivery_dates_generated' === $notice ) : ?>
                <div class="amap-notice amap-notice--success">
                    <?php
                    printf(
                        /* translators: %d: nombre de dates générées. */
                        esc_html__( '%d date(s) de livraison générée(s).', 'association-manager' ),
                        (int) $args['generated_count']
                    );
                    ?>
                </div>
            <?php elseif ( 'contract_delivery_dates_bulk_deleted' === $notice ) : ?>
                <div class="amap-notice amap-notice--success">
                    <?php
                    printf(
                        /* translators: %d: nombre de dates supprimées. */
                        esc_html__( '%d date(s) de livraison supprimée(s).', 'association-manager' ),
                        (int) $args['deleted_count']
                    );
                    ?>
                </div>
            <?php endif; ?>

            <?php if ( empty( $args['delivery_date_groups'] ) ) : ?>
                <p>
                    <?php esc_html_e( "Ce producteur n'a encore aucun groupe de distribution rattaché.", 'association-manager' ); ?>
                    <a href="<?php echo esc_url( amap_get_board_groups_url() ); ?>"><?php esc_html_e( 'Voir la page Groupes', 'association-manager' ); ?></a>
                </p>
            <?php else : ?>
                <?php foreach ( $args['delivery_date_groups'] as $delivery_date_group ) : ?>
                    <?php
                    $group_row       = $delivery_date_group['group'];
                    $dates           = $delivery_date_group['dates'];
                    $candidate_dates = $delivery_date_group['candidate_dates'];
                    $group_open      = ( (int) $args['generate_group_id'] === (int) $group_row->id )
                        || ( $args['delivery_date_editing'] && (int) $args['delivery_date_editing']->group_id === (int) $group_row->id );
                    ?>
                    <details class="amap-delivery-group"<?php echo $group_open ? ' open' : ''; ?>>
                        <summary>
                            <span>
                                <?php
                                printf(
                                    /* translators: 1: nom du groupe. 2: jour de semaine. 3: nombre de dates enregistrées. */
                                    esc_html__( '%1$s — %2$s (%3$d date(s))', 'association-manager' ),
                                    esc_html( $group_row->name ),
                                    esc_html( $weekday_labels[ (int) $group_row->weekday ] ?? '' ),
                                    count( $dates )
                                );
                                ?>
                            </span>
                            <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
                        </summary>
                        <div class="amap-disclosure__body">
                            <?php if ( empty( $dates ) ) : ?>
                                <p><?php esc_html_e( 'Aucune date enregistrée pour ce groupe.', 'association-manager' ); ?></p>
                            <?php else : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-bulk-delete-dates-form" data-count="<?php echo esc_attr( count( $dates ) ); ?>">
                                    <?php wp_nonce_field( 'amap_bulk_delete_contract_delivery_dates_' . $contract->id . '_' . $group_row->id ); ?>
                                    <input type="hidden" name="action" value="amap_bulk_delete_contract_delivery_dates">
                                    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $contract->id ); ?>">
                                    <input type="hidden" name="group_id" value="<?php echo esc_attr( $group_row->id ); ?>">
                                    <input type="hidden" name="redirect_to" value="<?php echo esc_url( $view_url ); ?>">
                                    <ul class="amap-checkbox-date-list">
                                        <?php foreach ( $dates as $date_row ) : ?>
                                            <?php
                                            $date_edit_url   = add_query_arg( array( 'date_action' => 'edit', 'date_id' => $date_row->id ), $view_url );
                                            $date_delete_url = wp_nonce_url(
                                                add_query_arg(
                                                    array(
                                                        'action'      => 'amap_delete_contract_delivery_date',
                                                        'id'          => $date_row->id,
                                                        'redirect_to' => rawurlencode( $view_url ),
                                                    ),
                                                    admin_url( 'admin-post.php' )
                                                ),
                                                'amap_delete_contract_delivery_date_' . $date_row->id
                                            );
                                            ?>
                                            <li>
                                                <label>
                                                    <input type="checkbox" name="delivery_date_ids[]" value="<?php echo esc_attr( $date_row->id ); ?>">
                                                    <?php echo esc_html( $date_row->delivery_date ); ?>
                                                </label>
                                                <span class="amap-checkbox-date-list__actions">
                                                    <a href="<?php echo esc_url( $date_edit_url ); ?>"><?php esc_html_e( 'Modifier', 'association-manager' ); ?></a>
                                                    <a href="<?php echo esc_url( $date_delete_url ); ?>" class="is-danger" onclick="return confirm( '<?php echo esc_js( __( 'Supprimer définitivement cette date ?', 'association-manager' ) ); ?>' );">
                                                        <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                                    </a>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <div class="amap-form-actions">
                                        <button type="submit" class="button-secondary"><?php esc_html_e( 'Supprimer les dates sélectionnées', 'association-manager' ); ?></button>
                                    </div>
                                </form>
                            <?php endif; ?>

                            <?php if ( ! empty( $candidate_dates ) ) : ?>
                                <button type="button" class="button-secondary amap-dates-generate-toggle" data-target="amap-generate-form-<?php echo esc_attr( $group_row->id ); ?>">
                                    <?php esc_html_e( 'Générer des dates pour ce groupe', 'association-manager' ); ?>
                                </button>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-mini-form" id="amap-generate-form-<?php echo esc_attr( $group_row->id ); ?>" hidden>
                                    <?php wp_nonce_field( 'amap_generate_contract_delivery_dates_' . $contract->id . '_' . $group_row->id ); ?>
                                    <input type="hidden" name="action" value="amap_generate_contract_delivery_dates">
                                    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $contract->id ); ?>">
                                    <input type="hidden" name="group_id" value="<?php echo esc_attr( $group_row->id ); ?>">
                                    <input type="hidden" name="redirect_to" value="<?php echo esc_url( $view_url ); ?>">

                                    <div class="amap-frequency-row">
                                        <label for="amap-generate-every-<?php echo esc_attr( $group_row->id ); ?>"><?php esc_html_e( 'Cocher une date sur', 'association-manager' ); ?></label>
                                        <input type="number" id="amap-generate-every-<?php echo esc_attr( $group_row->id ); ?>" min="1" max="52" value="1">
                                        <button type="button" class="button-secondary amap-dates-thin-apply" data-target="amap-generate-form-<?php echo esc_attr( $group_row->id ); ?>">
                                            <?php esc_html_e( 'Appliquer', 'association-manager' ); ?>
                                        </button>
                                    </div>

                                    <ul class="amap-checkbox-date-list">
                                        <?php foreach ( $candidate_dates as $index => $candidate_date ) : ?>
                                            <li>
                                                <label>
                                                    <input type="checkbox" name="delivery_dates[]" value="<?php echo esc_attr( $candidate_date ); ?>" data-index="<?php echo esc_attr( $index ); ?>" checked>
                                                    <?php echo esc_html( date_i18n( 'l j F Y', strtotime( $candidate_date ) ) ); ?>
                                                </label>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>

                                    <div class="amap-form-actions">
                                        <button type="submit" class="button-primary"><?php esc_html_e( 'Générer les dates cochées', 'association-manager' ); ?></button>
                                        <button type="button" class="button-secondary amap-dates-generate-cancel" data-target="amap-generate-form-<?php echo esc_attr( $group_row->id ); ?>">
                                            <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>

                            <button type="button" class="button-secondary amap-dates-add-toggle" data-target="amap-add-date-form-<?php echo esc_attr( $group_row->id ); ?>">
                                <?php esc_html_e( '+ Ajouter une date pour ce groupe', 'association-manager' ); ?>
                            </button>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-mini-form" id="amap-add-date-form-<?php echo esc_attr( $group_row->id ); ?>" hidden>
                                <?php wp_nonce_field( 'amap_add_contract_delivery_date_' . $contract->id ); ?>
                                <input type="hidden" name="action" value="amap_add_contract_delivery_date">
                                <input type="hidden" name="contract_id" value="<?php echo esc_attr( $contract->id ); ?>">
                                <input type="hidden" name="group_id" value="<?php echo esc_attr( $group_row->id ); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $view_url ); ?>">
                                <div class="amap-field">
                                    <label for="amap-add-date-input-<?php echo esc_attr( $group_row->id ); ?>"><?php esc_html_e( 'Date exceptionnelle', 'association-manager' ); ?></label>
                                    <input type="date" id="amap-add-date-input-<?php echo esc_attr( $group_row->id ); ?>" name="delivery_date" min="<?php echo esc_attr( $contract->start_date ); ?>" max="<?php echo esc_attr( $contract->end_date ); ?>" required>
                                    <p class="amap-field__hint"><?php esc_html_e( "N'importe quel jour de la période du contrat, y compris hors du jour habituel de ce groupe (date exceptionnelle).", 'association-manager' ); ?></p>
                                </div>
                                <div class="amap-form-actions">
                                    <button type="submit" class="button-primary"><?php esc_html_e( 'Ajouter', 'association-manager' ); ?></button>
                                </div>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>

                <?php if ( $args['delivery_date_editing_id'] && $args['delivery_date_editing'] ) : ?>
                    <h3><?php esc_html_e( 'Modifier une date de livraison', 'association-manager' ); ?></h3>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-mini-form">
                        <?php wp_nonce_field( 'amap_edit_contract_delivery_date_' . $args['delivery_date_editing_id'] ); ?>
                        <input type="hidden" name="action" value="amap_update_contract_delivery_date">
                        <input type="hidden" name="id" value="<?php echo esc_attr( $args['delivery_date_editing_id'] ); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $view_url ); ?>">
                        <div class="amap-field-row">
                            <div class="amap-field">
                                <label for="amap-edit-date-group"><?php esc_html_e( 'Groupe', 'association-manager' ); ?></label>
                                <select id="amap-edit-date-group" name="group_id" required>
                                    <?php foreach ( $args['delivery_date_groups'] as $delivery_date_group ) : ?>
                                        <option value="<?php echo esc_attr( $delivery_date_group['group']->id ); ?>" <?php selected( (string) $delivery_date_group['group']->id, $args['delivery_date_form_data']['group_id'] ?? '' ); ?>>
                                            <?php echo esc_html( $delivery_date_group['group']->name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="amap-field">
                                <label for="amap-edit-date-input"><?php esc_html_e( 'Date', 'association-manager' ); ?></label>
                                <input type="date" id="amap-edit-date-input" name="delivery_date" min="<?php echo esc_attr( $contract->start_date ); ?>" max="<?php echo esc_attr( $contract->end_date ); ?>" value="<?php echo esc_attr( $args['delivery_date_form_data']['delivery_date'] ?? '' ); ?>" required>
                            </div>
                        </div>
                        <div class="amap-form-actions">
                            <button type="submit" class="button-primary"><?php esc_html_e( 'Enregistrer', 'association-manager' ); ?></button>
                            <a class="button-secondary" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <script>
            ( function () {
                "use strict";

                document.querySelectorAll( '.amap-dates-generate-toggle, .amap-dates-add-toggle' ).forEach( function ( toggle ) {
                    toggle.addEventListener( 'click', function () {
                        var target = document.getElementById( toggle.dataset.target );
                        target.hidden  = false;
                        toggle.hidden  = true;
                    } );
                } );

                document.querySelectorAll( '.amap-dates-generate-cancel' ).forEach( function ( cancel ) {
                    cancel.addEventListener( 'click', function () {
                        var target = document.getElementById( cancel.dataset.target );
                        var toggle = document.querySelector( '.amap-dates-generate-toggle[data-target="' + cancel.dataset.target + '"]' );
                        target.hidden = true;
                        if ( toggle ) {
                            toggle.hidden = false;
                        }
                    } );
                } );

                document.querySelectorAll( '.amap-dates-thin-apply' ).forEach( function ( applyButton ) {
                    applyButton.addEventListener( 'click', function () {
                        var form  = document.getElementById( applyButton.dataset.target );
                        var every = parseInt( form.querySelector( 'input[type="number"]' ).value, 10 ) || 1;
                        form.querySelectorAll( '.amap-checkbox-date-list input[type="checkbox"]' ).forEach( function ( checkbox ) {
                            var index = parseInt( checkbox.dataset.index, 10 );
                            checkbox.checked = ( 0 === index % every );
                        } );
                    } );
                } );

                document.querySelectorAll( '.amap-bulk-delete-dates-form' ).forEach( function ( form ) {
                    form.addEventListener( 'submit', function ( event ) {
                        var checkedCount = form.querySelectorAll( 'input[name="delivery_date_ids[]"]:checked' ).length;
                        if ( ! checkedCount ) {
                            event.preventDefault();
                            return;
                        }
                        var message = <?php echo wp_json_encode( __( 'Supprimer définitivement les dates sélectionnées ?', 'association-manager' ) ); ?>;
                        if ( ! confirm( message ) ) {
                            event.preventDefault();
                        }
                    } );
                } );
            } )();
            </script>
        </div>
    </details>
<?php endif; ?>
