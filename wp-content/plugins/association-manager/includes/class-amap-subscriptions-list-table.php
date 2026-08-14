<?php
/**
 * Liste des souscriptions (page "Souscriptions"), sous forme de WP_List_Table native. Même
 * principe que les autres pages AMAP : accès $wpdb direct sur wp_amap_subscriptions.
 *
 * Contrairement à Groupes/Contrats, aucune colonne texte native ne se prête à une recherche
 * (contrat/adhérent/groupe/taille sont tous résolus par jointure PHP, pas stockés en clair sur la
 * ligne) : pas de champ de recherche ici, seuls le tri et la pagination sont proposés.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Amap_Subscriptions_List_Table extends WP_List_Table {

    const SORTABLE_DB_COLUMNS = array( 'signed_at' );

    /**
     * Mémoïse amap_get_contract() par contrat pour la durée de l'affichage de la page : la
     * colonne "Contrat" et la colonne "Producteur" (dérivée du contrat) ont sinon chacune besoin
     * du même contrat, et plusieurs souscriptions partagent souvent le même contrat.
     */
    private $contract_cache = array();

    public function __construct() {
        parent::__construct(
            array(
                'singular' => 'amap_subscription',
                'plural'   => 'amap_subscriptions',
                'ajax'     => false,
            )
        );
    }

    public function get_columns() {
        return array(
            'contract_id'    => __( 'Contrat', 'association-manager' ),
            'producer'       => __( 'Producteur', 'association-manager' ),
            'member_user_id' => __( 'Adhérent', 'association-manager' ),
            'group_id'       => __( 'Groupe', 'association-manager' ),
            'basket_size_id' => __( 'Taille de panier', 'association-manager' ),
            'signed_at'      => __( 'Signée le', 'association-manager' ),
            'is_paid'        => __( 'Payé', 'association-manager' ),
        );
    }

    public function get_sortable_columns() {
        return array(
            'signed_at' => array( 'signed_at', false ),
        );
    }

    public function no_items() {
        esc_html_e( 'Aucune souscription enregistrée pour le moment.', 'association-manager' );
    }

    public function prepare_items() {
        global $wpdb;

        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $orderby      = isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], self::SORTABLE_DB_COLUMNS, true )
            ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) )
            : 'signed_at';
        // Défaut "récent d'abord" (comme l'ancien amap_get_subscriptions()) : contrairement aux
        // autres listes AMAP, l'absence de paramètre "order" retombe ici sur DESC, pas ASC.
        $order = ( isset( $_REQUEST['order'] ) && 'asc' === strtolower( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC';

        $table = $wpdb->prefix . 'amap_subscriptions';

        $total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $per_page,
                ( $current_page - 1 ) * $per_page
            )
        );

        $this->set_pagination_args(
            array(
                'total_items' => $total_items,
                'per_page'    => $per_page,
            )
        );

        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'contract_id' );
    }

    private function get_cached_contract( $contract_id ) {
        if ( ! array_key_exists( $contract_id, $this->contract_cache ) ) {
            $this->contract_cache[ $contract_id ] = amap_get_contract( $contract_id );
        }

        return $this->contract_cache[ $contract_id ];
    }

    protected function column_default( $subscription, $column_name ) {
        switch ( $column_name ) {
            case 'producer':
                $contract = $this->get_cached_contract( $subscription->contract_id );
                $producer = $contract ? get_user_by( 'id', $contract->producer_user_id ) : null;
                return esc_html( $producer ? $producer->display_name : '—' );
            case 'member_user_id':
                $member = get_user_by( 'id', $subscription->member_user_id );
                return esc_html( $member ? $member->display_name : '—' );
            case 'group_id':
                $group = amap_get_group( $subscription->group_id );
                return esc_html( $group ? $group->name : '—' );
            case 'basket_size_id':
                $basket_size = $subscription->basket_size_id ? amap_get_contract_basket_size( $subscription->basket_size_id ) : null;
                return esc_html( $basket_size ? $basket_size->label : '—' );
            case 'signed_at':
                return esc_html( $subscription->signed_at );
            case 'is_paid':
                return $subscription->is_paid ? esc_html__( 'Oui', 'association-manager' ) : esc_html__( 'Non', 'association-manager' );
            default:
                return '';
        }
    }

    protected function column_contract_id( $subscription ) {
        $contract = $this->get_cached_contract( $subscription->contract_id );
        $label    = $contract ? $contract->label : '—';

        $edit_url         = admin_url( 'admin.php?page=amap-subscriptions&action=edit&id=' . $subscription->id );
        $delete_url       = wp_nonce_url(
            admin_url( 'admin-post.php?action=amap_delete_subscription&id=' . $subscription->id ),
            'amap_delete_subscription_' . $subscription->id
        );
        $confirm_message = __( 'Supprimer définitivement cette souscription ?', 'association-manager' );

        $actions = array(
            'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'association-manager' ) ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm( \'%s\' );">%s</a>',
                esc_url( $delete_url ),
                esc_js( $confirm_message ),
                esc_html__( 'Supprimer', 'association-manager' )
            ),
        );

        return sprintf( '%1$s%2$s', esc_html( $label ), $this->row_actions( $actions ) );
    }
}
