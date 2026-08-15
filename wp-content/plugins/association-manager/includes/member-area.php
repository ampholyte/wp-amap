<?php
/**
 * Espace adhérent front-end : affichage des casquettes et édition du profil.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'show_admin_bar', 'amap_hide_admin_bar_on_member_area' );

/**
 * La coquille "app" de l'espace membre (header-app.php) ne laisse aucune place à la barre
 * d'outils WordPress : sa police/ses interlignes (propres au cœur WordPress, pas au thème) la
 * font ressortir au-dessus de la barre d'identité de l'espace membre. is_page() n'est pas encore
 * fiable ici (le filtre 'show_admin_bar' est appliqué une seule fois, mis en cache dès 'init' —
 * donc avant amap_maybe_render_member_area(), qui tourne sur template_redirect) : on regarde
 * directement l'URL plutôt que d'attendre que la requête principale soit résolue.
 */
function amap_hide_admin_bar_on_member_area( $show ) {
    if ( false !== strpos( $_SERVER['REQUEST_URI'] ?? '', '/espace-adherent' ) ) {
        return false;
    }

    return $show;
}

add_action( 'template_redirect', 'amap_maybe_render_member_area', 5 );

/**
 * Espace membre minimal pour un utilisateur déjà connecté sur "espace-adherent". Priorité 5
 * (les écrans du parcours de connexion ci-dessus sont en priorité par défaut 10) pour s'exécuter
 * avant eux et court-circuiter systématiquement l'affichage d'un écran de connexion à un
 * utilisateur déjà connecté, quel que soit le paramètre de requête présent dans l'URL (ex. lien
 * de login gardé en favori après connexion).
 */
function amap_maybe_render_member_area() {
    if ( ! is_page( 'espace-adherent' ) || ! is_user_logged_in() ) {
        return;
    }

    $user             = wp_get_current_user();
    $is_member        = in_array( 'amap_member', $user->roles, true );
    $is_producer      = in_array( 'amap_producer', $user->roles, true );
    $is_board         = in_array( 'amap_board', $user->roles, true );
    // Nom/prénom/email/téléphone/adresse sont liés au compte (user_id), pas à une casquette
    // particulière : accessibles dès qu'au moins une casquette AMAP est portée.
    $is_amap_user     = $is_member || $is_producer || $is_board;
    $can_manage_users = current_user_can( 'amap_manage_users' );
    $action           = isset( $_GET['amap_member_action'] ) ? sanitize_key( wp_unslash( $_GET['amap_member_action'] ) ) : '';
    $notice           = isset( $_GET['amap_member_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_member_notice'] ) ) : '';

    // Un onglet par casquette portée (dans cet ordre de priorité par défaut), plus "profile"
    // toujours accessible dès qu'au moins une casquette AMAP est portée. "Espace bureau" n'est
    // pas un onglet : lien direct vers wp-admin (member-area-nav.php).
    $available_tabs = array();
    if ( $is_member ) {
        $available_tabs[] = 'member';
    }
    if ( $is_producer ) {
        $available_tabs[] = 'producer';
    }
    if ( $is_amap_user ) {
        $available_tabs[] = 'profile';
    }
    $requested_tab = isset( $_GET['amap_tab'] ) ? sanitize_key( wp_unslash( $_GET['amap_tab'] ) ) : '';
    $tab           = in_array( $requested_tab, $available_tabs, true ) ? $requested_tab : reset( $available_tabs );

    // Le formulaire de souscription valide (et wp_die()/redirige si besoin) AVANT tout affichage
    // — même principe que amap_maybe_render_magic_link_confirmation() — pour ne jamais laisser
    // échapper un en-tête de page avant un wp_die() ou une redirection.
    $subscribe_form_data = null;
    if ( $is_member && 'subscribe' === $action ) {
        $subscribe_form_data = amap_get_member_subscribe_form_data( $user );
    }

    $leave_form_data = null;
    if ( $is_member && 'declare_leave' === $action ) {
        $leave_form_data = amap_get_member_leave_form_data( $user );
    }

    // Export CSV : jamais de page à rendre, amap_handle_export_contract_roster() /
    // amap_handle_export_contract_products() / amap_handle_export_contract_season_summary()
    // envoient le fichier et terminent la requête elles-mêmes — doit donc s'exécuter avant
    // get_header().
    if ( $is_producer && 'export_contract_roster' === $action ) {
        amap_handle_export_contract_roster( $user );
    }

    if ( $is_producer && 'export_contract_products' === $action ) {
        amap_handle_export_contract_products( $user );
    }

    if ( $is_producer && 'export_contract_season_summary' === $action ) {
        amap_handle_export_contract_season_summary( $user );
    }

    get_header( 'app' );
    ?>
    <main>
    <?php
    if ( $is_amap_user && 'edit_profile' === $action ) {
        amap_render_member_profile_edit_form( $user );
    } elseif ( $subscribe_form_data ) {
        get_template_part( 'template-parts/login/member-area-subscribe', null, $subscribe_form_data );
    } elseif ( $leave_form_data ) {
        get_template_part( 'template-parts/login/member-area-leave', null, $leave_form_data );
    } else {
        get_template_part(
            'template-parts/login/member-area',
            null,
            array(
                'is_member'        => $is_member,
                'is_producer'      => $is_producer,
                'is_board'         => $is_board,
                'is_amap_user'     => $is_amap_user,
                'can_manage_users' => $can_manage_users,
                'tab'              => $tab,
                'notice'           => $notice,
            )
        );
    }
    ?>
    </main>
    <?php
    get_footer( 'app' );
    exit;
}

/**
 * Valide le contrat visé par ?amap_member_action=subscribe&contract_id=X et prépare les données
 * du formulaire (member-area-subscribe.php). Un contrat inactif/inexistant, ou dont le
 * producteur ne livre pas le groupe de l'adhérent, ne peut venir que d'un lien périmé ou
 * trafiqué, vu que la liste des "contrats disponibles" (amap_get_available_contracts_for_member())
 * ne les propose jamais — wp_die() dans ces cas. Souscrire plusieurs fois au même contrat est
 * volontairement permis (voir amap_get_available_contracts_for_member()), donc pas de vérif de
 * doublon ici. L'absence de groupe rattaché redirige vers l'onglet adhérent, qui explique déjà la
 * marche à suivre. En revanche, une taille de panier / un produit / une date de livraison
 * manquants relèvent d'une configuration incomplète côté bureau, pas d'une tentative de trafiquer
 * la requête : un contrat proposé normalement à la souscription peut très bien y mener, donc ces
 * cas retournent un tableau avec une clé 'error' (affiché en notice par member-area-subscribe.php)
 * plutôt qu'un wp_die().
 */
function amap_get_member_subscribe_form_data( $user ) {
    $contract_id = isset( $_GET['contract_id'] ) ? absint( $_GET['contract_id'] ) : 0;
    $contract    = $contract_id ? amap_get_contract( $contract_id ) : null;

    if ( ! $contract || ! $contract->is_active ) {
        wp_die( esc_html__( "Ce contrat n'est pas ouvert à la souscription.", 'association-manager' ) );
    }

    // Point de retrait fixe de l'adhérent (fixé par le bureau, page "Utilisateurs AMAP"), jamais
    // un choix laissé au formulaire — voir amap_get_available_contracts_for_member().
    $member_group = amap_get_member_group( $user->ID );
    if ( ! $member_group ) {
        wp_safe_redirect( amap_get_member_area_tab_url( 'member' ) );
        exit;
    }

    $producer_group_ids = array_map( 'intval', wp_list_pluck( amap_get_producer_groups( $contract->producer_user_id ), 'id' ) );
    if ( ! in_array( (int) $member_group->id, $producer_group_ids, true ) ) {
        wp_die( esc_html__( "Ce contrat n'est pas disponible pour votre groupe.", 'association-manager' ) );
    }

    $basket_sizes    = array();
    $products        = array();
    $delivery_dates  = array();
    $discount_groups = array();

    if ( 'basket_recurring' === $contract->contract_type ) {
        $basket_sizes = array_map(
            static function ( $size ) {
                return array(
                    'id'    => (int) $size->id,
                    'label' => $size->label,
                    'price' => (float) $size->price,
                );
            },
            amap_get_contract_basket_sizes( $contract->id )
        );

        if ( empty( $basket_sizes ) ) {
            return array( 'error' => 'no_basket_sizes' );
        }
    } else {
        // price/discount_group_id (bruts, pas seulement le libellé déjà formaté) : nécessaires
        // pour calculer les totaux en direct côté front (member-area-subscribe.php), sans
        // dupliquer l'arrondi/la mise en forme monétaire déjà faite par number_format_i18n().
        $products = array_map(
            static function ( $product ) {
                return array(
                    'id'                => (int) $product->id,
                    'label'             => $product->label,
                    'price'             => (float) $product->price,
                    'discount_group_id' => $product->discount_group_id ? (int) $product->discount_group_id : null,
                );
            },
            amap_get_contract_products( $contract->id )
        );

        if ( empty( $products ) ) {
            return array( 'error' => 'no_products' );
        }

        // Même calcul de remise par palier que amap_get_subscription_price_summary() : appliquée
        // par date de livraison (voir son commentaire), puis sommée sur la saison — le total en
        // direct annonce donc le même montant que celui réellement facturé après confirmation.
        $discount_groups = array_map(
            static function ( $group ) {
                return array(
                    'id'              => (int) $group->id,
                    'label'           => $group->label,
                    'price'           => (float) $group->price,
                    'bought_quantity' => (int) $group->bought_quantity,
                    'billed_quantity' => (int) $group->billed_quantity,
                    'note'            => sprintf(
                        /* translators: 1: nom du groupe de remise. 2: quantité achetée déclenchant la remise. 3: quantité facturée correspondante. */
                        __( '%1$s : remise « %2$d achetés → %3$d facturés » appliquée par date de livraison, non reflétée dans les totaux ci-dessus par produit (détail exact à la confirmation).', 'association-manager' ),
                        $group->label,
                        (int) $group->bought_quantity,
                        (int) $group->billed_quantity
                    ),
                );
            },
            amap_get_contract_discount_groups( $contract->id )
        );

        // Groupe déjà fixé (contrairement à l'admin, qui construit les dates de tous les
        // groupes puisque le bureau choisit le groupe dans le même formulaire) : seules les
        // dates du groupe de l'adhérent sont nécessaires ici.
        foreach ( amap_get_contract_delivery_dates( $contract->id ) as $delivery_date_row ) {
            if ( (int) $delivery_date_row->group_id !== (int) $member_group->id ) {
                continue;
            }

            $delivery_dates[] = array(
                'id'          => (int) $delivery_date_row->id,
                'label'       => date_i18n( 'j F Y', strtotime( $delivery_date_row->delivery_date ) ),
                'short_label' => amap_get_short_date_label( $delivery_date_row->delivery_date ),
            );
        }

        if ( empty( $delivery_dates ) ) {
            return array( 'error' => 'no_delivery_dates' );
        }
    }

    return array(
        'contract'        => $contract,
        'producer'        => get_user_by( 'id', $contract->producer_user_id ),
        'group'           => $member_group,
        'basket_sizes'    => $basket_sizes,
        'products'        => $products,
        'delivery_dates'  => $delivery_dates,
        'discount_groups' => $discount_groups,
    );
}

/**
 * Valide la souscription visée par ?amap_member_action=declare_leave&subscription_id=X et
 * prépare les données du formulaire (member-area-leave.php). Contrairement à
 * amap_get_member_subscribe_form_data() (qui valide un contrat public), la souscription doit en
 * plus appartenir à l'utilisateur connecté — sinon wp_die(), un adhérent ne doit jamais pouvoir
 * agir sur la souscription d'un autre en changeant l'ID dans l'URL.
 *
 * Les dates proposées dans le formulaire sont calculées ici plutôt que saisies librement par
 * l'adhérent (contrairement à l'admin) : jour de semaine du groupe, période du contrat, délai
 * d'une semaine et non déjà déclarées — toutes les règles de amap_handle_add_leave() sont ainsi
 * satisfaites par construction, un parcours normal ne peut donc jamais déclencher les wp_die() de
 * amap_handle_add_member_leave().
 */
function amap_get_member_leave_form_data( $user ) {
    $subscription_id = isset( $_GET['subscription_id'] ) ? absint( $_GET['subscription_id'] ) : 0;
    $subscription    = $subscription_id ? amap_get_subscription( $subscription_id ) : null;
    if ( ! $subscription || (int) $subscription->member_user_id !== $user->ID ) {
        wp_die( esc_html__( 'Souscription introuvable.', 'association-manager' ) );
    }

    $contract = amap_get_contract( $subscription->contract_id );
    if ( ! $contract || 'basket_recurring' !== $contract->contract_type ) {
        wp_die( esc_html__( "Cette souscription n'est pas concernée par les congés.", 'association-manager' ) );
    }

    $group  = amap_get_group( $subscription->group_id );
    $leaves = amap_get_leaves( $subscription_id );

    $available_dates = array();
    if ( $group && count( $leaves ) < (int) $contract->max_leaves ) {
        $min_date    = ( new DateTime( current_time( 'Y-m-d' ) ) )->modify( '+7 days' )->format( 'Y-m-d' );
        $taken_dates = wp_list_pluck( $leaves, 'leave_date' );

        // Ancré sur start_date et espacé de frequency_weeks semaines : ne propose que les
        // vraies dates de distribution d'un contrat bimensuel/etc., pas toutes les occurrences
        // du jour de semaine (voir amap_get_weekday_dates_in_range()).
        foreach ( amap_get_weekday_dates_in_range( $contract->start_date, $contract->end_date, (int) $group->weekday, (int) $contract->frequency_weeks ) as $candidate_date ) {
            if ( $candidate_date < $min_date || in_array( $candidate_date, $taken_dates, true ) ) {
                continue;
            }

            $available_dates[] = array(
                'date'  => $candidate_date,
                'label' => date_i18n( 'l j F Y', strtotime( $candidate_date ) ),
            );
        }
    }

    return array(
        'subscription'     => $subscription,
        'contract'         => $contract,
        'producer'         => get_user_by( 'id', $contract->producer_user_id ),
        'group'            => $group,
        'leaves'           => $leaves,
        'available_dates'  => $available_dates,
    );
}

/**
 * Valide ?amap_member_action=export_contract_roster&contract_id=X&group_id=Y (bouton "Détail" de
 * la carte "Produits à livrer", onglet "Espace producteur") et envoie directement le fichier CSV
 * de pointage — jamais de page rendue, contrairement aux autres actions de ce fichier. wp_die()
 * sur un contrat/groupe trafiqué, non basket_recurring, ou n'appartenant pas au producteur
 * connecté : l'UI ne propose jamais un tel lien.
 *
 * Fenêtre glissante de 30 jours à partir d'aujourd'hui plutôt que le mois calendaire strict, pour
 * ne pas se retrouver avec seulement 2-3 jours utiles en fin de mois.
 */
function amap_handle_export_contract_roster( $producer ) {
    $contract_id = isset( $_GET['contract_id'] ) ? absint( $_GET['contract_id'] ) : 0;
    $group_id    = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;

    $contract = $contract_id ? amap_get_contract( $contract_id ) : null;
    $group    = $group_id ? amap_get_group( $group_id ) : null;

    if ( ! $contract || ! $group
        || 'basket_recurring' !== $contract->contract_type
        || (int) $contract->producer_user_id !== $producer->ID
        || ! in_array( $group_id, array_map( 'intval', wp_list_pluck( amap_get_producer_groups( $producer->ID ), 'id' ) ), true )
    ) {
        wp_die( esc_html__( 'Export non autorisé.', 'association-manager' ) );
    }

    $window_start = current_time( 'Y-m-d' );
    $window_end   = ( new DateTime( $window_start ) )->modify( '+29 days' )->format( 'Y-m-d' );
    $window_dates = amap_get_weekday_dates_in_range( $window_start, $window_end, (int) $group->weekday );
    $rows         = amap_get_contract_roster_rows( $contract, $group, $window_start, $window_end );

    nocache_headers();
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $contract->label . '-' . $group->name . '.csv' ) . '"' );

    $output = fopen( 'php://output', 'w' );
    // BOM UTF-8 : sans lui, Excel affiche les accents mal encodés à l'ouverture directe du fichier.
    fwrite( $output, "\xEF\xBB\xBF" );

    fputcsv(
        $output,
        array_merge(
            array(
                __( 'Nom Prénom', 'association-manager' ),
                __( 'Téléphone', 'association-manager' ),
                __( 'Congés déposés', 'association-manager' ),
                __( 'Distributions faites', 'association-manager' ),
            ),
            array_map(
                static function ( $date ) {
                    return date_i18n( 'd/m', strtotime( $date ) );
                },
                $window_dates
            ),
            array( __( 'Commentaire', 'association-manager' ) )
        ),
        ';'
    );

    foreach ( $rows as $row ) {
        fputcsv(
            $output,
            array_merge(
                array( $row['name'], $row['phone'], $row['leaves_count'], $row['done_count'] ),
                $row['statuses'],
                array( '' )
            ),
            ';'
        );
    }

    fclose( $output );
    exit;
}

/**
 * Valide ?amap_member_action=export_contract_products&contract_id=X&group_id=Y&distribution_date=Z
 * (bouton "Détail (CSV)" de la carte "Produits à livrer" pour un contrat product_grid) et envoie
 * directement le fichier CSV nominatif des commandes de cette distribution — même principe que
 * amap_handle_export_contract_roster(), mais un fichier par distribution (une seule date, pas de
 * fenêtre glissante) et des colonnes produits plutôt que des colonnes dates. wp_die() sur un
 * contrat/groupe/date trafiqué, non product_grid, n'appartenant pas au producteur connecté, ou ne
 * correspondant à aucune date de livraison enregistrée (amap_get_contract_product_subscribers()
 * retourne alors null) : l'UI ne propose jamais un tel lien.
 */
function amap_handle_export_contract_products( $producer ) {
    $contract_id       = isset( $_GET['contract_id'] ) ? absint( $_GET['contract_id'] ) : 0;
    $group_id          = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;
    $distribution_date = isset( $_GET['distribution_date'] ) ? sanitize_text_field( wp_unslash( $_GET['distribution_date'] ) ) : '';

    $contract = $contract_id ? amap_get_contract( $contract_id ) : null;
    $group    = $group_id ? amap_get_group( $group_id ) : null;

    if ( ! $contract || ! $group
        || 'product_grid' !== $contract->contract_type
        || (int) $contract->producer_user_id !== $producer->ID
        || ! in_array( $group_id, array_map( 'intval', wp_list_pluck( amap_get_producer_groups( $producer->ID ), 'id' ) ), true )
        || ! amap_is_valid_date( $distribution_date )
    ) {
        wp_die( esc_html__( 'Export non autorisé.', 'association-manager' ) );
    }

    $subscribers = amap_get_contract_product_subscribers( $contract, $group, $distribution_date );
    if ( null === $subscribers ) {
        wp_die( esc_html__( 'Export non autorisé.', 'association-manager' ) );
    }

    // Une colonne par produit effectivement commandé à cette date, pas tout le catalogue du
    // contrat — ordonnées par première apparition (même logique que
    // amap_get_contract_products_to_deliver()).
    $products = array();
    foreach ( $subscribers as $entry ) {
        foreach ( $entry['items'] as $item ) {
            $products[ $item['product']->id ] = $item['product'];
        }
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $contract->label . '-' . $group->name . '-' . $distribution_date . '.csv' ) . '"' );

    $output = fopen( 'php://output', 'w' );
    // BOM UTF-8 : sans lui, Excel affiche les accents mal encodés à l'ouverture directe du fichier.
    fwrite( $output, "\xEF\xBB\xBF" );

    fputcsv(
        $output,
        array_merge(
            array( __( 'Nom Prénom', 'association-manager' ), __( 'Téléphone', 'association-manager' ) ),
            wp_list_pluck( $products, 'label' ),
            array( __( 'Commentaire', 'association-manager' ) )
        ),
        ';'
    );

    foreach ( $subscribers as $entry ) {
        if ( ! $entry['member'] ) {
            continue;
        }

        $quantities = array();
        foreach ( $entry['items'] as $item ) {
            $quantities[ $item['product']->id ] = $item['quantity'];
        }

        $contact = amap_get_user_contact( $entry['member']->ID );

        fputcsv(
            $output,
            array_merge(
                array(
                    trim( $entry['member']->last_name . ' ' . $entry['member']->first_name ),
                    $contact->phone ?? '',
                ),
                array_map(
                    static function ( $product_id ) use ( $quantities ) {
                        return $quantities[ $product_id ] ?? '—';
                    },
                    array_keys( $products )
                ),
                array( '' )
            ),
            ';'
        );
    }

    fclose( $output );
    exit;
}

/**
 * Valide ?amap_member_action=export_contract_season_summary&contract_id=X&group_id=Y (lien
 * "Export saison" de la fiche contrat, onglet "Espace producteur") et envoie le fichier CSV
 * consolidé sur toute la durée du contrat — une ligne par adhérent, quantités/paniers facturés et
 * montant dû, contrairement aux exports "Feuille de présence"/"Commandes" limités à une fenêtre ou
 * une seule distribution. Disponible pour les deux types de contrat (contrairement aux deux autres
 * exports, chacun spécifique à un type). wp_die() sur un contrat/groupe trafiqué ou n'appartenant
 * pas au producteur connecté : l'UI ne propose jamais un tel lien.
 */
function amap_handle_export_contract_season_summary( $producer ) {
    $contract_id = isset( $_GET['contract_id'] ) ? absint( $_GET['contract_id'] ) : 0;
    $group_id    = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;

    $contract = $contract_id ? amap_get_contract( $contract_id ) : null;
    $group    = $group_id ? amap_get_group( $group_id ) : null;

    if ( ! $contract || ! $group
        || (int) $contract->producer_user_id !== $producer->ID
        || ! in_array( $group_id, array_map( 'intval', wp_list_pluck( amap_get_producer_groups( $producer->ID ), 'id' ) ), true )
    ) {
        wp_die( esc_html__( 'Export non autorisé.', 'association-manager' ) );
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $contract->label . '-' . $group->name . '-saison.csv' ) . '"' );

    $output = fopen( 'php://output', 'w' );
    // BOM UTF-8 : sans lui, Excel affiche les accents mal encodés à l'ouverture directe du fichier.
    fwrite( $output, "\xEF\xBB\xBF" );

    if ( 'basket_recurring' === $contract->contract_type ) {
        fputcsv(
            $output,
            array(
                __( 'Nom Prénom', 'association-manager' ),
                __( 'Téléphone', 'association-manager' ),
                __( 'Taille de panier', 'association-manager' ),
                __( 'Distributions prévues', 'association-manager' ),
                __( 'Congés pris', 'association-manager' ),
                __( 'Distributions facturées', 'association-manager' ),
                __( 'Montant dû (€)', 'association-manager' ),
            ),
            ';'
        );

        foreach ( amap_get_contract_basket_season_rows( $contract, $group ) as $row ) {
            fputcsv(
                $output,
                array(
                    $row['name'],
                    $row['phone'],
                    $row['basket_size'],
                    $row['total_distributions'],
                    $row['leaves_count'],
                    $row['billed_distributions'],
                    number_format( $row['amount'], 2, ',', '' ),
                ),
                ';'
            );
        }
    } else {
        // Le catalogue complet du contrat sert de colonnes (pas seulement les produits
        // effectivement commandés, contrairement à amap_handle_export_contract_products()) : les
        // colonnes restent ainsi identiques d'une ligne à l'autre sur toute la saison.
        $products = amap_get_contract_products( $contract->id );

        fputcsv(
            $output,
            array_merge(
                array( __( 'Nom Prénom', 'association-manager' ), __( 'Téléphone', 'association-manager' ) ),
                wp_list_pluck( $products, 'label' ),
                array( __( 'Montant dû (€)', 'association-manager' ) )
            ),
            ';'
        );

        foreach ( amap_get_contract_product_season_rows( $contract, $group ) as $row ) {
            fputcsv(
                $output,
                array_merge(
                    array( $row['name'], $row['phone'] ),
                    array_map(
                        static function ( $product ) use ( $row ) {
                            return $row['quantities'][ $product->id ] ?? 0;
                        },
                        $products
                    ),
                    array( number_format( $row['amount'], 2, ',', '' ) )
                ),
                ';'
            );
        }
    }

    fclose( $output );
    exit;
}

/**
 * Formulaire self-service permettant à un adhérent de modifier lui-même nom/prénom/email/
 * téléphone/adresse. Rendu par amap_maybe_render_member_area() ; la soumission est traitée par
 * amap_handle_update_member_profile().
 */
function amap_render_member_profile_edit_form( $user ) {
    $notice = isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '';

    // Comme amap_render_users_page() : en cas d'erreur de validation, on réaffiche les valeurs
    // saisies (transient) plutôt que les valeurs actuelles en base.
    $transient_key = 'amap_user_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } else {
        $contact   = amap_get_user_contact( $user->ID );
        $form_data = array(
            'last_name'  => $user->last_name,
            'first_name' => $user->first_name,
            'email'      => $user->user_email,
            'phone'      => $contact->phone ?? '',
            'address'    => $contact->address ?? '',
        );
    }

    get_template_part(
        'template-parts/login/member-profile-edit',
        null,
        array(
            'notice'    => $notice,
            'form_data' => $form_data,
        )
    );
}

add_action( 'admin_post_amap_update_member_profile', 'amap_handle_update_member_profile' );

/**
 * Traite le formulaire self-service (template-parts/login/member-profile-edit.php). Contrairement
 * à amap_handle_update_user() (admin, où l'ID cible vient de la requête), l'utilisateur modifié
 * est TOUJOURS get_current_user_id() : un adhérent ne peut ainsi jamais modifier un autre compte
 * en falsifiant un paramètre.
 */
function amap_handle_update_member_profile() {
    $user = wp_get_current_user();
    // Même vérification que amap_get_amap_user() : au moins une casquette AMAP, quelle qu'elle
    // soit — ces informations sont liées au compte, pas à un rôle particulier.
    if ( ! is_user_logged_in() || ! array_intersect( $user->roles, array_keys( amap_get_available_roles() ) ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_update_member_profile' );

    $id       = get_current_user_id();
    $edit_url = amap_get_member_profile_edit_url();

    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
    $submitted  = compact( 'last_name', 'first_name', 'email', 'phone', 'address' );

    if ( '' === $last_name || '' === $first_name || '' === $email || '' === $phone ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid', $edit_url ) );
        exit;
    }

    if ( ! amap_is_valid_phone( $phone ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'invalid_phone', $edit_url ) );
        exit;
    }

    // Comme amap_handle_update_user() : l'email doit rester libre, ou appartenir à CE compte.
    $email_owner = get_user_by( 'email', $email );
    if ( $email_owner && $email_owner->ID !== $id ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'email_taken', $edit_url ) );
        exit;
    }

    $updated = wp_update_user(
        array(
            'ID'         => $id,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'user_email' => $email,
        )
    );

    if ( is_wp_error( $updated ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( add_query_arg( 'amap_notice', 'account_error', $edit_url ) );
        exit;
    }

    if ( ! amap_save_user_contact( $id, $phone, $address ) ) {
        wp_safe_redirect( add_query_arg( 'amap_notice', 'contact_error', $edit_url ) );
        exit;
    }

    wp_safe_redirect( add_query_arg( 'amap_member_notice', 'profile_updated', amap_get_member_area_url() ) );
    exit;
}
