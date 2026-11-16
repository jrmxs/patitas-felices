<?php
/**
 * Datos y utilidades compartidas: catálogo, mascotas del cliente y preferencias de privacidad.
 */

defined( 'ABSPATH' ) || exit;

/** Catálogo fuente (el mismo del prototipo HTML). */
function patitas_productos_data() {
	static $datos = null;
	if ( null === $datos ) {
		$datos = json_decode( (string) file_get_contents( PATITAS_DIR . 'data/productos.json' ), true );
		$datos = is_array( $datos ) ? $datos : array();
	}
	return $datos;
}

/** ID de WooCommerce a partir del código del prototipo (p1, p2...). */
function patitas_pid( $codigo ) {
	$mapa = get_option( 'patitas_mapa', array() );
	return isset( $mapa[ $codigo ] ) ? (int) $mapa[ $codigo ] : 0;
}

/** Kilos de un formato ("15 kg", "1,5 kg"); 0 si no es un formato en kilos. */
function patitas_kg( $formato ) {
	if ( preg_match( '/([\d.,]+)\s*kg/i', (string) $formato, $m ) ) {
		return (float) str_replace( ',', '.', $m[1] );
	}
	return 0.0;
}

/** Atributos del producto usados por el recomendador (guardados al instalar). */
function patitas_ficha( $product_id ) {
	$product_id = (int) $product_id;
	$parent     = wp_get_post_parent_id( $product_id );
	if ( $parent ) {
		$product_id = $parent;
	}
	return array(
		'id'        => $product_id,
		'especie'   => (string) get_post_meta( $product_id, '_pf_especie', true ),
		'etapa'     => (string) get_post_meta( $product_id, '_pf_etapa', true ),
		'categoria' => (string) get_post_meta( $product_id, '_pf_categoria', true ),
		'necesidad' => (array) get_post_meta( $product_id, '_pf_necesidad', true ),
	);
}

function patitas_es_alimento( $categoria ) {
	return in_array( $categoria, array( 'Alimento seco', 'Alimento húmedo' ), true );
}

/** Mascotas registradas por el cliente. */
function patitas_mascotas( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return array();
	}
	$m = get_user_meta( $user_id, 'patitas_mascotas', true );
	return is_array( $m ) ? array_values( $m ) : array();
}

/** Especies de las mascotas del cliente (Perros / Gatos). */
function patitas_especies( $user_id = 0 ) {
	return array_values( array_unique( wp_list_pluck( patitas_mascotas( $user_id ), 'especie' ) ) );
}

/** Preferencias de privacidad. Personalización activa por defecto; marketing solo con consentimiento. */
function patitas_pref( $clave, $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	$defecto = array( 'personalizacion' => 'si', 'marketing' => 'no', 'recordatorios' => 'si' );
	if ( ! $user_id ) {
		return 'personalizacion' === $clave ? false : ( 'si' === ( $defecto[ $clave ] ?? 'no' ) );
	}
	$v = get_user_meta( $user_id, 'patitas_pref_' . $clave, true );
	if ( '' === $v ) {
		$v = $defecto[ $clave ] ?? 'no';
	}
	return 'si' === $v;
}

/** Nombre de la especie en singular para textos ("perro", "gato"). */
function patitas_singular( $especie ) {
	return 'Gatos' === $especie ? 'gato' : 'perro';
}

/** Formatea pesos chilenos. */
function patitas_clp( $monto ) {
	return '$' . number_format( (float) $monto, 0, ',', '.' );
}

/** Fecha en español ("24 de noviembre"). */
function patitas_fecha( $timestamp ) {
	return date_i18n( 'j \d\e F', $timestamp );
}
