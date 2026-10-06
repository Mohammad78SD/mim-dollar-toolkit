( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.wtt-sms-copy' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var target = document.getElementById( btn.getAttribute( 'data-target' ) );
		if ( ! target ) {
			return;
		}
		var done = function () {
			var old = btn.textContent;
			btn.textContent = ( window.wttSmsAdmin && window.wttSmsAdmin.copied ) || 'Copied';
			setTimeout( function () {
				btn.textContent = old;
			}, 1500 );
		};
		target.focus();
		target.select();
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( target.value ).then( done, function () {
				document.execCommand( 'copy' );
				done();
			} );
		} else {
			document.execCommand( 'copy' );
			done();
		}
	} );
}() );
