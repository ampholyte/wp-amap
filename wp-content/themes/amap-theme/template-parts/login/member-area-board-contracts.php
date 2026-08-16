<?php
/**
 * Section "Contrats" de l'onglet "Espace bureau" : liste des contrats (recherche sur le libellé,
 * pagination), migrée depuis la page wp-admin "Contrats" (amap_render_contracts_page()) sans
 * WP_List_Table. $args : voir amap_get_board_contracts_list_data() (plugin, member-area.php).
 */
$contract_type_labels = amap_get_contract_types();
?>
<div class="amap-stack">
    <div class="amap-list-toolbar">
        <h2><?php esc_html_e( 'Contrats', 'association-manager' ); ?></h2>
        <a class="button-primary" href="<?php echo esc_url( amap_get_board_contract_add_url() ); ?>">
            <?php esc_html_e( '+ Ajouter un contrat', 'association-manager' ); ?>
        </a>
    </div>

    <?php if ( 'created' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Contrat ajouté.', 'association-manager' ); ?></div>
    <?php elseif ( 'deleted' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Contrat supprimé.', 'association-manager' ); ?></div>
    <?php endif; ?>

    <form method="get" class="amap-search-bar">
        <input type="hidden" name="amap_tab" value="board">
        <input type="hidden" name="amap_board_section" value="contracts">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-search"></use></svg>
        <input type="search" name="amap_board_search" placeholder="<?php esc_attr_e( 'Rechercher un libellé, un nom de producteur…', 'association-manager' ); ?>" value="<?php echo esc_attr( $args['search'] ); ?>">
    </form>

    <?php if ( empty( $args['contracts'] ) ) : ?>
        <p><?php esc_html_e( 'Aucun contrat trouvé.', 'association-manager' ); ?></p>
    <?php else : ?>
        <div class="amap-admin-list">
            <?php foreach ( $args['contracts'] as $contract ) : ?>
                <?php
                $producer_name      = $args['producer_names'][ (int) $contract->producer_user_id ] ?? '—';
                $subscription_count = $args['subscription_counts'][ (int) $contract->id ] ?? 0;
                $paid_count         = $args['paid_subscription_counts'][ (int) $contract->id ] ?? 0;

                $meta_parts = array(
                    $contract->start_date . ' → ' . $contract->end_date,
                );
                if ( 'basket_recurring' === $contract->contract_type ) {
                    $meta_parts[] = sprintf(
                        /* translators: %d: fréquence en semaines. */
                        __( 'Toutes les %d semaines', 'association-manager' ),
                        (int) $contract->frequency_weeks
                    );
                    $meta_parts[] = sprintf(
                        /* translators: %d: nombre de congés maximum autorisés. */
                        __( '%d congés max', 'association-manager' ),
                        (int) $contract->max_leaves
                    );
                }
                $meta_parts[] = sprintf(
                    /* translators: 1: nombre de souscriptions. 2: nombre de souscriptions payées. */
                    __( '%1$d souscription(s), %2$d payée(s)', 'association-manager' ),
                    $subscription_count,
                    $paid_count
                );
                ?>
                <div class="amap-admin-row">
                    <div class="amap-admin-row__top">
                        <div class="amap-admin-row__id">
                            <span class="amap-admin-row__name"><?php echo esc_html( $contract->label ); ?></span>
                            <span class="amap-status-pill amap-status-pill--<?php echo $contract->is_active ? 'active' : 'inactive'; ?>">
                                <?php echo $contract->is_active ? esc_html__( 'Actif', 'association-manager' ) : esc_html__( 'Inactif', 'association-manager' ); ?>
                            </span>
                        </div>
                        <div class="amap-admin-row__actions">
                            <a href="<?php echo esc_url( amap_get_board_contract_view_url( $contract->id ) ); ?>">
                                <?php esc_html_e( 'Voir', 'association-manager' ); ?>
                            </a>
                            <a href="<?php echo esc_url( amap_get_board_contract_delete_url( $contract->id ) ); ?>" class="is-danger">
                                <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                            </a>
                        </div>
                    </div>
                    <p class="amap-admin-row__contract">
                        <?php echo esc_html( $producer_name ); ?>
                        <span class="amap-admin-row__producer">&middot; <?php echo esc_html( $contract_type_labels[ $contract->contract_type ] ?? $contract->contract_type ); ?></span>
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
                        /* translators: 1: nombre total de contrats. 2: page actuelle. 3: nombre total de pages. */
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
