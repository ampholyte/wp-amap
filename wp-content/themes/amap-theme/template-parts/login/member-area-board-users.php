<?php
/**
 * Section "Utilisateurs" de l'onglet "Espace bureau" : liste des comptes portant une casquette
 * AMAP (recherche, pagination), migrée depuis la page wp-admin "Utilisateurs AMAP"
 * (amap_render_users_page()) sans WP_List_Table (réservée à wp-admin). $args : voir
 * amap_get_board_users_list_data() (plugin, member-area.php).
 */
$available_roles = amap_get_available_roles();
?>
<div class="amap-stack">
    <div class="amap-list-toolbar">
        <h2><?php esc_html_e( 'Utilisateurs', 'association-manager' ); ?></h2>
        <a class="button-primary" href="<?php echo esc_url( amap_get_board_user_add_url() ); ?>">
            <?php esc_html_e( '+ Ajouter un utilisateur', 'association-manager' ); ?>
        </a>
    </div>

    <?php if ( 'created' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Utilisateur ajouté.', 'association-manager' ); ?></div>
    <?php elseif ( 'reused' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Compte WordPress existant réutilisé : rôle(s) et coordonnées mis à jour.', 'association-manager' ); ?></div>
    <?php elseif ( 'deleted' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Utilisateur supprimé.', 'association-manager' ); ?></div>
    <?php elseif ( 'magic_link_sent' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Lien de connexion envoyé.', 'association-manager' ); ?></div>
    <?php elseif ( 'magic_link_failed' === $args['notice'] ) : ?>
        <div class="amap-notice amap-notice--error"><?php esc_html_e( "Échec de l'envoi du lien de connexion.", 'association-manager' ); ?></div>
    <?php endif; ?>

    <form method="get" class="amap-search-bar">
        <input type="hidden" name="amap_tab" value="board">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-search"></use></svg>
        <input type="search" name="amap_board_search" placeholder="<?php esc_attr_e( 'Rechercher un nom, un email…', 'association-manager' ); ?>" value="<?php echo esc_attr( $args['search'] ); ?>">
    </form>

    <?php if ( empty( $args['users'] ) ) : ?>
        <p><?php esc_html_e( 'Aucun utilisateur AMAP trouvé.', 'association-manager' ); ?></p>
    <?php else : ?>
        <div class="amap-admin-list">
            <?php foreach ( $args['users'] as $board_user ) : ?>
                <?php
                $is_admin_account = in_array( 'administrator', $board_user->roles, true );
                $is_member_role   = in_array( 'amap_member', $board_user->roles, true );
                $member_group     = $is_member_role ? amap_get_member_group( $board_user->ID ) : null;

                // Téléphone/adresse retirés de cette ligne pour l'alléger (visibles dans le
                // formulaire "Modifier" si besoin) : seul l'email reste, plus léger à parcourir sur
                // une liste d'une centaine d'utilisateurs.
                $meta_parts = array( $board_user->user_email );
                if ( $is_member_role ) {
                    $meta_parts[] = sprintf(
                        /* translators: %s: nom du groupe (point de retrait), ou "aucun pour l'instant". */
                        __( 'Point de retrait : %s', 'association-manager' ),
                        $member_group ? $member_group->name : __( "aucun pour l'instant", 'association-manager' )
                    );
                }
                ?>
                <div class="amap-admin-row<?php echo $is_admin_account ? ' amap-admin-row--restricted' : ''; ?>">
                    <div class="amap-admin-row__top">
                        <div class="amap-admin-row__id">
                            <span class="amap-admin-row__name"><?php echo esc_html( trim( $board_user->last_name . ' ' . $board_user->first_name ) ); ?></span>
                            <?php if ( $is_admin_account ) : ?>
                                <span class="amap-role-badge amap-role-badge--admin"><?php esc_html_e( 'Administrateur', 'association-manager' ); ?></span>
                            <?php endif; ?>
                            <?php foreach ( $board_user->roles as $role_slug ) : ?>
                                <?php if ( isset( $available_roles[ $role_slug ] ) ) : ?>
                                    <span class="amap-role-badge amap-role-badge--<?php echo esc_attr( str_replace( 'amap_', '', $role_slug ) ); ?>">
                                        <?php echo esc_html( $available_roles[ $role_slug ] ); ?>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if ( ! $is_admin_account ) : ?>
                            <div class="amap-admin-row__actions">
                                <a href="<?php echo esc_url( amap_get_board_user_edit_url( $board_user->ID ) ); ?>">
                                    <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                </a>
                                <?php if ( $is_member_role ) : ?>
                                    <a href="<?php echo esc_url( amap_get_board_subscription_add_url( $board_user->ID ) ); ?>">
                                        <?php esc_html_e( 'Ajouter une souscription', 'association-manager' ); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ( in_array( 'amap_producer', $board_user->roles, true ) ) : ?>
                                    <a href="<?php echo esc_url( amap_get_board_producer_profile_url( $board_user->ID ) ); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e( 'Voir la fiche', 'association-manager' ); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ( amap_user_uses_magic_link( $board_user ) ) : ?>
                                    <?php
                                    $magic_link_url = wp_nonce_url(
                                        add_query_arg(
                                            array(
                                                'action'      => 'amap_send_magic_link',
                                                'id'          => $board_user->ID,
                                                'redirect_to' => rawurlencode( amap_get_board_users_url() ),
                                            ),
                                            admin_url( 'admin-post.php' )
                                        ),
                                        'amap_send_magic_link_' . $board_user->ID
                                    );
                                    ?>
                                    <a href="<?php echo esc_url( $magic_link_url ); ?>">
                                        <?php esc_html_e( 'Envoyer un lien de connexion', 'association-manager' ); ?>
                                    </a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url( amap_get_board_user_delete_url( $board_user->ID ) ); ?>" class="is-danger">
                                    <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="amap-admin-row__meta"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ( $args['total_pages'] > 1 ) : ?>
            <div class="amap-pagination">
                <span>
                    <?php
                    printf(
                        /* translators: 1: nombre total d'utilisateurs. 2: page actuelle. 3: nombre total de pages. */
                        esc_html__( '%1$d éléments · page %2$d sur %3$d', 'association-manager' ),
                        $args['total'],
                        $args['current_page'],
                        $args['total_pages']
                    );
                    ?>
                </span>
                <div class="amap-pagination__nav">
                    <?php if ( $args['current_page'] > 1 ) : ?>
                        <a class="amap-pagination__btn" href="<?php echo esc_url( add_query_arg( 'amap_board_page', $args['current_page'] - 1 ) ); ?>" aria-label="<?php esc_attr_e( 'Page précédente', 'association-manager' ); ?>">
                            <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
                        </a>
                    <?php else : ?>
                        <span class="amap-pagination__btn is-disabled" aria-hidden="true">
                            <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
                        </span>
                    <?php endif; ?>
                    <?php if ( $args['current_page'] < $args['total_pages'] ) : ?>
                        <a class="amap-pagination__btn amap-pagination__btn--next" href="<?php echo esc_url( add_query_arg( 'amap_board_page', $args['current_page'] + 1 ) ); ?>" aria-label="<?php esc_attr_e( 'Page suivante', 'association-manager' ); ?>">
                            <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
                        </a>
                    <?php else : ?>
                        <span class="amap-pagination__btn amap-pagination__btn--next is-disabled" aria-hidden="true">
                            <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
