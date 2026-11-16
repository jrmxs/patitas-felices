<?php
/**
 * Capa de machine learning de la tienda (sección 4 del informe):
 *  - Recomendador híbrido: reglas + contenido + colaborativo ítem a ítem (50 % colaborativo, 30 % contenido, 20 % popularidad).
 *  - Predicción de la fecha de recompra de alimento.
 *  - Segmentación de clientes con K-means sobre variables RFM (k elegido con el coeficiente de silueta).
 * El cálculo pesado (matriz de compras conjuntas) se recalcula en un proceso diario de WP-Cron.
 */

defined( 'ABSPATH' ) || exit;

const PATITAS_PESO_COLAB     = 0.5;
const PATITAS_PESO_CONTENIDO = 0.3;
const PATITAS_PESO_POPULAR   = 0.2;

/* ------------------------------------------------------------------ proceso diario */

add_action( 'patitas_recalcular_diario', 'patitas_recalcular' );

/** Pedidos válidos para el análisis (completados o en preparación). */
function patitas_pedidos( $args = array() ) {
	return wc_get_orders( array_merge( array(
		'limit'  => -1,
		'status' => array( 'wc-completed', 'wc-processing' ),
		'type'   => 'shop_order',
	), $args ) );
}

/** Recalcula la matriz de compras conjuntas y la segmentación; guarda los resultados. */
function patitas_recalcular() {
	$co = array();
	foreach ( patitas_pedidos() as $pedido ) {
		$ids = array();
		foreach ( $pedido->get_items() as $item ) {
			$ids[] = (int) $item->get_product_id();
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		foreach ( $ids as $a ) {
			foreach ( $ids as $b ) {
				if ( $a !== $b ) {
					$co[ $a ][ $b ] = ( $co[ $a ][ $b ] ?? 0 ) + 1;
				}
			}
		}
	}
	update_option( 'patitas_cocompras', $co, false );
	update_option( 'patitas_segmentos', patitas_segmentar(), false );
	update_option( 'patitas_recalculo', time(), false );
	delete_transient( 'patitas_catalogo' );
}

/* ------------------------------------------------------------------ catálogo */

/** Datos mínimos de cada producto para puntuar (en caché 1 hora). */
function patitas_catalogo() {
	$cat = get_transient( 'patitas_catalogo' );
	if ( is_array( $cat ) ) {
		return $cat;
	}
	$cat = array();
	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) {
		$f           = patitas_ficha( $id );
		$f['ventas'] = (int) get_post_meta( $id, 'total_sales', true );
		$f['stock']  = 'outofstock' !== get_post_meta( $id, '_stock_status', true );
		$f['nombre'] = get_the_title( $id );
		$marca       = wp_get_post_terms( $id, 'pa_marca', array( 'fields' => 'names' ) );
		$f['marca']  = ( $marca && ! is_wp_error( $marca ) ) ? $marca[0] : '';
		$prod        = wc_get_product( $id );
		$f['precio'] = $prod ? (float) patitas_precio_defecto( $prod ) : 0;
		// Valores de cada faceta del catálogo (slugs, como en las URL de filtros de WooCommerce).
		$etapas    = 'Todas' === $f['etapa'] ? array( 'Cachorro', 'Adulto', 'Senior' ) : array( $f['etapa'] );
		$f['facetas'] = array(
			'especie'   => array( sanitize_title( $f['especie'] ) ),
			'categoria' => array( sanitize_title( $f['categoria'] ) ),
			'etapa'     => array_map( 'sanitize_title', $etapas ),
			'marca'     => $f['marca'] ? array( sanitize_title( $f['marca'] ) ) : array(),
			'necesidad' => array_map( 'sanitize_title', (array) $f['necesidad'] ),
		);
		$cat[ $id ] = $f;
	}
	set_transient( 'patitas_catalogo', $cat, HOUR_IN_SECONDS );
	return $cat;
}

add_action( 'woocommerce_update_product', function () {
	delete_transient( 'patitas_catalogo' );
} );

/* ------------------------------------------------------------------ recomendador */

/**
 * Recomienda productos.
 *
 * @param array $args contexto (IDs de productos vistos, en el carrito o comprados), mascotas, excluir, limite.
 * @return array lista de [ 'id' => int, 'puntaje' => float, 'razon' => string ].
 */
function patitas_recomendar( $args = array() ) {
	$args = wp_parse_args( $args, array(
		'contexto' => array(),
		'mascotas' => array(),
		'excluir'  => array(),
		'limite'   => 4,
		'especie'  => '',
	) );
	$catalogo = patitas_catalogo();
	$co       = get_option( 'patitas_cocompras', array() );
	$contexto = array_map( 'intval', (array) $args['contexto'] );
	$excluir  = array_merge( array_map( 'intval', (array) $args['excluir'] ), $contexto );
	$especies = array_unique( array_filter( array_merge( wp_list_pluck( $args['mascotas'], 'especie' ), array( $args['especie'] ) ) ) );

	$max_ventas = max( 1, max( array_merge( array( 1 ), wp_list_pluck( $catalogo, 'ventas' ) ) ) );
	$filas      = array();
	$max_colab  = 0;

	foreach ( $catalogo as $id => $p ) {
		// 1) Reglas de negocio: con stock, especie de las mascotas y no repetir lo que ya está en contexto.
		if ( ! $p['stock'] || in_array( $id, $excluir, true ) ) {
			continue;
		}
		if ( $especies && ! in_array( $p['especie'], $especies, true ) ) {
			continue;
		}
		// 2) Colaborativo ítem a ítem: veces que se compró junto a los productos del contexto.
		$colab   = 0;
		$origen  = 0;
		foreach ( $contexto as $c ) {
			$n = $co[ $c ][ $id ] ?? 0;
			if ( $n > $colab ) {
				$origen = $c;
			}
			$colab += $n;
		}
		$max_colab = max( $max_colab, $colab );
		// 3) Contenido: coincidencia con el perfil de la mascota o con el producto de contexto.
		$contenido = 0;
		$para      = '';
		foreach ( $args['mascotas'] as $m ) {
			$s = patitas_similitud( $p, $m['especie'] ?? '', $m['etapa'] ?? '', (array) ( $m['necesidad'] ?? array() ) );
			if ( $s > $contenido ) {
				$contenido = $s;
				$para      = $m['nombre'] ?? '';
			}
		}
		foreach ( $contexto as $c ) {
			if ( isset( $catalogo[ $c ] ) ) {
				$r = $catalogo[ $c ];
				$s = patitas_similitud( $p, $r['especie'], $r['etapa'], $r['necesidad'] ) * ( $p['categoria'] === $r['categoria'] ? 0.6 : 1 );
				$contenido = max( $contenido, $s );
			}
		}
		$filas[ $id ] = array( 'colab' => $colab, 'origen' => $origen, 'contenido' => $contenido, 'para' => $para, 'pop' => $p['ventas'] / $max_ventas );
	}

	$salida = array();
	foreach ( $filas as $id => $f ) {
		$colab   = $max_colab ? $f['colab'] / $max_colab : 0;
		$puntaje = PATITAS_PESO_COLAB * $colab + PATITAS_PESO_CONTENIDO * $f['contenido'] + PATITAS_PESO_POPULAR * $f['pop'];
		if ( $colab >= 0.4 && $f['origen'] ) {
			$razon = sprintf( 'Clientes que compraron «%s» también compraron este producto.', $catalogo[ $f['origen'] ]['nombre'] ?? '' );
		} elseif ( $f['para'] && $f['contenido'] >= 0.75 ) {
			$razon = sprintf( 'Coincide con el perfil de %s (especie, etapa y necesidades).', $f['para'] );
		} elseif ( $f['contenido'] >= 0.5 ) {
			$razon = 'Es similar a lo que estás viendo o compraste antes.';
		} else {
			$razon = 'Es de lo más vendido en Copiapó esta semana.';
		}
		$salida[] = array( 'id' => $id, 'puntaje' => round( $puntaje, 3 ), 'razon' => $razon, 'para' => $f['para'] );
	}
	usort( $salida, function ( $a, $b ) {
		return $b['puntaje'] <=> $a['puntaje'];
	} );
	return array_slice( $salida, 0, (int) $args['limite'] );
}

/**
 * Orden «Recomendados para ti» del catálogo: el mismo puntaje híbrido aplicado a todos los productos
 * (sin excluir ninguno); los agotados quedan al final.
 */
function patitas_ranking( $user_id = 0 ) {
	$catalogo = patitas_catalogo();
	$mascotas = ( $user_id && patitas_pref( 'personalizacion', $user_id ) ) ? patitas_mascotas( $user_id ) : array();
	$historia = ( $user_id && patitas_pref( 'personalizacion', $user_id ) ) ? patitas_historial( $user_id ) : array();
	$co       = get_option( 'patitas_cocompras', array() );
	$max_v    = max( 1, max( array_merge( array( 1 ), wp_list_pluck( $catalogo, 'ventas' ) ) ) );
	$colab    = array();
	foreach ( $catalogo as $id => $p ) {
		$colab[ $id ] = 0;
		foreach ( $historia as $h ) {
			$colab[ $id ] += $co[ $h ][ $id ] ?? 0;
		}
	}
	$max_c   = max( 1, max( array_merge( array( 0 ), $colab ) ) );
	$puntaje = array();
	foreach ( $catalogo as $id => $p ) {
		$contenido = 0;
		foreach ( $mascotas as $m ) {
			$contenido = max( $contenido, patitas_similitud( $p, $m['especie'] ?? '', $m['etapa'] ?? '', (array) ( $m['necesidad'] ?? array() ) ) );
		}
		$puntaje[ $id ] = PATITAS_PESO_COLAB * $colab[ $id ] / $max_c + PATITAS_PESO_CONTENIDO * $contenido + PATITAS_PESO_POPULAR * $p['ventas'] / $max_v - ( $p['stock'] ? 0 : 10 );
	}
	arsort( $puntaje );
	return array_keys( $puntaje );
}

/** Similitud de contenido (0 a 1): especie 0,5 + etapa 0,25 + necesidad 0,25. */
function patitas_similitud( $p, $especie, $etapa, $necesidades ) {
	if ( $especie && $p['especie'] !== $especie ) {
		return 0;
	}
	$s = 0.5;
	if ( 'Todas' === $p['etapa'] || $p['etapa'] === $etapa ) {
		$s += 0.25;
	}
	if ( ! $necesidades || ! $p['necesidad'] || array_intersect( (array) $necesidades, (array) $p['necesidad'] ) ) {
		$s += 0.25;
	}
	return $s;
}

/** Productos comprados por un cliente (IDs de producto padre). */
function patitas_historial( $user_id ) {
	$ids = array();
	if ( ! $user_id ) {
		return $ids;
	}
	foreach ( patitas_pedidos( array( 'customer_id' => $user_id ) ) as $pedido ) {
		foreach ( $pedido->get_items() as $item ) {
			$ids[] = (int) $item->get_product_id();
		}
	}
	return array_values( array_unique( $ids ) );
}

/* ------------------------------------------------------------------ predicción de recompra */

/** Ración diaria aproximada en kilos según especie y peso (regla de consumo). */
function patitas_racion_kg( $especie, $peso ) {
	$gramos_por_kg = 'Gatos' === $especie ? 15 : 12.5;
	return max( 0.03, ( (float) $peso * $gramos_por_kg ) / 1000 );
}

/**
 * Estima cuándo se acabará el alimento de cada mascota a partir de su última compra.
 *
 * @return array lista ordenada por fecha: mascota, producto, variación, formato, compra, fin, dias.
 */
function patitas_recompras( $user_id ) {
	$mascotas = patitas_mascotas( $user_id );
	if ( ! $mascotas ) {
		return array();
	}
	$ultimas = array(); // especie => datos de la última compra de alimento
	foreach ( patitas_pedidos( array( 'customer_id' => $user_id, 'orderby' => 'date', 'order' => 'DESC' ) ) as $pedido ) {
		foreach ( $pedido->get_items() as $item ) {
			$f = patitas_ficha( $item->get_product_id() );
			if ( ! patitas_es_alimento( $f['categoria'] ) || isset( $ultimas[ $f['especie'] ] ) ) {
				continue;
			}
			$formato = $item->get_meta( 'formato' );
			if ( ! $formato ) {
				$prod    = $item->get_product();
				$formato = $prod ? $prod->get_attribute( 'formato' ) : '';
			}
			$kg = patitas_kg( $formato ) * $item->get_quantity();
			if ( $kg > 0 ) {
				$ultimas[ $f['especie'] ] = array(
					'producto'  => (int) $item->get_product_id(),
					'variacion' => (int) $item->get_variation_id(),
					'formato'   => $formato,
					'kg'        => $kg,
					'compra'    => $pedido->get_date_created() ? $pedido->get_date_created()->getTimestamp() : time(),
				);
			}
		}
	}
	$salida = array();
	foreach ( $mascotas as $m ) {
		$u = $ultimas[ $m['especie'] ] ?? null;
		if ( ! $u ) {
			continue;
		}
		// Si varias mascotas de la misma especie comparten alimento, se suman sus raciones.
		$racion = 0;
		foreach ( $mascotas as $otra ) {
			if ( $otra['especie'] === $m['especie'] ) {
				$racion += patitas_racion_kg( $otra['especie'], $otra['peso'] ?? 10 );
			}
		}
		$duracion = (int) round( $u['kg'] / $racion );
		$fin      = $u['compra'] + $duracion * DAY_IN_SECONDS;
		$salida[ $m['especie'] ] = $u + array(
			'mascota'  => $m,
			'duracion' => $duracion,
			'fin'      => $fin,
			'dias'     => (int) floor( ( $fin - time() ) / DAY_IN_SECONDS ),
		);
	}
	$salida = array_values( $salida );
	usort( $salida, function ( $a, $b ) {
		return $a['fin'] <=> $b['fin'];
	} );
	return $salida;
}

/* ------------------------------------------------------------------ segmentación K-means (RFM) */

/** Variables RFM por cliente (agrupado por correo de facturación). */
function patitas_rfm() {
	$clientes = array();
	foreach ( patitas_pedidos() as $pedido ) {
		$correo = strtolower( (string) $pedido->get_billing_email() );
		if ( ! $correo || ! $pedido->get_date_created() ) {
			continue;
		}
		$t = $pedido->get_date_created()->getTimestamp();
		if ( ! isset( $clientes[ $correo ] ) ) {
			$clientes[ $correo ] = array( 'nombre' => trim( $pedido->get_billing_first_name() . ' ' . $pedido->get_billing_last_name() ), 'ultima' => 0, 'f' => 0, 'm' => 0 );
		}
		$clientes[ $correo ]['ultima'] = max( $clientes[ $correo ]['ultima'], $t );
		$clientes[ $correo ]['f']++;
		$clientes[ $correo ]['m'] += (float) $pedido->get_total();
	}
	foreach ( $clientes as &$c ) {
		$c['r'] = max( 0, (int) floor( ( time() - $c['ultima'] ) / DAY_IN_SECONDS ) );
	}
	return $clientes;
}

/** Estandariza columnas (z-score); el monto se transforma con log para reducir sesgo. */
function patitas_estandarizar( $filas ) {
	$cols = array( 'r', 'f', 'm' );
	$x    = array();
	foreach ( $filas as $k => $f ) {
		$x[ $k ] = array( (float) $f['r'], (float) $f['f'], log( 1 + (float) $f['m'] ) );
	}
	$n = count( $x );
	for ( $j = 0; $j < 3; $j++ ) {
		$col  = array_column( $x, $j );
		$prom = array_sum( $col ) / max( 1, $n );
		$var  = 0;
		foreach ( $col as $v ) {
			$var += ( $v - $prom ) ** 2;
		}
		$de = sqrt( $var / max( 1, $n ) ) ?: 1;
		foreach ( $x as $k => $fila ) {
			$x[ $k ][ $j ] = ( $fila[ $j ] - $prom ) / $de;
		}
	}
	unset( $cols );
	return $x;
}

function patitas_dist( $a, $b ) {
	$s = 0;
	foreach ( $a as $i => $v ) {
		$s += ( $v - $b[ $i ] ) ** 2;
	}
	return sqrt( $s );
}

/** K-means con inicialización k-means++ determinista (semilla fija). */
function patitas_kmeans( $x, $k, $iter = 60 ) {
	$claves = array_keys( $x );
	mt_srand( 42 );
	$centros = array( $x[ $claves[ mt_rand( 0, count( $claves ) - 1 ) ] ] );
	while ( count( $centros ) < $k ) {
		$d = array();
		foreach ( $claves as $c ) {
			$min = INF;
			foreach ( $centros as $ce ) {
				$min = min( $min, patitas_dist( $x[ $c ], $ce ) ** 2 );
			}
			$d[ $c ] = $min;
		}
		$total = array_sum( $d );
		$r     = mt_rand() / mt_getrandmax() * $total;
		foreach ( $d as $c => $v ) {
			$r -= $v;
			if ( $r <= 0 ) {
				$centros[] = $x[ $c ];
				break;
			}
		}
		if ( $r > 0 ) {
			$centros[] = $x[ end( $claves ) ];
		}
	}
	$asig = array();
	for ( $it = 0; $it < $iter; $it++ ) {
		$cambio = false;
		foreach ( $x as $c => $fila ) {
			$mejor = 0;
			$dmin  = INF;
			foreach ( $centros as $i => $ce ) {
				$dd = patitas_dist( $fila, $ce );
				if ( $dd < $dmin ) {
					$dmin  = $dd;
					$mejor = $i;
				}
			}
			if ( ! isset( $asig[ $c ] ) || $asig[ $c ] !== $mejor ) {
				$asig[ $c ] = $mejor;
				$cambio     = true;
			}
		}
		foreach ( $centros as $i => $ce ) {
			$miembros = array_keys( $asig, $i, true );
			if ( $miembros ) {
				$nuevo = array_fill( 0, count( $ce ), 0 );
				foreach ( $miembros as $m ) {
					foreach ( $x[ $m ] as $j => $v ) {
						$nuevo[ $j ] += $v / count( $miembros );
					}
				}
				$centros[ $i ] = $nuevo;
			}
		}
		if ( ! $cambio ) {
			break;
		}
	}
	return $asig;
}

/** Coeficiente de silueta promedio (Rousseeuw, 1987). */
function patitas_silueta( $x, $asig ) {
	$total = 0;
	$n     = 0;
	foreach ( $x as $c => $fila ) {
		$grupos = array();
		foreach ( $x as $o => $otra ) {
			if ( $o !== $c ) {
				$grupos[ $asig[ $o ] ][] = patitas_dist( $fila, $otra );
			}
		}
		$propio = $grupos[ $asig[ $c ] ] ?? array();
		if ( ! $propio ) {
			continue;
		}
		$a = array_sum( $propio ) / count( $propio );
		$b = INF;
		foreach ( $grupos as $g => $ds ) {
			if ( $g !== $asig[ $c ] ) {
				$b = min( $b, array_sum( $ds ) / count( $ds ) );
			}
		}
		if ( INF === $b ) {
			continue;
		}
		$total += ( $b - $a ) / max( $a, $b );
		$n++;
	}
	return $n ? $total / $n : 0;
}

/** Segmenta a los clientes probando k = 2 a 5 y eligiendo el de mayor silueta. */
function patitas_segmentar() {
	$clientes = patitas_rfm();
	if ( count( $clientes ) < 6 ) {
		return array();
	}
	$x       = patitas_estandarizar( $clientes );
	$prueba  = array();
	$mejor_k = 2;
	$mejor   = null;
	for ( $k = 2; $k <= 5; $k++ ) {
		$asig         = patitas_kmeans( $x, $k );
		$prueba[ $k ] = round( patitas_silueta( $x, $asig ), 3 );
		if ( null === $mejor || $prueba[ $k ] > $prueba[ $mejor_k ] ) {
			$mejor_k = $k;
			$mejor   = $asig;
		}
	}
	// Resumen de cada grupo en unidades originales.
	$grupos = array();
	foreach ( $mejor as $correo => $g ) {
		$c = $clientes[ $correo ];
		$grupos[ $g ]['clientes'][] = array( 'correo' => $correo, 'nombre' => $c['nombre'], 'r' => $c['r'], 'f' => $c['f'], 'm' => $c['m'] );
	}
	foreach ( $grupos as $g => &$d ) {
		$n       = count( $d['clientes'] );
		$d['n']  = $n;
		$d['r']  = round( array_sum( array_column( $d['clientes'], 'r' ) ) / $n );
		$d['f']  = round( array_sum( array_column( $d['clientes'], 'f' ) ) / $n, 1 );
		$d['m']  = round( array_sum( array_column( $d['clientes'], 'm' ) ) / $n );
	}
	unset( $d );
	patitas_nombrar_grupos( $grupos );
	return array( 'k' => $mejor_k, 'silueta' => $prueba, 'grupos' => array_values( $grupos ), 'n' => count( $clientes ) );
}

/**
 * Asigna nombre y acción a cada grupo según sus promedios RFM:
 * nuevos (≈1 pedido), en riesgo (más de 90 días sin comprar), leales (frecuencia alta) y ocasionales (el resto).
 * Si dos grupos quedan con el mismo perfil se distinguen por su ticket (monto).
 */
function patitas_nombrar_grupos( &$grupos ) {
	$perfiles = array(
		'nuevos'      => array( 'Nuevos tutores', 'Kit de inicio, guía de cuidados y registro del perfil de la mascota.' ),
		'riesgo'      => array( 'En riesgo de abandono', 'Cupón de regreso VUELVE10 por correo o WhatsApp (solo con consentimiento).' ),
		'leales'      => array( 'Leales de alimento', 'Recordatorio de recompra y suscripción con 5 % de descuento.' ),
		'ocasionales' => array( 'Ocasionales y buscadores de ofertas', 'Aviso de ofertas y packs; campañas de temporada en redes sociales.' ),
	);
	$por_perfil = array();
	foreach ( $grupos as $g => $d ) {
		if ( $d['f'] <= 1.3 ) {
			$p = 'nuevos';
		} elseif ( $d['r'] >= 90 ) {
			$p = 'riesgo';
		} elseif ( $d['f'] >= 3.5 ) {
			$p = 'leales';
		} else {
			$p = 'ocasionales';
		}
		$grupos[ $g ]['nombre'] = $perfiles[ $p ][0];
		$grupos[ $g ]['accion'] = $perfiles[ $p ][1];
		$por_perfil[ $p ][]     = $g;
	}
	foreach ( $por_perfil as $lista ) {
		if ( count( $lista ) < 2 ) {
			continue;
		}
		usort( $lista, function ( $a, $b ) use ( $grupos ) {
			return $grupos[ $b ]['m'] <=> $grupos[ $a ]['m'];
		} );
		foreach ( $lista as $i => $g ) {
			$grupos[ $g ]['nombre'] .= 0 === $i ? ' (ticket alto)' : ( count( $lista ) - 1 === $i ? ' (ticket bajo)' : ' (ticket medio)' );
		}
	}
	uasort( $grupos, function ( $a, $b ) {
		return $b['f'] <=> $a['f'];
	} );
}
