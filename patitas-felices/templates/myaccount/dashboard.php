<?php
/**
 * Resumen de Mi cuenta con el diseño del prototipo (reemplaza woocommerce/templates/myaccount/dashboard.php):
 * pedidos recientes, mascotas, próxima recompra y privacidad.
 */

defined( 'ABSPATH' ) || exit;

$uid     = get_current_user_id();
$pedidos = wc_get_orders( array( 'customer_id' => $uid, 'limit' => 3, 'orderby' => 'date', 'order' => 'DESC' ) );
$estados = array(
	'processing' => array( 'Preparando', 'ambar' ),
	'completed'  => array( 'Entregado', 'verde' ),
	'on-hold'    => array( 'En espera de pago', 'ambar' ),
	'pending'    => array( 'Pendiente', 'ambar' ),
	'cancelled'  => array( 'Cancelado', 'gris' ),
);
?>
<section class="pf-bloque">
	<div class="pf-bloque-cab"><h3>Mis pedidos</h3><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">Ver seguimiento</a></div>
	<?php if ( $pedidos ) : ?>
		<table class="pf-tabla-pedidos">
			<thead><tr><th>Pedido</th><th>Fecha</th><th>Productos</th><th>Total</th><th>Estado</th><th></th></tr></thead>
			<tbody>
			<?php
			foreach ( $pedidos as $p ) :
				$partes = array();
				foreach ( $p->get_items() as $item ) {
					$partes[] = $item->get_name() . ( $item->get_quantity() > 1 ? ' × ' . $item->get_quantity() : '' );
				}
				$resumen = implode( ', ', $partes );
				$estado  = $estados[ $p->get_status() ] ?? array( wc_get_order_status_name( $p->get_status() ), 'gris' );
				$fecha   = $p->get_date_created();
				$es_hoy  = $fecha && $fecha->date( 'Y-m-d' ) === current_time( 'Y-m-d' );
				?>
				<tr>
					<td><b>#<?php echo esc_html( $p->get_order_number() ); ?></b></td>
					<td><?php echo esc_html( $es_hoy ? 'Hoy' : ( $fecha ? $fecha->date_i18n( 'd-m-Y' ) : '' ) ); ?></td>
					<td class="pf-tabla-productos" title="<?php echo esc_attr( $resumen ); ?>"><?php echo esc_html( wp_html_excerpt( $resumen, 46, '…' ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $p->get_total() ) ); ?></td>
					<td><span class="pf-estado pf-estado-<?php echo esc_attr( $estado[1] ); ?>"><?php echo esc_html( $estado[0] ); ?></span></td>
					<td>
						<?php if ( $p->has_status( 'completed' ) ) : ?>
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'order_again', $p->get_id(), wc_get_cart_url() ), 'woocommerce-order_again' ) ); ?>">Repetir</a>
						<?php else : ?>
							<a href="<?php echo esc_url( $p->get_view_order_url() ); ?>">Seguir</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p>Aún no tienes pedidos. <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">Ir al catálogo</a></p>
	<?php endif; ?>
</section>

<section class="pf-bloque">
	<div class="pf-bloque-cab"><h3>Mis mascotas</h3><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'mis-mascotas' ) ); ?>">+ Agregar mascota</a></div>
	<div class="pf-mascotas">
		<?php
		$recompras = array();
		foreach ( patitas_recompras( $uid ) as $r ) {
			$recompras[ $r['mascota']['nombre'] ] = $r;
		}
		foreach ( patitas_mascotas( $uid ) as $m ) :
			$come = isset( $recompras[ $m['nombre'] ] ) ? get_the_title( $recompras[ $m['nombre'] ]['producto'] ) : '';
			?>
			<div class="pf-tarjeta pf-mascota">
				<span class="pf-avatar pf-avatar-<?php echo 'Gatos' === $m['especie'] ? 'gato' : 'perro'; ?>" aria-hidden="true"><?php echo 'Gatos' === $m['especie'] ? '🐱' : '🐶'; ?></span>
				<div>
					<b><?php echo esc_html( $m['nombre'] ); ?></b>
					<small><?php echo esc_html( ucfirst( patitas_singular( $m['especie'] ) ) . ' ' . strtolower( $m['etapa'] ) . ' · ' . $m['peso'] . ' kg' ); ?></small>
					<?php if ( $come ) : ?><small>Come: <?php echo esc_html( $come ); ?></small><?php endif; ?>
					<?php if ( ! empty( $m['necesidad'] ) ) : ?><small>Necesidad: <?php echo esc_html( implode( ', ', (array) $m['necesidad'] ) ); ?></small><?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</section>

<?php
$lista = patitas_recompras( $uid );
if ( $lista ) :
	$r    = $lista[0];
	$prod = wc_get_product( $r['variacion'] ? $r['variacion'] : $r['producto'] );
	if ( $prod ) :
		$args = array( 'add-to-cart' => $r['producto'] );
		if ( $r['variacion'] ) {
			$args['variation_id']      = $r['variacion'];
			$args['attribute_formato'] = $r['formato'];
		}
		?>
		<section class="pf-bloque">
			<div class="pf-bloque-cab"><h3>Próxima recompra</h3><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'suscripciones' ) ); ?>">Suscripciones</a></div>
			<div class="pf-tarjeta pf-recompra-fila">
				<?php echo $prod->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore ?>
				<div>
					<b><?php echo esc_html( get_the_title( $r['producto'] ) . ' ' . $r['formato'] ); ?></b>
					<small>Para <?php echo esc_html( $r['mascota']['nombre'] ); ?> · dura unos <?php echo (int) $r['duracion']; ?> días · se acabaría cerca del <?php echo esc_html( patitas_fecha( $r['fin'] ) ); ?> (<?php echo $r['dias'] >= 0 ? 'en ' . (int) $r['dias'] . ' días' : 'hace ' . (int) abs( $r['dias'] ) . ' días'; ?>)</small>
				</div>
				<a class="pf-boton-chico" href="<?php echo esc_url( get_permalink( $r['producto'] ) ); ?>">Suscribirme</a>
				<a class="button pf-btn-oscuro" href="<?php echo esc_url( add_query_arg( $args, wc_get_cart_url() ) ); ?>">Repetir pedido</a>
			</div>
		</section>
		<?php
	endif;
endif;
?>

<section class="pf-bloque">
	<div class="pf-bloque-cab"><h3>Privacidad y personalización</h3></div>
	<p class="pf-gris">Tú decides cómo usamos tus datos. Puedes cambiarlo cuando quieras o pedir una copia o la eliminación de tus datos.</p>
	<?php patitas_form_privacidad(); ?>
</section>
