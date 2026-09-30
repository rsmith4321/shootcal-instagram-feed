( function () {
	'use strict';

	// The whole feed is lazy: nothing is fetched and no image loads until the
	// feed container nears the viewport. Instagram images injected by script
	// are invisible to native and plugin lazy-loaders (they scan markup, not
	// later DOM insertions), so the script controls image timing itself and
	// injects everything eagerly at the moment the feed is about to be seen.
	var NEAR_VIEWPORT = '600px 0px';

	var stylesheetPromise;
    var nextGridId = 0;

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

    function initializeMore( root, restoredCount ) {
        root.querySelectorAll( '[data-scif-more="true"]' ).forEach( function ( shell ) {
            if ( shell.dataset.moreReady ) return;
            shell.dataset.moreReady = 'true';
            var grid = shell.querySelector( '.shootcal-instagram-feed' );
            var button = shell.querySelector( '.shootcal-instagram-feed__more-button' );
            var status = shell.querySelector( '[role="status"]' );
            if ( ! grid || ! button ) return;
            // Separate REST responses can each contain the same wp_unique_id.
            // Allocate in the live document so every button controls its own grid.
            var gridId;
            do { gridId = 'scif-posts-' + ( ++nextGridId ); } while ( document.getElementById( gridId ) );
            grid.id = gridId;
            button.setAttribute( 'aria-controls', gridId );
            // Preserve the authored phone count, but let View more reveal those
            // hidden entries too. Inert templates do not request their images.
            if ( window.matchMedia( '(max-width: 520px)' ).matches ) {
                grid.querySelectorAll( '.shootcal-instagram-feed__item--mobile-hidden' ).forEach( function ( item ) {
                    var template = document.createElement( 'template' );
                    template.dataset.scifDeferred = '';
                    item.replaceWith( template );
                    template.content.appendChild( item );
                } );
            }
            grid.querySelectorAll( '.shootcal-instagram-feed__item--mobile-hidden' ).forEach( function ( item ) {
                item.classList.remove( 'shootcal-instagram-feed__item--mobile-hidden' );
            } );
            var pending = function () { return Array.prototype.slice.call( grid.querySelectorAll( 'template[data-scif-deferred]' ) ); };
            var shown = function () { return grid.querySelectorAll( 'a.shootcal-instagram-feed__item' ).length; };
            var total = shown() + pending().length;
            var reveal = function ( count ) {
                var first;
                pending().slice( 0, count ).forEach( function ( template ) {
                    var item = template.content.querySelector( 'a' );
                    item.classList.remove( 'shootcal-instagram-feed__item--mobile-hidden' );
                    if ( ! first ) first = item;
                    template.replaceWith( template.content );
                    loadImagesNow( item );
                } );
                button.hidden = pending().length === 0;
                return first;
            };
            reveal( Math.max( 0, Math.min( 30, restoredCount || 0 ) - shown() ) );
            if ( restoredCount ) shell.dataset.scifVisible = String( shown() );
            button.addEventListener( 'click', function () {
                var tracks = window.getComputedStyle( grid ).gridTemplateColumns;
                var columns = tracks && tracks !== 'none' && tracks.indexOf( 'repeat(' ) === -1 ? tracks.trim().split( /\s+/ ).length : 1;
                var first = reveal( columns );
                shell.dataset.scifVisible = String( shown() );
                if ( status ) status.textContent = status.dataset.message.replace( '%1$d', shown() ).replace( '%2$d', total );
                if ( first ) first.focus( { preventScroll: true } );
            } );
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

		[ 'feed', 'hashtag', 'exclude', 'limit', 'columns', 'mobileLimit', 'follow', 'more', 'class' ].forEach( function ( key ) {
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
					var previousShell = loader.querySelector( '[data-scif-more]' );
                    var previousCount = Number( previousShell && previousShell.dataset.scifVisible ) || 0;
                    loader.innerHTML = payload.html;
					loader.dataset.loaded = 'true';
                    initializeMore( loader, previousCount );
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
        initializeMore( document );
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
