<?php
/**
 * Espace membre affiché sur "espace-adherent" à un utilisateur connecté (cf.
 * amap_maybe_render_member_area() dans le plugin, qui calcule is_member/is_producer/is_board).
 * Casquette adhérent : coordonnées + lien vers le formulaire self-service
 * (member-profile-edit.php). Producteur/bureau : coquille minimale pour l'instant, le contenu
 * métier (contrats, distributions, congés, gestion producteurs...) viendra dans une étape
 * ultérieure.
 */

$current_user = wp_get_current_user();
$display_name = $current_user->display_name ? $current_user->display_name : $current_user->user_email;
?>

<h1><?php esc_html_e( 'Espace adhérent', 'association-manager' ); ?></h1>

<?php if ( ! empty( $args['profile_updated'] ) ) : ?>
    <p><?php esc_html_e( 'Vos informations ont été mises à jour.', 'association-manager' ); ?></p>
<?php endif; ?>

<p>
    <?php
    printf(
        /* translators: %s: nom ou email de l'utilisateur connecté */
        esc_html__( 'Bonjour %s.', 'association-manager' ),
        esc_html( $display_name )
    );
    ?>
</p>

<?php if ( $args['is_amap_user'] ) : ?>
    <?php $member_contact = amap_get_user_contact( $current_user->ID ); ?>
    <h2><?php esc_html_e( 'Mes informations', 'association-manager' ); ?></h2>
    <ul>
        <li><?php esc_html_e( 'Nom', 'association-manager' ); ?> : <?php echo esc_html( $current_user->last_name ); ?></li>
        <li><?php esc_html_e( 'Prénom', 'association-manager' ); ?> : <?php echo esc_html( $current_user->first_name ); ?></li>
        <li><?php esc_html_e( 'Email', 'association-manager' ); ?> : <?php echo esc_html( $current_user->user_email ); ?></li>
        <li><?php esc_html_e( 'Téléphone', 'association-manager' ); ?> : <?php echo esc_html( $member_contact->phone ?? '' ); ?></li>
        <li><?php esc_html_e( 'Adresse', 'association-manager' ); ?> : <?php echo esc_html( $member_contact->address ?? '' ); ?></li>
    </ul>
    <p><a href="<?php echo esc_url( amap_get_member_profile_edit_url() ); ?>"><?php esc_html_e( 'Modifier mes informations', 'association-manager' ); ?></a></p>
<?php endif; ?>

<?php if ( $args['is_member'] ) : ?>
    <p><?php esc_html_e( 'Vous êtes adhérent.', 'association-manager' ); ?></p>
<?php endif; ?>

<?php if ( $args['is_producer'] ) : ?>
    <p><?php esc_html_e( 'Vous êtes producteur.', 'association-manager' ); ?></p>
<?php endif; ?>

<?php if ( $args['is_board'] ) : ?>
    <p>
        <?php esc_html_e( 'Vous êtes membre du bureau.', 'association-manager' ); ?>
        <?php if ( current_user_can( 'amap_manage_users' ) ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-users' ) ); ?>"><?php esc_html_e( 'Gérer les utilisateurs AMAP', 'association-manager' ); ?></a>
        <?php endif; ?>
    </p>
<?php endif; ?>

<p><a href="<?php echo esc_url( wp_logout_url( amap_get_member_area_url() ) ); ?>"><?php esc_html_e( 'Se déconnecter', 'association-manager' ); ?></a></p>
