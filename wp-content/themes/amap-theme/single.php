<?php get_header(); ?>

<main>
    <?php while ( have_posts() ) : the_post(); ?>
        <article>
            <h1><?php the_title(); ?></h1>
            <p class="amap-post-meta"><?php echo esc_html( get_the_date() ); ?></p>
            <?php if ( has_post_thumbnail() ) : ?>
                <?php the_post_thumbnail( 'large' ); ?>
            <?php endif; ?>
            <div><?php the_content(); ?></div>
        </article>
    <?php endwhile; ?>
</main>

<?php get_footer(); ?>
