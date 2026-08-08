<?php
/**
 * Formulaire de déclaration d'un congé (étape 8b), atteint depuis le lien "Déclarer un congé" de
 * l'onglet "Espace adhérent" (?amap_member_action=declare_leave&subscription_id=X). Les données
 * sont préparées et validées par amap_get_member_leave_form_data() ; la soumission est traitée
 * par amap_handle_add_member_leave(). $available_dates ne contient que des dates déjà valides
 * (jour du groupe, période du contrat, délai d'une semaine, non déjà déclarées) : pas de champ
 * date libre, l'adhérent choisit dans une liste plutôt que de risquer une date invalide.
 */
$subscription     = $args['subscription'];
$contract         = $args['contract'];
$producer         = $args['producer'];
$group            = $args['group'];
$leaves           = $args['leaves'];
$available_dates  = $args['available_dates'];
?>

<h1><?php esc_html_e( 'Déclarer un congé', 'association-manager' ); ?></h1>

<div class="amap-card">
    <h2><?php echo esc_html( $contract->label ); ?></h2>
    <ul>
        <li><?php esc_html_e( 'Producteur', 'association-manager' ); ?> : <?php echo esc_html( $producer ? $producer->display_name : '—' ); ?></li>
        <li><?php esc_html_e( 'Groupe (point de retrait)', 'association-manager' ); ?> : <?php echo esc_html( $group ? $group->name : '—' ); ?></li>
    </ul>
</div>

<div class="amap-card">
    <h2><?php esc_html_e( 'Vos congés déjà déclarés', 'association-manager' ); ?></h2>

    <?php if ( empty( $leaves ) ) : ?>
        <p><?php esc_html_e( "Vous n'avez déclaré aucun congé pour ce contrat.", 'association-manager' ); ?></p>
    <?php else : ?>
        <ul>
            <?php foreach ( $leaves as $leave ) : ?>
                <li><?php echo esc_html( date_i18n( 'l j F Y', strtotime( $leave->leave_date ) ) ); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="description">
        <?php
        printf(
            /* translators: 1: nombre de congés déjà déclarés. 2: nombre de congés autorisés pour ce contrat. */
            esc_html__( '%1$d congé(s) déclaré(s) sur %2$d autorisés.', 'association-manager' ),
            count( $leaves ),
            (int) $contract->max_leaves
        );
        ?>
    </p>
</div>

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
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'amap_declare_leave_' . $subscription->id ); ?>
        <input type="hidden" name="action" value="amap_add_member_leave">
        <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->id ); ?>">

        <label for="amap-leave-date"><?php esc_html_e( 'Date du congé', 'association-manager' ); ?></label>
        <select id="amap-leave-date" name="leave_date" required>
            <option value=""></option>
            <?php foreach ( $available_dates as $date_option ) : ?>
                <option value="<?php echo esc_attr( $date_option['date'] ); ?>"><?php echo esc_html( $date_option['label'] ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Un congé annule la réception de votre panier maraîcher ce jour-là ; la distribution a bien lieu pour les autres adhérents.', 'association-manager' ); ?></p>

        <p>
            <button type="submit"><?php esc_html_e( 'Déclarer ce congé', 'association-manager' ); ?></button>
            <a class="button-secondary" href="<?php echo esc_url( amap_get_member_area_tab_url( 'member' ) ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
        </p>
    </form>
<?php endif; ?>
