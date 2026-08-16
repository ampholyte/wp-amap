<?php
/**
 * Section "Souscriptions" de l'onglet "Espace bureau" : liste des souscriptions (triée par date
 * de signature récente), recherche libre + filtre par contrat (voir
 * amap_get_board_subscriptions_list_data()), migrée depuis la page wp-admin "Souscriptions"
 * (amap_render_subscriptions_page()) sans WP_List_Table. $args : voir
 * amap_get_board_subscriptions_list_data() (plugin, member-area.php).
 */
?>
<div class="amap-stack">
    <div class="amap-list-toolbar">
        <h2><?php esc_html_e( 'Souscriptions', 'association-manager' ); ?></h2>
        <a class="button-primary" href="<?php echo esc_url( amap_get_board_subscription_add_url() ); ?>">
            <?php esc_html_e( '+ Ajouter une souscription', 'association-manager' ); ?>
        </a>
    </div>

    <?php if ( 'created' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Souscription ajoutée.', 'association-manager' ); ?></div>
    <?php elseif ( 'deleted' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Souscription supprimée.', 'association-manager' ); ?></div>
    <?php endif; ?>

    <form method="get" class="amap-filters-row">
        <input type="hidden" name="amap_tab" value="board">
        <input type="hidden" name="amap_board_section" value="subscriptions">
        <div class="amap-search-bar">
            <svg class="icon" aria-hidden="true"><use href="#amap-icon-search"></use></svg>
            <input type="search" name="amap_board_search" placeholder="<?php esc_attr_e( 'Rechercher un adhérent, un contrat, un producteur…', 'association-manager' ); ?>" value="<?php echo esc_attr( $args['search'] ); ?>">
        </div>
        <select name="amap_subscription_contract_id" class="amap-contract-filter" onchange="this.form.submit()">
            <option value=""><?php esc_html_e( 'Tous les contrats', 'association-manager' ); ?></option>
            <?php foreach ( $args['contracts'] as $contract ) : ?>
                <option value="<?php echo esc_attr( $contract->id ); ?>" <?php selected( (string) $contract->id, (string) $args['contract_filter'] ); ?>>
                    <?php echo esc_html( $contract->label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ( empty( $args['subscriptions'] ) ) : ?>
        <p><?php esc_html_e( 'Aucune souscription trouvée.', 'association-manager' ); ?></p>
    <?php else : ?>
        <div class="amap-admin-list">
            <?php foreach ( $args['subscriptions'] as $subscription ) : ?>
                <?php
                $contract    = amap_get_contract( $subscription->contract_id );
                $producer    = $contract ? get_user_by( 'id', $contract->producer_user_id ) : null;
                $member      = get_user_by( 'id', $subscription->member_user_id );
                $group       = amap_get_group( $subscription->group_id );
                $basket_size = $subscription->basket_size_id ? amap_get_contract_basket_size( $subscription->basket_size_id ) : null;

                // Groupe/taille/date en meta : producteur et adhérent ont chacun leur propre ligne
                // ci-dessous (l'adhérent étant ce qu'on cherche le plus souvent une fois la liste
                // longue, il ressort en premier plutôt que noyé au milieu d'une seule ligne meta).
                $meta_parts = array( $group ? $group->name : '—' );
                if ( $basket_size ) {
                    $meta_parts[] = $basket_size->label;
                }
                $meta_parts[] = sprintf(
                    /* translators: %s: date de signature. */
                    __( 'Signée le %s', 'association-manager' ),
                    $subscription->signed_at
                );
                ?>
                <div class="amap-admin-row">
                    <div class="amap-admin-row__top">
                        <div class="amap-admin-row__id">
                            <span class="amap-admin-row__name"><?php echo esc_html( $member ? $member->display_name : '—' ); ?></span>
                            <span class="amap-paid-badge amap-paid-badge--<?php echo $subscription->is_paid ? 'yes' : 'no'; ?>">
                                <?php echo $subscription->is_paid ? esc_html__( 'Payé', 'association-manager' ) : esc_html__( 'Non payé', 'association-manager' ); ?>
                            </span>
                        </div>
                        <div class="amap-admin-row__actions">
                            <a href="<?php echo esc_url( amap_get_board_subscription_edit_url( $subscription->id ) ); ?>">
                                <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                            </a>
                            <a href="<?php echo esc_url( amap_get_board_subscription_delete_url( $subscription->id ) ); ?>" class="is-danger">
                                <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                            </a>
                        </div>
                    </div>
                    <p class="amap-admin-row__contract">
                        <?php echo esc_html( $contract ? $contract->label : '—' ); ?>
                        <span class="amap-admin-row__producer">&middot; <?php echo esc_html( $producer ? $producer->display_name : '—' ); ?></span>
                    </p>
                    <p class="amap-admin-row__meta"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ( $args['total_pages'] > 1 ) : ?>
            <div class="amap-pagination">
                <span>
                    <?php
                    printf(
                        /* translators: 1: nombre total de souscriptions. 2: page actuelle. 3: nombre total de pages. */
                        esc_html__( '%1$d éléments · page %2$d sur %3$d', 'association-manager' ),
                        $args['total'],
                        $args['current_page'],
                        $args['total_pages']
                    );
                    ?>
                </span>
                <div class="amap-pagination__nav">
                    <?php if ( $args['current_page'] > 1 ) : ?>
                        <a class="amap-pagination__btn" href="<?php echo esc_url( add_query_arg( 'amap_board_page', $args['current_page'] - 1 ) ); ?>" aria-label="<?php esc_attr_e( 'Page précédente', 'association-manager' ); ?>">
                            <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
                        </a>
                    <?php else : ?>
                        <span class="amap-pagination__btn is-disabled" aria-hidden="true">
                            <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
                        </span>
                    <?php endif; ?>
                    <?php if ( $args['current_page'] < $args['total_pages'] ) : ?>
                        <a class="amap-pagination__btn amap-pagination__btn--next" href="<?php echo esc_url( add_query_arg( 'amap_board_page', $args['current_page'] + 1 ) ); ?>" aria-label="<?php esc_attr_e( 'Page suivante', 'association-manager' ); ?>">
                            <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
                        </a>
                    <?php else : ?>
                        <span class="amap-pagination__btn amap-pagination__btn--next is-disabled" aria-hidden="true">
                            <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
