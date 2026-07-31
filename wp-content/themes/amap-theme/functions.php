<?php
// Empêche l'accès direct au fichier en dehors du contexte WordPress.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function amap_theme_enqueue_assets() {
    wp_enqueue_style(
        'amap-theme-style',
        get_stylesheet_uri(),
        array(),
        wp_get_theme()->get( 'Version' )
    );
}
add_action( 'wp_enqueue_scripts', 'amap_theme_enqueue_assets' );

function amap_theme_setup() {
    register_nav_menus(
        array(
            'primary' => __( 'Menu principal', 'amap-theme' ),
        )
    );

    add_theme_support( 'post-thumbnails' );
}
add_action( 'after_setup_theme', 'amap_theme_setup' );
