<?php
/**
 * Liste des familles de remise d'un contrat product_grid, affichée dans l'onglet "Produits" de la
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

class Amap_Contract_Discount_Groups_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(
            array(
                'singular' => 'amap_contract_discount_group',
                'plural'   => 'amap_contract_discount_groups',
                'ajax'     => false,
            )
        );
    }

    public function get_columns() {
        return array(
            'label'    => __( 'Libellé', 'association-manager' ),
            'price'    => __( 'Prix', 'association-manager' ),
            'discount' => __( 'Remise', 'association-manager' ),
        );
    }

    public function no_items() {
        esc_html_e( 'Aucune famille de remise pour le moment.', 'association-manager' );
    }

    /**
     * Signature volontairement différente de la classe mère (paramètre $contract_id), même
     * principe que Amap_Contract_Basket_Sizes_List_Table::prepare_items().
     */
    public function prepare_items( $contract_id = 0 ) {
        $this->items = amap_get_contract_discount_groups( $contract_id );

        // Pas de pagination voulue ici, même raison que les autres listes imbriquées.
        $this->set_pagination_args(
            array(
                'total_items' => count( $this->items ),
                'per_page'    => max( count( $this->items ), 1 ),
            )
        );

        $this->_column_headers = array( $this->get_columns(), array(), array() );
    }

    protected function column_default( $discount_group, $column_name ) {
        switch ( $column_name ) {
            case 'price':
                return esc_html( number_format_i18n( (float) $discount_group->price, 2 ) ) . ' €';
            case 'discount':
                return esc_html(
                    sprintf(
                        /* translators: 1: quantité achetée, 2: quantité facturée. */
                        __( '%1$d achetés → %2$d facturés', 'association-manager' ),
                        (int) $discount_group->bought_quantity,
                        (int) $discount_group->billed_quantity
                    )
                );
            default:
                return '';
        }
    }

    protected function column_label( $discount_group ) {
        $edit_url = admin_url(
            'admin.php?page=amap-contracts&action=edit&id=' . $discount_group->contract_id
            . '&discount_action=edit&discount_id=' . $discount_group->id
        );
        $delete_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=amap_delete_contract_discount_group&id=' . $discount_group->id ),
            'amap_delete_contract_discount_group_' . $discount_group->id
        );
        // translators: %s: libellé de la famille de remise.
        $confirm_message = sprintf( __( 'Supprimer définitivement la famille %s ? Les produits qui en faisaient partie repasseront en prix libre.', 'association-manager' ), $discount_group->label );

        $actions = array(
            'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'association-manager' ) ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm( \'%s\' );">%s</a>',
                esc_url( $delete_url ),
                esc_js( $confirm_message ),
                esc_html__( 'Supprimer', 'association-manager' )
            ),
        );

        return sprintf( '%1$s%2$s', esc_html( $discount_group->label ), $this->row_actions( $actions ) );
    }
}
