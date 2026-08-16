<?php
/**
 * Formulaire "Ajouter"/"Modifier les infos du groupe", section "Groupes" de l'espace bureau —
 * page dédiée (pas de modale, voir project_espace_bureau_design_consolide), reprise de
 * amap_render_groups_page() côté wp-admin. La soumission est traitée par les mêmes handlers que
 * wp-admin (amap_handle_add_group()/amap_handle_update_group()), le champ caché "redirect_to" leur
 * indiquant la page de retour en cas de succès (liste en ajout, fiche du groupe en modification).
 * $args : voir amap_get_board_group_form_data() (plugin, member-area.php).
 *
 * Sous-page en dehors de la coquille à onglets (atteinte directement par
 * amap_maybe_render_member_area(), pas via member-area.php) : elle inclut donc elle-même les
 * symboles SVG (#amap-icon-*), comme member-profile-edit.php.
 */
$editing_id = $args['editing_id'];
$form_data  = $args['form_data'];
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( $editing_id ? amap_get_board_group_view_url( $editing_id ) : amap_get_board_groups_url() ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php echo $editing_id
            ? esc_html__( 'Retour à la fiche du groupe', 'association-manager' )
            : esc_html__( 'Retour à la liste', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title">
        <?php echo $editing_id
            ? esc_html__( 'Modifier les infos du groupe', 'association-manager' )
            : esc_html__( 'Ajouter un groupe', 'association-manager' ); ?>
    </h1>
</div>

<?php if ( 'invalid' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Champs obligatoires manquants.', 'association-manager' ); ?></div>
<?php elseif ( 'invalid_time' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( "L'heure de fin doit être après l'heure de début.", 'association-manager' ); ?></div>
<?php elseif ( 'invalid_email' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( "L'adresse de notification n'est pas une adresse email valide.", 'association-manager' ); ?></div>
<?php endif; ?>

<form class="amap-profile-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php if ( $editing_id ) : ?>
        <?php wp_nonce_field( 'amap_edit_group_' . $editing_id ); ?>
        <input type="hidden" name="action" value="amap_update_group">
        <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
        <input type="hidden" name="redirect_to" value="<?php echo esc_url( amap_get_board_group_view_url( $editing_id ) ); ?>">
    <?php else : ?>
        <?php wp_nonce_field( 'amap_add_group' ); ?>
        <input type="hidden" name="action" value="amap_add_group">
        <input type="hidden" name="redirect_to" value="<?php echo esc_url( amap_get_board_groups_url() ); ?>">
    <?php endif; ?>

    <div class="amap-field">
        <label for="amap-board-group-name"><?php esc_html_e( 'Nom', 'association-manager' ); ?></label>
        <input type="text" id="amap-board-group-name" name="name" value="<?php echo esc_attr( $form_data['name'] ?? '' ); ?>" required>
    </div>

    <div class="amap-field">
        <label for="amap-board-group-delivery-place"><?php esc_html_e( 'Lieu de livraison', 'association-manager' ); ?></label>
        <input type="text" id="amap-board-group-delivery-place" name="delivery_place" value="<?php echo esc_attr( $form_data['delivery_place'] ?? '' ); ?>" required>
    </div>

    <div class="amap-field-row amap-field-row--3">
        <div class="amap-field">
            <label for="amap-board-group-weekday"><?php esc_html_e( 'Jour de la semaine', 'association-manager' ); ?></label>
            <select id="amap-board-group-weekday" name="weekday" required>
                <?php foreach ( amap_get_weekday_labels() as $weekday => $weekday_label ) : ?>
                    <option value="<?php echo esc_attr( $weekday ); ?>" <?php selected( (string) $weekday, $form_data['weekday'] ?? '' ); ?>>
                        <?php echo esc_html( $weekday_label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="amap-field">
            <label for="amap-board-group-start-time"><?php esc_html_e( 'Heure de début', 'association-manager' ); ?></label>
            <input type="time" id="amap-board-group-start-time" name="start_time" value="<?php echo esc_attr( $form_data['start_time'] ?? '' ); ?>" required>
        </div>
        <div class="amap-field">
            <label for="amap-board-group-end-time"><?php esc_html_e( 'Heure de fin', 'association-manager' ); ?></label>
            <input type="time" id="amap-board-group-end-time" name="end_time" value="<?php echo esc_attr( $form_data['end_time'] ?? '' ); ?>" required>
        </div>
    </div>

    <div class="amap-field">
        <label for="amap-board-group-notification-email"><?php esc_html_e( 'Adresse de notification', 'association-manager' ); ?> <span class="amap-field__optional">(<?php esc_html_e( 'facultatif', 'association-manager' ); ?>)</span></label>
        <input type="email" id="amap-board-group-notification-email" name="notification_email" value="<?php echo esc_attr( $form_data['notification_email'] ?? '' ); ?>">
        <p class="amap-field__hint">
            <?php esc_html_e( "Adresse (ex. un alias créé par le bureau) qui recevra un récapitulatif en cas d'annulation ou de déplacement d'une distribution de ce groupe. Laissée vide, aucune notification ne sera envoyée aux adhérents.", 'association-manager' ); ?>
        </p>
    </div>

    <div class="amap-form-actions">
        <button type="submit" class="button-primary">
            <?php echo $editing_id ? esc_html__( 'Enregistrer', 'association-manager' ) : esc_html__( 'Ajouter', 'association-manager' ); ?>
        </button>
        <a class="button-secondary" href="<?php echo esc_url( $editing_id ? amap_get_board_group_view_url( $editing_id ) : amap_get_board_groups_url() ); ?>">
            <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
        </a>
    </div>
</form>
