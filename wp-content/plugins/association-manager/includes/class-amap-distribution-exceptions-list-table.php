<?php
/**
 * Liste des exceptions de distribution d'un groupe, affichée dans l'accordéon "Exceptions de
 * distribution" de la fiche groupe (includes/groups.php), sous forme de WP_List_Table native —
 * même principe que les listes principales (Amap_Groups_List_Table, etc.), mais imbriquée dans
 * une page d'édition plutôt qu'en page de liste à part entière. Portée toujours limitée à un seul
 * groupe et jeu de données restreint (quelques exceptions par groupe) : pas de recherche ni de
 * pagination, l'ordre chronologique fixe (voir amap_get_distribution_exceptions()) suffit.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Amap_Distribution_Exceptions_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(
            array(
                'singular' => 'amap_distribution_exception',
                'plural'   => 'amap_distribution_exceptions',
                'ajax'     => false,
            )
        );
    }

    public function get_columns() {
        return array(
            'distribution_date' => __( 'Distribution concernée', 'association-manager' ),
            'exception_type'    => __( 'Type', 'association-manager' ),
            'moved_details'     => __( 'Nouvelle date/horaire/lieu', 'association-manager' ),
            'reason'            => __( 'Motif', 'association-manager' ),
            'decided_by'        => __( 'Décidé par', 'association-manager' ),
        );
    }

    public function no_items() {
        esc_html_e( 'Aucune exception enregistrée pour ce groupe.', 'association-manager' );
    }

    /**
     * Signature volontairement différente de la classe mère (paramètre $group_id) : cette table
     * n'est jamais instanciée depuis une page de liste où WordPress l'appellerait lui-même, elle
     * est toujours préparée explicitement par amap_render_groups_page() pour LE groupe affiché.
     */
    public function prepare_items( $group_id = 0 ) {
        $this->items = amap_get_distribution_exceptions( $group_id );

        // Pas de pagination voulue ici : per_page >= total_items masque les liens de pagination
        // (voir WP_List_Table::pagination()) sans avoir à les désactiver un par un.
        $this->set_pagination_args(
            array(
                'total_items' => count( $this->items ),
                'per_page'    => max( count( $this->items ), 1 ),
            )
        );

        $this->_column_headers = array( $this->get_columns(), array(), array() );
    }

    protected function column_default( $exception, $column_name ) {
        $exception_type_labels = amap_get_distribution_exception_type_labels();

        switch ( $column_name ) {
            case 'exception_type':
                return esc_html( $exception_type_labels[ $exception->exception_type ] ?? $exception->exception_type );
            case 'moved_details':
                if ( 'moved' !== $exception->exception_type ) {
                    return '—';
                }
                $moved_parts = array();
                if ( $exception->new_date ) {
                    $moved_parts[] = $exception->new_date;
                }
                if ( $exception->new_start_time && $exception->new_end_time ) {
                    $moved_parts[] = amap_format_time( $exception->new_start_time ) . '-' . amap_format_time( $exception->new_end_time );
                }
                if ( $exception->new_place ) {
                    $moved_parts[] = $exception->new_place;
                }
                return esc_html( implode( ' · ', $moved_parts ) );
            case 'reason':
                return esc_html( $exception->reason ? $exception->reason : '—' );
            case 'decided_by':
                $decided_by_user = get_userdata( $exception->decided_by );
                return esc_html( $decided_by_user ? $decided_by_user->display_name : '—' );
            default:
                return '';
        }
    }

    protected function column_distribution_date( $exception ) {
        $edit_url = admin_url(
            'admin.php?page=amap-groups&action=edit&id=' . $exception->group_id
            . '&exception_action=edit&exception_id=' . $exception->id
        );
        $delete_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=amap_delete_distribution_exception&id=' . $exception->id ),
            'amap_delete_distribution_exception_' . $exception->id
        );
        // translators: %s: date de la distribution concernée.
        $confirm_message = sprintf( __( "Supprimer définitivement l'exception du %s ?", 'association-manager' ), $exception->distribution_date );

        $actions = array(
            'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'association-manager' ) ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm( \'%s\' );">%s</a>',
                esc_url( $delete_url ),
                esc_js( $confirm_message ),
                esc_html__( 'Supprimer', 'association-manager' )
            ),
        );

        return sprintf( '%1$s%2$s', esc_html( $exception->distribution_date ), $this->row_actions( $actions ) );
    }
}
