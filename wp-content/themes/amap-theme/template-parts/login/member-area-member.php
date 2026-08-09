<?php
/**
 * Onglet "Espace adhérent" : souscriptions de l'adhérent connecté (étape 7.1, lecture seule) et
 * contrats ouverts à la souscription (étape 7.2/7.3, avec le formulaire de souscription —
 * member-area-subscribe.php).
 */
$subscriptions         = amap_get_member_subscriptions( $args['current_user']->ID );
$member_group          = amap_get_member_group( $args['current_user']->ID );
$available_contracts   = $member_group ? amap_get_available_contracts_for_member( $args['current_user']->ID ) : array();
$status_labels         = amap_get_contract_period_status_labels();
$contract_types        = amap_get_contract_types();
$weekday_labels        = amap_get_weekday_labels();
$exception_type_labels = amap_get_distribution_exception_type_labels();
$next_distribution     = $member_group ? amap_get_group_next_distribution( $member_group ) : null;
?>

<?php if ( $member_group ) : ?>
    <div class="amap-context-card">
        <p class="amap-context-row">
            <svg class="icon" aria-hidden="true"><use href="#amap-icon-pin"></use></svg>
            <?php
            printf(
                /* translators: 1: nom du groupe (point de retrait). 2: jour de la semaine. 3: horaire. 4: lieu de livraison. */
                esc_html__( 'Votre point de retrait : %1$s — %2$s, %3$s (%4$s)', 'association-manager' ),
                esc_html( $member_group->name ),
                esc_html( $weekday_labels[ (int) $member_group->weekday ] ?? '' ),
                esc_html( amap_format_time( $member_group->start_time ) . '–' . amap_format_time( $member_group->end_time ) ),
                esc_html( $member_group->delivery_place )
            );
            ?>
        </p>

        <?php if ( $next_distribution ) : ?>
            <?php
            // Repère "Aujourd'hui/Demain/Dans X jours" à droite du bandeau, seulement quand la
            // distribution a lieu normalement — sinon la place est déjà prise par le badge
            // d'exception (annulée/déplacée) ci-dessous.
            if ( 'normal' === $next_distribution['status'] ) {
                $days_until = (int) floor( ( strtotime( $next_distribution['date'] ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
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
            <div class="amap-next-distribution amap-next-distribution--<?php echo esc_attr( $next_distribution['status'] ); ?>">
                <span class="amap-next-distribution__text">
                    <svg class="icon" aria-hidden="true"><use href="#amap-icon-calendar"></use></svg>
                    <?php if ( 'cancelled' === $next_distribution['status'] ) : ?>
                        <?php
                        printf(
                            /* translators: %s: date de la distribution annulée. Balise <strong> à conserver. */
                            __( 'Prochaine distribution : <strong>%s</strong> — annulée.', 'association-manager' ),
                            esc_html( date_i18n( 'j F Y', strtotime( $next_distribution['date'] ) ) )
                        );
                        ?>
                    <?php else : ?>
                        <?php
                        printf(
                            /* translators: 1: date. 2: horaire. 3: lieu de livraison. Balise <strong> à conserver. */
                            __( 'Prochaine distribution : <strong>%1$s</strong>, %2$s (%3$s)', 'association-manager' ),
                            esc_html( date_i18n( 'j F Y', strtotime( $next_distribution['date'] ) ) ),
                            esc_html( amap_format_time( $next_distribution['start_time'] ) . '–' . amap_format_time( $next_distribution['end_time'] ) ),
                            esc_html( $next_distribution['place'] )
                        );
                        ?>
                    <?php endif; ?>
                    <?php if ( 'normal' !== $next_distribution['status'] && ! empty( $next_distribution['exception']->reason ) ) : ?>
                        <?php echo esc_html( sprintf( __( '— Motif : %s', 'association-manager' ), $next_distribution['exception']->reason ) ); ?>
                    <?php endif; ?>
                </span>
                <?php if ( 'normal' === $next_distribution['status'] ) : ?>
                    <span class="amap-chip"><?php echo esc_html( $relative_day_label ); ?></span>
                <?php else : ?>
                    <span class="amap-status-badge amap-status-badge--<?php echo esc_attr( $next_distribution['status'] ); ?>">
                        <?php echo esc_html( $exception_type_labels[ $next_distribution['status'] ] ); ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<h2><?php esc_html_e( 'Mes contrats', 'association-manager' ); ?></h2>

<?php if ( empty( $subscriptions ) ) : ?>
    <p><?php esc_html_e( "Vous n'avez pour l'instant souscrit à aucun contrat.", 'association-manager' ); ?></p>
<?php else : ?>
    <?php
    // Regroupement par pertinence plutôt que liste plate : ce qui se passe maintenant d'abord,
    // ce qui arrive ensuite, l'historique replié en dernier.
    $active_subscriptions   = array();
    $upcoming_subscriptions = array();
    $ended_subscriptions    = array();
    foreach ( $subscriptions as $item ) {
        if ( 'upcoming' === $item['status'] ) {
            $upcoming_subscriptions[] = $item;
        } elseif ( 'ended' === $item['status'] ) {
            $ended_subscriptions[] = $item;
        } else {
            $active_subscriptions[] = $item;
        }
    }
    ?>

    <?php if ( ! empty( $active_subscriptions ) ) : ?>
        <h3 class="amap-section-title">
            <?php esc_html_e( 'En cours', 'association-manager' ); ?>
            <span class="amap-section-count"><?php echo (int) count( $active_subscriptions ); ?></span>
        </h3>
        <ul class="amap-contract-list">
            <?php foreach ( $active_subscriptions as $item ) : ?>
                <?php get_template_part( 'template-parts/login/member-area-subscription-item', null, array( 'item' => $item, 'contract_types' => $contract_types, 'status_labels' => $status_labels ) ); ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ( ! empty( $upcoming_subscriptions ) ) : ?>
        <h3 class="amap-section-title">
            <?php esc_html_e( 'À venir', 'association-manager' ); ?>
            <span class="amap-section-count"><?php echo (int) count( $upcoming_subscriptions ); ?></span>
        </h3>
        <ul class="amap-contract-list">
            <?php foreach ( $upcoming_subscriptions as $item ) : ?>
                <?php get_template_part( 'template-parts/login/member-area-subscription-item', null, array( 'item' => $item, 'contract_types' => $contract_types, 'status_labels' => $status_labels ) ); ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ( ! empty( $ended_subscriptions ) ) : ?>
        <details class="amap-ended-group">
            <summary>
                <?php
                printf(
                    /* translators: %d: nombre de contrats terminés. */
                    esc_html__( 'Contrats terminés (%d)', 'association-manager' ),
                    count( $ended_subscriptions )
                );
                ?>
            </summary>
            <ul class="amap-contract-list">
                <?php foreach ( $ended_subscriptions as $item ) : ?>
                    <?php get_template_part( 'template-parts/login/member-area-subscription-item', null, array( 'item' => $item, 'contract_types' => $contract_types, 'status_labels' => $status_labels ) ); ?>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
<?php endif; ?>

<h2><?php esc_html_e( 'Contrats disponibles', 'association-manager' ); ?></h2>

<?php if ( ! $member_group ) : ?>
    <p><?php esc_html_e( "Vous n'êtes rattaché à aucun groupe de distribution pour l'instant. Contactez le bureau pour vous voir proposer des contrats.", 'association-manager' ); ?></p>
<?php elseif ( empty( $available_contracts ) ) : ?>
    <p><?php esc_html_e( "Aucun contrat n'est actuellement ouvert à la souscription pour votre groupe.", 'association-manager' ); ?></p>
<?php else : ?>
    <ul class="amap-available-list">
        <?php foreach ( $available_contracts as $item ) : ?>
            <li class="amap-available-item">
                <div class="amap-available-item__name"><?php echo esc_html( $item['contract']->label ); ?></div>
                <div class="amap-available-item__meta">
                    <?php
                    printf(
                        /* translators: 1: nom du producteur. 2: type de contrat. 3: date de début. 4: date de fin. */
                        esc_html__( '%1$s · %2$s · %3$s – %4$s', 'association-manager' ),
                        esc_html( $item['producer'] ? $item['producer']->display_name : '—' ),
                        esc_html( $contract_types[ $item['contract']->contract_type ] ?? '—' ),
                        esc_html( $item['contract']->start_date ),
                        esc_html( $item['contract']->end_date )
                    );
                    ?>
                </div>
                <p class="amap-actions">
                    <a class="button-secondary" href="<?php echo esc_url( amap_get_member_subscribe_url( $item['contract']->id ) ); ?>">
                        <?php esc_html_e( 'Souscrire', 'association-manager' ); ?>
                    </a>
                </p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
