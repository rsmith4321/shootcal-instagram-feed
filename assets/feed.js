( function () {
	'use strict';

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

		window.fetch( endpoint.toString(), {
			cache: 'no-store',
			credentials: 'omit',
			headers: { Accept: 'application/json' },
			method: 'GET',
			referrerPolicy: 'same-origin',
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
