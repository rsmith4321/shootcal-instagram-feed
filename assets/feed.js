( function () {
	'use strict';

	// The whole feed is lazy: nothing is fetched and no image loads until the
	// feed container nears the viewport. Instagram images injected by script
	// are invisible to native and plugin lazy-loaders (they scan markup, not
	// later DOM insertions), so the script controls image timing itself and
	// injects everything eagerly at the moment the feed is about to be seen.
	var NEAR_VIEWPORT = '600px 0px';

	var stylesheetPromise;

	function waitForStylesheet( link, loader ) {
		return new Promise( function ( resolve ) {
			var settled = false;
			var timeout;
			var finish = function ( loaded ) {
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

		var existing = document.querySelector( 'link[data-shootcal-instagram-feed-runtime], link[href*="/shootcal-social-feed/assets/feed.css"], link[href*="/shootcal-instagram-feed/assets/feed.css"]' );
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

		var stylesheet;
		try {
			stylesheet = new URL( loader.dataset.stylesheet, window.location.origin );
		} catch ( error ) {
			return Promise.resolve();
		}

		var link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.href = stylesheet.toString();
		link.dataset.shootcalInstagramFeedRuntime = 'true';
		stylesheetPromise = waitForStylesheet( link, loader );
		document.head.appendChild( link );

		return stylesheetPromise;
	}

	function loadImagesNow( loader ) {
		loader.querySelectorAll( 'img[loading="lazy"]' ).forEach( function ( image ) {
			image.loading = 'eager';
		} );
	}

	function loadFeed( loader ) {
		if ( loader.dataset.loading === 'true' || loader.dataset.loaded === 'true' ) {
			return;
		}

		loader.dataset.loading = 'true';

		// The server-rendered fallback should start painting while the fresh
		// markup is fetched; its images are usually identical, so they come
		// straight from HTTP cache after the swap.
		loadImagesNow( loader );

		var endpoint;
		try {
			endpoint = new URL( loader.dataset.endpoint, window.location.origin );
		} catch ( error ) {
			loader.dataset.loading = 'false';
			return;
		}

		[ 'feed', 'hashtag', 'exclude', 'limit', 'columns', 'mobileLimit', 'follow', 'class' ].forEach( function ( key ) {
			var value = loader.dataset[ key ];
			if ( undefined !== value && '' !== value ) {
				var parameter = 'mobileLimit' === key ? 'mobile_limit' : key;
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
					loadImagesNow( loader );
				}
			} )
			.catch( function () {
				loader.dataset.failed = 'true';
			} )
			.finally( function () {
				loader.dataset.loading = 'false';
			} );
	}

	function observeLoaders( loaders ) {
		if ( ! loaders.length ) {
			return;
		}

		if ( ! ( 'IntersectionObserver' in window ) ) {
			loaders.forEach( loadFeed );
			return;
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						observer.unobserve( entry.target );
						loadFeed( entry.target );
					}
				} );
			},
			{ rootMargin: NEAR_VIEWPORT }
		);

		loaders.forEach( function ( loader ) {
			observer.observe( loader );
		} );
	}

	function initialize() {
		observeLoaders( Array.prototype.slice.call( document.querySelectorAll( '.shootcal-instagram-feed-loader' ) ) );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initialize, { once: true } );
	} else {
		initialize();
	}

	// A back/forward-cache restore freezes dataset state and drops observer
	// targets: a loader caught mid-fetch would otherwise be stranded on the
	// static fallback forever. Reset unfinished loaders and observe them again.
	window.addEventListener( 'pageshow', function ( event ) {
		if ( ! event.persisted ) {
			return;
		}
		var unfinished = Array.prototype.filter.call(
			document.querySelectorAll( '.shootcal-instagram-feed-loader' ),
			function ( loader ) {
				return loader.dataset.loaded !== 'true';
			}
		);
		unfinished.forEach( function ( loader ) {
			delete loader.dataset.loading;
			delete loader.dataset.failed;
		} );
		observeLoaders( unfinished );
	} );
}() );
