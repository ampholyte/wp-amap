<?php
/**
 * Page de confirmation de suppression d'un utilisateur AMAP, section "Utilisateurs" de l'espace
 * bureau — page dédiée plutôt qu'un simple lien confirm() JS (voir
 * project_espace_bureau_design_consolide), avec un état "bloqué" quand la suppression n'est pas
 * possible (compte administrateur, producteur avec contrats, adhérent avec souscriptions) : le
 * motif est affiché ici, avant de cliquer, plutôt que découvert après coup via un wp_die().
 * $args : voir amap_get_board_user_delete_data() (plugin, member-area.php). La suppression
 * elle-même reste gérée par amap_handle_delete_user(), déjà utilisée par wp-admin.
 */
$user = $args['user'];
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( amap_get_board_users_url() ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php esc_html_e( 'Retour à la liste', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title">
        <?php
        printf(
            /* translators: 1: prénom. 2: nom. */
            esc_html__( 'Supprimer %1$s %2$s ?', 'association-manager' ),
            esc_html( $user->first_name ),
            esc_html( $user->last_name )
        );
        ?>
    </h1>
</div>

<?php if ( $args['blocked_reason'] ) : ?>
    <div class="amap-danger-card amap-danger-card--blocked">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-info"></use></svg>
        <div>
            <p class="amap-danger-card__title"><?php esc_html_e( 'Suppression impossible', 'association-manager' ); ?></p>
            <p><?php echo esc_html( $args['blocked_reason'] ); ?></p>
        </div>
    </div>

    <div class="amap-form-actions">
        <a class="button-secondary" href="<?php echo esc_url( amap_get_board_users_url() ); ?>"><?php esc_html_e( 'Retour à la liste', 'association-manager' ); ?></a>
    </div>
<?php else : ?>
    <div class="amap-danger-card">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-info"></use></svg>
        <div>
            <p class="amap-danger-card__title"><?php esc_html_e( 'Cette action est irréversible', 'association-manager' ); ?></p>
            <p>
                <?php
                printf(
                    /* translators: 1: prénom. 2: nom. */
                    esc_html__( 'Supprime définitivement le compte WordPress de %1$s %2$s, y compris son historique de connexion.', 'association-manager' ),
                    esc_html( $user->first_name ),
                    esc_html( $user->last_name )
                );
                ?>
            </p>
        </div>
    </div>

    <?php
    $delete_url = wp_nonce_url(
        add_query_arg(
            array(
                'action'      => 'amap_delete_user',
                'id'          => $user->ID,
                'redirect_to' => rawurlencode( amap_get_board_users_url() ),
            ),
            admin_url( 'admin-post.php' )
        ),
        'amap_delete_user_' . $user->ID
    );
    ?>
    <div class="amap-form-actions">
        <a class="button-danger" href="<?php echo esc_url( $delete_url ); ?>"><?php esc_html_e( 'Supprimer définitivement', 'association-manager' ); ?></a>
        <a class="button-secondary" href="<?php echo esc_url( amap_get_board_users_url() ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
    </div>
<?php endif; ?>
