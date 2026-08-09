<?php
/**
 * Une carte de la liste "Mes contrats" de l'espace producteur (member-area-producer.php), extraite
 * en template part pour être réutilisée par les groupes "En cours" / "À venir" / "Terminés" sans
 * tripler le balisage — même principe que member-area-subscription-item.php côté adhérent.
 * $args : contract (une ligne de amap_get_producer_contracts()), status (calculé par l'appelant
 * via amap_get_contract_period_status() pour ne pas le recalculer trois fois), status_labels,
 * contract_types. Pas de producteur à afficher ici (c'est le contrat de l'utilisateur connecté) :
 * .amap-contract-card__producer sert donc de simple emplacement pour le texte en gras, réutilisé
 * pour le libellé du contrat plutôt que pour un nom de producteur. Les symboles SVG référencés
 * (#amap-icon-*) sont définis dans la coquille commune member-area.php.
 */
$contract      = $args['contract'];
$status        = $args['status'];
$is_basket     = ( 'basket_recurring' === $contract->contract_type );
$type_modifier = $is_basket ? 'basket' : 'grid';
$type_icon     = $is_basket ? 'amap-icon-basket' : 'amap-icon-grid';
$type_label    = $args['contract_types'][ $contract->contract_type ] ?? '';
?>
<li class="amap-contract-card amap-contract-card--<?php echo esc_attr( $type_modifier ); ?>">
    <div class="amap-contract-card__top">
        <span class="amap-contract-card__type">
            <svg class="icon" aria-hidden="true"><use href="#<?php echo esc_attr( $type_icon ); ?>"></use></svg>
            <span class="sr-only"><?php echo esc_html( $type_label ); ?></span>
        </span>
        <div class="amap-contract-card__body">
            <div class="amap-contract-card__heading">
                <div>
                    <div class="amap-contract-card__producer"><?php echo esc_html( $contract->label ); ?></div>
                    <div class="amap-contract-card__label"><?php echo esc_html( $type_label ); ?></div>
                </div>
                <span class="amap-status-badge amap-status-badge--<?php echo esc_attr( $status ); ?>">
                    <?php echo esc_html( $args['status_labels'][ $status ] ); ?>
                </span>
            </div>
            <p class="amap-contract-card__facts">
                <?php
                printf(
                    /* translators: 1: date de début du contrat. 2: date de fin du contrat. */
                    esc_html__( 'Période : %1$s – %2$s', 'association-manager' ),
                    esc_html( date_i18n( 'j F Y', strtotime( $contract->start_date ) ) ),
                    esc_html( date_i18n( 'j F Y', strtotime( $contract->end_date ) ) )
                );
                ?>
            </p>
        </div>
    </div>
</li>
