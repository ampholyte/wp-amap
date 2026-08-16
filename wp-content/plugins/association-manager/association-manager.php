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
register_activation_hook( __FILE__, 'amap_schedule_daily_checks' );
register_deactivation_hook( __FILE__, 'amap_unschedule_daily_checks' );

/**
 * Programme la tâche planifiée quotidienne (WP-Cron) à l'activation du plugin —
 * wp_next_scheduled() évite de la reprogrammer en double si le plugin est réactivé sans être
 * passé par la désactivation (ex. mise à jour du code sans repasser par "Extensions").
 */
function amap_schedule_daily_checks() {
    if ( ! wp_next_scheduled( 'amap_daily_checks' ) ) {
        wp_schedule_event( time(), 'daily', 'amap_daily_checks' );
    }
}

/**
 * Retire la tâche planifiée à la désactivation du plugin, pour ne rien laisser en suspens dans
 * WP-Cron une fois le plugin désactivé.
 */
function amap_unschedule_daily_checks() {
    wp_clear_scheduled_hook( 'amap_daily_checks' );
}

add_action( 'amap_daily_checks', 'amap_run_daily_checks' );

/**
 * Point d'entrée unique des vérifications quotidiennes qui doivent s'exécuter sans action
 * utilisateur — WP-Cron n'est pas un vrai démon en tâche de fond, il se déclenche au chargement
 * d'une page du site (front ou admin) quand une tâche planifiée est en retard.
 */
function amap_run_daily_checks() {
    amap_check_missing_distribution_volunteers();
}

// L'admin wp-admin de ce plugin (pages "Utilisateurs AMAP"/"Groupes"/"Contrats"/"Souscriptions",
// leurs classes WP_List_Table, et le rappel "contrats arrivant à échéance") a été entièrement
// remplacé par l'espace bureau du front (voir includes/member-area.php et
// template-parts/login/member-area-board-*.php dans le thème) — plus aucune page wp-admin dédiée
// n'est enregistrée. Le rappel d'échéance vit maintenant dans member-area-board.php (thème),
// visible sur toutes les sections du bureau comme c'était déjà le cas ici.
