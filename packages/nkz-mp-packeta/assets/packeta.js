/* NKZ Marketplace – Zásilkovna checkout widget */
( function ( $ ) {
	'use strict';

	function isPacketaChosen() {
		var chosen = $( 'input[name^="shipping_method"]:checked' ).val()
			|| $( 'input[name^="shipping_method"]' ).val();
		return chosen && chosen.indexOf( 'nkzmp_packeta' ) === 0;
	}

	function toggleRow() {
		var $row = $( '.nkzmp-packeta-row' );
		if ( isPacketaChosen() ) {
			$row.show();
		} else {
			$row.hide();
		}
	}

	function openWidget() {
		if ( typeof Packeta === 'undefined' || ! Packeta.Widget ) {
			window.alert( 'Packeta widget se nenačetl. Zkus obnovit stránku.' );
			return;
		}
		Packeta.Widget.pick( nkzmpPacketa.apiKey, function ( point ) {
			if ( ! point ) {
				return;
			}
			var label = point.name || point.place || ( point.street ? point.street + ', ' + point.city : point.id );
			$( '#nkzmp-packeta-point-id' ).val( point.id );
			$( '#nkzmp-packeta-point-name' ).val( label );
			$( '#nkzmp-packeta-name' ).text( label );
			$( '#nkzmp-packeta-selected' ).show();

			// Ulož do session přes AJAX (přežije refresh checkoutu).
			$.post( nkzmpPacketa.ajaxUrl, {
				action: 'nkzmp_packeta_set_point',
				nonce: nkzmpPacketa.nonce,
				id: point.id,
				name: label
			} );
		}, { country: 'cz', language: 'cs' } );
	}

	$( document ).on( 'click', '#nkzmp-packeta-pick', function ( e ) {
		e.preventDefault();
		openWidget();
	} );

	// Reaguj na změnu shipping metody + re-render checkoutu.
	$( document ).on( 'change', 'input[name^="shipping_method"]', toggleRow );
	$( document.body ).on( 'updated_checkout', toggleRow );
	$( toggleRow );

} )( jQuery );
