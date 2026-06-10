/**
 * Coywolf Video Manager — admin scripts.
 *
 * Vanilla JS (no build step). Wires up whatever controls are present on the
 * current plugin screen: All Videos (search reset, filter, delete-confirm),
 * Edit Video (save, poster, captions, copy ID, delete), Upload (direct upload).
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

	// 266 -> "4:26", 3735 -> "1:02:15".
	function formatTime( value ) {
		var total = Math.max( 0, Math.floor( parseFloat( value ) || 0 ) );
		var h = Math.floor( total / 3600 );
		var m = Math.floor( ( total % 3600 ) / 60 );
		var s = total % 60;
		var ss = ( s < 10 ? '0' : '' ) + s;
		if ( h > 0 ) {
			return h + ':' + ( m < 10 ? '0' : '' ) + m + ':' + ss;
		}
		return m + ':' + ss;
	}

	// "4:26" -> 266, "1:02:15" -> 3735, "266" -> 266; null when not a time.
	function parseTime( text ) {
		var t = String( text ).trim();
		if ( ! /^[0-9]+(:[0-9]+){0,2}(\.[0-9]+)?$/.test( t ) ) {
			return null;
		}
		var parts = t.split( ':' );
		var total = 0;
		for ( var i = 0; i < parts.length; i++ ) {
			total = total * 60 + parseFloat( parts[ i ] );
		}
		return total;
	}

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

	/* ----- All Videos: search reset, filter, delete confirm ----- */

	function wireListControls() {
		// Submit (reset) when the native search "X" clears the field.
		var search = document.querySelector( '.search-box input[type="search"]' );
		if ( search ) {
			search.addEventListener( 'search', function () {
				if ( '' === search.value && search.form ) {
					search.form.submit();
				}
			} );
		}
		// Apply the Plays/Likes/Posts/Pages filter on selection.
		var filter = document.querySelector( 'select.coywolf-cvm-filter' );
		if ( filter ) {
			filter.addEventListener( 'change', function () {
				if ( filter.form ) {
					filter.form.submit();
				}
			} );
		}
		// Confirm row deletes.
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
		var listUrl = root.getAttribute( 'data-list-url' );
		var saveStatus = root.querySelector( '.coywolf-cvm-save-status' );
		var previewImg = document.getElementById( 'cvm-poster-img' );

		function posterMode() {
			var checked = document.querySelector( 'input[name="cvm-poster-mode"]:checked' );
			return checked ? checked.value : 'timestamp';
		}

		// Save.
		document.getElementById( 'cvm-save' ).addEventListener( 'click', function () {
			var saveBtn = this;
			var origins = document.getElementById( 'cvm-origins' ).value
				.split( /\n+/ ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
			var mode = posterMode();
			var data = {
				name: document.getElementById( 'cvm-name' ).value,
				description: document.getElementById( 'cvm-description' ).value,
				creator: document.getElementById( 'cvm-creator' ).value,
				allowedOrigins: origins,
				posterMode: mode
			};
			if ( 'image' === mode ) {
				data.posterImageId = document.getElementById( 'cvm-poster-image-id' ).value;
				data.posterImageUrl = document.getElementById( 'cvm-poster-image-url' ).value;
			} else {
				var posterTime = parseFloat( document.getElementById( 'cvm-poster-time' ).value ) || 0;
				data.posterTime = posterTime;
				data.thumbnailTimestampPct = duration > 0 ? Math.min( 1, posterTime / duration ) : 0;
			}

			saveBtn.disabled = true;
			saveStatus.textContent = '…';
			rest( '/videos/' + encodeURIComponent( uid ), 'POST', data ).then( function () {
				// Back to All Videos on success.
				window.location = listUrl + '&coywolf_cvm_saved=1';
			} ).catch( function ( e ) {
				saveBtn.disabled = false;
				saveStatus.textContent = '✗ ' + errMsg( e );
			} );
		} );

		// Poster mode toggle.
		var tsBox = root.querySelector( '.cvm-poster-timestamp' );
		var imgBox = root.querySelector( '.cvm-poster-image' );
		forEach( document.querySelectorAll( 'input[name="cvm-poster-mode"]' ), function ( radio ) {
			radio.addEventListener( 'change', function () {
				var isImage = 'image' === posterMode();
				if ( tsBox ) {
					tsBox.style.display = isImage ? 'none' : '';
				}
				if ( imgBox ) {
					imgBox.style.display = isImage ? '' : 'none';
				}
				updatePosterPreview();
			} );
		} );

		// Poster timestamp → live thumbnail. The slider scrubs; the text field
		// shows/accepts h:mm:ss, m:ss, or plain seconds. The slider holds the
		// canonical seconds value (the save handler reads it).
		var range = document.getElementById( 'cvm-poster-time' );
		var timeText = document.getElementById( 'cvm-poster-time-text' );
		var timer = null;

		function schedulePosterPreview() {
			if ( timer ) {
				window.clearTimeout( timer );
			}
			timer = window.setTimeout( updatePosterPreview, 350 );
		}

		if ( range ) {
			range.addEventListener( 'input', function () {
				if ( timeText ) {
					timeText.value = formatTime( range.value );
				}
				schedulePosterPreview();
			} );
		}
		if ( range && timeText ) {
			timeText.addEventListener( 'input', function () {
				var parsed = parseTime( timeText.value );
				if ( null !== parsed ) {
					range.value = parsed; // the browser clamps to the slider max.
					schedulePosterPreview();
				}
			} );
			// Normalize whatever was typed back to the canonical format.
			timeText.addEventListener( 'blur', function () {
				timeText.value = formatTime( range.value );
			} );
		}

		function updatePosterPreview() {
			if ( ! previewImg ) {
				return;
			}
			if ( 'image' === posterMode() ) {
				var url = document.getElementById( 'cvm-poster-image-url' ).value;
				if ( url ) {
					previewImg.src = url;
				}
				return;
			}
			rest( '/thumbnail/' + encodeURIComponent( uid ) + '?time=' + encodeURIComponent( range ? range.value : 0 ) ).then( function ( res ) {
				if ( res && res.url ) {
					previewImg.src = res.url;
				}
			} ).catch( function () {} );
		}

		// Poster image: Media Library.
		var pick = document.getElementById( 'cvm-poster-pick' );
		if ( pick && window.wp && window.wp.media ) {
			pick.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var frame = window.wp.media( {
					title: i18n.mediaTitle || 'Select poster image',
					library: { type: 'image' },
					multiple: false,
					button: { text: i18n.mediaButton || 'Use image' }
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					document.getElementById( 'cvm-poster-image-id' ).value = att.id;
					document.getElementById( 'cvm-poster-image-url' ).value = att.url;
					if ( previewImg ) {
						previewImg.src = att.url;
					}
				} );
				frame.open();
			} );
		}

		// Copy the Video ID.
		forEach( document.querySelectorAll( '.coywolf-cvm-copy-id' ), function ( el ) {
			var hint = el.querySelector( '.coywolf-cvm-copy-hint' );
			function doCopy() {
				copyText( el.getAttribute( 'data-id' ) || '' ).then( function () {
					if ( ! hint ) {
						return;
					}
					var prev = hint.textContent;
					hint.textContent = i18n.copiedId || 'Copied!';
					window.setTimeout( function () { hint.textContent = prev; }, 1500 );
				} );
			}
			el.addEventListener( 'click', doCopy );
			el.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key || ' ' === e.key ) {
					e.preventDefault();
					doCopy();
				}
			} );
		} );

		// Delete — confirmed via a modal dialog.
		var deleteBtn = document.getElementById( 'cvm-delete' );
		var modal = document.getElementById( 'cvm-delete-modal' );
		if ( deleteBtn && modal ) {
			var cancelBtn = document.getElementById( 'cvm-delete-cancel' );
			var confirmBtn = document.getElementById( 'cvm-delete-confirm' );

			var closeModal = function () {
				modal.hidden = true;
				deleteBtn.focus();
			};
			var openModal = function () {
				modal.hidden = false;
				if ( cancelBtn ) {
					cancelBtn.focus();
				}
			};

			deleteBtn.addEventListener( 'click', openModal );
			if ( cancelBtn ) {
				cancelBtn.addEventListener( 'click', closeModal );
			}
			// Close on overlay click or Escape.
			modal.addEventListener( 'click', function ( e ) {
				if ( e.target === modal ) {
					closeModal();
				}
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && ! modal.hidden ) {
					closeModal();
				}
			} );
			// Keep Tab focus inside the open dialog (focus trap).
			modal.addEventListener( 'keydown', function ( e ) {
				if ( 'Tab' !== e.key || modal.hidden ) {
					return;
				}
				var focusable = modal.querySelectorAll( 'button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])' );
				if ( ! focusable.length ) {
					return;
				}
				var first = focusable[ 0 ];
				var last = focusable[ focusable.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			} );

			if ( confirmBtn ) {
				confirmBtn.addEventListener( 'click', function () {
					confirmBtn.disabled = true;
					if ( cancelBtn ) {
						cancelBtn.disabled = true;
					}
					confirmBtn.textContent = i18n.deleting || 'Deleting…';
					rest( '/videos/' + encodeURIComponent( uid ), 'DELETE' ).then( function () {
						window.location = listUrl + '&coywolf_cvm_deleted=1';
					} ).catch( function ( e ) {
						closeModal();
						confirmBtn.disabled = false;
						if ( cancelBtn ) {
							cancelBtn.disabled = false;
						}
						confirmBtn.textContent = i18n.deleteConfirm || 'Delete';
						saveStatus.textContent = '✗ ' + errMsg( e );
					} );
				} );
			}
		}

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
		var urlBtn = document.getElementById( 'cvm-up-url-start' );
		var statusEl = root.querySelector( '.coywolf-cvm-upload-status' );
		var progress = root.querySelector( '.coywolf-cvm-progress' );
		var bar = root.querySelector( '.coywolf-cvm-progress-bar' );
		var listUrl = root.getAttribute( 'data-list-url' );

		// 50 MB — a multiple of 256 KiB, as Cloudflare's TUS endpoint requires.
		var TUS_CHUNK = 52428800;

		function fieldValue( id ) {
			var el = document.getElementById( id );
			return el ? el.value.trim() : '';
		}

		function origins() {
			return fieldValue( 'cvm-up-origins' )
				.split( /\n+/ ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
		}

		function setBusy( busy ) {
			startBtn.disabled = busy;
			if ( urlBtn ) {
				urlBtn.disabled = busy;
			}
		}

		function fail( message ) {
			setBusy( false );
			statusEl.textContent = '✗ ' + message;
		}

		function pollStatus( uid, tries ) {
			var editUrl = listUrl + '&action=edit&uid=' + encodeURIComponent( uid );
			if ( tries > 60 ) {
				// Processing is taking a while; send them to the Edit page anyway.
				window.location = editUrl;
				return;
			}
			rest( '/videos/' + encodeURIComponent( uid ) ).then( function ( v ) {
				if ( v && ( v.ready || 'error' === v.state ) ) {
					bar.style.width = '100%';
					window.location = editUrl;
				} else {
					window.setTimeout( function () { pollStatus( uid, tries + 1 ); }, 3000 );
				}
			} ).catch( function () {
				window.setTimeout( function () { pollStatus( uid, tries + 1 ); }, 3000 );
			} );
		}

		// TUS metadata only carries the name; apply creator/allowed origins
		// through the regular update route, then wait for processing.
		function applyAndFinish( uid ) {
			var data = {};
			var creator = fieldValue( 'cvm-up-creator' );
			var allowed = origins();
			if ( creator ) {
				data.creator = creator;
			}
			if ( allowed.length ) {
				data.allowedOrigins = allowed;
			}
			var apply = Object.keys( data ).length
				? rest( '/videos/' + encodeURIComponent( uid ), 'POST', data ).catch( function () {} )
				: Promise.resolve();
			apply.then( function () {
				pollStatus( uid, 0 );
			} );
		}

		// Minimal TUS 1.0.0 client: sequential PATCH chunks; on a hiccup,
		// HEAD re-asks Cloudflare how much it has and resumes from there.
		function tusUpload( uploadURL, file, done, error ) {
			var offset = 0;
			var attempts = 0;

			function report( loaded ) {
				var total = Math.min( file.size, offset + loaded );
				bar.style.width = ( file.size > 0 ? Math.round( ( total / file.size ) * 100 ) : 0 ) + '%';
			}

			function resync() {
				var xhr = new XMLHttpRequest();
				xhr.open( 'HEAD', uploadURL, true );
				xhr.setRequestHeader( 'Tus-Resumable', '1.0.0' );
				xhr.onload = function () {
					var at = parseInt( xhr.getResponseHeader( 'Upload-Offset' ), 10 );
					if ( ! isNaN( at ) ) {
						offset = at;
					}
					sendChunk();
				};
				xhr.onerror = function () {
					sendChunk();
				};
				xhr.send();
			}

			function retry() {
				attempts += 1;
				if ( attempts > 5 ) {
					error();
					return;
				}
				statusEl.textContent = i18n.retrying || 'Connection hiccup — resuming upload…';
				window.setTimeout( resync, 2000 * attempts );
			}

			function sendChunk() {
				if ( offset >= file.size ) {
					done();
					return;
				}
				var xhr = new XMLHttpRequest();
				xhr.open( 'PATCH', uploadURL, true );
				xhr.setRequestHeader( 'Tus-Resumable', '1.0.0' );
				xhr.setRequestHeader( 'Upload-Offset', String( offset ) );
				xhr.setRequestHeader( 'Content-Type', 'application/offset+octet-stream' );
				xhr.upload.onprogress = function ( e ) {
					if ( e.lengthComputable ) {
						report( e.loaded );
					}
				};
				xhr.onload = function () {
					if ( xhr.status >= 200 && xhr.status < 300 ) {
						attempts = 0;
						statusEl.textContent = i18n.uploading || 'Uploading…';
						var at = parseInt( xhr.getResponseHeader( 'Upload-Offset' ), 10 );
						if ( isNaN( at ) ) {
							resync();
							return;
						}
						offset = at;
						sendChunk();
					} else {
						retry();
					}
				};
				xhr.onerror = function () {
					retry();
				};
				xhr.send( file.slice( offset, Math.min( file.size, offset + TUS_CHUNK ) ) );
			}

			sendChunk();
		}

		// Upload a local file (chunked + resumable; no 200 MB ceiling).
		startBtn.addEventListener( 'click', function () {
			var fileInput = document.getElementById( 'cvm-up-file' );
			var file = fileInput.files && fileInput.files[ 0 ];
			if ( ! file ) {
				statusEl.textContent = i18n.pickVideo || 'Choose a video file first.';
				return;
			}
			setBusy( true );
			statusEl.textContent = i18n.preparing || 'Preparing upload…';

			rest( '/tus-upload', 'POST', {
				length: file.size,
				name: fieldValue( 'cvm-up-name' ) || file.name,
				creator: fieldValue( 'cvm-up-creator' )
			} ).then( function ( res ) {
				if ( ! res || ! res.uploadURL || ! res.uid ) {
					throw new Error( 'No upload URL returned.' );
				}
				progress.style.display = 'block';
				statusEl.textContent = i18n.uploading || 'Uploading…';
				tusUpload( res.uploadURL, file, function () {
					statusEl.textContent = i18n.processing || 'Uploaded. Cloudflare is processing the video…';
					applyAndFinish( res.uid );
				}, function () {
					fail( i18n.uploadFailed || 'Upload failed.' );
				} );
			} ).catch( function ( e ) {
				fail( errMsg( e ) );
			} );
		} );

		// Import from a URL: Cloudflare fetches it server-side (name, creator,
		// and origins all travel with the copy request itself).
		if ( urlBtn ) {
			urlBtn.addEventListener( 'click', function () {
				var url = fieldValue( 'cvm-up-url' );
				if ( ! url ) {
					statusEl.textContent = i18n.pickUrl || 'Enter a video URL first.';
					return;
				}
				setBusy( true );
				statusEl.textContent = i18n.fetching || 'Cloudflare is fetching the video from the URL…';

				rest( '/copy', 'POST', {
					url: url,
					name: fieldValue( 'cvm-up-name' ),
					creator: fieldValue( 'cvm-up-creator' ),
					allowedOrigins: origins()
				} ).then( function ( v ) {
					if ( ! v || ! v.uid ) {
						throw new Error( 'No video returned.' );
					}
					progress.style.display = 'block';
					statusEl.textContent = i18n.processing || 'Cloudflare is processing the video…';
					pollStatus( v.uid, 0 );
				} ).catch( function ( e ) {
					fail( errMsg( e ) );
				} );
			} );
		}
	}

	onReady( function () {
		wireListControls();
		wireEdit();
		wireUpload();
	} );
} )();
