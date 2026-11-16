<?php
/**
 * Catálogo con la presentación del prototipo: panel de filtros por facetas (sobre los filtros nativos de
 * WooCommerce: filter_especie, product_cat, filter_etapa...), título dinámico, filtros activos, orden
 * «Recomendados para ti» y tarjetas de producto con marca, reseñas, formato y etiquetas.
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ facetas */

/** Definición de facetas: clave => [título, parámetro de URL, opciones (nombre visible)]. */
function patitas_facetas() {
	static $f = null;
	if ( null === $f ) {
		$marcas = array_unique( array_filter( wp_list_pluck( patitas_catalogo(), 'marca' ) ) );
		sort( $marcas );
		$f = array(
			'especie'   => array( 'Especie', 'filter_especie', array( 'Perros', 'Gatos' ) ),
			'categoria' => array( 'Categoría', 'product_cat', array( 'Alimento seco', 'Alimento húmedo', 'Arena', 'Snacks', 'Accesorios', 'Farmacia', 'Higiene', 'Kits' ) ),
			'etapa'     => array( 'Etapa de vida', 'filter_etapa', array( 'Cachorro', 'Adulto', 'Senior' ) ),
			'marca'     => array( 'Marca', 'filter_marca', $marcas ),
			'necesidad' => array( 'Necesidad especial', 'filter_necesidad', array( 'Raza grande', 'Control de peso', 'Hipoalergénico' ) ),
		);
	}
	return $f;
}

function patitas_es_catalogo() {
	return function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() );
}

/** Selección actual (slugs por faceta) leída de la URL. */
function patitas_seleccion() {
	$sel = array();
	foreach ( patitas_facetas() as $clave => $f ) {
		$valor = 'product_cat' === $f[1] ? (string) get_query_var( 'product_cat' ) : sanitize_text_field( wp_unslash( $_GET[ $f[1] ] ?? '' ) ); // phpcs:ignore
		$slugs = array_values( array_filter( array_map( 'sanitize_title', preg_split( '/[,+]/', $valor ) ) ) );
		if ( 'categoria' === $clave && in_array( 'alimento', $slugs, true ) ) {
			$slugs = array_values( array_unique( array_merge( array_diff( $slugs, array( 'alimento' ) ), array( 'alimento-seco', 'alimento-humedo' ) ) ) );
		}
		$sel[ $clave ] = $slugs;
	}
	return $sel;
}

/** Otros filtros que se conservan al cambiar facetas. */
function patitas_extras() {
	$extras = array();
	foreach ( array( 'orderby', 'min_price', 'max_price', 'en_stock', 's', 'post_type' ) as $p ) {
		if ( isset( $_GET[ $p ] ) && '' !== $_GET[ $p ] ) { // phpcs:ignore
			$extras[ $p ] = sanitize_text_field( wp_unslash( $_GET[ $p ] ) ); // phpcs:ignore
		}
	}
	return $extras;
}

/** URL del catálogo con una selección dada. */
function patitas_url_catalogo( $sel, $extras = null ) {
	$extras = null === $extras ? patitas_extras() : $extras;
	$base   = is_product_tag() ? get_term_link( get_queried_object() ) : wc_get_page_permalink( 'shop' );
	$args   = array();
	foreach ( patitas_facetas() as $clave => $f ) {
		if ( ! empty( $sel[ $clave ] ) ) {
			$args[ $f[1] ] = implode( ',', $sel[ $clave ] );
			if ( 0 === strpos( $f[1], 'filter_' ) ) {
				$args[ 'query_type_' . substr( $f[1], 7 ) ] = 'or';
			}
		}
	}
	return add_query_arg( array_map( 'rawurlencode', array_merge( $extras, $args ) ), $base );
}

/** Productos que cumplen los filtros fijos (búsqueda, etiqueta, precio y stock), sin facetas. */
function patitas_base_catalogo() {
	$args = array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true );
	if ( is_search() ) {
		$args['s'] = get_search_query();
	}
	if ( is_product_tag() ) {
		$args['tax_query'] = array( array( 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => get_queried_object_id() ) ); // phpcs:ignore
	}
	$ids      = get_posts( $args );
	$catalogo = patitas_catalogo();
	$min      = isset( $_GET['min_price'] ) ? (float) $_GET['min_price'] : null; // phpcs:ignore
	$max      = isset( $_GET['max_price'] ) ? (float) $_GET['max_price'] : null; // phpcs:ignore
	$stock    = ! empty( $_GET['en_stock'] ); // phpcs:ignore
	$salida   = array();
	foreach ( $ids as $id ) {
		$p = $catalogo[ $id ] ?? null;
		if ( ! $p || ( null !== $min && $p['precio'] < $min ) || ( null !== $max && $p['precio'] > $max ) || ( $stock && ! $p['stock'] ) ) {
			continue;
		}
		$salida[ $id ] = $p;
	}
	return $salida;
}

function patitas_cumple( $p, $sel, $excepto = null ) {
	foreach ( $sel as $clave => $valores ) {
		if ( $clave === $excepto || ! $valores ) {
			continue;
		}
		if ( ! array_intersect( $p['facetas'][ $clave ], $valores ) ) {
			return false;
		}
	}
	return true;
}

/* ------------------------------------------------------------------ consulta: orden, stock y cantidad por página */

add_filter( 'loop_shop_per_page', function () {
	return 60;
}, 20 );
add_filter( 'loop_shop_columns', function () {
	return 4;
}, 20 );
add_filter( 'storefront_loop_columns', function () {
	return 4;
}, 20 );

add_filter( 'woocommerce_catalog_orderby', function ( $opciones ) {
	unset( $opciones['menu_order'] );
	return array( 'recomendados' => 'Recomendados para ti' ) + $opciones;
} );
add_filter( 'woocommerce_default_catalog_orderby', function () {
	return 'recomendados';
} );
add_filter( 'woocommerce_get_catalog_ordering_args', function ( $args, $orderby ) {
	if ( 'recomendados' === $orderby ) {
		$args = array( 'orderby' => 'post__in', 'order' => 'ASC', 'meta_key' => '' ); // phpcs:ignore
	}
	return $args;
}, 10, 2 );

add_action( 'woocommerce_product_query', function ( $q ) {
	$orden = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'recomendados'; // phpcs:ignore
	if ( 'recomendados' === $orden ) {
		$q->set( 'post__in', patitas_ranking( get_current_user_id() ) );
		$q->set( 'orderby', 'post__in' );
	}
	if ( ! empty( $_GET['en_stock'] ) ) { // phpcs:ignore
		$meta   = (array) $q->get( 'meta_query' );
		$meta[] = array( 'key' => '_stock_status', 'value' => 'instock' );
		$q->set( 'meta_query', $meta );
	}
} );

/* ------------------------------------------------------------------ cabecera del catálogo */

function patitas_titulo_catalogo() {
	$sel = patitas_seleccion();
	if ( is_search() ) {
		return sprintf( 'Resultados para «%s»', get_search_query() );
	}
	if ( is_product_tag() ) {
		$t = single_term_title( '', false );
		return 'Oferta' === $t ? 'Ofertas' : $t;
	}
	$nombres = array();
	foreach ( patitas_facetas()['categoria'][2] as $c ) {
		if ( in_array( sanitize_title( $c ), $sel['categoria'], true ) ) {
			$nombres[] = $c;
		}
	}
	if ( array( 'Alimento seco', 'Alimento húmedo' ) === $nombres ) {
		$titulo = 'Alimento';
	} elseif ( 1 === count( $nombres ) ) {
		$titulo = $nombres[0];
	} else {
		$titulo = 'Productos';
	}
	if ( 1 === count( $sel['especie'] ) ) {
		$titulo .= ' para ' . $sel['especie'][0];
	} elseif ( 'Productos' === $titulo && ! array_filter( $sel ) ) {
		$titulo = 'Todos los productos';
	}
	return $titulo;
}

add_filter( 'woocommerce_page_title', function ( $titulo ) {
	return patitas_es_catalogo() ? patitas_titulo_catalogo() : $titulo;
} );

add_action( 'woocommerce_archive_description', function () {
	global $wp_query;
	if ( ! patitas_es_catalogo() ) {
		return;
	}
	printf( '<p class="pf-subtitulo">%d productos en la tienda · %d coinciden con tu selección</p>',
		count( patitas_catalogo() ), (int) $wp_query->found_posts );
}, 5 );

// WooCommerce y Storefront registran sus plantillas después de cargar este plugin: los cambios se hacen en «init».
add_action( 'init', function () {
	remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
	// La etiqueta propia reemplaza al «¡Oferta!» (WooCommerce lo pone antes del título y Storefront después).
	remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 6 );
	// Precio del formato por defecto en lugar del rango de precios.
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
}, 20 );

add_filter( 'woocommerce_get_breadcrumb', function ( $migas ) {
	if ( ! patitas_es_catalogo() ) {
		return $migas;
	}
	$sel = patitas_seleccion();
	if ( 1 === count( $sel['especie'] ) && ! is_product_category() ) {
		$migas[] = array( ucfirst( $sel['especie'][0] ), '' );
	}
	return $migas;
} );

/* ------------------------------------------------------------------ barra: filtros activos, cantidad y orden */

add_action( 'wp', function () {
	if ( ! patitas_es_catalogo() ) {
		return;
	}
	remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
	remove_action( 'woocommerce_before_shop_loop', 'storefront_woocommerce_pagination', 30 );
	remove_action( 'woocommerce_after_shop_loop', 'woocommerce_catalog_ordering', 10 );
	remove_action( 'woocommerce_after_shop_loop', 'woocommerce_result_count', 20 );
	// Panel de filtros propio en lugar de los widgets (la barra lateral puede venir de cualquiera de los dos ganchos).
	foreach ( array( 'storefront_sidebar', 'woocommerce_sidebar' ) as $gancho ) {
		remove_action( $gancho, 'storefront_get_sidebar', 10 );
		remove_action( $gancho, 'woocommerce_get_sidebar', 10 );
		add_action( $gancho, 'patitas_panel_filtros', 10 );
	}
} );

add_action( 'woocommerce_before_shop_loop', function () {
	global $wp_query;
	if ( ! patitas_es_catalogo() ) {
		return;
	}
	$sel    = patitas_seleccion();
	$extras = patitas_extras();
	$chips  = array();
	foreach ( patitas_facetas() as $clave => $f ) {
		foreach ( $f[2] as $nombre ) {
			$slug = sanitize_title( $nombre );
			if ( in_array( $slug, $sel[ $clave ], true ) ) {
				$otra           = $sel;
				$otra[ $clave ] = array_values( array_diff( $sel[ $clave ], array( $slug ) ) );
				$chips[]        = array( $nombre, patitas_url_catalogo( $otra ) );
			}
		}
	}
	if ( isset( $extras['min_price'] ) || isset( $extras['max_price'] ) ) {
		$sin = $extras;
		unset( $sin['min_price'], $sin['max_price'] );
		$chips[] = array( 'Precio ' . patitas_clp( $extras['min_price'] ?? 0 ) . ' – ' . ( isset( $extras['max_price'] ) ? patitas_clp( $extras['max_price'] ) : 'más' ), patitas_url_catalogo( $sel, $sin ) );
	}
	if ( ! empty( $extras['en_stock'] ) ) {
		$sin = $extras;
		unset( $sin['en_stock'] );
		$chips[] = array( 'Con stock', patitas_url_catalogo( $sel, $sin ) );
	}
	echo '<div class="pf-activos">';
	foreach ( $chips as $c ) {
		printf( '<a class="pf-chip-filtro" href="%s" aria-label="Quitar filtro %s">%s <span aria-hidden="true">×</span></a>', esc_url( $c[1] ), esc_attr( $c[0] ), esc_html( $c[0] ) );
	}
	printf( '<span class="pf-total">%d productos</span>', (int) $wp_query->found_posts );
	if ( count( $chips ) > 1 ) {
		printf( '<a class="pf-limpiar" href="%s">Limpiar filtros</a>', esc_url( wc_get_page_permalink( 'shop' ) ) );
	}
	echo '</div>';
}, 15 );

/* ------------------------------------------------------------------ panel de filtros */

function patitas_panel_filtros() {
	static $hecho = false;
	if ( $hecho ) {
		return;
	}
	$hecho    = true;
	$sel      = patitas_seleccion();
	$base     = patitas_base_catalogo();
	$extras   = patitas_extras();
	echo '<div id="secondary" class="widget-area pf-panel" role="complementary"><form class="pf-filtros" method="get" action="' . esc_url( patitas_url_catalogo( $sel, array() ) ) . '">';
	foreach ( patitas_facetas() as $clave => $f ) {
		echo '<fieldset><legend>' . esc_html( $f[0] ) . '</legend>';
		foreach ( $f[2] as $nombre ) {
			$slug   = sanitize_title( $nombre );
			$activo = in_array( $slug, $sel[ $clave ], true );
			$n      = 0;
			foreach ( $base as $p ) {
				if ( in_array( $slug, $p['facetas'][ $clave ], true ) && patitas_cumple( $p, $sel, $clave ) ) {
					$n++;
				}
			}
			$otra           = $sel;
			$otra[ $clave ] = $activo ? array_values( array_diff( $sel[ $clave ], array( $slug ) ) ) : array_merge( $sel[ $clave ], array( $slug ) );
			if ( ! $n && ! $activo ) {
				printf( '<span class="pf-op pf-op-vacia"><span class="pf-caja"></span>%s<span class="pf-n">0</span></span>', esc_html( $nombre ) );
				continue;
			}
			printf( '<a class="pf-op%s" href="%s" role="checkbox" aria-checked="%s"><span class="pf-caja"></span>%s<span class="pf-n">%d</span></a>',
				$activo ? ' pf-op-activa' : '', esc_url( patitas_url_catalogo( $otra ) ), $activo ? 'true' : 'false', esc_html( $nombre ), (int) $n );
		}
		echo '</fieldset>';
	}
	// Precio y stock: formulario GET que conserva el resto de la selección.
	foreach ( patitas_facetas() as $clave => $f ) {
		if ( ! empty( $sel[ $clave ] ) && 'categoria' !== $clave ) {
			printf( '<input type="hidden" name="%s" value="%s"><input type="hidden" name="query_type_%s" value="or">', esc_attr( $f[1] ), esc_attr( implode( ',', $sel[ $clave ] ) ), esc_attr( substr( $f[1], 7 ) ) );
		}
	}
	if ( ! empty( $sel['categoria'] ) ) {
		printf( '<input type="hidden" name="product_cat" value="%s">', esc_attr( implode( ',', $sel['categoria'] ) ) );
	}
	if ( ! empty( $extras['orderby'] ) ) {
		printf( '<input type="hidden" name="orderby" value="%s">', esc_attr( $extras['orderby'] ) );
	}
	if ( is_search() ) {
		printf( '<input type="hidden" name="s" value="%s"><input type="hidden" name="post_type" value="product">', esc_attr( get_search_query() ) );
	}
	?>
	<fieldset>
		<legend>Precio</legend>
		<div class="pf-rango">
			<input type="number" name="min_price" min="0" step="1000" placeholder="Mín." value="<?php echo esc_attr( $extras['min_price'] ?? '' ); ?>" aria-label="Precio mínimo">
			<input type="number" name="max_price" min="0" step="1000" placeholder="Máx." value="<?php echo esc_attr( $extras['max_price'] ?? '' ); ?>" aria-label="Precio máximo">
		</div>
		<label class="pf-op pf-op-check"><input type="checkbox" name="en_stock" value="1" <?php checked( ! empty( $extras['en_stock'] ) ); ?>> Solo con stock</label>
		<button type="submit" class="button pf-aplicar">Aplicar</button>
	</fieldset>
	<?php
	echo '</form></div>';
}

/* ------------------------------------------------------------------ tarjetas de producto */

// Etiqueta de la tarjeta: Oferta, Nuevo, Más vendido o Recomendado.
add_action( 'woocommerce_before_shop_loop_item_title', function () {
	global $product;
	$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
	$tag  = ( $tags && ! is_wp_error( $tags ) ) ? $tags[0] : ( $product->is_on_sale() ? 'Oferta' : '' );
	if ( $tag ) {
		$clase = array( 'Oferta' => 'rosa', 'Nuevo' => 'teal', 'Más vendido' => 'sol', 'Recomendado' => 'sol' )[ $tag ] ?? 'teal';
		printf( '<span class="pf-tag pf-tag-%s">%s</span>', esc_attr( $clase ), esc_html( $tag ) );
	}
}, 9 );

/** Mascota del cliente para la que el producto es ideal (especie, etapa y necesidad coinciden). */
function patitas_ideal_para( $product_id ) {
	static $mascotas = null;
	if ( null === $mascotas ) {
		$mascotas = ( is_user_logged_in() && patitas_pref( 'personalizacion' ) ) ? patitas_mascotas() : array();
	}
	$p = patitas_catalogo()[ $product_id ] ?? null;
	if ( ! $p || ! $mascotas ) {
		return '';
	}
	// Solo alimento cuya especie y etapa coinciden con la mascota y que cubre su necesidad especial (si la tiene).
	if ( ! patitas_es_alimento( $p['categoria'] ) ) {
		return '';
	}
	foreach ( $mascotas as $m ) {
		$necesidades = (array) ( $m['necesidad'] ?? array() );
		if ( $p['especie'] === $m['especie'] && $p['etapa'] === $m['etapa']
			&& ( ! $necesidades || array_intersect( $necesidades, (array) $p['necesidad'] ) ) ) {
			return $m['nombre'];
		}
	}
	return '';
}

add_action( 'woocommerce_shop_loop_item_title', function () {
	global $product;
	$para = patitas_ideal_para( $product->get_id() );
	if ( $para ) {
		printf( '<span class="pf-ideal"><svg width="12" height="12" viewBox="-12 -12 24 24" aria-hidden="true"><g fill="currentColor"><ellipse cx="0" cy="6" rx="7" ry="6"/><circle cx="-8" cy="-2" r="3"/><circle cx="-3" cy="-8" r="3"/><circle cx="3" cy="-8" r="3"/><circle cx="8" cy="-2" r="3"/></g></svg>Ideal para %s</span>', esc_html( $para ) );
	}
	$marca = $product->get_attribute( 'pa_marca' );
	if ( $marca ) {
		echo '<span class="pf-marca">' . esc_html( $marca ) . '</span>';
	}
}, 5 );

// Estrellas con nota y cantidad de reseñas en una sola línea (reemplaza a las estrellas de WooCommerce).
add_action( 'init', function () {
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );
}, 20 );
add_action( 'woocommerce_after_shop_loop_item_title', function () {
	global $product;
	if ( $product->get_review_count() ) {
		printf( '<div class="pf-valoracion">%s<span>%s (%d)</span></div>', wc_get_rating_html( $product->get_average_rating() ), // phpcs:ignore
			esc_html( number_format( (float) $product->get_average_rating(), 1, ',', '.' ) ), (int) $product->get_review_count() );
	}
}, 5 );

// Producto agotado: «Avísame cuando llegue» (lleva a la ficha), como en el prototipo.
add_filter( 'woocommerce_product_add_to_cart_text', function ( $texto, $product ) {
	return $product->is_in_stock() ? $texto : 'Avísame cuando llegue';
}, 10, 2 );

// El resumen del carrito de la cabecera se calcula en el servidor (Storefront agrega este script al dibujar la cabecera).
add_action( 'wp_footer', function () {
	wp_dequeue_script( 'wc-cart-fragments' );
}, 1 );

// Precio del formato por defecto y línea de formato y stock.
add_action( 'woocommerce_after_shop_loop_item_title', function () {
	global $product;
	$v      = patitas_variacion_defecto( $product );
	$precio = $v ? $v->get_price_html() : $product->get_price_html();
	echo '<span class="price">' . wp_kses_post( $precio ) . '</span>';
	$linea = esc_html( patitas_formato_defecto( $product ) );
	if ( ! $product->is_in_stock() ) {
		$linea .= ' · <b class="pf-agotado">Agotado</b>';
	} elseif ( $product->managing_stock() && $product->get_stock_quantity() <= 5 ) {
		$linea .= ' · <b class="pf-quedan">¡Quedan ' . (int) $product->get_stock_quantity() . '!</b>';
	}
	echo '<span class="pf-formato">' . wp_kses_post( $linea ) . '</span>';
}, 10 );

// En productos con formatos, el botón agrega directamente el formato por defecto (como en el prototipo).
add_filter( 'woocommerce_loop_add_to_cart_link', function ( $html, $product ) {
	$v = patitas_variacion_defecto( $product );
	if ( ! $v || ! $v->is_in_stock() || ! $v->is_purchasable() ) {
		return $html;
	}
	return sprintf( '<a href="%s" data-quantity="1" class="button product_type_simple add_to_cart_button ajax_add_to_cart" data-product_id="%d" data-product_sku="%s" aria-label="%s" rel="nofollow">Agregar al carrito</a>',
		esc_url( add_query_arg( array( 'add-to-cart' => $product->get_id(), 'variation_id' => $v->get_id(), 'attribute_formato' => patitas_formato_defecto( $product ) ), wc_get_cart_url() ) ),
		(int) $v->get_id(), esc_attr( $v->get_sku() ), esc_attr( 'Agregar ' . $v->get_name() . ' al carrito' ) );
}, 10, 2 );
