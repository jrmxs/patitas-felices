<?php
/**
 * Guía del prototipo: panel flotante con accesos a cada pantalla y cambio rápido de usuario.
 * El cambio de usuario solo funciona en modo demo dentro de WordPress Playground o en un entorno local,
 * nunca en un sitio publicado.
 */

defined( 'ABSPATH' ) || exit;

function patitas_demo_activa() {
	if ( 'yes' !== get_option( 'patitas_demo' ) ) {
		return false;
	}
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	return 'local' === wp_get_environment_type()
		|| in_array( $host, array( 'localhost', '127.0.0.1' ), true )
		|| (bool) preg_match( '/(^|\.)(playground\.wordpress\.net|wordpress\.net)$/', $host );
}

add_action( 'init', function () {
	if ( empty( $_GET['patitas_como'] ) || ! patitas_demo_activa() ) { // phpcs:ignore
		return;
	}
	$como = sanitize_key( $_GET['patitas_como'] ); // phpcs:ignore
	$destino = home_url( '/' );
	if ( 'salir' === $como ) {
		wp_logout();
	} else {
		$usuario = 'admin' === $como ? get_users( array( 'role' => 'administrator', 'number' => 1 ) ) : array( get_user_by( 'id', (int) get_option( 'patitas_cliente_demo' ) ) );
		$u       = $usuario ? reset( $usuario ) : null;
		if ( $u ) {
			wp_clear_auth_cookie();
			wp_set_current_user( $u->ID );
			wp_set_auth_cookie( $u->ID, true );
			$destino = 'admin' === $como ? admin_url( 'admin.php?page=patitas-ml' ) : home_url( '/' );
		}
	}
	wp_safe_redirect( $destino );
	exit;
}, 1 );

add_action( 'wp_footer', function () {
	if ( ! patitas_demo_activa() ) {
		return;
	}
	$p1      = patitas_pid( 'p1' );
	$tienda  = wc_get_page_permalink( 'shop' );
	$enlaces = array(
		'Inicio y recomendaciones'      => home_url( '/' ),
		'Catálogo con filtros (gatos)'  => add_query_arg( 'filter_especie', 'gatos', $tienda ),
		'Ficha de producto y reseñas'   => $p1 ? get_permalink( $p1 ) : $tienda,
		'Carrito'                       => wc_get_cart_url(),
		'Finalizar compra (checkout)'   => wc_get_checkout_url(),
		'Mi cuenta y pedidos'           => wc_get_page_permalink( 'myaccount' ),
		'Mis mascotas'                  => wc_get_account_endpoint_url( 'mis-mascotas' ),
		'Privacidad'                    => wc_get_account_endpoint_url( 'privacidad' ),
	);
	$u = wp_get_current_user();
	?>
	<details class="pf-guia">
		<summary>Guía del prototipo</summary>
		<div class="pf-guia-panel">
			<b>Flujo principal de compra</b>
			<ol>
				<?php foreach ( $enlaces as $t => $url ) : ?>
					<li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $t ); ?></a></li>
				<?php endforeach; ?>
			</ol>
			<b>Ver como</b>
			<p class="pf-guia-usuario">Ahora: <?php echo esc_html( $u->exists() ? $u->display_name : 'visitante' ); ?></p>
			<div class="pf-guia-botones">
				<a href="<?php echo esc_url( add_query_arg( 'patitas_como', 'cliente', home_url( '/' ) ) ); ?>">Cliente (Felipe)</a>
				<a href="<?php echo esc_url( add_query_arg( 'patitas_como', 'admin', home_url( '/' ) ) ); ?>">Administrador</a>
				<a href="<?php echo esc_url( add_query_arg( 'patitas_como', 'salir', home_url( '/' ) ) ); ?>">Visitante</a>
			</div>
			<small>Pagos simulados: usa Webpay o Mercado Pago y el pedido se aprueba sin cobro. Cupón de prueba: BIENVENIDA10.</small>
		</div>
	</details>
	<?php
} );

// En el escritorio de administración: accesos directos para revisar la tienda.
add_action( 'admin_notices', function () {
	$pantalla = get_current_screen();
	if ( ! patitas_demo_activa() || ! $pantalla || 'dashboard' !== $pantalla->id ) {
		return;
	}
	printf( '<div class="notice notice-info"><p><b>Patitas Felices (demo):</b> <a href="%s">Pedidos</a> · <a href="%s">Productos</a> · <a href="%s">Clientes</a> · <a href="%s">ML y recomendador</a> · <a href="%s">Envíos</a> · <a href="%s">Pagos</a> · <a href="%s">Ver la tienda como cliente</a></p></div>',
		esc_url( admin_url( 'admin.php?page=wc-orders' ) ), esc_url( admin_url( 'edit.php?post_type=product' ) ), esc_url( admin_url( 'users.php?role=customer' ) ),
		esc_url( admin_url( 'admin.php?page=patitas-ml' ) ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ),
		esc_url( add_query_arg( 'patitas_como', 'cliente', home_url( '/' ) ) ) );
} );
