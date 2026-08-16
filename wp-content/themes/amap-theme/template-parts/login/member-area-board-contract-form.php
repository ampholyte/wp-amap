<?php
/**
 * Formulaire "Ajouter"/"Modifier les infos" d'un contrat, section "Contrats" de l'espace bureau —
 * page dédiée (pas de modale, voir project_espace_bureau_design_consolide), reprise de
 * amap_render_contracts_page() côté wp-admin. La soumission est traitée par les mêmes handlers que
 * wp-admin (amap_handle_add_contract()/amap_handle_update_contract()), le champ caché
 * "redirect_to" leur indiquant la page de retour en cas de succès (fiche du contrat, créé ou
 * modifié). $args : voir amap_get_board_contract_form_data() (plugin, member-area.php).
 *
 * Sous-page en dehors de la coquille à onglets (atteinte directement par
 * amap_maybe_render_member_area(), pas via member-area.php) : elle inclut donc elle-même les
 * symboles SVG (#amap-icon-*), comme member-profile-edit.php.
 */
$editing_id     = $args['editing_id'];
$form_data      = $args['form_data'];
$contract_types = amap_get_contract_types();
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( $editing_id ? amap_get_board_contract_view_url( $editing_id ) : amap_get_board_contracts_url() ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php echo $editing_id
            ? esc_html__( 'Retour à la fiche du contrat', 'association-manager' )
            : esc_html__( 'Retour à la liste', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title">
        <?php echo $editing_id
            ? esc_html__( 'Modifier les infos du contrat', 'association-manager' )
            : esc_html__( 'Ajouter un contrat', 'association-manager' ); ?>
    </h1>
</div>

<?php if ( 'invalid' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Champs obligatoires manquants ou invalides.', 'association-manager' ); ?></div>
<?php elseif ( 'invalid_dates' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Dates invalides : la date de fin doit être après la date de début.', 'association-manager' ); ?></div>
<?php elseif ( 'invalid_frequency' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'La fréquence (en semaines) est obligatoire et doit être un nombre positif pour un contrat de type panier récurrent.', 'association-manager' ); ?></div>
<?php elseif ( 'invalid_max_leaves' === $args['notice'] ) : ?>
    <div class="amap-notice amap-notice--error"><?php esc_html_e( 'Le nombre de congés maximum est obligatoire et doit être un nombre entier positif ou nul (0 = aucun congé autorisé) pour un contrat de type panier récurrent.', 'association-manager' ); ?></div>
<?php endif; ?>

<?php if ( empty( $args['producers'] ) ) : ?>
    <p><?php esc_html_e( "Aucun compte producteur pour le moment : créez d'abord un producteur depuis la section Utilisateurs.", 'association-manager' ); ?></p>
<?php else : ?>

    <form class="amap-profile-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-board-contract-form">
        <?php if ( $editing_id ) : ?>
            <?php wp_nonce_field( 'amap_edit_contract_' . $editing_id ); ?>
            <input type="hidden" name="action" value="amap_update_contract">
            <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
            <input type="hidden" name="redirect_to" value="<?php echo esc_url( amap_get_board_contract_view_url( $editing_id ) ); ?>">
        <?php else : ?>
            <?php wp_nonce_field( 'amap_add_contract' ); ?>
            <input type="hidden" name="action" value="amap_add_contract">
            <input type="hidden" name="redirect_to" value="<?php echo esc_url( amap_get_board_contract_add_url() ); ?>">
        <?php endif; ?>

        <div class="amap-field">
            <label for="amap-board-contract-label"><?php esc_html_e( 'Libellé', 'association-manager' ); ?></label>
            <input type="text" id="amap-board-contract-label" name="label" value="<?php echo esc_attr( $form_data['label'] ?? '' ); ?>" required>
        </div>

        <div class="amap-field-row">
            <div class="amap-field">
                <label for="amap-board-contract-producer"><?php esc_html_e( 'Producteur', 'association-manager' ); ?></label>
                <select id="amap-board-contract-producer" name="producer_user_id" required>
                    <option value=""></option>
                    <?php foreach ( $args['producers'] as $producer ) : ?>
                        <option value="<?php echo esc_attr( $producer->ID ); ?>" <?php selected( (string) $producer->ID, $form_data['producer_user_id'] ?? '' ); ?>>
                            <?php echo esc_html( $producer->display_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="amap-field__hint" id="amap-board-contract-producer-hint" hidden>
                    <?php esc_html_e( "Ce producteur n'a encore aucun groupe de distribution rattaché : rattachez-le depuis la section Groupes avant de pouvoir livrer ce contrat.", 'association-manager' ); ?>
                </p>
            </div>
            <div class="amap-field">
                <label for="amap-board-contract-type"><?php esc_html_e( 'Type', 'association-manager' ); ?></label>
                <select id="amap-board-contract-type" name="contract_type" required>
                    <?php foreach ( $contract_types as $type_slug => $type_label ) : ?>
                        <option value="<?php echo esc_attr( $type_slug ); ?>" <?php selected( $type_slug, $form_data['contract_type'] ?? '' ); ?>>
                            <?php echo esc_html( $type_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="amap-field-row">
            <div class="amap-field">
                <label for="amap-board-contract-start-date"><?php esc_html_e( 'Date de début', 'association-manager' ); ?></label>
                <input type="date" id="amap-board-contract-start-date" name="start_date" value="<?php echo esc_attr( $form_data['start_date'] ?? '' ); ?>" required>
            </div>
            <div class="amap-field">
                <label for="amap-board-contract-end-date"><?php esc_html_e( 'Date de fin', 'association-manager' ); ?></label>
                <input type="date" id="amap-board-contract-end-date" name="end_date" value="<?php echo esc_attr( $form_data['end_date'] ?? '' ); ?>" required>
            </div>
        </div>

        <div class="amap-field-row" id="amap-board-contract-basket-row">
            <div class="amap-field">
                <label for="amap-board-contract-frequency"><?php esc_html_e( 'Fréquence (semaines)', 'association-manager' ); ?></label>
                <input type="number" id="amap-board-contract-frequency" name="frequency_weeks" min="1" max="52" value="<?php echo esc_attr( $form_data['frequency_weeks'] ?? '' ); ?>">
            </div>
            <div class="amap-field">
                <label for="amap-board-contract-max-leaves"><?php esc_html_e( 'Congés max', 'association-manager' ); ?></label>
                <input type="number" id="amap-board-contract-max-leaves" name="max_leaves" min="0" max="52" value="<?php echo esc_attr( $form_data['max_leaves'] ?? '' ); ?>">
            </div>
        </div>

        <div class="amap-field amap-field--inline">
            <label class="amap-checkbox-field">
                <input type="checkbox" id="amap-board-contract-is-active" name="is_active" value="1" <?php checked( ! empty( $form_data['is_active'] ) ); ?>>
                <?php esc_html_e( 'Ouvert à la souscription', 'association-manager' ); ?>
            </label>
        </div>

        <div class="amap-form-actions">
            <button type="submit" class="button-primary">
                <?php echo $editing_id ? esc_html__( 'Enregistrer', 'association-manager' ) : esc_html__( 'Ajouter', 'association-manager' ); ?>
            </button>
            <a class="button-secondary" href="<?php echo esc_url( $editing_id ? amap_get_board_contract_view_url( $editing_id ) : amap_get_board_contracts_url() ); ?>">
                <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
            </a>
        </div>
    </form>

    <script>
    ( function () {
        "use strict";
        var producerGroupCounts = <?php echo wp_json_encode( $args['producer_group_counts'] ); ?>;

        var typeField      = document.getElementById( 'amap-board-contract-type' );
        var basketRow       = document.getElementById( 'amap-board-contract-basket-row' );
        var frequencyField  = document.getElementById( 'amap-board-contract-frequency' );
        var maxLeavesField  = document.getElementById( 'amap-board-contract-max-leaves' );

        function toggleBasketFields() {
            var isBasketRecurring = ( 'basket_recurring' === typeField.value );
            basketRow.hidden      = ! isBasketRecurring;
            frequencyField.required = isBasketRecurring;
            maxLeavesField.required = isBasketRecurring;
        }

        typeField.addEventListener( 'change', toggleBasketFields );
        toggleBasketFields();

        var producerField = document.getElementById( 'amap-board-contract-producer' );
        var producerHint   = document.getElementById( 'amap-board-contract-producer-hint' );

        function toggleProducerHint() {
            var count       = producerGroupCounts[ producerField.value ];
            producerHint.hidden = ! producerField.value || undefined === count || count > 0;
        }

        producerField.addEventListener( 'change', toggleProducerHint );
        toggleProducerHint();
    } )();
    </script>

<?php endif; ?>
