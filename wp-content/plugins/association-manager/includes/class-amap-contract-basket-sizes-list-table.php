<?php
/**
 * Liste des tailles de panier d'un contrat basket_recurring, affichée dans l'onglet "Tailles de
 * panier" de la fiche contrat (includes/contracts.php), sous forme de WP_List_Table native —
 * même principe que Amap_Distribution_Exceptions_List_Table. Portée toujours limitée à un seul
 * contrat et jeu de données restreint (quelques tailles par contrat) : pas de recherche ni de
 * pagination.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Amap_Contract_Basket_Sizes_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(
            array(
                'singular' => 'amap_contract_basket_size',
                'plural'   => 'amap_contract_basket_sizes',
                'ajax'     => false,
            )
        );
    }

    public function get_columns() {
        return array(
            'label' => __( 'Libellé', 'association-manager' ),
            'price' => __( 'Prix', 'association-manager' ),
        );
    }

    public function no_items() {
        esc_html_e( 'Aucune taille de panier pour le moment.', 'association-manager' );
    }

    /**
     * Signature volontairement différente de la classe mère (paramètre $contract_id), même
     * principe que Amap_Distribution_Exceptions_List_Table::prepare_items() : toujours préparée
     * explicitement par amap_render_contracts_page() pour LE contrat affiché.
     */
    public function prepare_items( $contract_id = 0 ) {
        $this->items = amap_get_contract_basket_sizes( $contract_id );

        // Pas de pagination voulue ici, même raison que la liste des exceptions.
        $this->set_pagination_args(
            array(
                'total_items' => count( $this->items ),
                'per_page'    => max( count( $this->items ), 1 ),
            )
        );

        $this->_column_headers = array( $this->get_columns(), array(), array() );
    }

    protected function column_default( $basket_size, $column_name ) {
        switch ( $column_name ) {
            case 'price':
                return esc_html( number_format_i18n( (float) $basket_size->price, 2 ) ) . ' €';
            default:
                return '';
        }
    }

    protected function column_label( $basket_size ) {
        $edit_url = admin_url(
            'admin.php?page=amap-contracts&action=edit&id=' . $basket_size->contract_id
            . '&size_action=edit&size_id=' . $basket_size->id
        );
        $delete_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=amap_delete_contract_basket_size&id=' . $basket_size->id ),
            'amap_delete_contract_basket_size_' . $basket_size->id
        );
        // translators: %s: libellé de la taille de panier.
        $confirm_message = sprintf( __( 'Supprimer définitivement la taille %s ?', 'association-manager' ), $basket_size->label );

        $actions = array(
            'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'association-manager' ) ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm( \'%s\' );">%s</a>',
                esc_url( $delete_url ),
                esc_js( $confirm_message ),
                esc_html__( 'Supprimer', 'association-manager' )
            ),
        );

        return sprintf( '%1$s%2$s', esc_html( $basket_size->label ), $this->row_actions( $actions ) );
    }
}
