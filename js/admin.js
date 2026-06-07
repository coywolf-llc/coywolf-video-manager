/**
 * Coywolf Video Manager — admin scripts.
 *
 * Vanilla JS (no build step). Wires up whatever controls are present on the
 * current plugin screen: All Videos (copy-embed, delete-confirm), Edit Video
 * (save, poster preview, captions), and Upload Video (direct upload + polling).
 */
( function () {
	'use strict';

	var cfg = window.coywolfCVM || {};
	var i18n = cfg.i18n || {};
	var apiFetch = window.wp && window.wp.apiFetch;

	function onReady( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function forEach( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

	function rest( path, method, data ) {
		return apiFetch( {
			path: '/coywolf-cvm/v1' + path,
			method: method || 'GET',
			data: data || undefined
		} );
	}

	function errMsg( e ) {
		return ( e && e.message ) ? e.message : 'Request failed.';
	}

	/* ----- All Videos: copy embed + delete confirm ----- */

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild( ta );
		ta.select();
		try {
			document.execCommand( 'copy' );
		} catch ( e ) {} // eslint-disable-line no-empty
		document.body.removeChild( ta );
		return Promise.resolve();
	}

	function wireCopyEmbed() {
		forEach( document.querySelectorAll( '.coywolf-cvm-copy-embed' ), function ( el ) {
			el.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				copyText( el.getAttribute( 'data-embed' ) || '' ).then( function () {
					window.alert( i18n.copied || 'Copied.' );
				} );
			} );
		} );
	}

	function wireDeleteConfirm() {
		forEach( document.querySelectorAll( 'a.coywolf-cvm-delete' ), function ( el ) {
			el.addEventListener( 'click', function ( e ) {
				if ( ! window.confirm( i18n.confirmDelete || 'Delete?' ) ) {
					e.preventDefault();
				}
			} );
		} );
	}

	/* ----- Edit Video ----- */

	function wireEdit() {
		var root = document.querySelector( '.coywolf-cvm-edit' );
		if ( ! root || ! apiFetch ) {
			return;
		}
		var uid = root.getAttribute( 'data-uid' );
		var duration = parseFloat( root.getAttribute( 'data-duration' ) ) || 0;

		// Save.
		var saveBtn = document.getElementById( 'cvm-save' );
		var saveStatus = root.querySelector( '.coywolf-cvm-save-status' );
		saveBtn.addEventListener( 'click', function () {
			var origins = document.getElementById( 'cvm-origins' ).value
				.split( /\n+/ )
				.map( function ( s ) { return s.trim(); } )
				.filter( Boolean );
			var posterTime = parseFloat( document.getElementById( 'cvm-poster-time' ).value ) || 0;
			var data = {
				name: document.getElementById( 'cvm-name' ).value,
				creator: document.getElementById( 'cvm-creator' ).value,
				allowedOrigins: origins,
				requireSignedURLs: document.getElementById( 'cvm-signed' ).checked,
				thumbnailTimestampPct: duration > 0 ? Math.min( 1, posterTime / duration ) : 0
			};
			saveBtn.disabled = true;
			saveStatus.textContent = '…';
			rest( '/videos/' + encodeURIComponent( uid ), 'POST', data ).then( function () {
				saveBtn.disabled = false;
				saveStatus.textContent = '✓ ' + ( i18n.saved || 'Saved.' );
			} ).catch( function ( e ) {
				saveBtn.disabled = false;
				saveStatus.textContent = '✗ ' + errMsg( e );
			} );
		} );

		// Poster preview.
		var range = document.getElementById( 'cvm-poster-time' );
		var out = document.getElementById( 'cvm-poster-time-out' );
		var img = document.getElementById( 'cvm-poster-img' );
		var timer = null;
		range.addEventListener( 'input', function () {
			out.textContent = range.value + 's';
			if ( timer ) {
				window.clearTimeout( timer );
			}
			timer = window.setTimeout( function () {
				rest( '/thumbnail/' + encodeURIComponent( uid ) + '?time=' + encodeURIComponent( range.value ) ).then( function ( res ) {
					if ( res && res.url ) {
						img.src = res.url;
					}
				} ).catch( function () {} );
			}, 350 );
		} );

		wireCaptions( root, uid );
	}

	/* ----- Captions ----- */

	function wireCaptions( root, uid ) {
		var list = root.querySelector( '.coywolf-cvm-captions-list' );
		var status = root.querySelector( '.coywolf-cvm-cap-status' );

		function load() {
			rest( '/videos/' + encodeURIComponent( uid ) + '/captions' ).then( function ( res ) {
				list.innerHTML = '';
				var caps = ( res && res.captions ) ? res.captions : [];
				if ( ! caps.length ) {
					var li = document.createElement( 'li' );
					li.textContent = i18n.noCaptions || 'No captions yet.';
					list.appendChild( li );
					return;
				}
				caps.forEach( function ( c ) {
					var li = document.createElement( 'li' );
					var label = ( c.label || c.language ) + ( c.status && 'ready' !== c.status ? ' (' + c.status + ')' : '' );
					li.appendChild( document.createTextNode( label + ' ' ) );
					var del = document.createElement( 'button' );
					del.type = 'button';
					del.className = 'button-link';
					del.textContent = i18n.remove || 'Remove';
					del.addEventListener( 'click', function () {
						rest( '/videos/' + encodeURIComponent( uid ) + '/captions/' + encodeURIComponent( c.language ), 'DELETE' ).then( load ).catch( function ( e ) {
							status.textContent = '✗ ' + errMsg( e );
						} );
					} );
					li.appendChild( del );
					list.appendChild( li );
				} );
			} ).catch( function ( e ) {
				status.textContent = '✗ ' + errMsg( e );
			} );
		}

		function lang() {
			return ( document.getElementById( 'cvm-cap-lang' ).value || 'en' ).trim();
		}

		document.getElementById( 'cvm-cap-upload' ).addEventListener( 'click', function () {
			var fileInput = document.getElementById( 'cvm-cap-file' );
			var file = fileInput.files && fileInput.files[ 0 ];
			if ( ! file ) {
				status.textContent = i18n.pickFile || 'Choose a .vtt file first.';
				return;
			}
			var reader = new FileReader();
			reader.onload = function () {
				status.textContent = '…';
				rest( '/videos/' + encodeURIComponent( uid ) + '/captions/' + encodeURIComponent( lang() ), 'POST', { vtt: String( reader.result ) } ).then( function () {
					status.textContent = '✓';
					fileInput.value = '';
					load();
				} ).catch( function ( e ) {
					status.textContent = '✗ ' + errMsg( e );
				} );
			};
			reader.readAsText( file );
		} );

		document.getElementById( 'cvm-cap-generate' ).addEventListener( 'click', function () {
			status.textContent = '…';
			rest( '/videos/' + encodeURIComponent( uid ) + '/captions/' + encodeURIComponent( lang() ) + '/generate', 'POST' ).then( function () {
				status.textContent = '✓ ' + ( i18n.generating || 'Generating…' );
				load();
			} ).catch( function ( e ) {
				status.textContent = '✗ ' + errMsg( e );
			} );
		} );

		load();
	}

	/* ----- Upload Video ----- */

	function wireUpload() {
		var root = document.querySelector( '.coywolf-cvm-uploader' );
		if ( ! root || ! apiFetch ) {
			return;
		}
		var startBtn = document.getElementById( 'cvm-up-start' );
		var statusEl = root.querySelector( '.coywolf-cvm-upload-status' );
		var progress = root.querySelector( '.coywolf-cvm-progress' );
		var bar = root.querySelector( '.coywolf-cvm-progress-bar' );
		var listUrl = root.getAttribute( 'data-list-url' );

		startBtn.addEventListener( 'click', function () {
			var fileInput = document.getElementById( 'cvm-up-file' );
			var file = fileInput.files && fileInput.files[ 0 ];
			if ( ! file ) {
				statusEl.textContent = i18n.pickVideo || 'Choose a video file first.';
				return;
			}
			var origins = document.getElementById( 'cvm-up-origins' ).value
				.split( /\n+/ )
				.map( function ( s ) { return s.trim(); } )
				.filter( Boolean );
			var opts = {
				name: document.getElementById( 'cvm-up-name' ).value || file.name,
				creator: document.getElementById( 'cvm-up-creator' ).value,
				allowedOrigins: origins,
				requireSignedURLs: document.getElementById( 'cvm-up-signed' ).checked,
				maxDurationSeconds: 21600
			};

			startBtn.disabled = true;
			statusEl.textContent = i18n.preparing || 'Preparing upload…';

			rest( '/direct-upload', 'POST', opts ).then( function ( res ) {
				if ( ! res || ! res.uploadURL || ! res.uid ) {
					throw new Error( 'No upload URL returned.' );
				}
				uploadFile( res.uploadURL, file, res.uid );
			} ).catch( function ( e ) {
				startBtn.disabled = false;
				statusEl.textContent = '✗ ' + errMsg( e );
			} );

			function uploadFile( url, file, uid ) {
				var fd = new FormData();
				fd.append( 'file', file );
				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', url, true );
				progress.style.display = 'block';
				xhr.upload.onprogress = function ( e ) {
					if ( e.lengthComputable ) {
						bar.style.width = Math.round( ( e.loaded / e.total ) * 100 ) + '%';
					}
				};
				xhr.onload = function () {
					if ( xhr.status >= 200 && xhr.status < 300 ) {
						statusEl.textContent = i18n.processing || 'Uploaded. Cloudflare is processing the video…';
						pollStatus( uid, 0 );
					} else {
						startBtn.disabled = false;
						statusEl.textContent = '✗ ' + ( i18n.uploadFailed || 'Upload failed.' ) + ' (' + xhr.status + ')';
					}
				};
				xhr.onerror = function () {
					startBtn.disabled = false;
					statusEl.textContent = '✗ ' + ( i18n.uploadFailed || 'Upload failed.' );
				};
				xhr.send( fd );
			}

			function pollStatus( uid, tries ) {
				if ( tries > 60 ) {
					statusEl.textContent = i18n.stillProcessing || 'Still processing — check All Videos shortly.';
					startBtn.disabled = false;
					return;
				}
				rest( '/videos/' + encodeURIComponent( uid ) ).then( function ( v ) {
					if ( v && v.ready ) {
						var editUrl = listUrl + '&action=edit&uid=' + encodeURIComponent( uid );
						statusEl.innerHTML = '✓ ' + ( i18n.ready || 'Ready!' ) + ' <a href="' + editUrl + '">' + ( i18n.editNow || 'Edit this video' ) + '</a>';
						bar.style.width = '100%';
						startBtn.disabled = false;
					} else if ( v && 'error' === v.state ) {
						statusEl.textContent = '✗ ' + ( i18n.processError || 'Cloudflare could not process this video.' );
						startBtn.disabled = false;
					} else {
						window.setTimeout( function () { pollStatus( uid, tries + 1 ); }, 3000 );
					}
				} ).catch( function () {
					window.setTimeout( function () { pollStatus( uid, tries + 1 ); }, 3000 );
				} );
			}
		} );
	}

	onReady( function () {
		wireCopyEmbed();
		wireDeleteConfirm();
		wireEdit();
		wireUpload();
	} );
} )();
