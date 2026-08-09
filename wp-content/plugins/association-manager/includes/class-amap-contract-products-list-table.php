<?php
/**
 * Liste du catalogue produits d'un contrat product_grid, affichée dans l'onglet "Produits" de la
 * fiche contrat (includes/contracts.php), sous forme de WP_List_Table native — même principe que
 * Amap_Contract_Basket_Sizes_List_Table. Portée toujours limitée à un seul contrat et jeu de
 * données restreint : pas de recherche ni de pagination.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Amap_Contract_Products_List_Table extends WP_List_Table {

    /**
     * Libellé de la famille de remise par discount_group_id, indexé après prepare_items() pour
     * éviter de rejouer amap_get_contract_discount_groups() dans column_default().
     */
    private $discount_group_labels = array();

    public function __construct() {
        parent::__construct(
            array(
                'singular' => 'amap_contract_product',
                'plural'   => 'amap_contract_products',
                'ajax'     => false,
            )
        );
    }

    public function get_columns() {
        return array(
            'label'          => __( 'Libellé', 'association-manager' ),
            'price'          => __( 'Prix', 'association-manager' ),
            'discount_group' => __( 'Famille de remise', 'association-manager' ),
        );
    }

    public function no_items() {
        esc_html_e( 'Aucun produit pour le moment.', 'association-manager' );
    }

    /**
     * Signature volontairement différente de la classe mère (paramètre $contract_id), même
     * principe que Amap_Contract_Basket_Sizes_List_Table::prepare_items().
     */
    public function prepare_items( $contract_id = 0 ) {
        $this->items                 = amap_get_contract_products( $contract_id );
        $this->discount_group_labels = wp_list_pluck( amap_get_contract_discount_groups( $contract_id ), 'label', 'id' );

        // Pas de pagination voulue ici, même raison que les autres listes imbriquées.
        $this->set_pagination_args(
            array(
                'total_items' => count( $this->items ),
                'per_page'    => max( count( $this->items ), 1 ),
            )
        );

        $this->_column_headers = array( $this->get_columns(), array(), array() );
    }

    protected function column_default( $contract_product, $column_name ) {
        switch ( $column_name ) {
            case 'price':
                return esc_html( number_format_i18n( (float) $contract_product->price, 2 ) ) . ' €';
            case 'discount_group':
                return esc_html( $this->discount_group_labels[ (int) $contract_product->discount_group_id ] ?? '—' );
            default:
                return '';
        }
    }

    protected function column_label( $contract_product ) {
        $edit_url = admin_url(
            'admin.php?page=amap-contracts&action=edit&id=' . $contract_product->contract_id
            . '&product_action=edit&product_id=' . $contract_product->id
        );
        $delete_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=amap_delete_contract_product&id=' . $contract_product->id ),
            'amap_delete_contract_product_' . $contract_product->id
        );
        // translators: %s: libellé du produit.
        $confirm_message = sprintf( __( 'Supprimer définitivement le produit %s ?', 'association-manager' ), $contract_product->label );

        $actions = array(
            'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'association-manager' ) ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm( \'%s\' );">%s</a>',
                esc_url( $delete_url ),
                esc_js( $confirm_message ),
                esc_html__( 'Supprimer', 'association-manager' )
            ),
        );

        return sprintf( '%1$s%2$s', esc_html( $contract_product->label ), $this->row_actions( $actions ) );
    }
}
