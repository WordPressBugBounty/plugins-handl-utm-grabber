// Fills placeholders (.handl-entry-insight) with insights via POST to handl_analyze_posted.
// Non-blocking; attribution summary/skeleton already rendered.
(function () {
	var cfg = window.handlInsightCard;
	if ( ! cfg || ! cfg.ajax_url ) {
		return;
	}

	function button( cta ) {
		var cls = 'button button-small';
		if ( cta.variant === 'primary' || cta.variant === 'upgrade' ) {
			cls += ' button-primary';
		}
		if ( cta.variant === 'upgrade' ) {
			cls += ' handl-btn-upgrade';
		}
		var a = document.createElement( 'a' );
		a.className = cls;
		a.href = cta.url;
		a.textContent = cta.label + ( cta.external ? ' \u2192' : '' );
		if ( cta.external ) {
			a.target = '_blank';
			a.rel = 'noopener';
		}
		return a;
	}

	function render( el, card ) {
		el.innerHTML = '';

		if ( ! card ) {
			el.className = 'handl-entry-insight handl-sev-info';
			var none = document.createElement( 'div' );
			none.className = 'handl-entry-action';
			none.textContent = 'No attribution insight for this lead.';
			el.appendChild( none );
			return;
		}

		el.className = 'handl-entry-insight handl-sev-' + ( card.severity || 'info' );

		var msg = document.createElement( 'div' );
		msg.className = 'handl-entry-msg';
		msg.textContent = card.message || '';
		el.appendChild( msg );

		if ( card.action ) {
			var act = document.createElement( 'div' );
			act.className = 'handl-entry-action';
			act.textContent = card.action;
			el.appendChild( act );
		}

		if ( card.cta && card.cta.url && card.cta.label ) {
			var links = document.createElement( 'div' );
			links.className = 'handl-entry-links';
			var btns = document.createElement( 'div' );
			btns.className = 'handl-entry-btns';
			btns.appendChild( button( card.cta ) );
			links.appendChild( btns );
			el.appendChild( links );
		}
	}

	function fail( el ) {
		el.innerHTML = '';
		el.className = 'handl-entry-insight handl-sev-info';
		var d = document.createElement( 'div' );
		d.className = 'handl-entry-action';
		d.textContent = "Couldn't analyze this lead - refresh to retry.";
		el.appendChild( d );
	}

	function run() {
		var els = document.querySelectorAll( '.handl-entry-insight' );
		if ( ! els.length ) {
			return;
		}

		var body = new URLSearchParams();
		body.set( 'action', 'handl_analyze_posted' );
		body.set( 'nonce', cfg.nonce || '' );
		body.set( 'posted', JSON.stringify( cfg.posted || {} ) );

		fetch( cfg.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					els.forEach( fail );
					return;
				}
				var card = res.data ? res.data.card : null;
				els.forEach( function ( el ) { render( el, card ); } );
			} )
			.catch( function () {
				els.forEach( fail );
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
})();
