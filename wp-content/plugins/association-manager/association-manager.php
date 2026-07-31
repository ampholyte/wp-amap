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

register_activation_hook( __FILE__, 'amap_activate' );

function amap_activate() {
    // update_option() (et non plus add_option()) : la version doit refléter le schéma du
    // code à chaque activation. dbDelta() est idempotent, le rappeler ne pose pas de problème.
    update_option( 'amap_db_version', '1.1' );
    amap_create_tables();
}

function amap_create_tables() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'amap_producers';
    $charset_collate = $wpdb->get_charset_collate();

    // Formatage imposé par dbDelta() : une colonne par ligne, deux espaces avant la
    // clé primaire, pas de backticks autour des noms de colonnes.
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        last_name varchar(255) NOT NULL,
        first_name varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        phone varchar(30) NOT NULL,
        address varchar(255) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY email (email)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // dbDelta() ajoute/modifie des colonnes mais n'en supprime jamais : l'ancienne colonne
    // "name" (remplacée par last_name/first_name) doit être retirée explicitement si elle
    // existe encore, pour les installations créées avant ce changement de schéma.
    $existing_columns = $wpdb->get_col( "DESC $table_name", 0 );
    if ( in_array( 'name', $existing_columns, true ) ) {
        $wpdb->query( "ALTER TABLE $table_name DROP COLUMN name" );
    }
}

add_action( 'admin_menu', 'amap_register_admin_menu' );

function amap_register_admin_menu() {
    add_menu_page(
        __( 'AMAP', 'association-manager' ),
        __( 'AMAP', 'association-manager' ),
        'manage_options',
        'amap-producers',
        'amap_render_producers_page',
        'dashicons-store',
        26
    );
}

function amap_get_producer( $id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'amap_producers';
    return $wpdb->get_row( $wpdb->prepare( "SELECT id, last_name, first_name, email, phone, address FROM $table_name WHERE id = %d", $id ) );
}

function amap_render_producers_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'amap_producers';
    $producers  = $wpdb->get_results( "SELECT id, last_name, first_name, email, phone, address, created_at FROM $table_name ORDER BY last_name ASC" );
    $notice     = isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '';

    // Mode édition : ?action=edit&id=X sur cette même page. Si l'ID ne correspond à
    // aucun producteur, on retombe silencieusement sur le formulaire d'ajout.
    $editing_id = 0;
    if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ) {
        $editing_id = absint( $_GET['id'] );
    }
    $producer_being_edited = $editing_id ? amap_get_producer( $editing_id ) : null;
    if ( $editing_id && ! $producer_being_edited ) {
        $editing_id = 0;
    }

    // Récupère les valeurs saisies avant la redirection en cas d'erreur (voir
    // amap_store_producer_form_data()), pour ne pas faire ressaisir tout le formulaire.
    $transient_key = 'amap_producer_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $producer_being_edited ) {
        // Pas d'erreur en attente : on préremplit avec les valeurs actuelles du producteur.
        $form_data = array(
            'last_name'  => $producer_being_edited->last_name,
            'first_name' => $producer_being_edited->first_name,
            'email'      => $producer_being_edited->email,
            'phone'      => $producer_being_edited->phone,
            'address'    => $producer_being_edited->address,
        );
    } else {
        $form_data = array();
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Producteurs', 'association-manager' ); ?></h1>

        <?php if ( 'invalid' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Nom, prénom, email et téléphone sont obligatoires.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'duplicate' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Un producteur avec cet email existe déjà.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_phone' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Le téléphone doit être au format 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></p></div>
        <?php endif; ?>

        <h2>
            <?php echo $editing_id
                ? esc_html__( 'Modifier un producteur', 'association-manager' )
                : esc_html__( 'Ajouter un producteur', 'association-manager' ); ?>
        </h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-producer-form">
            <?php if ( $editing_id ) : ?>
                <?php wp_nonce_field( 'amap_edit_producer_' . $editing_id ); ?>
                <input type="hidden" name="action" value="amap_update_producer">
                <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
            <?php else : ?>
                <?php wp_nonce_field( 'amap_add_producer' ); ?>
                <input type="hidden" name="action" value="amap_add_producer">
            <?php endif; ?>
            <p>
                <label>
                    <?php esc_html_e( 'Nom', 'association-manager' ); ?>
                    <input type="text" name="last_name" value="<?php echo esc_attr( $form_data['last_name'] ?? '' ); ?>" required>
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e( 'Prénom', 'association-manager' ); ?>
                    <input type="text" name="first_name" value="<?php echo esc_attr( $form_data['first_name'] ?? '' ); ?>" required>
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e( 'Email', 'association-manager' ); ?>
                    <input type="email" name="email" value="<?php echo esc_attr( $form_data['email'] ?? '' ); ?>" required>
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e( 'Téléphone', 'association-manager' ); ?>
                    <input type="text" inputmode="tel" name="phone" id="amap-producer-phone" value="<?php echo esc_attr( $form_data['phone'] ?? '' ); ?>" pattern="(0[1-9]|\+33[1-9])([\s.-]?\d{2}){4}" placeholder="0X XX XX XX XX" required>
                    <span id="amap-producer-phone-error" style="color:#d63638;" hidden><?php esc_html_e( 'Format attendu : 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></span>
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e( 'Adresse', 'association-manager' ); ?>
                    <input type="text" name="address" value="<?php echo esc_attr( $form_data['address'] ?? '' ); ?>">
                </label>
            </p>
            <p>
                <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                <?php if ( $editing_id ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-producers' ) ); ?>" class="button">
                        <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        </form>
        <script>
        ( function () {
            var form       = document.getElementById( 'amap-producer-form' );
            var phoneField = document.getElementById( 'amap-producer-phone' );
            var phoneError = document.getElementById( 'amap-producer-phone-error' );
            // Même règle que la validation serveur (amap_is_valid_phone) : on ne se fie pas
            // uniquement à l'attribut HTML "pattern", dont le comportement natif s'est révélé
            // peu fiable selon les navigateurs.
            var phonePattern = /^(0[1-9]\d{8}|\+33[1-9]\d{8})$/;

            function isPhoneValid( value ) {
                return phonePattern.test( value.replace( /[\s.-]/g, '' ) );
            }

            form.addEventListener( 'submit', function ( event ) {
                var valid = isPhoneValid( phoneField.value );
                phoneError.hidden = valid;
                if ( ! valid ) {
                    event.preventDefault();
                    phoneField.focus();
                }
            } );
        } )();
        </script>

        <?php if ( empty( $producers ) ) : ?>
            <p><?php esc_html_e( 'Aucun producteur enregistré pour le moment.', 'association-manager' ); ?></p>
        <?php else : ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Nom', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Prénom', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Téléphone', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Adresse', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Ajouté le', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'association-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $producers as $producer ) : ?>
                        <tr>
                            <td><?php echo esc_html( $producer->last_name ); ?></td>
                            <td><?php echo esc_html( $producer->first_name ); ?></td>
                            <td><?php echo esc_html( $producer->email ); ?></td>
                            <td><?php echo esc_html( $producer->phone ); ?></td>
                            <td><?php echo esc_html( $producer->address ); ?></td>
                            <td><?php echo esc_html( $producer->created_at ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-producers&action=edit&id=' . $producer->id ) ); ?>">
                                    <?php esc_html_e( 'Modifier', 'association-manager' ); ?>
                                </a>
                                |
                                <?php
                                $delete_url = wp_nonce_url(
                                    admin_url( 'admin-post.php?action=amap_delete_producer&id=' . $producer->id ),
                                    'amap_delete_producer_' . $producer->id
                                );
                                // translators: 1: prénom du producteur, 2: nom du producteur.
                                $confirm_message = sprintf( __( 'Supprimer %1$s %2$s ?', 'association-manager' ), $producer->first_name, $producer->last_name );
                                ?>
                                <a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm( '<?php echo esc_js( $confirm_message ); ?>' );">
                                    <?php esc_html_e( 'Supprimer', 'association-manager' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

function amap_is_valid_phone( $phone ) {
    // On tolère espaces, points et tirets entre les chiffres, mais on valide sur le
    // numéro "nettoyé" : 10 chiffres commençant par 0, ou +33 suivi de 9 chiffres.
    $digits_only = preg_replace( '/[\s.\-]/', '', $phone );
    return (bool) preg_match( '/^(0[1-9]\d{8}|\+33[1-9]\d{8})$/', $digits_only );
}

function amap_store_producer_form_data( array $data ) {
    // Durée de vie courte : cette donnée ne sert qu'à traverser la redirection qui suit
    // immédiatement une erreur de validation, pas à persister au-delà.
    set_transient( 'amap_producer_form_' . get_current_user_id(), $data, 60 );
}

add_action( 'admin_post_amap_add_producer', 'amap_handle_add_producer' );

function amap_handle_add_producer() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_producer' );

    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
    $submitted  = compact( 'last_name', 'first_name', 'email', 'phone', 'address' );

    if ( '' === $last_name || '' === $first_name || '' === $email || '' === $phone ) {
        amap_store_producer_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-producers&amap_notice=invalid' ) );
        exit;
    }

    if ( ! amap_is_valid_phone( $phone ) ) {
        amap_store_producer_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-producers&amap_notice=invalid_phone' ) );
        exit;
    }

    global $wpdb;
    $inserted = $wpdb->insert(
        $wpdb->prefix . 'amap_producers',
        array(
            'last_name'  => $last_name,
            'first_name' => $first_name,
            'email'      => $email,
            'phone'      => $phone,
            'address'    => '' !== $address ? $address : null,
        )
    );

    if ( false === $inserted ) {
        amap_store_producer_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-producers&amap_notice=duplicate' ) );
        exit;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-producers' ) );
    exit;
}

add_action( 'admin_post_amap_delete_producer', 'amap_handle_delete_producer' );

function amap_handle_delete_producer() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    if ( ! $id ) {
        wp_die( esc_html__( 'Producteur introuvable.', 'association-manager' ) );
    }

    // check_admin_referer() lit aussi bien $_GET que $_POST : ici le nonce arrive en
    // query string via wp_nonce_url(), pas dans un champ de formulaire.
    check_admin_referer( 'amap_delete_producer_' . $id );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_producers', array( 'id' => $id ) );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-producers' ) );
    exit;
}

add_action( 'admin_post_amap_update_producer', 'amap_handle_update_producer' );

function amap_handle_update_producer() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    if ( ! $id ) {
        wp_die( esc_html__( 'Producteur introuvable.', 'association-manager' ) );
    }

    // La chaîne d'action du nonce inclut l'ID : un nonce généré pour le formulaire du
    // producteur 5 est rejeté si le champ caché "id" a été modifié pour viser un autre ID.
    check_admin_referer( 'amap_edit_producer_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-producers&action=edit&id=' . $id );

    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
    $submitted  = compact( 'last_name', 'first_name', 'email', 'phone', 'address' );

    if ( '' === $last_name || '' === $first_name || '' === $email || '' === $phone ) {
        amap_store_producer_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid' );
        exit;
    }

    if ( ! amap_is_valid_phone( $phone ) ) {
        amap_store_producer_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid_phone' );
        exit;
    }

    global $wpdb;
    $updated = $wpdb->update(
        $wpdb->prefix . 'amap_producers',
        array(
            'last_name'  => $last_name,
            'first_name' => $first_name,
            'email'      => $email,
            'phone'      => $phone,
            'address'    => '' !== $address ? $address : null,
        ),
        array( 'id' => $id )
    );

    if ( false === $updated ) {
        amap_store_producer_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=duplicate' );
        exit;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-producers' ) );
    exit;
}
