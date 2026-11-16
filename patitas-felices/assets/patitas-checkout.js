/* Checkout en 3 pasos (Datos, Despacho, Pago) sobre el formulario estándar de WooCommerce. */
( function ( $ ) {
	var form = $( 'form.checkout' );
	if ( ! form.length ) {
		return;
	}
	var paso = 1;
	var asistente = $( '<div class="pf-asistente"></div>' );
	var panel = function ( n, titulo ) {
		return $( '<section class="pf-paso-panel" data-paso="' + n + '"><h3>' + titulo + '</h3><div class="pf-campos"></div><div class="pf-paso-botones"></div></section>' );
	};
	var p1 = panel( 1, '1. Tus datos' );
	var p2 = panel( 2, '2. Despacho' );
	var p3 = panel( 3, '3. Pago' );
	asistente.append( p1, p2, p3 );
	form.prepend( asistente );

	var mover = function ( ids, destino ) {
		$.each( ids, function ( i, id ) {
			var fila = $( '#' + id + '_field' );
			if ( fila.length ) {
				destino.append( fila );
			}
		} );
	};
	mover( [ 'billing_first_name', 'billing_last_name', 'billing_rut', 'billing_email', 'billing_phone' ], p1.find( '.pf-campos' ) );
	p1.find( '.pf-campos' ).after( form.find( '.woocommerce-account-fields' ) );
	mover( [ 'billing_country', 'billing_state', 'billing_city', 'billing_address_1', 'billing_address_2', 'billing_postcode', 'billing_company' ], p2.find( '.pf-campos' ) );
	var envio = $( '<div class="pf-envio-opciones"><b>¿Cómo quieres recibirlo?</b><div class="pf-envio-lista"></div></div>' );
	p2.find( '.pf-campos' ).after( envio );
	envio.after( form.find( '.woocommerce-shipping-fields' ), form.find( '.woocommerce-additional-fields' ) );
	p3.find( '.pf-campos' ).append( $( '#payment' ) );
	$( '#customer_details' ).hide();

	p1.find( '.pf-paso-botones' ).append( '<button type="button" class="button pf-continuar" data-ir="2">Continuar a despacho</button>' );
	p2.find( '.pf-paso-botones' ).append( '<button type="button" class="pf-boton-chico" data-ir="1">Volver</button><button type="button" class="button pf-continuar" data-ir="3">Continuar a pago</button>' );

	function valido( contenedor ) {
		var ok = true;
		contenedor.find( '.validate-required' ).filter( ':visible' ).each( function () {
			var fila = $( this );
			var campo = fila.find( 'input, select, textarea' ).first();
			var vacio = campo.is( ':checkbox' ) ? ! campo.is( ':checked' ) : ! $.trim( campo.val() || '' );
			fila.toggleClass( 'woocommerce-invalid woocommerce-invalid-required-field', vacio );
			if ( vacio ) {
				ok = false;
			}
		} );
		var correo = contenedor.find( '#billing_email' );
		if ( correo.length && correo.val() && ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( correo.val() ) ) {
			correo.closest( '.form-row' ).addClass( 'woocommerce-invalid' );
			ok = false;
		}
		return ok;
	}

	function ir( n ) {
		if ( n > paso && ! valido( asistente.find( '[data-paso="' + paso + '"]' ) ) ) {
			var primero = asistente.find( '.woocommerce-invalid' ).first();
			if ( primero.length ) {
				$( 'html, body' ).animate( { scrollTop: primero.offset().top - 120 }, 250 );
			}
			return;
		}
		paso = n;
		asistente.find( '.pf-paso-panel' ).each( function () {
			$( this ).toggleClass( 'activo', parseInt( $( this ).attr( 'data-paso' ), 10 ) === paso );
		} );
		$( '#pf-pasos li' ).each( function ( i ) {
			$( this ).toggleClass( 'hecho', i + 1 < paso ).toggleClass( 'actual', i + 1 === paso );
		} );
		var arriba = $( '#pf-pasos' );
		if ( arriba.length && $( window ).scrollTop() > arriba.offset().top ) {
			$( 'html, body' ).animate( { scrollTop: arriba.offset().top - 40 }, 200 );
		}
	}
	form.on( 'click', '[data-ir]', function () {
		ir( parseInt( $( this ).attr( 'data-ir' ), 10 ) );
	} );

	/* Opciones de despacho en el paso 2 (reflejan las de WooCommerce, que se recalculan con la dirección). */
	function construirEnvio() {
		var lista = envio.find( '.pf-envio-lista' ).empty();
		var reales = $( '#order_review' ).find( 'input.shipping_method, select.shipping_method' );
		if ( ! reales.length ) {
			lista.append( '<p class="pf-gris">Completa tu dirección para ver las opciones de despacho.</p>' );
			return;
		}
		var elegido = '';
		reales.each( function () {
			var real = $( this );
			var etiqueta = $( 'label[for="' + real.attr( 'id' ) + '"]' ).html() || real.closest( 'li' ).find( 'label' ).html() || '';
			var marcado = real.is( ':checked' ) || real.attr( 'type' ) === 'hidden';
			if ( marcado ) {
				elegido = etiqueta;
			}
			var item = $( '<label class="pf-envio-item"><input type="radio" name="pf_envio"><span></span></label>' );
			item.toggleClass( 'activo', marcado ).find( 'span' ).html( etiqueta );
			item.find( 'input' ).prop( 'checked', marcado ).on( 'change', function () {
				real.prop( 'checked', true ).trigger( 'change' );
			} );
			lista.append( item );
		} );
		var celda = $( '#order_review tr.woocommerce-shipping-totals td' );
		celda.find( '.pf-envio-elegido' ).remove();
		celda.append( $( '<span class="pf-envio-elegido"></span>' ).html( elegido ) );
	}

	/* Botón «Volver» junto a «Pagar» (la sección de pago se vuelve a dibujar en cada actualización). */
	function colocarVolver() {
		var lugar = $( '#payment .place-order' );
		if ( lugar.length && ! lugar.find( '[data-ir]' ).length ) {
			$( '<button type="button" class="pf-boton-chico pf-volver" data-ir="2">Volver</button>' ).insertBefore( lugar.find( '#place_order' ) );
		}
	}

	$( document.body ).on( 'updated_checkout', function () {
		construirEnvio();
		colocarVolver();
	} );
	construirEnvio();
	colocarVolver();
	ir( 1 );
} )( jQuery );
