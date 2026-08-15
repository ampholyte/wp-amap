<?php
/**
 * Formulaire de souscription à un contrat (étape 7.3), atteint depuis le bouton "Souscrire" de
 * l'onglet "Espace adhérent" (?amap_member_action=subscribe&contract_id=X). Les données sont
 * préparées et validées par amap_get_member_subscribe_form_data() ; la soumission est traitée
 * par amap_handle_add_member_subscription(). Le groupe (point de retrait) n'est plus un choix du
 * formulaire depuis l'ajout du rattachement adhérent↔groupe : il est fixé par le bureau et
 * simplement affiché ici, jamais posté (amap_handle_add_member_subscription() le redérive).
 *
 * Sous-page en dehors de la coquille à onglets (atteinte directement par
 * amap_maybe_render_member_area(), pas via member-area.php) : elle inclut donc elle-même les
 * symboles SVG (#amap-icon-*).
 */
?>

<?php get_template_part( 'template-parts/login/member-area-icon-sprite' ); ?>

<div class="amap-page-head">
    <a class="amap-back-link" href="<?php echo esc_url( amap_get_member_area_tab_url( 'member' ) ); ?>">
        <svg class="icon" aria-hidden="true"><use href="#amap-icon-arrow-left"></use></svg>
        <?php esc_html_e( 'Retour à mes contrats', 'association-manager' ); ?>
    </a>
    <h1 class="amap-page-title"><?php esc_html_e( 'Souscrire à un contrat', 'association-manager' ); ?></h1>
</div>

<?php if ( ! empty( $args['error'] ) ) : ?>
    <div class="amap-notice amap-notice--error">
        <?php if ( 'no_basket_sizes' === $args['error'] ) : ?>
            <?php esc_html_e( "Aucune taille de panier n'est configurée pour ce contrat. Contactez le bureau.", 'association-manager' ); ?>
        <?php elseif ( 'no_products' === $args['error'] ) : ?>
            <?php esc_html_e( "Aucun produit n'est configuré pour ce contrat. Contactez le bureau.", 'association-manager' ); ?>
        <?php elseif ( 'no_delivery_dates' === $args['error'] ) : ?>
            <?php esc_html_e( "Aucune date de livraison n'est configurée pour votre groupe sur ce contrat. Contactez le bureau.", 'association-manager' ); ?>
        <?php endif; ?>
    </div>
    <p>
        <a class="button-secondary" href="<?php echo esc_url( amap_get_member_area_tab_url( 'member' ) ); ?>"><?php esc_html_e( 'Retour à mes contrats', 'association-manager' ); ?></a>
    </p>
    <?php return; ?>
<?php endif; ?>

<?php
$contract        = $args['contract'];
$producer        = $args['producer'];
$group           = $args['group'];
$contract_types  = amap_get_contract_types();
$is_product_grid = ( 'product_grid' === $contract->contract_type );
$type_icon       = $is_product_grid ? 'amap-icon-grid' : 'amap-icon-basket';
$type_class      = $is_product_grid ? 'amap-type-icon--grid' : 'amap-type-icon--basket';
?>

<section class="amap-info-card">
    <div class="amap-info-card__head">
        <span class="amap-contract-card__type <?php echo esc_attr( $type_class ); ?>">
            <svg class="icon" aria-hidden="true"><use href="#<?php echo esc_attr( $type_icon ); ?>"></use></svg>
        </span>
        <div>
            <h2 class="amap-info-card__title"><?php echo esc_html( $contract->label ); ?></h2>
            <p class="amap-info-card__sub">
                <?php echo esc_html( $producer ? $producer->display_name : '—' ); ?>
                &middot; <?php echo esc_html( $contract_types[ $contract->contract_type ] ?? '—' ); ?>
            </p>
        </div>
    </div>
    <dl class="amap-info-list">
        <div>
            <dt><?php esc_html_e( 'Période', 'association-manager' ); ?></dt>
            <dd>
                <?php
                printf(
                    /* translators: 1: date de début du contrat. 2: date de fin du contrat. */
                    esc_html__( '%1$s – %2$s', 'association-manager' ),
                    esc_html( date_i18n( 'j F Y', strtotime( $contract->start_date ) ) ),
                    esc_html( date_i18n( 'j F Y', strtotime( $contract->end_date ) ) )
                );
                ?>
            </dd>
        </div>
        <div>
            <dt><?php esc_html_e( 'Point de retrait', 'association-manager' ); ?></dt>
            <dd><?php echo esc_html( $group->name ); ?></dd>
        </div>
    </dl>
</section>

<form class="amap-order-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-subscribe-form">
    <?php wp_nonce_field( 'amap_subscribe_contract_' . $contract->id ); ?>
    <input type="hidden" name="action" value="amap_add_member_subscription">
    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $contract->id ); ?>">

    <?php if ( $is_product_grid ) : ?>
        <div class="amap-order-form__head">
            <h2 class="amap-leave-form__title"><?php esc_html_e( 'Vos quantités', 'association-manager' ); ?></h2>
            <p class="amap-form-help-plain">
                <?php
                printf(
                    /* translators: 1: nombre de dates de livraison. 2: nombre de produits. */
                    esc_html__( '%1$d dates de livraison · %2$d produits. Une quantité par produit et par date ; les totaux se mettent à jour au fil de la saisie.', 'association-manager' ),
                    count( $args['delivery_dates'] ),
                    count( $args['products'] )
                );
                ?>
            </p>
        </div>
        <div id="amap-subscribe-items-wrapper"></div>
    <?php else : ?>
        <h2 class="amap-leave-form__title"><?php esc_html_e( 'Choisissez une taille', 'association-manager' ); ?></h2>
        <div class="amap-date-options" role="radiogroup" aria-label="<?php esc_attr_e( 'Taille de panier', 'association-manager' ); ?>">
            <?php foreach ( $args['basket_sizes'] as $size_option ) : ?>
                <label class="amap-date-option">
                    <input class="sr-only" type="radio" name="basket_size_id" value="<?php echo esc_attr( $size_option['id'] ); ?>" required>
                    <span class="amap-date-option__box">
                        <span class="amap-date-option__day"><?php echo esc_html( $size_option['label'] ); ?></span>
                        <span class="amap-date-option__date"><?php echo esc_html( number_format_i18n( $size_option['price'], 2 ) ); ?> €</span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="amap-form-actions">
        <button type="submit" class="button-primary"><?php esc_html_e( 'Confirmer ma souscription', 'association-manager' ); ?></button>
        <a class="button-secondary" href="<?php echo esc_url( amap_get_member_area_tab_url( 'member' ) ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
    </div>
</form>

<?php if ( $is_product_grid ) : ?>
<script>
( function () {
    "use strict";

    var products        = <?php echo wp_json_encode( $args['products'] ); ?>;
    var dates            = <?php echo wp_json_encode( $args['delivery_dates'] ); ?>;
    var discountGroups   = <?php echo wp_json_encode( $args['discount_groups'] ); ?>;
    var wrapper          = document.getElementById( 'amap-subscribe-items-wrapper' );

    var i18n = {
        duplicate: <?php echo wp_json_encode( __( 'Dupliquer la 1ère date sur toutes les autres', 'association-manager' ) ); ?>,
        scrollHint: <?php echo wp_json_encode( __( '↔ Faites glisser pour voir tous les produits', 'association-manager' ) ); ?>,
        total: <?php echo wp_json_encode( __( 'Total', 'association-manager' ) ); ?>,
        totalSeason: <?php echo wp_json_encode( __( 'Total saison', 'association-manager' ) ); ?>,
        productSingular: <?php echo wp_json_encode( __( 'produit', 'association-manager' ) ); ?>,
        productPlural: <?php echo wp_json_encode( __( 'produits', 'association-manager' ) ); ?>,
        decrease: <?php echo wp_json_encode( __( 'Diminuer', 'association-manager' ) ); ?>,
        increase: <?php echo wp_json_encode( __( 'Augmenter', 'association-manager' ) ); ?>
    };

    // quantities[dateId][productId] = quantité saisie (entier ≥ 0).
    var quantities = {};
    dates.forEach( function ( d ) { quantities[ d.id ] = {}; } );

    function formatEuros( amount ) {
        return amount.toLocaleString( 'fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ' €';
    }

    function getQuantity( dateId, productId ) {
        return ( quantities[ dateId ] && quantities[ dateId ][ productId ] ) || 0;
    }

    function computeRowTotal( dateId ) {
        var total = 0;
        products.forEach( function ( p ) {
            total += getQuantity( dateId, p.id ) * p.price;
        } );
        return total;
    }

    /**
     * Même calcul que amap_get_subscription_price_summary() côté serveur : quantité agrégée par
     * produit sur toute la saison, remise par palier appliquée sur cet agrégat (voir le
     * commentaire de amap_get_member_subscribe_form_data() — bug de remise par saison plutôt que
     * par semaine déjà connu, volontairement pas corrigé ici pour annoncer le même montant que ce
     * qui sera réellement facturé).
     */
    function computeSeasonSummary() {
        var qtyByProduct = {};
        dates.forEach( function ( d ) {
            products.forEach( function ( p ) {
                var q = getQuantity( d.id, p.id );
                if ( q > 0 ) {
                    qtyByProduct[ p.id ] = ( qtyByProduct[ p.id ] || 0 ) + q;
                }
            } );
        } );

        var qtyByGroup = {};
        var total      = 0;

        products.forEach( function ( p ) {
            var q = qtyByProduct[ p.id ] || 0;
            if ( q <= 0 ) {
                return;
            }
            if ( p.discount_group_id ) {
                qtyByGroup[ p.discount_group_id ] = ( qtyByGroup[ p.discount_group_id ] || 0 ) + q;
                return;
            }
            total += q * p.price;
        } );

        discountGroups.forEach( function ( g ) {
            var q = qtyByGroup[ g.id ] || 0;
            if ( q <= 0 ) {
                return;
            }
            var fullBatches = Math.floor( q / g.bought_quantity );
            var billed      = fullBatches * g.billed_quantity + ( q % g.bought_quantity );
            total += billed * g.price;
        } );

        return total;
    }

    function productTotalAcrossSeason( product ) {
        var qty = 0;
        dates.forEach( function ( d ) { qty += getQuantity( d.id, product.id ); } );
        return qty * product.price;
    }

    function refreshTotals() {
        dates.forEach( function ( d ) {
            var rowTotalEl = wrapper.querySelector( '[data-row-total="' + d.id + '"]' );
            if ( rowTotalEl ) {
                rowTotalEl.textContent = formatEuros( computeRowTotal( d.id ) );
            }
            var summaryEl = wrapper.querySelector( '[data-row-summary="' + d.id + '"]' );
            if ( summaryEl ) {
                var count = products.reduce( function ( n, p ) { return n + ( getQuantity( d.id, p.id ) > 0 ? 1 : 0 ); }, 0 );
                summaryEl.textContent = count + ' ' + ( count > 1 ? i18n.productPlural : i18n.productSingular ) + ' · ' + formatEuros( computeRowTotal( d.id ) );
            }
        } );

        products.forEach( function ( p ) {
            var productTotalEl = wrapper.querySelector( '[data-product-total="' + p.id + '"]' );
            if ( productTotalEl ) {
                productTotalEl.textContent = formatEuros( productTotalAcrossSeason( p ) ) + ( p.discount_group_id ? '*' : '' );
            }
        } );

        var seasonTotal = computeSeasonSummary();
        wrapper.querySelectorAll( '[data-season-total]' ).forEach( function ( el ) {
            el.textContent = formatEuros( seasonTotal );
        } );
    }

    wrapper.addEventListener( 'input', function ( event ) {
        var input = event.target;
        if ( ! input.classList.contains( 'amap-item-quantity' ) ) {
            return;
        }
        var value = Math.max( 0, parseInt( input.value, 10 ) || 0 );
        quantities[ input.dataset.dateId ][ input.dataset.productId ] = value;
        refreshTotals();
    } );

    function buildDuplicateButton() {
        var button = document.createElement( 'button' );
        button.type      = 'button';
        button.className = 'button-secondary';
        button.textContent = i18n.duplicate;
        button.addEventListener( 'click', function () {
            var firstDate = dates[ 0 ];
            products.forEach( function ( p ) {
                var value = getQuantity( firstDate.id, p.id );
                dates.forEach( function ( d, index ) {
                    if ( 0 === index ) {
                        return;
                    }
                    quantities[ d.id ][ p.id ] = value;
                    wrapper.querySelectorAll( '.amap-item-quantity[data-date-id="' + d.id + '"][data-product-id="' + p.id + '"]' ).forEach( function ( input ) {
                        input.value = value || '';
                    } );
                } );
            } );
            refreshTotals();
        } );
        return button;
    }

    function buildDiscountNote() {
        if ( ! discountGroups.length ) {
            return null;
        }
        var note = document.createElement( 'p' );
        note.className = 'amap-form-help-plain';
        note.textContent = discountGroups.map( function ( g ) { return g.note; } ).join( ' ' );
        return note;
    }

    function buildDesktopTable() {
        var container = document.createElement( 'div' );

        var hint = document.createElement( 'p' );
        hint.className   = 'amap-scroll-hint';
        hint.textContent = i18n.scrollHint;
        container.appendChild( hint );

        var gridWrap = document.createElement( 'div' );
        gridWrap.className = 'amap-items-grid-wrapper';

        var table = document.createElement( 'table' );
        table.className = 'amap-items-grid';

        var thead   = document.createElement( 'thead' );
        var headRow = document.createElement( 'tr' );
        headRow.appendChild( document.createElement( 'th' ) );
        products.forEach( function ( p ) {
            var th = document.createElement( 'th' );
            th.title = p.label;
            var nameSpan = document.createElement( 'span' );
            nameSpan.textContent = p.label;
            var priceSpan = document.createElement( 'span' );
            priceSpan.className = 'amap-items-grid__price';
            priceSpan.textContent = formatEuros( p.price );
            th.appendChild( nameSpan );
            th.appendChild( priceSpan );
            headRow.appendChild( th );
        } );
        var totalTh = document.createElement( 'th' );
        totalTh.className   = 'amap-items-grid__total-col';
        totalTh.textContent = i18n.total;
        headRow.appendChild( totalTh );
        thead.appendChild( headRow );
        table.appendChild( thead );

        var tbody = document.createElement( 'tbody' );
        dates.forEach( function ( d ) {
            var row       = document.createElement( 'tr' );
            var rowHeader = document.createElement( 'th' );
            rowHeader.scope     = 'row';
            rowHeader.textContent = d.short_label;
            row.appendChild( rowHeader );

            products.forEach( function ( p ) {
                var cell  = document.createElement( 'td' );
                var input = document.createElement( 'input' );
                input.type    = 'number';
                input.min     = '0';
                input.step    = '1';
                input.className = 'amap-item-quantity';
                input.name    = 'quantity[' + d.id + '][' + p.id + ']';
                input.dataset.dateId    = d.id;
                input.dataset.productId = p.id;
                input.setAttribute( 'aria-label', p.label + ', ' + d.label );
                cell.appendChild( input );
                row.appendChild( cell );
            } );

            var totalCell = document.createElement( 'td' );
            totalCell.className = 'amap-items-grid__total-col';
            totalCell.dataset.rowTotal = d.id;
            totalCell.textContent = formatEuros( 0 );
            row.appendChild( totalCell );

            tbody.appendChild( row );
        } );
        table.appendChild( tbody );

        var tfoot   = document.createElement( 'tfoot' );
        var footRow = document.createElement( 'tr' );
        var footHeader = document.createElement( 'th' );
        footHeader.scope = 'row';
        footHeader.textContent = i18n.totalSeason;
        footRow.appendChild( footHeader );
        products.forEach( function ( p ) {
            var td = document.createElement( 'td' );
            td.dataset.productTotal = p.id;
            td.textContent = formatEuros( 0 );
            footRow.appendChild( td );
        } );
        var footTotal = document.createElement( 'td' );
        footTotal.className = 'amap-items-grid__total-col';
        footTotal.dataset.seasonTotal = '';
        footTotal.textContent = formatEuros( 0 );
        footRow.appendChild( footTotal );
        tfoot.appendChild( footRow );
        table.appendChild( tfoot );

        gridWrap.appendChild( table );
        container.appendChild( gridWrap );

        var note = buildDiscountNote();
        if ( note ) {
            container.appendChild( note );
        }

        return container;
    }

    function buildMobileAccordion() {
        var container = document.createElement( 'div' );
        container.className = 'amap-date-acc-list';

        dates.forEach( function ( d, index ) {
            var details = document.createElement( 'details' );
            details.className = 'amap-date-acc';
            if ( 0 === index ) {
                details.open = true;
            }

            var summary = document.createElement( 'summary' );
            var dateSpan = document.createElement( 'span' );
            dateSpan.className = 'amap-date-acc__date';
            dateSpan.textContent = d.short_label;
            var summarySpan = document.createElement( 'span' );
            summarySpan.className = 'amap-date-acc__summary';
            summarySpan.dataset.rowSummary = d.id;
            summarySpan.textContent = '0 ' + i18n.productPlural + ' · ' + formatEuros( 0 );
            summary.appendChild( dateSpan );
            summary.appendChild( summarySpan );
            details.appendChild( summary );

            var list = document.createElement( 'ul' );
            list.className = 'amap-stepper-list';

            products.forEach( function ( p ) {
                var li = document.createElement( 'li' );
                li.className = 'amap-stepper-row';

                var label = document.createElement( 'span' );
                label.className = 'amap-stepper-row__label';
                label.textContent = p.label;
                var small = document.createElement( 'small' );
                small.textContent = formatEuros( p.price );
                label.appendChild( document.createElement( 'br' ) );
                label.appendChild( small );

                var stepper = document.createElement( 'span' );
                stepper.className = 'amap-stepper';

                var minusButton = document.createElement( 'button' );
                minusButton.type = 'button';
                minusButton.className = 'amap-stepper__btn';
                minusButton.textContent = '−';
                minusButton.setAttribute( 'aria-label', i18n.decrease );

                var input = document.createElement( 'input' );
                input.type  = 'number';
                input.min   = '0';
                input.value = '0';
                input.className = 'amap-item-quantity amap-stepper__value';
                input.name  = 'quantity[' + d.id + '][' + p.id + ']';
                input.dataset.dateId    = d.id;
                input.dataset.productId = p.id;
                input.setAttribute( 'aria-label', p.label );

                var plusButton = document.createElement( 'button' );
                plusButton.type = 'button';
                plusButton.className = 'amap-stepper__btn';
                plusButton.textContent = '+';
                plusButton.setAttribute( 'aria-label', i18n.increase );

                minusButton.addEventListener( 'click', function () {
                    input.value = Math.max( 0, ( parseInt( input.value, 10 ) || 0 ) - 1 );
                    input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
                } );
                plusButton.addEventListener( 'click', function () {
                    input.value = ( parseInt( input.value, 10 ) || 0 ) + 1;
                    input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
                } );

                stepper.appendChild( minusButton );
                stepper.appendChild( input );
                stepper.appendChild( plusButton );
                li.appendChild( label );
                li.appendChild( stepper );
                list.appendChild( li );
            } );

            details.appendChild( list );
            container.appendChild( details );
        } );

        var note = buildDiscountNote();
        if ( note ) {
            container.appendChild( note );
        }

        var seasonRow = document.createElement( 'div' );
        seasonRow.className = 'amap-order-total';
        var seasonLabel = document.createElement( 'span' );
        seasonLabel.textContent = i18n.totalSeason;
        var seasonValue = document.createElement( 'strong' );
        seasonValue.dataset.seasonTotal = '';
        seasonValue.textContent = formatEuros( 0 );
        seasonRow.appendChild( seasonLabel );
        seasonRow.appendChild( seasonValue );
        container.appendChild( seasonRow );

        return container;
    }

    function build() {
        wrapper.innerHTML = '';
        if ( dates.length > 1 ) {
            wrapper.appendChild( buildDuplicateButton() );
        }
        var isNarrowScreen = window.matchMedia( '(max-width: 700px)' ).matches;
        wrapper.appendChild( isNarrowScreen ? buildMobileAccordion() : buildDesktopTable() );
        refreshTotals();
    }

    build();
} )();
</script>
<?php endif; ?>
