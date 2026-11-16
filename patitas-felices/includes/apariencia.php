<?php
/**
 * Presentación igual al prototipo: cabecera, ficha de producto, carrito, checkout por pasos,
 * confirmación y Mi cuenta. La lógica de compra sigue siendo la de WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ plantillas propias */

// Las plantillas de /templates del plugin reemplazan a las de WooCommerce con el mismo nombre.
add_filter( 'woocommerce_locate_template', function ( $plantilla, $nombre ) {
	$propia = PATITAS_DIR . 'templates/' . $nombre;
	return file_exists( $propia ) ? $propia : $plantilla;
}, 10, 2 );

// Número de pedido como en el prototipo (#PF-10254).
add_filter( 'woocommerce_order_number', function ( $numero, $pedido ) {
	return 'PF-' . ( 10000 + (int) $pedido->get_id() );
}, 10, 2 );

/** Huellitas (puntos de fidelización): 1 punto por cada $100 comprados. */
function patitas_huellitas( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return 0;
	}
	$total = 0;
	foreach ( patitas_pedidos( array( 'customer_id' => $user_id ) ) as $p ) {
		$total += (float) $p->get_total();
	}
	return (int) floor( $total / 100 );
}

function patitas_icono( $nombre ) {
	$rutas = array(
		'usuario' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
		'carrito' => '<path d="M3 4h2l2.4 11h11L21 7H6.2"/><circle cx="9" cy="19.5" r="1.5"/><circle cx="17" cy="19.5" r="1.5"/>',
	);
	return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . $rutas[ $nombre ] . '</svg>';
}

/* ------------------------------------------------------------------ cabecera */

// Barra superior del prototipo (reemplaza el aviso inferior de WooCommerce).
add_action( 'wp_body_open', function () {
	echo '<div class="pf-barra-proto">Prototipo académico navegable · INACAP E-Commerce · Datos, marcas y precios ficticios</div>';
}, 1 );
add_filter( 'option_woocommerce_demo_store', function () {
	return 'no';
} );

function patitas_enlace_carrito() {
	$n = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	return sprintf( '<a class="pf-ic pf-carrito" href="%s">%s<span class="pf-ic-txt">Carrito</span><span class="pf-badge">%d</span></a>', esc_url( wc_get_cart_url() ), patitas_icono( 'carrito' ), (int) $n );
}

add_action( 'init', function () {
	remove_action( 'storefront_header', 'storefront_secondary_navigation', 30 );
	remove_action( 'storefront_header', 'storefront_header_cart', 60 );
	add_action( 'storefront_header', function () {
		$u     = wp_get_current_user();
		$texto = $u->exists() ? 'Hola, ' . ( $u->first_name ? $u->first_name : $u->display_name ) : 'Ingresar';
		printf( '<div class="pf-acciones"><a class="pf-ic" href="%s">%s<span class="pf-ic-txt">%s</span></a>%s</div>',
			esc_url( wc_get_page_permalink( 'myaccount' ) ), patitas_icono( 'usuario' ), esc_html( $texto ), patitas_enlace_carrito() ); // phpcs:ignore
	}, 40 );
}, 20 );

// El contador se actualiza al agregar productos sin recargar la página.
add_filter( 'woocommerce_add_to_cart_fragments', function ( $fragmentos ) {
	$fragmentos['a.pf-carrito'] = patitas_enlace_carrito();
	return $fragmentos;
} );

/* ------------------------------------------------------------------ ficha de producto */

add_action( 'init', function () {
	// Marca, estrellas con «Leer reseñas», precio por kilo e insignias de stock y despacho.
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
}, 20 );

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	$marca = $product->get_attribute( 'pa_marca' );
	if ( $marca ) {
		echo '<span class="pf-marca pf-marca-ficha">' . esc_html( $marca ) . '</span>';
	}
}, 4 );

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( $product->get_review_count() ) {
		printf( '<div class="pf-valoracion pf-valoracion-ficha">%s<span>%s (%d)</span><a href="#tab-reviews" class="pf-leer">Leer reseñas</a></div>', wc_get_rating_html( $product->get_average_rating() ), // phpcs:ignore
			esc_html( number_format( (float) $product->get_average_rating(), 1, ',', '.' ) ), (int) $product->get_review_count() );
	}
	$v      = patitas_variacion_defecto( $product );
	$base   = $v ? $v : $product;
	$kg     = patitas_kg( patitas_formato_defecto( $product ) );
	$precio = (float) $base->get_price();
	printf( '<p class="price pf-precio-ficha"><span class="pf-precio-actual">%s</span> <small class="pf-por-kg">%s</small></p>',
		wp_kses_post( $base->get_price_html() ),
		$kg ? esc_html( patitas_clp( round( $precio / $kg ) ) . ' por kg · IVA incluido' ) : 'IVA incluido' );
	$stock = $product->get_stock_quantity();
	echo '<div class="pf-insignias">';
	if ( $product->is_in_stock() ) {
		printf( '<span class="pf-insignia pf-insignia-ok">● En stock%s en tienda Copiapó</span>', $stock ? ': ' . (int) $stock . ' unidades' : '' );
		echo '<span class="pf-insignia pf-insignia-teal">Despacho hoy antes de las 14:00</span>';
	} else {
		echo '<span class="pf-insignia pf-insignia-rosa">Agotado: te avisamos cuando vuelva</span>';
	}
	echo '</div>';
}, 10 );

// Datos para que el JavaScript actualice precio, precio por kilo y suscripción al cambiar de formato.
add_action( 'woocommerce_before_add_to_cart_form', function () {
	global $product;
	$v = patitas_variacion_defecto( $product );
	printf( '<span id="pf-datos-producto" hidden data-precio="%s"></span>', esc_attr( (float) ( $v ? $v->get_price() : $product->get_price() ) ) );
} );

/* ------------------------------------------------------------------ carrito */

add_filter( 'gettext', function ( $traduccion, $texto, $dominio ) {
	if ( 'woocommerce' === $dominio && 'Cart totals' === $texto ) {
		return 'Resumen';
	}
	return $traduccion;
}, 10, 3 );

// Nombre del producto sin el formato (el formato va en la línea de detalle).
add_filter( 'woocommerce_cart_item_name', function ( $nombre, $item ) {
	if ( ! is_cart() || empty( $item['variation_id'] ) ) {
		return $nombre;
	}
	$padre = wc_get_product( $item['product_id'] );
	return $padre ? sprintf( '<a href="%s">%s</a>', esc_url( $padre->get_permalink() ), esc_html( $padre->get_name() ) ) : $nombre;
}, 10, 2 );

add_action( 'woocommerce_after_cart_item_name', function ( $item, $clave ) {
	$p       = $item['data'];
	$formato = $p->is_type( 'variation' ) ? ( $p->get_attributes()['formato'] ?? '' ) : $p->get_attribute( 'formato' );
	printf( '<span class="pf-linea-meta">%s%s c/u</span><span class="pf-disponible">%s</span><a class="pf-eliminar" href="%s">Eliminar</a>',
		$formato ? esc_html( $formato ) . ' · ' : '', wp_kses_post( wc_price( $p->get_price() ) ),
		$p->is_in_stock() ? 'Disponible para despacho hoy' : 'Sin stock', esc_url( wc_get_cart_remove_url( $clave ) ) );
}, 10, 2 );

// En el resumen: cupón, Huellitas y botón «Ir a pagar».
add_action( 'woocommerce_cart_totals_before_order_total', function () {
	?>
	<tr class="pf-fila-cupon"><td colspan="2">
		<form class="pf-cupon" method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>">
			<input type="text" name="coupon_code" placeholder="Código de descuento" aria-label="Código de descuento">
			<button type="submit" class="button" name="apply_coupon" value="1">Aplicar</button>
			<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
		</form>
	</td></tr>
	<tr class="pf-fila-huellitas"><th>Huellitas que ganarás</th><td>+<?php echo (int) floor( (float) WC()->cart->get_total( 'edit' ) / 100 ); ?></td></tr>
	<?php
} );
add_action( 'init', function () {
	remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
}, 20 );
add_action( 'woocommerce_proceed_to_checkout', function () {
	printf( '<a href="%s" class="checkout-button button alt wc-forward pf-ir-pagar">Ir a pagar</a><p class="pf-seguro">Pago seguro con Webpay Plus o Mercado Pago</p>', esc_url( wc_get_checkout_url() ) );
}, 20 );

/* ------------------------------------------------------------------ checkout por pasos */

add_action( 'wp_enqueue_scripts', function () {
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
		wp_enqueue_script( 'patitas-checkout', PATITAS_URL . 'assets/patitas-checkout.js', array( 'jquery', 'wc-checkout' ), PATITAS_VER, true );
	}
}, 100 );

add_filter( 'woocommerce_order_button_text', function ( $texto ) {
	return ( function_exists( 'WC' ) && WC()->cart ) ? 'Pagar ' . wp_strip_all_tags( WC()->cart->get_total() ) : $texto;
} );

add_action( 'woocommerce_checkout_after_order_review', function () {
	echo '<p class="pf-nota-segura">Conexión segura (HTTPS). Pago procesado por pasarelas certificadas PCI DSS.</p>';
} );

/* ------------------------------------------------------------------ Mi cuenta */

add_filter( 'woocommerce_account_menu_items', function ( $items ) {
	$nuevo = array();
	foreach ( $items as $clave => $texto ) {
		if ( 'downloads' === $clave ) {
			continue;
		}
		$nuevo[ $clave ] = array(
			'dashboard'       => 'Resumen',
			'orders'          => 'Mis pedidos',
			'edit-address'    => 'Direcciones',
			'edit-account'    => 'Mis datos',
			'customer-logout' => 'Cerrar sesión',
		)[ $clave ] ?? $texto;
		if ( 'suscripciones' === $clave ) {
			$nuevo['huellitas'] = sprintf( 'Huellitas: %s pts', number_format( patitas_huellitas(), 0, ',', '.' ) );
		}
	}
	return $nuevo;
}, 20 );

add_action( 'init', function () {
	add_rewrite_endpoint( 'huellitas', EP_PAGES );
} );
add_filter( 'woocommerce_get_query_vars', function ( $vars ) {
	$vars['huellitas'] = 'huellitas';
	return $vars;
} );
add_filter( 'woocommerce_endpoint_huellitas_title', function () {
	return 'Huellitas';
} );
add_action( 'woocommerce_account_huellitas_endpoint', function () {
	$pts = patitas_huellitas();
	printf( '<div class="pf-tarjeta pf-huellitas-total"><b>%s Huellitas</b><p>Ganas 1 Huellita por cada $100 de compra. Cada 1.000 Huellitas equivalen a $1.000 de descuento en tu próxima compra.</p></div>', esc_html( number_format( $pts, 0, ',', '.' ) ) );
	echo '<p>Las Huellitas vencen a los 12 meses. Úsalas en el checkout con el código que te enviamos por correo.</p>';
} );
