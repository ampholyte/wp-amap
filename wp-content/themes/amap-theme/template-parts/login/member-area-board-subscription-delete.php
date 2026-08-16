<?php
/**
 * Page de confirmation de suppression d'une souscription, section "Souscriptions" de l'espace
 * bureau — page dédiée plutôt qu'un simple lien confirm() JS (voir
 * project_espace_bureau_design_consolide), sans état "bloqué" : aucune règle métier n'empêche la
 * suppression d'une souscription (contrairement à Utilisateurs). $args : voir
 * amap_get_board_subscription_delete_data() (plugin, member-area.php). La suppression elle-même
 * reste gérée par amap_handle_delete_subscription(), déjà utilisée par wp-admin.
 */
$subscription = $args['subscription'];
$contract     = $args['contract'];
$member       = $args['member'];
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( amap_get_board_subscriptions_url() ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php esc_html_e( 'Retour à la liste', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title"><?php esc_html_e( 'Supprimer cette souscription ?', 'association-manager' ); ?></h1>
</div>

<div class="amap-danger-card">
    <svg class="icon" aria-hidden="true"><use href="#amap-icon-info"></use></svg>
    <div>
        <p class="amap-danger-card__title"><?php esc_html_e( 'Cette action est irréversible', 'association-manager' ); ?></p>
        <p>
            <?php
            printf(
                /* translators: 1: intitulé du contrat. 2: nom de l'adhérent. */
                esc_html__( 'Supprime définitivement la souscription « %1$s » de %2$s, ainsi que ses congés déclarés.', 'association-manager' ),
                esc_html( $contract ? $contract->label : '—' ),
                esc_html( $member ? $member->display_name : '—' )
            );
            ?>
        </p>
    </div>
</div>

<?php
$delete_url = wp_nonce_url(
    add_query_arg(
        array(
            'action'      => 'amap_delete_subscription',
            'id'          => $subscription->id,
            'redirect_to' => rawurlencode( amap_get_board_subscriptions_url() ),
        ),
        admin_url( 'admin-post.php' )
    ),
    'amap_delete_subscription_' . $subscription->id
);
?>
<div class="amap-form-actions">
    <a class="button-danger" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Supprimer définitivement', 'association-manager' ); ?></a>
    <a class="button-secondary" href="<?php echo esc_url( amap_get_board_subscriptions_url() ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
</div>
