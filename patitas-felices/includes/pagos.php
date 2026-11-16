<?php
/**
 * Medios de pago simulados (Webpay Plus y Mercado Pago). En la implementación real se reemplazan por los
 * plugins oficiales de Transbank y Mercado Pago; aquí completan el pedido sin cobrar nada.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'patitas_registrar_pagos', 20 );

function patitas_registrar_pagos() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'Patitas_Pago_Simulado' ) ) {
		return;
	}

	abstract class Patitas_Pago_Simulado extends WC_Payment_Gateway {
		public $titulo_defecto      = '';
		public $descripcion_defecto = '';

		public function __construct() {
			$this->has_fields         = false;
			$this->method_description = 'Pasarela simulada del prototipo académico: aprueba el pago sin cobrar. En producción se usa el plugin oficial.';
			$this->init_form_fields();
			$this->init_settings();
			$this->title       = $this->get_option( 'title', $this->titulo_defecto );
			$this->description = $this->get_option( 'description', $this->descripcion_defecto );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'     => array( 'title' => 'Activar', 'type' => 'checkbox', 'label' => 'Activar este medio de pago', 'default' => 'yes' ),
				'title'       => array( 'title' => 'Título', 'type' => 'text', 'default' => $this->titulo_defecto ),
				'description' => array( 'title' => 'Descripción', 'type' => 'textarea', 'default' => $this->descripcion_defecto ),
			);
		}

		public function process_payment( $order_id ) {
			$pedido = wc_get_order( $order_id );
			$pedido->payment_complete( 'SIM-' . strtoupper( wp_generate_password( 8, false ) ) );
			$pedido->add_order_note( 'Pago aprobado en modo simulado (' . $this->method_title . '). No se realizó ningún cobro.' );
			WC()->cart->empty_cart();
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $pedido ) );
		}
	}

	class Patitas_Pago_Webpay extends Patitas_Pago_Simulado {
		public function __construct() {
			$this->id                  = 'patitas_webpay';
			$this->method_title        = 'Webpay Plus (Transbank) – simulado';
			$this->titulo_defecto      = 'Webpay Plus: crédito, débito o prepago';
			$this->descripcion_defecto = 'Serás redirigido al formulario seguro de Transbank. En este prototipo el pago se aprueba automáticamente y no se cobra nada.';
			parent::__construct();
		}
	}

	class Patitas_Pago_MercadoPago extends Patitas_Pago_Simulado {
		public function __construct() {
			$this->id                  = 'patitas_mercadopago';
			$this->method_title        = 'Mercado Pago – simulado';
			$this->titulo_defecto      = 'Mercado Pago: tarjetas, saldo o cuotas';
			$this->descripcion_defecto = 'Paga con tu cuenta de Mercado Pago. En este prototipo el pago se aprueba automáticamente y no se cobra nada.';
			parent::__construct();
		}
	}

	add_filter( 'woocommerce_payment_gateways', function ( $pasarelas ) {
		$pasarelas[] = 'Patitas_Pago_Webpay';
		$pasarelas[] = 'Patitas_Pago_MercadoPago';
		return $pasarelas;
	} );
}
