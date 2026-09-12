( function () {
	'use strict';
	function render( node ) {
		if ( ! window.qrcodegen || ! window.qrcodegen.QrCode ) {
			return;
		}
		var value = node.getAttribute( 'data-atora-qr' );
		var size = parseInt( node.getAttribute( 'data-atora-qr-size' ) || '164', 10 );
		var qr = window.qrcodegen.QrCode.encodeText( value, window.qrcodegen.QrCode.Ecc.MEDIUM );
		var svg = qr.toSvgString( 4 );
		node.innerHTML = svg;
		var image = node.querySelector( 'svg' );
		if ( image ) {
			image.setAttribute( 'width', size );
			image.setAttribute( 'height', size );
			image.setAttribute( 'role', 'img' );
			image.setAttribute( 'aria-label', 'QR para verificar la credencial' );
		}
	}
	document.querySelectorAll( '[data-atora-qr]' ).forEach( render );
}() );
