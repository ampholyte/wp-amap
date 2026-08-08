<?php
/**
 * Onglet "Espace adhérent" : souscriptions de l'adhérent connecté (étape 7.1 — lecture seule ;
 * la souscription en ligne à un nouveau contrat viendra dans une étape ultérieure, voir
 * docs/plan-contrats-distributions.md).
 */
$subscriptions  = amap_get_member_subscriptions( $args['current_user']->ID );
$status_labels  = amap_get_contract_period_status_labels();
$contract_types = amap_get_contract_types();
?>
<div class="amap-card">
    <h2><?php esc_html_e( 'Mes contrats', 'association-manager' ); ?></h2>

    <?php if ( empty( $subscriptions ) ) : ?>
        <p><?php esc_html_e( "Vous n'avez pour l'instant souscrit à aucun contrat.", 'association-manager' ); ?></p>
    <?php else : ?>
        <ul class="amap-subscription-list">
            <?php foreach ( $subscriptions as $item ) : ?>
                <li class="amap-subscription-item">
                    <div class="amap-subscription-item__header">
                        <span class="amap-subscription-item__label"><?php echo esc_html( $item['contract']->label ); ?></span>
                        <span class="amap-status-badge amap-status-badge--<?php echo esc_attr( $item['status'] ); ?>">
                            <?php echo esc_html( $status_labels[ $item['status'] ] ); ?>
                        </span>
                    </div>
                    <ul>
                        <li><?php esc_html_e( 'Producteur', 'association-manager' ); ?> : <?php echo esc_html( $item['producer'] ? $item['producer']->display_name : '—' ); ?></li>
                        <li><?php esc_html_e( 'Type', 'association-manager' ); ?> : <?php echo esc_html( $contract_types[ $item['contract']->contract_type ] ?? '—' ); ?></li>
                        <li><?php esc_html_e( 'Groupe (point de retrait)', 'association-manager' ); ?> : <?php echo esc_html( $item['group'] ? $item['group']->name : '—' ); ?></li>
                        <?php if ( $item['basket_size'] ) : ?>
                            <li><?php esc_html_e( 'Taille de panier', 'association-manager' ); ?> : <?php echo esc_html( $item['basket_size']->label ); ?></li>
                        <?php endif; ?>
                        <li><?php esc_html_e( 'Signé le', 'association-manager' ); ?> : <?php echo esc_html( $item['subscription']->signed_at ); ?></li>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
