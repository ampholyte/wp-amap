<?php
/**
 * Liste des utilisateurs AMAP (page "Utilisateurs AMAP"), sous forme de WP_List_Table native :
 * tri de colonnes, recherche et pagination gérés par l'API d'admin WordPress plutôt que par un
 * tableau HTML fait main.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Amap_Users_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(
            array(
                'singular' => 'amap_user',
                'plural'   => 'amap_users',
                'ajax'     => false,
            )
        );
    }

    public function get_columns() {
        return array(
            'last_name'  => __( 'Nom', 'association-manager' ),
            'first_name' => __( 'Prénom', 'association-manager' ),
            'email'      => __( 'Email', 'association-manager' ),
            'phone'      => __( 'Téléphone', 'association-manager' ),
            'address'    => __( 'Adresse', 'association-manager' ),
            'roles'      => __( 'Rôles', 'association-manager' ),
            'group'      => __( 'Groupe', 'association-manager' ),
        );
    }

    public function get_sortable_columns() {
        return array(
            'last_name'  => array( 'last_name', false ),
            'first_name' => array( 'first_name', false ),
            'email'      => array( 'email', false ),
        );
    }

    public function no_items() {
        esc_html_e( 'Aucun utilisateur AMAP enregistré pour le moment.', 'association-manager' );
    }

    public function prepare_items() {
        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $orderby      = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'last_name';
        $order        = ( isset( $_REQUEST['order'] ) && 'desc' === strtolower( wp_unslash( $_REQUEST['order'] ) ) ) ? 'DESC' : 'ASC';
        $search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

        $query_args = array(
            'role__in' => array( 'amap_member', 'amap_producer', 'amap_board' ),
            'number'   => $per_page,
            'paged'    => $current_page,
            'order'    => $order,
        );

        // "last_name"/"first_name" sont des usermeta (pas des colonnes wp_users) : on trie sur
        // meta_value, un seul meta_key à la fois — comme WordPress ne permet pas de trier sur
        // deux meta_key en une requête, "Prénom" et "Nom" restent deux tris indépendants plutôt
        // qu'un tri combiné nom+prénom.
        if ( 'email' === $orderby ) {
            $query_args['orderby'] = 'user_email';
        } else {
            $query_args['orderby']  = 'meta_value';
            $query_args['meta_key'] = 'first_name' === $orderby ? 'first_name' : 'last_name';
        }

        if ( '' !== $search ) {
            $query_args['search']         = '*' . $search . '*';
            $query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
        }

        $user_query = new WP_User_Query( $query_args );

        $this->items = $user_query->get_results();

        $this->set_pagination_args(
            array(
                'total_items' => $user_query->get_total(),
                'per_page'    => $per_page,
            )
        );

        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'last_name' );
    }

    protected function column_default( $user, $column_name ) {
        switch ( $column_name ) {
            case 'first_name':
                return esc_html( $user->first_name );
            case 'email':
                return esc_html( $user->user_email );
            case 'phone':
                $contact = amap_get_user_contact( $user->ID );
                return esc_html( $contact->phone ?? '' );
            case 'address':
                $contact = amap_get_user_contact( $user->ID );
                return esc_html( $contact->address ?? '' );
            case 'roles':
                return esc_html( amap_format_user_roles( $user->roles ) );
            case 'group':
                $member_group = in_array( 'amap_member', $user->roles, true ) ? amap_get_member_group( $user->ID ) : null;
                return esc_html( $member_group ? $member_group->name : '—' );
            default:
                return '';
        }
    }

    /**
     * Colonne primaire : porte les actions (Modifier/Supprimer/Envoyer un lien de connexion),
     * affichées via row_actions() au survol de la ligne — convention WP_List_Table plutôt que la
     * colonne "Actions" séparée du tableau fait main précédent.
     */
    protected function column_last_name( $user ) {
        $edit_url = admin_url( 'admin.php?page=amap-users&action=edit&id=' . $user->ID );

        $actions = array(
            'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'association-manager' ) ),
        );

        // Fiche agrégée (coordonnées + groupes + contrats) : n'a de sens que pour la casquette
        // producteur, les adhérents/bureau n'ayant ni groupe de livraison ni contrat à consulter.
        if ( in_array( 'amap_producer', $user->roles, true ) ) {
            $profile_url = admin_url( 'admin.php?page=amap-users&action=view_producer&id=' . $user->ID );
            $actions['view_producer'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( $profile_url ),
                esc_html__( 'Voir la fiche', 'association-manager' )
            );
        }

        // Un compte administrateur ne peut pas être supprimé depuis cette page (voir
        // amap_handle_delete_user()) : pas de lien "Supprimer" qui mènerait de toute façon à un
        // refus côté serveur.
        if ( ! in_array( 'administrator', $user->roles, true ) ) {
            $delete_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=amap_delete_user&id=' . $user->ID ),
                'amap_delete_user_' . $user->ID
            );
            // translators: 1: prénom de l'utilisateur, 2: nom de l'utilisateur.
            $confirm_message = sprintf( __( 'Supprimer définitivement le compte WordPress de %1$s %2$s ?', 'association-manager' ), $user->first_name, $user->last_name );

            $actions['delete'] = sprintf(
                '<a href="%s" onclick="return confirm( \'%s\' );">%s</a>',
                esc_url( $delete_url ),
                esc_js( $confirm_message ),
                esc_html__( 'Supprimer', 'association-manager' )
            );
        }

        if ( amap_user_uses_magic_link( $user ) ) {
            $magic_link_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=amap_send_magic_link&id=' . $user->ID ),
                'amap_send_magic_link_' . $user->ID
            );
            $actions['magic_link'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( $magic_link_url ),
                esc_html__( 'Envoyer un lien de connexion', 'association-manager' )
            );
        }

        return sprintf( '%1$s%2$s', esc_html( $user->last_name ), $this->row_actions( $actions ) );
    }
}
