<?php
/**
 * Experiencia de compra: portada, ficha de producto, carrito, checkout y confirmación.
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ recursos y ajustes generales */

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'patitas-fuentes', 'https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Nunito+Sans:opsz,wght@6..12,400;6..12,600;6..12,700;6..12,800&display=swap', array(), null );
	wp_enqueue_style( 'patitas', PATITAS_URL . 'assets/patitas.css', array(), PATITAS_VER );
	wp_enqueue_script( 'patitas', PATITAS_URL . 'assets/patitas.js', array(), PATITAS_VER, true );
	// El resumen del carrito de la cabecera se calcula en el servidor en cada página; la copia guardada en el
	// navegador (cart fragments) queda desactualizada en Playground, que maneja las cookies por su cuenta.
	wp_dequeue_script( 'wc-cart-fragments' );
}, 99 );

// Las imágenes del catálogo se sirven tal cual (sin generar miniaturas al vuelo).
add_filter( 'woocommerce_resize_images', '__return_false' );
add_filter( 'woocommerce_background_image_regeneration', '__return_false' );

// Carrito, checkout y cuenta a ancho completo (sin barra lateral), como en el prototipo.
add_action( 'wp', function () {
	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() || is_product() ) ) {
		remove_action( 'storefront_sidebar', 'storefront_get_sidebar', 10 );
		add_filter( 'body_class', function ( $clases ) {
			$clases[] = 'storefront-full-width-content';
			$clases[] = 'pf-sin-lateral';
			return $clases;
		} );
	}
	// Miniaturas de «producto anterior / siguiente» en los bordes de la ficha.
	remove_action( 'woocommerce_after_single_product_summary', 'storefront_single_product_pagination', 30 );
} );

// Enlaces guardados sin dominio: el sitio puede abrirse en cualquier dirección (Playground cambia la URL en cada sesión).
add_filter( 'nav_menu_link_attributes', function ( $atributos ) {
	if ( ! empty( $atributos['href'] ) && 0 === strpos( $atributos['href'], '#pf-especie-' ) ) {
		$atributos['href'] = add_query_arg( 'filter_especie', substr( $atributos['href'], 12 ), wc_get_page_permalink( 'shop' ) );
	}
	return $atributos;
} );
foreach ( array( 'widget_text', 'widget_text_content' ) as $patitas_filtro ) {
	add_filter( $patitas_filtro, function ( $texto ) {
		return str_replace( '{{inicio}}', untrailingslashit( home_url() ), $texto );
	}, 99 );
}

add_filter( 'storefront_credit_link', '__return_false' );
add_filter( 'storefront_copyright_text', function () {
	return '© 2026 Patitas Felices SpA · Prototipo académico INACAP: datos, marcas y precios ficticios';
} );

// Si corresponde envío gratis, se oculta el despacho pagado de la misma zona.
add_filter( 'woocommerce_package_rates', function ( $tarifas ) {
	$gratis = false;
	foreach ( $tarifas as $t ) {
		if ( 'free_shipping' === $t->get_method_id() ) {
			$gratis = true;
		}
	}
	if ( $gratis ) {
		foreach ( $tarifas as $k => $t ) {
			if ( 'flat_rate' === $t->get_method_id() ) {
				unset( $tarifas[ $k ] );
			}
		}
	}
	return $tarifas;
}, 20 );

/* ------------------------------------------------------------------ utilidades de presentación */

/** Razones de las recomendaciones visibles en la página ("¿Por qué veo esto?"). */
$GLOBALS['patitas_razones'] = array();

/** Muestra productos con el diseño de WooCommerce y su explicación. */
function patitas_mostrar_productos( $recos, $titulo = '', $intro = '', $columnas = 4 ) {
	if ( ! $recos ) {
		return;
	}
	foreach ( $recos as $r ) {
		$GLOBALS['patitas_razones'][ $r['id'] ] = $r['razon'];
	}
	$q = new WP_Query( array(
		'post_type'      => 'product',
		'post__in'       => wp_list_pluck( $recos, 'id' ),
		'orderby'        => 'post__in',
		'posts_per_page' => count( $recos ),
		'no_found_rows'  => true,
	) );
	echo '<section class="pf-recos">';
	if ( $titulo ) {
		echo '<h2>' . esc_html( $titulo ) . '</h2>';
	}
	if ( $intro ) {
		echo '<p class="pf-intro">' . wp_kses_post( $intro ) . '</p>';
	}
	wc_set_loop_prop( 'name', 'patitas' );
	wc_set_loop_prop( 'columns', $columnas );
	woocommerce_product_loop_start();
	while ( $q->have_posts() ) {
		$q->the_post();
		wc_get_template_part( 'content', 'product' );
	}
	woocommerce_product_loop_end();
	wp_reset_postdata();
	wc_reset_loop();
	echo '</section>';
	$GLOBALS['patitas_razones'] = array(); // la explicación solo acompaña a este bloque
}

add_action( 'woocommerce_after_shop_loop_item', function () {
	global $product;
	$id = $product ? $product->get_id() : 0;
	if ( $id && ! empty( $GLOBALS['patitas_razones'][ $id ] ) ) {
		echo '<details class="pf-por-que"><summary>¿Por qué veo esto?</summary><p>' . esc_html( $GLOBALS['patitas_razones'][ $id ] ) . '</p></details>';
	}
}, 20 );

// Aviso de stock bajo o agotado en los listados.
add_action( 'woocommerce_after_shop_loop_item_title', function () {
	global $product;
	if ( ! $product ) {
		return;
	}
	if ( ! $product->is_in_stock() ) {
		echo '<span class="pf-stock pf-agotado">Agotado</span>';
	} elseif ( $product->managing_stock() && $product->get_stock_quantity() <= 5 ) {
		echo '<span class="pf-stock">¡Quedan ' . (int) $product->get_stock_quantity() . '!</span>';
	}
}, 15 );

function patitas_url_cuenta( $endpoint = '' ) {
	return $endpoint ? wc_get_account_endpoint_url( $endpoint ) : wc_get_page_permalink( 'myaccount' );
}

function patitas_url_categoria( $nombre ) {
	$t = get_term_by( 'name', $nombre, 'product_cat' );
	$l = $t ? get_term_link( $t ) : '';
	return is_wp_error( $l ) || ! $l ? wc_get_page_permalink( 'shop' ) : $l;
}

/* ------------------------------------------------------------------ portada */

add_shortcode( 'patitas_inicio', 'patitas_inicio' );

function patitas_inicio() {
	$tienda = wc_get_page_permalink( 'shop' );
	$img    = PATITAS_URL . 'assets/img/';
	ob_start();
	?>
	<section class="pf-hero">
		<div class="pf-hero-texto">
			<span class="pf-chip pf-chip-blanco">Despacho hoy en Copiapó si compras antes de las 14:00</span>
			<h1>Todo para tu mascota, en tu puerta hoy mismo.</h1>
			<p>Alimento, arena y accesorios con despacho el mismo día en Copiapó, Caldera y Tierra Amarilla. Programa tu recompra y no te quedes sin alimento en pleno turno.</p>
			<div class="pf-cta">
				<a class="button pf-btn-rosa" href="<?php echo esc_url( patitas_url_categoria( 'Alimento' ) ); ?>">Comprar alimento</a>
				<a class="button pf-btn-borde" href="<?php echo esc_url( patitas_url_cuenta( 'mis-mascotas' ) ); ?>">Programar recompra</a>
			</div>
		</div>
		<img class="pf-hero-img" src="<?php echo esc_url( $img . 'portada.png' ); ?>" alt="Perro y gato en el desierto florido" width="800" height="520">
	</section>

	<div class="pf-props">
		<?php
		$props = array(
			array( 'M2 6h11v9H2zM13 9h4l3 3v3h-7', 'Despacho el mismo día', 'Copiapó, Caldera y T. Amarilla' ),
			array( 'M3 9l2-5h14l2 5M4 9v11h16V9M3 9h18M9 20v-6h6v6', 'Retiro en tienda', 'Listo en 2 horas, sin costo' ),
			array( 'M2 5h20v14H2zM2 10h20M6 15h4', 'Paga como quieras', 'Webpay, Mercado Pago o transferencia' ),
			array( 'M17 2l3 3-3 3M4 11V9a4 4 0 0 1 4-4h12M7 22l-3-3 3-3M20 13v2a4 4 0 0 1-4 4H4', 'Recompra en 1 clic', 'Suscríbete y ahorra 5 %' ),
		);
		foreach ( $props as $p ) {
			printf( '<div class="pf-prop"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#1E6B66" stroke-width="1.8" aria-hidden="true"><path d="%s"/></svg><div><b>%s</b><span>%s</span></div></div>', esc_attr( $p[0] ), esc_html( $p[1] ), esc_html( $p[2] ) );
		}
		?>
	</div>

	<?php patitas_bloque_personal(); ?>

	<section class="pf-seccion">
		<div class="pf-seccion-cab"><h2>Lo más vendido en Copiapó</h2><a href="<?php echo esc_url( $tienda ); ?>">Ver catálogo completo</a></div>
		<?php echo do_shortcode( '[products limit="4" columns="4" orderby="popularity"]' ); ?>
	</section>

	<section class="pf-seccion">
		<div class="pf-seccion-cab"><h2>Compra por categoría</h2></div>
		<div class="pf-categorias">
			<a href="<?php echo esc_url( add_query_arg( 'filter_especie', 'perros', $tienda ) ); ?>">🐶 Perros</a>
			<a href="<?php echo esc_url( add_query_arg( 'filter_especie', 'gatos', $tienda ) ); ?>">🐱 Gatos</a>
			<?php foreach ( array( 'Alimento', 'Arena', 'Farmacia', 'Accesorios', 'Kits' ) as $c ) : ?>
				<a href="<?php echo esc_url( patitas_url_categoria( $c ) ); ?>"><?php echo esc_html( $c ); ?></a>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="pf-social">
		<div>
			<span class="pf-chip pf-chip-blanco">#PatitasFelicesCPY</span>
			<h2>Nuestra comunidad en Instagram y TikTok</h2>
			<p>Sube la foto de tu mascota con el hashtag y aparece aquí (siempre con tu autorización). Toca una foto para ver los productos que usa.</p>
			<a class="button pf-btn-rosa" href="https://www.instagram.com/" target="_blank" rel="noopener">Seguir @patitasfelices.cpy</a>
		</div>
		<div class="pf-ugc">
			<?php
			$fotos = array( array( '@lacoco_cpy', 'p14', 'Kit Cachorro Feliz' ), array( '@michi.luna', 'p13', 'Rascador' ), array( '@toby_caldera', 'p10', 'Cama Oasis' ), array( '@gato.naranjo', 'p34', 'Ratones con catnip' ) );
			foreach ( $fotos as $i => $f ) {
				$url = get_permalink( patitas_pid( $f[1] ) );
				printf( '<a class="pf-foto" href="%s"><img src="%s" alt="Foto de la comunidad de %s" width="240" height="240" loading="lazy"><span><b>%s</b>Comprar el look: %s</span></a>',
					esc_url( $url ), esc_url( $img . 'comunidad-' . ( $i + 1 ) . '.png' ), esc_attr( $f[0] ), esc_html( $f[0] ), esc_html( $f[2] ) );
			}
			?>
		</div>
	</section>

	<section class="pf-campana">
		<div class="pf-campana-icono" aria-hidden="true">🌸</div>
		<div>
			<h3>Desafío Desierto Florido: muéstranos tu paseo</h3>
			<p>Publica un video del paseo con tu mascota, etiqueta a @patitasfelices.cpy y participa por un Kit Paseo Seguro. Invita a un amigo con tu código y ambos reciben $5.000 de descuento.</p>
		</div>
		<a class="button" href="<?php echo esc_url( get_permalink( patitas_pid( 'p41' ) ) ); ?>">Ver Kit Paseo Seguro</a>
	</section>
	<?php
	return ob_get_clean();
}

/** Bloque personalizado: recordatorio de recompra y recomendaciones según las mascotas. */
function patitas_bloque_personal() {
	$uid = get_current_user_id();
	if ( ! $uid ) {
		printf( '<section class="pf-invita"><b>¿Tienes perro o gato?</b> Crea tu cuenta, registra a tu mascota y te avisamos antes de que se acabe su alimento. <a class="button" href="%s">Crear cuenta o ingresar</a></section>', esc_url( patitas_url_cuenta() ) );
		return;
	}
	if ( ! patitas_pref( 'personalizacion', $uid ) ) {
		printf( '<section class="pf-invita">Tienes la personalización desactivada, por eso no ves recomendaciones. Puedes activarla en <a href="%s">Mi cuenta › Privacidad</a>.</section>', esc_url( patitas_url_cuenta( 'privacidad' ) ) );
		return;
	}
	$usuario  = wp_get_current_user();
	$mascotas = patitas_mascotas( $uid );
	$nombres  = wp_list_pluck( $mascotas, 'nombre' );
	$para     = $nombres ? ( count( $nombres ) > 1 ? implode( ', ', array_slice( $nombres, 0, -1 ) ) . ' y ' . end( $nombres ) : $nombres[0] ) : 'ti';
	echo '<section class="pf-seccion pf-personal">';
	printf( '<div class="pf-seccion-cab"><div><span class="pf-chip">Personalizado para ti</span><h2>Hola %s, esto es para %s</h2></div><a href="%s">Editar mis mascotas</a></div>',
		esc_html( $usuario->first_name ? $usuario->first_name : $usuario->display_name ), esc_html( $para ), esc_url( patitas_url_cuenta( 'mis-mascotas' ) ) );
	echo '<div class="pf-perso">';
	patitas_tarjeta_recompra( $uid );
	echo '<div class="pf-perso-recos">';
	$recos = patitas_recomendar( array( 'contexto' => patitas_historial( $uid ), 'mascotas' => $mascotas, 'limite' => 3 ) );
	patitas_mostrar_productos( $recos, '', '', 3 );
	printf( '<p class="pf-nota">Recomendaciones según el perfil de tus mascotas y tus compras anteriores. <a href="%s">Administrar personalización</a></p>', esc_url( patitas_url_cuenta( 'privacidad' ) ) );
	echo '</div></div></section>';
}

/** Tarjeta "Recordatorio de recompra" (predicción de la sección 4). */
function patitas_tarjeta_recompra( $uid ) {
	if ( ! patitas_pref( 'recordatorios', $uid ) ) {
		return;
	}
	$lista = patitas_recompras( $uid );
	if ( ! $lista ) {
		return;
	}
	$r    = $lista[0];
	$prod = wc_get_product( $r['variacion'] ? $r['variacion'] : $r['producto'] );
	if ( ! $prod ) {
		return;
	}
	$m = $r['mascota'];
	if ( $r['dias'] > 1 ) {
		$cuando = sprintf( 'se acabaría cerca del <b>%s</b> (en %d días)', esc_html( patitas_fecha( $r['fin'] ) ), $r['dias'] );
	} elseif ( $r['dias'] >= 0 ) {
		$cuando = 'se está por acabar';
	} else {
		$cuando = 'probablemente ya se acabó';
	}
	$args = array( 'add-to-cart' => $r['producto'] );
	if ( $r['variacion'] ) {
		$args['variation_id']      = $r['variacion'];
		$args['attribute_formato'] = $r['formato'];
	}
	$racion = patitas_racion_kg( $m['especie'], $m['peso'] ?? 10 ) * 1000;
	?>
	<div class="pf-recompra">
		<span class="pf-etiqueta">Recordatorio de recompra</span>
		<div class="pf-recompra-prod">
			<?php echo $prod->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore ?>
			<div>
				<b><?php echo esc_html( $prod->get_name() ); ?></b>
				<p>Según el consumo de <?php echo esc_html( $m['nombre'] ); ?> (<?php echo esc_html( $m['peso'] ); ?> kg), tu última bolsa <?php echo wp_kses_post( $cuando ); ?>.</p>
			</div>
		</div>
		<a class="button pf-btn-oscuro" href="<?php echo esc_url( add_query_arg( $args, wc_get_cart_url() ) ); ?>">Repetir pedido · <?php echo wp_kses_post( wc_price( $prod->get_price() ) ); ?></a>
		<details class="pf-por-que">
			<summary>¿Por qué veo esto?</summary>
			<p>Lo calculamos con la fecha de tu última compra (<?php echo esc_html( patitas_fecha( $r['compra'] ) ); ?>, <?php echo esc_html( $r['formato'] ); ?>) y una ración estimada de <?php echo (int) $racion; ?> g diarios para un <?php echo esc_html( patitas_singular( $m['especie'] ) ); ?> de <?php echo esc_html( $m['peso'] ); ?> kg. Puedes desactivar los recordatorios en <a href="<?php echo esc_url( patitas_url_cuenta( 'privacidad' ) ); ?>">Mi cuenta › Privacidad</a>.</p>
		</details>
	</div>
	<?php
}

/* ------------------------------------------------------------------ ficha de producto */

function patitas_es_consumible( $product_id ) {
	return in_array( patitas_ficha( $product_id )['categoria'], array( 'Alimento seco', 'Alimento húmedo', 'Arena' ), true );
}

// Opción de suscripción con 5 % de descuento para alimento y arena.
add_action( 'woocommerce_before_add_to_cart_button', function () {
	global $product;
	if ( ! $product || ! patitas_es_consumible( $product->get_id() ) ) {
		return;
	}
	?>
	<fieldset class="pf-suscripcion">
		<legend>¿Cómo quieres comprarlo?</legend>
		<label><input type="radio" name="patitas_suscripcion" value="" checked> Compra única</label>
		<label><input type="radio" name="patitas_suscripcion" value="4"> Suscríbete y ahorra 5 %: cada
			<select name="patitas_frecuencia" aria-label="Frecuencia de la suscripción"><option value="4">4 semanas</option><option value="6">6 semanas</option><option value="8">8 semanas</option></select>
		</label>
		<small>Te avisamos 2 días antes de cada envío y puedes pausar cuando quieras.</small>
	</fieldset>
	<?php
} );

add_filter( 'woocommerce_add_cart_item_data', function ( $datos, $product_id, $variation_id ) {
	if ( ! empty( $_POST['patitas_suscripcion'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$prod                    = wc_get_product( $variation_id ? $variation_id : $product_id );
		$datos['patitas_sub']    = max( 4, (int) ( $_POST['patitas_frecuencia'] ?? 4 ) ); // phpcs:ignore
		$datos['patitas_precio'] = $prod ? (float) $prod->get_price() : 0;
	}
	return $datos;
}, 10, 3 );

add_action( 'woocommerce_before_calculate_totals', function ( $carrito ) {
	foreach ( $carrito->get_cart() as $item ) {
		if ( ! empty( $item['patitas_sub'] ) && ! empty( $item['patitas_precio'] ) ) {
			$item['data']->set_price( round( $item['patitas_precio'] * 0.95 ) );
		}
	}
} );

add_filter( 'woocommerce_get_item_data', function ( $datos, $item ) {
	if ( ! empty( $item['patitas_sub'] ) ) {
		$datos[] = array( 'key' => 'Suscripción', 'value' => sprintf( 'cada %d semanas (−5 %%)', $item['patitas_sub'] ) );
	}
	return $datos;
}, 10, 2 );

add_action( 'woocommerce_checkout_create_order_line_item', function ( $linea, $clave, $item ) {
	if ( ! empty( $item['patitas_sub'] ) ) {
		$linea->add_meta_data( 'Suscripción', sprintf( 'cada %d semanas (−5 %%)', $item['patitas_sub'] ) );
	}
}, 10, 3 );

// Cálculo de despacho por comuna.
add_action( 'woocommerce_single_product_summary', function () {
	?>
	<div class="pf-despacho">
		<label for="pf-comuna"><b>Calcula tu despacho</b></label>
		<select id="pf-comuna">
			<option value="hoy|2990">Copiapó</option>
			<option value="hoy|2990">Caldera</option>
			<option value="hoy|2990">Tierra Amarilla</option>
			<option value="courier|5990">Vallenar y resto de Atacama</option>
			<option value="courier|5990">Otra región</option>
		</select>
		<p id="pf-despacho-res" aria-live="polite">Llega <b>hoy</b> si compras antes de las 14:00 · $2.990 (gratis sobre $40.000). También puedes retirar en tienda sin costo.</p>
	</div>
	<?php
}, 35 );

// Botones para compartir.
add_action( 'woocommerce_share', function () {
	global $product;
	if ( ! $product ) {
		return;
	}
	$url   = rawurlencode( get_permalink( $product->get_id() ) );
	$texto = rawurlencode( '¡Mira esto en Patitas Felices! ' . $product->get_name() );
	printf(
		'<div class="pf-compartir"><span>Compartir:</span><a href="https://wa.me/?text=%1$s%%20%2$s" target="_blank" rel="noopener">WhatsApp</a><a href="https://www.facebook.com/sharer/sharer.php?u=%2$s" target="_blank" rel="noopener">Facebook</a><a href="https://twitter.com/intent/tweet?text=%1$s&url=%2$s" target="_blank" rel="noopener">X</a></div>',
		esc_attr( $texto ), esc_attr( $url )
	);
} );

// "Se compran juntos" (colaborativo ítem a ítem) antes de las pestañas.
add_action( 'woocommerce_after_single_product_summary', function () {
	global $product;
	if ( ! $product ) {
		return;
	}
	$id    = $product->get_id();
	$co    = get_option( 'patitas_cocompras', array() );
	$lista = $co[ $id ] ?? array();
	arsort( $lista );
	$juntos = array();
	foreach ( array_keys( $lista ) as $otro ) {
		$p = wc_get_product( $otro );
		if ( $p && $p->is_in_stock() && $p->is_purchasable() ) {
			$juntos[] = $p;
		}
		if ( count( $juntos ) >= 2 ) {
			break;
		}
	}
	if ( ! $juntos || ! $product->is_in_stock() ) {
		return;
	}
	$todos = array_merge( array( $product ), $juntos );
	$total = 0;
	echo '<section class="pf-juntos"><h2>Se compran juntos</h2><div class="pf-juntos-fila">';
	foreach ( $todos as $i => $p ) {
		$total += (float) $p->get_price();
		if ( $i ) {
			echo '<span class="pf-mas" aria-hidden="true">+</span>';
		}
		printf( '<a class="pf-juntos-item" href="%s">%s<span>%s</span><b>%s</b></a>', esc_url( $p->get_permalink() ), $p->get_image( 'woocommerce_thumbnail' ), esc_html( $p->get_name() ), wp_kses_post( $p->get_price_html() ) ); // phpcs:ignore
	}
	$ids = array_map( function ( $p ) {
		return $p->get_id();
	}, $todos );
	$url = add_query_arg( 'patitas_combo', implode( ',', $ids ), wc_get_cart_url() );
	printf( '<div class="pf-juntos-total"><span>Total de los %d</span><b>%s</b><a class="button pf-btn-rosa" href="%s">Agregar los %d al carrito</a><details class="pf-por-que"><summary>¿Por qué veo esto?</summary><p>Son los productos que más se compran junto a este en los pedidos de la tienda (filtrado colaborativo ítem a ítem, recalculado cada noche).</p></details></div>',
		count( $todos ), wp_kses_post( wc_price( $total ) ), esc_url( $url ), count( $todos ) );
	echo '</div></section>';
}, 5 );

// Agrega varios productos de una vez (se usa en "Se compran juntos").
add_action( 'wp_loaded', function () {
	if ( empty( $_GET['patitas_combo'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) { // phpcs:ignore
		return;
	}
	$n = 0;
	foreach ( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_GET['patitas_combo'] ) ) ) ) as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			continue;
		}
		if ( $p->is_type( 'variable' ) ) {
			$attrs = array();
			foreach ( $p->get_default_attributes() as $k => $v ) {
				$attrs[ 'attribute_' . $k ] = $v;
			}
			$vid = ( new WC_Product_Data_Store_CPT() )->find_matching_product_variation( $p, $attrs );
			if ( $vid && WC()->cart->add_to_cart( $id, 1, $vid, $attrs ) ) {
				$n++;
			}
		} elseif ( WC()->cart->add_to_cart( $id, 1 ) ) {
			$n++;
		}
	}
	if ( $n ) {
		wc_add_notice( sprintf( 'Agregamos %d productos a tu carrito.', $n ) );
	}
	wp_safe_redirect( wc_get_cart_url() );
	exit;
}, 25 );

// Productos relacionados: reemplazados por el recomendador híbrido (se quita cuando WooCommerce ya registró sus plantillas).
add_action( 'wp', function () {
	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
} );
add_action( 'woocommerce_after_single_product_summary', function () {
	global $product;
	if ( ! $product ) {
		return;
	}
	$recos = patitas_recomendar( array(
		'contexto' => array( $product->get_id() ),
		'especie'  => patitas_ficha( $product->get_id() )['especie'],
		'mascotas' => patitas_pref( 'personalizacion' ) ? patitas_mascotas() : array(),
		'limite'   => 4,
	) );
	patitas_mostrar_productos( $recos, 'También te puede gustar' );
}, 20 );

/* ------------------------------------------------------------------ carrito */

function patitas_barra_envio() {
	if ( ! WC()->cart ) {
		return;
	}
	$sub   = (float) WC()->cart->get_displayed_subtotal();
	$falta = PATITAS_ENVIO_GRATIS - $sub;
	$pct   = min( 100, round( $sub / PATITAS_ENVIO_GRATIS * 100 ) );
	$texto = $falta > 0
		? sprintf( 'Te faltan <b>%s</b> para el <b>envío gratis</b> el mismo día.', wc_price( $falta ) )
		: '¡Tu pedido tiene <b>envío gratis</b> el mismo día en Copiapó, Caldera y Tierra Amarilla!';
	printf( '<div class="pf-envio"><p>%s</p><div class="pf-barra" role="progressbar" aria-valuenow="%d" aria-valuemin="0" aria-valuemax="100"><b style="width:%d%%"></b></div></div>', wp_kses_post( $texto ), (int) $pct, (int) $pct );
}
add_action( 'woocommerce_before_cart', 'patitas_barra_envio' );
add_action( 'woocommerce_widget_shopping_cart_before_buttons', 'patitas_barra_envio' );

// "Completa tu pedido": venta cruzada calculada por el recomendador.
add_filter( 'woocommerce_cart_crosssell_ids', function ( $ids ) {
	if ( ! WC()->cart ) {
		return $ids;
	}
	$contexto = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		$contexto[] = (int) $item['product_id'];
	}
	$recos = patitas_recomendar( array(
		'contexto' => $contexto,
		'mascotas' => patitas_pref( 'personalizacion' ) ? patitas_mascotas() : array(),
		'limite'   => 3,
	) );
	foreach ( $recos as $r ) {
		$GLOBALS['patitas_razones'][ $r['id'] ] = $r['razon'];
	}
	return $recos ? wp_list_pluck( $recos, 'id' ) : $ids;
} );
add_filter( 'woocommerce_product_cross_sells_products_heading', function () {
	return 'Completa tu pedido';
} );
add_filter( 'woocommerce_cross_sells_total', function () {
	return 3;
} );
add_filter( 'woocommerce_cross_sells_columns', function () {
	return 3;
} );

/* ------------------------------------------------------------------ checkout */

add_action( 'woocommerce_before_checkout_form', function () {
	echo '<ol class="pf-pasos"><li class="hecho">Carrito</li><li class="actual">Datos y despacho</li><li class="actual">Pago</li><li>Confirmación</li></ol>';
	patitas_barra_envio();
}, 1 );

add_filter( 'woocommerce_billing_fields', function ( $campos ) {
	$campos['billing_rut'] = array(
		'label'       => 'RUT (para tu boleta electrónica)',
		'placeholder' => '12.345.678-5',
		'required'    => false,
		'class'       => array( 'form-row-wide' ),
		'priority'    => 25,
	);
	return $campos;
} );

add_action( 'woocommerce_review_order_before_submit', function () {
	$marcado = is_user_logged_in() && patitas_pref( 'marketing' );
	?>
	<p class="form-row pf-consentimiento">
		<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
			<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="patitas_marketing" value="1" <?php checked( $marcado ); ?>>
			<span>Quiero recibir ofertas y recordatorios por correo o WhatsApp (opcional; puedes retirar tu consentimiento en Mi cuenta › Privacidad).</span>
		</label>
	</p>
	<p class="pf-retracto">Tienes <b>derecho a retracto de 10 días</b> desde que recibes tu pedido (Ley N.º 19.496). Pago 100 % seguro: la tienda no guarda datos de tu tarjeta.</p>
	<?php
} );

add_action( 'woocommerce_checkout_create_order', function ( $pedido ) {
	$si = ! empty( $_POST['patitas_marketing'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$pedido->update_meta_data( '_patitas_marketing', $si ? 'si' : 'no' );
	if ( $pedido->get_customer_id() ) {
		update_user_meta( $pedido->get_customer_id(), 'patitas_pref_marketing', $si ? 'si' : 'no' );
	}
} );

/* ------------------------------------------------------------------ confirmación */

add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$pedido = wc_get_order( $order_id );
	if ( ! $pedido ) {
		return;
	}
	$retiro = false;
	foreach ( $pedido->get_shipping_methods() as $envio ) {
		if ( false !== strpos( $envio->get_method_id(), 'pickup' ) ) {
			$retiro = true;
		}
	}
	$pasos = $retiro
		? array( array( 'Pedido recibido', 'hecho' ), array( 'Preparando tu pedido', 'actual' ), array( 'Listo para retiro (2 horas)', '' ), array( 'Retirado', '' ) )
		: array( array( 'Pedido recibido', 'hecho' ), array( 'Preparando tu pedido', 'actual' ), array( 'En camino (hoy si pagaste antes de las 14:00)', '' ), array( 'Entregado', '' ) );
	echo '<section class="pf-seguimiento"><h2>Seguimiento de tu pedido</h2><ol>';
	foreach ( $pasos as $p ) {
		printf( '<li class="%s">%s</li>', esc_attr( $p[1] ), esc_html( $p[0] ) );
	}
	echo '</ol><p>Te avisaremos por correo y WhatsApp en cada cambio de estado. También puedes verlo en <a href="' . esc_url( patitas_url_cuenta( 'orders' ) ) . '">Mi cuenta › Pedidos</a>.</p></section>';
}, 5 );

add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$pedido = wc_get_order( $order_id );
	if ( ! $pedido ) {
		return;
	}
	$contexto = array();
	foreach ( $pedido->get_items() as $item ) {
		$contexto[] = (int) $item->get_product_id();
	}
	$recos = patitas_recomendar( array(
		'contexto' => $contexto,
		'mascotas' => patitas_pref( 'personalizacion' ) ? patitas_mascotas() : array(),
		'limite'   => 4,
	) );
	$codigo = 'AMIGO5000';
	printf( '<section class="pf-invita-amigo"><b>Invita a un amigo</b><p>Comparte el código <code>%s</code>: tu amigo recibe $5.000 en su primera compra sobre $25.000 y tú recibes otros $5.000.</p><a class="button pf-btn-rosa" href="https://wa.me/?text=%s" target="_blank" rel="noopener">Compartir por WhatsApp</a></section>',
		esc_html( $codigo ), rawurlencode( 'Te regalo $5.000 en Patitas Felices con el código ' . $codigo . ' ' . home_url( '/' ) ) );
	patitas_mostrar_productos( $recos, 'Para tu próxima compra' );
}, 20 );
