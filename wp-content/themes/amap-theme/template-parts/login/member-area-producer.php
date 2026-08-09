<?php
/**
 * Onglet "Espace producteur" (lecture seule, voir docs/plan-contrats-distributions.md) : contrats
 * du producteur connecté, groupes qu'il livre, prochaine distribution de chacun et
 * produits/paniers à y livrer (étapes 12.1/12.2/12.3). Pour un contrat basket_recurring, un bouton
 * "Détail (CSV)" télécharge le pointage nominatif des adhérents (étape 12.4,
 * amap_handle_export_contract_roster()) plutôt que de l'afficher en page.
 */
$contracts             = amap_get_producer_contracts( $args['current_user']->ID );
$groups                = amap_get_producer_groups( $args['current_user']->ID );
$status_labels         = amap_get_contract_period_status_labels();
$contract_types        = amap_get_contract_types();
$weekday_labels        = amap_get_weekday_labels();
$exception_type_labels = amap_get_distribution_exception_type_labels();

// Calculée une seule fois par groupe, réutilisée par les cartes "Mes groupes" et "Produits à
// livrer" ci-dessous.
$group_next_distributions = array();
foreach ( $groups as $group ) {
    $group_next_distributions[ $group->id ] = amap_get_group_next_distribution( $group );
}
?>

<div class="amap-card">
    <h2><?php esc_html_e( 'Mes contrats', 'association-manager' ); ?></h2>

    <?php if ( empty( $contracts ) ) : ?>
        <p><?php esc_html_e( "Vous n'avez pour l'instant aucun contrat.", 'association-manager' ); ?></p>
    <?php else : ?>
        <ul class="amap-subscription-list">
            <?php foreach ( $contracts as $contract ) : ?>
                <li class="amap-subscription-item">
                    <div class="amap-subscription-item__header">
                        <span class="amap-subscription-item__label"><?php echo esc_html( $contract->label ); ?></span>
                        <?php $status = amap_get_contract_period_status( $contract ); ?>
                        <span class="amap-status-badge amap-status-badge--<?php echo esc_attr( $status ); ?>">
                            <?php echo esc_html( $status_labels[ $status ] ); ?>
                        </span>
                    </div>
                    <ul>
                        <li><?php esc_html_e( 'Type', 'association-manager' ); ?> : <?php echo esc_html( $contract_types[ $contract->contract_type ] ?? '—' ); ?></li>
                        <li><?php esc_html_e( 'Période', 'association-manager' ); ?> : <?php echo esc_html( $contract->start_date . ' – ' . $contract->end_date ); ?></li>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="amap-card">
    <h2><?php esc_html_e( 'Mes groupes', 'association-manager' ); ?></h2>

    <?php if ( empty( $groups ) ) : ?>
        <p><?php esc_html_e( "Vous n'êtes pour l'instant rattaché à aucun groupe de distribution. Contactez le bureau.", 'association-manager' ); ?></p>
    <?php else : ?>
        <ul class="amap-subscription-list">
            <?php foreach ( $groups as $group ) : ?>
                <?php $next = $group_next_distributions[ $group->id ]; ?>
                <li class="amap-subscription-item">
                    <div class="amap-subscription-item__header">
                        <span class="amap-subscription-item__label"><?php echo esc_html( $group->name ); ?></span>
                        <?php if ( 'normal' !== $next['status'] ) : ?>
                            <span class="amap-status-badge amap-status-badge--<?php echo esc_attr( $next['status'] ); ?>">
                                <?php echo esc_html( $exception_type_labels[ $next['status'] ] ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <ul>
                        <li>
                            <?php
                            printf(
                                /* translators: 1: jour de la semaine. 2: horaire. 3: lieu de livraison. */
                                esc_html__( 'Jour de distribution : %1$s %2$s (%3$s)', 'association-manager' ),
                                esc_html( $weekday_labels[ (int) $group->weekday ] ?? '' ),
                                esc_html( amap_format_time( $group->start_time ) . '-' . amap_format_time( $group->end_time ) ),
                                esc_html( $group->delivery_place )
                            );
                            ?>
                        </li>
                        <?php if ( 'cancelled' === $next['status'] ) : ?>
                            <li>
                                <?php
                                printf(
                                    /* translators: %s: date de la distribution annulée. */
                                    esc_html__( 'Prochaine distribution : %s — annulée.', 'association-manager' ),
                                    esc_html( date_i18n( 'j F Y', strtotime( $next['date'] ) ) )
                                );
                                ?>
                                <?php if ( ! empty( $next['exception']->reason ) ) : ?>
                                    <?php echo esc_html( sprintf( __( ' Motif : %s', 'association-manager' ), $next['exception']->reason ) ); ?>
                                <?php endif; ?>
                            </li>
                        <?php else : ?>
                            <li>
                                <?php
                                printf(
                                    /* translators: 1: date. 2: horaire. 3: lieu de livraison. */
                                    esc_html__( 'Prochaine distribution : %1$s, %2$s (%3$s)', 'association-manager' ),
                                    esc_html( date_i18n( 'j F Y', strtotime( $next['date'] ) ) ),
                                    esc_html( amap_format_time( $next['start_time'] ) . '-' . amap_format_time( $next['end_time'] ) ),
                                    esc_html( $next['place'] )
                                );
                                ?>
                                <?php if ( 'moved' === $next['status'] && ! empty( $next['exception']->reason ) ) : ?>
                                    <?php echo esc_html( sprintf( __( ' Motif : %s', 'association-manager' ), $next['exception']->reason ) ); ?>
                                <?php endif; ?>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="amap-card">
    <h2><?php esc_html_e( 'Produits à livrer', 'association-manager' ); ?></h2>

    <?php if ( empty( $groups ) ) : ?>
        <p><?php esc_html_e( "Vous n'êtes pour l'instant rattaché à aucun groupe de distribution.", 'association-manager' ); ?></p>
    <?php else : ?>
        <ul class="amap-subscription-list">
            <?php foreach ( $groups as $group ) : ?>
                <?php $next = $group_next_distributions[ $group->id ]; ?>
                <li class="amap-subscription-item">
                    <div class="amap-subscription-item__header">
                        <span class="amap-subscription-item__label"><?php echo esc_html( $group->name ); ?></span>
                        <span class="amap-subscription-item__meta">
                            <?php echo esc_html( date_i18n( 'j F Y', strtotime( $next['date'] ) ) ); ?>
                        </span>
                    </div>
                    <?php if ( 'cancelled' === $next['status'] ) : ?>
                        <p><?php esc_html_e( 'Distribution annulée : rien à préparer.', 'association-manager' ); ?></p>
                    <?php else : ?>
                        <?php $deliveries = amap_get_group_deliveries( $group, $contracts, $next['original_date'] ); ?>
                        <?php if ( empty( $deliveries ) ) : ?>
                            <p><?php esc_html_e( 'Rien à livrer pour cette distribution.', 'association-manager' ); ?></p>
                        <?php else : ?>
                            <?php foreach ( $deliveries as $delivery ) : ?>
                                <div class="amap-delivery-contract-header">
                                    <p class="amap-delivery-contract-label"><?php echo esc_html( $delivery['contract']->label ); ?></p>
                                    <?php if ( 'basket_recurring' === $delivery['contract']->contract_type ) : ?>
                                        <a class="button-secondary" href="<?php echo esc_url( amap_get_contract_roster_export_url( $delivery['contract']->id, $group->id ) ); ?>">
                                            <?php esc_html_e( 'Détail (CSV)', 'association-manager' ); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <ul>
                                    <?php foreach ( $delivery['items'] as $item ) : ?>
                                        <li><?php echo esc_html( $item['label'] . ' × ' . $item['quantity'] ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
