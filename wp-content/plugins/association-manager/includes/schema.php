<?php
/**
 * Activation du plugin : rôles/capabilities AMAP et création des tables SQL (dbDelta).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function amap_activate() {
    // update_option() (et non plus add_option()) : la version doit refléter le schéma du
    // code à chaque activation. dbDelta() est idempotent, le rappeler ne pose pas de problème.
    update_option( 'amap_db_version', '3.21' );
    amap_create_tables();
    amap_drop_obsolete_tables();

    // add_role() ne fait rien si le rôle existe déjà : sûr à rappeler à chaque activation,
    // comme dbDelta() pour les tables. Les trois casquettes sont cumulables nativement par
    // WordPress (un utilisateur peut porter plusieurs rôles à la fois).
    add_role( 'amap_member', __( 'Adhérent', 'association-manager' ), array() );
    add_role( 'amap_producer', __( 'Producteur', 'association-manager' ), array() );
    add_role( 'amap_board', __( 'Bureau', 'association-manager' ), array() );

    // add_cap() est également idempotent : le rappeler à chaque activation ne duplique rien.
    $administrator = get_role( 'administrator' );
    if ( $administrator ) {
        $administrator->add_cap( 'amap_manage_users' );
        $administrator->add_cap( 'amap_manage_groups' );
        $administrator->add_cap( 'amap_manage_contracts' );
        $administrator->add_cap( 'amap_manage_subscriptions' );
        $administrator->remove_cap( 'amap_manage_producers' );
    }

    // Un membre du bureau doit pouvoir gérer les utilisateurs AMAP au même titre qu'un
    // administrateur (page d'admin "Utilisateurs AMAP" existante, amap_render_users_page()).
    // amap_manage_groups, amap_manage_contracts et amap_manage_subscriptions sont des
    // capabilities distinctes (pages "Groupes", "Contrats" et "Souscriptions" séparées) : le
    // rattachement producteur↔groupe, les contrats, les souscriptions et la gestion des
    // distributions sont décidés par le bureau, mais restent conceptuellement différents de la
    // gestion des comptes.
    $board = get_role( 'amap_board' );
    if ( $board ) {
        $board->add_cap( 'amap_manage_users' );
        $board->add_cap( 'amap_manage_groups' );
        $board->add_cap( 'amap_manage_contracts' );
        $board->add_cap( 'amap_manage_subscriptions' );
    }
}

function amap_create_tables() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'amap_users';
    $charset_collate = $wpdb->get_charset_collate();

    // Pas de nom/prénom/email ici : ce sont des doublons de wp_users (usermeta
    // first_name/last_name et colonne native user_email). user_id porte l'identité unique ;
    // phone/address sont des données structurées communes à tout utilisateur AMAP, quelle que
    // soit sa casquette, donc en table dédiée plutôt qu'en usermeta.
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        phone varchar(30) NOT NULL,
        address varchar(255) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY user_id (user_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    $magic_links_table = $wpdb->prefix . 'amap_magic_links';

    // token_hash stocke le hachage (sha256) du jeton, jamais le jeton en clair : seul le lien
    // envoyé par email contient le jeton réel, la base ne permet donc pas à elle seule de se
    // connecter à la place d'un adhérent. used_at NULL = jeton encore valide ; renseigné au
    // moment du clic sur le lien de confirmation (pas au simple chargement de la page), ce qui
    // rend le jeton à usage unique tout en résistant aux scanners anti-spam qui préchargent les
    // liens des emails. purpose distingue un jeton de connexion ('login') d'un jeton donnant
    // accès au formulaire de réinitialisation de mot de passe ('password_reset') pour les
    // comptes producteur/bureau : même mécanique de sécurité, deux usages.
    $sql_magic_links = "CREATE TABLE $magic_links_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        token_hash char(64) NOT NULL,
        purpose varchar(20) NOT NULL DEFAULT 'login',
        expires_at datetime NOT NULL,
        used_at datetime DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY token_hash (token_hash),
        KEY user_id (user_id)
    ) $charset_collate;";

    dbDelta( $sql_magic_links );

    $groups_table = $wpdb->prefix . 'amap_groups';

    // weekday : 0=lundi ... 6=dimanche (voir amap_get_weekday_labels()), jour fixe de la
    // distribution hebdomadaire du groupe. start_time/end_time : plage horaire fixe de cette
    // même distribution (ex. les adhérents doivent être présents 15 min avant/après, mais ce
    // délai est une règle appliquée à l'usage, pas stockée ici). notification_email : adresse
    // alias gérée côté bureau (hors outil, ex. liste de diffusion) qui reçoit un récapitulatif
    // lors d'une exception de distribution (étape 11) — un seul envoi par exception plutôt qu'une
    // boucle sur chaque adhérent du groupe, pour rester sous la limite d'envois quotidiens de
    // Brevo si plusieurs groupes ont une exception le même jour. Optionnelle : si vide, aucune
    // notification n'est envoyée pour ce groupe (voir amap_notify_distribution_exception()).
    $sql_groups = "CREATE TABLE $groups_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(120) NOT NULL,
        delivery_place varchar(255) NOT NULL,
        weekday tinyint(1) unsigned NOT NULL,
        start_time time NOT NULL,
        end_time time NOT NULL,
        notification_email varchar(255) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    dbDelta( $sql_groups );

    $group_producers_table = $wpdb->prefix . 'amap_group_producers';

    // Rattachement producteur↔groupe décidé par le bureau : un groupe n'a pas accès à tous
    // les producteurs automatiquement, un producteur peut être rattaché à plusieurs groupes.
    // UNIQUE(group_id, producer_user_id) empêche un doublon de rattachement.
    $sql_group_producers = "CREATE TABLE $group_producers_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        group_id bigint(20) unsigned NOT NULL,
        producer_user_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY group_producer (group_id, producer_user_id)
    ) $charset_collate;";

    dbDelta( $sql_group_producers );

    $group_members_table = $wpdb->prefix . 'amap_group_members';

    // Rattachement adhérent↔groupe (point de retrait), fixé par le bureau sur la page
    // "Utilisateurs AMAP" plutôt que choisi librement par l'adhérent à chaque souscription.
    // UNIQUE(member_user_id), et non UNIQUE(group_id, member_user_id) comme pour
    // wp_amap_group_producers : on suppose pour l'instant qu'un adhérent n'appartient qu'à un
    // seul groupe (contrairement à un producteur, qui peut en livrer plusieurs) — à desserrer
    // le jour où cette hypothèse ne tient plus.
    $sql_group_members = "CREATE TABLE $group_members_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        group_id bigint(20) unsigned NOT NULL,
        member_user_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY member_user_id (member_user_id)
    ) $charset_collate;";

    dbDelta( $sql_group_members );

    $contracts_table = $wpdb->prefix . 'amap_contracts';

    // Table mère des contrats, discriminée par contract_type : 'basket_recurring' (maraîcher,
    // panier à fréquence fixe) ou 'product_grid' (laitière/boulangers, grille produit×date
    // remplie une fois à la signature — tables filles prévues aux étapes 4b/4c). frequency_weeks
    // et max_leaves n'ont de sens que pour basket_recurring (1 = hebdo, 2 = toutes les 2 semaines ;
    // nombre de congés maraîcher autorisés sur la durée du contrat, voir wp_amap_leaves) ; NULL
    // sinon, contrôlé côté PHP comme les autres discriminants du plugin.
    $sql_contracts = "CREATE TABLE $contracts_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        producer_user_id bigint(20) unsigned NOT NULL,
        contract_type varchar(20) NOT NULL,
        label varchar(120) NOT NULL,
        start_date date NOT NULL,
        end_date date NOT NULL,
        frequency_weeks tinyint(2) unsigned DEFAULT NULL,
        max_leaves tinyint(2) unsigned DEFAULT NULL,
        is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    dbDelta( $sql_contracts );

    // dbDelta() ajoute la colonne max_leaves mais ne peut pas deviner une valeur pour les
    // contrats basket_recurring déjà enregistrés (NULL par défaut) : reprend la valeur qui était
    // jusqu'ici codée en dur (4) pour ne pas casser silencieusement les congés déjà en place sur
    // des contrats de test existants. Le bureau ajuste ensuite depuis la page "Contrats" si besoin.
    $wpdb->query(
        "UPDATE $contracts_table SET max_leaves = 4 WHERE contract_type = 'basket_recurring' AND max_leaves IS NULL"
    );

    $contract_basket_sizes_table = $wpdb->prefix . 'amap_contract_basket_sizes';

    // Table fille des tailles+prix, uniquement pour un contrat basket_recurring (ex. petit/
    // moyen/grand pour le maraîcher, prix fixe par taille). Un contrat product_grid n'a aucune
    // ligne ici. Pas de contrainte FOREIGN KEY SQL sur contract_id, comme le reste du plugin.
    $sql_contract_basket_sizes = "CREATE TABLE $contract_basket_sizes_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        contract_id bigint(20) unsigned NOT NULL,
        label varchar(60) NOT NULL,
        price decimal(6,2) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY contract_id (contract_id)
    ) $charset_collate;";

    dbDelta( $sql_contract_basket_sizes );

    $contract_discount_groups_table = $wpdb->prefix . 'amap_contract_discount_groups';

    // Famille de remise par quantité (product_grid uniquement, point ouvert depuis l'étape 4b —
    // voir docs/plan-contrats-distributions.md) : regroupe plusieurs produits du catalogue (ex.
    // les 10 variétés de yaourts) qui partagent un même prix unitaire et un même seuil "achetées
    // → facturées" (ex. 6 achetées, tous produits du groupe confondus, facturées 5). Le reliquat
    // hors palier plein est facturé normalement (calcul fait à l'étape 3, pas ici). Pas de
    // contrainte FOREIGN KEY SQL sur contract_id, comme le reste du plugin.
    $sql_contract_discount_groups = "CREATE TABLE $contract_discount_groups_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        contract_id bigint(20) unsigned NOT NULL,
        label varchar(60) NOT NULL,
        price decimal(6,2) unsigned NOT NULL,
        bought_quantity smallint(5) unsigned NOT NULL,
        billed_quantity smallint(5) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY contract_id (contract_id)
    ) $charset_collate;";

    dbDelta( $sql_contract_discount_groups );

    $contract_products_table = $wpdb->prefix . 'amap_contract_products';

    // Table fille du catalogue produits, uniquement pour un contrat product_grid (ex. yaourt,
    // lait, fromage blanc pour la productrice laitière). Un contrat basket_recurring n'a aucune
    // ligne ici. Même structure que wp_amap_contract_basket_sizes (label+prix) : pas de
    // contrainte FOREIGN KEY SQL sur contract_id, comme le reste du plugin. discount_group_id
    // NULL : produit hors famille de remise, prix libre (colonne price ci-dessous) ; sinon le
    // prix utilisé est celui de la famille (wp_amap_contract_discount_groups.price), price
    // reste renseignée mais n'est alors plus la valeur de facturation (étape 2 pour l'UI qui
    // masque ce cas).
    $sql_contract_products = "CREATE TABLE $contract_products_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        contract_id bigint(20) unsigned NOT NULL,
        label varchar(60) NOT NULL,
        price decimal(6,2) unsigned NOT NULL,
        discount_group_id bigint(20) unsigned DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY contract_id (contract_id),
        KEY discount_group_id (discount_group_id)
    ) $charset_collate;";

    dbDelta( $sql_contract_products );

    $contract_delivery_dates_table = $wpdb->prefix . 'amap_contract_delivery_dates';

    // Table fille des dates de livraison du trimestre, uniquement pour un contrat product_grid.
    // group_id : un producteur peut livrer plusieurs groupes de distribution
    // (wp_amap_group_producers), chacun avec son propre jour fixe (wp_amap_groups.weekday) — les
    // dates de livraison d'un même contrat diffèrent donc selon le groupe de l'adhérent.
    // UNIQUE(contract_id, group_id, delivery_date) : deux groupes différents peuvent tomber sur
    // le même jour calendaire si leurs weekday coïncident, ce n'est donc plus un doublon dans ce
    // cas. Revérifié côté PHP avant insert/update pour afficher un message clair (voir
    // amap_contract_has_delivery_date()), la contrainte SQL restant le garde-fou final.
    $sql_contract_delivery_dates = "CREATE TABLE $contract_delivery_dates_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        contract_id bigint(20) unsigned NOT NULL,
        group_id bigint(20) unsigned NOT NULL,
        delivery_date date NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY contract_group_delivery_date (contract_id, group_id, delivery_date)
    ) $charset_collate;";

    dbDelta( $sql_contract_delivery_dates );

    $subscriptions_table = $wpdb->prefix . 'amap_subscriptions';

    // Souscription d'un adhérent à un contrat. group_id : point de retrait de l'adhérent au
    // moment de la signature, dérivé de son rattachement (wp_amap_group_members) plutôt que
    // choisi librement — dupliqué ici (plutôt que rejoint à chaque lecture) car un changement de
    // rattachement plus tard ne doit pas modifier rétroactivement une souscription déjà signée.
    // basket_size_id n'a de sens que pour un contrat basket_recurring, NULL sinon (même
    // discriminant que wp_amap_contracts.frequency_weeks). signed_at est saisi manuellement par
    // le bureau (date de signature du contrat papier, potentiellement antérieure à la saisie
    // informatique) alors que created_at reste l'horodatage technique automatique. Pas de
    // contrainte UNIQUE(contract_id, member_user_id) (existait jusqu'à l'étape 7.3) : un compte
    // adhérent représente parfois un foyer entier, qui doit pouvoir souscrire plusieurs fois au
    // même contrat sous des lignes séparées (ex. 2 grands paniers + 1 petit) — l'index reste en
    // KEY simple, seulement pour les performances des lectures par (contract_id, member_user_id).
    // is_paid/paid_at : statut de paiement saisi manuellement par le bureau (relances non
    // gérées à ce stade) ; paid_at NULL tant que is_paid vaut 0.
    $sql_subscriptions = "CREATE TABLE $subscriptions_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        contract_id bigint(20) unsigned NOT NULL,
        member_user_id bigint(20) unsigned NOT NULL,
        group_id bigint(20) unsigned NOT NULL,
        basket_size_id bigint(20) unsigned DEFAULT NULL,
        signed_at date NOT NULL,
        is_paid tinyint(1) unsigned NOT NULL DEFAULT 0,
        paid_at date DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY contract_member (contract_id, member_user_id)
    ) $charset_collate;";

    dbDelta( $sql_subscriptions );

    // dbDelta() ne modifie pas fiablement un index existant qui change de type (UNIQUE → simple)
    // même à nom et colonnes identiques (limitation déjà rencontrée à l'étape 4c pour un
    // changement de composition de colonnes) : la contrainte UNIQUE posée à l'étape 5 est retirée
    // explicitement ici plutôt que par un DROP TABLE, pour ne pas perdre les souscriptions déjà
    // enregistrées.
    $existing_unique_index = $wpdb->get_row( "SHOW INDEX FROM $subscriptions_table WHERE Key_name = 'contract_member' AND Non_unique = 0" );
    if ( $existing_unique_index ) {
        $wpdb->query( "ALTER TABLE $subscriptions_table DROP INDEX contract_member" );
        $wpdb->query( "ALTER TABLE $subscriptions_table ADD KEY contract_member (contract_id, member_user_id)" );
    }

    $subscription_items_table = $wpdb->prefix . 'amap_subscription_items';

    // Grille produit×date, uniquement pour une souscription à un contrat product_grid : quantité
    // commandée par l'adhérent pour chaque couple (produit, date de livraison). Resynchronisée en
    // bloc (delete + réinsertion des cases > 0) à chaque modification de la souscription côté
    // admin (voir amap_handle_update_subscription()), pas de mise à jour ligne à ligne.
    // UNIQUE(subscription_id, contract_product_id, contract_delivery_date_id) : garde-fou contre
    // un doublon de case de la grille, même principe que les autres contraintes UNIQUE du plugin.
    $sql_subscription_items = "CREATE TABLE $subscription_items_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        subscription_id bigint(20) unsigned NOT NULL,
        contract_product_id bigint(20) unsigned NOT NULL,
        contract_delivery_date_id bigint(20) unsigned NOT NULL,
        quantity smallint(5) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY subscription_item (subscription_id, contract_product_id, contract_delivery_date_id)
    ) $charset_collate;";

    dbDelta( $sql_subscription_items );

    $leaves_table = $wpdb->prefix . 'amap_leaves';

    // Congé maraîcher (voir metier-producteurs.md) : date où l'adhérent ne récupère pas son
    // panier, rattachée à une souscription basket_recurring uniquement (jamais product_grid, et
    // jamais côté producteur). declared_at est la date de déclaration (aujourd'hui au moment de
    // l'ajout), distincte de created_at qui reste l'horodatage technique automatique — même
    // principe que signed_at sur wp_amap_subscriptions. Règles "max 4 par souscription" et
    // "délai d'une semaine avant la distribution" : vérifications applicatives (voir
    // amap_handle_add_leave()), pas de contrainte SQL — le délai d'une semaine ne concerne que la
    // future auto-déclaration adhérent, pas la saisie de secours par le bureau en admin.
    // UNIQUE(subscription_id, leave_date) : garde-fou contre un doublon de date.
    $sql_leaves = "CREATE TABLE $leaves_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        subscription_id bigint(20) unsigned NOT NULL,
        leave_date date NOT NULL,
        declared_at date NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY subscription_leave_date (subscription_id, leave_date)
    ) $charset_collate;";

    dbDelta( $sql_leaves );

    $distribution_exceptions_table = $wpdb->prefix . 'amap_distribution_exceptions';

    // Annulation/déplacement ponctuel d'une distribution, toujours décidé par le bureau (voir
    // metier-producteurs.md), jamais par le producteur ou le groupe seuls. Les distributions
    // normales ne sont pas stockées ligne par ligne (dérivées de wp_amap_groups.weekday) : seules
    // les exceptions le sont ici. new_date/new_start_time/new_end_time/new_place sont NULL quand
    // ils ne changent pas par rapport à la distribution normale du groupe — forcés à NULL pour
    // exception_type = 'cancelled', et au moins l'un des trois doit être renseigné pour 'moved'
    // (un déplacement peut ne changer que l'heure ou le lieu, à date inchangée). decided_by est le
    // user_id du membre du bureau qui a créé la ligne, jamais modifié ensuite même si un autre
    // membre corrige une saisie. notified_at reste NULL à cette étape : la notification aux
    // adhérents est un chantier séparé (voir plan-contrats-distributions.md, étape 11).
    // UNIQUE(group_id, distribution_date) : une seule exception par distribution, revérifiée côté
    // PHP (amap_group_has_distribution_exception()) avant la contrainte SQL.
    $sql_distribution_exceptions = "CREATE TABLE $distribution_exceptions_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        group_id bigint(20) unsigned NOT NULL,
        distribution_date date NOT NULL,
        exception_type varchar(20) NOT NULL,
        new_date date DEFAULT NULL,
        new_start_time time DEFAULT NULL,
        new_end_time time DEFAULT NULL,
        new_place varchar(255) DEFAULT NULL,
        reason varchar(255) DEFAULT NULL,
        decided_by bigint(20) unsigned NOT NULL,
        notified_at datetime DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY group_distribution_date (group_id, distribution_date)
    ) $charset_collate;";

    dbDelta( $sql_distribution_exceptions );

    $distribution_volunteers_table = $wpdb->prefix . 'amap_distribution_volunteers';

    // Roster des adhérents bénévoles tenant une distribution (voir metier-producteurs.md) : 2 à 3
    // bénévoles par distribution, présents 15 min avant/après, chaque adhérent devant en assurer au
    // moins 3 par an. Distinct des souscriptions (qui concernent la réception de produits, pas la
    // tenue de la distribution) : un adhérent n'est éligible que pour son propre groupe de retrait
    // (wp_amap_group_members), voir amap_get_group_member_users(). Règles "2 à 3 par distribution"
    // et "au moins 3 par an" : vérifications applicatives (COUNT), pas de contrainte SQL.
    // UNIQUE(group_id, distribution_date, member_user_id) : un adhérent ne peut être inscrit deux
    // fois sur la même distribution.
    $sql_distribution_volunteers = "CREATE TABLE $distribution_volunteers_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        group_id bigint(20) unsigned NOT NULL,
        distribution_date date NOT NULL,
        member_user_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY group_distribution_member (group_id, distribution_date, member_user_id)
    ) $charset_collate;";

    dbDelta( $sql_distribution_volunteers );
}

function amap_drop_obsolete_tables() {
    global $wpdb;

    // wp_amap_producers est remplacée par wp_amap_users (données communes à toutes les
    // casquettes, plus rôle amap_producer cumulable). dbDelta() ne supprime jamais de table,
    // il faut le faire explicitement.
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'amap_producers' );

    // wp_amap_totp_secrets : la 2FA par TOTP a été abandonnée au profit d'un second facteur par
    // lien magique (comme pour les adhérents), voir amap_send_login_link().
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'amap_totp_secrets' );
}
