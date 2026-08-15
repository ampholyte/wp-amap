<?php
/**
 * Onglet "Espace producteur" (lecture seule, voir docs/plan-contrats-distributions.md) : contrats
 * du producteur connecté, groupes qu'il livre, prochaine distribution de chacun et
 * produits/paniers à y livrer (étapes 12.1/12.2/12.3). "Mes prochaines livraisons" regroupe par
 * groupe (plutôt que trois listes séparées groupes/contrats/produits) car c'est le point de vue
 * qui compte pour le producteur : à quoi ressemble la prochaine distribution de CE groupe, tous
 * contrats confondus. Le bouton d'export CSV télécharge le détail nominatif des adhérents plutôt
 * que de l'afficher en page : "Feuille de présence" = pointage sur une fenêtre de 30 jours pour un
 * contrat basket_recurring (amap_handle_export_contract_roster()), "Commandes" = commandes de
 * cette seule distribution pour un contrat product_grid (amap_handle_export_contract_products()).
 */
$contracts             = amap_get_producer_contracts( $args['current_user']->ID );
$groups                = amap_get_producer_groups( $args['current_user']->ID );
$status_labels         = amap_get_contract_period_status_labels();
$contract_types        = amap_get_contract_types();
$weekday_labels        = amap_get_weekday_labels();
$exception_type_labels = amap_get_distribution_exception_type_labels();

// Calculés une seule fois par groupe, réutilisés par la carte de chaque groupe ci-dessous.
$group_next_distributions = array();
$group_member_counts      = array();
foreach ( $groups as $group ) {
    $group_next_distributions[ $group->id ] = amap_get_group_next_distribution( $group );
    $group_member_counts[ $group->id ]      = count( amap_get_group_member_users( $group->id ) );
}
?>

<div class="amap-stack">
<h2><?php esc_html_e( 'Mes prochaines livraisons', 'association-manager' ); ?></h2>

<?php if ( empty( $groups ) ) : ?>
    <p><?php esc_html_e( "Vous n'êtes pour l'instant rattaché à aucun groupe de distribution. Contactez le bureau.", 'association-manager' ); ?></p>
<?php else : ?>
    <div class="amap-context-cards">
    <?php foreach ( $groups as $group ) : ?>
        <?php $next = $group_next_distributions[ $group->id ]; ?>
        <div class="amap-context-card">
            <p class="amap-context-row">
                <svg class="icon" aria-hidden="true"><use href="#amap-icon-pin"></use></svg>
                <?php
                // Le lien Google Maps est sur le lieu de distribution lui-même ; pas d'horaire ici,
                // déjà donné juste en dessous par la ligne "Prochaine distribution" (même principe
                // que le bandeau de contexte côté adhérent, member-area-member.php).
                printf(
                    /* translators: 1: URL Google Maps de l'adresse. 2: nom du groupe. 3: jour de la semaine. Balises <a>/<strong> à conserver. */
                    __( '<a href="%1$s" target="_blank" rel="noopener noreferrer"><strong>%2$s</strong></a> — %3$s', 'association-manager' ),
                    esc_url( amap_get_google_maps_url( $group->delivery_place ) ),
                    esc_html( $group->name ),
                    esc_html( $weekday_labels[ (int) $group->weekday ] ?? '' )
                );
                ?>
            </p>

            <p>
                <span class="amap-badge">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d : nombre d'adhérents rattachés à ce groupe comme point de retrait. */
                            _n( '%d adhérent rattaché', '%d adhérents rattachés', $group_member_counts[ $group->id ], 'association-manager' ),
                            $group_member_counts[ $group->id ]
                        )
                    );
                    ?>
                </span>
            </p>

            <?php
            if ( 'normal' === $next['status'] ) {
                $days_until = (int) floor( ( strtotime( $next['date'] ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
                if ( 0 === $days_until ) {
                    $relative_day_label = __( "Aujourd'hui", 'association-manager' );
                } elseif ( 1 === $days_until ) {
                    $relative_day_label = __( 'Demain', 'association-manager' );
                } else {
                    $relative_day_label = sprintf(
                        /* translators: %d: nombre de jours avant la prochaine distribution. */
                        __( 'Dans %d jours', 'association-manager' ),
                        $days_until
                    );
                }
            }
            ?>
            <div class="amap-next-distribution amap-next-distribution--<?php echo esc_attr( $next['status'] ); ?>">
                <span class="amap-next-distribution__text">
                    <svg class="icon" aria-hidden="true"><use href="#amap-icon-calendar"></use></svg>
                    <?php if ( 'cancelled' === $next['status'] ) : ?>
                        <?php
                        printf(
                            /* translators: %s: date de la distribution annulée. Balise <strong> à conserver. */
                            __( 'Prochaine distribution : <strong>%s</strong> — annulée.', 'association-manager' ),
                            esc_html( date_i18n( 'j F Y', strtotime( $next['date'] ) ) )
                        );
                        ?>
                    <?php else : ?>
                        <?php
                        // Pas de lieu ici : déjà donné (en lien Google Maps) sur la ligne juste
                        // au-dessus.
                        printf(
                            /* translators: 1: date. 2: horaire. Balise <strong> à conserver. */
                            __( 'Prochaine distribution : <strong>%1$s</strong>, %2$s', 'association-manager' ),
                            esc_html( date_i18n( 'j F Y', strtotime( $next['date'] ) ) ),
                            esc_html( amap_format_time( $next['start_time'] ) . '–' . amap_format_time( $next['end_time'] ) )
                        );
                        ?>
                    <?php endif; ?>
                    <?php if ( 'normal' !== $next['status'] && ! empty( $next['exception']->reason ) ) : ?>
                        <?php echo esc_html( sprintf( __( '— Motif : %s', 'association-manager' ), $next['exception']->reason ) ); ?>
                    <?php endif; ?>
                </span>
                <?php if ( 'normal' === $next['status'] ) : ?>
                    <span class="amap-chip"><?php echo esc_html( $relative_day_label ); ?></span>
                <?php else : ?>
                    <span class="amap-status-badge amap-status-badge--<?php echo esc_attr( $next['status'] ); ?>">
                        <?php echo esc_html( $exception_type_labels[ $next['status'] ] ); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ( 'cancelled' === $next['status'] ) : ?>
                <p class="amap-contract-card__facts"><?php esc_html_e( 'Distribution annulée : rien à préparer.', 'association-manager' ); ?></p>
            <?php else : ?>
                <?php $deliveries = amap_get_group_deliveries( $group, $contracts, $next['original_date'] ); ?>
                <?php if ( empty( $deliveries ) ) : ?>
                    <p class="amap-contract-card__facts"><?php esc_html_e( 'Rien à livrer pour cette distribution.', 'association-manager' ); ?></p>
                <?php else : ?>
                    <div class="amap-group-deliveries">
                        <?php foreach ( $deliveries as $delivery ) : ?>
                            <?php
                            $is_basket        = ( 'basket_recurring' === $delivery['contract']->contract_type );
                            $producer_actions = array(
                                'export_url'   => $is_basket
                                    ? amap_get_contract_roster_export_url( $delivery['contract']->id, $group->id )
                                    : amap_get_contract_products_export_url( $delivery['contract']->id, $group->id, $next['original_date'] ),
                                'export_label' => $is_basket
                                    ? __( 'Feuille de présence (CSV)', 'association-manager' )
                                    : __( 'Commandes (CSV)', 'association-manager' ),
                                'basket_total' => $is_basket ? array_sum( wp_list_pluck( $delivery['items'], 'quantity' ) ) : null,
                            );
                            ?>
                            <?php get_template_part( 'template-parts/login/member-area-delivery-card', null, array( 'delivery' => $delivery, 'producer_actions' => $producer_actions ) ); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<div class="amap-stack">
<h2><?php esc_html_e( 'Mes contrats', 'association-manager' ); ?></h2>

<?php if ( empty( $contracts ) ) : ?>
    <p><?php esc_html_e( "Vous n'avez pour l'instant aucun contrat.", 'association-manager' ); ?></p>
<?php else : ?>
    <?php
    // Regroupement par pertinence plutôt que liste plate, même principe que "Mes contrats" côté
    // adhérent : ce qui est en cours d'abord, ce qui arrive ensuite, l'historique replié en
    // dernier.
    $active_contracts   = array();
    $upcoming_contracts = array();
    $ended_contracts    = array();
    foreach ( $contracts as $contract ) {
        $status = amap_get_contract_period_status( $contract );
        if ( 'upcoming' === $status ) {
            $upcoming_contracts[] = $contract;
        } elseif ( 'ended' === $status ) {
            $ended_contracts[] = $contract;
        } else {
            $active_contracts[] = $contract;
        }
    }
    ?>

    <?php if ( ! empty( $active_contracts ) ) : ?>
        <h3 class="amap-section-title">
            <?php esc_html_e( 'En cours', 'association-manager' ); ?>
            <span class="amap-section-count"><?php echo (int) count( $active_contracts ); ?></span>
        </h3>
        <ul class="amap-contract-list">
            <?php foreach ( $active_contracts as $contract ) : ?>
                <?php get_template_part( 'template-parts/login/member-area-producer-contract-item', null, array( 'contract' => $contract, 'status' => 'active', 'status_labels' => $status_labels, 'contract_types' => $contract_types ) ); ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ( ! empty( $upcoming_contracts ) ) : ?>
        <h3 class="amap-section-title">
            <?php esc_html_e( 'À venir', 'association-manager' ); ?>
            <span class="amap-section-count"><?php echo (int) count( $upcoming_contracts ); ?></span>
        </h3>
        <ul class="amap-contract-list">
            <?php foreach ( $upcoming_contracts as $contract ) : ?>
                <?php get_template_part( 'template-parts/login/member-area-producer-contract-item', null, array( 'contract' => $contract, 'status' => 'upcoming', 'status_labels' => $status_labels, 'contract_types' => $contract_types ) ); ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ( ! empty( $ended_contracts ) ) : ?>
        <details class="amap-ended-group">
            <summary>
                <?php
                printf(
                    /* translators: %d: nombre de contrats terminés. */
                    esc_html__( 'Contrats terminés (%d)', 'association-manager' ),
                    count( $ended_contracts )
                );
                ?>
            </summary>
            <ul class="amap-contract-list">
                <?php foreach ( $ended_contracts as $contract ) : ?>
                    <?php get_template_part( 'template-parts/login/member-area-producer-contract-item', null, array( 'contract' => $contract, 'status' => 'ended', 'status_labels' => $status_labels, 'contract_types' => $contract_types ) ); ?>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
<?php endif; ?>
</div>
