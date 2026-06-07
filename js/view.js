/**
 * Coywolf Video Manager — front-end player, plays/likes, lazy-load.
 * Vanilla JS, no build step.
 */
( function () {
	'use strict';

	var cfg = window.coywolfCVMView || {};
	var i18n = cfg.i18n || { view: 'view', views: 'views' };
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

	function api( path ) {
		var headers = { 'Content-Type': 'application/json' };
		if ( cfg.nonce ) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		return fetch( cfg.restUrl + path, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin'
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
	 * A counter that records one view once playback passes ~2s (so muted-autoplay
	 * previews don't inflate it), then updates the figure's views label.
	 */
	function viewCounter( figure ) {
		var uid = figure.getAttribute( 'data-uid' );
		var counted = false;
		return function ( currentTime ) {
			if ( counted || ! uid || ! currentTime || currentTime <= 2 ) {
				return;
			}
			counted = true;
			api( '/play/' + encodeURIComponent( uid ) ).then( function ( res ) {
				if ( res && 'undefined' !== typeof res.plays ) {
					updateViews( figure, res.plays );
				}
			} ).catch( function () {} );
		};
	}

	function updateViews( figure, n ) {
		var el = figure.querySelector( '.coywolf-cvm-views' );
		if ( ! el ) {
			return;
		}
		n = Number( n ) || 0;
		if ( n > 0 ) {
			el.textContent = formatNumber( n ) + ' ' + ( 1 === n ? i18n.view : i18n.views );
			el.classList.remove( 'is-empty' );
		} else {
			el.classList.add( 'is-empty' );
		}
	}

	/* ----- Cloudflare iframe ----- */

	function trackCloudflare( iframe, figure ) {
		loadStreamSDK().then( function ( Stream ) {
			if ( ! Stream ) {
				return;
			}
			var player = Stream( iframe );
			var counter = viewCounter( figure );
			player.addEventListener( 'timeupdate', function () {
				counter( player.currentTime );
			} );
		} );
	}

	function activateCloudflare( figure, lazy ) {
		var iframe = figure.querySelector( 'iframe' );
		if ( ! iframe ) {
			return;
		}
		if ( lazy ) {
			var io = new IntersectionObserver( function ( entries ) {
				forEach( entries, function ( entry ) {
					if ( entry.isIntersecting ) {
						var src = iframe.getAttribute( 'data-src' );
						if ( src && ! iframe.src ) {
							iframe.src = src;
							trackCloudflare( iframe, figure );
						}
						io.disconnect();
					}
				} );
			}, { rootMargin: '200px' } );
			io.observe( figure );
		} else {
			trackCloudflare( iframe, figure );
		}
	}

	/* ----- Open-source players (Plyr / Video.js) ----- */

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

	function initOSS( video, figure ) {
		var player = figure.getAttribute( 'data-player' );
		var hls = video.getAttribute( 'data-hls' );
		var counter = viewCounter( figure );

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

	function activateOSS( figure, lazy ) {
		var video = figure.querySelector( 'video.coywolf-cvm-video' );
		if ( ! video ) {
			return;
		}
		if ( lazy ) {
			var io = new IntersectionObserver( function ( entries ) {
				forEach( entries, function ( entry ) {
					if ( entry.isIntersecting ) {
						initOSS( video, figure );
						io.disconnect();
					}
				} );
			}, { rootMargin: '200px' } );
			io.observe( figure );
		} else {
			initOSS( video, figure );
		}
	}

	/* ----- Likes ----- */

	function setLikeCount( btn, n ) {
		var el = btn.querySelector( '.coywolf-cvm-like-count' );
		if ( ! el ) {
			return;
		}
		n = Number( n ) || 0;
		if ( n > 0 ) {
			el.textContent = formatNumber( n );
			el.classList.remove( 'is-empty' );
		} else {
			el.textContent = '';
			el.classList.add( 'is-empty' );
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
				// Animate immediately for responsiveness.
				btn.classList.remove( 'is-animating' );
				void btn.offsetWidth; // restart the animation.
				btn.classList.add( 'is-animating' );

				api( '/like/' + encodeURIComponent( uid ) ).then( function ( res ) {
					btn.disabled = false;
					if ( ! res ) {
						return;
					}
					if ( 'undefined' !== typeof res.likes ) {
						setLikeCount( btn, res.likes );
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
		forEach( document.querySelectorAll( '.coywolf-cvm' ), function ( figure ) {
			var mode = figure.getAttribute( 'data-mode' ) || 'inline';
			if ( 'oss-inline' === mode ) {
				activateOSS( figure, false );
			} else if ( 'oss-lazy' === mode ) {
				activateOSS( figure, true );
			} else if ( 'lazy' === mode ) {
				activateCloudflare( figure, true );
			} else {
				activateCloudflare( figure, false );
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
