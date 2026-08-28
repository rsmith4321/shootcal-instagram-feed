( function () {
	'use strict';

	let stylesheetPromise;

	function waitForStylesheet( link, loader ) {
		return new Promise( function ( resolve ) {
			let settled = false;
			let timeout;
			const finish = function ( loaded ) {
				if ( settled ) {
					return;
				}
				settled = true;
				window.clearTimeout( timeout );
				loader.dataset.styles = loaded ? 'loaded' : 'failed';
				resolve();
			};

			link.addEventListener( 'load', function () {
				finish( true );
			}, { once: true } );
			link.addEventListener( 'error', function () {
				finish( false );
			}, { once: true } );

			if ( link.sheet ) {
				finish( true );
				return;
			}

			timeout = window.setTimeout( function () {
				finish( !! link.sheet );
			}, 4000 );
		} );
	}

	function ensureStylesheet( loader ) {
		if ( stylesheetPromise ) {
			return stylesheetPromise;
		}

		const existing = document.querySelector( 'link[data-shootcal-instagram-feed-runtime], link[href*="/shootcal-instagram-feed/assets/feed.css"]' );
		if (
			existing &&
			existing.relList.contains( 'stylesheet' ) &&
			! existing.disabled &&
			( ! existing.media || window.matchMedia( existing.media ).matches )
		) {
			stylesheetPromise = waitForStylesheet( existing, loader );
			return stylesheetPromise;
		}

		if ( ! loader.dataset.stylesheet ) {
			return Promise.resolve();
		}

		let stylesheet;
		try {
			stylesheet = new URL( loader.dataset.stylesheet, window.location.origin );
		} catch ( error ) {
			return Promise.resolve();
		}

		const link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.href = stylesheet.toString();
		link.dataset.shootcalInstagramFeedRuntime = 'true';
		stylesheetPromise = waitForStylesheet( link, loader );
		document.head.appendChild( link );

		return stylesheetPromise;
	}

	function loadFeed( loader ) {
		if ( loader.dataset.loading === 'true' || loader.dataset.loaded === 'true' ) {
			return;
		}

		loader.dataset.loading = 'true';

		let endpoint;
		try {
			endpoint = new URL( loader.dataset.endpoint, window.location.origin );
		} catch ( error ) {
			loader.dataset.loading = 'false';
			return;
		}

		[ 'hashtag', 'limit', 'columns', 'mobileLimit', 'follow', 'class' ].forEach( function ( key ) {
			const value = loader.dataset[ key ];
			if ( undefined !== value && '' !== value ) {
				const parameter = 'mobileLimit' === key ? 'mobile_limit' : key;
				endpoint.searchParams.set( parameter, value );
			}
		} );

		ensureStylesheet( loader ).then( function () {
			return window.fetch( endpoint.toString(), {
				cache: 'no-store',
				credentials: 'omit',
				headers: { Accept: 'application/json' },
				method: 'GET',
				referrerPolicy: 'same-origin',
			} );
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Instagram feed request failed.' );
				}
				return response.json();
			} )
			.then( function ( payload ) {
				if ( payload && 'string' === typeof payload.html && '' !== payload.html.trim() ) {
					loader.innerHTML = payload.html;
					loader.dataset.loaded = 'true';
				}
			} )
			.catch( function () {
				loader.dataset.failed = 'true';
			} )
			.finally( function () {
				loader.dataset.loading = 'false';
			} );
	}

	function initialize() {
		document.querySelectorAll( '.shootcal-instagram-feed-loader' ).forEach( loadFeed );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initialize, { once: true } );
	} else {
		initialize();
	}
}() );
