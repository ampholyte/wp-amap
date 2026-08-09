<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header>
    <?php if ( has_custom_logo() ) : ?>
        <?php the_custom_logo(); ?>
    <?php else : ?>
        <h1><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></h1>
    <?php endif; ?>

    <nav>
        <?php
        wp_nav_menu(
            array(
                'theme_location' => 'primary',
            )
        );
        ?>
    </nav>
</header>
