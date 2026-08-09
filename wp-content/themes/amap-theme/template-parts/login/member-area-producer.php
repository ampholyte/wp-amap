<?php
/**
 * Onglet "Espace producteur" (étape 12.1, lecture seule) : contrats du producteur connecté et
 * groupes qu'il livre. La prochaine distribution, les produits à livrer et les adhérents par
 * groupe viendront dans une étape ultérieure (voir docs/plan-contrats-distributions.md).
 */
$contracts      = amap_get_producer_contracts( $args['current_user']->ID );
$groups         = amap_get_producer_groups( $args['current_user']->ID );
$status_labels  = amap_get_contract_period_status_labels();
$contract_types = amap_get_contract_types();
$weekday_labels = amap_get_weekday_labels();
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
        <ul>
            <?php foreach ( $groups as $group ) : ?>
                <li>
                    <?php
                    printf(
                        /* translators: 1: nom du groupe. 2: jour de la semaine. 3: horaire. 4: lieu de livraison. */
                        esc_html__( '%1$s — %2$s %3$s (%4$s)', 'association-manager' ),
                        esc_html( $group->name ),
                        esc_html( $weekday_labels[ (int) $group->weekday ] ?? '' ),
                        esc_html( amap_format_time( $group->start_time ) . '-' . amap_format_time( $group->end_time ) ),
                        esc_html( $group->delivery_place )
                    );
                    ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
