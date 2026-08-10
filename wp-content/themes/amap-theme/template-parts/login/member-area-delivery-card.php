<?php
/**
 * Une carte "livraison" (icône de type, libellé de contrat, liste d'items) pour une distribution
 * donnée, extraite en template part car reprise à l'identique par member-area-member.php (espace
 * adhérent, lecture seule) et member-area-producer.php (espace producteur, avec en plus le badge
 * de comptage panier et le bouton d'export CSV — voir $args['producer_actions']).
 * $args : delivery (une entrée de amap_get_member_deliveries()/amap_get_group_deliveries(), sous
 * la forme ['contract' => ..., 'items' => [{label, quantity}]]), producer_actions (optionnel,
 * absent côté adhérent : ['export_url', 'export_label', 'basket_total']).
 */
$delivery         = $args['delivery'];
$is_basket        = ( 'basket_recurring' === $delivery['contract']->contract_type );
$type_icon        = $is_basket ? 'amap-icon-basket' : 'amap-icon-grid';
$type_class       = $is_basket ? 'amap-type-icon--basket' : 'amap-type-icon--grid';
$producer_actions = $args['producer_actions'] ?? null;
?>
<div class="amap-group-delivery">
    <div class="amap-delivery-contract-header">
        <p class="amap-delivery-contract-label">
            <span class="amap-contract-card__type <?php echo esc_attr( $type_class ); ?>">
                <svg class="icon" aria-hidden="true"><use href="#<?php echo esc_attr( $type_icon ); ?>"></use></svg>
            </span>
            <?php echo esc_html( $delivery['contract']->label ); ?>
            <?php if ( $producer_actions && $is_basket ) : ?>
                <span class="amap-badge">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d : nombre total de paniers à livrer pour ce contrat sur ce groupe. */
                            _n( '%d panier', '%d paniers', $producer_actions['basket_total'], 'association-manager' ),
                            $producer_actions['basket_total']
                        )
                    );
                    ?>
                </span>
            <?php endif; ?>
        </p>
        <?php if ( $producer_actions ) : ?>
            <a class="button-secondary" href="<?php echo esc_url( $producer_actions['export_url'] ); ?>">
                <?php echo esc_html( $producer_actions['export_label'] ); ?>
            </a>
        <?php endif; ?>
    </div>
    <ul class="amap-delivery-items">
        <?php foreach ( $delivery['items'] as $item ) : ?>
            <li><span><?php echo esc_html( $item['label'] ); ?></span><strong>× <?php echo esc_html( $item['quantity'] ); ?></strong></li>
        <?php endforeach; ?>
    </ul>
</div>
