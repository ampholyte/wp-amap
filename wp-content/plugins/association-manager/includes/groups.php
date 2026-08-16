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

/**
 * URL de recherche Google Maps pour une adresse de livraison (wp_amap_groups.delivery_place) —
 * format "search" officiel d'URL Google Maps, sans clé API. Utilisée par le lien cliquable du
 * point de retrait dans l'espace membre.
 */
function amap_get_google_maps_url( $address ) {
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address );
}

/**
 * "3 sept.", "27 août" : jour + mois tronqué aux 4 premières lettres, avec un "." seulement sur
 * les mois réellement raccourcis (pas "mai"/"juin"/"mars"/"août", déjà ≤ 4 lettres) — évite les
 * cases de date à largeur/hauteur inégale selon la longueur du mois (espace membre : "Déclarer un
 * congé", "Souscrire à un contrat").
 */
function amap_get_short_date_label( $date ) {
    $timestamp   = strtotime( $date );
    $month_full  = date_i18n( 'F', $timestamp );
    $month_short = mb_substr( $month_full, 0, 4 );
    if ( mb_strlen( $month_full ) > 4 ) {
        $month_short .= '.';
    }

    return date_i18n( 'j', $timestamp ) . ' ' . $month_short;
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

/**
 * Vérifie qu'une date correspond bien à une distribution réelle de ce groupe : soit une date
 * normale sur son jour fixe et non concernée par une exception (annulée ou déplacée, la
 * distribution n'a alors pas lieu à cette date-là), soit la nouvelle date d'une exception
 * "déplacée" — les bénévoles suivent la distribution physique, pas le calendrier théorique,
 * contrairement à une exception elle-même (toujours ancrée sur le jour fixe, voir
 * amap_handle_add_distribution_exception()). Utilisée par
 * amap_handle_add_distribution_volunteer().
 */
function amap_group_distribution_date_is_valid( $group, $date ) {
    if ( (int) ( new DateTime( $date ) )->format( 'N' ) === ( (int) $group->weekday + 1 ) ) {
        return ! amap_get_group_distribution_exception_by_date( $group->id, $date );
    }

    global $wpdb;

    return (bool) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amap_distribution_exceptions
             WHERE group_id = %d AND exception_type = 'moved' AND new_date = %s",
            $group->id,
            $date
        )
    );
}

/**
 * Supprime les bénévoles déjà inscrits sur une date qui vient d'être annulée ou déplacée : sans
 * ça, ils restent inscrits sur une date où la distribution n'a plus lieu (voir
 * amap_handle_add_distribution_exception()/amap_handle_update_distribution_exception()).
 */
function amap_delete_distribution_volunteers_for_date( $group_id, $distribution_date ) {
    global $wpdb;

    $wpdb->delete(
        $wpdb->prefix . 'amap_distribution_volunteers',
        array(
            'group_id'          => $group_id,
            'distribution_date' => $distribution_date,
        )
    );
}

/**
 * Relance "bénévoles manquants", appelée quotidiennement par amap_run_daily_checks() (WP-Cron,
 * association-manager.php) : pour chaque groupe dont la distribution tombe dans 2 jours, envoie
 * une alerte à l'alias de notification du groupe si moins de 2 bénévoles sont inscrits (règle "2
 * à 3 par distribution", voir metier-producteurs.md). amap_get_weekday_dates_in_range() avec une
 * date de début/fin identique sert ici uniquement à vérifier si cette date tombe bien sur le jour
 * fixe du groupe. Une distribution déjà annulée n'a pas besoin de bénévoles, elle est donc
 * exclue ; une distribution déplacée reste concernée (le lieu/horaire change, pas le besoin de
 * bénévoles).
 */
function amap_check_missing_distribution_volunteers() {
    $target_date = ( new DateTime( current_time( 'Y-m-d' ) ) )->modify( '+2 days' )->format( 'Y-m-d' );

    foreach ( amap_get_groups() as $group ) {
        if ( empty( $group->notification_email ) ) {
            continue;
        }

        if ( ! amap_get_weekday_dates_in_range( $target_date, $target_date, (int) $group->weekday ) ) {
            continue;
        }

        $exception = amap_get_group_distribution_exception_by_date( $group->id, $target_date );
        if ( $exception && 'cancelled' === $exception->exception_type ) {
            continue;
        }

        if ( amap_count_group_distribution_volunteers( $group->id, $target_date ) >= 2 ) {
            continue;
        }

        if ( amap_group_had_volunteer_alert_sent( $group->id, $target_date ) ) {
            continue;
        }

        amap_send_missing_volunteers_alert( $group, $target_date );
        amap_mark_group_volunteer_alert_sent( $group->id, $target_date );
    }
}

/**
 * Garde contre un double envoi de la même alerte (group_id, distribution_date) si la tâche
 * planifiée s'exécute plus d'une fois le même jour — expiration à 3 jours, largement suffisante
 * pour ne plus être consultée une fois la distribution passée.
 */
function amap_group_had_volunteer_alert_sent( $group_id, $distribution_date ) {
    return (bool) get_transient( 'amap_volunteer_alert_' . $group_id . '_' . $distribution_date );
}

function amap_mark_group_volunteer_alert_sent( $group_id, $distribution_date ) {
    set_transient( 'amap_volunteer_alert_' . $group_id . '_' . $distribution_date, 1, 3 * DAY_IN_SECONDS );
}

/**
 * Envoie l'alerte à l'alias de notification du groupe — même canal et même principe qu'un seul
 * envoi par groupe que amap_notify_distribution_exception(), jamais une boucle sur chaque
 * adhérent.
 */
function amap_send_missing_volunteers_alert( $group, $distribution_date ) {
    $subject = sprintf(
        // translators: 1: nom du groupe, 2: date de la distribution concernée.
        __( 'Bénévoles manquants — %1$s du %2$s', 'association-manager' ),
        $group->name,
        date_i18n( 'j F Y', strtotime( $distribution_date ) )
    );

    $html_body  = '<p>' . sprintf(
        // translators: 1: nom du groupe, 2: date de la distribution concernée.
        esc_html__( 'Il manque des bénévoles pour tenir la distribution de %1$s du %2$s (dans 2 jours).', 'association-manager' ),
        esc_html( $group->name ),
        esc_html( date_i18n( 'j F Y', strtotime( $distribution_date ) ) )
    ) . '</p>';
    $html_body .= '<p>' . esc_html__( "Merci de trouver un ou deux adhérents supplémentaires pour l'assurer.", 'association-manager' ) . '</p>';

    amap_send_email( $group->notification_email, $subject, amap_render_email( $subject, $html_body ) );
}

add_action( 'admin_post_amap_add_group', 'amap_handle_add_group' );

function amap_handle_add_group() {
    if ( ! current_user_can( 'amap_manage_groups' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_group' );

    // Contrairement à wp-admin (formulaire d'ajout et liste sur la même page), la section
    // "Groupes" de l'espace bureau front a une page "Ajouter" séparée de la liste : une erreur
    // doit rouvrir CE formulaire ($add_url, déterministe), un succès repart vers la liste
    // ($list_url, fournie par le champ caché "redirect_to" du formulaire).
    $is_front_request = isset( $_POST['redirect_to'] );
    $add_url           = $is_front_request ? amap_get_board_group_add_url() : admin_url( 'admin.php?page=amap-groups' );
    $list_url          = $is_front_request ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $add_url;

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
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $add_url ) );
        exit;
    }

    if ( $start_time >= $end_time ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_time', $add_url ) );
        exit;
    }

    // Optionnelle : seule une valeur non vide et malformée est bloquante.
    if ( '' !== $notification_email && ! is_email( $notification_email ) ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_email', $add_url ) );
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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'created', $list_url ) );
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

    // Même principe que amap_handle_update_subscription() : la présence de "redirect_to"
    // distingue le formulaire front (espace bureau) de celui de wp-admin. $edit_url reste la page
    // "Modifier les infos" (où rester en cas d'erreur) ; $view_url est la page de retour en cas de
    // succès — la fiche du groupe côté front, la même page côté wp-admin (pas de fiche séparée).
    $is_front_request = isset( $_POST['redirect_to'] );
    $edit_url          = $is_front_request ? amap_get_board_group_edit_url( $id ) : admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $id );
    $view_url          = $is_front_request ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $edit_url;

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
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $edit_url ) );
        exit;
    }

    if ( $start_time >= $end_time ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_time', $edit_url ) );
        exit;
    }

    if ( '' !== $notification_email && ! is_email( $notification_email ) ) {
        amap_store_group_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_email', $edit_url ) );
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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'updated', $view_url ) );
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

    // Même principe que amap_handle_delete_user()/amap_handle_delete_subscription() : "Supprimer"
    // est un lien, pas un formulaire posté, la page de retour arrive donc en paramètre d'URL.
    $redirect_url = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-groups' );

    // Bloque plutôt que de supprimer en cascade : une souscription porte le group_id de son point
    // de retrait au moment de la signature (voir amap_create_tables()) — le supprimer laisserait
    // cette référence orpheline. Le bureau doit d'abord supprimer les souscriptions concernées
    // depuis la page "Souscriptions". Revalidé ici même si la page de confirmation front
    // (amap_get_board_group_delete_data()) l'a déjà fait, au cas où ce lien serait atteint
    // directement (favori, lien périmé).
    if ( amap_group_has_subscriptions( $id ) ) {
        wp_die( esc_html__( 'Suppression impossible : des souscriptions ont ce groupe comme point de retrait. Supprimez-les d\'abord depuis la page "Souscriptions".', 'association-manager' ) );
    }

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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'deleted', $redirect_url ) );
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

    // Même principe que amap_handle_update_subscription() : le champ caché "redirect_to" indique
    // l'URL de retour sur la fiche de CE groupe (front ou wp-admin).
    $view_url = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group_id );

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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'producers_updated', $view_url ) );
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

    // Même principe que amap_handle_add_leave() : le champ caché "redirect_to" indique la page
    // de retour sur CE groupe (front ou wp-admin).
    $edit_url = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group_id );

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
        wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_invalid', $edit_url ) );
        exit;
    }

    // Les distributions normales ne sont pas stockées ligne par ligne : la date doit tomber sur
    // le jour de semaine fixe du groupe (amap_groups.weekday), même principe que leave_date à
    // l'étape 8.
    if ( (int) ( new DateTime( $distribution_date ) )->format( 'N' ) !== ( (int) $group->weekday + 1 ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_invalid_weekday', $edit_url ) );
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
            wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_invalid_moved', $edit_url ) );
            exit;
        }
        $new_date = $has_new_date ? $new_date : null;

        if ( $has_new_time ) {
            if ( '' === $new_start_time || '' === $new_end_time
                || ! amap_is_valid_time( $new_start_time ) || ! amap_is_valid_time( $new_end_time )
                || $new_start_time >= $new_end_time ) {
                amap_store_distribution_exception_form_data( $submitted );
                wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_invalid_moved', $edit_url ) );
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
            wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_invalid_moved', $edit_url ) );
            exit;
        }
    }

    if ( amap_group_has_distribution_exception( $group_id, $distribution_date ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_duplicate', $edit_url ) );
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

    // La distribution normale n'a plus lieu à cette date (annulée, ou déplacée ailleurs) : les
    // bénévoles qui y étaient inscrits n'ont plus de distribution à tenir à cette date.
    amap_delete_distribution_volunteers_for_date( $group_id, $distribution_date );

    $new_exception = amap_get_distribution_exception( $wpdb->insert_id );
    if ( amap_notify_distribution_exception( $new_exception, $group, 'created' ) ) {
        $wpdb->update(
            $wpdb->prefix . 'amap_distribution_exceptions',
            array( 'notified_at' => current_time( 'mysql' ) ),
            array( 'id' => $new_exception->id )
        );
    }

    wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_saved', $edit_url ) );
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

    // Même principe que amap_handle_update_subscription() : "redirect_to" indique la page de
    // retour sur CE groupe (front ou wp-admin), utilisée en cas de succès ; $edit_notice_url reste
    // sur l'édition de cette exception (rouverte) en cas d'erreur.
    $edit_url = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group_id );

    $distribution_date  = isset( $_POST['distribution_date'] ) ? sanitize_text_field( wp_unslash( $_POST['distribution_date'] ) ) : '';
    $exception_type     = isset( $_POST['exception_type'] ) ? sanitize_key( wp_unslash( $_POST['exception_type'] ) ) : '';
    $new_date           = isset( $_POST['new_date'] ) ? sanitize_text_field( wp_unslash( $_POST['new_date'] ) ) : '';
    $new_start_time     = isset( $_POST['new_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['new_start_time'] ) ) : '';
    $new_end_time       = isset( $_POST['new_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['new_end_time'] ) ) : '';
    $new_place          = isset( $_POST['new_place'] ) ? sanitize_text_field( wp_unslash( $_POST['new_place'] ) ) : '';
    $reason             = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
    $submitted          = compact( 'distribution_date', 'exception_type', 'new_date', 'new_start_time', 'new_end_time', 'new_place', 'reason' );

    $edit_notice_url = add_query_arg(
        array(
            'exception_action' => 'edit',
            'exception_id'      => $id,
        ),
        $edit_url
    );

    if ( '' === $distribution_date || ! amap_is_valid_date( $distribution_date )
        || ! array_key_exists( $exception_type, amap_get_distribution_exception_type_labels() ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_invalid', $edit_notice_url ) );
        exit;
    }

    if ( (int) ( new DateTime( $distribution_date ) )->format( 'N' ) !== ( (int) $group->weekday + 1 ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_invalid_weekday', $edit_notice_url ) );
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
            wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_invalid_moved', $edit_notice_url ) );
            exit;
        }
        $new_date = $has_new_date ? $new_date : null;

        if ( $has_new_time ) {
            if ( '' === $new_start_time || '' === $new_end_time
                || ! amap_is_valid_time( $new_start_time ) || ! amap_is_valid_time( $new_end_time )
                || $new_start_time >= $new_end_time ) {
                amap_store_distribution_exception_form_data( $submitted );
                wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_invalid_moved', $edit_notice_url ) );
                exit;
            }
        } else {
            $new_start_time = null;
            $new_end_time   = null;
        }

        $new_place = $has_new_place ? $new_place : null;

        if ( ! $has_new_date && ! $has_new_time && ! $has_new_place ) {
            amap_store_distribution_exception_form_data( $submitted );
            wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_invalid_moved', $edit_notice_url ) );
            exit;
        }
    }

    if ( amap_group_has_distribution_exception( $group_id, $distribution_date, $id ) ) {
        amap_store_distribution_exception_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_duplicate', $edit_notice_url ) );
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

    // Même principe qu'à la création : la date (éventuellement modifiée par ce formulaire) n'a
    // plus de distribution normale, les bénévoles qui y étaient inscrits sont à retirer.
    amap_delete_distribution_volunteers_for_date( $group_id, $distribution_date );

    $updated_exception = amap_get_distribution_exception( $id );
    if ( amap_notify_distribution_exception( $updated_exception, $group, 'updated' ) ) {
        $wpdb->update(
            $wpdb->prefix . 'amap_distribution_exceptions',
            array( 'notified_at' => current_time( 'mysql' ) ),
            array( 'id' => $id )
        );
    }

    wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_saved', $edit_url ) );
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

    // Même principe que amap_handle_delete_leave() : lien, pas formulaire posté.
    $edit_url = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $exception->group_id );

    $group = amap_get_group( $exception->group_id );
    if ( $group ) {
        amap_notify_distribution_exception( $exception, $group, 'deleted' );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_distribution_exceptions', array( 'id' => $id ) );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'exception_deleted', $edit_url ) );
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

    // Même principe que amap_handle_add_leave() : le champ caché "redirect_to" indique la page de
    // retour sur CE groupe (front ou wp-admin).
    $edit_url = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group_id );

    $distribution_date = isset( $_POST['distribution_date'] ) ? sanitize_text_field( wp_unslash( $_POST['distribution_date'] ) ) : '';
    $member_user_id     = isset( $_POST['member_user_id'] ) ? absint( $_POST['member_user_id'] ) : 0;
    $submitted          = compact( 'distribution_date', 'member_user_id' );

    if ( '' === $distribution_date || ! amap_is_valid_date( $distribution_date ) || ! $member_user_id ) {
        amap_store_distribution_volunteer_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'volunteer_invalid', $edit_url ) );
        exit;
    }

    // Contrairement aux exceptions (toujours ancrées sur le jour fixe du groupe), un bénévole doit
    // pouvoir être inscrit sur la date réelle de la distribution : le jour habituel du groupe s'il
    // n'est pas concerné par une exception, ou la nouvelle date d'une distribution déplacée.
    if ( ! amap_group_distribution_date_is_valid( $group, $distribution_date ) ) {
        amap_store_distribution_volunteer_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'volunteer_invalid_weekday', $edit_url ) );
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
        wp_safe_redirect( add_query_arg( 'amap_notice', 'volunteer_duplicate', $edit_url ) );
        exit;
    }

    // Règle "2 à 3 bénévoles par distribution" (voir metier-producteurs.md) : seul le maximum de 3
    // est bloquant, le minimum de 2 restant purement informatif à l'affichage (badge "x/3" coloré,
    // voir member-area-board-group-view.php dans le thème).
    if ( amap_count_group_distribution_volunteers( $group_id, $distribution_date ) >= 3 ) {
        amap_store_distribution_volunteer_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'volunteer_full', $edit_url ) );
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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'volunteer_saved', $edit_url ) );
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

    // Même principe que amap_handle_delete_leave() : lien, pas formulaire posté.
    $edit_url = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $volunteer->group_id );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_distribution_volunteers', array( 'id' => $id ) );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'volunteer_deleted', $edit_url ) );
    exit;
}
