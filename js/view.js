/**
 * Coywolf Video Manager — front-end player, plays/likes, lightbox, lazy-load.
 * Vanilla JS, no build step.
 */
( function () {
	'use strict';

	var cfg = window.coywolfCVMView || {};
	var sdkPromise = null;

	function forEach( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

	function formatNumber( n ) {
		try {
			return Number( n ).toLocaleString();
		} catch ( e ) {
			return '' + n;
		}
	}

	function api( path, body ) {
		var headers = { 'Content-Type': 'application/json' };
		if ( cfg.nonce ) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		return fetch( cfg.restUrl + path, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : null
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function loadStreamSDK() {
		if ( window.Stream ) {
			return Promise.resolve( window.Stream );
		}
		if ( sdkPromise ) {
			return sdkPromise;
		}
		sdkPromise = new Promise( function ( resolve ) {
			var s = document.createElement( 'script' );
			s.src = 'https://embed.cloudflarestream.com/embed/sdk.latest.js';
			s.async = true;
			s.onload = function () {
				resolve( window.Stream );
			};
			s.onerror = function () {
				resolve( null );
			};
			document.head.appendChild( s );
		} );
		return sdkPromise;
	}

	/**
	 * Count a play once the viewer is genuinely past ~2s (avoids autoplay/preroll
	 * inflation). Cloudflare player via the Stream SDK; the OSS players via the
	 * native <video> 'timeupdate'.
	 */
	function trackPlays( iframe, container ) {
		var uid = container.getAttribute( 'data-uid' );
		if ( ! uid ) {
			return;
		}
		loadStreamSDK().then( function ( Stream ) {
			if ( ! Stream ) {
				return;
			}
			var player = Stream( iframe );
			var counted = false;
			player.addEventListener( 'timeupdate', function () {
				if ( counted || ! player.currentTime || player.currentTime <= 2 ) {
					return;
				}
				counted = true;
				api( '/play/' + encodeURIComponent( uid ) ).then( function ( res ) {
					if ( res && 'undefined' !== typeof res.plays ) {
						updatePlays( container, res.plays );
					}
				} ).catch( function () {} );
			} );
		} );
	}

	function updatePlays( container, plays ) {
		var figure = container.parentNode;
		if ( ! figure ) {
			return;
		}
		var countEl = figure.querySelector( '.coywolf-cvm-plays-count' );
		if ( countEl ) {
			countEl.textContent = formatNumber( plays );
		}
	}

	function activateInline( container ) {
		var iframe = container.querySelector( 'iframe' );
		if ( iframe ) {
			trackPlays( iframe, container );
		}
	}

	function activateLazy( container ) {
		var iframe = container.querySelector( 'iframe' );
		if ( ! iframe ) {
			return;
		}
		var io = new IntersectionObserver( function ( entries ) {
			forEach( entries, function ( entry ) {
				if ( entry.isIntersecting ) {
					var src = iframe.getAttribute( 'data-src' );
					if ( src && ! iframe.src ) {
						iframe.src = src;
						trackPlays( iframe, container );
					}
					io.disconnect();
				}
			} );
		}, { rootMargin: '200px' } );
		io.observe( container );
	}

	function activateLightbox( container ) {
		var trigger = container.querySelector( '.coywolf-cvm-lightbox-trigger' );
		if ( ! trigger ) {
			return;
		}
		trigger.addEventListener( 'click', function () {
			openLightbox( container, trigger );
		} );
	}

	function openLightbox( container, trigger ) {
		var url = container.getAttribute( 'data-iframe' );
		if ( ! url ) {
			return;
		}
		var overlay = document.createElement( 'div' );
		overlay.className = 'coywolf-cvm-lightbox-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );

		var inner = document.createElement( 'div' );
		inner.className = 'coywolf-cvm-lightbox-inner';

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'coywolf-cvm-lightbox-close';
		closeBtn.setAttribute( 'aria-label', 'Close' );
		closeBtn.innerHTML = '&times;';

		var iframe = document.createElement( 'iframe' );
		iframe.src = url + ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'autoplay=true';
		iframe.setAttribute( 'allow', 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;' );
		iframe.setAttribute( 'allowfullscreen', 'true' );

		inner.appendChild( closeBtn );
		inner.appendChild( iframe );
		overlay.appendChild( inner );
		document.body.appendChild( overlay );
		document.body.classList.add( 'coywolf-cvm-lightbox-open' );

		function close() {
			overlay.parentNode && overlay.parentNode.removeChild( overlay );
			document.body.classList.remove( 'coywolf-cvm-lightbox-open' );
			document.removeEventListener( 'keydown', onKey );
			if ( trigger && trigger.focus ) {
				trigger.focus();
			}
		}
		function onKey( e ) {
			if ( 'Escape' === e.key ) {
				close();
			}
		}

		closeBtn.addEventListener( 'click', close );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
		document.addEventListener( 'keydown', onKey );
		closeBtn.focus();
		trackPlays( iframe, container );
	}

	/**
	 * Count one play once playback passes ~2s, regardless of player.
	 */
	function playCounter( container ) {
		var uid = container.getAttribute( 'data-uid' );
		var counted = false;
		return function ( currentTime ) {
			if ( counted || ! uid || ! currentTime || currentTime <= 2 ) {
				return;
			}
			counted = true;
			api( '/play/' + encodeURIComponent( uid ) ).then( function ( res ) {
				if ( res && 'undefined' !== typeof res.plays ) {
					updatePlays( container, res.plays );
				}
			} ).catch( function () {} );
		};
	}

	function attachHls( video, hls ) {
		if ( ! hls ) {
			return;
		}
		if ( video.canPlayType( 'application/vnd.apple.mpegurl' ) ) {
			video.src = hls; // Safari plays HLS natively.
			return;
		}
		if ( window.Hls && window.Hls.isSupported() ) {
			var h = new window.Hls();
			h.loadSource( hls );
			h.attachMedia( video );
		} else {
			video.src = hls;
		}
	}

	function initOSS( video, container ) {
		var player = container.getAttribute( 'data-player' );
		var hls = video.getAttribute( 'data-hls' );
		var counter = playCounter( container );

		if ( 'videojs' === player && window.videojs ) {
			var vp = window.videojs( video, { fluid: true } );
			vp.src( { src: hls, type: 'application/x-mpegURL' } );
			vp.on( 'timeupdate', function () {
				counter( vp.currentTime() );
			} );
			return;
		}

		attachHls( video, hls );
		if ( 'plyr' === player && window.Plyr ) {
			try {
				new window.Plyr( video ); // eslint-disable-line no-new
			} catch ( e ) {} // eslint-disable-line no-empty
		}
		video.addEventListener( 'timeupdate', function () {
			counter( video.currentTime );
		} );
	}

	function activateOSS( container, lazy ) {
		var video = container.querySelector( 'video.coywolf-cvm-video' );
		if ( ! video ) {
			return;
		}
		if ( lazy ) {
			var io = new IntersectionObserver( function ( entries ) {
				forEach( entries, function ( entry ) {
					if ( entry.isIntersecting ) {
						initOSS( video, container );
						io.disconnect();
					}
				} );
			}, { rootMargin: '200px' } );
			io.observe( container );
		} else {
			initOSS( video, container );
		}
	}

	function wireLikes() {
		forEach( document.querySelectorAll( '.coywolf-cvm-like' ), function ( btn ) {
			var uid = btn.getAttribute( 'data-uid' );
			if ( ! uid ) {
				return;
			}
			try {
				if ( localStorage.getItem( 'coywolf_cvm_liked_' + uid ) ) {
					btn.setAttribute( 'aria-pressed', 'true' );
					btn.classList.add( 'is-liked' );
				}
			} catch ( e ) {} // eslint-disable-line no-empty

			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				api( '/like/' + encodeURIComponent( uid ) ).then( function ( res ) {
					btn.disabled = false;
					if ( ! res ) {
						return;
					}
					var countEl = btn.querySelector( '.coywolf-cvm-like-count' );
					if ( countEl && 'undefined' !== typeof res.likes ) {
						countEl.textContent = formatNumber( res.likes );
					}
					if ( res.liked ) {
						btn.setAttribute( 'aria-pressed', 'true' );
						btn.classList.add( 'is-liked' );
						try {
							localStorage.setItem( 'coywolf_cvm_liked_' + uid, '1' );
						} catch ( e ) {} // eslint-disable-line no-empty
					} else {
						btn.setAttribute( 'aria-pressed', 'false' );
						btn.classList.remove( 'is-liked' );
						try {
							localStorage.removeItem( 'coywolf_cvm_liked_' + uid );
						} catch ( e ) {} // eslint-disable-line no-empty
					}
				} ).catch( function () {
					btn.disabled = false;
				} );
			} );
		} );
	}

	function init() {
		forEach( document.querySelectorAll( '.coywolf-cvm-player' ), function ( container ) {
			var mode = container.getAttribute( 'data-mode' );
			if ( 'lightbox' === mode ) {
				activateLightbox( container );
			} else if ( 'lazy' === mode ) {
				activateLazy( container );
			} else if ( 'oss-inline' === mode ) {
				activateOSS( container, false );
			} else if ( 'oss-lazy' === mode ) {
				activateOSS( container, true );
			} else {
				activateInline( container );
			}
		} );
		wireLikes();
	}

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
} )();
