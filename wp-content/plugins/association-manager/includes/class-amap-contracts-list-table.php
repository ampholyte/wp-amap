<?php
/**
 * Liste des contrats (page "Contrats"), sous forme de WP_List_Table native. Même principe que
 * Amap_Groups_List_Table : accès $wpdb direct sur wp_amap_contracts, tri/recherche/pagination
 * écrits à la main plutôt que via WP_User_Query.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Amap_Contracts_List_Table extends WP_List_Table {

    /**
     * Colonnes autorisées en tri : sert aussi de liste blanche pour ORDER BY. "Producteur" n'en
     * fait pas partie (nécessiterait une jointure vers wp_users pour trier par nom), comme
     * "Groupe" restait non triable sur la liste des utilisateurs AMAP.
     */
    const SORTABLE_DB_COLUMNS = array( 'label', 'contract_type', 'start_date', 'is_active' );

    public function __construct() {
        parent::__construct(
            array(
                'singular' => 'amap_contract',
                'plural'   => 'amap_contracts',
                'ajax'     => false,
            )
        );
    }

    public function get_columns() {
        return array(
            'label'            => __( 'Libellé', 'association-manager' ),
            'producer_user_id' => __( 'Producteur', 'association-manager' ),
            'contract_type'    => __( 'Type', 'association-manager' ),
            'start_date'       => __( 'Période', 'association-manager' ),
            'frequency_weeks'  => __( 'Fréquence', 'association-manager' ),
            'max_leaves'       => __( 'Congés max', 'association-manager' ),
            'is_active'        => __( 'Actif', 'association-manager' ),
        );
    }

    public function get_sortable_columns() {
        return array(
            'label'         => array( 'label', false ),
            'contract_type' => array( 'contract_type', false ),
            'start_date'    => array( 'start_date', false ),
            'is_active'     => array( 'is_active', false ),
        );
    }

    public function no_items() {
        esc_html_e( 'Aucun contrat enregistré pour le moment.', 'association-manager' );
    }

    public function prepare_items() {
        global $wpdb;

        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $orderby      = isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], self::SORTABLE_DB_COLUMNS, true )
            ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) )
            : '';
        $order        = ( isset( $_REQUEST['order'] ) && 'desc' === strtolower( wp_unslash( $_REQUEST['order'] ) ) ) ? 'DESC' : 'ASC';

        $table        = $wpdb->prefix . 'amap_contracts';
        $where        = '';
        $where_params = array();

        if ( '' !== $search ) {
            $where          = 'WHERE label LIKE %s';
            $where_params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        $total_items = $where_params
            ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $where_params ) )
            : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        // Sans tri demandé : actifs d'abord puis période la plus récente, comme l'ancien
        // amap_get_contracts() dont cette classe reprend la requête pour cette page.
        $orderby_sql = '' === $orderby ? 'is_active DESC, start_date DESC' : "{$orderby} {$order}";

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

        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'label' );
    }

    protected function column_default( $contract, $column_name ) {
        switch ( $column_name ) {
            case 'producer_user_id':
                $producer = get_user_by( 'id', $contract->producer_user_id );
                return esc_html( $producer ? $producer->display_name : '—' );
            case 'contract_type':
                $contract_types = amap_get_contract_types();
                return esc_html( $contract_types[ $contract->contract_type ] ?? $contract->contract_type );
            case 'start_date':
                return esc_html( $contract->start_date . ' → ' . $contract->end_date );
            case 'frequency_weeks':
                return esc_html( null !== $contract->frequency_weeks ? $contract->frequency_weeks : '—' );
            case 'max_leaves':
                return esc_html( null !== $contract->max_leaves ? $contract->max_leaves : '—' );
            case 'is_active':
                return $contract->is_active ? esc_html__( 'Oui', 'association-manager' ) : esc_html__( 'Non', 'association-manager' );
            default:
                return '';
        }
    }

    protected function column_label( $contract ) {
        $edit_url   = admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract->id );
        $delete_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=amap_delete_contract&id=' . $contract->id ),
            'amap_delete_contract_' . $contract->id
        );
        // translators: %s: libellé du contrat.
        $confirm_message = sprintf( __( 'Supprimer définitivement le contrat %s ?', 'association-manager' ), $contract->label );

        $actions = array(
            'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Voir', 'association-manager' ) ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm( \'%s\' );">%s</a>',
                esc_url( $delete_url ),
                esc_js( $confirm_message ),
                esc_html__( 'Supprimer', 'association-manager' )
            ),
        );

        return sprintf( '%1$s%2$s', esc_html( $contract->label ), $this->row_actions( $actions ) );
    }
}
