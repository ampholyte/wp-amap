<?php
/**
 * Page d'admin "Utilisateurs AMAP" : CRUD des comptes portant une casquette AMAP.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function amap_get_user_contact( $user_id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT phone, address FROM {$wpdb->prefix}amap_users WHERE user_id = %d",
            $user_id
        )
    );
}

function amap_get_available_roles() {
    return array(
        'amap_member'   => __( 'Adhérent', 'association-manager' ),
        'amap_producer' => __( 'Producteur', 'association-manager' ),
        'amap_board'    => __( 'Bureau', 'association-manager' ),
    );
}

function amap_format_user_roles( array $roles ) {
    // Ordre d'affichage fixe (adhérent, producteur, bureau) indépendant de l'ordre de
    // $roles, qui reflète l'ordre d'ajout des casquettes plutôt qu'un ordre voulu à l'affichage.
    return implode( ', ', array_intersect_key( amap_get_available_roles(), array_flip( $roles ) ) );
}

/**
 * Récupère un utilisateur AMAP par son ID (celui du compte WordPress). Retourne null si le
 * compte n'existe pas ou ne porte aucune des trois casquettes — un simple abonné WP par
 * exemple n'est pas un "utilisateur AMAP" éditable depuis cette page.
 */
function amap_get_amap_user( $user_id ) {
    $user = get_user_by( 'id', $user_id );
    if ( ! $user || ! array_intersect( $user->roles, array_keys( amap_get_available_roles() ) ) ) {
        return null;
    }

    return $user;
}

function amap_render_users_page() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        return;
    }

    // Fiche producteur en lecture seule (?action=view_producer&id=X) : remplace entièrement la
    // liste/formulaire habituels de cette page, plutôt qu'une nouvelle page CRUD séparée — voir
    // amap_render_producer_profile_page().
    if ( isset( $_GET['action'], $_GET['id'] ) && 'view_producer' === $_GET['action'] ) {
        amap_render_producer_profile_page( absint( $_GET['id'] ) );
        return;
    }

    $notice = isset( $_GET['amap_notice'] ) ? sanitize_key( wp_unslash( $_GET['amap_notice'] ) ) : '';

    $magic_link_error_key = 'amap_magic_link_error_' . get_current_user_id();
    $magic_link_error     = get_transient( $magic_link_error_key );
    if ( false !== $magic_link_error ) {
        delete_transient( $magic_link_error_key );
    }

    // Mode édition : ?action=edit&id=X sur cette même page. Si l'ID ne correspond à aucun
    // utilisateur AMAP, on retombe silencieusement sur le formulaire d'ajout.
    $editing_id = 0;
    if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ) {
        $editing_id = absint( $_GET['id'] );
    }
    $editing_user = $editing_id ? amap_get_amap_user( $editing_id ) : null;
    // Un compte administrateur ne peut pas être modifié depuis cette page (voir
    // amap_handle_update_user()) : on retombe sur le formulaire d'ajout plutôt que d'afficher un
    // formulaire d'édition dont la soumission serait de toute façon refusée côté serveur.
    if ( $editing_user && in_array( 'administrator', $editing_user->roles, true ) ) {
        $editing_user = null;
    }
    if ( $editing_id && ! $editing_user ) {
        $editing_id = 0;
    }

    // Récupère les valeurs saisies avant la redirection en cas d'erreur (voir
    // amap_store_user_form_data()), pour ne pas faire ressaisir tout le formulaire.
    $transient_key = 'amap_user_form_' . get_current_user_id();
    $form_data     = get_transient( $transient_key );
    if ( false !== $form_data ) {
        delete_transient( $transient_key );
    } elseif ( $editing_user ) {
        // Pas d'erreur en attente : on préremplit avec les valeurs actuelles de l'utilisateur.
        $contact      = amap_get_user_contact( $editing_user->ID );
        $member_group = amap_get_member_group( $editing_user->ID );
        $form_data    = array(
            'last_name'  => $editing_user->last_name,
            'first_name' => $editing_user->first_name,
            'email'      => $editing_user->user_email,
            'phone'      => $contact->phone ?? '',
            'address'    => $contact->address ?? '',
            'roles'      => array_intersect( $editing_user->roles, array_keys( amap_get_available_roles() ) ),
            'group_id'   => $member_group ? (string) $member_group->id : '',
        );
    } else {
        $form_data = array();
    }
    $selected_roles    = $form_data['roles'] ?? array();
    $selected_group_id = $form_data['group_id'] ?? '';
    $groups            = amap_get_groups();

    $users_list_table = new Amap_Users_List_Table();
    $users_list_table->prepare_items();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Utilisateurs AMAP', 'association-manager' ); ?></h1>

        <?php if ( 'reused' === $notice ) : ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Compte WordPress existant réutilisé : rôle(s) et coordonnées mis à jour.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Champs obligatoires manquants ou aucun rôle sélectionné.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'invalid_phone' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Le téléphone doit être au format 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'account_error' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Impossible de créer le compte WordPress associé à cet email.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'contact_error' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( "Le compte a été créé ou mis à jour mais l'enregistrement du téléphone/adresse a échoué.", 'association-manager' ); ?></p></div>
        <?php elseif ( 'email_taken' === $notice ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Cet email est déjà utilisé par un autre compte WordPress.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'magic_link_sent' === $notice ) : ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Lien de connexion envoyé.', 'association-manager' ); ?></p></div>
        <?php elseif ( 'magic_link_failed' === $notice ) : ?>
            <div class="notice notice-error">
                <p>
                    <?php esc_html_e( "Échec de l'envoi du lien de connexion.", 'association-manager' ); ?>
                    <?php if ( $magic_link_error ) : ?>
                        <?php echo esc_html( $magic_link_error ); ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ( ! $editing_id ) : ?>
            <p>
                <button type="button" class="button button-primary" id="amap-user-add-toggle"><?php esc_html_e( '+ Ajouter un utilisateur', 'association-manager' ); ?></button>
            </p>
        <?php endif; ?>
        <div id="amap-user-form-wrapper"<?php echo $editing_id ? '' : ' hidden'; ?>>
        <h2>
            <?php echo $editing_id
                ? esc_html__( 'Modifier un utilisateur', 'association-manager' )
                : esc_html__( 'Ajouter un utilisateur', 'association-manager' ); ?>
        </h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-user-form">
            <?php if ( $editing_id ) : ?>
                <?php wp_nonce_field( 'amap_edit_user_' . $editing_id ); ?>
                <input type="hidden" name="action" value="amap_update_user">
                <input type="hidden" name="id" value="<?php echo esc_attr( $editing_id ); ?>">
            <?php else : ?>
                <?php wp_nonce_field( 'amap_add_user' ); ?>
                <input type="hidden" name="action" value="amap_add_user">
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><label for="amap-user-last-name"><?php esc_html_e( 'Nom', 'association-manager' ); ?></label></th>
                    <td><input type="text" id="amap-user-last-name" name="last_name" value="<?php echo esc_attr( $form_data['last_name'] ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="amap-user-first-name"><?php esc_html_e( 'Prénom', 'association-manager' ); ?></label></th>
                    <td><input type="text" id="amap-user-first-name" name="first_name" value="<?php echo esc_attr( $form_data['first_name'] ?? '' ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="amap-user-email"><?php esc_html_e( 'Email', 'association-manager' ); ?></label></th>
                    <td>
                        <input type="email" id="amap-user-email" name="email" value="<?php echo esc_attr( $form_data['email'] ?? '' ); ?>" required>
                        <?php if ( ! $editing_id ) : ?>
                            <p class="description">
                                <?php esc_html_e( "Si un compte WordPress existe déjà avec cet email, il est réutilisé (identité inchangée) et les rôles cochés ci-dessous lui sont simplement ajoutés — utile pour faire cumuler une nouvelle casquette à un utilisateur existant.", 'association-manager' ); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="amap-user-phone"><?php esc_html_e( 'Téléphone', 'association-manager' ); ?></label></th>
                    <td>
                        <input type="text" inputmode="tel" name="phone" id="amap-user-phone" value="<?php echo esc_attr( $form_data['phone'] ?? '' ); ?>" pattern="(0[1-9]|\+33[1-9])([\s.-]?\d{2}){4}" placeholder="0X XX XX XX XX" required>
                        <span id="amap-user-phone-error" style="color:#d63638;" hidden><?php esc_html_e( 'Format attendu : 0X XX XX XX XX ou +33 X XX XX XX XX.', 'association-manager' ); ?></span>
                    </td>
                </tr>
                <tr>
                    <th><label for="amap-user-address"><?php esc_html_e( 'Adresse', 'association-manager' ); ?></label></th>
                    <td><input type="text" id="amap-user-address" name="address" value="<?php echo esc_attr( $form_data['address'] ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Rôles', 'association-manager' ); ?></th>
                    <td>
                        <?php foreach ( amap_get_available_roles() as $role_slug => $role_label ) : ?>
                            <label>
                                <input type="checkbox" id="amap-user-role-<?php echo esc_attr( $role_slug ); ?>" name="roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $selected_roles, true ) ); ?>>
                                <?php echo esc_html( $role_label ); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr id="amap-user-group-row"<?php echo in_array( 'amap_member', $selected_roles, true ) ? '' : ' hidden'; ?>>
                    <th><label for="amap-user-group"><?php esc_html_e( 'Groupe (point de retrait)', 'association-manager' ); ?></label></th>
                    <td>
                        <select id="amap-user-group" name="group_id">
                            <option value=""><?php esc_html_e( '— aucun pour l\'instant —', 'association-manager' ); ?></option>
                            <?php foreach ( $groups as $group ) : ?>
                                <option value="<?php echo esc_attr( $group->id ); ?>" <?php selected( (string) $group->id, $selected_group_id ); ?>>
                                    <?php echo esc_html( $group->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( "Point de retrait de l'adhérent : détermine les contrats qu'il pourra voir et souscrire.", 'association-manager' ); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <?php submit_button( $editing_id ? __( 'Enregistrer', 'association-manager' ) : __( 'Ajouter', 'association-manager' ), 'primary', 'submit', false ); ?>
                <?php if ( $editing_id ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-users' ) ); ?>" class="button">
                        <?php esc_html_e( 'Annuler', 'association-manager' ); ?>
                    </a>
                <?php else : ?>
                    <button type="button" class="button" id="amap-user-add-cancel"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></button>
                <?php endif; ?>
            </p>
        </form>
        </div>
        <script>
        ( function () {
            var toggle  = document.getElementById( 'amap-user-add-toggle' );
            var wrapper = document.getElementById( 'amap-user-form-wrapper' );
            var cancel  = document.getElementById( 'amap-user-add-cancel' );
            if ( toggle ) {
                toggle.addEventListener( 'click', function () {
                    wrapper.hidden = false;
                    toggle.hidden  = true;
                } );
            }
            if ( cancel ) {
                cancel.addEventListener( 'click', function () {
                    wrapper.hidden = true;
                    toggle.hidden  = false;
                } );
            }
        } )();
        </script>
        <script>
        ( function () {
            var memberCheckbox = document.getElementById( 'amap-user-role-amap_member' );
            var groupRow        = document.getElementById( 'amap-user-group-row' );
            if ( memberCheckbox && groupRow ) {
                memberCheckbox.addEventListener( 'change', function () {
                    groupRow.hidden = ! memberCheckbox.checked;
                } );
            }
        } )();
        </script>
        <script>
        ( function () {
            var form        = document.getElementById( 'amap-user-form' );
            var phoneField  = document.getElementById( 'amap-user-phone' );
            var phoneError  = document.getElementById( 'amap-user-phone-error' );
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

        <form method="get">
            <input type="hidden" name="page" value="amap-users">
            <?php
            $users_list_table->search_box( __( 'Rechercher', 'association-manager' ), 'amap-user' );
            $users_list_table->display();
            ?>
        </form>
    </div>
    <?php
}

/**
 * Fiche producteur agrégée en lecture seule : coordonnées + groupes de livraison + contrats,
 * pour éviter d'avoir à recouper trois pages différentes quand le bureau veut juste consulter la
 * situation d'un producteur. Volontairement pas de formulaire d'édition ici : les modifications
 * restent sur les pages Utilisateurs AMAP/Groupes/Contrats existantes.
 */
function amap_render_producer_profile_page( $producer_user_id ) {
    $producer = get_user_by( 'id', $producer_user_id );
    if ( ! $producer || ! in_array( 'amap_producer', $producer->roles, true ) ) {
        wp_die( esc_html__( 'Producteur introuvable.', 'association-manager' ) );
    }

    $contact        = amap_get_user_contact( $producer->ID );
    $groups         = amap_get_producer_groups( $producer->ID );
    $contracts      = amap_get_producer_contracts( $producer->ID );
    $weekday_labels = amap_get_weekday_labels();
    $contract_types = amap_get_contract_types();
    ?>
    <div class="wrap">
        <h1>
            <?php
            printf(
                /* translators: %s: nom du producteur. */
                esc_html__( 'Fiche producteur : %s', 'association-manager' ),
                esc_html( $producer->display_name )
            );
            ?>
        </h1>

        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-users' ) ); ?>">&larr; <?php esc_html_e( 'Retour à la liste des utilisateurs', 'association-manager' ); ?></a></p>

        <h2><?php esc_html_e( 'Coordonnées', 'association-manager' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Nom', 'association-manager' ); ?></th>
                <td><?php echo esc_html( $producer->display_name ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Email', 'association-manager' ); ?></th>
                <td><?php echo esc_html( $producer->user_email ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Téléphone', 'association-manager' ); ?></th>
                <td><?php echo esc_html( $contact->phone ?? '—' ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Adresse', 'association-manager' ); ?></th>
                <td><?php echo esc_html( $contact->address ?? '—' ); ?></td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Groupes de livraison rattachés', 'association-manager' ); ?></h2>
        <?php if ( empty( $groups ) ) : ?>
            <p><?php esc_html_e( "Ce producteur n'est rattaché à aucun groupe de distribution.", 'association-manager' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Nom', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Jour', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Horaire', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Lieu de livraison', 'association-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $groups as $group ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-groups&action=edit&id=' . $group->id ) ); ?>">
                                    <?php echo esc_html( $group->name ); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( $weekday_labels[ (int) $group->weekday ] ?? '' ); ?></td>
                            <td><?php echo esc_html( amap_format_time( $group->start_time ) . ' - ' . amap_format_time( $group->end_time ) ); ?></td>
                            <td><?php echo esc_html( $group->delivery_place ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?php esc_html_e( 'Contrats', 'association-manager' ); ?></h2>
        <?php if ( empty( $contracts ) ) : ?>
            <p><?php esc_html_e( "Ce producteur n'a aucun contrat.", 'association-manager' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Libellé', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Période', 'association-manager' ); ?></th>
                        <th><?php esc_html_e( 'Actif', 'association-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $contracts as $contract ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=amap-contracts&action=edit&id=' . $contract->id ) ); ?>">
                                    <?php echo esc_html( $contract->label ); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( $contract_types[ $contract->contract_type ] ?? $contract->contract_type ); ?></td>
                            <td><?php echo esc_html( $contract->start_date . ' → ' . $contract->end_date ); ?></td>
                            <td><?php echo $contract->is_active ? esc_html__( 'Oui', 'association-manager' ) : esc_html__( 'Non', 'association-manager' ); ?></td>
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

function amap_store_user_form_data( array $data ) {
    // Durée de vie courte : cette donnée ne sert qu'à traverser la redirection qui suit
    // immédiatement une erreur de validation, pas à persister au-delà.
    set_transient( 'amap_user_form_' . get_current_user_id(), $data, 60 );
}

/**
 * Trouve le compte WordPress correspondant à cet email, ou en crée un nouveau. Aucun mot de
 * passe n'est communiqué à la création : l'authentification par casquette est une étape
 * ultérieure, ici on ne fait que poser l'identité (wp_users) et les rôles seront ajoutés par
 * l'appelant.
 */
function amap_find_or_create_user( $first_name, $last_name, $email ) {
    $user = get_user_by( 'email', $email );
    if ( $user ) {
        return $user;
    }

    $new_user_id = wp_insert_user(
        array(
            'user_login' => amap_generate_username( $first_name, $last_name ),
            'user_email' => $email,
            'user_pass'  => wp_generate_password( 32, true, true ),
            'first_name' => $first_name,
            'last_name'  => $last_name,
            // Chaîne vide : aucun rôle par défaut (ex. subscriber) n'est assigné à la
            // création, seuls les rôles cochés dans le formulaire seront ajoutés par l'appelant.
            'role'       => '',
        )
    );

    if ( is_wp_error( $new_user_id ) ) {
        return $new_user_id;
    }

    return get_user_by( 'id', $new_user_id );
}

/**
 * Construit un identifiant de connexion unique "prenom.nom", avec un suffixe numérique en cas
 * de collision (deux utilisateurs peuvent porter le même nom complet).
 */
function amap_generate_username( $first_name, $last_name ) {
    $base     = sanitize_user( remove_accents( $first_name . '.' . $last_name ), true );
    $username = $base;
    $suffix   = 1;

    while ( username_exists( $username ) ) {
        ++$suffix;
        $username = $base . $suffix;
    }

    return $username;
}

/**
 * Crée ou met à jour la ligne wp_amap_users (phone/address) d'un utilisateur. UPDATE plutôt
 * que $wpdb->replace() : replace() supprimerait puis réinsérerait la ligne, ce qui changerait
 * l'id et réinitialiserait created_at à chaque simple mise à jour du téléphone.
 */
function amap_save_user_contact( $user_id, $phone, $address ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'amap_users';
    $data       = array(
        'phone'   => $phone,
        'address' => '' !== $address ? $address : null,
    );

    $existing_id = $wpdb->get_var(
        $wpdb->prepare( "SELECT id FROM $table_name WHERE user_id = %d", $user_id )
    );

    if ( $existing_id ) {
        return false !== $wpdb->update( $table_name, $data, array( 'user_id' => $user_id ) );
    }

    $data['user_id'] = $user_id;
    return false !== $wpdb->insert( $table_name, $data );
}

add_action( 'admin_post_amap_add_user', 'amap_handle_add_user' );

function amap_handle_add_user() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_add_user' );

    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
    $roles      = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['roles'] ) ) : array();
    $roles      = array_values( array_intersect( $roles, array_keys( amap_get_available_roles() ) ) );
    $group_id   = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $submitted  = compact( 'last_name', 'first_name', 'email', 'phone', 'address', 'roles', 'group_id' );

    if ( '' === $last_name || '' === $first_name || '' === $email || '' === $phone || empty( $roles )
        || ( $group_id && ! amap_get_group( $group_id ) ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=invalid' ) );
        exit;
    }

    if ( ! amap_is_valid_phone( $phone ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=invalid_phone' ) );
        exit;
    }

    // Vérifié avant amap_find_or_create_user() : une fois celle-ci appelée, le compte existe
    // forcément (créé ou préexistant), on ne pourrait plus distinguer les deux cas.
    $account_already_existed = (bool) get_user_by( 'email', $email );

    $user = amap_find_or_create_user( $first_name, $last_name, $email );
    if ( is_wp_error( $user ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=account_error' ) );
        exit;
    }

    // Cumul des casquettes : add_role() ajoute le rôle sans retirer les rôles déjà présents.
    // Soumettre à nouveau ce formulaire avec le même email permet donc d'ajouter une nouvelle
    // casquette (ex. producteur) à un compte déjà adhérent, sans dupliquer l'identité.
    foreach ( $roles as $role ) {
        $user->add_role( $role );
    }

    // Comme les rôles, le groupe n'est fixé que si "Adhérent" est coché dans CETTE soumission :
    // un compte déjà adhérent, réutilisé ici seulement pour lui ajouter une autre casquette, ne
    // doit pas se voir modifier ou retirer son groupe existant.
    if ( in_array( 'amap_member', $roles, true ) ) {
        amap_set_member_group( $user->ID, $group_id );
    }

    if ( ! amap_save_user_contact( $user->ID, $phone, $address ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=contact_error' ) );
        exit;
    }

    // Premier accès envoyé uniquement pour un compte réellement nouveau : réutiliser ce
    // formulaire pour ajouter une casquette à un compte déjà existant ne doit pas renvoyer de
    // lien à quelqu'un qui a déjà accès à son espace.
    if ( ! $account_already_existed ) {
        if ( amap_user_uses_magic_link( $user ) ) {
            amap_send_login_link( $user );
        } else {
            amap_send_password_reset_link( $user );
        }
    }

    $redirect_notice = $account_already_existed ? '&amap_notice=reused' : '';
    wp_safe_redirect( admin_url( 'admin.php?page=amap-users' . $redirect_notice ) );
    exit;
}

add_action( 'admin_post_amap_update_user', 'amap_handle_update_user' );

function amap_handle_update_user() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $user = $id ? amap_get_amap_user( $id ) : null;
    if ( ! $user ) {
        wp_die( esc_html__( 'Utilisateur introuvable.', 'association-manager' ) );
    }

    // Même garde-fou que amap_handle_delete_user(), suite au même incident réel : un compte
    // administrateur qui porte aussi une casquette AMAP ne doit pas pouvoir être modifié depuis
    // cette page (changer son email permettrait de prendre le contrôle du compte administrateur
    // via "mot de passe oublié" sur wp-login.php).
    if ( in_array( 'administrator', $user->roles, true ) ) {
        wp_die( esc_html__( 'Modification impossible : ce compte porte le rôle administrateur WordPress.', 'association-manager' ) );
    }

    // La chaîne d'action du nonce inclut l'ID : un nonce généré pour le formulaire de
    // l'utilisateur 5 est rejeté si le champ caché "id" a été modifié pour viser un autre ID.
    check_admin_referer( 'amap_edit_user_' . $id );

    $edit_url = admin_url( 'admin.php?page=amap-users&action=edit&id=' . $id );

    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $address    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
    $roles      = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['roles'] ) ) : array();
    $roles      = array_values( array_intersect( $roles, array_keys( amap_get_available_roles() ) ) );
    $group_id   = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
    $submitted  = compact( 'last_name', 'first_name', 'email', 'phone', 'address', 'roles', 'group_id' );

    if ( '' === $last_name || '' === $first_name || '' === $email || '' === $phone || empty( $roles )
        || ( $group_id && ! amap_get_group( $group_id ) ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid' );
        exit;
    }

    if ( ! amap_is_valid_phone( $phone ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=invalid_phone' );
        exit;
    }

    // Contrairement à l'ajout (qui réutilise un compte existant), ici l'email doit rester
    // celui de CE compte : s'il correspond à un AUTRE compte WordPress, c'est un conflit.
    $email_owner = get_user_by( 'email', $email );
    if ( $email_owner && $email_owner->ID !== $id ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=email_taken' );
        exit;
    }

    $updated = wp_update_user(
        array(
            'ID'         => $id,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'user_email' => $email,
        )
    );

    if ( is_wp_error( $updated ) ) {
        amap_store_user_form_data( $submitted );
        wp_safe_redirect( $edit_url . '&amap_notice=account_error' );
        exit;
    }

    // Contrairement à l'ajout (qui cumule sans jamais retirer de casquette), l'édition
    // applique exactement l'ensemble de rôles coché : une casquette décochée est retirée.
    foreach ( amap_get_available_roles() as $role_slug => $role_label ) {
        $has_role   = in_array( $role_slug, $user->roles, true );
        $wants_role = in_array( $role_slug, $roles, true );

        if ( $wants_role && ! $has_role ) {
            $user->add_role( $role_slug );
        } elseif ( ! $wants_role && $has_role ) {
            $user->remove_role( $role_slug );
        }
    }

    // Contrairement à l'ajout, l'édition applique ici aussi l'état exact de la casquette
    // adhérent : décocher "Adhérent" retire le groupe (amap_set_member_group( $id, 0 )), pour ne
    // pas laisser un rattachement orphelin sur un compte qui n'est plus adhérent.
    amap_set_member_group( $id, in_array( 'amap_member', $roles, true ) ? $group_id : 0 );

    if ( ! amap_save_user_contact( $id, $phone, $address ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=contact_error' ) );
        exit;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-users' ) );
    exit;
}

add_action( 'admin_post_amap_delete_user', 'amap_handle_delete_user' );

function amap_handle_delete_user() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    $id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $user = $id ? amap_get_amap_user( $id ) : null;
    if ( ! $user ) {
        wp_die( esc_html__( 'Utilisateur introuvable.', 'association-manager' ) );
    }

    // Garde-fou suite à un incident réel : un compte administrateur qui porte aussi une
    // casquette AMAP (ex. amap_board, pour tester cette page) apparaît dans cette liste comme
    // n'importe quel autre utilisateur AMAP. Le supprimer supprimerait le compte WordPress en
    // entier (amap_get_amap_user()/wp_delete_user() ci-dessous), pas seulement la casquette.
    if ( in_array( 'administrator', $user->roles, true ) ) {
        wp_die( esc_html__( 'Suppression impossible : ce compte porte le rôle administrateur WordPress.', 'association-manager' ) );
    }

    // Bloque plutôt que de supprimer en cascade : un producteur avec des contrats, ou un adhérent
    // avec des souscriptions, porte un historique (et, depuis le suivi de paiement, des montants
    // payés/impayés) qu'une suppression de compte effacerait ou laisserait orphelin.
    if ( in_array( 'amap_producer', $user->roles, true ) && amap_get_producer_contracts( $id ) ) {
        wp_die( esc_html__( 'Suppression impossible : ce producteur a des contrats enregistrés.', 'association-manager' ) );
    }

    if ( in_array( 'amap_member', $user->roles, true ) && amap_member_has_subscriptions( $id ) ) {
        wp_die( esc_html__( 'Suppression impossible : cet adhérent a des souscriptions enregistrées.', 'association-manager' ) );
    }

    // check_admin_referer() lit aussi bien $_GET que $_POST : ici le nonce arrive en query
    // string via wp_nonce_url(), pas dans un champ de formulaire.
    check_admin_referer( 'amap_delete_user_' . $id );

    // Suppression complète du compte WordPress (identité + rôles), pas seulement des
    // casquettes AMAP : cette page est le point d'entrée unique de gestion des utilisateurs.
    // Réattribution de l'éventuel contenu (articles/pages) de ce compte à la personne qui
    // effectue la suppression : sans ce second paramètre, wp_delete_user() envoie ce contenu à
    // la corbeille par défaut — incident réel qui avait vidé la page d'accueil du site après la
    // suppression d'un compte qui en était l'auteur.
    require_once ABSPATH . 'wp-admin/includes/user.php';
    if ( ! wp_delete_user( $id, get_current_user_id() ) ) {
        wp_die( esc_html__( 'La suppression du compte WordPress a échoué.', 'association-manager' ) );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'amap_users', array( 'user_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_group_members', array( 'member_user_id' => $id ) );
    $wpdb->delete( $wpdb->prefix . 'amap_magic_links', array( 'user_id' => $id ) );

    wp_safe_redirect( admin_url( 'admin.php?page=amap-users' ) );
    exit;
}
