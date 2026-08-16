<?php
/**
 * Symboles SVG (#amap-icon-*) réutilisés par tout l'espace membre — onglets (member-area.php) et
 * sous-pages en dehors de cette coquille (ex. member-area-leave.php, atteinte directement par
 * amap_maybe_render_member_area() sans passer par member-area.php) : extrait en template part
 * pour ne pas dupliquer ce bloc dans chaque fichier qui a besoin d'une icône.
 */
?>
<svg class="amap-icon-sprite" aria-hidden="true">
    <defs>
        <symbol id="amap-icon-pin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 21s7-7.2 7-12.1A7 7 0 1 0 5 8.9C5 13.8 12 21 12 21Z"></path>
            <circle cx="12" cy="8.9" r="2.4"></circle>
        </symbol>
        <symbol id="amap-icon-calendar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3.5" y="5" width="17" height="15" rx="2"></rect>
            <path d="M3.5 9.5h17"></path>
            <path d="M8 3v4M16 3v4"></path>
        </symbol>
        <symbol id="amap-icon-basket" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 9h16l-1.4 9.1a2 2 0 0 1-2 1.7H7.4a2 2 0 0 1-2-1.7L4 9Z"></path>
            <path d="M8 9 9 4h6l1 5"></path>
            <path d="M9.5 12.2v4.6M12 12.2v4.6M14.5 12.2v4.6"></path>
        </symbol>
        <symbol id="amap-icon-grid" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <rect x="4" y="4" width="7" height="7" rx="1.2"></rect>
            <rect x="13" y="4" width="7" height="7" rx="1.2"></rect>
            <rect x="4" y="13" width="7" height="7" rx="1.2"></rect>
            <rect x="13" y="13" width="7" height="7" rx="1.2"></rect>
        </symbol>
        <symbol id="amap-icon-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M7 9.5 12 14.5 17 9.5"></path>
        </symbol>
        <symbol id="amap-icon-arrow-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M11 6l-6 6 6 6"></path>
        </symbol>
        <symbol id="amap-icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 13l4 4L19 7"></path>
        </symbol>
        <symbol id="amap-icon-info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 11v5.5M12 8v.01"></path>
        </symbol>
        <symbol id="amap-icon-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="7"></circle>
            <path d="M21 21l-4.3-4.3"></path>
        </symbol>
    </defs>
</svg>
