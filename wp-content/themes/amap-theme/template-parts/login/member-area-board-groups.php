<?php
/**
 * Section "Groupes" de l'onglet "Espace bureau" : liste des groupes de distribution (recherche
 * nom/lieu de livraison, pagination), migrée depuis la page wp-admin "Groupes"
 * (amap_render_groups_page()) sans WP_List_Table. $args : voir amap_get_board_groups_list_data()
 * (plugin, member-area.php).
 */
$weekday_labels = amap_get_weekday_labels();
?>
<div class="amap-stack">
    <div class="amap-list-toolbar">
        <h2><?php esc_html_e( 'Groupes de distribution', 'association-manager' ); ?></h2>
        <a class="button-primary" href="<?php echo esc_url( amap_get_board_group_add_url() ); ?>">
            <?php esc_html_e( '+ Ajouter un groupe', 'association-manager' ); ?>
        </a>
    </div>

    <?php if ( 'created' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Groupe ajouté.', 'association-manager' ); ?></div>
    <?php elseif ( 'deleted' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Groupe supprimé.', 'association-manager' ); ?></div>
    <?php endif; ?>

    <form method="get" class="amap-search-bar">
        <input type="hidden" name="amap_tab" value="board">
        <input type="hidden" name="amap_board_section" value="groups">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-search"></use></svg>
        <input type="search" name="amap_board_search" placeholder="<?php esc_attr_e( 'Rechercher un nom, un lieu de livraison…', 'association-manager' ); ?>" value="<?php echo esc_attr( $args['search'] ); ?>">
    </form>

    <?php if ( empty( $args['groups'] ) ) : ?>
        <p><?php esc_html_e( 'Aucun groupe trouvé.', 'association-manager' ); ?></p>
    <?php else : ?>
        <div class="amap-admin-list">
            <?php foreach ( $args['groups'] as $group ) : ?>
                <?php
                $producer_count = $args['producer_counts'][ (int) $group->id ] ?? 0;
                $member_count   = $args['member_counts'][ (int) $group->id ] ?? 0;

                $meta_parts = array(
                    $group->delivery_place,
                    $weekday_labels[ (int) $group->weekday ] ?? '',
                    amap_format_time( $group->start_time ) . '–' . amap_format_time( $group->end_time ),
                    sprintf(
                        /* translators: %d: nombre de producteurs rattachés. */
                        _n( '%d producteur', '%d producteurs', $producer_count, 'association-manager' ),
                        $producer_count
                    ),
                    sprintf(
                        /* translators: %d: nombre d'adhérents rattachés. */
                        _n( '%d adhérent', '%d adhérents', $member_count, 'association-manager' ),
                        $member_count
                    ),
                );
                ?>
                <div class="amap-admin-row">
                    <div class="amap-admin-row__top">
                        <div class="amap-admin-row__id">
                            <span class="amap-admin-row__name"><?php echo esc_html( $group->name ); ?></span>
                        </div>
                        <div class="amap-admin-row__actions">
                            <a href="<?php echo esc_url( amap_get_board_group_view_url( $group->id ) ); ?>">
                                <?php esc_html_e( 'Voir le groupe', 'association-manager' ); ?>
                            </a>
                            <a href="<?php echo esc_url( amap_get_board_group_delete_url( $group->id ) ); ?>" class="is-danger">
                                <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                            </a>
                        </div>
                    </div>
                    <p class="amap-admin-row__meta"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ( $args['total_pages'] > 1 ) : ?>
            <div class="amap-pagination">
                <span>
                    <?php
                    printf(
                        /* translators: 1: nombre total de groupes. 2: page actuelle. 3: nombre total de pages. */
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
