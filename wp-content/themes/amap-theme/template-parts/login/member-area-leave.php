<?php
/**
 * Formulaire de déclaration d'un congé (étape 8b), atteint depuis le lien "Déclarer un congé" de
 * l'onglet "Espace adhérent" (?amap_member_action=declare_leave&subscription_id=X). Les données
 * sont préparées et validées par amap_get_member_leave_form_data() ; la soumission est traitée
 * par amap_handle_add_member_leave(). $available_dates ne contient que des dates déjà valides
 * (jour du groupe, période du contrat, délai d'une semaine, non déjà déclarées) : pas de champ
 * date libre, l'adhérent choisit dans une case plutôt que de risquer une date invalide.
 *
 * Sous-page en dehors de la coquille à onglets (atteinte directement par
 * amap_maybe_render_member_area(), pas via member-area.php) : elle inclut donc elle-même les
 * symboles SVG (#amap-icon-*).
 */
$subscription    = $args['subscription'];
$contract        = $args['contract'];
$producer        = $args['producer'];
$group           = $args['group'];
$leaves          = $args['leaves'];
$available_dates = $args['available_dates'];
$weekday_labels   = amap_get_weekday_labels();
// Jour identique pour toutes les dates proposées (jour fixe du groupe) : calculé une seule fois
// plutôt que par date, pour la case abrégée ("Mar") de chaque option.
$weekday_short    = $group ? mb_substr( $weekday_labels[ (int) $group->weekday ] ?? '', 0, 3 ) : '';
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( amap_get_member_area_tab_url( 'member' ) ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php esc_html_e( 'Retour à mes contrats', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title"><?php esc_html_e( 'Déclarer un congé', 'association-manager' ); ?></h1>
</div>

<section class="amap-info-card">
    <div class="amap-info-card__head">
        <span class="amap-contract-card__type amap-type-icon--basket">
            <svg class="icon" aria-hidden="true"><use href="#amap-icon-basket"></use></svg>
        </span>
        <div>
            <h2 class="amap-info-card__title"><?php echo esc_html( $contract->label ); ?></h2>
            <p class="amap-info-card__sub"><?php echo esc_html( $producer ? $producer->display_name : '—' ); ?></p>
        </div>
    </div>
    <dl class="amap-info-list">
        <div>
            <dt><?php esc_html_e( 'Point de retrait', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $group ? $group->name : '—' ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Jour de distribution', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $group ? ( $weekday_labels[ (int) $group->weekday ] ?? '—' ) : '—' ); ?></dd>
        </div>
    </dl>
</section>

<section class="amap-info-card">
    <h2 class="amap-info-card__title"><?php esc_html_e( 'Vos congés déjà déclarés', 'association-manager' ); ?></h2>

    <?php if ( empty( $leaves ) ) : ?>
        <p><?php esc_html_e( "Vous n'avez déclaré aucun congé pour ce contrat.", 'association-manager' ); ?></p>
    <?php else : ?>
        <ul class="amap-declared-leaves">
            <?php foreach ( $leaves as $leave ) : ?>
                <li>
                    <svg class="icon" aria-hidden="true"><use href="#amap-icon-check"></use></svg>
                    <?php echo esc_html( date_i18n( 'l j F Y', strtotime( $leave->leave_date ) ) ); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="amap-info-card__note">
        <?php
        printf(
            /* translators: 1: nombre de congés déjà déclarés. 2: nombre de congés autorisés pour ce contrat. */
            esc_html__( '%1$d congé(s) déclaré(s) sur %2$d autorisés.', 'association-manager' ),
            count( $leaves ),
            (int) $contract->max_leaves
        );
        ?>
    </p>
</section>

<?php if ( empty( $available_dates ) ) : ?>
    <div class="amap-notice amap-notice--info">
        <?php if ( count( $leaves ) >= (int) $contract->max_leaves ) : ?>
            <?php esc_html_e( 'Vous avez déclaré tous vos congés pour ce contrat.', 'association-manager' ); ?>
        <?php else : ?>
            <?php esc_html_e( 'Aucune date de congé disponible pour le moment (délai d\'une semaine avant la distribution).', 'association-manager' ); ?>
        <?php endif; ?>
    </div>
    <p>
        <a class="button-secondary" href="<?php echo esc_url( amap_get_member_area_tab_url( 'member' ) ); ?>"><?php esc_html_e( 'Retour à mes contrats', 'association-manager' ); ?></a>
    </p>
<?php else : ?>
    <form class="amap-leave-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'amap_declare_leave_' . $subscription->id ); ?>
        <input type="hidden" name="action" value="amap_add_member_leave">
        <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->id ); ?>">

        <h2 class="amap-leave-form__title"><?php esc_html_e( 'Choisissez une date', 'association-manager' ); ?></h2>

        <div class="amap-date-options" role="radiogroup" aria-label="<?php esc_attr_e( 'Date du congé', 'association-manager' ); ?>">
            <?php foreach ( $available_dates as $date_option ) : ?>
                <label class="amap-date-option">
                    <input class="sr-only" type="radio" name="leave_date" value="<?php echo esc_attr( $date_option['date'] ); ?>" required>
                    <span class="amap-date-option__box">
                        <span class="amap-date-option__day"><?php echo esc_html( $weekday_short ); ?></span>
                        <span class="amap-date-option__date"><?php echo esc_html( amap_get_short_date_label( $date_option['date'] ) ); ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <p class="amap-form-help">
            <svg class="icon" aria-hidden="true"><use href="#amap-icon-info"></use></svg>
            <?php esc_html_e( 'Un congé annule la réception de votre panier maraîcher ce jour-là ; la distribution a bien lieu pour les autres adhérents.', 'association-manager' ); ?>
        </p>

        <div class="amap-form-actions">
            <button type="submit" class="button-primary"><?php esc_html_e( 'Déclarer ce congé', 'association-manager' ); ?></button>
            <a class="button-secondary" href="<?php echo esc_url( amap_get_member_area_tab_url( 'member' ) ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
        </div>
    </form>
<?php endif; ?>
