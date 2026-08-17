<?php get_header(); ?>

<main>
    <?php if ( AMAP_DEMO_MODE ) : ?>
        <!-- Affiché uniquement sur l'instance de démo (Playground), au-dessus du vrai contenu de
             la page d'accueil ci-dessous : sert de guide de test, pas de contenu éditorial. -->
        <section class="amap-card">
            <h2>Bienvenue sur la démo de l'AMAP</h2>
            <p>Cette instance est un environnement de test : les données sont fictives et peuvent être réinitialisées à tout moment. Vous pouvez cliquer et essayer librement, rien ici n'affecte une vraie association.</p>

            <div class="amap-demo-grid">
                <div class="amap-demo-tile">
                    <h3>Adhérent</h3>
                    <p>Contrats souscrits, prochaines distributions, déclaration de congé, téléchargement d'un contrat en PDF.</p>
                </div>
                <div class="amap-demo-tile">
                    <h3>Producteur</h3>
                    <p>Planning de distribution et grille de produits ou de paniers proposés aux adhérents.</p>
                </div>
                <div class="amap-demo-tile">
                    <h3>Bureau</h3>
                    <p>Gestion des adhérents, des groupes, des producteurs, des contrats et suivi des paiements.</p>
                </div>
            </div>

            <div class="amap-notice amap-notice--info">
                <p class="amap-notice__title">Quelques parcours à tester</p>
                <ul class="amap-notice__list">
                    <li>Se connecter en tant qu'adhérent (lien de connexion, sans mot de passe) et consulter « Mes contrats ».</li>
                    <li>Déclarer un congé sur une prochaine distribution.</li>
                    <li>Télécharger le PDF d'un contrat signé.</li>
                    <li>Se connecter en tant que producteur et consulter le planning de distribution.</li>
                    <li>Se connecter en tant que membre du bureau, modifier le texte ci-dessous depuis wp-admin, et parcourir la liste des adhérents, des groupes et des paiements.</li>
                </ul>
            </div>

            <a class="button-primary" href="<?php echo esc_url( amap_get_member_area_url() ); ?>">Accéder à l'espace membre</a>
        </section>
    <?php endif; ?>

    <?php while ( have_posts() ) : the_post(); ?>
        <article>
            <h1><?php the_title(); ?></h1>
            <?php if ( has_post_thumbnail() ) : ?>
                <?php the_post_thumbnail( 'large' ); ?>
            <?php endif; ?>
            <div><?php the_content(); ?></div>
        </article>
    <?php endwhile; ?>
</main>

<?php get_footer(); ?>
