<?php
/**
 * Fiche producteur en lecture seule, atteinte depuis un lien "Voir la fiche" sur une ligne
 * producteur (section "Utilisateurs" ou "Producteurs rattachés" d'une fiche groupe) — reprise de
 * amap_render_producer_profile_page() côté wp-admin. Page 100% lecture seule : aucune action de
 * modification ici, chaque ligne des sections "Groupes rattachés"/"Contrats" est un lien entier
 * vers la vraie fiche (groupe/contrat), où ces informations se gèrent réellement. $args : voir
 * amap_get_board_producer_profile_data() (plugin, member-area.php).
 *
 * Sous-page en dehors de la coquille à onglets (atteinte directement par
 * amap_maybe_render_member_area(), pas via member-area.php) : elle inclut donc elle-même les
 * symboles SVG (#amap-icon-*), comme member-profile-edit.php.
 */
$producer       = $args['producer'];
$contact        = $args['contact'];
$weekday_labels = amap_get_weekday_labels();
$contract_types = amap_get_contract_types();
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( amap_get_board_users_url() ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php esc_html_e( 'Retour à Utilisateurs', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title"><?php echo esc_html( $producer->display_name ); ?></h1>
    <span class="amap-role-badge amap-role-badge--producer"><?php esc_html_e( 'Producteur', 'association-manager' ); ?></span>
</div>

<div class="amap-info-card">
    <h3 class="amap-info-card__title"><?php esc_html_e( 'Coordonnées', 'association-manager' ); ?></h3>
    <dl class="amap-info-list">
        <div>
            <dt><?php esc_html_e( 'Nom', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $producer->display_name ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Email', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $producer->user_email ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Téléphone', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $contact->phone ?? '—' ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Adresse', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $contact->address ?? '—' ); ?></dd>
        </div>
    </dl>
</div>

<details class="amap-disclosure" open>
    <summary>
        <?php esc_html_e( 'Groupes de livraison rattachés', 'association-manager' ); ?>
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
    </summary>
    <div class="amap-disclosure__body">
        <?php if ( empty( $args['groups'] ) ) : ?>
            <p class="amap-disclosure__hint"><?php esc_html_e( "Ce producteur n'est rattaché à aucun groupe de distribution.", 'association-manager' ); ?></p>
        <?php else : ?>
            <div class="amap-mini-list">
                <?php foreach ( $args['groups'] as $group ) : ?>
                    <a class="amap-mini-row amap-mini-row--link" href="<?php echo esc_url( amap_get_board_group_view_url( $group->id ) ); ?>">
                        <span class="amap-mini-row__label"><?php echo esc_html( $group->name ); ?></span>
                        <span class="amap-mini-row__value">
                            <?php
                            echo esc_html(
                                ( $weekday_labels[ (int) $group->weekday ] ?? '' ) . ' · '
                                . amap_format_time( $group->start_time ) . '–' . amap_format_time( $group->end_time )
                            );
                            ?>
                        </span>
                        <svg class="icon amap-mini-row__chevron" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</details>

<details class="amap-disclosure" open>
    <summary>
        <?php esc_html_e( 'Contrats', 'association-manager' ); ?>
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
    </summary>
    <div class="amap-disclosure__body">
        <?php if ( empty( $args['contracts'] ) ) : ?>
            <p class="amap-disclosure__hint"><?php esc_html_e( "Ce producteur n'a aucun contrat.", 'association-manager' ); ?></p>
        <?php else : ?>
            <div class="amap-mini-list">
                <?php foreach ( $args['contracts'] as $contract ) : ?>
                    <a class="amap-mini-row amap-mini-row--link" href="<?php echo esc_url( amap_get_board_contract_view_url( $contract->id ) ); ?>">
                        <span class="amap-mini-row__label"><?php echo esc_html( $contract->label ); ?></span>
                        <span class="amap-mini-row__value">
                            <?php
                            echo esc_html(
                                ( $contract_types[ $contract->contract_type ] ?? $contract->contract_type ) . ' · '
                                . $contract->start_date . ' → ' . $contract->end_date
                            );
                            ?>
                        </span>
                        <span class="amap-status-pill amap-status-pill--<?php echo $contract->is_active ? 'active' : 'inactive'; ?>">
                            <?php echo $contract->is_active ? esc_html__( 'Actif', 'association-manager' ) : esc_html__( 'Inactif', 'association-manager' ); ?>
                        </span>
                        <svg class="icon amap-mini-row__chevron" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</details>
