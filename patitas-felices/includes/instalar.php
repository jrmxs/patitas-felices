<?php
/**
 * Instalador de la tienda demo: deja WooCommerce configurado para Chile y carga el catálogo y datos de ejemplo.
 * Se ejecuta una vez desde el blueprint de WordPress Playground (o desde WooCommerce › Patitas Felices).
 */

defined( 'ABSPATH' ) || exit;

function patitas_instalar() {
	@set_time_limit( 0 );
	// Durante la carga no se envían correos.
	add_filter( 'pre_wp_mail', '__return_false' );
	add_filter( 'woocommerce_defer_transactional_emails', '__return_true' );
	add_filter( 'woocommerce_allow_send_queued_transactional_email', '__return_false' );
	// Ni tareas en segundo plano (Action Scheduler) por cada producto o pedido de ejemplo.
	foreach ( array( 'pre_as_schedule_single_action', 'pre_as_enqueue_async_action', 'pre_as_schedule_recurring_action', 'pre_as_schedule_cron_action' ) as $filtro ) {
		add_filter( $filtro, '__return_zero', 99 );
	}
	if ( ! defined( 'WP_IMPORTING' ) ) {
		define( 'WP_IMPORTING', true );
	}

	$log = array( 'Instalación ' . gmdate( 'Y-m-d H:i:s' ) );
	foreach ( array( 'opciones', 'paginas', 'catalogo', 'resenas', 'clientes', 'pedidos', 'envios', 'pagos', 'cupones', 'apariencia' ) as $etapa ) {
		$t0 = microtime( true );
		try {
			call_user_func( 'patitas_instalar_' . $etapa );
			$log[] = sprintf( 'ok %-10s %.1f s', $etapa, microtime( true ) - $t0 );
		} catch ( Throwable $e ) {
			$log[] = sprintf( 'ERROR %s: %s (%s:%d)', $etapa, $e->getMessage(), basename( $e->getFile() ), $e->getLine() );
		}
	}
	try {
		patitas_recalcular();
		$log[] = 'ok recalculo';
	} catch ( Throwable $e ) {
		$log[] = 'ERROR recalculo: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
	}
	if ( ! wp_next_scheduled( 'patitas_recalcular_diario' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', 'patitas_recalcular_diario' );
	}
	update_option( 'patitas_demo', 'yes' );
	update_option( 'patitas_instalado', time() );
	delete_transient( '_wc_activation_redirect' );
	delete_option( 'rewrite_rules' ); // se regeneran en la próxima visita con las rutas en español
	wc_delete_product_transients();
	file_put_contents( WP_CONTENT_DIR . '/patitas-instalacion.log', implode( "\n", $log ) . "\n" );
	return $log;
}

/* ------------------------------------------------------------------ fechas relativas al día de hoy */

/**
 * La tienda demo se distribuye ya instalada (copia del sitio). Para que el historial siga siendo «reciente»
 * (recordatorios de recompra, segmentos, reseñas), al abrirla se corren todas las fechas de pedidos y reseñas
 * en el tiempo transcurrido desde que se armó la copia.
 */
function patitas_actualizar_fechas() {
	$base  = (int) get_option( 'patitas_instalado' );
	$delta = time() - $base;
	if ( ! $base || $delta < HOUR_IN_SECONDS ) {
		return 0;
	}
	global $wpdb;
	$correr = function ( $valor ) use ( $delta ) {
		if ( ! $valor || 0 === strpos( $valor, '0000' ) ) {
			return $valor;
		}
		return gmdate( 'Y-m-d H:i:s', strtotime( $valor . ' UTC' ) + $delta );
	};
	// Pedidos en tablas clásicas (posts).
	$filas = $wpdb->get_results( "SELECT ID, post_date, post_date_gmt, post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE post_type = 'shop_order'", ARRAY_A );
	foreach ( $filas as $f ) {
		$id = $f['ID'];
		unset( $f['ID'] );
		$wpdb->update( $wpdb->posts, array_map( $correr, $f ), array( 'ID' => $id ) );
	}
	$metas = $wpdb->get_results( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_date_paid', '_date_completed', '_paid_date', '_completed_date')", ARRAY_A );
	foreach ( $metas as $m ) {
		$v = is_numeric( $m['meta_value'] ) ? (string) ( (int) $m['meta_value'] + $delta ) : $correr( $m['meta_value'] );
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $v ), array( 'meta_id' => $m['meta_id'] ) );
	}
	// Pedidos en tablas de alto rendimiento (HPOS), si existen.
	$tabla = $wpdb->prefix . 'wc_orders';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabla ) ) === $tabla ) {
		foreach ( $wpdb->get_results( "SELECT id, date_created_gmt, date_updated_gmt FROM {$tabla}", ARRAY_A ) as $f ) {
			$id = $f['id'];
			unset( $f['id'] );
			$wpdb->update( $tabla, array_map( $correr, $f ), array( 'id' => $id ) );
		}
		$operacional = $wpdb->prefix . 'wc_order_operational_data';
		foreach ( $wpdb->get_results( "SELECT id, date_paid_gmt, date_completed_gmt FROM {$operacional}", ARRAY_A ) as $f ) {
			$id = $f['id'];
			unset( $f['id'] );
			$wpdb->update( $operacional, array_map( $correr, $f ), array( 'id' => $id ) );
		}
	}
	// Reseñas.
	foreach ( $wpdb->get_results( "SELECT comment_ID, comment_date, comment_date_gmt FROM {$wpdb->comments} WHERE comment_type = 'review'", ARRAY_A ) as $f ) {
		$id = $f['comment_ID'];
		unset( $f['comment_ID'] );
		$wpdb->update( $wpdb->comments, array_map( $correr, $f ), array( 'comment_ID' => $id ) );
	}
	update_option( 'patitas_instalado', time() );
	update_option( 'patitas_recalculo', time(), false );
	wp_clear_scheduled_hook( 'patitas_recalcular_diario' );
	wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', 'patitas_recalcular_diario' );
	wp_cache_flush();
	delete_transient( 'patitas_catalogo' );
	return $delta;
}

// Respaldo: si el blueprint no lo ejecutó, se hace en la primera visita.
add_action( 'init', function () {
	if ( 'yes' === get_option( 'patitas_demo' ) && time() - (int) get_option( 'patitas_instalado' ) > HOUR_IN_SECONDS ) {
		patitas_actualizar_fechas();
	}
}, 5 );

/* ------------------------------------------------------------------ opciones generales */

function patitas_instalar_opciones() {
	global $wp_rewrite;
	$opciones = array(
		'blogname'                                    => 'Patitas Felices',
		'blogdescription'                             => 'Todo para tu mascota en Copiapó, Caldera y Tierra Amarilla',
		'timezone_string'                             => 'America/Santiago',
		'date_format'                                 => 'j \d\e F \d\e Y',
		'time_format'                                 => 'H:i',
		'start_of_week'                               => 1,
		'woocommerce_currency'                        => 'CLP',
		'woocommerce_currency_pos'                    => 'left',
		'woocommerce_price_thousand_sep'              => '.',
		'woocommerce_price_decimal_sep'               => ',',
		'woocommerce_price_num_decimals'              => 0,
		'woocommerce_default_country'                 => 'CL:CL-AT',
		'woocommerce_store_address'                   => 'Av. Copayapu 1234',
		'woocommerce_store_city'                      => 'Copiapó',
		'woocommerce_store_postcode'                  => '1530000',
		'woocommerce_allowed_countries'               => 'specific',
		'woocommerce_specific_allowed_countries'      => array( 'CL' ),
		'woocommerce_ship_to_countries'               => '',
		'woocommerce_default_customer_address'        => 'base',
		'woocommerce_calc_taxes'                      => 'no',
		'woocommerce_weight_unit'                     => 'kg',
		'woocommerce_dimension_unit'                  => 'cm',
		'woocommerce_coming_soon'                     => 'no',
		'woocommerce_store_pages_only'                => 'no',
		'woocommerce_manage_stock'                    => 'yes',
		'woocommerce_notify_low_stock_amount'         => 5,
		'woocommerce_notify_no_stock_amount'          => 0,
		'woocommerce_hide_out_of_stock_items'         => 'no',
		'woocommerce_stock_format'                    => '',
		'woocommerce_enable_reviews'                  => 'yes',
		'woocommerce_enable_review_rating'            => 'yes',
		'woocommerce_review_rating_required'          => 'yes',
		'woocommerce_review_rating_verification_label' => 'yes',
		'woocommerce_enable_guest_checkout'           => 'yes',
		'woocommerce_enable_checkout_login_reminder'  => 'yes',
		'woocommerce_enable_signup_and_login_from_checkout' => 'yes',
		'woocommerce_enable_myaccount_registration'   => 'yes',
		'woocommerce_registration_generate_password'  => 'no',
		'woocommerce_enable_ajax_add_to_cart'         => 'yes',
		'woocommerce_cart_redirect_after_add'         => 'no',
		'woocommerce_attribute_lookup_enabled'        => 'no',
		'woocommerce_demo_store'                      => 'yes',
		'woocommerce_demo_store_notice'               => 'Prototipo académico INACAP · Datos, marcas y precios ficticios · Los pagos son simulados',
		'woocommerce_onboarding_profile'              => array( 'skipped' => true, 'completed' => true ),
		'woocommerce_task_list_hidden'                => 'yes',
		'woocommerce_task_list_complete'              => 'yes',
		'woocommerce_extended_task_list_hidden'       => 'yes',
		'woocommerce_show_marketplace_suggestions'    => 'no',
		'woocommerce_allow_tracking'                  => 'no',
		'woocommerce_thumbnail_cropping'              => '1:1',
		'woocommerce_checkout_privacy_policy_text'    => 'Usaremos tus datos personales para procesar tu pedido, mejorar tu experiencia en este sitio y otros fines descritos en nuestra [privacy_policy].',
		'woocommerce_registration_privacy_policy_text' => 'Usaremos tus datos personales para mejorar tu experiencia en este sitio, administrar tu cuenta y otros fines descritos en nuestra [privacy_policy].',
		'woocommerce_checkout_terms_and_conditions_checkbox_text' => 'He leído y acepto los [terms] del sitio',
		'woocommerce_permalinks'                      => array(
			'product_base'           => 'producto',
			'category_base'          => 'categoria-producto',
			'tag_base'               => 'etiqueta-producto',
			'attribute_base'         => '',
			'use_verbose_page_rules' => false,
		),
	);
	foreach ( $opciones as $clave => $valor ) {
		update_option( $clave, $valor );
	}
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
}

/* ------------------------------------------------------------------ páginas */

function patitas_pagina( $titulo, $slug, $contenido, $plantilla = '' ) {
	$p  = get_page_by_path( $slug );
	$id = $p ? $p->ID : wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $titulo,
		'post_name'    => $slug,
		'post_content' => $contenido,
	) );
	if ( $plantilla ) {
		update_post_meta( $id, '_wp_page_template', $plantilla );
	}
	return (int) $id;
}

function patitas_instalar_paginas() {
	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::create_pages();
	}
	$woo = array(
		'shop'      => array( 'Catálogo', 'tienda', null ),
		'cart'      => array( 'Carrito', 'carrito', '[woocommerce_cart]' ),
		'checkout'  => array( 'Finalizar compra', 'finalizar-compra', '[woocommerce_checkout]' ),
		'myaccount' => array( 'Mi cuenta', 'mi-cuenta', '[woocommerce_my_account]' ),
	);
	foreach ( $woo as $clave => $d ) {
		$id = wc_get_page_id( $clave );
		if ( $id > 0 ) {
			$datos = array( 'ID' => $id, 'post_title' => $d[0], 'post_name' => $d[1], 'post_status' => 'publish' );
			if ( null !== $d[2] ) {
				$datos['post_content'] = $d[2];
			}
			wp_update_post( $datos );
		}
	}

	$inicio = patitas_pagina( 'Inicio', 'inicio', '[patitas_inicio]', 'template-fullwidth.php' );

	$terminos = patitas_pagina( 'Términos y condiciones', 'terminos-y-condiciones',
		'<p><em>Documento de ejemplo para un prototipo académico.</em></p>'
		. '<h2>1. Compras</h2><p>Los precios incluyen IVA y se expresan en pesos chilenos. La compra se confirma cuando el pago es aprobado y recibes el correo de confirmación.</p>'
		. '<h2>2. Stock</h2><p>El stock se sincroniza con la tienda física cada 5 minutos. Si un producto se agota después de tu compra, te ofrecemos un reemplazo o la devolución del dinero.</p>'
		. '<h2>3. Despacho y retiro</h2><p>Despacho el mismo día en Copiapó, Caldera y Tierra Amarilla para compras pagadas antes de las 14:00. Envío gratis sobre $40.000. Retiro en tienda sin costo, listo en 2 horas.</p>'
		. '<h2>4. Derecho a retracto</h2><p>Puedes retractarte dentro de 10 días desde la recepción del producto, sin uso y en su envase original, conforme a la Ley N.º 19.496 sobre Protección de los Derechos de los Consumidores.</p>'
		. '<h2>5. Datos personales</h2><p>Tratamos tus datos según nuestra Política de privacidad y la Ley N.º 21.719.</p>' );

	$privacidad = patitas_pagina( 'Política de privacidad', 'politica-de-privacidad',
		'<p><em>Documento de ejemplo para un prototipo académico.</em></p>'
		. '<h2>Qué datos usamos</h2><p>Datos de contacto y despacho, historial de compras, perfil de tus mascotas (especie, etapa, peso y necesidades) y, solo si lo autorizas, datos de navegación.</p>'
		. '<h2>Para qué</h2><ul><li>Procesar y despachar tus pedidos y emitir la boleta electrónica.</li><li>Calcular recordatorios de recompra y recomendarte productos (personalización), si la mantienes activa.</li><li>Enviarte ofertas por correo o WhatsApp, solo con tu consentimiento expreso.</li></ul>'
		. '<h2>Tus derechos</h2><p>Puedes acceder, rectificar, suprimir, oponerte y pedir la portabilidad de tus datos (Ley N.º 21.719) desde <strong>Mi cuenta › Privacidad</strong>, donde también puedes desactivar la personalización y los recordatorios.</p>'
		. '<h2>Pagos</h2><p>Los pagos se procesan en las plataformas de Transbank o Mercado Pago; la tienda no almacena datos de tarjetas.</p>' );

	patitas_pagina( 'Despachos y retiro', 'despachos-y-retiro',
		'<h2>Despacho el mismo día</h2><p>Copiapó, Caldera y Tierra Amarilla: compras pagadas antes de las 14:00 se entregan el mismo día. Costo $2.990; <strong>gratis sobre $40.000</strong>.</p>'
		. '<h2>Retiro en tienda</h2><p>Av. Copayapu 1234, Copiapó. Listo en 2 horas, sin costo.</p>'
		. '<h2>Resto de Chile</h2><p>Courier a regiones en 2 a 4 días hábiles desde $5.990.</p>' );

	update_option( 'woocommerce_terms_page_id', $terminos );
	update_option( 'wp_page_for_privacy_policy', $privacidad );
	$devoluciones = (int) get_option( 'woocommerce_refund_returns_page_id' );
	if ( $devoluciones ) {
		wp_update_post( array(
			'ID'           => $devoluciones,
			'post_title'   => 'Cambios y devoluciones',
			'post_name'    => 'cambios-y-devoluciones',
			'post_status'  => 'publish',
			'post_content' => '<p>Tienes 10 días para cambios o devoluciones desde que recibes tu pedido (derecho a retracto, Ley N.º 19.496). Escríbenos por WhatsApp con tu número de pedido.</p>',
		) );
	}
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $inicio );
	update_option( 'page_for_posts', 0 );

	// Contenido de ejemplo de WordPress.
	wp_delete_post( 1, true );
	foreach ( array( 'sample-page', 'pagina-ejemplo' ) as $slug ) {
		$p = get_page_by_path( $slug );
		if ( $p ) {
			wp_delete_post( $p->ID, true );
		}
	}
}

/* ------------------------------------------------------------------ catálogo */

function patitas_crear_atributo( $nombre, $slug ) {
	if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
		$r = wc_create_attribute( array( 'name' => $nombre, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ) );
		if ( is_wp_error( $r ) ) {
			throw new Exception( 'Atributo ' . $slug . ': ' . $r->get_error_message() );
		}
	}
	$tax = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $tax ) ) {
		register_taxonomy( $tax, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false, 'labels' => array( 'name' => $nombre ) ) );
	}
	return $tax;
}

function patitas_termino( $tax, $nombre, $padre = 0 ) {
	$t = term_exists( $nombre, $tax, $padre ? $padre : null );
	if ( ! $t ) {
		$t = wp_insert_term( $nombre, $tax, $padre ? array( 'parent' => $padre ) : array() );
	}
	if ( is_wp_error( $t ) ) {
		throw new Exception( $tax . ' / ' . $nombre . ': ' . $t->get_error_message() );
	}
	return (int) $t['term_id'];
}

/** Copia una imagen del plugin a la biblioteca de medios (sin generar miniaturas, para que sea rápido). */
function patitas_adjuntar( $relativa, $titulo ) {
	$origen = PATITAS_DIR . 'assets/img/' . $relativa;
	if ( ! file_exists( $origen ) ) {
		return 0;
	}
	$up = wp_upload_dir();
	wp_mkdir_p( $up['path'] );
	$destino = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], basename( $relativa ) );
	copy( $origen, $destino );
	$id = wp_insert_attachment( array(
		'post_mime_type' => 'image/png',
		'post_title'     => $titulo,
		'post_status'    => 'inherit',
		'post_content'   => '',
	), $destino );
	$dim = @getimagesize( $destino );
	wp_update_attachment_metadata( $id, array(
		'width'  => $dim ? $dim[0] : 600,
		'height' => $dim ? $dim[1] : 600,
		'file'   => _wp_relative_upload_path( $destino ),
		'sizes'  => array(),
	) );
	update_post_meta( $id, '_wp_attachment_image_alt', $titulo );
	return (int) $id;
}

/** Precio normal y de oferta: los productos en oferta muestran el precio anterior (~15 % más alto). */
function patitas_precios( $precio, $oferta ) {
	if ( ! $oferta ) {
		return array( (int) $precio, '' );
	}
	return array( (int) ( ceil( $precio * 1.15 / 1000 ) * 1000 - 10 ), (int) $precio );
}

function patitas_textos( $d ) {
	$beneficio = array(
		'Alimento seco'   => 'Croquetas con proteína de calidad y omega 3 y 6 para una piel y un pelaje sanos.',
		'Alimento húmedo' => 'Trozos jugosos en salsa que aportan hidratación y mucho sabor.',
		'Arena'           => 'Aglomera rápido, controla los olores y facilita la limpieza diaria.',
		'Snacks'          => 'Premio ideal para entrenar y consentir, en porciones pequeñas.',
		'Farmacia'        => 'Producto veterinario: sigue siempre las indicaciones del envase y de tu veterinario.',
		'Accesorios'      => 'Diseño resistente pensado para el día a día de tu mascota.',
		'Higiene'         => 'Cuidado suave para mantener a tu mascota limpia y cómoda.',
		'Kits'            => 'Selección de productos esenciales con precio especial de kit.',
	);
	$texto_b  = $beneficio[ $d['cat'] ] ?? '';
	$etapa    = 'Todas' === $d['stage'] ? 'todas las etapas' : strtolower( $d['stage'] );
	$especie  = strtolower( $d['sp'] );
	$corta    = sprintf( '<p>%s Para %s · %s.</p>', $texto_b, $especie, $etapa );
	$formatos = implode( ', ', array_keys( $d['v'] ) );
	$larga    = sprintf(
		'<p>%s</p><ul><li><strong>Marca:</strong> %s</li><li><strong>Especie:</strong> %s</li><li><strong>Etapa de vida:</strong> %s</li><li><strong>Formatos:</strong> %s</li>%s</ul>'
		. '<p>Despacho el mismo día en Copiapó, Caldera y Tierra Amarilla para compras antes de las 14:00. Envío gratis sobre $40.000.</p>'
		. '<p><em>Producto, marca y precio ficticios creados para un prototipo académico.</em></p>',
		$texto_b, $d['brand'], $d['sp'], ucfirst( $etapa ), $formatos,
		! empty( $d['need'] ) ? '<li><strong>Necesidad especial:</strong> ' . implode( ', ', $d['need'] ) . '</li>' : ''
	);
	return array( $corta, $larga );
}

function patitas_instalar_catalogo() {
	if ( get_option( 'patitas_mapa' ) ) {
		return; // ya instalado
	}
	// Carga masiva: los conteos de términos se calculan una sola vez al final.
	update_option( 'woocommerce_attribute_lookup_direct_updates', 'no' );
	wp_defer_term_counting( true );
	$tax = array(
		'especie'   => patitas_crear_atributo( 'Especie', 'especie' ),
		'etapa'     => patitas_crear_atributo( 'Etapa de vida', 'etapa' ),
		'marca'     => patitas_crear_atributo( 'Marca', 'marca' ),
		'necesidad' => patitas_crear_atributo( 'Necesidad especial', 'necesidad' ),
	);
	// Orden fijo de términos para los filtros.
	foreach ( array( 'Perros', 'Gatos' ) as $t ) {
		patitas_termino( $tax['especie'], $t );
	}
	foreach ( array( 'Cachorro', 'Adulto', 'Senior' ) as $t ) {
		patitas_termino( $tax['etapa'], $t );
	}

	$alimento = patitas_termino( 'product_cat', 'Alimento' );
	$cats     = array(
		'Alimento seco'   => patitas_termino( 'product_cat', 'Alimento seco', $alimento ),
		'Alimento húmedo' => patitas_termino( 'product_cat', 'Alimento húmedo', $alimento ),
	);
	foreach ( array( 'Arena', 'Snacks', 'Farmacia', 'Accesorios', 'Higiene', 'Kits' ) as $c ) {
		$cats[ $c ] = patitas_termino( 'product_cat', $c );
	}

	$mapa        = array();
	$variaciones = array();
	foreach ( patitas_productos_data() as $d ) {
		$variable = count( $d['v'] ) > 1;
		$oferta   = isset( $d['tag'] ) && 'Oferta' === $d['tag'];
		$p        = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
		list( $corta, $larga ) = patitas_textos( $d );
		$p->set_name( $d['name'] );
		$p->set_status( 'publish' );
		$p->set_sku( 'PF-' . strtoupper( $d['id'] ) );
		$p->set_short_description( $corta );
		$p->set_description( $larga );
		$p->set_category_ids( array( $cats[ $d['cat'] ] ?? $alimento ) );
		if ( ! empty( $d['tag'] ) ) {
			$p->set_tag_ids( array( patitas_termino( 'product_tag', $d['tag'] ) ) );
		}
		$p->set_featured( isset( $d['tag'] ) && 'Más vendido' === $d['tag'] );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( (int) $d['stock'] );
		$p->set_stock_status( $d['stock'] > 0 ? 'instock' : 'outofstock' );
		$p->set_low_stock_amount( 5 );
		$p->set_reviews_allowed( true );
		$p->set_image_id( patitas_adjuntar( 'productos/' . $d['id'] . '.png', $d['name'] ) );

		$etapas = 'Todas' === $d['stage'] ? array( 'Cachorro', 'Adulto', 'Senior' ) : array( $d['stage'] );
		$attrs  = array();
		$pos    = 0;
		$valores = array(
			$tax['especie']   => array( $d['sp'] ),
			$tax['etapa']     => $etapas,
			$tax['marca']     => array( $d['brand'] ),
			$tax['necesidad'] => (array) ( $d['need'] ?? array() ),
		);
		foreach ( $valores as $t => $nombres ) {
			if ( ! $nombres ) {
				continue;
			}
			$a = new WC_Product_Attribute();
			$a->set_id( wc_attribute_taxonomy_id_by_name( $t ) );
			$a->set_name( $t );
			$a->set_options( array_map( function ( $n ) use ( $t ) {
				return patitas_termino( $t, $n );
			}, $nombres ) );
			$a->set_position( $pos++ );
			$a->set_visible( true );
			$a->set_variation( false );
			$attrs[ $t ] = $a;
		}
		$f = new WC_Product_Attribute();
		$f->set_id( 0 );
		$f->set_name( 'Formato' );
		$f->set_options( array_keys( $d['v'] ) );
		$f->set_position( $pos );
		$f->set_visible( true );
		$f->set_variation( $variable );
		$attrs['formato'] = $f;
		$p->set_attributes( $attrs );

		if ( ! $variable ) {
			list( $normal, $rebaja ) = patitas_precios( reset( $d['v'] ), $oferta );
			$p->set_regular_price( (string) $normal );
			if ( $rebaja ) {
				$p->set_sale_price( (string) $rebaja );
			}
			$kg = patitas_kg( $d['def'] );
			if ( $kg ) {
				$p->set_weight( (string) $kg );
			}
		} else {
			$p->set_default_attributes( array( 'formato' => $d['def'] ) );
		}
		$id = $p->save();

		if ( $variable ) {
			$i = 0;
			foreach ( $d['v'] as $formato => $precio ) {
				list( $normal, $rebaja ) = patitas_precios( $precio, $oferta );
				$v = new WC_Product_Variation();
				$v->set_parent_id( $id );
				$v->set_attributes( array( 'formato' => $formato ) );
				$v->set_regular_price( (string) $normal );
				if ( $rebaja ) {
					$v->set_sale_price( (string) $rebaja );
				}
				$v->set_sku( 'PF-' . strtoupper( $d['id'] ) . '-V' . ( ++$i ) );
				$v->set_stock_status( $d['stock'] > 0 ? 'instock' : 'outofstock' );
				$kg = patitas_kg( $formato );
				if ( $kg ) {
					$v->set_weight( (string) $kg );
				}
				$variaciones[ $d['id'] ][ $formato ] = $v->save();
			}
			WC_Product_Variable::sync( $id );
		}

		update_post_meta( $id, '_patitas_id', $d['id'] );
		update_post_meta( $id, '_pf_especie', $d['sp'] );
		update_post_meta( $id, '_pf_etapa', $d['stage'] );
		update_post_meta( $id, '_pf_categoria', $d['cat'] );
		update_post_meta( $id, '_pf_necesidad', (array) ( $d['need'] ?? array() ) );
		$mapa[ $d['id'] ] = $id;
	}
	update_option( 'patitas_mapa', $mapa );
	update_option( 'patitas_variaciones', $variaciones );
	wp_defer_term_counting( false );
	patitas_recontar_terminos();
}

/** Recalcula los conteos de categorías, etiquetas y atributos (los usan los filtros de la tienda). */
function patitas_recontar_terminos() {
	$taxonomias = array_merge( array( 'product_cat', 'product_tag' ), wc_get_attribute_taxonomy_names() );
	foreach ( $taxonomias as $tax ) {
		if ( ! taxonomy_exists( $tax ) ) {
			continue;
		}
		$tt = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'tt_ids' ) );
		if ( $tt && ! is_wp_error( $tt ) ) {
			wp_update_term_count_now( $tt, $tax );
		}
	}
	if ( function_exists( 'wc_recount_all_terms' ) ) {
		wc_recount_all_terms();
	}
}

/* ------------------------------------------------------------------ reseñas */

function patitas_instalar_resenas() {
	if ( get_option( 'patitas_resenas' ) ) {
		return;
	}
	$autores = array( 'Camila P.', 'Jorge M.', 'Valentina R.', 'Matías S.', 'Fernanda L.', 'Diego A.', 'Catalina V.', 'Ignacio T.', 'Constanza G.', 'Benjamín O.' );
	$comunas = array( 'Copiapó', 'Caldera', 'Copiapó', 'Tierra Amarilla', 'Copiapó', 'Vallenar', 'Copiapó', 'Caldera', 'Copiapó', 'Copiapó' );
	$textos  = array(
		'Alimento seco'   => array( 'Se nota en el pelaje, quedó brillante a las pocas semanas.', 'Llegó el mismo día a la casa, súper rápido.', 'Buena relación precio-calidad, ya es mi segunda compra.', 'Lo compro con suscripción y me olvido de ir a la tienda.', 'Le cayó bien desde el primer día, sin problemas de digestión.' ),
		'Alimento húmedo' => array( 'Se lo come todo, hasta deja el plato limpio.', 'Práctico el pack, me dura dos semanas.', 'Buen complemento para el alimento seco.' ),
		'Arena'           => array( 'Aglomera muy bien y no deja olor en el depa.', 'Rinde harto, me dura casi un mes con dos gatos.', 'Poco polvo, la recomiendo.' ),
		'Snacks'          => array( 'Lo uso para entrenar y funciona perfecto.', 'Le encantan, apenas abro la bolsa viene corriendo.', 'Buen tamaño para premiar.' ),
		'Farmacia'        => array( 'Fácil de aplicar y llegó el mismo día.', 'Lo recomendó mi veterinario y aquí estaba más barato.', 'Funcionó bien, sin reacciones.' ),
		'Accesorios'      => array( 'Resistente, ya lleva meses de uso.', 'Bonito y de buena calidad.', 'Tal cual la foto, lo recomiendo.' ),
		'Higiene'         => array( 'Deja el pelo suave y con buen olor.', 'Muy práctico para el día a día.', 'Buena calidad por el precio.' ),
		'Kits'            => array( 'Ideal para el primer mes con la mascota.', 'Viene todo lo necesario, buen precio.', 'Lo regalé y quedaron felices.' ),
	);
	wp_defer_comment_counting( true );
	$n = 0;
	foreach ( patitas_productos_data() as $k => $d ) {
		$id = patitas_pid( $d['id'] );
		if ( ! $id ) {
			continue;
		}
		$cantidad = 2 + ( $k % 2 );
		$lista    = $textos[ $d['cat'] ] ?? $textos['Accesorios'];
		$base     = $d['r'] >= 4.75 ? array( 5, 5, 5, 4, 5 ) : ( $d['r'] >= 4.55 ? array( 5, 4, 5, 4, 5 ) : array( 4, 5, 4, 4, 3 ) );
		for ( $i = 0; $i < $cantidad; $i++ ) {
			$a   = ( $k + $i * 3 ) % count( $autores );
			$cid = wp_insert_comment( array(
				'comment_post_ID'      => $id,
				'comment_author'       => $autores[ $a ] . ' · ' . $comunas[ $a ],
				'comment_author_email' => 'resena' . $a . '@ejemplo.cl',
				'comment_content'      => $lista[ ( $k + $i ) % count( $lista ) ],
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'comment_date'         => gmdate( 'Y-m-d H:i:s', time() - ( 5 + $i * 17 + $k ) * DAY_IN_SECONDS ),
			) );
			add_comment_meta( $cid, 'rating', $base[ $i % count( $base ) ] );
			add_comment_meta( $cid, 'verified', 1 );
			$n++;
		}
	}
	wp_defer_comment_counting( false );
	foreach ( get_option( 'patitas_mapa', array() ) as $id ) {
		if ( class_exists( 'WC_Comments' ) ) {
			WC_Comments::clear_transients( $id );
		}
	}
	update_option( 'patitas_resenas', $n );
}

/* ------------------------------------------------------------------ cliente demo */

function patitas_instalar_clientes() {
	$uid = username_exists( 'felipe' );
	if ( ! $uid ) {
		$uid = wp_insert_user( array(
			'user_login'   => 'felipe',
			'user_pass'    => 'patitas2026',
			'user_email'   => 'felipe.rojas@ejemplo.cl',
			'first_name'   => 'Felipe',
			'last_name'    => 'Rojas',
			'display_name' => 'Felipe Rojas',
			'role'         => 'customer',
		) );
		if ( is_wp_error( $uid ) ) {
			throw new Exception( $uid->get_error_message() );
		}
	}
	$c = new WC_Customer( $uid );
	foreach ( array( 'billing', 'shipping' ) as $tipo ) {
		$c->{"set_{$tipo}_first_name"}( 'Felipe' );
		$c->{"set_{$tipo}_last_name"}( 'Rojas' );
		$c->{"set_{$tipo}_address_1"}( 'Los Carrera 845, depto. 32' );
		$c->{"set_{$tipo}_city"}( 'Copiapó' );
		$c->{"set_{$tipo}_state"}( 'CL-AT' );
		$c->{"set_{$tipo}_country"}( 'CL' );
	}
	$c->set_billing_email( 'felipe.rojas@ejemplo.cl' );
	$c->set_billing_phone( '+56 9 1234 5678' );
	$c->save();
	update_user_meta( $uid, 'billing_rut', '12.345.678-5' );
	update_user_meta( $uid, 'patitas_mascotas', array(
		array( 'nombre' => 'Rocky', 'especie' => 'Perros', 'etapa' => 'Adulto', 'peso' => 32, 'necesidad' => array( 'Raza grande' ) ),
		array( 'nombre' => 'Luna', 'especie' => 'Gatos', 'etapa' => 'Adulto', 'peso' => 4, 'necesidad' => array( 'Hipoalergénico' ) ),
	) );
	update_user_meta( $uid, 'patitas_pref_personalizacion', 'si' );
	update_user_meta( $uid, 'patitas_pref_recordatorios', 'si' );
	update_user_meta( $uid, 'patitas_pref_marketing', 'si' );
	update_option( 'patitas_cliente_demo', $uid );
}

/* ------------------------------------------------------------------ pedidos de ejemplo */

function patitas_crear_pedido( $cliente, $items, $dias, $user_id = 0 ) {
	$variaciones = get_option( 'patitas_variaciones', array() );
	$pagos       = array(
		array( 'patitas_webpay', 'Webpay Plus (simulado)' ),
		array( 'patitas_webpay', 'Webpay Plus (simulado)' ),
		array( 'patitas_mercadopago', 'Mercado Pago (simulado)' ),
		array( 'bacs', 'Transferencia bancaria' ),
	);
	$pedido = wc_create_order( array( 'customer_id' => $user_id, 'created_via' => 'patitas-demo' ) );
	foreach ( $items as $it ) {
		list( $codigo, $cant ) = $it;
		$pid = patitas_pid( $codigo );
		if ( ! $pid ) {
			continue;
		}
		$formato = $it[2] ?? '';
		$vid     = $formato && isset( $variaciones[ $codigo ][ $formato ] ) ? $variaciones[ $codigo ][ $formato ] : 0;
		if ( ! $vid && isset( $variaciones[ $codigo ] ) ) {
			$vid = reset( $variaciones[ $codigo ] );
		}
		$prod = wc_get_product( $vid ? $vid : $pid );
		if ( $prod ) {
			$pedido->add_product( $prod, $cant, $vid ? array( 'variation' => $prod->get_variation_attributes() ) : array() );
		}
	}
	foreach ( array( 'billing', 'shipping' ) as $tipo ) {
		$pedido->{"set_{$tipo}_first_name"}( $cliente[0] );
		$pedido->{"set_{$tipo}_last_name"}( $cliente[1] );
		$pedido->{"set_{$tipo}_address_1"}( $cliente[3] );
		$pedido->{"set_{$tipo}_city"}( $cliente[2] );
		$pedido->{"set_{$tipo}_state"}( 'CL-AT' );
		$pedido->{"set_{$tipo}_country"}( 'CL' );
	}
	$pedido->set_billing_email( $cliente[4] );
	$pedido->set_billing_phone( '+56 9 ' . wp_rand( 5000, 9999 ) . ' ' . wp_rand( 1000, 9999 ) );
	$subtotal = 0;
	foreach ( $pedido->get_items() as $item ) {
		$subtotal += (float) $item->get_subtotal();
	}
	$envio = new WC_Order_Item_Shipping();
	$gratis = $subtotal >= PATITAS_ENVIO_GRATIS;
	$envio->set_method_title( $gratis ? 'Envío gratis el mismo día' : 'Despacho el mismo día (compras antes de las 14:00)' );
	$envio->set_method_id( $gratis ? 'free_shipping' : 'flat_rate' );
	$envio->set_total( $gratis ? 0 : 2990 );
	$pedido->add_item( $envio );
	$pago = $pagos[ mt_rand( 0, count( $pagos ) - 1 ) ];
	$pedido->set_payment_method( $pago[0] );
	$pedido->set_payment_method_title( $pago[1] );
	$t = time() - $dias * DAY_IN_SECONDS - mt_rand( 1, 8 ) * HOUR_IN_SECONDS;
	$pedido->set_date_created( $t );
	$pedido->set_date_paid( $t + 120 );
	$pedido->calculate_totals( false );
	if ( $dias > 1 ) {
		$pedido->set_date_completed( $t + 5 * HOUR_IN_SECONDS );
		$pedido->set_status( 'completed' );
	} else {
		$pedido->set_status( 'processing' );
	}
	$pedido->save();
	return $pedido;
}

function patitas_instalar_pedidos() {
	if ( get_option( 'patitas_pedidos_demo' ) ) {
		return;
	}
	// Pedidos históricos: se crean sin los procesos automáticos de WooCommerce (correos, stock, estadísticas),
	// que solo tienen sentido para pedidos nuevos. Las ventas por producto se calculan al final.
	foreach ( array( 'woocommerce_order_status_completed', 'woocommerce_order_status_processing', 'woocommerce_order_status_changed',
		'woocommerce_order_status_pending_to_processing', 'woocommerce_order_status_pending_to_completed', 'woocommerce_payment_complete',
		'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_order_object_updated_props', 'woocommerce_before_order_object_save',
		'woocommerce_after_order_object_save' ) as $gancho ) {
		remove_all_actions( $gancho );
	}
	mt_srand( 2026 );
	$perro = array(
		array( 'p1', '15 kg', array( 'p7', 'p8' ) ),
		array( 'p2', '10 kg', array( 'p30', 'p9' ) ),
		array( 'p17', '10 kg', array( 'p12', 'p48' ) ),
		array( 'p18', '3 kg', array( 'p39', 'p7' ) ),
		array( 'p16', '12 kg', array( 'p33', 'p48' ) ),
		array( 'p3', '12 kg', array( 'p8', 'p48' ) ),
	);
	$gato = array(
		array( 'p4', '7,5 kg', array( 'p6', 'p29' ) ),
		array( 'p23', '10 kg', array( 'p28', 'p42' ) ),
		array( 'p24', '2 kg', array( 'p6', 'p36' ) ),
		array( 'p21', '1,5 kg', array( 'p25', 'p34' ) ),
		array( 'p5', '', array( 'p6', 'p29' ) ),
	);
	$nombres   = array( 'Camila', 'Matías', 'Valentina', 'Benjamín', 'Fernanda', 'Diego', 'Catalina', 'Ignacio', 'Constanza', 'Tomás', 'Javiera', 'Vicente', 'Antonia', 'Martín', 'Josefa', 'Lucas', 'Isidora', 'Joaquín', 'Florencia', 'Sebastián', 'Trinidad', 'Cristóbal', 'Emilia', 'Agustín', 'Paula' );
	$apellidos = array( 'González', 'Muñoz', 'Rojas', 'Díaz', 'Pérez', 'Soto', 'Contreras', 'Silva', 'Martínez', 'Sepúlveda', 'Morales', 'Rodríguez', 'López', 'Fuentes', 'Hernández', 'Torres', 'Araya', 'Flores', 'Espinoza', 'Valenzuela', 'Castillo', 'Tapia', 'Reyes', 'Gutiérrez', 'Castro' );
	$comunas   = array( 'Copiapó', 'Copiapó', 'Caldera', 'Copiapó', 'Tierra Amarilla' );
	$tipos     = array_merge( array_fill( 0, 7, 'leal' ), array_fill( 0, 6, 'ofertas' ), array_fill( 0, 6, 'nuevo' ), array_fill( 0, 6, 'riesgo' ) );
	$total     = 0;
	foreach ( $tipos as $i => $tipo ) {
		$cliente = array( $nombres[ $i ], $apellidos[ $i ], $comunas[ $i % 5 ], 'Calle Ficticia ' . ( 100 + $i * 7 ), 'cliente' . ( $i + 1 ) . '@ejemplo.cl' );
		$base    = 0 === $i % 2 ? $perro[ $i % count( $perro ) ] : $gato[ $i % count( $gato ) ];
		$armar   = function ( $extra = array() ) use ( $base ) {
			$items = array( array( $base[0], 1, $base[1] ) );
			$comp  = $base[2];
			$items[] = array( $comp[ mt_rand( 0, 1 ) ], mt_rand( 1, 2 ) );
			if ( mt_rand( 0, 2 ) === 0 ) {
				$items[] = array( $comp[0] === $items[1][0] ? $comp[1] : $comp[0], 1 );
			}
			foreach ( $extra as $e ) {
				$items[] = array( $e, 1 );
			}
			return $items;
		};
		switch ( $tipo ) {
			case 'leal':
				for ( $d = 165 - $i * 3; $d >= 8; $d -= mt_rand( 27, 34 ) ) {
					patitas_crear_pedido( $cliente, $armar(), $d );
					$total++;
				}
				break;
			case 'ofertas':
				foreach ( array( 140 - $i * 4, 80 - $i * 3, 30 - $i ) as $d ) {
					$ofertas = array( 'p6', 'p18', 'p29', 'p38' );
					patitas_crear_pedido( $cliente, array( array( $ofertas[ mt_rand( 0, 3 ) ], mt_rand( 1, 3 ) ), array( $ofertas[ mt_rand( 0, 3 ) ], 1 ) ), $d );
					$total++;
				}
				break;
			case 'nuevo':
				$kit = 0 === $i % 2 ? 'p14' : 'p40';
				patitas_crear_pedido( $cliente, $armar( array( $kit ) ), 2 + $i % 12 );
				$total++;
				break;
			default: // en riesgo
				foreach ( array( 175 - $i, 130 - $i ) as $d ) {
					patitas_crear_pedido( $cliente, $armar(), $d );
					$total++;
				}
		}
	}
	// Historial del cliente demo Felipe (Rocky, perro de 32 kg; Luna, gata de 4 kg).
	$uid     = (int) get_option( 'patitas_cliente_demo' );
	$felipe  = array( 'Felipe', 'Rojas', 'Copiapó', 'Los Carrera 845, depto. 32', 'felipe.rojas@ejemplo.cl' );
	$pedidos = array(
		array( 140, array( array( 'p1', 1, '15 kg' ), array( 'p8', 1 ) ) ),
		array( 100, array( array( 'p1', 1, '15 kg' ), array( 'p7', 2 ) ) ),
		array( 63, array( array( 'p1', 1, '15 kg' ), array( 'p4', 1, '7,5 kg' ), array( 'p6', 1, '10 kg' ) ) ),
		array( 26, array( array( 'p1', 1, '15 kg' ), array( 'p7', 2 ) ) ),
	);
	foreach ( $pedidos as $p ) {
		patitas_crear_pedido( $felipe, $p[1], $p[0], $uid );
		$total++;
	}
	// Ventas acumuladas por producto (popularidad del recomendador y orden «Más vendidos»).
	$ventas = array();
	foreach ( patitas_pedidos() as $pedido ) {
		foreach ( $pedido->get_items() as $item ) {
			$ventas[ $item->get_product_id() ] = ( $ventas[ $item->get_product_id() ] ?? 0 ) + $item->get_quantity();
		}
	}
	foreach ( $ventas as $id => $n ) {
		update_post_meta( $id, 'total_sales', $n );
	}
	update_option( 'patitas_pedidos_demo', $total );
}

/* ------------------------------------------------------------------ envíos, pagos y cupones */

function patitas_metodo_envio( $zona, $tipo, $ajustes ) {
	$instancia = $zona->add_shipping_method( $tipo );
	update_option( "woocommerce_{$tipo}_{$instancia}_settings", $ajustes );
}

function patitas_instalar_envios() {
	if ( WC_Shipping_Zones::get_zones() ) {
		return;
	}
	$z = new WC_Shipping_Zone();
	$z->set_zone_name( 'Copiapó, Caldera y Tierra Amarilla (Región de Atacama)' );
	$z->set_zone_order( 1 );
	$z->add_location( 'CL:CL-AT', 'state' );
	$z->save();
	patitas_metodo_envio( $z, 'flat_rate', array( 'title' => 'Despacho el mismo día (compras antes de las 14:00)', 'tax_status' => 'none', 'cost' => '2990' ) );
	patitas_metodo_envio( $z, 'free_shipping', array( 'title' => 'Envío gratis el mismo día', 'requires' => 'min_amount', 'min_amount' => (string) PATITAS_ENVIO_GRATIS, 'ignore_discounts' => 'no' ) );
	patitas_metodo_envio( $z, 'local_pickup', array( 'title' => 'Retiro en tienda (listo en 2 horas)', 'tax_status' => 'none', 'cost' => '0' ) );

	$r = new WC_Shipping_Zone();
	$r->set_zone_name( 'Resto de Chile' );
	$r->set_zone_order( 2 );
	$r->add_location( 'CL', 'country' );
	$r->save();
	patitas_metodo_envio( $r, 'flat_rate', array( 'title' => 'Courier a regiones (2 a 4 días hábiles)', 'tax_status' => 'none', 'cost' => '5990' ) );
	patitas_metodo_envio( $r, 'free_shipping', array( 'title' => 'Courier gratis a regiones', 'requires' => 'min_amount', 'min_amount' => '60000', 'ignore_discounts' => 'no' ) );
}

function patitas_instalar_pagos() {
	update_option( 'woocommerce_patitas_webpay_settings', array( 'enabled' => 'yes' ) );
	update_option( 'woocommerce_patitas_mercadopago_settings', array( 'enabled' => 'yes' ) );
	update_option( 'woocommerce_bacs_settings', array(
		'enabled'      => 'yes',
		'title'        => 'Transferencia bancaria',
		'description'  => 'Transfiere a la cuenta indicada al finalizar la compra; despachamos al confirmar el pago.',
		'instructions' => 'Cuenta ficticia del prototipo: Banco Ejemplo, cuenta corriente 00-123-45678-9, Patitas Felices SpA.',
	) );
	update_option( 'woocommerce_bacs_accounts', array( array(
		'account_name'   => 'Patitas Felices SpA',
		'account_number' => '00-123-45678-9',
		'bank_name'      => 'Banco Ejemplo (ficticio)',
		'sort_code'      => '',
		'iban'           => '',
		'bic'            => '',
	) ) );
	update_option( 'woocommerce_cod_settings', array( 'enabled' => 'no' ) );
	update_option( 'woocommerce_cheque_settings', array( 'enabled' => 'no' ) );
	update_option( 'woocommerce_gateway_order', array( 'patitas_webpay' => 0, 'patitas_mercadopago' => 1, 'bacs' => 2, 'cheque' => 3, 'cod' => 4 ) );
}

function patitas_instalar_cupones() {
	$cupones = array(
		array( 'BIENVENIDA10', 'percent', 10, '10 % en la primera compra (cuenta nueva).', 0, 1 ),
		array( 'VUELVE10', 'percent', 10, 'Cupón de regreso para el segmento «En riesgo de abandono».', 0, 1 ),
		array( 'AMIGO5000', 'fixed_cart', 5000, 'Programa «Invita a un amigo»: $5.000 en compras sobre $25.000.', 25000, 1 ),
	);
	foreach ( $cupones as $c ) {
		if ( wc_get_coupon_id_by_code( $c[0] ) ) {
			continue;
		}
		$cp = new WC_Coupon();
		$cp->set_code( $c[0] );
		$cp->set_discount_type( $c[1] );
		$cp->set_amount( $c[2] );
		$cp->set_description( $c[3] );
		if ( $c[4] ) {
			$cp->set_minimum_amount( $c[4] );
		}
		$cp->set_usage_limit_per_user( $c[5] );
		$cp->set_individual_use( true );
		$cp->save();
	}
}

/* ------------------------------------------------------------------ apariencia: colores, logo, menús y widgets */

function patitas_instalar_apariencia() {
	$mods = array(
		'storefront_heading_color'               => '#14323A',
		'storefront_text_color'                  => '#4A6168',
		'storefront_accent_color'                => '#1E6B66',
		'storefront_header_background_color'     => '#ffffff',
		'storefront_header_text_color'           => '#4A6168',
		'storefront_header_link_color'           => '#14323A',
		'storefront_footer_background_color'     => '#14323A',
		'storefront_footer_heading_color'        => '#ffffff',
		'storefront_footer_text_color'           => '#D5E3E1',
		'storefront_footer_link_color'           => '#ffffff',
		'storefront_button_background_color'     => '#1E6B66',
		'storefront_button_text_color'           => '#ffffff',
		'storefront_button_alt_background_color' => '#C2187A',
		'storefront_button_alt_text_color'       => '#ffffff',
		'storefront_layout'                      => 'left', // filtros a la izquierda, como el prototipo
		'background_color'                       => 'f5f8f7',
	);
	foreach ( $mods as $k => $v ) {
		set_theme_mod( $k, $v );
	}
	if ( ! get_theme_mod( 'custom_logo' ) ) {
		$logo = patitas_adjuntar( 'logo.png', 'Patitas Felices' );
		if ( $logo ) {
			set_theme_mod( 'custom_logo', $logo );
		}
	}

	// Menú principal.
	$menu = wp_get_nav_menu_object( 'Principal' );
	if ( ! $menu ) {
		$menu_id = wp_create_nav_menu( 'Principal' );
		foreach ( array( 'Perros' => 'perros', 'Gatos' => 'gatos' ) as $titulo => $slug ) {
			wp_update_nav_menu_item( $menu_id, 0, array(
				'menu-item-title'  => $titulo,
				'menu-item-url'    => '#pf-especie-' . $slug, // se convierte en el enlace real al mostrar el menú
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			) );
		}
		foreach ( array( 'Alimento', 'Arena', 'Snacks', 'Farmacia', 'Accesorios', 'Higiene', 'Kits' ) as $c ) {
			$t = get_term_by( 'name', $c, 'product_cat' );
			if ( $t ) {
				wp_update_nav_menu_item( $menu_id, 0, array(
					'menu-item-title'     => $c,
					'menu-item-object'    => 'product_cat',
					'menu-item-object-id' => $t->term_id,
					'menu-item-type'      => 'taxonomy',
					'menu-item-status'    => 'publish',
				) );
			}
		}
		$oferta = get_term_by( 'name', 'Oferta', 'product_tag' );
		if ( $oferta ) {
			wp_update_nav_menu_item( $menu_id, 0, array(
				'menu-item-title'     => 'Ofertas',
				'menu-item-object'    => 'product_tag',
				'menu-item-object-id' => $oferta->term_id,
				'menu-item-type'      => 'taxonomy',
				'menu-item-classes'   => 'pf-menu-ofertas',
				'menu-item-status'    => 'publish',
			) );
		}
		$secundario = wp_create_nav_menu( 'Ayuda' );
		foreach ( array( wc_get_page_id( 'myaccount' ) => 'Mi cuenta', get_page_by_path( 'despachos-y-retiro' )->ID => 'Despachos y retiro' ) as $pid => $titulo ) {
			wp_update_nav_menu_item( $secundario, 0, array(
				'menu-item-title'     => $titulo,
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $pid,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			) );
		}
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu_id, 'handheld' => $menu_id, 'secondary' => $secundario ) );
	}

	// Barra lateral de la tienda con filtros nativos de WooCommerce y pie de página.
	update_option( 'widget_woocommerce_layered_nav_filters', array( 2 => array( 'title' => 'Filtros activos' ), '_multiwidget' => 1 ) );
	update_option( 'widget_woocommerce_product_categories', array( 2 => array( 'title' => 'Categorías', 'orderby' => 'name', 'dropdown' => 0, 'count' => 1, 'hierarchical' => 1, 'show_children_only' => 0, 'hide_empty' => 1, 'max_depth' => '' ), '_multiwidget' => 1 ) );
	$filtro = function ( $titulo, $atributo ) {
		return array( 'title' => $titulo, 'attribute' => $atributo, 'display_type' => 'list', 'query_type' => 'or' );
	};
	update_option( 'widget_woocommerce_layered_nav', array(
		2              => $filtro( 'Especie', 'especie' ),
		3              => $filtro( 'Etapa de vida', 'etapa' ),
		4              => $filtro( 'Marca', 'marca' ),
		5              => $filtro( 'Necesidad especial', 'necesidad' ),
		'_multiwidget' => 1,
	) );
	update_option( 'widget_woocommerce_price_filter', array( 2 => array( 'title' => 'Precio' ), '_multiwidget' => 1 ) );
	// {{inicio}} se reemplaza por la dirección del sitio al mostrar el pie (cada sesión de Playground tiene otra URL).
	$a = function ( $ruta, $texto ) {
		return '<a href="{{inicio}}' . esc_attr( $ruta ) . '">' . $texto . '</a>';
	};
	update_option( 'widget_text', array(
		2              => array( 'title' => 'Patitas Felices SpA', 'text' => 'Tienda para mascotas en Copiapó desde 2019. Despacho el mismo día en Copiapó, Caldera y Tierra Amarilla.<br><small>Sitio de ejemplo para evaluación académica (INACAP).</small>', 'filter' => true, 'visual' => true ),
		3              => array( 'title' => 'Compra', 'text' => $a( '/tienda/', 'Catálogo' ) . '<br>' . $a( '/mi-cuenta/orders/', 'Seguimiento de pedido' ) . '<br>' . $a( '/mi-cuenta/suscripciones/', 'Suscripciones' ), 'filter' => true, 'visual' => true ),
		4              => array( 'title' => 'Ayuda', 'text' => $a( '/despachos-y-retiro/', 'Despachos y retiro' ) . '<br>' . $a( '/cambios-y-devoluciones/', 'Cambios y derecho a retracto (10 días)' ) . '<br>WhatsApp +56 9 0000 0000', 'filter' => true, 'visual' => true ),
		5              => array( 'title' => 'Legal y redes', 'text' => $a( '/terminos-y-condiciones/', 'Términos y condiciones' ) . '<br>' . $a( '/politica-de-privacidad/', 'Política de privacidad' ) . '<br>Instagram · TikTok · Facebook: @patitasfelices.cpy', 'filter' => true, 'visual' => true ),
		'_multiwidget' => 1,
	) );
	update_option( 'sidebars_widgets', array(
		'wp_inactive_widgets' => array(),
		'sidebar-1'           => array( 'woocommerce_layered_nav_filters-2', 'woocommerce_product_categories-2', 'woocommerce_layered_nav-2', 'woocommerce_layered_nav-3', 'woocommerce_layered_nav-4', 'woocommerce_layered_nav-5', 'woocommerce_price_filter-2' ),
		'header-1'            => array(),
		'footer-1'            => array( 'text-2' ),
		'footer-2'            => array( 'text-3' ),
		'footer-3'            => array( 'text-4' ),
		'footer-4'            => array( 'text-5' ),
		'array_version'       => 3,
	) );
}
