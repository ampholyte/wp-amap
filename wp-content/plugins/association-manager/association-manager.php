<?php
/**
 * Plugin Name: Association Manager
 * Description: Logique métier de l'AMAP (adhérents, groupes, producteurs, contrats, distributions).
 * Version: 0.1.0
 * Author: Association AMAP
 * Text Domain: association-manager
 */

// Empêche l'accès direct au fichier en dehors du contexte WordPress.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/member-area.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/groups.php';
require_once __DIR__ . '/includes/contracts.php';
require_once __DIR__ . '/includes/subscriptions.php';

register_activation_hook( __FILE__, 'amap_activate' );

add_action( 'admin_menu', 'amap_register_admin_menu' );

function amap_register_admin_menu() {
    add_menu_page(
        __( 'AMAP', 'association-manager' ),
        __( 'AMAP', 'association-manager' ),
        'amap_manage_users',
        'amap-users',
        'amap_render_users_page',
        'dashicons-groups',
        26
    );

    add_submenu_page(
        'amap-users',
        __( 'Groupes', 'association-manager' ),
        __( 'Groupes', 'association-manager' ),
        'amap_manage_groups',
        'amap-groups',
        'amap_render_groups_page'
    );

    add_submenu_page(
        'amap-users',
        __( 'Contrats', 'association-manager' ),
        __( 'Contrats', 'association-manager' ),
        'amap_manage_contracts',
        'amap-contracts',
        'amap_render_contracts_page'
    );

    add_submenu_page(
        'amap-users',
        __( 'Souscriptions', 'association-manager' ),
        __( 'Souscriptions', 'association-manager' ),
        'amap_manage_subscriptions',
        'amap-subscriptions',
        'amap_render_subscriptions_page'
    );
}
