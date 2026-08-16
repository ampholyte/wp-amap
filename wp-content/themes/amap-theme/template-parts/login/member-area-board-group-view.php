<?php
/**
 * Fiche d'un groupe (infos + les 3 sections nichées côté wp-admin), section "Groupes" de l'espace
 * bureau — page dédiée, reprise de la branche "édition" de amap_render_groups_page() côté
 * wp-admin. Les 3 sections (Producteurs rattachés / Exceptions de distribution / Bénévoles de
 * distribution) restent des accordéons <details> repliés par défaut, comme côté wp-admin — sauf
 * si un message ou une édition en cours les concerne (jamais masquer un message pertinent derrière
 * une section repliée). $args : voir amap_get_board_group_view_data() (plugin, member-area.php).
 *
 * Sous-page en dehors de la coquille à onglets (atteinte directement par
 * amap_maybe_render_member_area(), pas via member-area.php) : elle inclut donc elle-même les
 * symboles SVG (#amap-icon-*), comme member-profile-edit.php.
 */
$group                  = $args['group'];
$notice                 = $args['notice'];
$weekday_labels         = amap_get_weekday_labels();
$exception_type_labels  = amap_get_distribution_exception_type_labels();
$exception_editing_id   = $args['exception_editing_id'];
$exception_form_data    = $args['exception_form_data'];
$volunteer_form_data    = $args['volunteer_form_data'];
$view_url               = amap_get_board_group_view_url( $group->id );

$producers_open  = ( 'producers_updated' === $notice );
$exceptions_open = $exception_editing_id || ( 0 === strpos( (string) $notice, 'exception_' ) );
$volunteers_open = ( 0 === strpos( (string) $notice, 'volunteer_' ) );
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( amap_get_board_groups_url() ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php esc_html_e( 'Retour à la liste', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title"><?php echo esc_html( $group->name ); ?></h1>
</div>

<?php if ( 'updated' === $notice ) : ?>
    <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Groupe mis à jour.', 'association-manager' ); ?></div>
<?php endif; ?>

<div class="amap-info-card">
    <dl class="amap-info-list">
        <div>
            <dt><?php esc_html_e( 'Lieu de livraison', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $group->delivery_place ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Jour', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $weekday_labels[ (int) $group->weekday ] ?? '' ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Horaire', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( amap_format_time( $group->start_time ) . ' – ' . amap_format_time( $group->end_time ) ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Adresse de notification', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $group->notification_email ? $group->notification_email : '—' ); ?></dd>
        </div>
    </dl>
    <div class="amap-info-card__actions">
        <a class="button-primary" href="<?php echo esc_url( amap_get_board_group_edit_url( $group->id ) ); ?>">
            <?php esc_html_e( 'Modifier les infos', 'association-manager' ); ?>
        </a>
    </div>
</div>

<details class="amap-disclosure"<?php echo $producers_open ? ' open' : ''; ?>>
    <summary>
        <?php esc_html_e( 'Producteurs rattachés', 'association-manager' ); ?>
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
    </summary>
    <div class="amap-disclosure__body">
        <?php if ( 'producers_updated' === $notice ) : ?>
            <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Producteurs rattachés mis à jour.', 'association-manager' ); ?></div>
        <?php endif; ?>

        <?php if ( empty( $args['producers'] ) ) : ?>
            <p><?php esc_html_e( 'Aucun compte producteur pour le moment.', 'association-manager' ); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-checklist-form">
                <?php wp_nonce_field( 'amap_update_group_producers_' . $group->id ); ?>
                <input type="hidden" name="action" value="amap_update_group_producers">
                <input type="hidden" name="group_id" value="<?php echo esc_attr( $group->id ); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $view_url ); ?>">
                <?php foreach ( $args['producers'] as $producer ) : ?>
                    <label class="amap-checklist-item">
                        <span class="amap-checklist-item__main">
                            <input type="checkbox" name="producer_ids[]" value="<?php echo esc_attr( $producer->ID ); ?>" <?php checked( in_array( (string) $producer->ID, $args['attached_producer_ids'], true ) ); ?>>
                            <?php echo esc_html( $producer->display_name ); ?>
                        </span>
                        <a href="<?php echo esc_url( amap_get_board_producer_profile_url( $producer->ID ) ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Voir la fiche', 'association-manager' ); ?>
                        </a>
                    </label>
                <?php endforeach; ?>
                <div class="amap-form-actions">
                    <button type="submit" class="button-primary"><?php esc_html_e( 'Enregistrer les producteurs', 'association-manager' ); ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</details>

<details class="amap-disclosure"<?php echo $exceptions_open ? ' open' : ''; ?>>
    <summary>
        <?php esc_html_e( 'Exceptions de distribution', 'association-manager' ); ?>
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
    </summary>
    <div class="amap-disclosure__body">
        <p class="amap-disclosure__hint">
            <?php esc_html_e( "Annulation ou déplacement ponctuel d'une distribution, décidé par le bureau. Ne concerne qu'une date précise : la distribution normale du groupe n'est pas affectée les autres semaines.", 'association-manager' ); ?>
        </p>
        <?php if ( empty( $group->notification_email ) ) : ?>
            <div class="amap-notice amap-notice--warning">
                <?php esc_html_e( "Aucune adresse de notification configurée pour ce groupe (voir « Modifier les infos » ci-dessus) : les adhérents ne seront pas prévenus d'une exception de distribution.", 'association-manager' ); ?>
            </div>
        <?php endif; ?>

        <?php if ( 'exception_invalid' === $notice ) : ?>
            <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Champs obligatoires manquants ou invalides.', 'association-manager' ); ?></div>
        <?php elseif ( 'exception_invalid_weekday' === $notice ) : ?>
            <div class="amap-notice amap-notice--error"><?php esc_html_e( 'La date doit tomber sur le jour de semaine habituel de ce groupe.', 'association-manager' ); ?></div>
        <?php elseif ( 'exception_invalid_moved' === $notice ) : ?>
            <div class="amap-notice amap-notice--error"><?php esc_html_e( "Pour un déplacement, renseignez une nouvelle date, un nouvel horaire (les deux heures) ou un nouveau lieu, et une heure de fin après l'heure de début.", 'association-manager' ); ?></div>
        <?php elseif ( 'exception_duplicate' === $notice ) : ?>
            <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Une exception existe déjà pour ce groupe à cette date.', 'association-manager' ); ?></div>
        <?php elseif ( 'exception_saved' === $notice ) : ?>
            <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Exception de distribution enregistrée.', 'association-manager' ); ?></div>
        <?php elseif ( 'exception_deleted' === $notice ) : ?>
            <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Exception de distribution supprimée.', 'association-manager' ); ?></div>
        <?php endif; ?>

        <?php if ( empty( $args['exceptions'] ) ) : ?>
            <p><?php esc_html_e( 'Aucune exception enregistrée pour ce groupe.', 'association-manager' ); ?></p>
        <?php else : ?>
            <div class="amap-exception-list">
                <?php foreach ( $args['exceptions'] as $exception ) : ?>
                    <?php
                    $moved_parts = array();
                    if ( 'moved' === $exception->exception_type ) {
                        if ( $exception->new_date ) {
                            $moved_parts[] = $exception->new_date;
                        }
                        if ( $exception->new_start_time && $exception->new_end_time ) {
                            $moved_parts[] = amap_format_time( $exception->new_start_time ) . '-' . amap_format_time( $exception->new_end_time );
                        }
                        if ( $exception->new_place ) {
                            $moved_parts[] = $exception->new_place;
                        }
                    }
                    $meta_parts = $moved_parts;
                    if ( $exception->reason ) {
                        $meta_parts[] = sprintf(
                            /* translators: %s: motif renseigné par le bureau. */
                            __( 'Motif : %s', 'association-manager' ),
                            $exception->reason
                        );
                    }
                    $decided_by_user = get_userdata( $exception->decided_by );
                    $meta_parts[]     = sprintf(
                        /* translators: %s: nom du membre du bureau ayant décidé l'exception. */
                        __( 'Décidé par %s', 'association-manager' ),
                        $decided_by_user ? $decided_by_user->display_name : '—'
                    );

                    $exception_edit_url = add_query_arg(
                        array(
                            'exception_action' => 'edit',
                            'exception_id'      => $exception->id,
                        ),
                        $view_url
                    );
                    $exception_delete_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'action'      => 'amap_delete_distribution_exception',
                                'id'          => $exception->id,
                                'redirect_to' => rawurlencode( $view_url ),
                            ),
                            admin_url( 'admin-post.php' )
                        ),
                        'amap_delete_distribution_exception_' . $exception->id
                    );
                    ?>
                    <div class="amap-exception-row">
                        <div class="amap-exception-row__top">
                            <span class="amap-exception-row__date"><?php echo esc_html( $exception->distribution_date ); ?></span>
                            <span class="amap-status-badge amap-status-badge--<?php echo esc_attr( 'cancelled' === $exception->exception_type ? 'cancelled' : 'moved' ); ?>">
                                <?php echo esc_html( $exception_type_labels[ $exception->exception_type ] ?? $exception->exception_type ); ?>
                            </span>
                        </div>
                        <p class="amap-exception-row__meta"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></p>
                        <div class="amap-exception-row__actions">
                            <a href="<?php echo esc_url( $exception_edit_url ); ?>"><?php esc_html_e( 'Modifier', 'association-manager' ); ?></a>
                            <a href="<?php echo esc_url( $exception_delete_url ); ?>" class="is-danger" onclick="return confirm( '<?php echo esc_js( __( 'Supprimer définitivement cette exception ?', 'association-manager' ) ); ?>' );">
                                <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( ! $exception_editing_id ) : ?>
            <button type="button" class="button-primary" id="amap-exception-add-toggle">
                <?php esc_html_e( '+ Ajouter une exception', 'association-manager' ); ?>
            </button>
        <?php endif; ?>
        <div id="amap-exception-form-wrapper"<?php echo $exception_editing_id ? '' : ' hidden'; ?>>
            <h3>
                <?php echo $exception_editing_id
                    ? esc_html__( 'Modifier une exception', 'association-manager' )
                    : esc_html__( 'Ajouter une exception', 'association-manager' ); ?>
            </h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-exception-form">
                <?php if ( $exception_editing_id ) : ?>
                    <?php wp_nonce_field( 'amap_edit_distribution_exception_' . $exception_editing_id ); ?>
                    <input type="hidden" name="action" value="amap_update_distribution_exception">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $exception_editing_id ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_distribution_exception_' . $group->id ); ?>
                    <input type="hidden" name="action" value="amap_add_distribution_exception">
                    <input type="hidden" name="group_id" value="<?php echo esc_attr( $group->id ); ?>">
                <?php endif; ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $view_url ); ?>">

                <div class="amap-field-row">
                    <div class="amap-field">
                        <label for="amap-exception-distribution-date"><?php esc_html_e( 'Distribution concernée', 'association-manager' ); ?></label>
                        <input type="date" id="amap-exception-distribution-date" name="distribution_date" value="<?php echo esc_attr( $exception_form_data['distribution_date'] ?? '' ); ?>" required>
                        <p class="amap-field__hint"><?php esc_html_e( 'Doit tomber sur le jour de semaine habituel de ce groupe.', 'association-manager' ); ?></p>
                    </div>
                    <div class="amap-field">
                        <label for="amap-exception-type"><?php esc_html_e( 'Type', 'association-manager' ); ?></label>
                        <select id="amap-exception-type" name="exception_type" required>
                            <?php foreach ( $exception_type_labels as $type_slug => $type_label ) : ?>
                                <option value="<?php echo esc_attr( $type_slug ); ?>" <?php selected( $type_slug, $exception_form_data['exception_type'] ?? '' ); ?>>
                                    <?php echo esc_html( $type_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="amap-field" id="amap-exception-new-date-row">
                    <label for="amap-exception-new-date"><?php esc_html_e( 'Nouvelle date', 'association-manager' ); ?></label>
                    <input type="date" id="amap-exception-new-date" name="new_date" value="<?php echo esc_attr( $exception_form_data['new_date'] ?? '' ); ?>">
                </div>
                <div class="amap-field-row" id="amap-exception-new-time-row">
                    <div class="amap-field">
                        <label for="amap-exception-new-start-time"><?php esc_html_e( 'Nouvel horaire — de', 'association-manager' ); ?></label>
                        <input type="time" id="amap-exception-new-start-time" name="new_start_time" value="<?php echo esc_attr( $exception_form_data['new_start_time'] ?? '' ); ?>">
                    </div>
                    <div class="amap-field">
                        <label for="amap-exception-new-end-time"><?php esc_html_e( 'à', 'association-manager' ); ?></label>
                        <input type="time" id="amap-exception-new-end-time" name="new_end_time" value="<?php echo esc_attr( $exception_form_data['new_end_time'] ?? '' ); ?>">
                    </div>
                </div>
                <div class="amap-field" id="amap-exception-new-place-row">
                    <label for="amap-exception-new-place"><?php esc_html_e( 'Nouveau lieu', 'association-manager' ); ?></label>
                    <input type="text" id="amap-exception-new-place" name="new_place" value="<?php echo esc_attr( $exception_form_data['new_place'] ?? '' ); ?>">
                </div>
                <div class="amap-field">
                    <label for="amap-exception-reason"><?php esc_html_e( 'Motif', 'association-manager' ); ?> <span class="amap-field__optional">(<?php esc_html_e( 'facultatif', 'association-manager' ); ?>)</span></label>
                    <textarea id="amap-exception-reason" name="reason" rows="3"><?php echo esc_textarea( $exception_form_data['reason'] ?? '' ); ?></textarea>
                </div>

                <div class="amap-form-actions">
                    <button type="submit" class="button-primary">
                        <?php echo $exception_editing_id ? esc_html__( 'Enregistrer', 'association-manager' ) : esc_html__( 'Ajouter', 'association-manager' ); ?>
                    </button>
                    <?php if ( $exception_editing_id ) : ?>
                        <a class="button-secondary" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
                    <?php else : ?>
                        <button type="button" class="button-secondary" id="amap-exception-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <script>
        ( function () {
            "use strict";
            var typeField   = document.getElementById( 'amap-exception-type' );
            var newDateRow  = document.getElementById( 'amap-exception-new-date-row' );
            var newTimeRow  = document.getElementById( 'amap-exception-new-time-row' );
            var newPlaceRow = document.getElementById( 'amap-exception-new-place-row' );

            function toggleMovedRows() {
                var isMoved        = ( 'moved' === typeField.value );
                newDateRow.hidden  = ! isMoved;
                newTimeRow.hidden  = ! isMoved;
                newPlaceRow.hidden = ! isMoved;
            }

            typeField.addEventListener( 'change', toggleMovedRows );
            toggleMovedRows();

            var toggle  = document.getElementById( 'amap-exception-add-toggle' );
            var wrapper = document.getElementById( 'amap-exception-form-wrapper' );
            var cancel  = document.getElementById( 'amap-exception-add-cancel' );
            if ( toggle ) {
                toggle.addEventListener( 'click', function () {
                    wrapper.hidden = false;
                    toggle.hidden  = true;
                } );
            }
            if ( cancel ) {
                cancel.addEventListener( 'click', function () {
                    wrapper.hidden = true;
                    toggle.hidden  = false;
                } );
            }
        } )();
        </script>
    </div>
</details>

<details class="amap-disclosure"<?php echo $volunteers_open ? ' open' : ''; ?>>
    <summary>
        <?php esc_html_e( 'Bénévoles de distribution', 'association-manager' ); ?>
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-chevron"></use></svg>
    </summary>
    <div class="amap-disclosure__body">
        <p class="amap-disclosure__hint">
            <?php esc_html_e( "Adhérents volontaires pour tenir une distribution (2 à 3 personnes, présentes 15 min avant et après). Chaque adhérent doit en assurer au moins 3 par an (indiqué entre parenthèses dans la liste ci-dessous, sans maximum). Distinct des souscriptions : concerne la présence à la distribution, pas la réception de produits.", 'association-manager' ); ?>
        </p>

        <?php if ( 'volunteer_invalid' === $notice ) : ?>
            <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Champs obligatoires manquants ou invalides.', 'association-manager' ); ?></div>
        <?php elseif ( 'volunteer_invalid_weekday' === $notice ) : ?>
            <div class="amap-notice amap-notice--error"><?php esc_html_e( "Cette date ne correspond à aucune distribution de ce groupe (jour habituel, ou nouvelle date d'une distribution déplacée).", 'association-manager' ); ?></div>
        <?php elseif ( 'volunteer_duplicate' === $notice ) : ?>
            <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Cet adhérent est déjà inscrit comme bénévole pour cette distribution.', 'association-manager' ); ?></div>
        <?php elseif ( 'volunteer_full' === $notice ) : ?>
            <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Cette distribution a déjà 3 bénévoles inscrits, maximum atteint.', 'association-manager' ); ?></div>
        <?php elseif ( 'volunteer_saved' === $notice ) : ?>
            <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Bénévole ajouté.', 'association-manager' ); ?></div>
        <?php elseif ( 'volunteer_deleted' === $notice ) : ?>
            <div class="amap-notice amap-notice--success"><?php esc_html_e( 'Bénévole retiré.', 'association-manager' ); ?></div>
        <?php endif; ?>

        <?php if ( empty( $args['volunteer_groups'] ) ) : ?>
            <p><?php esc_html_e( "Aucune distribution de ce groupe n'a encore de bénévole inscrit.", 'association-manager' ); ?></p>
        <?php else : ?>
            <div class="amap-volunteer-list">
                <?php foreach ( $args['volunteer_groups'] as $volunteer_group ) : ?>
                    <?php $volunteer_count = count( $volunteer_group['volunteers'] ); ?>
                    <div class="amap-volunteer-row">
                        <div class="amap-volunteer-row__top">
                            <span class="amap-volunteer-row__date"><?php echo esc_html( $volunteer_group['distribution_date'] ); ?></span>
                            <span class="amap-volunteer-count amap-volunteer-count--<?php echo $volunteer_count < 2 ? 'low' : 'ok'; ?>">
                                <?php echo esc_html( $volunteer_count . '/3' ); ?>
                            </span>
                        </div>
                        <ul class="amap-volunteer-names">
                            <?php foreach ( $volunteer_group['volunteers'] as $volunteer ) : ?>
                                <?php
                                $volunteer_user = get_userdata( $volunteer->member_user_id );
                                $volunteer_delete_url = wp_nonce_url(
                                    add_query_arg(
                                        array(
                                            'action'      => 'amap_delete_distribution_volunteer',
                                            'id'          => $volunteer->id,
                                            'redirect_to' => rawurlencode( $view_url ),
                                        ),
                                        admin_url( 'admin-post.php' )
                                    ),
                                    'amap_delete_distribution_volunteer_' . $volunteer->id
                                );
                                ?>
                                <li>
                                    <span><?php echo esc_html( $volunteer_user ? $volunteer_user->display_name : '#' . $volunteer->member_user_id ); ?></span>
                                    <a href="<?php echo esc_url( $volunteer_delete_url ); ?>" onclick="return confirm( '<?php echo esc_js( __( 'Retirer ce bénévole de cette distribution ?', 'association-manager' ) ); ?>' );">
                                        <?php esc_html_e( 'Retirer', 'association-manager' ); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( empty( $args['eligible_members'] ) ) : ?>
            <p><?php esc_html_e( "Aucun adhérent rattaché à ce groupe comme point de retrait pour l'instant.", 'association-manager' ); ?></p>
        <?php else : ?>
            <?php
            // Champ "Adhérent" cherchable plutôt qu'un <select> potentiellement long, même
            // composant que member-area-board-subscription-form.php : $volunteer_member_options
            // sert à la fois au pré-remplissage PHP (retour après erreur) et à la liste JS filtrée
            // en direct (member_list ci-dessous).
            $volunteer_member_options = array();
            foreach ( $args['eligible_members'] as $member ) {
                $member_year_count            = amap_count_member_distribution_volunteers_in_year( $member->ID, $args['current_year'] );
                $volunteer_member_options[] = array(
                    'id'    => $member->ID,
                    'label' => sprintf(
                        /* translators: 1: nom de l'adhérent. 2: nombre de distributions déjà assurées cette année (au moins 3 attendues, sans maximum). */
                        _n( '%1$s (%2$d distribution cette année)', '%1$s (%2$d distributions cette année)', $member_year_count, 'association-manager' ),
                        $member->display_name,
                        $member_year_count
                    ),
                );
            }
            $selected_volunteer_member_id    = isset( $volunteer_form_data['member_user_id'] ) ? (int) $volunteer_form_data['member_user_id'] : 0;
            $selected_volunteer_member_label = '';
            foreach ( $volunteer_member_options as $volunteer_member_option ) {
                if ( (int) $volunteer_member_option['id'] === $selected_volunteer_member_id ) {
                    $selected_volunteer_member_label = $volunteer_member_option['label'];
                    break;
                }
            }
            ?>
            <h3><?php esc_html_e( 'Ajouter un bénévole', 'association-manager' ); ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-volunteer-form" id="amap-volunteer-add-form" novalidate>
                <?php wp_nonce_field( 'amap_add_distribution_volunteer_' . $group->id ); ?>
                <input type="hidden" name="action" value="amap_add_distribution_volunteer">
                <input type="hidden" name="group_id" value="<?php echo esc_attr( $group->id ); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $view_url ); ?>">
                <div class="amap-field-row">
                    <div class="amap-field">
                        <label for="amap-volunteer-date"><?php esc_html_e( 'Distribution concernée', 'association-manager' ); ?></label>
                        <input type="date" id="amap-volunteer-date" name="distribution_date" value="<?php echo esc_attr( $volunteer_form_data['distribution_date'] ?? '' ); ?>" required>
                        <p class="amap-field__hint"><?php esc_html_e( "Jour habituel de ce groupe, ou nouvelle date d'une distribution déplacée.", 'association-manager' ); ?></p>
                    </div>
                    <div class="amap-field">
                        <label for="amap-volunteer-member"><?php esc_html_e( 'Adhérent', 'association-manager' ); ?></label>
                        <div class="amap-combo">
                            <input
                                type="text"
                                id="amap-volunteer-member"
                                placeholder="<?php esc_attr_e( 'Rechercher un nom…', 'association-manager' ); ?>"
                                value="<?php echo esc_attr( $selected_volunteer_member_label ); ?>"
                                autocomplete="off"
                                role="combobox"
                                aria-expanded="false"
                                aria-autocomplete="list"
                                aria-controls="amap-volunteer-member-list"
                                aria-describedby="amap-volunteer-member-error"
                                required
                            >
                            <input type="hidden" name="member_user_id" id="amap-volunteer-member-id" value="<?php echo esc_attr( $selected_volunteer_member_id ?: '' ); ?>">
                            <ul class="amap-combo__list" id="amap-volunteer-member-list" role="listbox" hidden></ul>
                        </div>
                        <span id="amap-volunteer-member-error" class="amap-field__error" hidden><?php esc_html_e( 'Sélectionnez un adhérent dans la liste proposée.', 'association-manager' ); ?></span>
                    </div>
                </div>
                <div class="amap-form-actions">
                    <button type="submit" class="button-primary"><?php esc_html_e( 'Ajouter un bénévole', 'association-manager' ); ?></button>
                </div>
            </form>
            <script>
            ( function () {
                "use strict";
                var members        = <?php echo wp_json_encode( $volunteer_member_options ); ?>;
                var form            = document.getElementById( 'amap-volunteer-add-form' );
                var memberInput     = document.getElementById( 'amap-volunteer-member' );
                var memberIdField   = document.getElementById( 'amap-volunteer-member-id' );
                var memberList      = document.getElementById( 'amap-volunteer-member-list' );
                var memberFieldWrap = memberInput.closest( '.amap-field' );
                var memberError     = document.getElementById( 'amap-volunteer-member-error' );
                var activeIndex     = -1;
                var matches         = [];

                function setMemberError( hasError ) {
                    memberFieldWrap.classList.toggle( 'has-error', hasError );
                    memberError.hidden = ! hasError;
                }

                function closeList() {
                    memberList.hidden = true;
                    memberList.innerHTML = '';
                    memberInput.setAttribute( 'aria-expanded', 'false' );
                    activeIndex = -1;
                    matches = [];
                }

                function selectMember( member ) {
                    memberInput.value   = member.label;
                    memberIdField.value = member.id;
                    setMemberError( false );
                    closeList();
                }

                function highlight( label, query ) {
                    var index = label.toLowerCase().indexOf( query.toLowerCase() );
                    if ( -1 === index ) {
                        return document.createTextNode( label );
                    }
                    var fragment = document.createDocumentFragment();
                    fragment.appendChild( document.createTextNode( label.slice( 0, index ) ) );
                    var mark = document.createElement( 'mark' );
                    mark.textContent = label.slice( index, index + query.length );
                    fragment.appendChild( mark );
                    fragment.appendChild( document.createTextNode( label.slice( index + query.length ) ) );
                    return fragment;
                }

                function renderMatches( query ) {
                    memberList.innerHTML = '';
                    matches.forEach( function ( member, index ) {
                        var li = document.createElement( 'li' );
                        li.setAttribute( 'role', 'option' );
                        li.appendChild( highlight( member.label, query ) );
                        li.className = ( index === activeIndex ) ? 'is-active' : '';
                        li.addEventListener( 'mousedown', function ( event ) {
                            event.preventDefault();
                            selectMember( member );
                        } );
                        memberList.appendChild( li );
                    } );
                    memberList.hidden = ! matches.length;
                    memberInput.setAttribute( 'aria-expanded', matches.length ? 'true' : 'false' );
                }

                memberInput.addEventListener( 'input', function () {
                    memberIdField.value = '';
                    setMemberError( false );
                    var query = memberInput.value.trim();
                    if ( ! query ) {
                        closeList();
                        return;
                    }
                    matches = members.filter( function ( member ) {
                        return -1 !== member.label.toLowerCase().indexOf( query.toLowerCase() );
                    } ).slice( 0, 20 );
                    activeIndex = -1;
                    renderMatches( query );
                } );

                memberInput.addEventListener( 'keydown', function ( event ) {
                    if ( memberList.hidden || ! matches.length ) {
                        return;
                    }
                    if ( 'ArrowDown' === event.key ) {
                        event.preventDefault();
                        activeIndex = ( activeIndex + 1 ) % matches.length;
                        renderMatches( memberInput.value.trim() );
                    } else if ( 'ArrowUp' === event.key ) {
                        event.preventDefault();
                        activeIndex = ( activeIndex - 1 + matches.length ) % matches.length;
                        renderMatches( memberInput.value.trim() );
                    } else if ( 'Enter' === event.key ) {
                        if ( activeIndex >= 0 ) {
                            event.preventDefault();
                            selectMember( matches[ activeIndex ] );
                        }
                    } else if ( 'Escape' === event.key ) {
                        closeList();
                    }
                } );

                memberInput.addEventListener( 'blur', function () {
                    window.setTimeout( closeList, 150 );
                } );

                form.addEventListener( 'submit', function ( event ) {
                    if ( ! memberIdField.value ) {
                        event.preventDefault();
                        setMemberError( true );
                        memberInput.focus();
                    }
                } );
            } )();
            </script>
        <?php endif; ?>
    </div>
</details>
