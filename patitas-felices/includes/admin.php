<?php
/**
 * Panel de administración «WooCommerce › Patitas Felices»: resultados de la capa de machine learning.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Patitas Felices: ML y recomendador', 'Patitas Felices (ML)', 'manage_woocommerce', 'patitas-ml', 'patitas_admin_pagina' );
}, 99 );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false !== strpos( $hook, 'patitas-ml' ) ) {
		wp_enqueue_style( 'patitas-admin', PATITAS_URL . 'assets/patitas-admin.css', array(), PATITAS_VER );
	}
} );

add_action( 'admin_post_patitas_recalcular', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'patitas_recalcular' ) ) {
		wp_die( 'Sin permiso.' );
	}
	patitas_recalcular();
	wp_safe_redirect( admin_url( 'admin.php?page=patitas-ml&recalculado=1' ) );
	exit;
} );

add_action( 'admin_post_patitas_instalar', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'patitas_instalar' ) ) {
		wp_die( 'Sin permiso.' );
	}
	patitas_instalar();
	wp_safe_redirect( admin_url( 'admin.php?page=patitas-ml' ) );
	exit;
} );

function patitas_admin_pagina() {
	echo '<div class="wrap pf-admin"><h1>Patitas Felices: machine learning y recomendador</h1>';
	if ( ! get_option( 'patitas_instalado' ) ) {
		printf( '<div class="notice notice-warning"><p>La tienda demo aún no tiene datos. <a class="button button-primary" href="%s">Cargar catálogo y datos de ejemplo</a></p></div></div>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=patitas_instalar' ), 'patitas_instalar' ) ) );
		return;
	}
	if ( ! empty( $_GET['recalculado'] ) ) { // phpcs:ignore
		echo '<div class="notice notice-success is-dismissible"><p>Modelo recalculado con los pedidos actuales.</p></div>';
	}
	$ultimo = (int) get_option( 'patitas_recalculo' );
	$prox   = wp_next_scheduled( 'patitas_recalcular_diario' );
	printf( '<p>Último cálculo: <b>%s</b>. Próximo cálculo automático (WP-Cron): <b>%s</b>. <a class="button" href="%s">Recalcular ahora</a></p>',
		esc_html( $ultimo ? wp_date( 'j \d\e F, H:i', $ultimo ) : 'nunca' ),
		esc_html( $prox ? wp_date( 'j \d\e F, H:i', $prox ) : 'no programado' ),
		esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=patitas_recalcular' ), 'patitas_recalcular' ) ) );

	/* 1. Segmentación */
	$seg = get_option( 'patitas_segmentos', array() );
	echo '<h2>1. Segmentación de clientes (K-means sobre RFM)</h2>';
	echo '<p>Variables por cliente: <b>R</b> = días desde la última compra, <b>F</b> = número de pedidos, <b>M</b> = monto total (en logaritmo). Se estandarizan (z-score) y se prueba k = 2 a 5; se elige el k con mayor coeficiente de silueta.</p>';
	if ( $seg ) {
		echo '<table class="widefat striped pf-tabla"><thead><tr><th>k</th>';
		foreach ( $seg['silueta'] as $k => $s ) {
			printf( '<th>%d</th>', (int) $k );
		}
		echo '</tr></thead><tbody><tr><td>Silueta</td>';
		foreach ( $seg['silueta'] as $k => $s ) {
			printf( '<td%s>%s</td>', $k === $seg['k'] ? ' class="pf-elegido"' : '', esc_html( number_format( $s, 3, ',', '.' ) ) );
		}
		echo '</tr></tbody></table>';
		printf( '<p>Se analizaron <b>%d clientes</b>; k elegido = <b>%d</b>.</p>', (int) $seg['n'], (int) $seg['k'] );
		echo '<table class="widefat striped pf-tabla"><thead><tr><th>Segmento</th><th>Clientes</th><th>R prom. (días)</th><th>F prom. (pedidos)</th><th>M prom.</th><th>Acción sugerida</th><th>Ejemplos</th></tr></thead><tbody>';
		foreach ( $seg['grupos'] as $g ) {
			$ej = implode( ', ', array_slice( wp_list_pluck( $g['clientes'], 'nombre' ), 0, 3 ) );
			printf( '<tr><td><b>%s</b></td><td>%d</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $g['nombre'] ), (int) $g['n'], (int) $g['r'], esc_html( number_format( $g['f'], 1, ',', '.' ) ), esc_html( patitas_clp( $g['m'] ) ), esc_html( $g['accion'] ), esc_html( $ej ) );
		}
		echo '</tbody></table>';
	} else {
		echo '<p>No hay suficientes clientes para segmentar.</p>';
	}

	/* 2. Recompra */
	echo '<h2>2. Predicción de recompra de alimento</h2>';
	echo '<p>Regla de consumo: ración diaria ≈ 12,5 g por kg (perros) o 15 g por kg (gatos) según el perfil de la mascota; duración = kilos comprados ÷ ración. Sin perfil, se usa el intervalo promedio entre compras del cliente.</p>';
	$filas = patitas_admin_recompras();
	if ( $filas ) {
		echo '<table class="widefat striped pf-tabla"><thead><tr><th>Cliente</th><th>Producto</th><th>Última compra</th><th>Método</th><th>Fin estimado</th><th>Estado</th></tr></thead><tbody>';
		foreach ( array_slice( $filas, 0, 12 ) as $f ) {
			$estado = $f['dias'] < 0 ? '<span class="pf-rojo">Atrasado ' . abs( $f['dias'] ) . ' días</span>' : ( $f['dias'] <= 5 ? '<span class="pf-ambar">Enviar recordatorio</span>' : 'En ' . $f['dias'] . ' días' );
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $f['cliente'] ), esc_html( $f['producto'] ), esc_html( wp_date( 'j M Y', $f['compra'] ) ), esc_html( $f['metodo'] ), esc_html( wp_date( 'j M Y', $f['fin'] ) ), wp_kses_post( $estado ) );
		}
		echo '</tbody></table>';
	}

	/* 3. Recomendador */
	$co    = get_option( 'patitas_cocompras', array() );
	$pares = array();
	foreach ( $co as $a => $lista ) {
		foreach ( $lista as $b => $n ) {
			if ( $a < $b ) {
				$pares[] = array( $a, $b, $n );
			}
		}
	}
	usort( $pares, function ( $x, $y ) {
		return $y[2] <=> $x[2];
	} );
	echo '<h2>3. Recomendador híbrido</h2>';
	printf( '<p>Puntaje = <b>%d %%</b> colaborativo (compras conjuntas) + <b>%d %%</b> contenido (especie, etapa y necesidad de la mascota) + <b>%d %%</b> popularidad local. Antes de puntuar se aplican reglas de negocio: solo productos con stock y de la especie de las mascotas del cliente.</p>',
		PATITAS_PESO_COLAB * 100, PATITAS_PESO_CONTENIDO * 100, PATITAS_PESO_POPULAR * 100 );
	echo '<table class="widefat striped pf-tabla"><thead><tr><th>Producto A</th><th>Producto B</th><th>Pedidos en que se compraron juntos</th></tr></thead><tbody>';
	foreach ( array_slice( $pares, 0, 10 ) as $p ) {
		printf( '<tr><td>%s</td><td>%s</td><td>%d</td></tr>', esc_html( get_the_title( $p[0] ) ), esc_html( get_the_title( $p[1] ) ), (int) $p[2] );
	}
	echo '</tbody></table>';
	$demo = (int) get_option( 'patitas_cliente_demo' );
	if ( $demo ) {
		$recos = patitas_recomendar( array( 'contexto' => patitas_historial( $demo ), 'mascotas' => patitas_mascotas( $demo ), 'limite' => 5 ) );
		echo '<h3>Ejemplo: recomendaciones para el cliente demo (Felipe: Rocky y Luna)</h3><table class="widefat striped pf-tabla"><thead><tr><th>Producto</th><th>Puntaje</th><th>Explicación mostrada al cliente</th></tr></thead><tbody>';
		foreach ( $recos as $r ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( get_the_title( $r['id'] ) ), esc_html( number_format( $r['puntaje'], 3, ',', '.' ) ), esc_html( $r['razon'] ) );
		}
		echo '</tbody></table>';
	}
	echo '<p class="description">Datos ficticios generados para el prototipo académico. En producción el cálculo se ejecuta cada noche y se evalúa con precisión@5, CTR del bloque y una prueba A/B.</p></div>';
}

/** Recompras previstas para todos los clientes con historial de alimento. */
function patitas_admin_recompras() {
	$filas    = array();
	$clientes = array();
	foreach ( patitas_pedidos( array( 'orderby' => 'date', 'order' => 'ASC' ) ) as $pedido ) {
		$correo = strtolower( (string) $pedido->get_billing_email() );
		foreach ( $pedido->get_items() as $item ) {
			$f = patitas_ficha( $item->get_product_id() );
			if ( patitas_es_alimento( $f['categoria'] ) && $pedido->get_date_created() ) {
				$clientes[ $correo ]['nombre']    = trim( $pedido->get_billing_first_name() . ' ' . $pedido->get_billing_last_name() );
				$clientes[ $correo ]['user']      = $pedido->get_customer_id();
				$clientes[ $correo ]['fechas'][]  = $pedido->get_date_created()->getTimestamp();
				$clientes[ $correo ]['producto']  = $item->get_name();
				break;
			}
		}
	}
	foreach ( $clientes as $c ) {
		$ultima = end( $c['fechas'] );
		if ( $c['user'] && patitas_mascotas( $c['user'] ) ) {
			$r = patitas_recompras( $c['user'] );
			if ( $r ) {
				$filas[] = array( 'cliente' => $c['nombre'], 'producto' => get_the_title( $r[0]['producto'] ) . ' ' . $r[0]['formato'], 'compra' => $r[0]['compra'], 'metodo' => 'Perfil de ' . $r[0]['mascota']['nombre'], 'fin' => $r[0]['fin'], 'dias' => $r[0]['dias'] );
				continue;
			}
		}
		if ( count( $c['fechas'] ) < 2 ) {
			continue;
		}
		$intervalo = ( $ultima - $c['fechas'][0] ) / ( count( $c['fechas'] ) - 1 );
		$fin       = $ultima + $intervalo;
		$filas[]   = array( 'cliente' => $c['nombre'], 'producto' => $c['producto'], 'compra' => $ultima, 'metodo' => 'Intervalo promedio (' . round( $intervalo / DAY_IN_SECONDS ) . ' días)', 'fin' => $fin, 'dias' => (int) floor( ( $fin - time() ) / DAY_IN_SECONDS ) );
	}
	usort( $filas, function ( $a, $b ) {
		return $a['fin'] <=> $b['fin'];
	} );
	return $filas;
}
