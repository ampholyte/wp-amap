<?php
/**
 * Onglet "Espace adhérent" : souscriptions de l'adhérent connecté (étape 7.1, lecture seule) et
 * contrats ouverts à la souscription (étape 7.2/7.3, avec le formulaire de souscription —
 * member-area-subscribe.php).
 */
$subscriptions       = amap_get_member_subscriptions( $args['current_user']->ID );
$member_group        = amap_get_member_group( $args['current_user']->ID );
$available_contracts = $member_group ? amap_get_available_contracts_for_member( $args['current_user']->ID ) : array();
$status_labels       = amap_get_contract_period_status_labels();
$contract_types      = amap_get_contract_types();
?>

<?php if ( $member_group ) : ?>
    <p class="description">
        <?php
        printf(
            /* translators: %s: nom du groupe (point de retrait) de l'adhérent */
            esc_html__( 'Votre point de retrait : %s', 'association-manager' ),
            esc_html( $member_group->name )
        );
        ?>
    </p>
<?php endif; ?>

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

<div class="amap-card">
    <h2><?php esc_html_e( 'Contrats disponibles', 'association-manager' ); ?></h2>

    <?php if ( ! $member_group ) : ?>
        <p><?php esc_html_e( "Vous n'êtes rattaché à aucun groupe de distribution pour l'instant. Contactez le bureau pour vous voir proposer des contrats.", 'association-manager' ); ?></p>
    <?php elseif ( empty( $available_contracts ) ) : ?>
        <p><?php esc_html_e( "Aucun contrat n'est actuellement ouvert à la souscription pour votre groupe.", 'association-manager' ); ?></p>
    <?php else : ?>
        <ul class="amap-subscription-list">
            <?php foreach ( $available_contracts as $item ) : ?>
                <li class="amap-subscription-item">
                    <div class="amap-subscription-item__header">
                        <span class="amap-subscription-item__label"><?php echo esc_html( $item['contract']->label ); ?></span>
                    </div>
                    <ul>
                        <li><?php esc_html_e( 'Producteur', 'association-manager' ); ?> : <?php echo esc_html( $item['producer'] ? $item['producer']->display_name : '—' ); ?></li>
                        <li><?php esc_html_e( 'Type', 'association-manager' ); ?> : <?php echo esc_html( $contract_types[ $item['contract']->contract_type ] ?? '—' ); ?></li>
                        <li><?php esc_html_e( 'Période', 'association-manager' ); ?> : <?php echo esc_html( $item['contract']->start_date . ' – ' . $item['contract']->end_date ); ?></li>
                    </ul>
                    <p>
                        <a class="button-primary" href="<?php echo esc_url( amap_get_member_subscribe_url( $item['contract']->id ) ); ?>">
                            <?php esc_html_e( 'Souscrire', 'association-manager' ); ?>
                        </a>
                    </p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
