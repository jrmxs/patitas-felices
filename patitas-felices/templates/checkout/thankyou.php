<?php
/**
 * Confirmación del pedido con el diseño del prototipo (reemplaza woocommerce/templates/checkout/thankyou.php).
 *
 * @var WC_Order|false $order
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="woocommerce-order pf-gracias">
	<?php if ( ! $order ) : ?>
		<p class="woocommerce-notice woocommerce-notice--success">Gracias. Tu pedido ha sido recibido.</p>
		<?php return; ?>
	<?php endif; ?>

	<?php if ( $order->has_status( 'failed' ) ) : ?>
		<p class="woocommerce-notice woocommerce-notice--error">No pudimos procesar el pago de tu pedido. Puedes intentarlo de nuevo.</p>
		<p><a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="button pay">Pagar</a></p>
		<?php return; ?>
	<?php endif; ?>

	<section class="pf-ok">
		<span class="pf-ok-icono" aria-hidden="true"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
		<div>
			<h2>¡Gracias, <?php echo esc_html( $order->get_billing_first_name() ); ?>! Recibimos tu pedido.</h2>
			<p>Pedido <b>#<?php echo esc_html( $order->get_order_number() ); ?></b> · <?php echo $order->is_paid() ? 'Pagado con ' . esc_html( $order->get_payment_method_title() ) : 'Pendiente de pago (' . esc_html( $order->get_payment_method_title() ) . ')'; ?> · Boleta electrónica enviada a <?php echo esc_html( $order->get_billing_email() ); ?></p>
		</div>
	</section>

	<div class="pf-gracias-grid">
		<?php patitas_gracias_seguimiento( $order ); ?>
		<?php patitas_gracias_invita( $order ); ?>
	</div>

	<?php
	// Instrucciones del medio de pago (por ejemplo, datos de la transferencia) y otros complementos.
	do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );
	remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
	do_action( 'woocommerce_thankyou', $order->get_id() );
	?>

	<?php patitas_gracias_recos( $order ); ?>

	<details class="pf-detalle-pedido">
		<summary>Ver detalle del pedido</summary>
		<?php woocommerce_order_details_table( $order->get_id() ); ?>
	</details>
</div>
