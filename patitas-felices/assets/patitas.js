/* Interacciones de la tienda Patitas Felices (sobre los formularios estándar de WooCommerce). */
( function ( $ ) {
	var clp = function ( n ) {
		return '$' + Math.round( n ).toLocaleString( 'es-CL' );
	};
	var kgDe = function ( texto ) {
		var m = /([\d.,]+)\s*kg/i.exec( texto || '' );
		return m ? parseFloat( m[ 1 ].replace( ',', '.' ) ) : 0;
	};

	/* Botones − / + en los campos de cantidad. */
	function pasos( raiz ) {
		$( raiz ).find( '.quantity' ).each( function () {
			var caja = $( this );
			var input = caja.find( 'input.qty' );
			if ( caja.hasClass( 'pf-paso' ) || ! input.length || input.attr( 'type' ) === 'hidden' ) {
				return;
			}
			caja.addClass( 'pf-paso' );
			var menos = $( '<button type="button" class="pf-menos" aria-label="Quitar uno">−</button>' );
			var mas = $( '<button type="button" class="pf-mas-uno" aria-label="Agregar uno">+</button>' );
			input.before( menos ).after( mas );
			var cambiar = function ( d ) {
				var min = parseFloat( input.attr( 'min' ) ) || 0;
				var max = parseFloat( input.attr( 'max' ) ) || 9999;
				var v = Math.min( max, Math.max( min, ( parseFloat( input.val() ) || 0 ) + d ) );
				input.val( v ).trigger( 'change' );
				if ( caja.closest( '.woocommerce-cart-form' ).length ) {
					var boton = $( '.woocommerce-cart-form :input[name="update_cart"]' );
					boton.prop( 'disabled', false ).attr( 'aria-disabled', 'false' ).trigger( 'click' );
				}
			};
			menos.on( 'click', function () { cambiar( -1 ); } );
			mas.on( 'click', function () { cambiar( 1 ); } );
		} );
	}
	pasos( document.body );
	$( document.body ).on( 'updated_wc_div updated_cart_totals', function () { pasos( document.body ); } );

	/* Formatos como botones (3 kg · 8 kg · 15 kg), igual que el prototipo. */
	$( 'form.variations_form' ).each( function () {
		var form = $( this );
		form.find( 'table.variations select' ).each( function () {
			var select = $( this );
			var caja = $( '<div class="pf-formatos" role="radiogroup"></div>' );
			select.find( 'option' ).each( function () {
				var v = $( this ).val();
				if ( ! v ) {
					return;
				}
				$( '<button type="button" class="pf-formato-btn"></button>' ).text( $( this ).text() ).attr( 'data-valor', v ).appendTo( caja );
			} );
			select.addClass( 'pf-oculto' ).after( caja );
			var marcar = function () {
				caja.find( 'button' ).each( function () {
					var activo = $( this ).attr( 'data-valor' ) === select.val();
					$( this ).toggleClass( 'activo', activo ).attr( 'aria-pressed', activo ? 'true' : 'false' );
				} );
			};
			caja.on( 'click', 'button', function () {
				select.val( $( this ).attr( 'data-valor' ) ).trigger( 'change' );
				marcar();
			} );
			select.on( 'change', marcar );
			marcar();
		} );
		form.on( 'found_variation', function ( e, variacion ) {
			var precio = variacion.display_price;
			var formato = '';
			$.each( variacion.attributes, function ( k, v ) { formato = v; } );
			if ( variacion.price_html ) {
				$( '.pf-precio-actual' ).html( $( variacion.price_html ).html() || variacion.price_html );
			}
			var kg = kgDe( formato );
			$( '.pf-por-kg' ).text( kg ? clp( precio / kg ) + ' por kg · IVA incluido' : 'IVA incluido' );
			$( '.pf-precio-unico' ).text( clp( precio ) );
			$( '.pf-precio-sub' ).text( clp( precio * 0.95 ) );
		} );
	} );

	/* Opción de compra seleccionada (suscripción o compra única). */
	$( document ).on( 'change', '.pf-compra-opciones input[type=radio]', function () {
		$( '.pf-compra-opciones .pf-opcion' ).removeClass( 'activa' );
		$( this ).closest( '.pf-opcion' ).addClass( 'activa' );
	} );
	$( '.pf-compra-opciones input:checked' ).trigger( 'change' );

	/* Calculadora de despacho por comuna. */
	var opciones = {
		local: [ [ 'Despacho mismo día (antes de 14:00)', '$2.990' ], [ 'Despacho programado (elige día)', '$1.990' ], [ 'Retiro en tienda Copiapó', 'Gratis' ] ],
		region: [ [ 'Courier a regiones (2 a 4 días hábiles)', '$5.990' ], [ 'Courier gratis sobre $60.000', 'Gratis' ], [ 'Retiro en tienda Copiapó', 'Gratis' ] ]
	};
	$( '#pf-comuna' ).on( 'change', function () {
		var lista = $( '#pf-despacho-lista' ).empty();
		$.each( opciones[ this.value ] || opciones.local, function ( i, o ) {
			$( '<li></li>' ).append( $( '<span></span>' ).text( o[ 0 ] ), $( '<b></b>' ).text( o[ 1 ] ) ).appendTo( lista );
		} );
	} );

	/* Copiar el código de referido. */
	$( document ).on( 'click', '.pf-copiar', function () {
		var b = $( this );
		var codigo = b.attr( 'data-codigo' );
		var listo = function () { b.text( '¡Copiado!' ); setTimeout( function () { b.text( 'Copiar código' ); }, 1800 ); };
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( codigo ).then( listo, listo );
		} else {
			listo();
		}
	} );
} )( jQuery );
