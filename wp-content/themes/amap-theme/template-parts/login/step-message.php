<?php
/**
 * Écran "message" du parcours de connexion (espace-adherent) : accusé de réception sans
 * formulaire (lien magique envoyé, réinitialisation envoyée, mot de passe mis à jour).
 * $args['message'], $args['show_login_link'] et $args['demo_email'] : cf. remarque dans
 * step-email.php sur get_template_part() qui ne déplie pas $args en variables individuelles.
 */
?>

<h1><?php esc_html_e( 'Connexion', 'association-manager' ); ?></h1>

<div class="amap-notice amap-notice--success"><?php echo esc_html( $args['message'] ); ?></div>

<?php if ( $args['show_login_link'] ) : ?>
    <p><a class="button-primary" href="<?php echo esc_url( amap_get_member_area_url() ); ?>"><?php esc_html_e( 'Se connecter', 'association-manager' ); ?></a></p>
<?php endif; ?>

<?php if ( $args['demo_email'] ) : ?>
    <div class="amap-notice amap-notice--info">
        <p><strong><?php esc_html_e( "Mode démo : l'email ci-dessous n'a pas été réellement envoyé.", 'association-manager' ); ?></strong></p>
        <p><?php echo esc_html( $args['demo_email']['subject'] ); ?></p>
        <?php
        /**
         * Le corps est un document HTML complet (amap_render_email()), avec son propre <style> :
         * on l'affiche dans un iframe pour éviter que ces styles ne s'appliquent à la page de
         * connexion elle-même. srcdoc attend le HTML échappé comme attribut, d'où esc_attr()
         * plutôt que esc_html() utilisé ailleurs pour du texte.
         */
        ?>
        <iframe
            title="<?php esc_attr_e( "Aperçu de l'email", 'association-manager' ); ?>"
            srcdoc="<?php echo esc_attr( $args['demo_email']['body'] ); ?>"
            style="width:100%; max-width:480px; height:420px; border:1px solid var(--color-border); border-radius:var(--radius); background:#fff;"
        ></iframe>
        <?php
        /**
         * Le bouton à l'intérieur de l'iframe ci-dessus ne fonctionne pas sur WordPress Playground :
         * cette iframe hérite du bac à sable (sandbox) de l'iframe Playground qui l'englobe, qui
         * interdit à tout ce qu'elle contient de faire naviguer un ancêtre — quel que soit le
         * target visé (_top comme _parent). Un lien direct dans la page (hors de toute iframe)
         * reste donc le seul moyen fiable de tester le clic en mode démo.
         */
        $demo_cta_url = null;
        if ( preg_match( '/<a href="([^"]+)"/', $args['demo_email']['body'], $demo_cta_match ) ) {
            $demo_cta_url = html_entity_decode( $demo_cta_match[1] );
        }
        ?>
        <?php if ( $demo_cta_url ) : ?>
            <p>
                <a class="button-primary" href="<?php echo esc_url( $demo_cta_url ); ?>">
                    <?php esc_html_e( "Suivre le lien (le bouton dans l'aperçu ci-dessus ne fonctionne pas sur Playground)", 'association-manager' ); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>
