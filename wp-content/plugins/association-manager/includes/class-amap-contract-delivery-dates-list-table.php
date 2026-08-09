<?php
/**
 * Liste des dates de livraison d'un contrat pour UN groupe de distribution, affichée dans
 * l'accordéon de ce groupe sous l'onglet "Dates de livraison" de la fiche contrat
 * (includes/contracts.php), sous forme de WP_List_Table native. Contrairement aux autres listes
 * imbriquées du plugin, celle-ci utilise la case à cocher native de WP_List_Table
 * (column_cb()) : l'ancien panneau "Modifier la liste" (cases à cocher inversées, cochée =
 * conserver) est remplacé par la convention WordPress standard (cochée = sélectionnée pour
 * suppression), au lieu de dupliquer la liste dans un second panneau caché. La page appelante
 * (amap_render_contracts_page()) fournit son propre <form> autour de display() et son propre
 * bouton de soumission : cette classe ne définit pas de bulk actions WP_List_Table classiques
 * (pas de current_action()/process_bulk_action()), le traitement passe par admin-post.php comme
 * le reste du plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Amap_Contract_Delivery_Dates_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(
            array(
                'singular' => 'amap_contract_delivery_date',
                'plural'   => 'amap_contract_delivery_dates',
                'ajax'     => false,
            )
        );
    }

    public function get_columns() {
        return array(
            'cb'            => '<input type="checkbox" />',
            'delivery_date' => __( 'Date', 'association-manager' ),
        );
    }

    public function no_items() {
        esc_html_e( 'Aucune date enregistrée pour ce groupe.', 'association-manager' );
    }

    /**
     * Reçoit directement les lignes déjà chargées par amap_render_contracts_page() (une seule
     * requête pour tout le contrat, regroupée par groupe en PHP), plutôt que de rejouer une
     * requête par groupe ici — un producteur peut être rattaché à plusieurs groupes, chacun avec
     * son propre accordéon et donc sa propre instance de cette classe.
     */
    public function prepare_items( $group_dates = array() ) {
        $this->items = $group_dates;

        // Pas de pagination voulue ici, même raison que les autres listes imbriquées.
        $this->set_pagination_args(
            array(
                'total_items' => count( $this->items ),
                'per_page'    => max( count( $this->items ), 1 ),
            )
        );

        $this->_column_headers = array( $this->get_columns(), array(), array() );
    }

    protected function column_cb( $delivery_date_row ) {
        return sprintf( '<input type="checkbox" name="delivery_date_ids[]" value="%d" />', (int) $delivery_date_row->id );
    }

    protected function column_delivery_date( $delivery_date_row ) {
        $edit_url = admin_url(
            'admin.php?page=amap-contracts&action=edit&id=' . $delivery_date_row->contract_id
            . '&date_action=edit&date_id=' . $delivery_date_row->id
        );
        $delete_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=amap_delete_contract_delivery_date&id=' . $delivery_date_row->id ),
            'amap_delete_contract_delivery_date_' . $delivery_date_row->id
        );
        // translators: %s: date de livraison.
        $confirm_message = sprintf( __( 'Supprimer définitivement la date %s ?', 'association-manager' ), $delivery_date_row->delivery_date );

        $actions = array(
            'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'association-manager' ) ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm( \'%s\' );">%s</a>',
                esc_url( $delete_url ),
                esc_js( $confirm_message ),
                esc_html__( 'Supprimer', 'association-manager' )
            ),
        );

        return sprintf( '%1$s%2$s', esc_html( $delivery_date_row->delivery_date ), $this->row_actions( $actions ) );
    }
}
