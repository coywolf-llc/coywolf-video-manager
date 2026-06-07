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

		// Poster timestamp → live thumbnail.
		var range = document.getElementById( 'cvm-poster-time' );
		var out = document.getElementById( 'cvm-poster-time-out' );
		var timer = null;
		if ( range ) {
			range.addEventListener( 'input', function () {
				out.textContent = range.value + 's';
				if ( timer ) {
					window.clearTimeout( timer );
				}
				timer = window.setTimeout( updatePosterPreview, 350 );
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
				.split( /\n+/ ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
			var opts = {
				name: document.getElementById( 'cvm-up-name' ).value || file.name,
				creator: document.getElementById( 'cvm-up-creator' ).value,
				allowedOrigins: origins,
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
		} );
	}

	onReady( function () {
		wireListControls();
		wireEdit();
		wireUpload();
	} );
} )();
