<?php
/**
 * Mi cuenta: perfil de mascotas, suscripciones y panel de privacidad (Ley N.º 21.719).
 */

defined( 'ABSPATH' ) || exit;

const PATITAS_ENDPOINTS = array(
	'mis-mascotas'  => 'Mis mascotas',
	'suscripciones' => 'Suscripciones',
	'privacidad'    => 'Privacidad',
);

add_action( 'init', function () {
	foreach ( array_keys( PATITAS_ENDPOINTS ) as $e ) {
		add_rewrite_endpoint( $e, EP_ROOT | EP_PAGES );
	}
} );

add_filter( 'woocommerce_get_query_vars', function ( $vars ) {
	foreach ( array_keys( PATITAS_ENDPOINTS ) as $e ) {
		$vars[ $e ] = $e;
	}
	return $vars;
} );

add_filter( 'woocommerce_account_menu_items', function ( $items ) {
	$nuevo = array();
	foreach ( $items as $k => $v ) {
		$nuevo[ $k ] = $v;
		if ( 'orders' === $k ) {
			$nuevo = array_merge( $nuevo, PATITAS_ENDPOINTS );
		}
	}
	return $nuevo;
} );

foreach ( array_keys( PATITAS_ENDPOINTS ) as $patitas_e ) {
	add_filter( 'woocommerce_endpoint_' . $patitas_e . '_title', function () use ( $patitas_e ) {
		return PATITAS_ENDPOINTS[ $patitas_e ];
	} );
}

/* ------------------------------------------------------------------ escritorio de la cuenta */

add_action( 'woocommerce_account_dashboard', function () {
	$uid = get_current_user_id();
	echo '<div class="pf-cuenta-resumen">';
	patitas_tarjeta_recompra( $uid );
	$m = patitas_mascotas( $uid );
	echo '<div class="pf-tarjeta"><b>Tus mascotas</b>';
	if ( $m ) {
		echo '<ul>';
		foreach ( $m as $x ) {
			printf( '<li>%s · %s %s, %s kg</li>', esc_html( $x['nombre'] ), esc_html( patitas_singular( $x['especie'] ) ), esc_html( strtolower( $x['etapa'] ) ), esc_html( $x['peso'] ) );
		}
		echo '</ul>';
	} else {
		echo '<p>Aún no registras mascotas. Con su perfil calculamos recordatorios y recomendaciones.</p>';
	}
	printf( '<a class="button" href="%s">Administrar mascotas</a></div></div>', esc_url( wc_get_account_endpoint_url( 'mis-mascotas' ) ) );
} );

/* ------------------------------------------------------------------ mis mascotas */

add_action( 'template_redirect', function () {
	if ( ! is_user_logged_in() || empty( $_POST['patitas_accion'] ) ) { // phpcs:ignore
		return;
	}
	if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'patitas_cuenta' ) ) { // phpcs:ignore
		return;
	}
	$uid    = get_current_user_id();
	$accion = sanitize_key( $_POST['patitas_accion'] );
	if ( 'agregar_mascota' === $accion ) {
		$nombre = sanitize_text_field( wp_unslash( $_POST['nombre'] ?? '' ) );
		if ( $nombre ) {
			$m   = patitas_mascotas( $uid );
			$m[] = array(
				'nombre'    => $nombre,
				'especie'   => 'Gatos' === ( $_POST['especie'] ?? '' ) ? 'Gatos' : 'Perros',
				'etapa'     => in_array( $_POST['etapa'] ?? '', array( 'Cachorro', 'Adulto', 'Senior' ), true ) ? $_POST['etapa'] : 'Adulto', // phpcs:ignore
				'peso'      => max( 0.5, min( 90, (float) str_replace( ',', '.', $_POST['peso'] ?? '5' ) ) ), // phpcs:ignore
				'necesidad' => array_values( array_intersect( (array) ( $_POST['necesidad'] ?? array() ), array( 'Raza grande', 'Control de peso', 'Hipoalergénico' ) ) ), // phpcs:ignore
			);
			update_user_meta( $uid, 'patitas_mascotas', $m );
			wc_add_notice( sprintf( 'Registramos a %s. Ya ajustamos tus recomendaciones.', $nombre ) );
		}
	} elseif ( 'quitar_mascota' === $accion ) {
		$m = patitas_mascotas( $uid );
		unset( $m[ (int) ( $_POST['indice'] ?? -1 ) ] );
		update_user_meta( $uid, 'patitas_mascotas', array_values( $m ) );
		wc_add_notice( 'Quitamos la mascota de tu perfil.' );
	} elseif ( 'privacidad' === $accion ) {
		foreach ( array( 'personalizacion', 'recordatorios', 'marketing' ) as $p ) {
			update_user_meta( $uid, 'patitas_pref_' . $p, empty( $_POST[ $p ] ) ? 'no' : 'si' );
		}
		wc_add_notice( 'Guardamos tus preferencias de privacidad.' );
	} elseif ( in_array( $accion, array( 'export_personal_data', 'remove_personal_data' ), true ) ) {
		$correo = wp_get_current_user()->user_email;
		$id     = wp_create_user_request( $correo, $accion, array(), 'confirmed' );
		if ( is_wp_error( $id ) ) {
			wc_add_notice( 'Ya tienes una solicitud en curso: ' . $id->get_error_message(), 'notice' );
		} else {
			wc_add_notice( 'export_personal_data' === $accion
				? 'Recibimos tu solicitud de copia de datos. La tienda la revisa en Herramientas › Exportar datos personales y te la envía por correo.'
				: 'Recibimos tu solicitud de eliminación de datos. Se procesa en un plazo máximo de 30 días, salvo los datos que la ley obliga a conservar (boletas).' );
		}
	}
	wp_safe_redirect( wc_get_account_endpoint_url( 'privacidad' === $accion || false !== strpos( $accion, 'personal' ) ? 'privacidad' : 'mis-mascotas' ) );
	exit;
} );

add_action( 'woocommerce_account_mis-mascotas_endpoint', function () {
	$m = patitas_mascotas();
	echo '<p>Con el perfil de tus mascotas calculamos cuándo se acabará su alimento y qué productos les sirven. Puedes desactivar este uso en <a href="' . esc_url( wc_get_account_endpoint_url( 'privacidad' ) ) . '">Privacidad</a>.</p>';
	if ( $m ) {
		echo '<div class="pf-mascotas">';
		foreach ( $m as $i => $x ) {
			printf( '<div class="pf-tarjeta"><span class="pf-avatar" aria-hidden="true">%s</span><div><b>%s</b><p>%s %s · %s kg%s</p></div><form method="post">%s<input type="hidden" name="patitas_accion" value="quitar_mascota"><input type="hidden" name="indice" value="%d"><button type="submit" class="pf-link">Quitar</button></form></div>',
				'Gatos' === $x['especie'] ? '🐱' : '🐶',
				esc_html( $x['nombre'] ),
				esc_html( ucfirst( patitas_singular( $x['especie'] ) ) ),
				esc_html( strtolower( $x['etapa'] ) ),
				esc_html( $x['peso'] ),
				$x['necesidad'] ? ' · ' . esc_html( implode( ', ', (array) $x['necesidad'] ) ) : '',
				wp_nonce_field( 'patitas_cuenta', '_wpnonce', true, false ),
				(int) $i
			);
		}
		echo '</div>';
	}
	?>
	<h3>Agregar mascota</h3>
	<form method="post" class="pf-form">
		<?php wp_nonce_field( 'patitas_cuenta' ); ?>
		<input type="hidden" name="patitas_accion" value="agregar_mascota">
		<p><label>Nombre <input type="text" name="nombre" required></label></p>
		<p><label>Especie <select name="especie"><option>Perros</option><option>Gatos</option></select></label>
		<label>Etapa <select name="etapa"><option>Cachorro</option><option selected>Adulto</option><option>Senior</option></select></label>
		<label>Peso (kg) <input type="number" name="peso" min="0.5" max="90" step="0.5" value="10"></label></p>
		<fieldset><legend>Necesidades especiales</legend>
			<label><input type="checkbox" name="necesidad[]" value="Raza grande"> Raza grande</label>
			<label><input type="checkbox" name="necesidad[]" value="Control de peso"> Control de peso</label>
			<label><input type="checkbox" name="necesidad[]" value="Hipoalergénico"> Hipoalergénico</label>
		</fieldset>
		<p><button type="submit" class="button">Guardar mascota</button></p>
	</form>
	<?php
} );

/* ------------------------------------------------------------------ suscripciones */

add_action( 'woocommerce_account_suscripciones_endpoint', function () {
	$filas = array();
	foreach ( wc_get_orders( array( 'customer_id' => get_current_user_id(), 'limit' => 20 ) ) as $pedido ) {
		foreach ( $pedido->get_items() as $item ) {
			$sub = $item->get_meta( 'Suscripción' );
			if ( $sub ) {
				$filas[] = array( $item->get_name(), $sub, $pedido->get_date_created() );
			}
		}
	}
	echo '<p>Con la suscripción recibes tu alimento o arena cada 4, 6 u 8 semanas con 5 % de descuento. Te avisamos 2 días antes de cada envío.</p>';
	if ( ! $filas ) {
		$r = patitas_recompras( get_current_user_id() );
		echo '<div class="pf-tarjeta"><b>Aún no tienes suscripciones activas.</b><p>Elige «Suscríbete y ahorra 5 %» en la ficha de cualquier alimento o arena.';
		if ( $r ) {
			$p = wc_get_product( $r[0]['producto'] );
			if ( $p ) {
				printf( ' Por ejemplo, para %s te sugerimos <a href="%s">%s</a> cada %d semanas.', esc_html( $r[0]['mascota']['nombre'] ), esc_url( $p->get_permalink() ), esc_html( $p->get_name() ), max( 4, min( 8, (int) round( $r[0]['duracion'] / 7 ) ) ) );
			}
		}
		echo '</p></div>';
		return;
	}
	echo '<table class="shop_table"><thead><tr><th>Producto</th><th>Frecuencia</th><th>Desde</th><th></th></tr></thead><tbody>';
	foreach ( $filas as $f ) {
		printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td><span class="pf-chip">Activa</span></td></tr>', esc_html( $f[0] ), esc_html( $f[1] ), esc_html( $f[2] ? $f[2]->date_i18n( 'j \d\e F' ) : '' ) );
	}
	echo '</tbody></table>';
} );

/* ------------------------------------------------------------------ privacidad */

add_action( 'woocommerce_account_privacidad_endpoint', function () {
	$prefs = array(
		'personalizacion' => array( 'Recomendaciones personalizadas', 'Usamos tu historial y el perfil de tus mascotas para sugerirte productos. Si lo desactivas, verás solo productos populares.' ),
		'recordatorios'   => array( 'Recordatorios de recompra', 'Calculamos cuándo se acabará el alimento de tus mascotas y te avisamos.' ),
		'marketing'       => array( 'Ofertas por correo y WhatsApp', 'Solo te escribimos si lo autorizas. Puedes retirar el consentimiento cuando quieras.' ),
	);
	?>
	<p>Tú decides cómo usamos tus datos (Ley N.º 21.719). Revisa la <a href="<?php echo esc_url( get_privacy_policy_url() ); ?>">Política de privacidad</a>.</p>
	<form method="post" class="pf-form pf-privacidad">
		<?php wp_nonce_field( 'patitas_cuenta' ); ?>
		<input type="hidden" name="patitas_accion" value="privacidad">
		<?php foreach ( $prefs as $k => $p ) : ?>
			<label class="pf-interruptor">
				<input type="checkbox" name="<?php echo esc_attr( $k ); ?>" value="1" <?php checked( patitas_pref( $k ) ); ?>>
				<span><b><?php echo esc_html( $p[0] ); ?></b><small><?php echo esc_html( $p[1] ); ?></small></span>
			</label>
		<?php endforeach; ?>
		<p><button type="submit" class="button">Guardar preferencias</button></p>
	</form>
	<h3>Tus datos</h3>
	<div class="pf-datos-acciones">
		<form method="post"><?php wp_nonce_field( 'patitas_cuenta' ); ?><input type="hidden" name="patitas_accion" value="export_personal_data"><button type="submit" class="button">Solicitar copia de mis datos</button></form>
		<form method="post"><?php wp_nonce_field( 'patitas_cuenta' ); ?><input type="hidden" name="patitas_accion" value="remove_personal_data"><button type="submit" class="button pf-btn-borde">Solicitar eliminación de mis datos</button></form>
	</div>
	<?php
} );

/* ------------------------------------------------------------------ RUT en el perfil */

add_filter( 'woocommerce_customer_meta_fields', function ( $campos ) {
	$campos['billing']['fields']['billing_rut'] = array( 'label' => 'RUT', 'description' => 'Para la boleta electrónica.' );
	return $campos;
} );
