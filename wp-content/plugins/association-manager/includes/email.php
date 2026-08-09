<?php
/**
 * Envoi d'emails transactionnels (API Brevo) et action d'email de test depuis l'admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Envoie un email transactionnel via l'API Brevo. Utilise wp_remote_post() (la "HTTP API" de
 * WordPress) plutôt qu'un appel curl direct : WordPress choisit lui-même le transport
 * disponible sur l'hébergement, ce qui compte puisqu'on ne maîtrise pas l'environnement du
 * mutualisé visé en production.
 */
function amap_send_email( $to, $subject, $html_body ) {
    if ( AMAP_DEMO_MODE ) {
        set_transient(
            'amap_demo_last_email',
            array(
                'to'      => $to,
                'subject' => $subject,
                'body'    => $html_body,
            ),
            5 * MINUTE_IN_SECONDS
        );

        return true;
    }

    if ( '' === AMAP_BREVO_API_KEY ) {
        return new WP_Error( 'amap_email_not_configured', __( 'Clé API Brevo non configurée.', 'association-manager' ) );
    }

    $response = wp_remote_post(
        'https://api.brevo.com/v3/smtp/email',
        array(
            'headers' => array(
                'accept'       => 'application/json',
                'api-key'      => AMAP_BREVO_API_KEY,
                'content-type' => 'application/json',
            ),
            'body'    => wp_json_encode(
                array(
                    'sender'      => array(
                        'name'  => AMAP_EMAIL_FROM_NAME,
                        'email' => AMAP_EMAIL_FROM_ADDRESS,
                    ),
                    'to'          => array( array( 'email' => $to ) ),
                    'subject'     => $subject,
                    'htmlContent' => $html_body,
                )
            ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    if ( $status_code < 200 || $status_code >= 300 ) {
        return new WP_Error(
            'amap_email_send_failed',
            sprintf( 'Brevo a répondu avec le code %d : %s', $status_code, wp_remote_retrieve_body( $response ) )
        );
    }

    return true;
}

/**
 * Contenu du dernier email intercepté en mode démo (voir amap_send_email()), utilisé par les
 * écrans front-end pour afficher le lien magique à la place d'un envoi réel.
 */
function amap_get_demo_last_email() {
    return AMAP_DEMO_MODE ? get_transient( 'amap_demo_last_email' ) : false;
}

add_action( 'admin_post_amap_send_test_email', 'amap_handle_send_test_email' );

function amap_handle_send_test_email() {
    if ( ! current_user_can( 'amap_manage_users' ) ) {
        wp_die( esc_html__( 'Action non autorisée.', 'association-manager' ) );
    }

    check_admin_referer( 'amap_send_test_email' );

    $admin   = wp_get_current_user();
    $subject = __( 'Email de test AMAP', 'association-manager' );
    $result  = amap_send_email(
        $admin->user_email,
        $subject,
        amap_render_email( $subject, '<p>' . esc_html__( "Cet email confirme que l'envoi via Brevo fonctionne.", 'association-manager' ) . '</p>' )
    );

    if ( is_wp_error( $result ) ) {
        set_transient( 'amap_test_email_error_' . get_current_user_id(), $result->get_error_message(), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=test_email_failed' ) );
        exit;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=amap-users&amap_notice=test_email_sent' ) );
    exit;
}
