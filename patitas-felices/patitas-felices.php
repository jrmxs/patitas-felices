<?php
/**
 * Plugin Name:       Patitas Felices – Tienda demo
 * Description:       Configura la tienda Patitas Felices sobre WooCommerce: catálogo, despacho en Copiapó, pagos simulados, perfil de mascotas, predicción de recompra, recomendador híbrido y elementos de marketing social. Prototipo académico (INACAP, E-Commerce); datos, marcas y precios ficticios.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            Proyecto académico Patitas Felices
 * License:           GPL-2.0-or-later
 * Text Domain:       patitas
 */

defined( 'ABSPATH' ) || exit;

define( 'PATITAS_DIR', plugin_dir_path( __FILE__ ) );
define( 'PATITAS_URL', plugin_dir_url( __FILE__ ) );
define( 'PATITAS_VER', '1.0.0' );
define( 'PATITAS_ENVIO_GRATIS', 40000 );

require_once PATITAS_DIR . 'includes/datos.php';
require_once PATITAS_DIR . 'includes/recomendador.php';
require_once PATITAS_DIR . 'includes/instalar.php';
require_once PATITAS_DIR . 'includes/tienda.php';
require_once PATITAS_DIR . 'includes/catalogo.php';
require_once PATITAS_DIR . 'includes/apariencia.php';
require_once PATITAS_DIR . 'includes/cuenta.php';
require_once PATITAS_DIR . 'includes/pagos.php';
require_once PATITAS_DIR . 'includes/admin.php';
require_once PATITAS_DIR . 'includes/demo.php';

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'patitas_recalcular_diario' );
} );
