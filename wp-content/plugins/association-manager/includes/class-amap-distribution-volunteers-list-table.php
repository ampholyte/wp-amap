<?php
/**
 * Liste des bénévoles de distribution d'un groupe, affichée dans l'accordéon "Bénévoles de
 * distribution" de la fiche groupe (includes/groups.php), sous forme de WP_List_Table native —
 * même principe que Amap_Distribution_Exceptions_List_Table. Une ligne par distribution (pas par
 * bénévole) : sur un planning hebdomadaire étalé sur une année, une ligne par bénévole répéterait
 * la même date des dizaines de fois et rendrait le tableau illisible. Les 2-3 bénévoles d'une même
 * distribution restent donc empilés dans la colonne "Bénévoles", chacun avec son propre lien
 * "Retirer" — nesting réduit par rapport à l'ancien tableau fait main (accordéon > table > <ul>
 * imbriqué), mais pas éliminé : il reflète ici une vraie relation un-vers-plusieurs, pas une
 * duplication de balisage. Portée toujours limitée à un seul groupe : pas de recherche ni de
 * pagination, même choix que la liste des exceptions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Amap_Distribution_Volunteers_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(
            array(
                'singular' => 'amap_distribution_volunteer_date',
                'plural'   => 'amap_distribution_volunteer_dates',
                'ajax'     => false,
            )
        );
    }

    public function get_columns() {
        return array(
            'distribution_date' => __( 'Distribution', 'association-manager' ),
            'volunteer_count'   => __( 'Bénévoles inscrits', 'association-manager' ),
            'member'            => __( 'Bénévoles', 'association-manager' ),
        );
    }

    public function no_items() {
        esc_html_e( 'Aucun bénévole enregistré pour ce groupe.', 'association-manager' );
    }

    /**
     * Signature volontairement différente de la classe mère (paramètre $group_id), même principe
     * que Amap_Distribution_Exceptions_List_Table::prepare_items() : toujours préparée
     * explicitement par amap_render_groups_page() pour LE groupe affiché, jamais appelée par
     * WordPress lui-même.
     */
    public function prepare_items( $group_id = 0 ) {
        $volunteers_by_date = array();
        foreach ( amap_get_distribution_volunteers( $group_id ) as $volunteer ) {
            $volunteers_by_date[ $volunteer->distribution_date ][] = $volunteer;
        }

        // Une "ligne" synthétique par distribution, regroupant ses bénévoles — amap_get_distribution_
        // volunteers() les retourne déjà triés par distribution_date ASC, donc $volunteers_by_date
        // conserve cet ordre (PHP préserve l'ordre d'insertion des clés d'un tableau associatif).
        $this->items = array();
        foreach ( $volunteers_by_date as $distribution_date => $volunteers ) {
            $this->items[] = (object) array(
                'distribution_date' => $distribution_date,
                'volunteers'        => $volunteers,
            );
        }

        // Pas de pagination voulue ici, même raison que la liste des exceptions.
        $this->set_pagination_args(
            array(
                'total_items' => count( $this->items ),
                'per_page'    => max( count( $this->items ), 1 ),
            )
        );

        $this->_column_headers = array( $this->get_columns(), array(), array() );
    }

    protected function column_distribution_date( $row ) {
        return esc_html( $row->distribution_date );
    }

    protected function column_default( $row, $column_name ) {
        switch ( $column_name ) {
            case 'volunteer_count':
                $count = count( $row->volunteers );
                return sprintf(
                    '<span style="color: %1$s; font-weight: 600;">%2$s</span>',
                    esc_attr( $count < 2 ? '#d63638' : '#00a32a' ),
                    // translators: %d: nombre de bénévoles déjà inscrits pour cette distribution (sur 3 maximum).
                    esc_html( sprintf( __( '%d/3', 'association-manager' ), $count ) )
                );
            case 'member':
                return $this->render_volunteer_list( $row->volunteers );
            default:
                return '';
        }
    }

    private function render_volunteer_list( array $volunteers ) {
        $rows = array();

        foreach ( $volunteers as $volunteer ) {
            $volunteer_user = get_userdata( $volunteer->member_user_id );
            $delete_url     = wp_nonce_url(
                admin_url( 'admin-post.php?action=amap_delete_distribution_volunteer&id=' . $volunteer->id ),
                'amap_delete_distribution_volunteer_' . $volunteer->id
            );

            $rows[] = sprintf(
                '<li>%1$s — <a href="%2$s" onclick="return confirm( \'%3$s\' );">%4$s</a></li>',
                esc_html( $volunteer_user ? $volunteer_user->display_name : '#' . $volunteer->member_user_id ),
                esc_url( $delete_url ),
                esc_js( __( 'Retirer ce bénévole de cette distribution ?', 'association-manager' ) ),
                esc_html__( 'Retirer', 'association-manager' )
            );
        }

        return '<ul style="margin: 0;">' . implode( '', $rows ) . '</ul>';
    }
}
