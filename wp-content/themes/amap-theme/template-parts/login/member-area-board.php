<?php
/**
 * Onglet "Espace bureau" : sous-navigation vers les 4 sections du CRUD bureau, migrées
 * progressivement depuis wp-admin (voir project_espace_bureau_admin_map en mémoire de session).
 * Les 4 sections ("Utilisateurs", "Groupes", "Contrats", "Souscriptions") ont maintenant du
 * contenu réel.
 * $args['board_section'] : voir amap_maybe_render_member_area() (plugin), toujours 'users',
 * 'groups', 'contracts' ou 'subscriptions' (revalidé côté plugin avant d'arriver ici).
 */
$board_section = $args['board_section'];
?>
<nav class="amap-subnav" aria-label="<?php esc_attr_e( 'Sections du bureau', 'association-manager' ); ?>">
    <a class="amap-subnav-item<?php echo ( 'users' === $board_section ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( amap_get_board_users_url() ); ?>">
        <?php esc_html_e( 'Utilisateurs', 'association-manager' ); ?>
    </a>
    <a class="amap-subnav-item<?php echo ( 'groups' === $board_section ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( amap_get_board_groups_url() ); ?>">
        <?php esc_html_e( 'Groupes', 'association-manager' ); ?>
    </a>
    <a class="amap-subnav-item<?php echo ( 'contracts' === $board_section ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( amap_get_board_contracts_url() ); ?>">
        <?php esc_html_e( 'Contrats', 'association-manager' ); ?>
    </a>
    <a class="amap-subnav-item<?php echo ( 'subscriptions' === $board_section ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( amap_get_board_subscriptions_url() ); ?>">
        <?php esc_html_e( 'Souscriptions', 'association-manager' ); ?>
    </a>
</nav>

<?php
// Rappel "contrats arrivant à échéance", ancien admin_notices de wp-admin
// (amap_render_contracts_ending_soon_notice(), supprimé avec le reste de l'admin AMAP) — reporté
// ici pour rester visible quelle que soit la section du bureau consultée, pas seulement
// "Contrats", comme c'était déjà le cas côté wp-admin (affiché sur ses 4 pages).
$contracts_ending_soon = current_user_can( 'amap_manage_contracts' )
    ? amap_get_contracts_ending_soon( amap_get_contract_renewal_reminder_days() )
    : array();
?>
<?php if ( $contracts_ending_soon ) : ?>
    <div class="amap-notice amap-notice--warning">
        <p class="amap-notice__title"><?php esc_html_e( 'Contrats producteur arrivant à échéance', 'association-manager' ); ?></p>
        <ul class="amap-notice__list">
            <?php foreach ( $contracts_ending_soon as $ending_contract ) : ?>
                <?php $ending_producer = get_user_by( 'id', $ending_contract->producer_user_id ); ?>
                <li>
                    <a href="<?php echo esc_url( amap_get_board_contract_view_url( $ending_contract->id ) ); ?>"><?php echo esc_html( $ending_contract->label ); ?></a>
                    <?php
                    printf(
                        /* translators: 1: nom du producteur. 2: date de fin. */
                        esc_html__( ' (%1$s) — se termine le %2$s', 'association-manager' ),
                        esc_html( $ending_producer ? $ending_producer->display_name : '—' ),
                        esc_html( date_i18n( 'j F Y', strtotime( $ending_contract->end_date ) ) )
                    );
                    ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ( 'subscriptions' === $board_section ) : ?>
    <?php get_template_part( 'template-parts/login/member-area-board-subscriptions', null, amap_get_board_subscriptions_list_data() ); ?>
<?php elseif ( 'groups' === $board_section ) : ?>
    <?php get_template_part( 'template-parts/login/member-area-board-groups', null, amap_get_board_groups_list_data() ); ?>
<?php elseif ( 'contracts' === $board_section ) : ?>
    <?php get_template_part( 'template-parts/login/member-area-board-contracts', null, amap_get_board_contracts_list_data() ); ?>
<?php else : ?>
    <?php get_template_part( 'template-parts/login/member-area-board-users', null, amap_get_board_users_list_data() ); ?>
<?php endif; ?>
