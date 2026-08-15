<?php
/**
 * Une carte de la liste "Mes contrats" de l'espace adhérent (member-area-member.php), extraite en
 * template part pour être réutilisée par les groupes "En cours" / "À venir" / "Terminés" sans
 * tripler le balisage. $args : item (une entrée de amap_get_member_subscriptions()),
 * contract_types, status_labels. Pas de ligne "Groupe (point de retrait)" ici : elle est affichée
 * une seule fois en haut de page (bandeau de contexte), un adhérent n'ayant qu'un seul point de
 * retrait pour tous ses contrats. Les symboles SVG référencés (#amap-icon-*) sont définis une
 * seule fois dans la coquille commune member-area.php, chargée avant l'onglet actif.
 *
 * Congés/montant/détail sont des frères de .amap-contract-card__top (pas nichés dans
 * .amap-contract-card__body, à côté de l'icône) : ils prennent toute la largeur de la carte,
 * comme sur la maquette — seuls le titre et le statut restent alignés à côté de l'icône.
 *
 * Le tableau de détail du prix est reconstruit ici à partir de amap_get_subscription_price_summary()
 * (les données), pas affiché via amap_get_subscription_price_summary_html() : cette dernière est
 * volontairement en styles inline (partagée avec l'email de confirmation, où une feuille de style
 * externe ne s'applique pas) — le styliser via des classes CSS ici permet de suivre la maquette
 * sans toucher à cette fonction partagée.
 */
$item            = $args['item'];
$is_basket       = ( 'basket_recurring' === $item['contract']->contract_type );
$type_modifier   = $is_basket ? 'basket' : 'grid';
$type_icon       = $is_basket ? 'amap-icon-basket' : 'amap-icon-grid';
$type_label      = $args['contract_types'][ $item['contract']->contract_type ] ?? '';
?>
<li class="amap-contract-card amap-contract-card--<?php echo esc_attr( $type_modifier ); ?>">
    <div class="amap-contract-card__top">
        <span class="amap-contract-card__type">
            <svg class="icon" aria-hidden="true"><use href="#<?php echo esc_attr( $type_icon ); ?>"></use></svg>
            <span class="sr-only"><?php echo esc_html( $type_label ); ?></span>
        </span>
        <div class="amap-contract-card__heading">
            <div>
                <div class="amap-contract-card__producer"><?php echo esc_html( $item['producer'] ? $item['producer']->display_name : '—' ); ?></div>
                <div class="amap-contract-card__label">
                    <?php echo esc_html( $item['contract']->label ); ?>
                    <?php if ( $item['basket_size'] ) : ?>
                        &middot; <?php echo esc_html( $item['basket_size']->label ); ?>
                    <?php endif; ?>
                </div>
            </div>
            <span class="amap-status-badge amap-status-badge--<?php echo esc_attr( $item['status'] ); ?>">
                <?php echo esc_html( $args['status_labels'][ $item['status'] ] ); ?>
            </span>
        </div>
    </div>

    <?php if ( $is_basket ) : ?>
        <?php
        $item_leaves       = amap_get_leaves( $item['subscription']->id );
        $item_max_leaves   = (int) $item['contract']->max_leaves;
        $item_leaves_used  = count( $item_leaves );
        $item_leaves_left  = $item_max_leaves - $item_leaves_used;
        ?>
        <div class="amap-leave-tracker">
            <span class="amap-leave-dots">
                <?php for ( $i = 0; $i < $item_max_leaves; $i++ ) : ?>
                    <span class="amap-leave-dot<?php echo ( $i < $item_leaves_used ) ? ' is-used' : ''; ?>"></span>
                <?php endfor; ?>
            </span>
            <span class="amap-leave-tracker__label">
                <?php if ( $item_leaves_used > 0 ) : ?>
                    <?php
                    printf(
                        /* translators: 1: nombre de congés pris. 2: leurs dates, séparées par des virgules. 3: nombre de congés restants. */
                        esc_html__( '%1$d congé(s) pris (%2$s) · %3$d restant(s)', 'association-manager' ),
                        $item_leaves_used,
                        implode(
                            ', ',
                            array_map(
                                static function ( $leave ) {
                                    return date_i18n( 'j F', strtotime( $leave->leave_date ) );
                                },
                                $item_leaves
                            )
                        ),
                        $item_leaves_left
                    );
                    ?>
                <?php else : ?>
                    <?php
                    printf(
                        /* translators: %d: nombre de congés restants. */
                        esc_html__( 'Aucun congé pris · %d restant(s)', 'association-manager' ),
                        $item_leaves_left
                    );
                    ?>
                <?php endif; ?>
            </span>
            <?php if ( $item_leaves_left > 0 ) : ?>
                <a class="button-secondary" href="<?php echo esc_url( amap_get_member_leave_url( $item['subscription']->id ) ); ?>">
                    <?php esc_html_e( 'Déclarer un congé', 'association-manager' ); ?>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php $item_price_summary = amap_get_subscription_price_summary( $item['subscription']->id ); ?>
    <?php if ( ! empty( $item_price_summary['lines'] ) ) : ?>
        <div class="amap-amount-row">
            <span class="amount">
                <?php echo esc_html( number_format_i18n( $item_price_summary['total'], 2 ) ); ?> €
                <small><?php esc_html_e( 'dû sur la saison', 'association-manager' ); ?></small>
            </span>
        </div>
        <details class="amap-detail">
            <summary>
                <?php esc_html_e( 'Voir le détail de la commande', 'association-manager' ); ?>
                <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
            </summary>
            <div class="amap-detail-table-wrap">
                <table class="amap-detail-table">
                    <tbody>
                        <?php foreach ( $item_price_summary['lines'] as $line ) : ?>
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
                            <td class="amap-detail-table__num"><?php echo esc_html( number_format_i18n( $item_price_summary['total'], 2 ) ); ?> €</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>

    <?php if ( 'upcoming' === $item['status'] ) : ?>
        <p class="amap-contract-card__facts">
            <?php
            printf(
                /* translators: %s: date de début du contrat. */
                esc_html__( 'Démarre le %s', 'association-manager' ),
                esc_html( date_i18n( 'j F Y', strtotime( $item['contract']->start_date ) ) )
            );
            ?>
        </p>
    <?php elseif ( 'ended' === $item['status'] ) : ?>
        <p class="amap-contract-card__facts">
            <?php
            printf(
                /* translators: %s: date de fin du contrat. */
                esc_html__( 'Terminé le %s', 'association-manager' ),
                esc_html( date_i18n( 'j F Y', strtotime( $item['contract']->end_date ) ) )
            );
            ?>
        </p>
    <?php endif; ?>
</li>
