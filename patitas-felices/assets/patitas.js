/* Calculadora de despacho por comuna en la ficha de producto. */
( function () {
	var sel = document.getElementById( 'pf-comuna' );
	var res = document.getElementById( 'pf-despacho-res' );
	if ( ! sel || ! res ) {
		return;
	}
	sel.addEventListener( 'change', function () {
		var partes = sel.value.split( '|' );
		var costo = '$' + Number( partes[ 1 ] ).toLocaleString( 'es-CL' );
		res.innerHTML = partes[ 0 ] === 'hoy'
			? 'Llega <b>hoy</b> si compras antes de las 14:00 · ' + costo + ' (gratis sobre $40.000). También puedes retirar en tienda sin costo.'
			: 'Llega en <b>2 a 4 días hábiles</b> por courier · ' + costo + ' (gratis sobre $60.000).';
	} );
} )();
