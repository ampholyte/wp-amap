<?php
/**
 * Formulaire de souscription à un contrat (étape 7.3), atteint depuis le bouton "Souscrire" de
 * l'onglet "Espace adhérent" (?amap_member_action=subscribe&contract_id=X). Les données sont
 * préparées et validées par amap_get_member_subscribe_form_data() ; la soumission est traitée
 * par amap_handle_add_member_subscription(). Le groupe (point de retrait) n'est plus un choix du
 * formulaire depuis l'ajout du rattachement adhérent↔groupe : il est fixé par le bureau et
 * simplement affiché ici, jamais posté (amap_handle_add_member_subscription() le redérive).
 */
$contract        = $args['contract'];
$producer        = $args['producer'];
$group           = $args['group'];
$contract_types  = amap_get_contract_types();
$is_product_grid = ( 'product_grid' === $contract->contract_type );
?>

<h1><?php esc_html_e( 'Souscrire à un contrat', 'association-manager' ); ?></h1>

<div class="amap-card">
    <h2><?php echo esc_html( $contract->label ); ?></h2>
    <ul>
        <li><?php esc_html_e( 'Producteur', 'association-manager' ); ?> : <?php echo esc_html( $producer ? $producer->display_name : '—' ); ?></li>
        <li><?php esc_html_e( 'Type', 'association-manager' ); ?> : <?php echo esc_html( $contract_types[ $contract->contract_type ] ?? '—' ); ?></li>
        <li><?php esc_html_e( 'Période', 'association-manager' ); ?> : <?php echo esc_html( $contract->start_date . ' – ' . $contract->end_date ); ?></li>
        <li><?php esc_html_e( 'Groupe (point de retrait)', 'association-manager' ); ?> : <?php echo esc_html( $group->name ); ?></li>
    </ul>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amap-subscribe-form">
    <?php wp_nonce_field( 'amap_subscribe_contract_' . $contract->id ); ?>
    <input type="hidden" name="action" value="amap_add_member_subscription">
    <input type="hidden" name="contract_id" value="<?php echo esc_attr( $contract->id ); ?>">

    <?php if ( $is_product_grid ) : ?>
        <div id="amap-subscribe-items-wrapper">
            <h3><?php esc_html_e( 'Produits commandés', 'association-manager' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Une quantité par produit et par date de livraison.', 'association-manager' ); ?></p>
            <div id="amap-subscribe-items-grid" class="amap-items-grid-wrapper"></div>
        </div>
    <?php else : ?>
        <p>
            <label for="amap-subscribe-basket-size"><?php esc_html_e( 'Taille de panier', 'association-manager' ); ?></label>
            <select id="amap-subscribe-basket-size" name="basket_size_id" required>
                <?php foreach ( $args['basket_sizes'] as $size_option ) : ?>
                    <option value="<?php echo esc_attr( $size_option['id'] ); ?>"><?php echo esc_html( $size_option['label'] ); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
    <?php endif; ?>

    <p>
        <button type="submit"><?php esc_html_e( 'Confirmer ma souscription', 'association-manager' ); ?></button>
        <a class="button-secondary" href="<?php echo esc_url( amap_get_member_area_tab_url( 'member' ) ); ?>"><?php esc_html_e( 'Annuler', 'association-manager' ); ?></a>
    </p>
</form>

<?php if ( $is_product_grid ) : ?>
<script>
( function () {
    var products       = <?php echo wp_json_encode( $args['products'] ); ?>;
    var dates          = <?php echo wp_json_encode( $args['delivery_dates'] ); ?>;
    var itemsGrid      = document.getElementById( 'amap-subscribe-items-grid' );
    var duplicateLabel = <?php echo wp_json_encode( __( 'Dupliquer la 1ère date sur toutes les autres', 'association-manager' ) ); ?>;

    function buildGrid() {
        itemsGrid.innerHTML = '';

        if ( dates.length > 1 ) {
            var duplicateButton = document.createElement( 'button' );
            duplicateButton.type        = 'button';
            duplicateButton.className   = 'button-secondary';
            duplicateButton.textContent = duplicateLabel;
            duplicateButton.addEventListener( 'click', function () {
                var firstRowValues = {};
                itemsGrid.querySelectorAll( '[data-date-index="0"]' ).forEach( function ( input ) {
                    firstRowValues[ input.dataset.productId ] = input.value;
                } );
                itemsGrid.querySelectorAll( '.amap-item-quantity' ).forEach( function ( input ) {
                    if ( '0' !== input.dataset.dateIndex ) {
                        input.value = firstRowValues[ input.dataset.productId ] || '';
                    }
                } );
            } );
            itemsGrid.appendChild( duplicateButton );
        }

        var table = document.createElement( 'table' );
        table.className = 'amap-items-grid';

        var thead   = document.createElement( 'thead' );
        var headRow = document.createElement( 'tr' );
        headRow.appendChild( document.createElement( 'th' ) );
        products.forEach( function ( product ) {
            var th = document.createElement( 'th' );
            th.textContent = product.label;
            headRow.appendChild( th );
        } );
        thead.appendChild( headRow );
        table.appendChild( thead );

        var tbody = document.createElement( 'tbody' );
        dates.forEach( function ( dateOption, dateIndex ) {
            var row       = document.createElement( 'tr' );
            var rowHeader = document.createElement( 'th' );
            rowHeader.textContent = dateOption.label;
            row.appendChild( rowHeader );

            products.forEach( function ( product ) {
                var cell  = document.createElement( 'td' );
                var input = document.createElement( 'input' );
                input.type              = 'number';
                input.min               = '0';
                input.step              = '1';
                input.className         = 'amap-item-quantity';
                input.name              = 'quantity[' + dateOption.id + '][' + product.id + ']';
                input.dataset.dateIndex = dateIndex;
                input.dataset.productId = product.id;
                cell.appendChild( input );
                row.appendChild( cell );
            } );

            tbody.appendChild( row );
        } );
        table.appendChild( tbody );
        itemsGrid.appendChild( table );
    }

    buildGrid();
} )();
</script>
<?php endif; ?>
