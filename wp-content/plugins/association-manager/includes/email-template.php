<?php
/**
 * Gabarit HTML commun aux emails transactionnels (en-tête, carte de contenu, pied de page).
 *
 * Les styles de mise en page (tableaux, en-tête, bouton, pied de page) sont en attributs
 * `style=""` : les clients mail (notamment Outlook) n'appliquent pas de façon fiable les
 * feuilles de style externes ou les balises <style> pour la mise en page. La balise <style> du
 * <head> reste utilisée pour le texte courant (titres, listes, liens) des emails, supportée par
 * la grande majorité des clients modernes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * En-tête de l'email : logo du site s'il est configuré (Apparence > Personnaliser > Identité du
 * site), sinon le nom du site en texte.
 */
function amap_get_email_header_html() {
    $site_name = get_bloginfo( 'name' );

    if ( has_custom_logo() ) {
        $logo_url = wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'medium' );

        if ( $logo_url ) {
            return sprintf(
                '<img src="%s" alt="%s" style="max-height:48px; max-width:220px;">',
                esc_url( $logo_url ),
                esc_attr( $site_name )
            );
        }
    }

    return sprintf(
        '<span style="font-size:20px; font-weight:700; color:#3f6b4a;">%s</span>',
        esc_html( $site_name )
    );
}

/**
 * Enveloppe le contenu d'un email (déjà construit et échappé par l'appelant) dans le gabarit
 * visuel commun. $cta_url/$cta_label sont optionnels : quand fournis, un bouton stylé est ajouté
 * sous le corps du message (utilisé pour les liens de connexion/réinitialisation).
 */
function amap_render_email( $subject, $body_html, $cta_url = '', $cta_label = '' ) {
    $site_name = get_bloginfo( 'name' );

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<!-- Ignoré par les clients mail ; permet aux liens de fonctionner normalement quand ce document
     est affiché dans l'iframe d'aperçu du mode démo (voir step-message.php côté thème). -->
<base target="_top">
<title><?php echo esc_html( $subject ); ?></title>
<style>
    h3 { margin: 24px 0 8px; font-size: 16px; color: #2c4d35; }
    p { margin: 0 0 12px; }
    ul { margin: 0 0 12px; padding-left: 20px; }
    li { margin-bottom: 4px; }
    a { color: #3f6b4a; }
</style>
</head>
<body style="margin:0; padding:0; background-color:#faf8f4; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#faf8f4;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border:1px solid #e2ddd0; border-radius:6px;">
<tr>
<td style="padding:24px 32px; text-align:center; border-bottom:1px solid #e2ddd0;">
<?php echo amap_get_email_header_html(); ?>
</td>
</tr>
<tr>
<td style="padding:32px; color:#2b2a26; font-size:15px; line-height:1.6;">
<?php echo $body_html; ?>
<?php if ( $cta_url && $cta_label ) : ?>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:20px;">
<tr>
<td style="border-radius:6px; background-color:#3f6b4a;">
<a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block; padding:12px 28px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none;">
<?php echo esc_html( $cta_label ); ?>
</a>
</td>
</tr>
</table>
<?php endif; ?>
</td>
</tr>
<tr>
<td style="padding:16px 32px; text-align:center; font-size:12px; color:#6b675c; border-top:1px solid #e2ddd0;">
<?php echo esc_html( $site_name ); ?>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
    <?php
    return ob_get_clean();
}
