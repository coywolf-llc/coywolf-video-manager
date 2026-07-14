/**
 * Coywolf Video Manager — shared resumable-upload client (no build step).
 *
 * A tiny, DOM-free TUS 1.0.0 client used by both the Upload Video admin screen
 * (js/admin.js) and the block's in-editor uploader (js/block.js). It PATCHes a
 * file to a Cloudflare one-time upload URL in chunks and, after a connection
 * hiccup, HEADs the URL to learn how much Cloudflare already has and resumes
 * from there. Progress and state are reported through callbacks so each caller
 * can drive its own UI.
 */
( function ( window ) {
	'use strict';

	// 50 MB — a multiple of 256 KiB, as Cloudflare's TUS endpoint requires.
	var TUS_CHUNK = 52428800;

	/**
	 * Upload a file to a Cloudflare TUS upload URL, resuming after hiccups.
	 *
	 * @param {string} uploadURL One-time TUS upload URL from the REST layer.
	 * @param {File}   file      The file to send.
	 * @param {Object} cb        Callbacks (all optional):
	 *   onProgress(fraction) — 0..1 as bytes are confirmed,
	 *   onStatus(state)      — 'uploading' | 'retrying',
	 *   onDone()             — every byte accepted,
	 *   onError()            — gave up after repeated failures.
	 */
	function tus( uploadURL, file, cb ) {
		cb = cb || {};
		var onProgress = cb.onProgress || function () {};
		var onStatus = cb.onStatus || function () {};
		var onDone = cb.onDone || function () {};
		var onError = cb.onError || function () {};
		var offset = 0;
		var attempts = 0;

		function report( loaded ) {
			var total = Math.min( file.size, offset + loaded );
			onProgress( file.size > 0 ? total / file.size : 0 );
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
				onError();
				return;
			}
			onStatus( 'retrying' );
			window.setTimeout( resync, 2000 * attempts );
		}

		function sendChunk() {
			if ( offset >= file.size ) {
				onDone();
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
					onStatus( 'uploading' );
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

	window.coywolfCVMUpload = { tus: tus, CHUNK: TUS_CHUNK };
} )( window );
