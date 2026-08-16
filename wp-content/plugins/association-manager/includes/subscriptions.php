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

function amap_get_subscription( $id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amap_subscriptions WHERE id = %d", $id )
    );
}

/**
 * Utilisées avant une suppression (contrat, groupe, compte) pour bloquer si elle laisserait des
 * souscriptions orphelines (amap_handle_delete_contract(), amap_handle_delete_group(),
 * amap_handle_delete_user()) : préserve l'historique et les données de paiement déjà enregistrées
 * plutôt que de les effacer silencieusement en cascade.
 */
function amap_contract_has_subscriptions( $contract_id ) {
    global $wpdb;

    return (bool) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}amap_subscriptions WHERE contract_id = %d", $contract_id )
    );
}

function amap_group_has_subscriptions( $group_id ) {
    global $wpdb;

    return (bool) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}amap_subscriptions WHERE group_id = %d", $group_id )
    );
}

function amap_member_has_subscriptions( $member_user_id ) {
    global $wpdb;

    return (bool) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}amap_subscriptions WHERE member_user_id = %d", $member_user_id )
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
        // Même garde qu'amap_get_member_deliveries() : une ligne à quantité nulle ne compte pas
        // comme un item à livrer (amap_insert_subscription_items() n'en persiste pas aujourd'hui,
        // mais les deux lecteurs doivent rester d'accord sur l'invariant si ça change un jour).
        if ( (int) $row->quantity <= 0 ) {
            continue;
        }

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
 * contrats dont la période est "en cours" à $distribution_date (amap_get_contract_period_status())
 * sont pris en compte, et seulement s'ils ont effectivement quelque chose à livrer ce jour-là : un
 * contrat bimensuel hors semaine de livraison, ou sans aucune commande, est silencieusement
 * absent du résultat plutôt qu'affiché avec "0".
 */
function amap_get_group_deliveries( $group, array $producer_contracts, $distribution_date ) {
    $deliveries = array();

    foreach ( $producer_contracts as $contract ) {
        // Statut évalué à $distribution_date, pas à aujourd'hui : un contrat activé avant sa
        // start_date doit quand même apparaître ici si cette date de distribution est déjà
        // couverte (même correctif que amap_get_member_deliveries()).
        if ( 'active' !== amap_get_contract_period_status( $contract, $distribution_date ) ) {
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
 * Groupes ayant au moins une souscription pour ce contrat — sert à savoir sur quels groupes
 * proposer l'export "Résumé de saison" (amap_get_contract_season_summary_export_url()) : un
 * contrat peut être livré à plusieurs groupes, mais seuls ceux ayant effectivement des
 * souscriptions ont quelque chose à exporter.
 */
function amap_get_contract_subscription_group_ids( $contract_id ) {
    global $wpdb;

    return array_map(
        'intval',
        $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT group_id FROM {$wpdb->prefix}amap_subscriptions WHERE contract_id = %d",
                $contract_id
            )
        )
    );
}

/**
 * Lignes du résumé de saison pour un contrat basket_recurring, sur un groupe donné — une ligne par
 * adhérent souscrit : nom, téléphone, taille de panier, nombre total de distributions prévues sur
 * toute la période du contrat, congés déclarés (information logistique, n'affecte plus le montant
 * facturé — voir project_pricing_bug_basket_leaves_dynamic), distributions facturées (= prix
 * plein, donc égal au total) et montant dû (amap_get_subscription_price_summary()). Contrairement
 * à amap_get_contract_roster_rows() (fenêtre glissante pour le pointage terrain), couvre toute la
 * période du contrat et ajoute le montant — vue de fin de saison plutôt qu'outil de suivi
 * hebdomadaire.
 */
function amap_get_contract_basket_season_rows( $contract, $group ) {
    global $wpdb;

    $total_distributions = count(
        amap_get_weekday_dates_in_range( $contract->start_date, $contract->end_date, (int) $group->weekday, (int) $contract->frequency_weeks )
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

        $basket_size  = $subscription->basket_size_id ? amap_get_contract_basket_size( $subscription->basket_size_id ) : null;
        $leaves_count = count( amap_get_leaves( $subscription->id ) );
        $contact      = amap_get_user_contact( $subscription->member_user_id );
        $summary      = amap_get_subscription_price_summary( $subscription->id );

        $rows[] = array(
            'name'                 => trim( $member->last_name . ' ' . $member->first_name ),
            'phone'                => $contact->phone ?? '',
            'basket_size'          => $basket_size ? $basket_size->label : '',
            'total_distributions'  => $total_distributions,
            'leaves_count'         => $leaves_count,
            'billed_distributions' => $total_distributions,
            'amount'               => $summary['total'],
        );
    }

    return $rows;
}

/**
 * Lignes du résumé de saison pour un contrat product_grid, sur un groupe donné — une ligne par
 * adhérent souscrit, quantité totale commandée par produit sur toute la saison (toutes dates de
 * livraison confondues, contrairement à amap_get_contract_product_subscribers() qui ne regarde
 * qu'une seule date) et montant dû (amap_get_subscription_price_summary()).
 */
function amap_get_contract_product_season_rows( $contract, $group ) {
    global $wpdb;

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

        $quantities = array();
        foreach ( amap_get_subscription_items( $subscription->id ) as $item ) {
            $product_id                = (int) $item->contract_product_id;
            $quantities[ $product_id ] = ( $quantities[ $product_id ] ?? 0 ) + (int) $item->quantity;
        }

        $contact = amap_get_user_contact( $subscription->member_user_id );
        $summary = amap_get_subscription_price_summary( $subscription->id );

        $rows[] = array(
            'name'       => trim( $member->last_name . ' ' . $member->first_name ),
            'phone'      => $contact->phone ?? '',
            'quantities' => $quantities,
            'amount'     => $summary['total'],
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
 * Produits/paniers à récupérer par l'adhérent connecté à sa prochaine distribution — même forme
 * de retour que amap_get_group_deliveries() (une entrée par contrat, items = [{label, quantity}]),
 * mais agrégée uniquement sur les souscriptions DE CET ADHÉRENT (pas tous les adhérents du
 * groupe) : un contrat souscrit plusieurs fois (ex. 2 grands paniers + 1 petit) donne une seule
 * carte aux quantités cumulées, jamais une carte par souscription. $distribution_date doit être
 * la date calendaire brute de la distribution (`original_date` de amap_get_group_next_distribution(),
 * jamais `date` qui peut avoir été déplacée — voir le commentaire de cette fonction).
 *
 * Composée sur amap_get_contract_basket_subscribers()/amap_get_contract_product_subscribers()
 * (les mêmes fonctions que la vue producteur, amap_get_group_deliveries()) plutôt que de retyper
 * séparément les règles d'échéancier/congés/dates de livraison. Leur SQL filtre par
 * subscription->group_id : une souscription dont le groupe n'a pas été migré depuis la dernière
 * réaffectation de l'adhérent (amap_set_member_group() ne touche que wp_amap_group_members,
 * jamais wp_amap_subscriptions) reste donc absente ici — cohérent avec le roster/export CSV du
 * producteur, qui reposent sur les mêmes fonctions et la même colonne.
 */
function amap_get_member_deliveries( array $subscriptions, $group, $distribution_date ) {
    $subscription_ids = array_map(
        'intval',
        wp_list_pluck( wp_list_pluck( $subscriptions, 'subscription' ), 'id' )
    );

    $deliveries             = array();
    $processed_contract_ids = array();

    foreach ( $subscriptions as $entry ) {
        $contract    = $entry['contract'];
        $contract_id = $contract->id;

        if ( in_array( $contract_id, $processed_contract_ids, true ) ) {
            continue;
        }
        $processed_contract_ids[] = $contract_id;

        if ( 'active' !== amap_get_contract_period_status( $contract, $distribution_date ) ) {
            continue;
        }

        $is_basket   = ( 'basket_recurring' === $contract->contract_type );
        $subscribers = $is_basket
            ? amap_get_contract_basket_subscribers( $contract, $group, $distribution_date )
            : amap_get_contract_product_subscribers( $contract, $group, $distribution_date );

        if ( ! $subscribers ) {
            continue;
        }

        $items = array();
        foreach ( $subscribers as $subscriber ) {
            // amap_get_contract_basket_subscribers()/product_subscribers() renvoient TOUS les
            // souscripteurs du groupe à ce contrat : on ne garde que les souscriptions DE CET
            // ADHÉRENT (voir $subscription_ids), les autres appartiennent à d'autres adhérents du
            // même groupe.
            if ( ! in_array( (int) $subscriber['subscription']->id, $subscription_ids, true ) ) {
                continue;
            }

            if ( $is_basket ) {
                if ( ! $subscriber['basket_size'] ) {
                    continue;
                }
                $item_key = 'basket_' . $subscriber['basket_size']->id;
                if ( ! isset( $items[ $item_key ] ) ) {
                    $items[ $item_key ] = array(
                        'label'    => $subscriber['basket_size']->label,
                        'quantity' => 0,
                    );
                }
                ++$items[ $item_key ]['quantity'];
            } else {
                foreach ( $subscriber['items'] as $item ) {
                    $item_key = 'product_' . $item['product']->id;
                    if ( ! isset( $items[ $item_key ] ) ) {
                        $items[ $item_key ] = array(
                            'label'    => $item['product']->label,
                            'quantity' => 0,
                        );
                    }
                    $items[ $item_key ]['quantity'] += $item['quantity'];
                }
            }
        }

        if ( ! empty( $items ) ) {
            $deliveries[] = array(
                'contract' => $contract,
                'items'    => array_values( $items ),
            );
        }
    }

    return $deliveries;
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
 * Construit le corps HTML du récap de souscription (contrat, producteur, groupe, produits,
 * montant) — partagé entre l'email de confirmation (amap_send_subscription_confirmation_email())
 * et le PDF téléchargeable depuis l'espace membre (amap_handle_export_subscription_contract_pdf()
 * dans member-area.php). Recalcule toujours depuis $subscription_id plutôt que de figer un
 * contenu au moment de la signature : un adhérent qui télécharge son contrat plus tard voit l'état
 * actuel (ex. après une correction du bureau), comme le ferait un nouvel envoi de l'email.
 */
function amap_get_subscription_confirmation_email_body( $subscription_id ) {
    $subscription = amap_get_subscription( $subscription_id );
    if ( ! $subscription ) {
        return '';
    }

    $contract = amap_get_contract( $subscription->contract_id );
    $member   = get_user_by( 'id', $subscription->member_user_id );
    if ( ! $contract || ! $member ) {
        return '';
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

    $price_summary_html = amap_get_subscription_price_summary_html( $subscription_id );
    if ( $price_summary_html ) {
        $html_body .= '<h3>' . esc_html__( 'Montant dû', 'association-manager' ) . '</h3>';
        $html_body .= $price_summary_html;
    }

    return $html_body;
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
    $contract     = $subscription ? amap_get_contract( $subscription->contract_id ) : null;
    $member       = $subscription ? get_user_by( 'id', $subscription->member_user_id ) : null;
    if ( ! $contract || ! $member ) {
        return;
    }

    $html_body = amap_get_subscription_confirmation_email_body( $subscription_id );
    if ( ! $html_body ) {
        return;
    }

    // translators: %s: libellé du contrat.
    $subject = sprintf( __( 'Confirmation de votre souscription — %s', 'association-manager' ), $contract->label );

    amap_send_email( $member->user_email, $subject, amap_render_email( $subject, $html_body ) );
}

/**
 * Notifie le producteur qu'un adhérent a déclaré un congé sur l'une de ses souscriptions —
 * appelée uniquement depuis le parcours front (amap_handle_add_member_leave()) : la saisie de
 * congé par le bureau ("admin est root", amap_handle_add_leave()) ne le concerne pas, le bureau
 * étant déjà au courant de ce qu'il saisit lui-même.
 */
function amap_notify_producer_of_member_leave( $subscription_id, $leave_date ) {
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
    if ( ! $producer ) {
        return;
    }

    $html_body  = '<p>' . sprintf(
        /* translators: 1: nom affiché de l'adhérent. 2: libellé du contrat. */
        esc_html__( '%1$s a déclaré un congé sur le contrat « %2$s ».', 'association-manager' ),
        esc_html( $member->display_name ),
        esc_html( $contract->label )
    ) . '</p>';
    $html_body .= '<p>' . sprintf(
        /* translators: %s: date du congé. */
        esc_html__( 'Date concernée : %s.', 'association-manager' ),
        esc_html( date_i18n( 'j F Y', strtotime( $leave_date ) ) )
    ) . '</p>';

    // translators: %s: libellé du contrat.
    $subject = sprintf( __( 'Congé déclaré — %s', 'association-manager' ), $contract->label );

    amap_send_email( $producer->user_email, $subject, amap_render_email( $subject, $html_body ) );
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

/**
 * Montant dû d'une souscription, sur l'ensemble de la saison : une seule ligne pour un contrat
 * basket_recurring (amap_get_basket_subscription_price_summary()), ou pour un contrat
 * product_grid une ligne par produit hors famille de remise (quantité × prix) plus une ligne par
 * famille de remise dont au moins un produit a été commandé. Le seuil "achetés → facturés" d'une
 * famille de remise s'applique par date de livraison (pas sur l'agrégat de toute la saison, voir
 * project_pricing_bug_grid_discount_per_week) : les quantités facturées de chaque date sont
 * ensuite additionnées pour la ligne de synthèse. Retourne un tableau vide si la souscription n'a
 * aucune ligne à facturer (contrat introuvable, ou product_grid sans quantité commandée). Ne
 * tient pas compte des exceptions de distribution (wp_amap_distribution_exceptions) : une
 * distribution annulée reste comptée comme facturable, cas jugé assez rare ailleurs dans le
 * plugin pour ne pas complexifier ce calcul.
 */
function amap_get_subscription_price_summary( $subscription_id ) {
    $subscription = amap_get_subscription( $subscription_id );
    $contract     = $subscription ? amap_get_contract( $subscription->contract_id ) : null;

    if ( ! $contract ) {
        return array(
            'lines' => array(),
            'total' => 0.0,
        );
    }

    if ( 'basket_recurring' === $contract->contract_type ) {
        return amap_get_basket_subscription_price_summary( $subscription, $contract );
    }

    $products_by_id = array();
    foreach ( amap_get_contract_products( $contract->id ) as $product ) {
        $products_by_id[ (int) $product->id ] = $product;
    }

    $discount_groups_by_id = array();
    foreach ( amap_get_contract_discount_groups( $contract->id ) as $group ) {
        $discount_groups_by_id[ (int) $group->id ] = $group;
    }

    $quantity_by_product = array();
    $items_by_date        = array();
    foreach ( amap_get_subscription_items( $subscription_id ) as $item ) {
        $product_id = (int) $item->contract_product_id;
        $date_id    = (int) $item->contract_delivery_date_id;
        $quantity   = (int) $item->quantity;

        $quantity_by_product[ $product_id ]       = ( $quantity_by_product[ $product_id ] ?? 0 ) + $quantity;
        $items_by_date[ $date_id ][ $product_id ] = ( $items_by_date[ $date_id ][ $product_id ] ?? 0 ) + $quantity;
    }

    $lines = array();
    $total = 0.0;

    foreach ( $quantity_by_product as $product_id => $quantity ) {
        $product = $products_by_id[ $product_id ] ?? null;
        if ( ! $product || $product->discount_group_id ) {
            continue;
        }

        $amount  = $quantity * (float) $product->price;
        $total  += $amount;
        $lines[] = array(
            'label'           => $product->label,
            'bought_quantity' => $quantity,
            'billed_quantity' => $quantity,
            'unit_price'      => (float) $product->price,
            'amount'          => $amount,
        );
    }

    // Remise par palier appliquée par date de livraison, pas sur l'agrégat de toute la saison : un
    // même total acheté peut être facturé différemment selon sa répartition dans le temps (ex.
    // 5/semaine × 4 semaines déclenche la remise chaque semaine, 4/semaine × 5 semaines ne
    // l'atteint jamais, bien que le total sur la saison soit identique dans les deux cas).
    $bought_by_group = array();
    $billed_by_group = array();

    foreach ( $items_by_date as $date_quantities ) {
        $quantity_by_group_this_date = array();
        foreach ( $date_quantities as $product_id => $quantity ) {
            $product = $products_by_id[ $product_id ] ?? null;
            if ( ! $product || ! $product->discount_group_id ) {
                continue;
            }
            $group_id = (int) $product->discount_group_id;
            $quantity_by_group_this_date[ $group_id ] = ( $quantity_by_group_this_date[ $group_id ] ?? 0 ) + $quantity;
        }

        foreach ( $quantity_by_group_this_date as $group_id => $quantity ) {
            $group = $discount_groups_by_id[ $group_id ] ?? null;
            if ( ! $group ) {
                continue;
            }

            $full_batches    = (int) floor( $quantity / (int) $group->bought_quantity );
            $billed_quantity = $full_batches * (int) $group->billed_quantity + ( $quantity % (int) $group->bought_quantity );

            $bought_by_group[ $group_id ] = ( $bought_by_group[ $group_id ] ?? 0 ) + $quantity;
            $billed_by_group[ $group_id ] = ( $billed_by_group[ $group_id ] ?? 0 ) + $billed_quantity;
        }
    }

    foreach ( $billed_by_group as $group_id => $billed_quantity ) {
        $group  = $discount_groups_by_id[ $group_id ];
        $amount = $billed_quantity * (float) $group->price;
        $total += $amount;

        $lines[] = array(
            'label'           => $group->label,
            'bought_quantity' => $bought_by_group[ $group_id ],
            'billed_quantity' => $billed_quantity,
            'unit_price'      => (float) $group->price,
            'amount'          => $amount,
        );
    }

    return array(
        'lines' => $lines,
        'total' => $total,
    );
}

/**
 * Détail de amap_get_subscription_price_summary() pour un contrat basket_recurring : une seule
 * ligne, quantité facturable = nombre de distributions de la période du contrat
 * (amap_get_weekday_dates_in_range(), ancré sur le jour fixe du groupe de retrait et le pas
 * frequency_weeks du contrat), prix plein sur toute cette période. Les congés déjà déclarés
 * (wp_amap_leaves) n'affectent jamais ce montant : le prix est fixé à la souscription, un congé
 * n'est qu'une information logistique pour le producteur (ce qu'il ne doit pas préparer telle
 * semaine), pas un motif de remboursement — voir project_pricing_bug_basket_leaves_dynamic.
 * is_paid/paid_at (wp_amap_subscriptions) restent la seule source de vérité sur ce qui a été
 * réellement payé.
 */
function amap_get_basket_subscription_price_summary( $subscription, $contract ) {
    $empty = array(
        'lines' => array(),
        'total' => 0.0,
    );

    if ( ! $subscription->basket_size_id ) {
        return $empty;
    }

    $group       = amap_get_group( $subscription->group_id );
    $basket_size = amap_get_contract_basket_size( $subscription->basket_size_id );
    if ( ! $group || ! $basket_size ) {
        return $empty;
    }

    $distribution_count = count(
        amap_get_weekday_dates_in_range(
            $contract->start_date,
            $contract->end_date,
            (int) $group->weekday,
            (int) $contract->frequency_weeks
        )
    );
    $amount = $distribution_count * (float) $basket_size->price;

    return array(
        'lines' => array(
            array(
                'label'           => $basket_size->label,
                'bought_quantity' => $distribution_count,
                'billed_quantity' => $distribution_count,
                'unit_price'      => (float) $basket_size->price,
                'amount'          => $amount,
            ),
        ),
        'total' => $amount,
    );
}

/**
 * Rendu HTML du montant dû (amap_get_subscription_price_summary()), réutilisé tel quel dans
 * l'email de confirmation, l'espace adhérent et la fiche souscription en admin : un tableau
 * suffisamment simple (styles en attributs, comme amap_render_email()) pour s'afficher
 * correctement dans les trois contextes sans dépendre d'une feuille de style particulière.
 * Chaîne vide si la souscription n'a aucune ligne à facturer.
 */
function amap_get_subscription_price_summary_html( $subscription_id ) {
    $summary = amap_get_subscription_price_summary( $subscription_id );

    if ( empty( $summary['lines'] ) ) {
        return '';
    }

    ob_start();
    ?>
    <table style="width:100%; border-collapse:collapse; margin-top:8px;">
        <tbody>
            <?php foreach ( $summary['lines'] as $line ) : ?>
                <tr>
                    <td style="padding:4px 8px 4px 0; border-bottom:1px solid #ddd;">
                        <?php echo esc_html( $line['label'] ); ?><br>
                        <small>
                            <?php if ( $line['bought_quantity'] !== $line['billed_quantity'] ) : ?>
                                <?php
                                // translators: 1: quantité achetée, 2: quantité facturée.
                                printf(
                                    esc_html__( '%1$d achetés → %2$d facturés', 'association-manager' ),
                                    $line['bought_quantity'],
                                    $line['billed_quantity']
                                );
                                ?>
                            <?php else : ?>
                                <?php
                                // translators: 1: quantité commandée, 2: prix unitaire.
                                printf(
                                    esc_html__( '%1$d × %2$s €', 'association-manager' ),
                                    $line['bought_quantity'],
                                    esc_html( number_format_i18n( $line['unit_price'], 2 ) )
                                );
                                ?>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td style="padding:4px 0 4px 8px; border-bottom:1px solid #ddd; text-align:right; white-space:nowrap;">
                        <?php echo esc_html( number_format_i18n( $line['amount'], 2 ) ); ?> €
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td style="padding:8px 8px 4px 0; font-weight:600;"><?php esc_html_e( 'Total', 'association-manager' ); ?></td>
                <td style="padding:8px 0 4px 8px; font-weight:600; text-align:right; white-space:nowrap;">
                    <?php echo esc_html( number_format_i18n( $summary['total'], 2 ) ); ?> €
                </td>
            </tr>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
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

    amap_notify_producer_of_member_leave( $subscription_id, $leave_date );

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

    // Même principe que amap_handle_add_user() : le formulaire (wp-admin ou section
    // "Souscriptions" de l'espace bureau front) précise sa page de retour via ce champ caché.
    $redirect_base = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-subscriptions' );

    $contract_id    = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $member_user_id = isset( $_POST['member_user_id'] ) ? absint( $_POST['member_user_id'] ) : 0;
    $group_id       = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $basket_size_id = isset( $_POST['basket_size_id'] ) ? absint( $_POST['basket_size_id'] ) : 0;
    $signed_at      = isset( $_POST['signed_at'] ) ? sanitize_text_field( wp_unslash( $_POST['signed_at'] ) ) : '';
    // is_paid/paid_at : paid_at forcé à NULL si la case n'est pas cochée, ou à aujourd'hui si
    // cochée sans date valide fournie — même principe que basket_size_id forcé à NULL plus bas
    // selon le type de contrat.
    $is_paid        = ! empty( $_POST['is_paid'] );
    $paid_at        = isset( $_POST['paid_at'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_at'] ) ) : '';
    if ( ! $is_paid ) {
        $paid_at = null;
    } elseif ( ! amap_is_valid_date( $paid_at ) ) {
        $paid_at = current_time( 'Y-m-d' );
    }
    // quantities : grille produits×dates saisie pour un contrat product_grid, revalidée et
    // insérée après la création de la souscription (amap_insert_subscription_items()) ; stockée
    // dans $submitted comme les autres champs pour être restituée si le formulaire est invalide.
    $quantities     = isset( $_POST['quantity'] ) && is_array( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : array();
    $submitted      = compact( 'contract_id', 'member_user_id', 'group_id', 'basket_size_id', 'signed_at', 'is_paid', 'paid_at', 'quantities' );

    $contract           = $contract_id ? amap_get_contract( $contract_id ) : null;
    $valid_member_ids   = wp_list_pluck( amap_get_member_users(), 'ID' );
    $producer_group_ids = $contract ? array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) ) : array();

    if ( ! $contract || ! in_array( $member_user_id, $valid_member_ids, true )
        || ! $group_id || ! in_array( $group_id, $producer_group_ids, true ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $redirect_base ) );
        exit;
    }

    if ( ! amap_is_valid_date( $signed_at ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_date', $redirect_base ) );
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
            wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $redirect_base ) );
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
            'is_paid'        => $is_paid ? 1 : 0,
            'paid_at'        => $paid_at,
        )
    );

    if ( 'product_grid' === $contract->contract_type ) {
        amap_insert_subscription_items( $wpdb->insert_id, $contract_id, $group_id );
    }

    wp_safe_redirect( add_query_arg( 'amap_notice', 'created', $redirect_base ) );
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

    // Même principe que amap_handle_update_user() : la présence de "redirect_to" distingue le
    // formulaire front (espace bureau) de celui de wp-admin, et donne l'URL de LISTE à utiliser —
    // l'URL de retour sur CETTE souscription (succès ou erreur) est dérivée pour rester cohérente.
    $is_front_request = isset( $_POST['redirect_to'] );
    $edit_url          = $is_front_request ? amap_get_board_subscription_edit_url( $id ) : admin_url( 'admin.php?page=amap-subscriptions&action=edit&id=' . $id );

    $contract_id    = isset( $_POST['contract_id'] ) ? absint( $_POST['contract_id'] ) : 0;
    $member_user_id = isset( $_POST['member_user_id'] ) ? absint( $_POST['member_user_id'] ) : 0;
    $group_id       = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $basket_size_id = isset( $_POST['basket_size_id'] ) ? absint( $_POST['basket_size_id'] ) : 0;
    $signed_at      = isset( $_POST['signed_at'] ) ? sanitize_text_field( wp_unslash( $_POST['signed_at'] ) ) : '';
    // is_paid/paid_at : voir amap_handle_add_subscription(), même principe de dérivation.
    $is_paid        = ! empty( $_POST['is_paid'] );
    $paid_at        = isset( $_POST['paid_at'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_at'] ) ) : '';
    if ( ! $is_paid ) {
        $paid_at = null;
    } elseif ( ! amap_is_valid_date( $paid_at ) ) {
        $paid_at = current_time( 'Y-m-d' );
    }
    // quantities : voir amap_handle_add_subscription(), même principe de restitution en cas
    // d'erreur de validation plus bas dans le formulaire.
    $quantities     = isset( $_POST['quantity'] ) && is_array( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : array();
    $submitted      = compact( 'contract_id', 'member_user_id', 'group_id', 'basket_size_id', 'signed_at', 'is_paid', 'paid_at', 'quantities' );

    $contract           = $contract_id ? amap_get_contract( $contract_id ) : null;
    $valid_member_ids   = wp_list_pluck( amap_get_member_users(), 'ID' );
    $producer_group_ids = $contract ? array_map( 'absint', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) ) : array();

    if ( ! $contract || ! in_array( $member_user_id, $valid_member_ids, true )
        || ! $group_id || ! in_array( $group_id, $producer_group_ids, true ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $edit_url ) );
        exit;
    }

    if ( ! amap_is_valid_date( $signed_at ) ) {
        amap_store_subscription_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_date', $edit_url ) );
        exit;
    }

    if ( 'basket_recurring' === $contract->contract_type ) {
        $contract_basket_size = $basket_size_id ? amap_get_contract_basket_size( $basket_size_id ) : null;
        if ( ! $contract_basket_size || (int) $contract_basket_size->contract_id !== $contract_id ) {
            amap_store_subscription_form_data( $submitted );
            wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $edit_url ) );
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
            'is_paid'        => $is_paid ? 1 : 0,
            'paid_at'        => $paid_at,
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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'updated', $edit_url ) );
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

    // Même principe que amap_handle_delete_user() : "Supprimer" est un lien, pas un formulaire
    // posté, la page de retour arrive donc en paramètre d'URL.
    $redirect_url = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-subscriptions' );

    global $wpdb;
    // Pas de contrainte FOREIGN KEY SQL sur subscription_id (cohérent avec le reste du plugin) :
    // nettoyage explicite des subscription_items et des congés orphelins, comme les tables
    // filles à la suppression d'un contrat.
    $wpdb->delete( $wpdb->prefix . 'amap_subscription_items', array( 'subscription_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_leaves', array( 'subscription_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_subscriptions', array( 'id' => $id ) );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'deleted', $redirect_url ) );
    exit;
}

add_action( 'admin_post_amap_add_leave', 'amap_handle_add_leave' );

/**
 * Congé maraîcher (voir metier-producteurs.md) : ajouté depuis le bloc "Congés" niché dans la
 * section "Souscriptions" de l'espace bureau (member-area-board-subscription-form.php, dans le
 * thème), jamais un nouveau menu ni une nouvelle capability — même logique que subscription_items
 * niché dans le même formulaire à l'étape 6. Le délai d'une semaine avant la distribution évoqué
 * dans le plan n'est
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

    // Même principe que amap_handle_update_subscription() : le champ caché "redirect_to" indique
    // l'URL de retour sur CETTE souscription (front ou wp-admin).
    $edit_url   = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-subscriptions&action=edit&id=' . $subscription_id );
    $leave_date = isset( $_POST['leave_date'] ) ? sanitize_text_field( wp_unslash( $_POST['leave_date'] ) ) : '';

    if ( ! amap_is_valid_date( $leave_date ) ) {
        wp_safe_redirect( add_query_arg( 'amap_notice', 'leave_invalid', $edit_url ) );
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
        wp_safe_redirect( add_query_arg( 'amap_notice', 'leave_not_a_distribution_date', $edit_url ) );
        exit;
    }

    if ( amap_subscription_has_leave( $subscription_id, $leave_date ) ) {
        wp_safe_redirect( add_query_arg( 'amap_notice', 'leave_duplicate', $edit_url ) );
        exit;
    }

    // Nombre de congés autorisés configurable par contrat (wp_amap_contracts.max_leaves) — ici
    // par souscription, cohérent avec le modèle de données (wp_amap_leaves.subscription_id) : un
    // adhérent qui souscrit plusieurs paniers maraîcher (foyer) dispose de max_leaves congés par
    // panier, pas au total.
    if ( count( amap_get_leaves( $subscription_id ) ) >= (int) $contract->max_leaves ) {
        wp_safe_redirect( add_query_arg( 'amap_notice', 'leave_max_reached', $edit_url ) );
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

    wp_safe_redirect( add_query_arg( 'amap_notice', 'leave_saved', $edit_url ) );
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

    // Même principe que amap_handle_delete_subscription() : lien, pas formulaire posté.
    $edit_url = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : admin_url( 'admin.php?page=amap-subscriptions&action=edit&id=' . $leave->subscription_id );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_leaves', array( 'id' => $id ) );

    wp_safe_redirect( add_query_arg( 'amap_notice', 'leave_deleted', $edit_url ) );
    exit;
}
