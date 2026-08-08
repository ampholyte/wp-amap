<?php
/**
 * Page d'admin "Groupes" : CRUD des groupes de distribution et rattachement des producteurs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 0=lundi ... 6=dimanche, convention partagée avec la colonne wp_amap_groups.weekday.
 */
function amap_get_weekday_labels() {
    return array(
        0 => __( 'Lundi', 'association-manager' ),
        1 => __( 'Mardi', 'association-manager' ),
        2 => __( 'Mercredi', 'association-manager' ),
        3 => __( 'Jeudi', 'association-manager' ),
        4 => __( 'Vendredi', 'association-manager' ),
        5 => __( 'Samedi', 'association-manager' ),
        6 => __( 'Dimanche', 'association-manager' ),
    );
}

function amap_get_groups() {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}amap_groups ORDER BY weekday ASC, start_time ASC"
    );
}

function amap_get_group( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_groups WHERE id = %d", $id )
    );
}

function amap_get_producer_users() {
    $user_query = new WP_User_Query(
        array(
            'role'    => 'amap_producer',
            'orderby' => 'display_name',
            'order'   => 'ASC',
        )
    );

    return $user_query->get_results();
}

function amap_get_group_producer_ids( $group_id ) {
    global $wpdb;

    return $wpdb->get_col(
        $wpdb->prepare(
            "SELECT producer_user_id FROM {$wpdb->prefix}amap_group_producers WHERE group_id = %d",
            $group_id
        )
    );
}

/**
 * Sens inverse de amap_get_group_producer_ids() : tous les groupes auxquels un producteur est
 * rattaché. Sert à limiter les menus déroulants "Groupe" des dates de livraison d'un contrat, et
 * à revalider côté serveur qu'un group_id soumis appartient bien au producteur du contrat.
 */
function amap_get_producer_groups( $producer_user_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT g.* FROM {$wpdb->prefix}amap_groups g
             INNER JOIN {$wpdb->prefix}amap_group_producers gp ON gp.group_id = g.id
             WHERE gp.producer_user_id = %d
             ORDER BY g.weekday ASC, g.start_time ASC",
            $producer_user_id
        )
    );
}

/**
 * Groupe rattaché à un adhérent (fixé par le bureau sur la page "Utilisateurs AMAP", voir
 * amap_set_member_group()) — au plus un groupe par adhérent pour l'instant, contrairement à
 * amap_get_producer_groups() qui en retourne plusieurs pour un producteur. Retourne null tant
 * qu'aucun groupe n'a été fixé.
 */
function amap_get_member_group( $member_user_id ) {
    global $wpdb;

    $group_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT group_id FROM {$wpdb->prefix}amap_group_members WHERE member_user_id = %d",
            $member_user_id
        )
    );

    return $group_id ? amap_get_group( $group_id ) : null;
}

/**
 * Fixe le groupe d'un adhérent, ou le retire si $group_id vaut 0 (ex. la casquette adhérent est
 * retirée du compte). Delete puis insert plutôt qu'un update : il n'existe jamais qu'une seule
 * ligne par adhérent (UNIQUE(member_user_id)), pas besoin de distinguer création/modification.
 */
function amap_set_member_group( $member_user_id, $group_id ) {
    global $wpdb;

    $wpdb->delete( $wpdb->prefix . 'amap_group_members', array( 'member_user_id' => $member_user_id ) );

    if ( $group_id ) {
        $wpdb->insert(
            $wpdb->prefix . 'amap_group_members',
            array(
                'group_id'       => $group_id,
                'member_user_id' => $member_user_id,
            )
        );
    }
}

/**
 * Les colonnes TIME de MySQL sont lues sous la forme "HH:MM:SS" par $wpdb : on ne garde que
 * "HH:MM", à la fois pour l'affichage dans le tableau et pour préremplir un <input type="time">.
 */
function amap_format_time( $time ) {
    return substr( $time, 0, 5 );
}

function amap_is_valid_time( $time ) {
    return (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time );
}

function amap_store_group_form_data( array $data ) {
    set_transient( 'amap_group_form_' . get_current_user_id(), $data, 60 );
}

function amap_render_groups_page() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        return;
    }

    $notice = isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '';

    // Mode édition : ?action=edit&id=X sur cette même page. Si l'ID ne correspond à aucun
    // groupe, on retombe silencieusement sur le formulaire d'ajout (même logique que la page
    // "Utilisateurs AMAP").
    $editing_id = 0;
    if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ) {
        $editing_id = absint( $_GET['id'] );
    }
    $editing_group = $editing_id ? amap_get_group( $editing_id ) : null;
    if ( $editing_id && ! $editing_group ) {
        $editing_id = 0;
    }

    $transient_key = 'amap_group_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_group ) {
        $form_data = array(
            'name'           => $editing_group->name,
            'delivery_place' => $editing_group->delivery_place,
            'weekday'        => (string) $editing_group->weekday,
            'start_time'     => amap_format_time( $editing_group->start_time ),
            'end_time'       => amap_format_time( $editing_group->end_time ),
        );
    } else {
        $form_data = array();
    }

    $groups = amap_get_groups();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Groupes de distribution', 'association-manager' ); ?></h1>

        <?php if ( 'invalid' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Champs obligatoires manquants.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_time' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( "L'heure de fin doit être après l'heure de début.", 'association-manager' ); ?></p></div>
        <?php endif; ?>

        <?php if ( ! $editing_id ) : ?>
            <p>
                <button type="button" class="button button-primary" id="amap-group-add-toggle"><?php esc_html_e( '+ Ajouter un groupe', 'association-manager' ); ?></button>
            </p>
        <?php endif; ?>
        <div id="amap-group-form-wrapper"<?php echo $editing_id ? '' : ' hidden'; ?>>
        <h2>
            <?php echo $editing_id
                ? esc_html__( 'Modifier un groupe', 'association-manager' )
                : esc_html__( 'Ajouter un groupe', 'association-manager' ); ?>
        </h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php if ( $editing_id ) : ?>
                <?php wp_nonce_field( 'amap_edit_group_' . $editing_id ); ?>
                <input type="hidden" name="action" value="amap_update_group">
                <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
            <?php else : ?>
                <?php wp_nonce_field( 'amap_add_group' ); ?>
                <input type="hidden" name="action" value="amap_add_group">
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><label for="amap-group-name"><?php esc_html_e( 'Nom', 'association-manager' ); ?></label></th>
                    <td><input type="text" id="amap-group-name" name="name" value="<?php echo esc_attr( $form_data['name'] ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="amap-group-delivery-place"><?php esc_html_e( 'Lieu de livraison', 'association-manager' ); ?></label></th>
                    <td><input type="text" id="amap-group-delivery-place" name="delivery_place" value="<?php echo esc_attr( $form_data['delivery_place'] ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="amap-group-weekday"><?php esc_html_e( 'Jour de la semaine', 'association-manager' ); ?></label></th>
                    <td>
                        <select id="amap-group-weekday" name="weekday" required>
                            <?php foreach ( amap_get_weekday_labels() as $weekday => $weekday_label ) : ?>
                                <option value="<?php echo esc_attr( $weekday ); ?>" <?php selected( (string) $weekday, $form_data['weekday'] ?? '' ); ?>>
                                    <?php echo esc_html( $weekday_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="amap-group-start-time"><?php esc_html_e( 'Heure de début', 'association-manager' ); ?></label></th>
                    <td><input type="time" id="amap-group-start-time" name="start_time" value="<?php echo esc_attr( $form_data['start_time'] ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="amap-group-end-time"><?php esc_html_e( 'Heure de fin', 'association-manager' ); ?></label></th>
                    <td><input type="time" id="amap-group-end-time" name="end_time" value="<?php echo esc_attr( $form_data['end_time'] ?? '' ); ?>" required></td>
                </tr>
            </table>
            <p>
                <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                <?php if ( $editing_id ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-groups' ) ); ?>" class="button">
                        <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                    </a>
                <?php else : ?>
                    <button type="button" class="button" id="amap-group-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                <?php endif; ?>
            </p>
        </form>
        </div>
        <script>
        ( function () {
            var toggle  = document.getElementById( 'amap-group-add-toggle' );
            var wrapper = document.getElementById( 'amap-group-form-wrapper' );
            var cancel  = document.getElementById( 'amap-group-add-cancel' );
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

        <?php if ( $editing_id ) : ?>
            <?php
            $producers              = amap_get_producer_users();
            $attached_producer_ids  = amap_get_group_producer_ids( $editing_id );
            ?>
            <h2><?php esc_html_e( 'Producteurs rattachés', 'association-manager' ); ?></h2>
            <?php if ( 'producers_updated' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Producteurs rattachés mis à jour.', 'association-manager' ); ?></p></div>
            <?php endif; ?>
            <?php if ( empty( $producers ) ) : ?>
                <p><?php esc_html_e( "Aucun compte producteur pour le moment.", 'association-manager' ); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'amap_update_group_producers_' . $editing_id ); ?>
                    <input type="hidden" name="action" value="amap_update_group_producers">
                    <input type="hidden" name="group_id" value="<?php echo esc_attr( $editing_id ); ?>">
                    <?php foreach ( $producers as $producer ) : ?>
                        <p>
                            <label>
                                <input
                                    type="checkbox"
                                    name="producer_ids[]"
                                    value="<?php echo esc_attr( $producer->ID ); ?>"
                                    <?php checked( in_array( (string) $producer->ID, $attached_producer_ids, true ) ); ?>
                                >
                                <?php echo esc_html( $producer->display_name ); ?>
                            </label>
                        </p>
                    <?php endforeach; ?>
                    <p>
                        <?php submit_button( __( 'Enregistrer les producteurs', 'association-manager' ), 'primary', 'submit', false ); ?>
                    </p>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( empty( $groups ) ) : ?>
            <p><?php esc_html_e( 'Aucun groupe enregistré pour le moment.', 'association-manager' ); ?></p>
        <?php else : ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Nom', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Lieu de livraison', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Jour', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Horaire', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $groups as $group ) : ?>
                        <?php $weekday_labels = amap_get_weekday_labels(); ?>
                        <tr>
                            <td><?php echo esc_html( $group->name ); ?></td>
                            <td><?php echo esc_html( $group->delivery_place ); ?></td>
                            <td><?php echo esc_html( $weekday_labels[ (int) $group->weekday ] ?? '' ); ?></td>
                            <td><?php echo esc_html( amap_format_time( $group->start_time ) . ' - ' . amap_format_time( $group->end_time ) ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group->id ) ); ?>">
                                    <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                </a>
                                |
                                <?php
                                $delete_url = wp_nonce_url(
                                    admin_url( 'admin-post.php?action=amap_delete_group&id=' . $group->id ),
                                    'amap_delete_group_' . $group->id
                                );
                                // translators: %s: nom du groupe.
                                $confirm_message = sprintf( __( 'Supprimer définitivement le groupe %s ?', 'association-manager' ), $group->name );
                                ?>
                                <a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm( '<?php echo esc_js( $confirm_message ); ?>' );">
                                    <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

add_action( 'admin_post_amap_add_group', 'amap_handle_add_group' );

function amap_handle_add_group() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_group' );

    $name           = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $delivery_place = isset( $_POST['delivery_place'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_place'] ) ) : '';
    $weekday        = isset( $_POST['weekday'] ) ? sanitize_key( wp_unslash( $_POST['weekday'] ) ) : '';
    $start_time     = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
    $end_time       = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
    $submitted      = compact( 'name', 'delivery_place', 'weekday', 'start_time', 'end_time' );

    if ( '' === $name || '' === $delivery_place || ! array_key_exists( (int) $weekday, amap_get_weekday_labels() )
        || ! amap_is_valid_time( $start_time ) || ! amap_is_valid_time( $end_time ) ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-groups&amap_notice=invalid' ) );
        exit;
    }

    if ( $start_time >= $end_time ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-groups&amap_notice=invalid_time' ) );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_groups',
        array(
            'name'           => $name,
            'delivery_place' => $delivery_place,
            'weekday'        => (int) $weekday,
            'start_time'     => $start_time,
            'end_time'       => $end_time,
        )
    );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-groups' ) );
    exit;
}

add_action( 'admin_post_amap_update_group', 'amap_handle_update_group' );

function amap_handle_update_group() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    if ( ! $id || ! amap_get_group( $id ) ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_edit_group_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $id );

    $name           = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $delivery_place = isset( $_POST['delivery_place'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_place'] ) ) : '';
    $weekday        = isset( $_POST['weekday'] ) ? sanitize_key( wp_unslash( $_POST['weekday'] ) ) : '';
    $start_time     = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
    $end_time       = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
    $submitted      = compact( 'name', 'delivery_place', 'weekday', 'start_time', 'end_time' );

    if ( '' === $name || '' === $delivery_place || ! array_key_exists( (int) $weekday, amap_get_weekday_labels() )
        || ! amap_is_valid_time( $start_time ) || ! amap_is_valid_time( $end_time ) ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid' );
        exit;
    }

    if ( $start_time >= $end_time ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid_time' );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_groups',
        array(
            'name'           => $name,
            'delivery_place' => $delivery_place,
            'weekday'        => (int) $weekday,
            'start_time'     => $start_time,
            'end_time'       => $end_time,
        ),
        array( 'id' => $id )
    );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-groups' ) );
    exit;
}

add_action( 'admin_post_amap_delete_group', 'amap_handle_delete_group' );

function amap_handle_delete_group() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    if ( ! $id || ! amap_get_group( $id ) ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_group_' . $id );

    global $wpdb;
    // Pas de contrainte FOREIGN KEY SQL sur group_id (cohérent avec le reste du plugin) : le
    // nettoyage des rattachements producteurs et adhérents orphelins, ainsi que des dates de
    // livraison de contrats déjà générées pour ce groupe, se fait explicitement ici.
    $wpdb->delete( $wpdb->prefix . 'amap_group_producers', array( 'group_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_group_members', array( 'group_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_contract_delivery_dates', array( 'group_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_groups', array( 'id' => $id ) );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-groups' ) );
    exit;
}

add_action( 'admin_post_amap_update_group_producers', 'amap_handle_update_group_producers' );

function amap_handle_update_group_producers() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    if ( ! $group_id || ! amap_get_group( $group_id ) ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_update_group_producers_' . $group_id );

    // Défense en profondeur : on ne garde que des ID correspondant réellement à un compte
    // portant la casquette amap_producer, même si le HTML du formulaire ne propose que ça.
    $valid_producer_ids = wp_list_pluck( amap_get_producer_users(), 'ID' );
    $submitted_ids      = isset( $_POST['producer_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['producer_ids'] ) ) : array();
    $producer_ids       = array_intersect( $submitted_ids, $valid_producer_ids );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_group_producers', array( 'group_id' => $group_id ) );
    foreach ( $producer_ids as $producer_id ) {
        $wpdb->insert(
            $wpdb->prefix . 'amap_group_producers',
            array(
                'group_id'         => $group_id,
                'producer_user_id' => $producer_id,
            )
        );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group_id . '&amap_notice=producers_updated' ) );
    exit;
}
