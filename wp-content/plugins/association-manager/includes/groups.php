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
 * Sens inverse de amap_get_member_group() : tous les adhérents dont ce groupe est le point de
 * retrait fixe. Sert à limiter le formulaire d'ajout d'un bénévole de distribution (étape 10) aux
 * adhérents réellement rattachés à ce groupe — voir metier-producteurs.md, "un adhérent ne
 * participe qu'aux distributions où son contrat est rattaché".
 */
function amap_get_group_member_users( $group_id ) {
    global $wpdb;

    $member_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT member_user_id FROM {$wpdb->prefix}amap_group_members WHERE group_id = %d",
            $group_id
        )
    );

    if ( empty( $member_ids ) ) {
        return array();
    }

    $user_query = new WP_User_Query(
        array(
            'include' => $member_ids,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        )
    );

    return $user_query->get_results();
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

function amap_get_distribution_exception_type_labels() {
    return array(
        'cancelled' => __( 'Annulée', 'association-manager' ),
        'moved'     => __( 'Déplacée', 'association-manager' ),
    );
}

function amap_get_distribution_exceptions( $group_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_distribution_exceptions WHERE group_id = %d ORDER BY distribution_date ASC",
            $group_id
        )
    );
}

function amap_get_distribution_exception( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_distribution_exceptions WHERE id = %d", $id )
    );
}

/**
 * UNIQUE(group_id, distribution_date) : une seule exception par distribution. $exclude_id permet
 * de revalider une modification sans se bloquer soi-même, même principe que
 * amap_contract_has_delivery_date().
 */
function amap_group_has_distribution_exception( $group_id, $distribution_date, $exclude_id = 0 ) {
    global $wpdb;

    $sql    = "SELECT COUNT(*) FROM {$wpdb->prefix}amap_distribution_exceptions WHERE group_id = %d AND distribution_date = %s";
    $params = array( $group_id, $distribution_date );

    if ( $exclude_id ) {
        $sql     .= ' AND id != %d';
        $params[] = $exclude_id;
    }

    return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
}

/**
 * Exception d'une distribution précise (couple group_id/distribution_date), ou null. Utilisée par
 * amap_get_group_next_distribution() pour savoir si la prochaine distribution calculée est
 * concernée par une annulation/un déplacement.
 */
function amap_get_group_distribution_exception_by_date( $group_id, $distribution_date ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_distribution_exceptions WHERE group_id = %d AND distribution_date = %s",
            $group_id,
            $distribution_date
        )
    );
}

/**
 * Prochaine distribution d'un groupe (aujourd'hui inclus), sur son jour fixe (`weekday`) — une
 * seule distribution par semaine et par groupe, pas de fréquence bimensuelle à ce niveau
 * (contrairement à un contrat basket_recurring, voir metier-producteurs.md). Utilisée pour
 * l'onglet "Espace producteur" de l'espace membre (member-area-producer.php).
 *
 * Ne cherche pas la prochaine date NON annulée en cas d'exception "cancelled" sur la date
 * calculée : une annulation reste un cas rare (metier-producteurs.md), simplement signalée sur la
 * date qu'elle concerne plutôt que masquée derrière une recherche en avant.
 *
 * `original_date` (toujours la date calculée sur le jour fixe du groupe, jamais remplacée par
 * `new_date`) sert à retrouver les commandes déjà enregistrées (dates de livraison product_grid,
 * échéancier basket_recurring), qui restent ancrées sur le calendrier normal même quand la
 * distribution est déplacée — `date` reste la date effective à afficher au producteur.
 */
function amap_get_group_next_distribution( $group ) {
    $today       = current_time( 'Y-m-d' );
    $week_ahead  = ( new DateTime( $today ) )->modify( '+6 days' )->format( 'Y-m-d' );
    $next_dates  = amap_get_weekday_dates_in_range( $today, $week_ahead, (int) $group->weekday );
    $date        = $next_dates[0];
    $exception   = amap_get_group_distribution_exception_by_date( $group->id, $date );

    if ( $exception && 'cancelled' === $exception->exception_type ) {
        return array(
            'date'          => $date,
            'original_date' => $date,
            'status'        => 'cancelled',
            'exception'     => $exception,
        );
    }

    if ( $exception && 'moved' === $exception->exception_type ) {
        return array(
            'date'          => $exception->new_date ?: $date,
            'original_date' => $date,
            'start_time'    => $exception->new_start_time ?: $group->start_time,
            'end_time'      => $exception->new_end_time ?: $group->end_time,
            'place'         => $exception->new_place ?: $group->delivery_place,
            'status'        => 'moved',
            'exception'     => $exception,
        );
    }

    return array(
        'date'          => $date,
        'original_date' => $date,
        'start_time'    => $group->start_time,
        'end_time'      => $group->end_time,
        'place'         => $group->delivery_place,
        'status'        => 'normal',
        'exception'     => null,
    );
}

function amap_store_distribution_exception_form_data( array $data ) {
    set_transient( 'amap_distribution_exception_form_' . get_current_user_id(), $data, 60 );
}

/**
 * Notifie l'adresse de notification du groupe ($group->notification_email, alias géré côté
 * bureau) d'une exception de distribution créée, modifiée ou supprimée — jamais une boucle
 * d'envois individuels à amap_get_group_member_users() : un seul email par exception, pour rester
 * sous la limite d'envois quotidiens de Brevo si plusieurs groupes ont une exception le même
 * jour. Ne fait rien si aucune adresse n'est configurée pour ce groupe. Retourne true si un envoi
 * a été tenté (indépendamment de son succès réel) afin que l'appelant sache s'il doit renseigner
 * notified_at, false sinon.
 */
function amap_notify_distribution_exception( $exception, $group, $event ) {
    if ( empty( $group->notification_email ) ) {
        return false;
    }

    $type_labels = amap_get_distribution_exception_type_labels();
    $type_label  = $type_labels[ $exception->exception_type ] ?? $exception->exception_type;

    if ( 'deleted' === $event ) {
        $subject = sprintf(
            // translators: 1: nom du groupe, 2: date de la distribution concernée.
            __( 'Retour à la normale — %1$s du %2$s', 'association-manager' ),
            $group->name,
            $exception->distribution_date
        );

        $html_body  = '<p>' . sprintf(
            // translators: 1: nom du groupe, 2: date de la distribution concernée.
            esc_html__( "L'exception précédemment annoncée pour la distribution de %1\$s du %2\$s a été annulée.", 'association-manager' ),
            esc_html( $group->name ),
            esc_html( $exception->distribution_date )
        ) . '</p>';
        $html_body .= '<p>' . esc_html__( "Cette distribution aura donc lieu normalement, au lieu et à l'horaire habituels du groupe.", 'association-manager' ) . '</p>';
    } else {
        $subject = sprintf(
            // translators: 1: libellé du type d'exception, 2: nom du groupe, 3: date concernée.
            __( 'Distribution %1$s — %2$s du %3$s', 'association-manager' ),
            $type_label,
            $group->name,
            $exception->distribution_date
        );

        $html_body = '<p>' . sprintf(
            // translators: 1: nom du groupe, 2: date concernée, 3: libellé du type (en minuscule).
            esc_html__( 'La distribution de %1$s du %2$s est %3$s.', 'association-manager' ),
            esc_html( $group->name ),
            esc_html( $exception->distribution_date ),
            esc_html( mb_strtolower( $type_label ) )
        ) . '</p>';

        if ( 'moved' === $exception->exception_type ) {
            $moved_parts = array();
            if ( $exception->new_date ) {
                $moved_parts[] = esc_html__( 'Nouvelle date', 'association-manager' ) . ' : ' . esc_html( $exception->new_date );
            }
            if ( $exception->new_start_time && $exception->new_end_time ) {
                $moved_parts[] = esc_html__( 'Nouvel horaire', 'association-manager' ) . ' : '
                    . esc_html( amap_format_time( $exception->new_start_time ) . '-' . amap_format_time( $exception->new_end_time ) );
            }
            if ( $exception->new_place ) {
                $moved_parts[] = esc_html__( 'Nouveau lieu', 'association-manager' ) . ' : ' . esc_html( $exception->new_place );
            }
            if ( ! empty( $moved_parts ) ) {
                $html_body .= '<ul><li>' . implode( '</li><li>', $moved_parts ) . '</li></ul>';
            }
        }

        if ( $exception->reason ) {
            $html_body .= '<p>' . esc_html__( 'Motif', 'association-manager' ) . ' : ' . esc_html( $exception->reason ) . '</p>';
        }
    }

    amap_send_email( $group->notification_email, $subject, amap_render_email( $subject, $html_body ) );

    return true;
}

function amap_get_distribution_volunteers( $group_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_distribution_volunteers WHERE group_id = %d ORDER BY distribution_date ASC, id ASC",
            $group_id
        )
    );
}

function amap_get_distribution_volunteer( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_distribution_volunteers WHERE id = %d", $id )
    );
}

/**
 * UNIQUE(group_id, distribution_date, member_user_id) : un adhérent ne peut être inscrit deux fois
 * sur la même distribution, revérifié en PHP avant la contrainte SQL.
 */
function amap_group_distribution_has_volunteer( $group_id, $distribution_date, $member_user_id ) {
    global $wpdb;

    return (bool) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amap_distribution_volunteers
             WHERE group_id = %d AND distribution_date = %s AND member_user_id = %d",
            $group_id,
            $distribution_date,
            $member_user_id
        )
    );
}

/**
 * Nombre de bénévoles déjà inscrits pour une distribution donnée — sert à bloquer un 4e inscrit
 * (règle "2 à 3 par distribution", voir metier-producteurs.md). Le minimum de 2 n'est jamais
 * bloquant, seul ce maximum l'est (voir amap_handle_add_distribution_volunteer()).
 */
function amap_count_group_distribution_volunteers( $group_id, $distribution_date ) {
    global $wpdb;

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amap_distribution_volunteers
             WHERE group_id = %d AND distribution_date = %s",
            $group_id,
            $distribution_date
        )
    );
}

/**
 * Nombre de distributions assurées par un adhérent sur une année civile donnée — purement
 * informatif (règle "au moins 3 par an", voir metier-producteurs.md) : jamais bloquant, ni pour
 * empêcher un ajout au-delà de 3, ni pour en forcer un en-deçà.
 */
function amap_count_member_distribution_volunteers_in_year( $member_user_id, $year ) {
    global $wpdb;

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amap_distribution_volunteers
             WHERE member_user_id = %d AND YEAR( distribution_date ) = %d",
            $member_user_id,
            $year
        )
    );
}

function amap_store_distribution_volunteer_form_data( array $data ) {
    set_transient( 'amap_distribution_volunteer_form_' . get_current_user_id(), $data, 60 );
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

    // Mode édition d'une exception : ?exception_action=edit&exception_id=Y, même principe que
    // size_action/size_id sur la page "Contrats". Ne peut être actif que si $editing_id est déjà
    // résolu (l'exception appartient forcément au groupe affiché).
    $exception_editing_id = 0;
    if ( $editing_id && isset( $_GET['exception_action'], $_GET['exception_id'] ) && 'edit' === $_GET['exception_action'] ) {
        $exception_editing_id = absint( $_GET['exception_id'] );
    }
    $editing_exception = $exception_editing_id ? amap_get_distribution_exception( $exception_editing_id ) : null;
    if ( $editing_exception && (int) $editing_exception->group_id !== $editing_id ) {
        $editing_exception   = null;
        $exception_editing_id = 0;
    }

    $exception_transient_key = 'amap_distribution_exception_form_' . get_current_user_id();
    $exception_form_data     = get_transient( $exception_transient_key );
    if ( false !== $exception_form_data ) {
        delete_transient( $exception_transient_key );
    } elseif ( $editing_exception ) {
        $exception_form_data = array(
            'distribution_date' => $editing_exception->distribution_date,
            'exception_type'    => $editing_exception->exception_type,
            'new_date'          => (string) $editing_exception->new_date,
            'new_start_time'    => $editing_exception->new_start_time ? amap_format_time( $editing_exception->new_start_time ) : '',
            'new_end_time'      => $editing_exception->new_end_time ? amap_format_time( $editing_exception->new_end_time ) : '',
            'new_place'         => (string) $editing_exception->new_place,
            'reason'            => (string) $editing_exception->reason,
        );
    } else {
        $exception_form_data = array();
    }

    $volunteer_transient_key = 'amap_distribution_volunteer_form_' . get_current_user_id();
    $volunteer_form_data     = get_transient( $volunteer_transient_key );
    if ( false !== $volunteer_form_data ) {
        delete_transient( $volunteer_transient_key );
    } else {
        $volunteer_form_data = array();
    }

    $transient_key = 'amap_group_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_group ) {
        $form_data = array(
            'name'                => $editing_group->name,
            'delivery_place'      => $editing_group->delivery_place,
            'weekday'             => (string) $editing_group->weekday,
            'start_time'          => amap_format_time( $editing_group->start_time ),
            'end_time'            => amap_format_time( $editing_group->end_time ),
            'notification_email'  => (string) $editing_group->notification_email,
        );
    } else {
        $form_data = array();
    }

    $groups_list_table = new Amap_Groups_List_Table();
    $groups_list_table->prepare_items();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Groupes de distribution', 'association-manager' ); ?></h1>

        <?php if ( 'invalid' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Champs obligatoires manquants.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_time' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( "L'heure de fin doit être après l'heure de début.", 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_email' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( "L'adresse de notification n'est pas une adresse email valide.", 'association-manager' ); ?></p></div>
        <?php endif; ?>

        <?php if ( ! $editing_id ) : ?>
            <p>
                <button type="button" class="button button-primary" id="amap-group-add-toggle"><?php esc_html_e( '+ Ajouter un groupe', 'association-manager' ); ?></button>
            </p>
        <?php endif; ?>
        <div id="amap-group-form-wrapper"<?php echo $editing_id ? '' : ' hidden'; ?>>
        <?php if ( $editing_id && $editing_group ) : ?>
            <?php $weekday_labels = amap_get_weekday_labels(); ?>
            <div id="amap-group-view">
                <table class="widefat">
                    <tbody>
                        <tr>
                            <th><?php esc_html_e( 'Nom', 'association-manager' ); ?></th>
                            <td><?php echo esc_html( $editing_group->name ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Lieu de livraison', 'association-manager' ); ?></th>
                            <td><?php echo esc_html( $editing_group->delivery_place ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Jour', 'association-manager' ); ?></th>
                            <td><?php echo esc_html( $weekday_labels[ (int) $editing_group->weekday ] ?? '' ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Horaire', 'association-manager' ); ?></th>
                            <td><?php echo esc_html( amap_format_time( $editing_group->start_time ) . ' - ' . amap_format_time( $editing_group->end_time ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Adresse de notification', 'association-manager' ); ?></th>
                            <td><?php echo esc_html( $editing_group->notification_email ? $editing_group->notification_email : '—' ); ?></td>
                        </tr>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="button button-primary" id="amap-group-edit-toggle"><?php esc_html_e( 'Modifier les infos', 'association-manager' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-groups' ) ); ?>" class="button">
                        <?php esc_html_e( 'Retour à la liste', 'association-manager' ); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>
        <div id="amap-group-edit-form"<?php echo $editing_id ? ' hidden' : ''; ?>>
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
                <tr>
                    <th><label for="amap-group-notification-email"><?php esc_html_e( 'Adresse de notification', 'association-manager' ); ?></label></th>
                    <td>
                        <input type="email" id="amap-group-notification-email" name="notification_email" value="<?php echo esc_attr( $form_data['notification_email'] ?? '' ); ?>">
                        <p class="description">
                            <?php esc_html_e( "Optionnelle. Adresse (ex. un alias créé par le bureau) qui recevra un récapitulatif en cas d'annulation ou de déplacement d'une distribution de ce groupe. Laissée vide, aucune notification ne sera envoyée aux adhérents.", 'association-manager' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p>
                <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                <?php if ( $editing_id ) : ?>
                    <button type="button" class="button" id="amap-group-edit-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                <?php else : ?>
                    <button type="button" class="button" id="amap-group-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                <?php endif; ?>
            </p>
        </form>
        </div>
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
        <script>
        ( function () {
            var viewBlock  = document.getElementById( 'amap-group-view' );
            var editForm   = document.getElementById( 'amap-group-edit-form' );
            var editToggle = document.getElementById( 'amap-group-edit-toggle' );
            var editCancel = document.getElementById( 'amap-group-edit-cancel' );
            if ( editToggle ) {
                editToggle.addEventListener( 'click', function () {
                    viewBlock.hidden = true;
                    editForm.hidden  = false;
                } );
            }
            if ( editCancel ) {
                editCancel.addEventListener( 'click', function () {
                    editForm.hidden  = true;
                    viewBlock.hidden = false;
                } );
            }
        } )();
        </script>

        <?php if ( $editing_id ) : ?>
            <style>
                .amap-group-section {
                    margin-bottom: 20px;
                    padding: 14px 18px;
                    background: #fff;
                    border: 1px solid #dcdcde;
                    border-radius: 4px;
                }
                .amap-group-section summary {
                    cursor: pointer;
                }
                .amap-group-section summary h2 {
                    display: inline-block;
                    margin: 0;
                }
                .amap-group-section[open] summary {
                    margin-bottom: 12px;
                }
            </style>
            <?php
            $producers              = amap_get_producer_users();
            $attached_producer_ids  = amap_get_group_producer_ids( $editing_id );
            ?>
            <details class="amap-group-section" id="amap-group-producers"<?php echo ( 'producers_updated' === $notice ) ? ' open' : ''; ?>>
                <summary><h2><?php esc_html_e( 'Producteurs rattachés', 'association-manager' ); ?></h2></summary>
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
            </details>

            <?php
            $distribution_exceptions = amap_get_distribution_exceptions( $editing_id );
            $exception_type_labels   = amap_get_distribution_exception_type_labels();
            // Reste ouverte si on est en train de modifier une exception (le formulaire d'édition
            // doit rester visible) ou si un message la concerne (retour après ajout/modification/
            // suppression) — jamais masquer un message pertinent derrière une section repliée.
            $exceptions_open = $exception_editing_id || ( 0 === strpos( (string) $notice, 'exception_' ) );
            ?>
            <details class="amap-group-section" id="amap-group-exceptions"<?php echo $exceptions_open ? ' open' : ''; ?>>
                <summary><h2><?php esc_html_e( 'Exceptions de distribution', 'association-manager' ); ?></h2></summary>
                <p class="description">
                    <?php esc_html_e( "Annulation ou déplacement ponctuel d'une distribution, décidé par le bureau. Ne concerne qu'une date précise : la distribution normale du groupe n'est pas affectée les autres semaines.", 'association-manager' ); ?>
                </p>
                <?php if ( empty( $editing_group->notification_email ) ) : ?>
                    <div class="notice notice-warning"><p>
                        <?php esc_html_e( "Aucune adresse de notification configurée pour ce groupe (voir « Modifier les infos » ci-dessus) : les adhérents ne seront pas prévenus d'une exception de distribution.", 'association-manager' ); ?>
                    </p></div>
                <?php endif; ?>
            <?php if ( 'exception_invalid' === $notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Champs obligatoires manquants ou invalides.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'exception_invalid_weekday' === $notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( "La date doit tomber sur le jour de semaine habituel de ce groupe.", 'association-manager' ); ?></p></div>
            <?php elseif ( 'exception_invalid_moved' === $notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( "Pour un déplacement, renseignez une nouvelle date, un nouvel horaire (les deux heures) ou un nouveau lieu, et une heure de fin après l'heure de début.", 'association-manager' ); ?></p></div>
            <?php elseif ( 'exception_duplicate' === $notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Une exception existe déjà pour ce groupe à cette date.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'exception_saved' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Exception de distribution enregistrée.', 'association-manager' ); ?></p></div>
            <?php elseif ( 'exception_deleted' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Exception de distribution supprimée.', 'association-manager' ); ?></p></div>
            <?php endif; ?>

            <?php if ( empty( $distribution_exceptions ) ) : ?>
                <p><?php esc_html_e( 'Aucune exception enregistrée pour ce groupe.', 'association-manager' ); ?></p>
            <?php else : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Distribution concernée', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Nouvelle date/horaire/lieu', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Motif', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Décidé par', 'association-manager' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $distribution_exceptions as $exception ) : ?>
                            <?php $decided_by_user = get_userdata( $exception->decided_by ); ?>
                            <tr>
                                <td><?php echo esc_html( $exception->distribution_date ); ?></td>
                                <td><?php echo esc_html( $exception_type_labels[ $exception->exception_type ] ?? $exception->exception_type ); ?></td>
                                <td>
                                    <?php if ( 'moved' === $exception->exception_type ) : ?>
                                        <?php
                                        $moved_parts = array();
                                        if ( $exception->new_date ) {
                                            $moved_parts[] = $exception->new_date;
                                        }
                                        if ( $exception->new_start_time && $exception->new_end_time ) {
                                            $moved_parts[] = amap_format_time( $exception->new_start_time ) . '-' . amap_format_time( $exception->new_end_time );
                                        }
                                        if ( $exception->new_place ) {
                                            $moved_parts[] = $exception->new_place;
                                        }
                                        echo esc_html( implode( ' · ', $moved_parts ) );
                                        ?>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $exception->reason ? $exception->reason : '—' ); ?></td>
                                <td><?php echo esc_html( $decided_by_user ? $decided_by_user->display_name : '—' ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $editing_id . '&exception_action=edit&exception_id=' . $exception->id ) ); ?>">
                                        <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                    </a>
                                    |
                                    <?php
                                    $delete_exception_url = wp_nonce_url(
                                        admin_url( 'admin-post.php?action=amap_delete_distribution_exception&id=' . $exception->id ),
                                        'amap_delete_distribution_exception_' . $exception->id
                                    );
                                    // translators: %s: date de la distribution concernée.
                                    $confirm_exception_message = sprintf( __( "Supprimer définitivement l'exception du %s ?", 'association-manager' ), $exception->distribution_date );
                                    ?>
                                    <a href="<?php echo esc_url( $delete_exception_url ); ?>" onclick="return confirm( '<?php echo esc_js( $confirm_exception_message ); ?>' );">
                                        <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( ! $exception_editing_id ) : ?>
                <p>
                    <button type="button" class="button button-primary" id="amap-exception-add-toggle"><?php esc_html_e( '+ Ajouter une exception', 'association-manager' ); ?></button>
                </p>
            <?php endif; ?>
            <div id="amap-exception-form-wrapper"<?php echo $exception_editing_id ? '' : ' hidden'; ?>>
            <h3>
                <?php echo $exception_editing_id
                    ? esc_html__( 'Modifier une exception', 'association-manager' )
                    : esc_html__( 'Ajouter une exception', 'association-manager' ); ?>
            </h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php if ( $exception_editing_id ) : ?>
                    <?php wp_nonce_field( 'amap_edit_distribution_exception_' . $exception_editing_id ); ?>
                    <input type="hidden" name="action" value="amap_update_distribution_exception">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $exception_editing_id ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_distribution_exception_' . $editing_id ); ?>
                    <input type="hidden" name="action" value="amap_add_distribution_exception">
                    <input type="hidden" name="group_id" value="<?php echo esc_attr( $editing_id ); ?>">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="amap-exception-distribution-date"><?php esc_html_e( 'Distribution concernée', 'association-manager' ); ?></label></th>
                        <td>
                            <input type="date" id="amap-exception-distribution-date" name="distribution_date" value="<?php echo esc_attr( $exception_form_data['distribution_date'] ?? '' ); ?>" required>
                            <p class="description"><?php esc_html_e( 'Doit tomber sur le jour de semaine habituel de ce groupe.', 'association-manager' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amap-exception-type"><?php esc_html_e( 'Type', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-exception-type" name="exception_type" required>
                                <?php foreach ( $exception_type_labels as $type_slug => $type_label ) : ?>
                                    <option value="<?php echo esc_attr( $type_slug ); ?>" <?php selected( $type_slug, $exception_form_data['exception_type'] ?? '' ); ?>>
                                        <?php echo esc_html( $type_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr id="amap-exception-new-date-row">
                        <th><label for="amap-exception-new-date"><?php esc_html_e( 'Nouvelle date', 'association-manager' ); ?></label></th>
                        <td><input type="date" id="amap-exception-new-date" name="new_date" value="<?php echo esc_attr( $exception_form_data['new_date'] ?? '' ); ?>"></td>
                    </tr>
                    <tr id="amap-exception-new-time-row">
                        <th><?php esc_html_e( 'Nouvel horaire', 'association-manager' ); ?></th>
                        <td>
                            <label for="amap-exception-new-start-time"><?php esc_html_e( 'De', 'association-manager' ); ?></label>
                            <input type="time" id="amap-exception-new-start-time" name="new_start_time" value="<?php echo esc_attr( $exception_form_data['new_start_time'] ?? '' ); ?>">
                            <label for="amap-exception-new-end-time"><?php esc_html_e( 'à', 'association-manager' ); ?></label>
                            <input type="time" id="amap-exception-new-end-time" name="new_end_time" value="<?php echo esc_attr( $exception_form_data['new_end_time'] ?? '' ); ?>">
                        </td>
                    </tr>
                    <tr id="amap-exception-new-place-row">
                        <th><label for="amap-exception-new-place"><?php esc_html_e( 'Nouveau lieu', 'association-manager' ); ?></label></th>
                        <td><input type="text" id="amap-exception-new-place" name="new_place" value="<?php echo esc_attr( $exception_form_data['new_place'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="amap-exception-reason"><?php esc_html_e( 'Motif', 'association-manager' ); ?></label></th>
                        <td><textarea id="amap-exception-reason" name="reason" rows="3" class="large-text"><?php echo esc_textarea( $exception_form_data['reason'] ?? '' ); ?></textarea></td>
                    </tr>
                </table>
                <p>
                    <?php submit_button( $exception_editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                    <?php if ( $exception_editing_id ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $editing_id ) ); ?>" class="button">
                            <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                        </a>
                    <?php else : ?>
                        <button type="button" class="button" id="amap-exception-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php endif; ?>
                </p>
            </form>
            </div>
            <script>
            ( function () {
                var typeField    = document.getElementById( 'amap-exception-type' );
                var newDateRow   = document.getElementById( 'amap-exception-new-date-row' );
                var newTimeRow   = document.getElementById( 'amap-exception-new-time-row' );
                var newPlaceRow  = document.getElementById( 'amap-exception-new-place-row' );

                function toggleMovedRows() {
                    var isMoved       = ( 'moved' === typeField.value );
                    newDateRow.hidden  = ! isMoved;
                    newTimeRow.hidden  = ! isMoved;
                    newPlaceRow.hidden = ! isMoved;
                }

                typeField.addEventListener( 'change', toggleMovedRows );
                toggleMovedRows();
            } )();
            </script>
            <script>
            ( function () {
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
            </details>

            <?php
            $distribution_volunteers = amap_get_distribution_volunteers( $editing_id );
            $volunteers_by_date      = array();
            foreach ( $distribution_volunteers as $volunteer ) {
                $volunteers_by_date[ $volunteer->distribution_date ][] = $volunteer;
            }
            $eligible_members = amap_get_group_member_users( $editing_id );
            $current_year     = (int) current_time( 'Y' );
            // Reste ouverte si un message concerne cette section (retour après ajout/suppression) —
            // jamais masquer un message pertinent derrière une section repliée.
            $volunteers_open = ( 0 === strpos( (string) $notice, 'volunteer_' ) );
            ?>
            <details class="amap-group-section" id="amap-group-volunteers"<?php echo $volunteers_open ? ' open' : ''; ?>>
                <summary><h2><?php esc_html_e( 'Bénévoles de distribution', 'association-manager' ); ?></h2></summary>
                <p class="description">
                    <?php esc_html_e( "Roster des adhérents bénévoles tenant une distribution (2 à 3 personnes, présentes 15 min avant et après). Chaque adhérent doit en assurer au moins 3 par an (indiqué entre parenthèses dans la liste ci-dessous, sans maximum). Distinct des souscriptions : concerne la présence à la distribution, pas la réception de produits.", 'association-manager' ); ?>
                </p>
                <?php if ( 'volunteer_invalid' === $notice ) : ?>
                    <div class="notice notice-error"><p><?php esc_html_e( 'Champs obligatoires manquants ou invalides.', 'association-manager' ); ?></p></div>
                <?php elseif ( 'volunteer_invalid_weekday' === $notice ) : ?>
                    <div class="notice notice-error"><p><?php esc_html_e( 'La date doit tomber sur le jour de semaine habituel de ce groupe.', 'association-manager' ); ?></p></div>
                <?php elseif ( 'volunteer_duplicate' === $notice ) : ?>
                    <div class="notice notice-error"><p><?php esc_html_e( 'Cet adhérent est déjà inscrit comme bénévole pour cette distribution.', 'association-manager' ); ?></p></div>
                <?php elseif ( 'volunteer_full' === $notice ) : ?>
                    <div class="notice notice-error"><p><?php esc_html_e( 'Cette distribution a déjà 3 bénévoles inscrits, maximum atteint.', 'association-manager' ); ?></p></div>
                <?php elseif ( 'volunteer_saved' === $notice ) : ?>
                    <div class="notice notice-success"><p><?php esc_html_e( 'Bénévole ajouté.', 'association-manager' ); ?></p></div>
                <?php elseif ( 'volunteer_deleted' === $notice ) : ?>
                    <div class="notice notice-success"><p><?php esc_html_e( 'Bénévole retiré.', 'association-manager' ); ?></p></div>
                <?php endif; ?>

                <?php if ( empty( $volunteers_by_date ) ) : ?>
                    <p><?php esc_html_e( 'Aucun bénévole enregistré pour ce groupe.', 'association-manager' ); ?></p>
                <?php else : ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Distribution', 'association-manager' ); ?></th>
                                <th><?php esc_html_e( 'Bénévoles', 'association-manager' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $volunteers_by_date as $distribution_date => $date_volunteers ) : ?>
                                <?php $volunteer_count = count( $date_volunteers ); ?>
                                <tr>
                                    <td><?php echo esc_html( $distribution_date ); ?></td>
                                    <td>
                                        <span style="color: <?php echo esc_attr( $volunteer_count < 2 ? '#d63638' : '#00a32a' ); ?>; font-weight: 600;">
                                            <?php
                                            // translators: %d: nombre de bénévoles déjà inscrits pour cette distribution (sur 3 maximum).
                                            echo esc_html( sprintf( __( '%d/3', 'association-manager' ), $volunteer_count ) );
                                            ?>
                                        </span>
                                        <ul style="margin: 4px 0 0;">
                                            <?php foreach ( $date_volunteers as $volunteer ) : ?>
                                                <?php $volunteer_user = get_userdata( $volunteer->member_user_id ); ?>
                                                <li>
                                                    <?php echo esc_html( $volunteer_user ? $volunteer_user->display_name : '#' . $volunteer->member_user_id ); ?>
                                                    —
                                                    <?php
                                                    $delete_volunteer_url = wp_nonce_url(
                                                        admin_url( 'admin-post.php?action=amap_delete_distribution_volunteer&id=' . $volunteer->id ),
                                                        'amap_delete_distribution_volunteer_' . $volunteer->id
                                                    );
                                                    ?>
                                                    <a href="<?php echo esc_url( $delete_volunteer_url ); ?>" onclick="return confirm( '<?php echo esc_js( __( 'Retirer ce bénévole de cette distribution ?', 'association-manager' ) ); ?>' );">
                                                        <?php esc_html_e( 'Retirer', 'association-manager' ); ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ( empty( $eligible_members ) ) : ?>
                    <p><?php esc_html_e( "Aucun adhérent rattaché à ce groupe comme point de retrait pour l'instant.", 'association-manager' ); ?></p>
                <?php else : ?>
                    <h3><?php esc_html_e( 'Ajouter un bénévole', 'association-manager' ); ?></h3>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'amap_add_distribution_volunteer_' . $editing_id ); ?>
                        <input type="hidden" name="action" value="amap_add_distribution_volunteer">
                        <input type="hidden" name="group_id" value="<?php echo esc_attr( $editing_id ); ?>">
                        <table class="form-table">
                            <tr>
                                <th><label for="amap-volunteer-date"><?php esc_html_e( 'Distribution concernée', 'association-manager' ); ?></label></th>
                                <td>
                                    <input type="date" id="amap-volunteer-date" name="distribution_date" value="<?php echo esc_attr( $volunteer_form_data['distribution_date'] ?? '' ); ?>" required>
                                    <p class="description"><?php esc_html_e( 'Doit tomber sur le jour de semaine habituel de ce groupe.', 'association-manager' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="amap-volunteer-member"><?php esc_html_e( 'Adhérent', 'association-manager' ); ?></label></th>
                                <td>
                                    <select id="amap-volunteer-member" name="member_user_id" required>
                                        <option value=""><?php esc_html_e( '— Choisir —', 'association-manager' ); ?></option>
                                        <?php foreach ( $eligible_members as $member ) : ?>
                                            <?php
                                            $member_year_count   = amap_count_member_distribution_volunteers_in_year( $member->ID, $current_year );
                                            $member_option_label = sprintf(
                                                // translators: 1: nom de l'adhérent, 2: nombre de distributions déjà assurées cette année (au moins 3 attendues, sans maximum).
                                                _n( '%1$s (%2$d distribution cette année)', '%1$s (%2$d distributions cette année)', $member_year_count, 'association-manager' ),
                                                $member->display_name,
                                                $member_year_count
                                            );
                                            ?>
                                            <option value="<?php echo esc_attr( $member->ID ); ?>" <?php selected( $member->ID, $volunteer_form_data['member_user_id'] ?? '' ); ?>>
                                                <?php echo esc_html( $member_option_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <p>
                            <?php submit_button( __( 'Ajouter un bénévole', 'association-manager' ), 'primary', 'submit', false ); ?>
                        </p>
                    </form>
                <?php endif; ?>
            </details>
        <?php endif; ?>

        <form method="get">
            <input type="hidden" name="page" value="amap-groups">
            <?php
            $groups_list_table->search_box( __( 'Rechercher', 'association-manager' ), 'amap-group' );
            $groups_list_table->display();
            ?>
        </form>
    </div>
    <?php
}

add_action( 'admin_post_amap_add_group', 'amap_handle_add_group' );

function amap_handle_add_group() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_group' );

    $name               = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $delivery_place     = isset( $_POST['delivery_place'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_place'] ) ) : '';
    $weekday            = isset( $_POST['weekday'] ) ? sanitize_key( wp_unslash( $_POST['weekday'] ) ) : '';
    $start_time         = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
    $end_time           = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
    $notification_email = isset( $_POST['notification_email'] ) ? sanitize_email( wp_unslash( $_POST['notification_email'] ) ) : '';
    $submitted          = compact( 'name', 'delivery_place', 'weekday', 'start_time', 'end_time', 'notification_email' );

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

    // Optionnelle : seule une valeur non vide et malformée est bloquante.
    if ( '' !== $notification_email && ! is_email( $notification_email ) ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-groups&amap_notice=invalid_email' ) );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_groups',
        array(
            'name'                => $name,
            'delivery_place'      => $delivery_place,
            'weekday'             => (int) $weekday,
            'start_time'          => $start_time,
            'end_time'            => $end_time,
            'notification_email'  => '' !== $notification_email ? $notification_email : null,
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

    $name               = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $delivery_place     = isset( $_POST['delivery_place'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_place'] ) ) : '';
    $weekday            = isset( $_POST['weekday'] ) ? sanitize_key( wp_unslash( $_POST['weekday'] ) ) : '';
    $start_time         = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
    $end_time           = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
    $notification_email = isset( $_POST['notification_email'] ) ? sanitize_email( wp_unslash( $_POST['notification_email'] ) ) : '';
    $submitted          = compact( 'name', 'delivery_place', 'weekday', 'start_time', 'end_time', 'notification_email' );

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

    if ( '' !== $notification_email && ! is_email( $notification_email ) ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid_email' );
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_groups',
        array(
            'name'                => $name,
            'delivery_place'      => $delivery_place,
            'weekday'             => (int) $weekday,
            'start_time'          => $start_time,
            'end_time'            => $end_time,
            'notification_email'  => '' !== $notification_email ? $notification_email : null,
        ),
        array( 'id' => $id )
    );

    wp_safe_redirect( $edit_url );
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
    // nettoyage des rattachements producteurs et adhérents orphelins, des dates de livraison de
    // contrats déjà générées, des exceptions de distribution et du roster de bénévoles pour ce
    // groupe se fait explicitement ici.
    $wpdb->delete( $wpdb->prefix . 'amap_group_producers', array( 'group_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_group_members', array( 'group_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_contract_delivery_dates', array( 'group_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_distribution_exceptions', array( 'group_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_distribution_volunteers', array( 'group_id' => $id ) );
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

add_action( 'admin_post_amap_add_distribution_exception', 'amap_handle_add_distribution_exception' );

/**
 * Annulation/déplacement ponctuel d'une distribution (voir metier-producteurs.md), ajoutée depuis
 * la section "Exceptions de distribution" nichée dans la page "Groupes" — jamais un nouveau menu
 * ni une nouvelle capability, même logique que "Producteurs rattachés". decided_by n'est jamais lu
 * du POST : c'est toujours le membre du bureau actuellement connecté qui crée la ligne.
 */
function amap_handle_add_distribution_exception() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $group    = $group_id ? amap_get_group( $group_id ) : null;
    if ( ! $group ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_distribution_exception_' . $group_id );

    $edit_url = admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group_id );

    $distribution_date  = isset( $_POST['distribution_date'] ) ? sanitize_text_field( wp_unslash( $_POST['distribution_date'] ) ) : '';
    $exception_type     = isset( $_POST['exception_type'] ) ? sanitize_key( wp_unslash( $_POST['exception_type'] ) ) : '';
    $new_date           = isset( $_POST['new_date'] ) ? sanitize_text_field( wp_unslash( $_POST['new_date'] ) ) : '';
    $new_start_time     = isset( $_POST['new_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['new_start_time'] ) ) : '';
    $new_end_time       = isset( $_POST['new_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['new_end_time'] ) ) : '';
    $new_place          = isset( $_POST['new_place'] ) ? sanitize_text_field( wp_unslash( $_POST['new_place'] ) ) : '';
    $reason             = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
    $submitted          = compact( 'distribution_date', 'exception_type', 'new_date', 'new_start_time', 'new_end_time', 'new_place', 'reason' );

    if ( '' === $distribution_date || ! amap_is_valid_date( $distribution_date )
        || ! array_key_exists( $exception_type, amap_get_distribution_exception_type_labels() ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=exception_invalid' );
        exit;
    }

    // Les distributions normales ne sont pas stockées ligne par ligne : la date doit tomber sur
    // le jour de semaine fixe du groupe (amap_groups.weekday), même principe que leave_date à
    // l'étape 8.
    if ( (int) ( new DateTime( $distribution_date ) )->format( 'N' ) !== ( (int) $group->weekday + 1 ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=exception_invalid_weekday' );
        exit;
    }

    if ( 'cancelled' === $exception_type ) {
        // Une distribution annulée n'a ni nouvelle date, ni nouvel horaire, ni nouveau lieu :
        // jamais lu du POST en confiance, même si le JS masque déjà ces champs.
        $new_date       = null;
        $new_start_time = null;
        $new_end_time   = null;
        $new_place      = null;
    } else {
        $has_new_date  = ( '' !== $new_date );
        $has_new_time  = ( '' !== $new_start_time || '' !== $new_end_time );
        $has_new_place = ( '' !== $new_place );

        if ( $has_new_date && ! amap_is_valid_date( $new_date ) ) {
            amap_store_distribution_exception_form_data( $submitted );
            wp_safe_redirect( $edit_url . '&amap_notice=exception_invalid_moved' );
            exit;
        }
        $new_date = $has_new_date ? $new_date : null;

        if ( $has_new_time ) {
            if ( '' === $new_start_time || '' === $new_end_time
                || ! amap_is_valid_time( $new_start_time ) || ! amap_is_valid_time( $new_end_time )
                || $new_start_time >= $new_end_time ) {
                amap_store_distribution_exception_form_data( $submitted );
                wp_safe_redirect( $edit_url . '&amap_notice=exception_invalid_moved' );
                exit;
            }
        } else {
            $new_start_time = null;
            $new_end_time   = null;
        }

        $new_place = $has_new_place ? $new_place : null;

        // Un déplacement doit changer au moins une chose (date, horaire ou lieu), sinon ce n'est
        // pas une exception à la distribution normale du groupe.
        if ( ! $has_new_date && ! $has_new_time && ! $has_new_place ) {
            amap_store_distribution_exception_form_data( $submitted );
            wp_safe_redirect( $edit_url . '&amap_notice=exception_invalid_moved' );
            exit;
        }
    }

    if ( amap_group_has_distribution_exception( $group_id, $distribution_date ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=exception_duplicate' );
        exit;
    }

    $reason = '' !== $reason ? $reason : null;

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_distribution_exceptions',
        array(
            'group_id'          => $group_id,
            'distribution_date' => $distribution_date,
            'exception_type'    => $exception_type,
            'new_date'          => $new_date,
            'new_start_time'    => $new_start_time,
            'new_end_time'      => $new_end_time,
            'new_place'         => $new_place,
            'reason'            => $reason,
            'decided_by'        => get_current_user_id(),
        )
    );

    $new_exception = amap_get_distribution_exception( $wpdb->insert_id );
    if ( amap_notify_distribution_exception( $new_exception, $group, 'created' ) ) {
        $wpdb->update(
            $wpdb->prefix . 'amap_distribution_exceptions',
            array( 'notified_at' => current_time( 'mysql' ) ),
            array( 'id' => $new_exception->id )
        );
    }

    wp_safe_redirect( $edit_url . '&amap_notice=exception_saved' );
    exit;
}

add_action( 'admin_post_amap_update_distribution_exception', 'amap_handle_update_distribution_exception' );

function amap_handle_update_distribution_exception() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id        = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $exception = $id ? amap_get_distribution_exception( $id ) : null;
    if ( ! $exception ) {
        wp_die( esc_html__( 'Exception introuvable.', 'association-manager' ) );
    }

    // group_id n'est jamais lu du POST : une exception reste rattachée au groupe où elle a été
    // créée, seul son contenu (date, type, nouvel horaire/lieu, motif) est modifiable.
    $group_id = (int) $exception->group_id;
    $group    = amap_get_group( $group_id );
    if ( ! $group ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_edit_distribution_exception_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group_id );

    $distribution_date  = isset( $_POST['distribution_date'] ) ? sanitize_text_field( wp_unslash( $_POST['distribution_date'] ) ) : '';
    $exception_type     = isset( $_POST['exception_type'] ) ? sanitize_key( wp_unslash( $_POST['exception_type'] ) ) : '';
    $new_date           = isset( $_POST['new_date'] ) ? sanitize_text_field( wp_unslash( $_POST['new_date'] ) ) : '';
    $new_start_time     = isset( $_POST['new_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['new_start_time'] ) ) : '';
    $new_end_time       = isset( $_POST['new_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['new_end_time'] ) ) : '';
    $new_place          = isset( $_POST['new_place'] ) ? sanitize_text_field( wp_unslash( $_POST['new_place'] ) ) : '';
    $reason             = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
    $submitted          = compact( 'distribution_date', 'exception_type', 'new_date', 'new_start_time', 'new_end_time', 'new_place', 'reason' );

    $edit_notice_url = $edit_url . '&exception_action=edit&exception_id=' . $id;

    if ( '' === $distribution_date || ! amap_is_valid_date( $distribution_date )
        || ! array_key_exists( $exception_type, amap_get_distribution_exception_type_labels() ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( $edit_notice_url . '&amap_notice=exception_invalid' );
        exit;
    }

    if ( (int) ( new DateTime( $distribution_date ) )->format( 'N' ) !== ( (int) $group->weekday + 1 ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( $edit_notice_url . '&amap_notice=exception_invalid_weekday' );
        exit;
    }

    if ( 'cancelled' === $exception_type ) {
        $new_date       = null;
        $new_start_time = null;
        $new_end_time   = null;
        $new_place      = null;
    } else {
        $has_new_date  = ( '' !== $new_date );
        $has_new_time  = ( '' !== $new_start_time || '' !== $new_end_time );
        $has_new_place = ( '' !== $new_place );

        if ( $has_new_date && ! amap_is_valid_date( $new_date ) ) {
            amap_store_distribution_exception_form_data( $submitted );
            wp_safe_redirect( $edit_notice_url . '&amap_notice=exception_invalid_moved' );
            exit;
        }
        $new_date = $has_new_date ? $new_date : null;

        if ( $has_new_time ) {
            if ( '' === $new_start_time || '' === $new_end_time
                || ! amap_is_valid_time( $new_start_time ) || ! amap_is_valid_time( $new_end_time )
                || $new_start_time >= $new_end_time ) {
                amap_store_distribution_exception_form_data( $submitted );
                wp_safe_redirect( $edit_notice_url . '&amap_notice=exception_invalid_moved' );
                exit;
            }
        } else {
            $new_start_time = null;
            $new_end_time   = null;
        }

        $new_place = $has_new_place ? $new_place : null;

        if ( ! $has_new_date && ! $has_new_time && ! $has_new_place ) {
            amap_store_distribution_exception_form_data( $submitted );
            wp_safe_redirect( $edit_notice_url . '&amap_notice=exception_invalid_moved' );
            exit;
        }
    }

    if ( amap_group_has_distribution_exception( $group_id, $distribution_date, $id ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( $edit_notice_url . '&amap_notice=exception_duplicate' );
        exit;
    }

    $reason = '' !== $reason ? $reason : null;

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_distribution_exceptions',
        array(
            'distribution_date' => $distribution_date,
            'exception_type'    => $exception_type,
            'new_date'          => $new_date,
            'new_start_time'    => $new_start_time,
            'new_end_time'      => $new_end_time,
            'new_place'         => $new_place,
            'reason'            => $reason,
        ),
        array( 'id' => $id )
    );

    $updated_exception = amap_get_distribution_exception( $id );
    if ( amap_notify_distribution_exception( $updated_exception, $group, 'updated' ) ) {
        $wpdb->update(
            $wpdb->prefix . 'amap_distribution_exceptions',
            array( 'notified_at' => current_time( 'mysql' ) ),
            array( 'id' => $id )
        );
    }

    wp_safe_redirect( $edit_url . '&amap_notice=exception_saved' );
    exit;
}

add_action( 'admin_post_amap_delete_distribution_exception', 'amap_handle_delete_distribution_exception' );

function amap_handle_delete_distribution_exception() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id        = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $exception = $id ? amap_get_distribution_exception( $id ) : null;
    if ( ! $exception ) {
        wp_die( esc_html__( 'Exception introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_distribution_exception_' . $id );

    $group = amap_get_group( $exception->group_id );
    if ( $group ) {
        amap_notify_distribution_exception( $exception, $group, 'deleted' );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_distribution_exceptions', array( 'id' => $id ) );

    wp_safe_redirect(
        admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $exception->group_id . '&amap_notice=exception_deleted' )
    );
    exit;
}

add_action( 'admin_post_amap_add_distribution_volunteer', 'amap_handle_add_distribution_volunteer' );

/**
 * Ajout d'un bénévole au roster d'une distribution (voir metier-producteurs.md : 2 à 3 bénévoles
 * par distribution, chacun devant en assurer au moins 3 par an). Nichée dans la page "Groupes",
 * même logique que amap_handle_add_distribution_exception().
 */
function amap_handle_add_distribution_volunteer() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $group    = $group_id ? amap_get_group( $group_id ) : null;
    if ( ! $group ) {
        wp_die( esc_html__( 'Groupe introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_distribution_volunteer_' . $group_id );

    $edit_url = admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group_id );

    $distribution_date = isset( $_POST['distribution_date'] ) ? sanitize_text_field( wp_unslash( $_POST['distribution_date'] ) ) : '';
    $member_user_id     = isset( $_POST['member_user_id'] ) ? absint( $_POST['member_user_id'] ) : 0;
    $submitted          = compact( 'distribution_date', 'member_user_id' );

    if ( '' === $distribution_date || ! amap_is_valid_date( $distribution_date ) || ! $member_user_id ) {
        amap_store_distribution_volunteer_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=volunteer_invalid' );
        exit;
    }

    // Les distributions normales ne sont pas stockées ligne par ligne : la date doit tomber sur le
    // jour de semaine fixe du groupe, même principe que les exceptions de distribution.
    if ( (int) ( new DateTime( $distribution_date ) )->format( 'N' ) !== ( (int) $group->weekday + 1 ) ) {
        amap_store_distribution_volunteer_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=volunteer_invalid_weekday' );
        exit;
    }

    // Défense en profondeur : un adhérent n'est éligible que s'il est rattaché à CE groupe comme
    // point de retrait (amap_get_group_member_users()), jamais lu du POST en confiance même si le
    // formulaire ne propose que ça.
    $valid_member_ids = wp_list_pluck( amap_get_group_member_users( $group_id ), 'ID' );
    if ( ! in_array( $member_user_id, $valid_member_ids, true ) ) {
        wp_die( esc_html__( 'Adhérent introuvable pour ce groupe.', 'association-manager' ) );
    }

    if ( amap_group_distribution_has_volunteer( $group_id, $distribution_date, $member_user_id ) ) {
        amap_store_distribution_volunteer_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=volunteer_duplicate' );
        exit;
    }

    // Règle "2 à 3 bénévoles par distribution" (voir metier-producteurs.md) : seul le maximum de 3
    // est bloquant, le minimum de 2 restant purement informatif à l'affichage (voir
    // amap_render_groups_page()).
    if ( amap_count_group_distribution_volunteers( $group_id, $distribution_date ) >= 3 ) {
        amap_store_distribution_volunteer_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=volunteer_full' );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_distribution_volunteers',
        array(
            'group_id'          => $group_id,
            'distribution_date' => $distribution_date,
            'member_user_id'    => $member_user_id,
        )
    );

    wp_safe_redirect( $edit_url . '&amap_notice=volunteer_saved' );
    exit;
}

add_action( 'admin_post_amap_delete_distribution_volunteer', 'amap_handle_delete_distribution_volunteer' );

function amap_handle_delete_distribution_volunteer() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id        = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $volunteer = $id ? amap_get_distribution_volunteer( $id ) : null;
    if ( ! $volunteer ) {
        wp_die( esc_html__( 'Bénévole introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_distribution_volunteer_' . $id );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_distribution_volunteers', array( 'id' => $id ) );

    wp_safe_redirect(
        admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $volunteer->group_id . '&amap_notice=volunteer_deleted' )
    );
    exit;
}
