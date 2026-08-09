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

/**
 * Mode démo (ex. WordPress Playground) : aucun email n'est réellement envoyé (amap_send_email()),
 * son contenu est affiché à l'écran à la place. Faux par défaut ; à définir à true uniquement via
 * wp-config.php d'un environnement de démonstration, jamais en production.
 */
if ( ! defined( 'AMAP_DEMO_MODE' ) ) {
    define( 'AMAP_DEMO_MODE', false );
}

require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/email-template.php';
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

add_action( 'admin_head', 'amap_render_admin_notice_styles' );

/**
 * Les messages de confirmation/erreur des pages d'admin AMAP (<div class="notice ...">, répété à
 * l'identique dans users.php/groups.php/contracts.php/subscriptions.php) utilisent sinon le style
 * WP par défaut — fine bordure colorée, texte normal — peu visible dans des pages denses avec
 * plusieurs tableaux/formulaires imbriqués. Un seul style partagé ici plutôt qu'un <style> dupliqué
 * dans chacun des quatre fichiers de page.
 */
function amap_render_admin_notice_styles() {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( ! in_array( $page, array( 'amap-users', 'amap-groups', 'amap-contracts', 'amap-subscriptions' ), true ) ) {
        return;
    }
    ?>
    <style>
        .wrap .notice {
            border-left-width: 6px;
            padding: 12px 14px;
        }
        .wrap .notice p {
            font-size: 16px;
            font-weight: 600;
        }
        .wrap .notice-success {
            background: #edfaef;
        }
        .wrap .notice-error {
            background: #fcf0f1;
        }
    </style>
    <?php
}
