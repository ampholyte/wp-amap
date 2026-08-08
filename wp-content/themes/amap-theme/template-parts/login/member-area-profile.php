<?php
/**
 * Onglet "Mes infos" : nom/prénom/email/téléphone/adresse, liés au compte (user_id) et non à
 * une casquette particulière.
 */
$member_contact = amap_get_user_contact( $args['current_user']->ID );
?>
<div class="amap-card">
    <h2><?php esc_html_e( 'Mes informations', 'association-manager' ); ?></h2>
    <ul>
        <li><?php esc_html_e( 'Nom', 'association-manager' ); ?> : <?php echo esc_html( $args['current_user']->last_name ); ?></li>
        <li><?php esc_html_e( 'Prénom', 'association-manager' ); ?> : <?php echo esc_html( $args['current_user']->first_name ); ?></li>
        <li><?php esc_html_e( 'Email', 'association-manager' ); ?> : <?php echo esc_html( $args['current_user']->user_email ); ?></li>
        <li><?php esc_html_e( 'Téléphone', 'association-manager' ); ?> : <?php echo esc_html( $member_contact->phone ?? '' ); ?></li>
        <li><?php esc_html_e( 'Adresse', 'association-manager' ); ?> : <?php echo esc_html( $member_contact->address ?? '' ); ?></li>
    </ul>
    <p><a class="button-secondary" href="<?php echo esc_url( amap_get_member_profile_edit_url() ); ?>"><?php esc_html_e( 'Modifier mes informations', 'association-manager' ); ?></a></p>
</div>
