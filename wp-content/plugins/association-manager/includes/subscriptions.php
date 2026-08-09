<?php
/**
 * Page d'admin "Souscriptions" : CRUD des souscriptions adhérent<->contrat.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function amap_get_member_users() {
    $user_query = new WP_User_Query(
        array(
            'role'    => 'amap_member',
            'orderby' => 'display_name',
            'order'   => 'ASC',
        )
    );

    return $user_query->get_results();
}

function amap_get_subscriptions() {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}amap_subscriptions ORDER BY signed_at DESC"
    );
}

function amap_get_subscription( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_subscriptions WHERE id = %d", $id )
    );
}

function amap_store_subscription_form_data( array $data ) {
    set_transient( 'amap_subscription_form_' . get_current_user_id(), $data, 60 );
}

function amap_get_subscription_items( $subscription_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_subscription_items WHERE subscription_id = %d",
            $subscription_id
        )
    );
}

function amap_get_leaves( $subscription_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_leaves WHERE subscription_id = %d ORDER BY leave_date ASC",
            $subscription_id
        )
    );
}

function amap_get_leave( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_leaves WHERE id = %d", $id )
    );
}

/**
 * Revérifie côté PHP la contrainte UNIQUE(subscription_id, leave_date), même principe que
 * amap_contract_has_delivery_date().
 */
function amap_subscription_has_leave( $subscription_id, $leave_date ) {
    global $wpdb;

    return (bool) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amap_leaves WHERE subscription_id = %d AND leave_date = %s",
            $subscription_id,
            $leave_date
        )
    );
}

/**
 * Souscriptions basket_recurring attendues à une distribution, pour un groupe et une date de
 * distribution donnés (date "brute" du jour fixe du groupe —
 * amap_get_group_next_distribution(), étape 12.2). Retourne null si cette date ne fait pas partie
 * de l'échéancier du contrat (contrat bimensuel hors semaine de livraison) :
 * amap_get_weekday_dates_in_range() revalidée avec frequency_weeks, même principe que les congés
 * (étape 8c). Sinon, un tableau (potentiellement vide) d'entrées { member, basket_size,
 * subscription }, un adhérent en congé ce jour-là étant exclu. Sert de base à l'agrégat
 * (amap_get_contract_baskets_to_deliver()) affiché dans "Produits à livrer".
 */
function amap_get_contract_basket_subscribers( $contract, $group, $distribution_date ) {
    global $wpdb;

    $delivery_dates = amap_get_weekday_dates_in_range(
        $contract->start_date,
        $contract->end_date,
        (int) $group->weekday,
        (int) $contract->frequency_weeks
    );
    if ( ! in_array( $distribution_date, $delivery_dates, true ) ) {
        return null;
    }

    $subscriptions = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_subscriptions WHERE contract_id = %d AND group_id = %d",
            $contract->id,
            $group->id
        )
    );

    $result = array();
    foreach ( $subscriptions as $subscription ) {
        if ( amap_subscription_has_leave( $subscription->id, $distribution_date ) ) {
            continue;
        }
        $result[] = array(
            'member'       => get_user_by( 'id', $subscription->member_user_id ),
            'basket_size'  => amap_get_contract_basket_size( $subscription->basket_size_id ),
            'subscription' => $subscription,
        );
    }

    return $result;
}

/**
 * Paniers à livrer par un contrat basket_recurring, agrégés par taille — voir
 * amap_get_contract_basket_subscribers() pour le détail des règles (échéancier, congés).
 */
function amap_get_contract_baskets_to_deliver( $contract, $group, $distribution_date ) {
    $subscribers = amap_get_contract_basket_subscribers( $contract, $group, $distribution_date );
    if ( null === $subscribers ) {
        return null;
    }

    $counts = array();
    foreach ( $subscribers as $entry ) {
        if ( ! $entry['basket_size'] ) {
            continue;
        }
        $size_id = $entry['basket_size']->id;
        if ( ! isset( $counts[ $size_id ] ) ) {
            $counts[ $size_id ] = array(
                'basket_size' => $entry['basket_size'],
                'count'       => 0,
            );
        }
        ++$counts[ $size_id ]['count'];
    }

    return array_values( $counts );
}

/**
 * Souscriptions product_grid attendues à une distribution, pour un groupe et une date de
 * distribution donnés (date "brute", voir amap_get_contract_basket_subscribers() ci-dessus).
 * Retourne null si aucune date de livraison n'est enregistrée pour ce couple (contrat, groupe) à
 * cette date exacte (amap_get_contract_delivery_date_by_date()). Sinon, un tableau
 * (potentiellement vide) d'entrées { member, items: [{ product, quantity }], subscription } — une
 * entrée par adhérent ayant au moins une commande sur cette date. Sert de base à l'agrégat
 * (amap_get_contract_products_to_deliver()) affiché dans "Produits à livrer".
 */
function amap_get_contract_product_subscribers( $contract, $group, $distribution_date ) {
    global $wpdb;

    $delivery_date_row = amap_get_contract_delivery_date_by_date( $contract->id, $group->id, $distribution_date );
    if ( ! $delivery_date_row ) {
        return null;
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT si.subscription_id, si.contract_product_id, si.quantity
             FROM {$wpdb->prefix}amap_subscription_items si
             INNER JOIN {$wpdb->prefix}amap_subscriptions s ON s.id = si.subscription_id
             WHERE s.contract_id = %d AND s.group_id = %d AND si.contract_delivery_date_id = %d
             ORDER BY si.subscription_id",
            $contract->id,
            $group->id,
            $delivery_date_row->id
        )
    );

    $by_subscription = array();
    foreach ( $rows as $row ) {
        $product = amap_get_contract_product( (int) $row->contract_product_id );
        if ( ! $product ) {
            continue;
        }

        $subscription_id = (int) $row->subscription_id;
        if ( ! isset( $by_subscription[ $subscription_id ] ) ) {
            $by_subscription[ $subscription_id ] = array(
                'subscription' => amap_get_subscription( $subscription_id ),
                'items'        => array(),
            );
        }
        $by_subscription[ $subscription_id ]['items'][] = array(
            'product'  => $product,
            'quantity' => (int) $row->quantity,
        );
    }

    $result = array();
    foreach ( $by_subscription as $entry ) {
        $result[] = array(
            'member'       => get_user_by( 'id', $entry['subscription']->member_user_id ),
            'items'        => $entry['items'],
            'subscription' => $entry['subscription'],
        );
    }

    return $result;
}

/**
 * Produits à livrer par un contrat product_grid, agrégés tous adhérents confondus — voir
 * amap_get_contract_product_subscribers() pour le détail des règles.
 */
function amap_get_contract_products_to_deliver( $contract, $group, $distribution_date ) {
    $subscribers = amap_get_contract_product_subscribers( $contract, $group, $distribution_date );
    if ( null === $subscribers ) {
        return null;
    }

    $totals = array();
    foreach ( $subscribers as $entry ) {
        foreach ( $entry['items'] as $item ) {
            $product_id = $item['product']->id;
            if ( ! isset( $totals[ $product_id ] ) ) {
                $totals[ $product_id ] = array(
                    'product'  => $item['product'],
                    'quantity' => 0,
                );
            }
            $totals[ $product_id ]['quantity'] += $item['quantity'];
        }
    }

    return array_values( $totals );
}

/**
 * Paniers/produits à livrer par un producteur, pour un groupe et une date de distribution donnés
 * — une entrée par contrat concerné, jamais fusionnées entre contrats même en cas de libellés
 * identiques (aucun lien fiable entre tailles/produits de contrats différents en base). Seuls les
 * contrats dont la période est "en cours" aujourd'hui (amap_get_contract_period_status()) sont
 * pris en compte, et seulement s'ils ont effectivement quelque chose à livrer ce jour-là : un
 * contrat bimensuel hors semaine de livraison, ou sans aucune commande, est silencieusement
 * absent du résultat plutôt qu'affiché avec "0".
 */
function amap_get_group_deliveries( $group, array $producer_contracts, $distribution_date ) {
    $deliveries = array();

    foreach ( $producer_contracts as $contract ) {
        if ( 'active' !== amap_get_contract_period_status( $contract ) ) {
            continue;
        }

        if ( 'basket_recurring' === $contract->contract_type ) {
            $entries = amap_get_contract_baskets_to_deliver( $contract, $group, $distribution_date );
            $items   = $entries ? array_map(
                static function ( $entry ) {
                    return array(
                        'label'    => $entry['basket_size']->label,
                        'quantity' => $entry['count'],
                    );
                },
                $entries
            ) : array();
        } else {
            $entries = amap_get_contract_products_to_deliver( $contract, $group, $distribution_date );
            $items   = $entries ? array_map(
                static function ( $entry ) {
                    return array(
                        'label'    => $entry['product']->label,
                        'quantity' => $entry['quantity'],
                    );
                },
                $entries
            ) : array();
        }

        if ( ! empty( $items ) ) {
            $deliveries[] = array(
                'contract' => $contract,
                'items'    => $items,
            );
        }
    }

    return $deliveries;
}

/**
 * Lignes de pointage des adhérents d'un contrat basket_recurring sur un groupe, pour une fenêtre
 * de dates donnée — export CSV "Détail" de la carte "Produits à livrer" (étape 12.4). Une ligne
 * par adhérent souscrit à ce contrat sur ce groupe : nom, téléphone, nombre total de congés
 * déjà déclarés, nombre de distributions déjà faites depuis le début du contrat (dates passées
 * hors congé), puis un statut par date de la fenêtre — "—" si le contrat ne livre pas ce jour-là
 * (ex. semaine creuse d'un contrat bimensuel, même logique que amap_get_contract_baskets_to_deliver()),
 * "ABS" si un congé est déclaré pour cette date précise, case vide sinon (cochée à la main pendant
 * la distribution pour valider que l'adhérent est passé — volontairement pas pré-rempli).
 */
function amap_get_contract_roster_rows( $contract, $group, $window_start, $window_end ) {
    global $wpdb;

    $window_dates   = amap_get_weekday_dates_in_range( $window_start, $window_end, (int) $group->weekday );
    $contract_dates = amap_get_weekday_dates_in_range(
        $contract->start_date,
        $contract->end_date,
        (int) $group->weekday,
        (int) $contract->frequency_weeks
    );

    $today      = current_time( 'Y-m-d' );
    $past_dates = array_filter(
        $contract_dates,
        static function ( $date ) use ( $today ) {
            return $date < $today;
        }
    );

    $subscriptions = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_subscriptions WHERE contract_id = %d AND group_id = %d",
            $contract->id,
            $group->id
        )
    );

    $rows = array();
    foreach ( $subscriptions as $subscription ) {
        $member = get_user_by( 'id', $subscription->member_user_id );
        if ( ! $member ) {
            continue;
        }

        $leave_dates = wp_list_pluck( amap_get_leaves( $subscription->id ), 'leave_date' );
        $contact     = amap_get_user_contact( $subscription->member_user_id );

        $statuses = array();
        foreach ( $window_dates as $date ) {
            if ( ! in_array( $date, $contract_dates, true ) ) {
                $statuses[] = '—';
            } elseif ( in_array( $date, $leave_dates, true ) ) {
                $statuses[] = 'ABS';
            } else {
                // Case vide plutôt qu'un "Présent" pré-rempli : c'est cette case que le
                // bénévole/producteur coche à la main pendant la distribution pour valider que
                // l'adhérent est effectivement passé.
                $statuses[] = '';
            }
        }

        $rows[] = array(
            'name'         => trim( $member->last_name . ' ' . $member->first_name ),
            'phone'        => $contact->phone ?? '',
            'leaves_count' => count( $leave_dates ),
            'done_count'   => count( array_diff( $past_dates, $leave_dates ) ),
            'statuses'     => $statuses,
        );
    }

    return $rows;
}

/**
 * Souscriptions de l'adhérent connecté, enrichies pour l'affichage front (onglet "Espace
 * adhérent" de member-area.php) — même principe de jointure en PHP que
 * amap_get_producer_groups(). Un contrat supprimé entre-temps (pas de contrainte FOREIGN KEY
 * SQL dans ce plugin, voir amap_handle_delete_contract()) est simplement ignoré.
 */
function amap_get_member_subscriptions( $member_user_id ) {
    global $wpdb;

    $subscriptions = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amap_subscriptions WHERE member_user_id = %d ORDER BY signed_at DESC",
            $member_user_id
        )
    );

    $result = array();
    foreach ( $subscriptions as $subscription ) {
        $contract = amap_get_contract( $subscription->contract_id );
        if ( ! $contract ) {
            continue;
        }

        $result[] = array(
            'subscription' => $subscription,
            'contract'     => $contract,
            'producer'     => get_user_by( 'id', $contract->producer_user_id ),
            'group'        => amap_get_group( $subscription->group_id ),
            'basket_size'  => $subscription->basket_size_id ? amap_get_contract_basket_size( $subscription->basket_size_id ) : null,
            'status'       => amap_get_contract_period_status( $contract ),
        );
    }

    return $result;
}

/**
 * Contrats ouverts à la souscription (`is_active`) des producteurs livrant le groupe de
 * l'adhérent connecté (amap_get_member_group(), fixé par le bureau sur la page "Utilisateurs
 * AMAP") — proposés dans l'onglet "Espace adhérent" (member-area-member.php) avec un bouton
 * "Souscrire" menant au formulaire de member-area-subscribe.php. Un contrat déjà souscrit reste
 * proposé : un compte adhérent représente parfois un foyer entier, qui peut avoir besoin de
 * souscrire plusieurs fois au même contrat (ex. 2 grands paniers + 1 petit). Sans groupe
 * rattaché, aucun contrat ne peut être proposé.
 */
function amap_get_available_contracts_for_member( $member_user_id ) {
    $member_group = amap_get_member_group( $member_user_id );
    if ( ! $member_group ) {
        return array();
    }

    $available = array();

    foreach ( amap_get_contracts() as $contract ) {
        if ( ! $contract->is_active ) {
            continue;
        }

        $producer_group_ids = array_map( 'intval', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) );
        if ( ! in_array( (int) $member_group->id, $producer_group_ids, true ) ) {
            continue;
        }

        $available[] = array(
            'contract' => $contract,
            'producer' => get_user_by( 'id', $contract->producer_user_id ),
        );
    }

    return $available;
}

/**
 * Envoie l'email de confirmation après une souscription front réussie
 * (amap_handle_add_member_subscription()) — récupère elle-même contrat/producteur/groupe/taille
 * depuis $subscription_id, même principe de jointure que amap_get_member_subscriptions(). Ne
 * distingue pas les souscriptions saisies côté admin : appelée uniquement depuis le parcours
 * front, la saisie de secours par le bureau n'en a pas besoin.
 */
function amap_send_subscription_confirmation_email( $subscription_id ) {
    $subscription = amap_get_subscription( $subscription_id );
    if ( ! $subscription ) {
        return;
    }

    $contract = amap_get_contract( $subscription->contract_id );
    $member   = get_user_by( 'id', $subscription->member_user_id );
    if ( ! $contract || ! $member ) {
        return;
    }

    $producer = get_user_by( 'id', $contract->producer_user_id );
    $group    = amap_get_group( $subscription->group_id );

    $recap_items = array(
        esc_html__( 'Contrat', 'association-manager' ) . ' : ' . esc_html( $contract->label ),
        esc_html__( 'Producteur', 'association-manager' ) . ' : ' . esc_html( $producer ? $producer->display_name : '—' ),
        esc_html__( 'Groupe (point de retrait)', 'association-manager' ) . ' : ' . esc_html( $group ? $group->name : '—' ),
        esc_html__( 'Date de signature', 'association-manager' ) . ' : ' . esc_html( $subscription->signed_at ),
    );

    if ( $subscription->basket_size_id ) {
        $basket_size = amap_get_contract_basket_size( $subscription->basket_size_id );
        if ( $basket_size ) {
            $recap_items[] = sprintf(
                '%s : %s (%s €)',
                esc_html__( 'Taille de panier', 'association-manager' ),
                esc_html( $basket_size->label ),
                esc_html( number_format_i18n( (float) $basket_size->price, 2 ) )
            );
        }
    }

    // translators: %s: nom affiché de l'adhérent.
    $html_body  = '<p>' . sprintf(
        esc_html__( 'Bonjour %s, votre souscription a bien été enregistrée.', 'association-manager' ),
        esc_html( $member->display_name )
    ) . '</p>';
    $html_body .= '<ul><li>' . implode( '</li><li>', $recap_items ) . '</li></ul>';

    if ( 'product_grid' === $contract->contract_type ) {
        $html_body .= '<h3>' . esc_html__( 'Produits commandés', 'association-manager' ) . '</h3>';
        $html_body .= amap_get_subscription_recap_html( $subscription_id );
    }

    // translators: %s: libellé du contrat.
    $subject = sprintf( __( 'Confirmation de votre souscription — %s', 'association-manager' ), $contract->label );

    amap_send_email( $member->user_email, $subject, amap_render_email( $subject, $html_body ) );
}

/**
 * Grille produits×dates d'une souscription product_grid mise en forme pour l'email de
 * confirmation : une entrée par date de livraison ayant au moins une quantité commandée (grille
 * creuse, comme partout ailleurs dans le plugin), listant les produits commandés à cette date —
 * plus lisible dans un email qu'un tableau HTML avec des cases vides.
 */
function amap_get_subscription_recap_html( $subscription_id ) {
    $items_by_date = array();
    foreach ( amap_get_subscription_items( $subscription_id ) as $item ) {
        $items_by_date[ (int) $item->contract_delivery_date_id ][] = $item;
    }

    $recaps_by_date = array();
    foreach ( $items_by_date as $delivery_date_id => $date_items ) {
        $delivery_date = amap_get_contract_delivery_date( $delivery_date_id );
        if ( ! $delivery_date ) {
            continue;
        }

        $product_lines = array();
        foreach ( $date_items as $item ) {
            $product = amap_get_contract_product( $item->contract_product_id );
            if ( ! $product ) {
                continue;
            }
            $product_lines[] = esc_html( $product->label . ' × ' . $item->quantity );
        }

        if ( empty( $product_lines ) ) {
            continue;
        }

        $recaps_by_date[ $delivery_date->delivery_date ] = '<li>' . esc_html( date_i18n( 'j F Y', strtotime( $delivery_date->delivery_date ) ) )
            . '<ul><li>' . implode( '</li><li>', $product_lines ) . '</li></ul></li>';
    }

    if ( empty( $recaps_by_date ) ) {
        return '<p>' . esc_html__( 'Aucun produit enregistré pour cette souscription.', 'association-manager' ) . '</p>';
    }

    ksort( $recaps_by_date );

    return '<ul>' . implode( '', $recaps_by_date ) . '</ul>';
}

add_action( 'admin_post_amap_add_member_subscription', 'amap_handle_add_member_subscription' );

/**
 * Traite le formulaire front de souscription (member-area-subscribe.php), soumis par un
 * adhérent depuis son espace membre — pendant équivalent de amap_handle_add_subscription() côté
 * admin, mais member_user_id est TOUJOURS l'utilisateur connecté (jamais lu du POST) : un
 * adhérent ne peut ainsi jamais souscrire au nom d'un autre, même en trafiquant le formulaire.
 * group_id n'est pas non plus lu du POST : il est dérivé du rattachement fixé par le bureau
 * (amap_get_member_group()), jamais un choix laissé à l'adhérent (voir
 * amap_get_available_contracts_for_member()). Souscrire plusieurs fois au même contrat est
 * volontairement permis (un compte adhérent peut représenter un foyer entier, ex. 2 grands
 * paniers + 1 petit sous 3 lignes séparées) : les vérifications ci-dessous (contrat actif,
 * groupe/taille appartenant bien au contrat) ne peuvent donc échouer que par lien périmé ou
 * requête trafiquée, jamais par une resoumission légitime — d'où wp_die() plutôt qu'une
 * redirection avec message.
 */
function amap_handle_add_member_subscription() {
    $user = wp_get_current_user();
    if ( ! is_user_logged_in() || ! in_array( 'amap_member', $user->roles, true ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $contract_id = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;

    check_admin_referer( 'amap_subscribe_contract_' . $contract_id );

    $contract = $contract_id ? amap_get_contract( $contract_id ) : null;
    if ( ! $contract || ! $contract->is_active ) {
        wp_die( esc_html__( "Ce contrat n'est pas ouvert à la souscription.", 'association-manager' ) );
    }

    $member_user_id = $user->ID;

    $member_group       = amap_get_member_group( $member_user_id );
    $producer_group_ids = array_map( 'intval', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) );

    if ( ! $member_group || ! in_array( (int) $member_group->id, $producer_group_ids, true ) ) {
        wp_die( esc_html__( "Ce contrat n'est pas disponible pour votre groupe.", 'association-manager' ) );
    }

    $group_id = (int) $member_group->id;

    $basket_size_id = isset( $_POST['basket_size_id'] ) ? absint( $_POST['basket_size_id'] ) : 0;
    if ( 'basket_recurring' === $contract->contract_type ) {
        $contract_basket_size = $basket_size_id ? amap_get_contract_basket_size( $basket_size_id ) : null;
        if ( ! $contract_basket_size || (int) $contract_basket_size->contract_id !== $contract_id ) {
            wp_die( esc_html__( 'Taille de panier invalide pour ce contrat.', 'association-manager' ) );
        }
    } else {
        $basket_size_id = null;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_subscriptions',
        array(
            'contract_id'    => $contract_id,
            'member_user_id' => $member_user_id,
            'group_id'       => $group_id,
            'basket_size_id' => $basket_size_id,
            // Signature immédiate : contrairement à l'admin (où signed_at peut être antidaté
            // pour une saisie a posteriori), ici l'action EST la signature.
            'signed_at'      => current_time( 'Y-m-d' ),
        )
    );
    // Capturé tout de suite : amap_insert_subscription_items() enchaîne d'autres $wpdb->insert()
    // juste après, qui écraseraient $wpdb->insert_id avant l'envoi de l'email de confirmation.
    $subscription_id = $wpdb->insert_id;

    if ( 'product_grid' === $contract->contract_type ) {
        amap_insert_subscription_items( $subscription_id, $contract_id, $group_id );
    }

    // Le résultat n'est pas vérifié : un échec d'envoi ne doit pas remettre en cause une
    // souscription déjà enregistrée en base, même logique que l'appel à amap_send_magic_link()
    // dans amap_handle_login_email_step().
    amap_send_subscription_confirmation_email( $subscription_id );

    wp_safe_redirect(
        add_query_arg(
            array(
                'amap_tab'           => 'member',
                'amap_member_notice' => 'subscription_created',
            ),
            amap_get_member_area_url()
        )
    );
    exit;
}

add_action( 'admin_post_amap_add_member_leave', 'amap_handle_add_member_leave' );

/**
 * Traite le formulaire front de déclaration d'un congé (member-area-leave.php). Toutes les
 * conditions revérifiées ci-dessous sont déjà garanties par construction par
 * amap_get_member_leave_form_data() (qui ne propose que des dates éligibles) : un échec ne peut
 * donc survenir que par lien périmé ou requête trafiquée, jamais par un parcours normal — même
 * philosophie que amap_get_member_subscribe_form_data()/amap_handle_add_member_subscription()
 * à l'étape 7.3.
 */
function amap_handle_add_member_leave() {
    $user = wp_get_current_user();
    if ( ! is_user_logged_in() || ! in_array( 'amap_member', $user->roles, true ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
    $subscription    = $subscription_id ? amap_get_subscription( $subscription_id ) : null;
    // Contrairement à l'admin : la souscription doit appartenir à l'utilisateur connecté, jamais
    // à un ID posté en confiance.
    if ( ! $subscription || (int) $subscription->member_user_id !== $user->ID ) {
        wp_die( esc_html__( 'Souscription introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_declare_leave_' . $subscription_id );

    $contract = amap_get_contract( $subscription->contract_id );
    if ( ! $contract || 'basket_recurring' !== $contract->contract_type ) {
        wp_die( esc_html__( "Cette souscription n'est pas concernée par les congés.", 'association-manager' ) );
    }

    $group      = amap_get_group( $subscription->group_id );
    $leave_date = isset( $_POST['leave_date'] ) ? sanitize_text_field( wp_unslash( $_POST['leave_date'] ) ) : '';
    $min_date   = ( new DateTime( current_time( 'Y-m-d' ) ) )->modify( '+7 days' )->format( 'Y-m-d' );

    // amap_get_weekday_dates_in_range() couvre en un seul appel la période du contrat, le jour de
    // semaine ET la fréquence (hebdo/bimensuel/etc.) — même logique que amap_handle_add_leave()
    // côté admin.
    $valid_dates = $group
        ? amap_get_weekday_dates_in_range( $contract->start_date, $contract->end_date, (int) $group->weekday, (int) $contract->frequency_weeks )
        : array();

    if ( ! in_array( $leave_date, $valid_dates, true )
        || $leave_date < $min_date
        || amap_subscription_has_leave( $subscription_id, $leave_date )
        || count( amap_get_leaves( $subscription_id ) ) >= (int) $contract->max_leaves
    ) {
        wp_die( esc_html__( "Cette date de congé n'est plus disponible.", 'association-manager' ) );
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_leaves',
        array(
            'subscription_id' => $subscription_id,
            'leave_date'      => $leave_date,
            'declared_at'     => current_time( 'Y-m-d' ),
        )
    );

    wp_safe_redirect(
        add_query_arg(
            array(
                'amap_tab'           => 'member',
                'amap_member_notice' => 'leave_declared',
            ),
            amap_get_member_area_url()
        )
    );
    exit;
}

function amap_render_subscriptions_page() {
    if ( ! current_user_can( 'amap_manage_subscriptions' ) ) {
        return;
    }

    $notice = isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '';

    // Mode édition : ?action=edit&id=X sur cette même page, même logique que "Groupes" et
    // "Contrats".
    $editing_id = 0;
    if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ) {
        $editing_id = absint( $_GET['id'] );
    }
    $editing_subscription = $editing_id ? amap_get_subscription( $editing_id ) : null;
    if ( $editing_id && ! $editing_subscription ) {
        $editing_id = 0;
    }

    // Tous les champs restent modifiables côté admin, y compris la grille produits×dates et le
    // contrat/groupe, même une fois la souscription signée : le bureau garde la main pour
    // corriger une erreur de saisie ("admin est root"). La modification resynchronise entièrement
    // les subscription_items à partir de la grille soumise (amap_handle_update_subscription()) —
    // changer le contrat/groupe rebâtit donc la grille sur les produits/dates du nouveau contrat,
    // au prix de perdre les quantités déjà saisies pour l'ancien.
    $editing_subscription_items = $editing_subscription ? amap_get_subscription_items( $editing_subscription->id ) : array();

    $transient_key = 'amap_subscription_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_subscription ) {
        // Préremplit la grille depuis les subscription_items déjà enregistrés (même structure
        // que celle postée par le formulaire : date_id => product_id => quantity), pour que la
        // grille de modification parte des quantités actuelles plutôt que d'une grille vide.
        $prefill_quantities = array();
        foreach ( $editing_subscription_items as $item ) {
            $prefill_quantities[ (int) $item->contract_delivery_date_id ][ (int) $item->contract_product_id ] = (int) $item->quantity;
        }

        $form_data = array(
            'contract_id'    => (string) $editing_subscription->contract_id,
            'member_user_id' => (string) $editing_subscription->member_user_id,
            'group_id'       => (string) $editing_subscription->group_id,
            'basket_size_id' => null !== $editing_subscription->basket_size_id ? (string) $editing_subscription->basket_size_id : '',
            'signed_at'      => $editing_subscription->signed_at,
            'quantities'     => $prefill_quantities,
        );
    } else {
        // Une nouvelle souscription est très généralement saisie le jour même de la signature
        // papier : préremplie à aujourd'hui, mais modifiable (saisie a posteriori).
        $form_data = array( 'signed_at' => current_time( 'Y-m-d' ) );
    }

    $members   = amap_get_member_users();
    $contracts = amap_get_contracts();

    // Seuls les contrats actifs ("ouverts à la souscription", voir la case à cocher de
    // amap_render_contracts_page()) sont proposés pour une nouvelle souscription. En édition, le
    // contrat déjà choisi reste proposé même s'il a été désactivé depuis, pour ne pas casser une
    // souscription existante.
    $selectable_contracts = array_values(
        array_filter(
            $contracts,
            static function ( $contract ) {
                return (bool) $contract->is_active;
            }
        )
    );
    if ( $editing_subscription ) {
        $selectable_contract_ids = array_map( 'intval', wp_list_pluck( $selectable_contracts, 'id' ) );
        if ( ! in_array( (int) $editing_subscription->contract_id, $selectable_contract_ids, true ) ) {
            $editing_subscription_contract = amap_get_contract( $editing_subscription->contract_id );
            if ( $editing_subscription_contract ) {
                $selectable_contracts[] = $editing_subscription_contract;
            }
        }
    }

    // Données nécessaires au filtrage JS des champs "Groupe"/"Taille de panier" selon le contrat
    // choisi : groupes réellement rattachés au producteur du contrat (amap_get_producer_groups(),
    // déjà utilisée pour les dates de livraison), tailles de panier propres au contrat si
    // basket_recurring. Précalculé pour tous les contrats proposés plutôt qu'en Ajax, ces volumes
    // restant faibles.
    $contracts_js_data = array();
    foreach ( $selectable_contracts as $contract ) {
        $producer_groups = amap_get_producer_groups( $contract->producer_user_id );
        $basket_sizes    = 'basket_recurring' === $contract->contract_type ? amap_get_contract_basket_sizes( $contract->id ) : array();
        $products        = 'product_grid' === $contract->contract_type ? amap_get_contract_products( $contract->id ) : array();

        // Dates de livraison groupées par groupe (une même liste de produits, mais des dates
        // différentes selon le groupe de retrait choisi, voir amap_get_contract_delivery_dates()).
        $delivery_dates_by_group = array();
        if ( 'product_grid' === $contract->contract_type ) {
            foreach ( amap_get_contract_delivery_dates( $contract->id ) as $delivery_date_row ) {
                $delivery_dates_by_group[ (int) $delivery_date_row->group_id ][] = array(
                    'id'    => (int) $delivery_date_row->id,
                    'label' => date_i18n( 'j F Y', strtotime( $delivery_date_row->delivery_date ) ),
                );
            }
        }

        $contracts_js_data[ (int) $contract->id ] = array(
            'type'                    => $contract->contract_type,
            'groups'                  => array_map(
                static function ( $group ) {
                    return array(
                        'id'    => (int) $group->id,
                        'label' => $group->name,
                    );
                },
                $producer_groups
            ),
            'basket_sizes'            => array_map(
                static function ( $size ) {
                    return array(
                        'id'    => (int) $size->id,
                        'label' => $size->label . ' (' . number_format_i18n( (float) $size->price, 2 ) . ' €)',
                    );
                },
                $basket_sizes
            ),
            'products'                => array_map(
                static function ( $product ) {
                    return array(
                        'id'    => (int) $product->id,
                        'label' => $product->label . ' (' . number_format_i18n( (float) $product->price, 2 ) . ' €)',
                    );
                },
                $products
            ),
            'delivery_dates_by_group' => $delivery_dates_by_group,
        );
    }

    $selected_contract_id   = isset( $form_data['contract_id'] ) ? (int) $form_data['contract_id'] : 0;
    $selected_contract_data = $contracts_js_data[ $selected_contract_id ] ?? null;

    $subscriptions = amap_get_subscriptions();
    ?>
    <style>
        .amap-items-grid-wrapper {
            overflow-x: auto;
            margin-bottom: 12px;
        }
        table.amap-items-grid {
            border-collapse: collapse;
            box-shadow: none;
            margin-top: 4px;
        }
        table.amap-items-grid th,
        table.amap-items-grid td {
            border: 1px solid #dcdcde;
            padding: 6px 10px;
            text-align: center;
        }
        table.amap-items-grid thead th {
            background: #f0f0f1;
            white-space: normal;
            min-width: 90px;
        }
        table.amap-items-grid tbody th {
            background: #f6f7f7;
            white-space: nowrap;
            text-align: left;
        }
        table.amap-items-grid tbody tr:nth-child(even) td {
            background: #fafafa;
        }
        table.amap-items-grid .amap-item-quantity {
            width: 4em;
            text-align: center;
        }
        ul.amap-leave-list {
            list-style: none;
            margin: 0 0 12px;
            padding: 0;
            max-width: 420px;
        }
        ul.amap-leave-list li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 6px 10px;
            border: 1px solid #dcdcde;
            border-top: none;
        }
        ul.amap-leave-list li:first-child {
            border-top: 1px solid #dcdcde;
        }
        .amap-leave-add-form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .amap-leave-add-form .description {
            flex-basis: 100%;
            margin: 4px 0 0;
        }
    </style>
    <div class="wrap">
        <h1><?php esc_html_e( 'Souscriptions', 'association-manager' ); ?></h1>

        <?php if ( 'invalid' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Champs obligatoires manquants ou invalides.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_date' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Date de signature invalide.', 'association-manager' ); ?></p></div>
        <?php endif; ?>

        <?php if ( empty( $members ) || empty( $selectable_contracts ) ) : ?>
            <p><?php esc_html_e( "Il faut au moins un compte adhérent et un contrat actif pour créer une souscription.", 'association-manager' ); ?></p>
        <?php else : ?>
            <?php if ( ! $editing_id ) : ?>
                <p>
                    <button type="button" class="button button-primary" id="amap-subscription-add-toggle"><?php esc_html_e( '+ Ajouter une souscription', 'association-manager' ); ?></button>
                </p>
            <?php endif; ?>
            <div id="amap-subscription-form-wrapper"<?php echo $editing_id ? '' : ' hidden'; ?>>
            <?php if ( $editing_id && $editing_subscription ) : ?>
                <?php
                $view_contract    = amap_get_contract( $editing_subscription->contract_id );
                $view_producer    = $view_contract ? get_user_by( 'id', $view_contract->producer_user_id ) : null;
                $view_member      = get_user_by( 'id', $editing_subscription->member_user_id );
                $view_group       = amap_get_group( $editing_subscription->group_id );
                $view_basket_size = $editing_subscription->basket_size_id ? amap_get_contract_basket_size( $editing_subscription->basket_size_id ) : null;
                ?>
                <div id="amap-subscription-view">
                    <table class="widefat">
                        <tbody>
                            <tr>
                                <th><?php esc_html_e( 'Contrat', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $view_contract ? $view_contract->label : '—' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Producteur', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $view_producer ? $view_producer->display_name : '—' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Adhérent', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $view_member ? $view_member->display_name : '—' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Groupe (point de retrait)', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $view_group ? $view_group->name : '—' ); ?></td>
                            </tr>
                            <?php if ( $view_basket_size ) : ?>
                                <tr>
                                    <th><?php esc_html_e( 'Taille de panier', 'association-manager' ); ?></th>
                                    <td><?php echo esc_html( $view_basket_size->label ); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <th><?php esc_html_e( 'Date de signature', 'association-manager' ); ?></th>
                                <td><?php echo esc_html( $editing_subscription->signed_at ); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if ( $view_contract && 'product_grid' === $view_contract->contract_type ) : ?>
                        <h3><?php esc_html_e( 'Produits commandés', 'association-manager' ); ?></h3>
                        <?php
                        // Même affichage que la grille de saisie (produits en colonne, une date
                        // de livraison par ligne) plutôt qu'une liste plate — plus simple à
                        // relire. Toutes les dates du groupe et tous les produits du contrat sont
                        // affichés, pas seulement ceux ayant une quantité enregistrée.
                        $recap_products = amap_get_contract_products( $view_contract->id );
                        $recap_dates    = array_values(
                            array_filter(
                                amap_get_contract_delivery_dates( $view_contract->id ),
                                static function ( $delivery_date ) use ( $editing_subscription ) {
                                    return (int) $delivery_date->group_id === (int) $editing_subscription->group_id;
                                }
                            )
                        );

                        $recap_quantities = array();
                        foreach ( $editing_subscription_items as $item ) {
                            $recap_quantities[ (int) $item->contract_delivery_date_id ][ (int) $item->contract_product_id ] = (int) $item->quantity;
                        }
                        ?>
                        <?php if ( empty( $recap_products ) || empty( $recap_dates ) ) : ?>
                            <p><?php esc_html_e( 'Aucun produit enregistré pour cette souscription.', 'association-manager' ); ?></p>
                        <?php else : ?>
                            <div class="amap-items-grid-wrapper">
                                <table class="widefat amap-items-grid">
                                    <thead>
                                        <tr>
                                            <th></th>
                                            <?php foreach ( $recap_products as $recap_product ) : ?>
                                                <th><?php echo esc_html( $recap_product->label ); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $recap_dates as $recap_date ) : ?>
                                            <tr>
                                                <th><?php echo esc_html( $recap_date->delivery_date ); ?></th>
                                                <?php foreach ( $recap_products as $recap_product ) : ?>
                                                    <?php $recap_qty = $recap_quantities[ (int) $recap_date->id ][ (int) $recap_product->id ] ?? 0; ?>
                                                    <td><?php echo $recap_qty ? esc_html( $recap_qty ) : '—'; ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <p>
                        <button type="button" class="button button-primary" id="amap-subscription-edit-toggle"><?php esc_html_e( 'Modifier', 'association-manager' ); ?></button>
                    </p>
                </div>
            <?php endif; ?>
            <div id="amap-subscription-edit-form"<?php echo $editing_id ? ' hidden' : ''; ?>>
            <h2>
                <?php echo $editing_id
                    ? esc_html__( 'Modifier une souscription', 'association-manager' )
                    : esc_html__( 'Ajouter une souscription', 'association-manager' ); ?>
            </h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php if ( $editing_id ) : ?>
                    <?php wp_nonce_field( 'amap_edit_subscription_' . $editing_id ); ?>
                    <input type="hidden" name="action" value="amap_update_subscription">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
                <?php else : ?>
                    <?php wp_nonce_field( 'amap_add_subscription' ); ?>
                    <input type="hidden" name="action" value="amap_add_subscription">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="amap-subscription-contract"><?php esc_html_e( 'Contrat', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-subscription-contract" name="contract_id" required>
                                <option value=""></option>
                                <?php foreach ( $selectable_contracts as $contract ) : ?>
                                    <?php $contract_producer = get_user_by( 'id', $contract->producer_user_id ); ?>
                                    <option value="<?php echo esc_attr( $contract->id ); ?>" <?php selected( (string) $contract->id, $form_data['contract_id'] ?? '' ); ?>>
                                        <?php echo esc_html( $contract->label . ' — ' . ( $contract_producer ? $contract_producer->display_name : '' ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amap-subscription-member"><?php esc_html_e( 'Adhérent', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-subscription-member" name="member_user_id" required>
                                <option value=""></option>
                                <?php foreach ( $members as $member ) : ?>
                                    <option value="<?php echo esc_attr( $member->ID ); ?>" <?php selected( (string) $member->ID, $form_data['member_user_id'] ?? '' ); ?>>
                                        <?php echo esc_html( $member->display_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amap-subscription-group"><?php esc_html_e( 'Groupe (point de retrait)', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-subscription-group" name="group_id" required>
                                <?php if ( $selected_contract_data ) : ?>
                                    <?php foreach ( $selected_contract_data['groups'] as $group_option ) : ?>
                                        <option value="<?php echo esc_attr( $group_option['id'] ); ?>" <?php selected( (string) $group_option['id'], $form_data['group_id'] ?? '' ); ?>>
                                            <?php echo esc_html( $group_option['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value=""><?php esc_html_e( '— choisir un contrat —', 'association-manager' ); ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr id="amap-subscription-basket-size-row"<?php echo ( $selected_contract_data && 'basket_recurring' === $selected_contract_data['type'] ) ? '' : ' hidden'; ?>>
                        <th><label for="amap-subscription-basket-size"><?php esc_html_e( 'Taille de panier', 'association-manager' ); ?></label></th>
                        <td>
                            <select id="amap-subscription-basket-size" name="basket_size_id">
                                <?php if ( $selected_contract_data ) : ?>
                                    <?php foreach ( $selected_contract_data['basket_sizes'] as $size_option ) : ?>
                                        <option value="<?php echo esc_attr( $size_option['id'] ); ?>" <?php selected( (string) $size_option['id'], $form_data['basket_size_id'] ?? '' ); ?>>
                                            <?php echo esc_html( $size_option['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amap-subscription-signed-at"><?php esc_html_e( 'Date de signature', 'association-manager' ); ?></label></th>
                        <td><input type="date" id="amap-subscription-signed-at" name="signed_at" value="<?php echo esc_attr( $form_data['signed_at'] ?? '' ); ?>" required></td>
                    </tr>
                </table>

                <div id="amap-subscription-items-wrapper" hidden>
                    <h3><?php esc_html_e( 'Produits commandés', 'association-manager' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Une quantité par produit et par date de livraison.', 'association-manager' ); ?></p>
                    <div id="amap-subscription-items-grid" class="amap-items-grid-wrapper"></div>
                </div>

                <p>
                    <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                    <?php if ( $editing_id ) : ?>
                        <button type="button" class="button" id="amap-subscription-edit-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php else : ?>
                        <button type="button" class="button" id="amap-subscription-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                    <?php endif; ?>
                </p>
            </form>
            </div>
            </div>

            <?php if ( $editing_id && $editing_subscription ) : ?>
                <?php $leaves_contract = amap_get_contract( $editing_subscription->contract_id ); ?>
                <?php if ( $leaves_contract && 'basket_recurring' === $leaves_contract->contract_type ) : ?>
                    <?php
                    $leaves       = amap_get_leaves( $editing_id );
                    $max_leaves   = (int) $leaves_contract->max_leaves;
                    $leaves_full  = count( $leaves ) >= $max_leaves;
                    $leaves_group = amap_get_group( $editing_subscription->group_id );

                    // Liste déroulante plutôt qu'un champ date libre : ne propose que les vraies
                    // dates de distribution de ce contrat/groupe (jour de semaine + fréquence,
                    // ancrées sur start_date via amap_get_weekday_dates_in_range()), moins celles
                    // déjà déclarées. Contrairement au front (amap_get_member_leave_form_data()),
                    // pas de filtre sur le délai d'une semaine : "admin est root", le bureau peut
                    // saisir un congé à tout moment.
                    $taken_dates            = wp_list_pluck( $leaves, 'leave_date' );
                    $leaves_available_dates = array();
                    if ( $leaves_group && ! $leaves_full ) {
                        foreach ( amap_get_weekday_dates_in_range( $leaves_contract->start_date, $leaves_contract->end_date, (int) $leaves_group->weekday, (int) $leaves_contract->frequency_weeks ) as $candidate_date ) {
                            if ( ! in_array( $candidate_date, $taken_dates, true ) ) {
                                $leaves_available_dates[] = $candidate_date;
                            }
                        }
                    }
                    ?>
                    <h2><?php esc_html_e( 'Congés', 'association-manager' ); ?></h2>

                    <?php if ( 'leave_saved' === $notice ) : ?>
                        <div class="notice notice-success"><p><?php esc_html_e( 'Congé ajouté.', 'association-manager' ); ?></p></div>
                    <?php elseif ( 'leave_deleted' === $notice ) : ?>
                        <div class="notice notice-success"><p><?php esc_html_e( 'Congé supprimé.', 'association-manager' ); ?></p></div>
                    <?php elseif ( 'leave_invalid' === $notice ) : ?>
                        <div class="notice notice-error"><p><?php esc_html_e( 'Date de congé invalide.', 'association-manager' ); ?></p></div>
                    <?php elseif ( 'leave_not_a_distribution_date' === $notice ) : ?>
                        <div class="notice notice-error"><p><?php esc_html_e( "Cette date ne correspond pas à une distribution réelle de ce contrat (jour de la semaine, période ou fréquence incorrecte).", 'association-manager' ); ?></p></div>
                    <?php elseif ( 'leave_duplicate' === $notice ) : ?>
                        <div class="notice notice-error"><p><?php esc_html_e( 'Ce congé est déjà déclaré.', 'association-manager' ); ?></p></div>
                    <?php elseif ( 'leave_max_reached' === $notice ) : ?>
                        <div class="notice notice-error"><p><?php esc_html_e( 'Le maximum de congés autorisés pour cette souscription est déjà atteint.', 'association-manager' ); ?></p></div>
                    <?php endif; ?>

                    <?php if ( empty( $leaves ) ) : ?>
                        <p><?php esc_html_e( 'Aucun congé déclaré pour le moment.', 'association-manager' ); ?></p>
                    <?php else : ?>
                        <ul class="amap-leave-list">
                            <?php foreach ( $leaves as $leave ) : ?>
                                <?php
                                $leave_delete_url = wp_nonce_url(
                                    admin_url( 'admin-post.php?action=amap_delete_leave&id=' . $leave->id ),
                                    'amap_delete_leave_' . $leave->id
                                );
                                ?>
                                <li>
                                    <span><?php echo esc_html( date_i18n( 'l j F Y', strtotime( $leave->leave_date ) ) ); ?></span>
                                    <a href="<?php echo esc_url( $leave_delete_url ); ?>" class="amap-leave-delete" onclick="return confirm( '<?php echo esc_js( __( 'Supprimer ce congé ?', 'association-manager' ) ); ?>' );">
                                        <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: nombre de congés déjà déclarés. 2: nombre de congés autorisés pour ce contrat. */
                            esc_html__( '%1$d congé(s) déclaré(s) sur %2$d autorisés.', 'association-manager' ),
                            count( $leaves ),
                            $max_leaves
                        );
                        ?>
                    </p>

                    <?php if ( $leaves_full ) : ?>
                        <p><?php esc_html_e( 'Le maximum de congés a été atteint pour cette souscription.', 'association-manager' ); ?></p>
                    <?php elseif ( empty( $leaves_available_dates ) ) : ?>
                        <p><?php esc_html_e( 'Aucune date de distribution disponible pour ce contrat et ce groupe.', 'association-manager' ); ?></p>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="amap-leave-add-form">
                            <?php wp_nonce_field( 'amap_add_leave_' . $editing_id ); ?>
                            <input type="hidden" name="action" value="amap_add_leave">
                            <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $editing_id ); ?>">
                            <label for="amap-leave-date"><?php esc_html_e( 'Date de congé', 'association-manager' ); ?></label>
                            <select id="amap-leave-date" name="leave_date" required>
                                <option value=""></option>
                                <?php foreach ( $leaves_available_dates as $candidate_date ) : ?>
                                    <option value="<?php echo esc_attr( $candidate_date ); ?>"><?php echo esc_html( date_i18n( 'l j F Y', strtotime( $candidate_date ) ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php submit_button( __( 'Ajouter le congé', 'association-manager' ), 'secondary', 'submit', false ); ?>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <script>
            ( function () {
                var contractsData   = <?php echo wp_json_encode( $contracts_js_data ); ?>;
                var contractField   = document.getElementById( 'amap-subscription-contract' );
                var groupField      = document.getElementById( 'amap-subscription-group' );
                var basketSizeRow   = document.getElementById( 'amap-subscription-basket-size-row' );
                var basketSizeField = document.getElementById( 'amap-subscription-basket-size' );
                var noContractLabel = <?php echo wp_json_encode( __( '— choisir un contrat —', 'association-manager' ) ); ?>;

                function populateSelect( select, options, placeholderText ) {
                    select.innerHTML = '';
                    if ( ! options.length ) {
                        if ( placeholderText ) {
                            var placeholder = document.createElement( 'option' );
                            placeholder.value       = '';
                            placeholder.textContent = placeholderText;
                            select.appendChild( placeholder );
                        }
                        return;
                    }
                    options.forEach( function ( option ) {
                        var optionElement = document.createElement( 'option' );
                        optionElement.value       = option.id;
                        optionElement.textContent = option.label;
                        select.appendChild( optionElement );
                    } );
                }

                var itemsWrapper = document.getElementById( 'amap-subscription-items-wrapper' );
                var itemsGrid    = document.getElementById( 'amap-subscription-items-grid' );
                var noProductsLabel  = <?php echo wp_json_encode( __( "Ce contrat n'a aucun produit dans son catalogue.", 'association-manager' ) ); ?>;
                var chooseGroupLabel = <?php echo wp_json_encode( __( 'Choisissez un groupe pour afficher les dates de livraison.', 'association-manager' ) ); ?>;
                var noDatesLabel     = <?php echo wp_json_encode( __( 'Aucune date de livraison enregistrée pour ce groupe.', 'association-manager' ) ); ?>;
                var duplicateLabel   = <?php echo wp_json_encode( __( 'Dupliquer la 1ère date sur toutes les autres', 'association-manager' ) ); ?>;

                function buildItemsGrid( contractId, groupId, prefill ) {
                    if ( ! itemsWrapper ) {
                        return;
                    }

                    var data = contractsData[ contractId ];
                    itemsGrid.innerHTML = '';

                    if ( ! data || 'product_grid' !== data.type ) {
                        itemsWrapper.hidden = true;
                        return;
                    }
                    itemsWrapper.hidden = false;

                    var products = data.products || [];
                    var dates    = ( data.delivery_dates_by_group && data.delivery_dates_by_group[ groupId ] ) || [];

                    if ( ! products.length || ! dates.length ) {
                        var message = document.createElement( 'p' );
                        if ( ! products.length ) {
                            message.textContent = noProductsLabel;
                        } else if ( ! groupId ) {
                            message.textContent = chooseGroupLabel;
                        } else {
                            message.textContent = noDatesLabel;
                        }
                        itemsGrid.appendChild( message );
                        return;
                    }

                    if ( dates.length > 1 ) {
                        var duplicateButton = document.createElement( 'button' );
                        duplicateButton.type      = 'button';
                        duplicateButton.className = 'button';
                        duplicateButton.textContent = duplicateLabel;
                        duplicateButton.addEventListener( 'click', function () {
                            var firstRowValues = {};
                            itemsGrid.querySelectorAll( '[data-date-index="0"]' ).forEach( function ( input ) {
                                firstRowValues[ input.dataset.productId ] = input.value;
                            } );
                            itemsGrid.querySelectorAll( '.amap-item-quantity' ).forEach( function ( input ) {
                                if ( '0' !== input.dataset.dateIndex ) {
                                    input.value = firstRowValues[ input.dataset.productId ] || '';
                                }
                            } );
                        } );
                        itemsGrid.appendChild( duplicateButton );
                    }

                    var table = document.createElement( 'table' );
                    table.className = 'widefat amap-items-grid';

                    var thead    = document.createElement( 'thead' );
                    var headRow  = document.createElement( 'tr' );
                    headRow.appendChild( document.createElement( 'th' ) );
                    products.forEach( function ( product ) {
                        var th = document.createElement( 'th' );
                        th.textContent = product.label;
                        headRow.appendChild( th );
                    } );
                    thead.appendChild( headRow );
                    table.appendChild( thead );

                    var tbody = document.createElement( 'tbody' );
                    dates.forEach( function ( dateOption, dateIndex ) {
                        var row = document.createElement( 'tr' );
                        var rowHeader = document.createElement( 'th' );
                        rowHeader.textContent = dateOption.label;
                        row.appendChild( rowHeader );

                        products.forEach( function ( product ) {
                            var cell  = document.createElement( 'td' );
                            var input = document.createElement( 'input' );
                            input.type                 = 'number';
                            input.min                  = '0';
                            input.step                 = '1';
                            input.className            = 'amap-item-quantity small-text';
                            input.name                 = 'quantity[' + dateOption.id + '][' + product.id + ']';
                            input.dataset.dateIndex     = dateIndex;
                            input.dataset.productId     = product.id;
                            var prefillValue = prefill && prefill[ dateOption.id ] ? prefill[ dateOption.id ][ product.id ] : '';
                            if ( prefillValue ) {
                                input.value = prefillValue;
                            }
                            cell.appendChild( input );
                            row.appendChild( cell );
                        } );

                        tbody.appendChild( row );
                    } );
                    table.appendChild( tbody );
                    itemsGrid.appendChild( table );
                }

                contractField.addEventListener( 'change', function () {
                    var data = contractsData[ contractField.value ];
                    populateSelect( groupField, data ? data.groups : [], noContractLabel );

                    var isBasketRecurring = !! data && 'basket_recurring' === data.type;
                    basketSizeRow.hidden = ! isBasketRecurring;
                    populateSelect( basketSizeField, isBasketRecurring ? data.basket_sizes : [], '' );

                    buildItemsGrid( contractField.value, groupField.value, {} );
                } );

                groupField.addEventListener( 'change', function () {
                    buildItemsGrid( contractField.value, groupField.value, {} );
                } );

                buildItemsGrid( contractField.value, groupField.value, <?php echo wp_json_encode( $form_data['quantities'] ?? array() ); ?> );
            } )();
            </script>
            <script>
            ( function () {
                var toggle  = document.getElementById( 'amap-subscription-add-toggle' );
                var wrapper = document.getElementById( 'amap-subscription-form-wrapper' );
                var cancel  = document.getElementById( 'amap-subscription-add-cancel' );
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
                var viewBlock  = document.getElementById( 'amap-subscription-view' );
                var editForm   = document.getElementById( 'amap-subscription-edit-form' );
                var editToggle = document.getElementById( 'amap-subscription-edit-toggle' );
                var editCancel = document.getElementById( 'amap-subscription-edit-cancel' );
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
        <?php endif; ?>

        <?php if ( empty( $subscriptions ) ) : ?>
            <p><?php esc_html_e( 'Aucune souscription enregistrée pour le moment.', 'association-manager' ); ?></p>
        <?php else : ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Contrat', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Producteur', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Adhérent', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Groupe', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Taille de panier', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Signée le', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $subscriptions as $subscription ) : ?>
                        <?php
                        $row_contract    = amap_get_contract( $subscription->contract_id );
                        $row_producer    = $row_contract ? get_user_by( 'id', $row_contract->producer_user_id ) : null;
                        $row_member      = get_user_by( 'id', $subscription->member_user_id );
                        $row_group       = amap_get_group( $subscription->group_id );
                        $row_basket_size = $subscription->basket_size_id ? amap_get_contract_basket_size( $subscription->basket_size_id ) : null;
                        ?>
                        <tr>
                            <td><?php echo esc_html( $row_contract ? $row_contract->label : '—' ); ?></td>
                            <td><?php echo esc_html( $row_producer ? $row_producer->display_name : '—' ); ?></td>
                            <td><?php echo esc_html( $row_member ? $row_member->display_name : '—' ); ?></td>
                            <td><?php echo esc_html( $row_group ? $row_group->name : '—' ); ?></td>
                            <td><?php echo esc_html( $row_basket_size ? $row_basket_size->label : '—' ); ?></td>
                            <td><?php echo esc_html( $subscription->signed_at ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-subscriptions&action=edit&id=' . $subscription->id ) ); ?>">
                                    <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                </a>
                                |
                                <?php
                                $delete_url       = wp_nonce_url(
                                    admin_url( 'admin-post.php?action=amap_delete_subscription&id=' . $subscription->id ),
                                    'amap_delete_subscription_' . $subscription->id
                                );
                                $confirm_message = __( 'Supprimer définitivement cette souscription ?', 'association-manager' );
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

/**
 * Insère les subscription_items depuis la grille soumise ($_POST['quantity'][date_id][product_id]),
 * en ne retenant que les couples produit/date réellement rattachés à ce contrat et ce groupe —
 * jamais les IDs postés en confiance — et les quantités strictement positives (grille creuse :
 * une case vide ou à 0 ne crée aucune ligne). Appelée à la création comme à la modification d'une
 * souscription product_grid (amap_handle_update_subscription() supprime d'abord les lignes
 * existantes pour resynchroniser entièrement la grille).
 */
function amap_insert_subscription_items( $subscription_id, $contract_id, $group_id ) {
    if ( ! isset( $_POST['quantity'] ) || ! is_array( $_POST['quantity'] ) ) {
        return;
    }

    $valid_product_ids = array_map( 'absint', wp_list_pluck( amap_get_contract_products( $contract_id ), 'id' ) );

    $valid_date_ids = array();
    foreach ( amap_get_contract_delivery_dates( $contract_id ) as $delivery_date ) {
        if ( (int) $delivery_date->group_id === $group_id ) {
            $valid_date_ids[] = (int) $delivery_date->id;
        }
    }

    global $wpdb;
    $quantities = wp_unslash( $_POST['quantity'] );

    foreach ( $quantities as $delivery_date_id => $products_quantities ) {
        $delivery_date_id = absint( $delivery_date_id );
        if ( ! is_array( $products_quantities ) || ! in_array( $delivery_date_id, $valid_date_ids, true ) ) {
            continue;
        }

        foreach ( $products_quantities as $product_id => $quantity ) {
            $product_id = absint( $product_id );
            $quantity   = absint( $quantity );
            if ( ! $quantity || ! in_array( $product_id, $valid_product_ids, true ) ) {
                continue;
            }

            $wpdb->insert(
                $wpdb->prefix . 'amap_subscription_items',
                array(
                    'subscription_id'           => $subscription_id,
                    'contract_product_id'       => $product_id,
                    'contract_delivery_date_id' => $delivery_date_id,
                    'quantity'                  => $quantity,
                )
            );
        }
    }
}

add_action( 'admin_post_amap_add_subscription', 'amap_handle_add_subscription' );

function amap_handle_add_subscription() {
    if ( ! current_user_can( 'amap_manage_subscriptions' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_subscription' );

    $contract_id    = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $member_user_id = isset( $_POST['member_user_id'] ) ? absint( $_POST['member_user_id'] ) : 0;
    $group_id       = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $basket_size_id = isset( $_POST['basket_size_id'] ) ? absint( $_POST['basket_size_id'] ) : 0;
    $signed_at      = isset( $_POST['signed_at'] ) ? sanitize_text_field( wp_unslash( $_POST['signed_at'] ) ) : '';
    // quantities : grille produits×dates saisie pour un contrat product_grid, revalidée et
    // insérée après la création de la souscription (amap_insert_subscription_items()) ; stockée
    // dans $submitted comme les autres champs pour être restituée si le formulaire est invalide.
    $quantities     = isset( $_POST['quantity'] ) && is_array( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : array();
    $submitted      = compact( 'contract_id', 'member_user_id', 'group_id', 'basket_size_id', 'signed_at', 'quantities' );

    $contract           = $contract_id ? amap_get_contract( $contract_id ) : null;
    $valid_member_ids   = wp_list_pluck( amap_get_member_users(), 'ID' );
    $producer_group_ids = $contract ? array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) ) : array();

    if ( ! $contract || ! in_array( $member_user_id, $valid_member_ids, true )
        || ! $group_id || ! in_array( $group_id, $producer_group_ids, true ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions&amap_notice=invalid' ) );
        exit;
    }

    if ( ! amap_is_valid_date( $signed_at ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions&amap_notice=invalid_date' ) );
        exit;
    }

    // basket_size_id n'a de sens que pour un panier récurrent : obligatoire et revérifié comme
    // appartenant à ce contrat dans ce cas (même si le formulaire masque le champ en JS, on
    // revalide côté serveur), forcé à NULL sinon — même principe que
    // wp_amap_contracts.frequency_weeks.
    if ( 'basket_recurring' === $contract->contract_type ) {
        $contract_basket_size = $basket_size_id ? amap_get_contract_basket_size( $basket_size_id ) : null;
        if ( ! $contract_basket_size || (int) $contract_basket_size->contract_id !== $contract_id ) {
            amap_store_subscription_form_data( $submitted );
            wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions&amap_notice=invalid' ) );
            exit;
        }
    } else {
        $basket_size_id = null;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_subscriptions',
        array(
            'contract_id'    => $contract_id,
            'member_user_id' => $member_user_id,
            'group_id'       => $group_id,
            'basket_size_id' => $basket_size_id,
            'signed_at'      => $signed_at,
        )
    );

    if ( 'product_grid' === $contract->contract_type ) {
        amap_insert_subscription_items( $wpdb->insert_id, $contract_id, $group_id );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions' ) );
    exit;
}

add_action( 'admin_post_amap_update_subscription', 'amap_handle_update_subscription' );

function amap_handle_update_subscription() {
    if ( ! current_user_can( 'amap_manage_subscriptions' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id           = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $subscription = $id ? amap_get_subscription( $id ) : null;
    if ( ! $subscription ) {
        wp_die( esc_html__( 'Souscription introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_edit_subscription_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-subscriptions&action=edit&id=' . $id );

    $contract_id    = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $member_user_id = isset( $_POST['member_user_id'] ) ? absint( $_POST['member_user_id'] ) : 0;
    $group_id       = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $basket_size_id = isset( $_POST['basket_size_id'] ) ? absint( $_POST['basket_size_id'] ) : 0;
    $signed_at      = isset( $_POST['signed_at'] ) ? sanitize_text_field( wp_unslash( $_POST['signed_at'] ) ) : '';
    // quantities : voir amap_handle_add_subscription(), même principe de restitution en cas
    // d'erreur de validation plus bas dans le formulaire.
    $quantities     = isset( $_POST['quantity'] ) && is_array( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : array();
    $submitted      = compact( 'contract_id', 'member_user_id', 'group_id', 'basket_size_id', 'signed_at', 'quantities' );

    $contract           = $contract_id ? amap_get_contract( $contract_id ) : null;
    $valid_member_ids   = wp_list_pluck( amap_get_member_users(), 'ID' );
    $producer_group_ids = $contract ? array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) ) : array();

    if ( ! $contract || ! in_array( $member_user_id, $valid_member_ids, true )
        || ! $group_id || ! in_array( $group_id, $producer_group_ids, true ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid' );
        exit;
    }

    if ( ! amap_is_valid_date( $signed_at ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid_date' );
        exit;
    }

    if ( 'basket_recurring' === $contract->contract_type ) {
        $contract_basket_size = $basket_size_id ? amap_get_contract_basket_size( $basket_size_id ) : null;
        if ( ! $contract_basket_size || (int) $contract_basket_size->contract_id !== $contract_id ) {
            amap_store_subscription_form_data( $submitted );
            wp_safe_redirect( $edit_url . '&amap_notice=invalid' );
            exit;
        }
    } else {
        $basket_size_id = null;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'amap_subscriptions',
        array(
            'contract_id'    => $contract_id,
            'member_user_id' => $member_user_id,
            'group_id'       => $group_id,
            'basket_size_id' => $basket_size_id,
            'signed_at'      => $signed_at,
        ),
        array( 'id' => $id )
    );

    // Resynchronise entièrement la grille depuis la soumission (delete + réinsertion des cases
    // > 0, même logique qu'à la création) plutôt qu'un update ligne à ligne — plus simple pour un
    // formulaire qui soumet la grille en bloc. Si le contrat n'est plus product_grid (changé en
    // basket_recurring) ou si la grille n'a pas été soumise (JS non chargé, ancien lien sans
    // champ quantity), les subscription_items existants ne sont supprimés que dans le premier cas
    // : on évite de les effacer par erreur simplement parce que la grille n'a pas pu être rebâtie.
    if ( 'product_grid' === $contract->contract_type ) {
        if ( isset( $_POST['quantity'] ) && is_array( $_POST['quantity'] ) ) {
            $wpdb->delete( $wpdb->prefix . 'amap_subscription_items', array( 'subscription_id' => $id ) );
            amap_insert_subscription_items( $id, $contract_id, $group_id );
        }
    } else {
        $wpdb->delete( $wpdb->prefix . 'amap_subscription_items', array( 'subscription_id' => $id ) );
    }

    wp_safe_redirect( $edit_url );
    exit;
}

add_action( 'admin_post_amap_delete_subscription', 'amap_handle_delete_subscription' );

function amap_handle_delete_subscription() {
    if ( ! current_user_can( 'amap_manage_subscriptions' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    if ( ! $id || ! amap_get_subscription( $id ) ) {
        wp_die( esc_html__( 'Souscription introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_subscription_' . $id );

    global $wpdb;
    // Pas de contrainte FOREIGN KEY SQL sur subscription_id (cohérent avec le reste du plugin) :
    // nettoyage explicite des subscription_items et des congés orphelins, comme les tables
    // filles à la suppression d'un contrat.
    $wpdb->delete( $wpdb->prefix . 'amap_subscription_items', array( 'subscription_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_leaves', array( 'subscription_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_subscriptions', array( 'id' => $id ) );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions' ) );
    exit;
}

add_action( 'admin_post_amap_add_leave', 'amap_handle_add_leave' );

/**
 * Congé maraîcher (voir metier-producteurs.md) : ajouté depuis la section "Congés" nichée dans
 * la page "Souscriptions" (amap_render_subscriptions_page()), jamais un nouveau menu ni une
 * nouvelle capability — même logique que subscription_items niché dans le même formulaire à
 * l'étape 6. Le délai d'une semaine avant la distribution évoqué dans le plan n'est
 * volontairement pas vérifié ici : il ne concernera que la future auto-déclaration front,
 * pas la saisie de secours du bureau en admin ("admin est root").
 */
function amap_handle_add_leave() {
    if ( ! current_user_can( 'amap_manage_subscriptions' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
    $subscription     = $subscription_id ? amap_get_subscription( $subscription_id ) : null;
    if ( ! $subscription ) {
        wp_die( esc_html__( 'Souscription introuvable.', 'association-manager' ) );
    }

    $contract = amap_get_contract( $subscription->contract_id );
    if ( ! $contract || 'basket_recurring' !== $contract->contract_type ) {
        wp_die( esc_html__( "Cette souscription n'est pas concernée par les congés.", 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_leave_' . $subscription_id );

    $edit_url   = admin_url( 'admin.php?page=amap-subscriptions&action=edit&id=' . $subscription_id );
    $leave_date = isset( $_POST['leave_date'] ) ? sanitize_text_field( wp_unslash( $_POST['leave_date'] ) ) : '';

    if ( ! amap_is_valid_date( $leave_date ) ) {
        wp_safe_redirect( $edit_url . '&amap_notice=leave_invalid' );
        exit;
    }

    // Contrairement aux dates de livraison product_grid (amap_handle_add_contract_delivery_date(),
    // volontairement permissif pour les reports exceptionnels), un congé n'a de sens que sur un
    // vrai jour de distribution du groupe de l'adhérent : la date basket_recurring se déduit
    // uniquement du jour fixe du groupe et de la fréquence du contrat (aucune ligne stockée,
    // aucune exception possible pour l'instant), donc pas de cas légitime de congé "hors jour
    // habituel". amap_get_weekday_dates_in_range() couvre en un seul appel la période du contrat,
    // le jour de semaine ET la fréquence (hebdo/bimensuel/etc.), ancrée sur start_date.
    $group       = amap_get_group( $subscription->group_id );
    $valid_dates = $group
        ? amap_get_weekday_dates_in_range( $contract->start_date, $contract->end_date, (int) $group->weekday, (int) $contract->frequency_weeks )
        : array();

    if ( ! in_array( $leave_date, $valid_dates, true ) ) {
        wp_safe_redirect( $edit_url . '&amap_notice=leave_not_a_distribution_date' );
        exit;
    }

    if ( amap_subscription_has_leave( $subscription_id, $leave_date ) ) {
        wp_safe_redirect( $edit_url . '&amap_notice=leave_duplicate' );
        exit;
    }

    // Nombre de congés autorisés configurable par contrat (wp_amap_contracts.max_leaves) — ici
    // par souscription, cohérent avec le modèle de données (wp_amap_leaves.subscription_id) : un
    // adhérent qui souscrit plusieurs paniers maraîcher (foyer) dispose de max_leaves congés par
    // panier, pas au total.
    if ( count( amap_get_leaves( $subscription_id ) ) >= (int) $contract->max_leaves ) {
        wp_safe_redirect( $edit_url . '&amap_notice=leave_max_reached' );
        exit;
    }

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'amap_leaves',
        array(
            'subscription_id' => $subscription_id,
            'leave_date'      => $leave_date,
            'declared_at'     => current_time( 'Y-m-d' ),
        )
    );

    wp_safe_redirect( $edit_url . '&amap_notice=leave_saved' );
    exit;
}

add_action( 'admin_post_amap_delete_leave', 'amap_handle_delete_leave' );

function amap_handle_delete_leave() {
    if ( ! current_user_can( 'amap_manage_subscriptions' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $leave = $id ? amap_get_leave( $id ) : null;
    if ( ! $leave ) {
        wp_die( esc_html__( 'Congé introuvable.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_delete_leave_' . $id );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_leaves', array( 'id' => $id ) );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-subscriptions&action=edit&id=' . $leave->subscription_id . '&amap_notice=leave_deleted' ) );
    exit;
}
