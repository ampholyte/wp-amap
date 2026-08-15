<?php
/**
 * Onglet "Mes infos" : nom/prénom/email/téléphone/adresse, liés au compte (user_id) et non à une
 * casquette particulière — accessible dès qu'au moins une casquette AMAP est portée, quel que soit
 * l'onglet par défaut de chacune. "Bonjour X" + les badges de casquette vivent ici (et nulle part
 * ailleurs dans la coquille, voir member-area.php) : c'est le seul endroit qui les affichait déjà
 * dans les maquettes validées.
 */
$current_user   = $args['current_user'];
$member_contact = amap_get_user_contact( $current_user->ID );
$display_name   = $current_user->display_name ? $current_user->display_name : $current_user->user_email;
$role_labels    = amap_get_available_roles();
?>

<div class="amap-greeting">
    <p class="amap-greeting__text">
        <?php
        printf(
            /* translators: %s: prénom ou email de l'utilisateur connecté */
            esc_html__( 'Bonjour %s', 'association-manager' ),
            esc_html( $display_name )
        );
        ?>
    </p>
    <p class="amap-badges">
        <?php if ( $args['is_member'] ) : ?>
            <span class="amap-badge"><?php echo esc_html( $role_labels['amap_member'] ); ?></span>
        <?php endif; ?>
        <?php if ( $args['is_producer'] ) : ?>
            <span class="amap-badge"><?php echo esc_html( $role_labels['amap_producer'] ); ?></span>
        <?php endif; ?>
        <?php if ( $args['is_board'] ) : ?>
            <span class="amap-badge"><?php echo esc_html( $role_labels['amap_board'] ); ?></span>
        <?php endif; ?>
    </p>
</div>

<section class="amap-info-card">
    <h2 class="amap-info-card__title"><?php esc_html_e( 'Mes informations', 'association-manager' ); ?></h2>
    <dl class="amap-info-list amap-info-list--divided">
        <div>
            <dt><?php esc_html_e( 'Nom', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $current_user->last_name ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Prénom', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $current_user->first_name ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Email', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $current_user->user_email ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Téléphone', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $member_contact->phone ?? '' ); ?></dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Adresse', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $member_contact->address ?? '' ); ?></dd>
        </div>
    </dl>
    <div class="amap-info-card__actions">
        <a class="button-primary" href="<?php echo esc_url( amap_get_member_profile_edit_url() ); ?>"><?php esc_html_e( 'Modifier mes informations', 'association-manager' ); ?></a>
    </div>
</section>
