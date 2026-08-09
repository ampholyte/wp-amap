<?php
/**
 * Liste des groupes de distribution (page "Groupes"), sous forme de WP_List_Table native : tri
 * de colonnes, recherche et pagination gérés par l'API d'admin WordPress plutôt que par un
 * tableau HTML fait main. Même principe que Amap_Users_List_Table, appliqué ici à la table
 * wp_amap_groups (accès $wpdb direct, pas de WP_User_Query).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Amap_Groups_List_Table extends WP_List_Table {

    /**
     * Colonnes autorisées en tri : sert aussi de liste blanche pour ORDER BY, $wpdb->prepare()
     * ne pouvant pas paramétrer un nom de colonne.
     */
    const SORTABLE_DB_COLUMNS = array( 'name', 'delivery_place', 'weekday', 'start_time' );

    public function __construct() {
        parent::__construct(
            array(
                'singular' => 'amap_group',
                'plural'   => 'amap_groups',
                'ajax'     => false,
            )
        );
    }

    public function get_columns() {
        return array(
            'name'           => __( 'Nom', 'association-manager' ),
            'delivery_place' => __( 'Lieu de livraison', 'association-manager' ),
            'weekday'        => __( 'Jour', 'association-manager' ),
            'start_time'     => __( 'Horaire', 'association-manager' ),
        );
    }

    public function get_sortable_columns() {
        return array(
            'name'           => array( 'name', false ),
            'delivery_place' => array( 'delivery_place', false ),
            'weekday'        => array( 'weekday', false ),
            'start_time'     => array( 'start_time', false ),
        );
    }

    public function no_items() {
        esc_html_e( 'Aucun groupe enregistré pour le moment.', 'association-manager' );
    }

    public function prepare_items() {
        global $wpdb;

        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $orderby      = isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], self::SORTABLE_DB_COLUMNS, true )
            ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) )
            : 'weekday';
        $order        = ( isset( $_REQUEST['order'] ) && 'desc' === strtolower( wp_unslash( $_REQUEST['order'] ) ) ) ? 'DESC' : 'ASC';

        $table        = $wpdb->prefix . 'amap_groups';
        $where        = '';
        $where_params = array();

        if ( '' !== $search ) {
            $where          = 'WHERE name LIKE %s OR delivery_place LIKE %s';
            $like           = '%' . $wpdb->esc_like( $search ) . '%';
            $where_params[] = $like;
            $where_params[] = $like;
        }

        $total_items = $where_params
            ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $where_params ) )
            : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        // Tri par défaut ("weekday") avec repère secondaire sur l'heure de début, pour retrouver
        // l'ordre chronologique des distributions dans la semaine — même comportement que
        // l'ancien amap_get_groups(), dont cette classe reprend la requête pour cette page.
        $orderby_sql = 'weekday' === $orderby ? "weekday {$order}, start_time ASC" : "{$orderby} {$order}";

        $query_params   = $where_params;
        $query_params[] = $per_page;
        $query_params[] = ( $current_page - 1 ) * $per_page;

        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$where} ORDER BY {$orderby_sql} LIMIT %d OFFSET %d",
                $query_params
            )
        );

        $this->set_pagination_args(
            array(
                'total_items' => $total_items,
                'per_page'    => $per_page,
            )
        );

        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
    }

    protected function column_default( $group, $column_name ) {
        switch ( $column_name ) {
            case 'delivery_place':
                return esc_html( $group->delivery_place );
            case 'weekday':
                $weekday_labels = amap_get_weekday_labels();
                return esc_html( $weekday_labels[ (int) $group->weekday ] ?? '' );
            case 'start_time':
                return esc_html( amap_format_time( $group->start_time ) . ' - ' . amap_format_time( $group->end_time ) );
            default:
                return '';
        }
    }

    protected function column_name( $group ) {
        $edit_url   = admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group->id );
        $delete_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=amap_delete_group&id=' . $group->id ),
            'amap_delete_group_' . $group->id
        );
        // translators: %s: nom du groupe.
        $confirm_message = sprintf( __( 'Supprimer définitivement le groupe %s ?', 'association-manager' ), $group->name );

        $actions = array(
            'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Voir le groupe', 'association-manager' ) ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm( \'%s\' );">%s</a>',
                esc_url( $delete_url ),
                esc_js( $confirm_message ),
                esc_html__( 'Supprimer', 'association-manager' )
            ),
        );

        return sprintf( '%1$s%2$s', esc_html( $group->name ), $this->row_actions( $actions ) );
    }
}
