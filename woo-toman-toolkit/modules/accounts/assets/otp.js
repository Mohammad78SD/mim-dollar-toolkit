( function () {
	'use strict';
	var cfg = window.wttAccOtp;
	var box = document.getElementById( 'wtt-acc-otp' );
	if ( ! cfg || ! box ) {
		return;
	}
	var $ = function ( sel ) {
		return box.querySelector( sel );
	};
	var msg = $( '.wtt-acc-msg' );
	var stepMobile = $( '.wtt-acc-step-mobile' );
	var stepCode = $( '.wtt-acc-step-code' );
	var mobileInput = $( '#wtt-acc-mobile' );
	var codeInput = $( '#wtt-acc-code' );
	var resendBtn = $( '.wtt-acc-resend' );
	var sentTo = $( '.wtt-acc-sent-to' );
	var timer = null;
	var busy = false;

	box.hidden = false;

	var digits = function ( s ) {
		return String( s ).replace( /[۰-۹]/g, function ( d ) {
			return String( d.charCodeAt( 0 ) - 0x06F0 );
		} ).replace( /[٠-٩]/g, function ( d ) {
			return String( d.charCodeAt( 0 ) - 0x0660 );
		} );
	};
	var isFa = !! cfg.isFa || /^fa/i.test( document.documentElement.lang || '' );
	var faDigits = function ( s ) {
		return isFa ? String( s ).replace( /[0-9]/g, function ( d ) {
			return String.fromCharCode( 0x06F0 + parseInt( d, 10 ) );
		} ) : String( s );
	};
	var say = function ( text, kind ) {
		msg.textContent = text || '';
		msg.className = 'wtt-acc-msg' + ( kind ? ' is-' + kind : '' );
	};
	var setBusy = function ( on ) {
		busy = on;
		Array.prototype.forEach.call( box.querySelectorAll( 'button[type=submit]' ), function ( b ) {
			b.disabled = on;
		} );
	};
	var send = function ( route, body ) {
		return fetch( cfg.restUrl + route, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
			body: JSON.stringify( body )
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				return { ok: res.ok, status: res.status, data: data };
			} );
		} );
	};
	var badNonce = function ( r ) {
		var code = r.data && r.data.code;
		return r.status === 403 && ( code === 'rest_cookie_invalid_nonce' || code === 'wtt_acc_bad_nonce' );
	};
	// A cached page carries a stale nonce: fetch a fresh one and retry the request once.
	var post = function ( route, body ) {
		return send( route, body ).then( function ( r ) {
			if ( ! badNonce( r ) ) {
				return r;
			}
			return fetch( cfg.restUrl + 'nonce', {
				method: 'GET',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { Accept: 'application/json' }
			} ).then( function ( res ) {
				return res.ok ? res.json() : null;
			} ).then( function ( data ) {
				if ( ! data || ! data.nonce ) {
					return r;
				}
				cfg.nonce = data.nonce;
				return send( route, body );
			}, function () {
				return r;
			} );
		} );
	};
	var countdown = function ( seconds ) {
		clearInterval( timer );
		var left = Math.max( 0, parseInt( seconds, 10 ) || 0 );
		var tick = function () {
			if ( left <= 0 ) {
				clearInterval( timer );
				resendBtn.disabled = false;
				resendBtn.textContent = cfg.i18n.resend;
				return;
			}
			resendBtn.disabled = true;
			resendBtn.textContent = cfg.i18n.resendIn.replace( '%s', faDigits( left ) );
			left--;
		};
		tick();
		timer = setInterval( tick, 1000 );
	};
	var requestCode = function () {
		var mobile = digits( mobileInput.value ).replace( /\s+/g, '' );
		if ( ! /^(\+98|0098|98|0)?9\d{9}$/.test( mobile ) ) {
			say( cfg.i18n.badMobile, 'error' );
			return;
		}
		if ( busy ) {
			return;
		}
		setBusy( true );
		say( cfg.i18n.sending );
		post( 'request', { mobile: mobile } ).then( function ( r ) {
			setBusy( false );
			if ( r.ok ) {
				say( r.data.message, 'ok' );
				stepMobile.hidden = true;
				stepCode.hidden = false;
				sentTo.textContent = mobile;
				sentTo.setAttribute( 'dir', 'ltr' );
				codeInput.value = '';
				codeInput.focus();
				countdown( r.data.cooldown || cfg.cooldown );
			} else {
				say( ( r.data && r.data.message ) || cfg.i18n.network, 'error' );
				if ( r.status === 429 && r.data && r.data.data && r.data.data.retry_after && ! stepCode.hidden ) {
					countdown( r.data.data.retry_after );
				}
			}
		}, function () {
			setBusy( false );
			say( cfg.i18n.network, 'error' );
		} );
	};

	stepMobile.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		requestCode();
	} );
	resendBtn.addEventListener( 'click', function () {
		if ( ! resendBtn.disabled ) {
			requestCode();
		}
	} );
	$( '.wtt-acc-change' ).addEventListener( 'click', function () {
		clearInterval( timer );
		stepCode.hidden = true;
		stepMobile.hidden = false;
		say( '' );
		mobileInput.focus();
	} );
	stepCode.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var code = digits( codeInput.value ).replace( /\D+/g, '' );
		if ( ! code ) {
			say( cfg.i18n.badCode, 'error' );
			return;
		}
		if ( busy ) {
			return;
		}
		setBusy( true );
		say( cfg.i18n.sending );
		post( 'verify', { mobile: digits( mobileInput.value ).replace( /\s+/g, '' ), code: code, redirect_to: cfg.redirect } ).then( function ( r ) {
			if ( r.ok && r.data && r.data.redirect ) {
				window.location.assign( r.data.redirect );
				return;
			}
			setBusy( false );
			say( ( r.data && r.data.message ) || cfg.i18n.network, 'error' );
		}, function () {
			setBusy( false );
			say( cfg.i18n.network, 'error' );
		} );
	} );

	// Password login: collapsed behind a toggle.
	var pwForm = document.querySelector( 'form.woocommerce-form-login' );
	var toggleWrap = document.querySelector( '.wtt-acc-pw-toggle-wrap' );
	if ( pwForm && toggleWrap ) {
		var target = pwForm.closest( '.u-column1' ) || pwForm;
		var toggle = toggleWrap.querySelector( '.wtt-acc-pw-toggle' );
		var heading = ( target === pwForm && pwForm.previousElementSibling && pwForm.previousElementSibling.tagName === 'H2' ) ? pwForm.previousElementSibling : null;
		var setPw = function ( show ) {
			target.classList.toggle( 'wtt-acc-hide-pw', ! show );
			if ( heading ) {
				heading.classList.toggle( 'wtt-acc-hide-pw', ! show );
			}
			toggle.textContent = show ? cfg.i18n.hidePw : cfg.i18n.showPw;
			toggle.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
		};
		if ( cfg.showPassword ) {
			toggleWrap.hidden = false;
			toggleWrap.parentNode.insertBefore( toggleWrap, box.nextSibling );
			toggle.addEventListener( 'click', function () {
				setPw( target.classList.contains( 'wtt-acc-hide-pw' ) );
			} );
		}
		setPw( false );
	}
}() );
