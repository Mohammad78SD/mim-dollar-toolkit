( function ( $ ) {
	'use strict';
	$( document ).on( 'click', '.wtt-pi-media-pick', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.wtt-pi-media' );
		var frame = wp.media( {
			title: window.wttPiAdmin ? window.wttPiAdmin.choose : 'Choose image',
			button: { text: window.wttPiAdmin ? window.wttPiAdmin.use : 'Use this image' },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
			$box.find( 'input[type=hidden]' ).val( att.id );
			$box.find( '.wtt-pi-media-preview' ).empty().append( $( '<img>' ).attr( 'src', url ).css( { maxWidth: '120px', height: 'auto' } ) );
		} );
		frame.open();
	} );
	$( document ).on( 'click', '.wtt-pi-media-clear', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.wtt-pi-media' );
		$box.find( 'input[type=hidden]' ).val( '' );
		$box.find( '.wtt-pi-media-preview' ).empty();
	} );
} )( jQuery );
