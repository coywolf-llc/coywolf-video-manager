/**
 * Coywolf Video Manager — editor block (no build step; uses window.wp.*).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var apiFetch = wp.apiFetch;
	var useSelect = wp.data.useSelect;
	var select = wp.data.select;
	var dispatch = wp.data.dispatch;
	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var ServerSideRender = wp.serverSideRender;

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var MediaUpload = blockEditor.MediaUpload;
	var MediaUploadCheck = blockEditor.MediaUploadCheck;

	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;
	var RangeControl = components.RangeControl;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var ColorPalette = components.ColorPalette;
	var BaseControl = components.BaseControl;
	var Button = components.Button;
	var Modal = components.Modal;
	var Spinner = components.Spinner;
	var Placeholder = components.Placeholder;

	var cfg = window.coywolfCVMBlock || { defaults: {} };
	var defaults = cfg.defaults || {};

	// Inherit-able boolean attribute → settings default key.
	var defaultKey = {
		controls: 'controls',
		autoplay: 'autoplay',
		loop: 'loop',
		mute: 'mute',
		lazy: 'lazy',
		showPlays: 'plays_enabled',
		enableLikes: 'likes_enabled',
		showLikeCount: 'likes_show_count',
		showDate: 'show_date',
		dateFromPost: 'date_from_post',
		showName: 'show_title',
		showDescription: 'show_desc',
		showBorder: 'border_enabled'
	};

	function defaultBool( attr ) {
		return !! defaults[ defaultKey[ attr ] ];
	}

	function snackbar( status, message, uid ) {
		var notices = dispatch( 'core/notices' );
		if ( notices && notices.createNotice ) {
			notices.createNotice( status, message, {
				type: 'snackbar',
				id: 'coywolf-cvm-video-save-' + uid
			} );
		}
	}

	function escapeHtml( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	// Editor-preview sanitizer for the staged description: keeps the safe
	// markup wp_kses_post would allow on save, but drops script-ish elements
	// and on*/javascript: attributes so pasted markup can't run in the editor
	// before the server has sanitized it. DOMParser documents are inert (no
	// resource loading, no script execution) while we clean.
	function sanitizeHtml( html ) {
		var doc = new window.DOMParser().parseFromString( '<body>' + String( html ), 'text/html' );
		var nodes = doc.body.querySelectorAll( '*' );
		for ( var i = nodes.length - 1; i >= 0; i-- ) {
			var node = nodes[ i ];
			var tag = node.tagName.toLowerCase();
			if ( 'script' === tag || 'style' === tag || 'iframe' === tag || 'object' === tag || 'embed' === tag || 'form' === tag || 'link' === tag || 'meta' === tag ) {
				node.parentNode.removeChild( node );
				continue;
			}
			for ( var j = node.attributes.length - 1; j >= 0; j-- ) {
				var attr = node.attributes[ j ];
				var name = attr.name.toLowerCase();
				if ( 0 === name.indexOf( 'on' ) || ( ( 'href' === name || 'src' === name || 'xlink:href' === name || 'action' === name || 'formaction' === name ) && /^\s*(javascript|data|vbscript):/i.test( attr.value ) ) ) {
					node.removeAttribute( attr.name );
				}
			}
		}
		return doc.body.innerHTML;
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

	/**
	 * Text control for a value stored in seconds, edited as h:mm:ss, m:ss, or
	 * plain seconds. Partial input ("4:") is kept while typing and normalized
	 * on blur; valid input commits through props.onChange in seconds, clamped
	 * to props.max when set.
	 */
	function TimeField( props ) {
		var draftState = useState( null );
		var draft = draftState[ 0 ];
		var setDraft = draftState[ 1 ];
		return el( TextControl, {
			label: props.label,
			value: null !== draft ? draft : formatTime( props.value ),
			placeholder: '0:00',
			help: props.help,
			__nextHasNoMarginBottom: true,
			onChange: function ( v ) {
				setDraft( v );
				var parsed = parseTime( v );
				if ( null !== parsed ) {
					if ( props.max && parsed > props.max ) {
						parsed = props.max;
					}
					props.onChange( parsed );
				}
			},
			onBlur: function () {
				setDraft( null );
			}
		} );
	}

	/**
	 * Video picker modal.
	 */
	function Picker( props ) {
		var state = useState( '' );
		var query = state[ 0 ];
		var setQuery = state[ 1 ];
		var listState = useState( null );
		var items = listState[ 0 ];
		var setItems = listState[ 1 ];
		var loadingState = useState( false );
		var loading = loadingState[ 0 ];
		var setLoading = loadingState[ 1 ];
		var searchRef = useRef( null );

		// Focus the filter input on open so typing filters immediately. The
		// Modal's own focus-on-mount also runs on a zero timeout; this one is
		// queued after it, so the input wins.
		useEffect( function () {
			var timer = setTimeout( function () {
				if ( searchRef.current ) {
					searchRef.current.focus();
				}
			}, 0 );
			return function () {
				clearTimeout( timer );
			};
		}, [] );

		function search( term ) {
			setLoading( true );
			apiFetch( { path: '/coywolf-cvm/v1/videos?s=' + encodeURIComponent( term ) } )
				.then( function ( res ) {
					setItems( res && res.videos ? res.videos : [] );
					setLoading( false );
				} )
				.catch( function () {
					setItems( [] );
					setLoading( false );
				} );
		}

		// Auto-filter as the user types (debounced); also runs on open (query '').
		useEffect( function () {
			var timer = setTimeout( function () {
				search( query );
			}, 300 );
			return function () {
				clearTimeout( timer );
			};
		}, [ query ] );

		var grid = null;
		if ( loading || null === items ) {
			grid = el( Spinner, {} );
		} else if ( items && items.length ) {
			grid = el(
				'div',
				{ className: 'coywolf-cvm-picker-grid' },
				items.map( function ( v ) {
					return el(
						'button',
						{
							key: v.uid,
							type: 'button',
							className: 'coywolf-cvm-picker-item',
							onClick: function () {
								props.onSelect( v );
							}
						},
						v.thumbnail ? el( 'img', { src: v.thumbnail, alt: '', loading: 'lazy' } ) : el( 'span', { className: 'coywolf-cvm-picker-noimg' } ),
						el( 'span', { className: 'coywolf-cvm-picker-name' }, v.name || v.uid ),
						! v.ready ? el( 'span', { className: 'coywolf-cvm-picker-badge' }, __( 'processing', 'coywolf-video-manager' ) ) : null
					);
				} )
			);
		} else if ( items ) {
			grid = el( 'p', {}, __( 'No videos found.', 'coywolf-video-manager' ) );
		}

		return el(
			Modal,
			{
				title: __( 'Select a video', 'coywolf-video-manager' ),
				onRequestClose: props.onClose,
				className: 'coywolf-cvm-picker'
			},
			el(
				'div',
				{ className: 'coywolf-cvm-picker-search' },
				el( TextControl, {
					ref: searchRef,
					label: __( 'Search videos', 'coywolf-video-manager' ),
					hideLabelFromVision: true,
					value: query,
					placeholder: __( 'Search videos…', 'coywolf-video-manager' ),
					onChange: setQuery,
					__nextHasNoMarginBottom: true
				} )
			),
			grid
		);
	}

	/**
	 * Upload modal — a modal port of the Upload Video admin screen. Uploads a
	 * local file to Cloudflare (resumable, chunked, via the shared upload.js
	 * client) or imports one from a URL, waits for processing, then hands the
	 * finished video back to the block through props.onUploaded.
	 */
	function Uploader( props ) {
		var nameState = useState( '' );
		var name = nameState[ 0 ];
		var setName = nameState[ 1 ];
		var creatorState = useState( '' );
		var creator = creatorState[ 0 ];
		var setCreator = creatorState[ 1 ];
		var originsState = useState( '' );
		var originsText = originsState[ 0 ];
		var setOriginsText = originsState[ 1 ];
		var urlState = useState( '' );
		var url = urlState[ 0 ];
		var setUrl = urlState[ 1 ];

		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		// -1 hides the determinate bar (used for the file upload); a URL import
		// and post-upload processing are indeterminate and show a spinner.
		var progressState = useState( -1 );
		var progress = progressState[ 0 ];
		var setProgress = progressState[ 1 ];
		var statusState = useState( '' );
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];

		var fileRef = useRef( null );
		// Guards async callbacks (upload chunks, poll timers) from touching
		// state or applying the video after the modal has been closed.
		var aliveRef = useRef( true );
		useEffect( function () {
			aliveRef.current = true;
			return function () {
				aliveRef.current = false;
			};
		}, [] );

		function origins() {
			return originsText.split( /\n+/ ).map( function ( s ) {
				return s.trim();
			} ).filter( Boolean );
		}

		function fail( message ) {
			if ( ! aliveRef.current ) {
				return;
			}
			setBusy( false );
			// Hide the determinate bar so a frozen partial bar doesn't sit under
			// the error text.
			setProgress( -1 );
			setStatus( '✗ ' + message );
		}

		// Poll until Cloudflare finishes processing, then hand the video to the
		// block. Mirrors the admin uploader's poll loop (~2 min cap); instead of
		// redirecting, it applies the finished video's metadata to the block.
		function pollReady( uid, tries ) {
			if ( ! aliveRef.current ) {
				return;
			}
			apiFetch( { path: '/coywolf-cvm/v1/videos/' + encodeURIComponent( uid ) } ).then( function ( v ) {
				if ( ! aliveRef.current ) {
					return;
				}
				if ( v && 'error' === v.state ) {
					fail( __( 'Cloudflare could not process the video.', 'coywolf-video-manager' ) );
					return;
				}
				if ( v && v.ready ) {
					props.onUploaded( v );
				} else if ( tries >= 40 ) {
					// Still processing after ~2 minutes — hand over what we have
					// (the block re-fetches metadata and Cloudflare keeps going).
					props.onUploaded( v || { uid: uid, name: name } );
				} else {
					window.setTimeout( function () {
						pollReady( uid, tries + 1 );
					}, 3000 );
				}
			} ).catch( function () {
				if ( ! aliveRef.current ) {
					return;
				}
				if ( tries >= 40 ) {
					props.onUploaded( { uid: uid, name: name } );
				} else {
					window.setTimeout( function () {
						pollReady( uid, tries + 1 );
					}, 3000 );
				}
			} );
		}

		// After the raw upload, push creator/allowed origins (TUS metadata only
		// carried the name), then wait for processing.
		function applyAndFinish( uid ) {
			var data = {};
			if ( creator.trim() ) {
				data.creator = creator.trim();
			}
			var allowed = origins();
			if ( allowed.length ) {
				data.allowedOrigins = allowed;
			}
			var apply = Object.keys( data ).length
				? apiFetch( { path: '/coywolf-cvm/v1/videos/' + encodeURIComponent( uid ), method: 'POST', data: data } ).catch( function () {} )
				: Promise.resolve();
			apply.then( function () {
				pollReady( uid, 0 );
			} );
		}

		function startUpload() {
			var file = fileRef.current && fileRef.current.files && fileRef.current.files[ 0 ];
			if ( ! file ) {
				setStatus( __( 'Choose a video file first.', 'coywolf-video-manager' ) );
				return;
			}
			if ( ! window.coywolfCVMUpload ) {
				setStatus( '✗ ' + __( 'The uploader failed to load.', 'coywolf-video-manager' ) );
				return;
			}
			setBusy( true );
			setStatus( __( 'Preparing upload…', 'coywolf-video-manager' ) );
			apiFetch( {
				path: '/coywolf-cvm/v1/tus-upload',
				method: 'POST',
				data: { length: file.size, name: name.trim() || file.name, creator: creator.trim() }
			} ).then( function ( res ) {
				if ( ! aliveRef.current ) {
					return;
				}
				if ( ! res || ! res.uploadURL || ! res.uid ) {
					throw new Error( __( 'No upload URL returned.', 'coywolf-video-manager' ) );
				}
				setProgress( 0 );
				setStatus( __( 'Uploading…', 'coywolf-video-manager' ) );
				window.coywolfCVMUpload.tus( res.uploadURL, file, {
					onProgress: function ( fraction ) {
						if ( aliveRef.current ) {
							setProgress( Math.round( fraction * 100 ) );
						}
					},
					onStatus: function ( state ) {
						if ( aliveRef.current ) {
							setStatus( 'retrying' === state
								? __( 'Connection hiccup — resuming upload…', 'coywolf-video-manager' )
								: __( 'Uploading…', 'coywolf-video-manager' ) );
						}
					},
					onDone: function () {
						if ( ! aliveRef.current ) {
							return;
						}
						// Processing is indeterminate — swap the full bar for the
						// spinner while we poll Cloudflare.
						setProgress( -1 );
						setStatus( __( 'Uploaded. Cloudflare is processing the video…', 'coywolf-video-manager' ) );
						applyAndFinish( res.uid );
					},
					onError: function () {
						fail( __( 'Upload failed.', 'coywolf-video-manager' ) );
					}
				} );
			} ).catch( function ( e ) {
				fail( ( e && e.message ) ? e.message : __( 'Upload failed.', 'coywolf-video-manager' ) );
			} );
		}

		function startUrl() {
			var link = url.trim();
			if ( ! link ) {
				setStatus( __( 'Enter a video URL first.', 'coywolf-video-manager' ) );
				return;
			}
			setBusy( true );
			// Indeterminate: Cloudflare fetches the URL server-side. Keep the
			// determinate bar hidden (progress = -1) and show a spinner.
			setProgress( -1 );
			setStatus( __( 'Cloudflare is fetching the video from the URL…', 'coywolf-video-manager' ) );
			apiFetch( {
				path: '/coywolf-cvm/v1/copy',
				method: 'POST',
				data: { url: link, name: name.trim(), creator: creator.trim(), allowedOrigins: origins() }
			} ).then( function ( v ) {
				if ( ! aliveRef.current ) {
					return;
				}
				if ( ! v || ! v.uid ) {
					throw new Error( __( 'No video returned.', 'coywolf-video-manager' ) );
				}
				setStatus( __( 'Cloudflare is processing the video…', 'coywolf-video-manager' ) );
				pollReady( v.uid, 0 );
			} ).catch( function ( e ) {
				fail( ( e && e.message ) ? e.message : __( 'Import failed.', 'coywolf-video-manager' ) );
			} );
		}

		return el(
			Modal,
			{
				title: __( 'Upload video', 'coywolf-video-manager' ),
				onRequestClose: props.onClose,
				className: 'coywolf-cvm-uploader-modal'
			},
			el(
				'div',
				{ className: 'coywolf-cvm-upload-field' },
				el( 'label', { htmlFor: 'coywolf-cvm-up-file' }, __( 'Video file', 'coywolf-video-manager' ) ),
				el( 'input', { id: 'coywolf-cvm-up-file', type: 'file', accept: 'video/*', ref: fileRef, disabled: busy } ),
				el( 'p', { className: 'components-base-control__help' }, __( 'Large files are supported — the upload is sent in chunks and resumes after connection hiccups.', 'coywolf-video-manager' ) )
			),
			el( TextControl, {
				label: __( 'Or add from a URL', 'coywolf-video-manager' ),
				value: url,
				type: 'url',
				placeholder: 'https://example.com/video.mp4',
				disabled: busy,
				help: __( 'Cloudflare fetches the video directly from a publicly accessible URL — nothing passes through this site.', 'coywolf-video-manager' ),
				__nextHasNoMarginBottom: true,
				onChange: setUrl
			} ),
			el( TextControl, {
				label: __( 'Name', 'coywolf-video-manager' ),
				value: name,
				disabled: busy,
				__nextHasNoMarginBottom: true,
				onChange: setName
			} ),
			el( TextControl, {
				label: __( 'Creator', 'coywolf-video-manager' ),
				value: creator,
				disabled: busy,
				__nextHasNoMarginBottom: true,
				onChange: setCreator
			} ),
			el( TextareaControl, {
				label: __( 'Allowed origins (one per line)', 'coywolf-video-manager' ),
				value: originsText,
				rows: 3,
				placeholder: 'example.com',
				disabled: busy,
				__nextHasNoMarginBottom: true,
				onChange: setOriginsText
			} ),
			el(
				'div',
				{ className: 'coywolf-cvm-upload-actions' },
				el( Button, { variant: 'primary', onClick: startUpload, disabled: busy }, __( 'Upload to Cloudflare', 'coywolf-video-manager' ) ),
				el( Button, { variant: 'secondary', onClick: startUrl, disabled: busy }, __( 'Add from URL', 'coywolf-video-manager' ) )
			),
			( busy && progress < 0 )
				? el( 'div', { className: 'coywolf-cvm-upload-spinner' }, el( Spinner, {} ) )
				: null,
			progress >= 0
				? el(
					'div',
					{ className: 'coywolf-cvm-upload-progress' },
					el( 'div', { className: 'coywolf-cvm-upload-bar', style: { width: progress + '%' } } )
				)
				: null,
			status
				? el( 'div', { className: 'coywolf-cvm-upload-status', role: 'status', 'aria-live': 'polite' }, status )
				: null
		);
	}

	/**
	 * Block edit.
	 */
	function Edit( props ) {
		var a = props.attributes;
		var setAttributes = props.setAttributes;
		var previewRef = useRef( null );
		var patchRef = useRef( null );
		var blockProps = useBlockProps( { ref: previewRef } );
		var pickerState = useState( false );
		var pickerOpen = pickerState[ 0 ];
		var setPickerOpen = pickerState[ 1 ];
		var uploaderState = useState( false );
		var uploaderOpen = uploaderState[ 0 ];
		var setUploaderOpen = uploaderState[ 1 ];

		// The video's canonical name/description (what the Edit Video page
		// shows) and the user's staged edits (null = untouched). Staged edits
		// are pushed to the video only after the post itself is saved.
		var videoMetaState = useState( { name: null, description: null } );
		var videoMeta = videoMetaState[ 0 ];
		var setVideoMeta = videoMetaState[ 1 ];
		var stagedState = useState( { name: null, description: null } );
		var staged = stagedState[ 0 ];
		var setStaged = stagedState[ 1 ];
		var wasSavingPost = useRef( false );

		var isSavingPost = useSelect( function ( sel ) {
			var editor = sel( 'core/editor' );
			return editor ? ( editor.isSavingPost() && ! editor.isAutosavingPost() ) : false;
		}, [] );

		// Load the canonical name/description when the selected video changes.
		useEffect( function () {
			setVideoMeta( { name: null, description: null } );
			setStaged( { name: null, description: null } );
			if ( ! a.videoId ) {
				return undefined;
			}
			var alive = true;
			apiFetch( { path: '/coywolf-cvm/v1/videos/' + encodeURIComponent( a.videoId ) } )
				.then( function ( res ) {
					if ( alive && res ) {
						setVideoMeta( {
							name: 'string' === typeof res.name ? res.name : '',
							description: 'string' === typeof res.description ? res.description : ''
						} );
					}
				} )
				.catch( function () {} );
			return function () {
				alive = false;
			};
		}, [ a.videoId ] );

		// After the post finishes saving (autosaves excluded), push any staged
		// name/description edits to the video — the same update the Edit Video
		// page performs. Failures keep the edits staged so re-saving retries.
		useEffect( function () {
			var finished = wasSavingPost.current && ! isSavingPost;
			wasSavingPost.current = isSavingPost;
			if ( ! finished || ! a.videoId ) {
				return;
			}
			var editor = select( 'core/editor' );
			if ( editor && 'function' === typeof editor.didPostSaveRequestSucceed && ! editor.didPostSaveRequestSucceed() ) {
				return;
			}
			var data = {};
			if ( null !== staged.name && staged.name !== videoMeta.name ) {
				data.name = staged.name;
			}
			if ( null !== staged.description && staged.description !== videoMeta.description ) {
				data.description = staged.description;
			}
			if ( ! Object.keys( data ).length ) {
				return;
			}
			var uid = a.videoId;
			apiFetch( {
				path: '/coywolf-cvm/v1/videos/' + encodeURIComponent( uid ),
				method: 'POST',
				data: data
			} ).then( function () {
				setVideoMeta( {
					name: undefined !== data.name ? data.name : videoMeta.name,
					description: undefined !== data.description ? data.description : videoMeta.description
				} );
				setStaged( { name: null, description: null } );
				snackbar( 'success', __( 'Video updated.', 'coywolf-video-manager' ), uid );
			} ).catch( function ( err ) {
				snackbar( 'error', ( err && err.message ) ? err.message : __( 'Updating the video failed.', 'coywolf-video-manager' ), uid );
			} );
		}, [ isSavingPost ] );

		// The effective value of an inherit-able boolean (override or default).
		function resolvedBool( attr ) {
			var v = a[ attr ];
			return ( undefined === v || null === v ) ? defaultBool( attr ) : !! v;
		}

		function inheritToggle( attr, label ) {
			var def = defaultBool( attr );
			var isInheriting = ( undefined === a[ attr ] || null === a[ attr ] );
			var value = isInheriting ? def : a[ attr ];
			var help = isInheriting ? __( 'Inheriting the site default.', 'coywolf-video-manager' ) : __( 'Overriding the site default.', 'coywolf-video-manager' );
			return el( ToggleControl, {
				label: label,
				checked: !! value,
				help: help,
				__nextHasNoMarginBottom: true,
				onChange: function ( v ) {
					var update = {};
					update[ attr ] = v;
					setAttributes( update );
				}
			} );
		}

		var durationMax = Math.max( 1, Math.round( a.duration || 0 ) );

		// Staged edit if any, else the canonical value once loaded, else the
		// stored block name while the lookup is still in flight.
		var videoNameValue = null !== staged.name ? staged.name : ( null !== videoMeta.name ? videoMeta.name : ( a.videoName || '' ) );
		var videoDescValue = null !== staged.description ? staged.description : ( null !== videoMeta.description ? videoMeta.description : '' );

		// Live preview: mirror the server's caption markup client-side so name
		// and description edits show on the block instantly instead of waiting
		// for a ServerSideRender round-trip.
		function patchPreview() {
			var root = previewRef.current;
			var figure = root ? root.querySelector( '.coywolf-cvm' ) : null;
			if ( ! figure ) {
				return;
			}
			var name = videoNameValue;
			if ( '' === name ) {
				// Same fallback as the server: the post/page title.
				var editor = select( 'core/editor' );
				name = ( editor && editor.getEditedPostAttribute ) ? ( editor.getEditedPostAttribute( 'title' ) || '' ) : '';
			}
			var desc = videoDescValue.trim();
			var wantName = resolvedBool( 'showName' ) && '' !== name;
			var wantDesc = resolvedBool( 'showDescription' ) && '' !== desc;
			var caption = figure.querySelector( 'figcaption.coywolf-cvm-title' );

			// Until the canonical lookup returns, the description is unknown;
			// rebuilding the caption would briefly drop the server-rendered
			// text. Patch only the name in place and leave the rest alone.
			if ( null === staged.description && null === videoMeta.description ) {
				var nameEl = figure.querySelector( '.coywolf-cvm-name' );
				if ( nameEl && wantName && nameEl.textContent !== name ) {
					nameEl.textContent = name;
				}
				return;
			}

			if ( ! wantName && ! wantDesc ) {
				if ( caption ) {
					caption.parentNode.removeChild( caption );
				}
				return;
			}

			var html = '';
			if ( wantName ) {
				html += '<strong class="coywolf-cvm-name">' + escapeHtml( name ) + '</strong>';
			}
			if ( wantDesc ) {
				// Safe HTML is allowed in descriptions (as on the Edit Video
				// page); the server sanitizes with wp_kses_post on save, and
				// sanitizeHtml() covers the staged text until then.
				html += ( '' !== html ? ' — ' : '' ) + '<span class="coywolf-cvm-desc">' + sanitizeHtml( desc ) + '</span>';
			}

			if ( ! caption ) {
				caption = document.createElement( 'figcaption' );
				caption.className = 'coywolf-cvm-title';
				figure.insertBefore( caption, figure.querySelector( '.coywolf-cvm-meta' ) );
			}
			// The marker keeps the MutationObserver below from looping on our
			// own writes (innerHTML read-back may not equal what was set).
			if ( caption.getAttribute( 'data-cvm-preview' ) !== html ) {
				caption.setAttribute( 'data-cvm-preview', html );
				caption.innerHTML = html;
			}
		}
		patchRef.current = patchPreview;

		// Re-apply after every render (typing, toggles) …
		useEffect( function () {
			patchPreview();
		} );

		// … and whenever ServerSideRender swaps in fresh markup, which happens
		// outside this component's render cycle.
		useEffect( function () {
			var root = previewRef.current;
			if ( ! root || ! window.MutationObserver ) {
				return undefined;
			}
			var observer = new window.MutationObserver( function () {
				if ( patchRef.current ) {
					patchRef.current();
				}
			} );
			observer.observe( root, { childList: true, subtree: true } );
			return function () {
				observer.disconnect();
			};
		}, [] );

		// The description renders from its saved (option) value, not the block
		// attribute, so leave the staged attribute out of ServerSideRender —
		// re-fetching on description keystrokes would change nothing.
		var ssrAttributes = {};
		Object.keys( a ).forEach( function ( key ) {
			if ( 'videoDescription' !== key ) {
				ssrAttributes[ key ] = a[ key ];
			}
		} );

		var inspector = el(
			InspectorControls,
			{},
			el(
				PanelBody,
				{ title: __( 'Video', 'coywolf-video-manager' ), initialOpen: true },
				el( 'p', {}, a.videoName ? a.videoName : __( 'No video selected.', 'coywolf-video-manager' ) ),
				el(
					Button,
					{
						variant: 'secondary',
						onClick: function () {
							setPickerOpen( true );
						}
					},
					a.videoId ? __( 'Replace video', 'coywolf-video-manager' ) : __( 'Select video', 'coywolf-video-manager' )
				)
			),
			el(
				PanelBody,
				{ title: __( 'Size', 'coywolf-video-manager' ), initialOpen: false },
				el( SelectControl, {
					label: __( 'Size', 'coywolf-video-manager' ),
					value: a.sizeMode,
					options: [
						{ label: __( 'Responsive', 'coywolf-video-manager' ), value: 'responsive' },
						{ label: __( 'Max width', 'coywolf-video-manager' ), value: 'maxwidth' }
					],
					__nextHasNoMarginBottom: true,
					onChange: function ( v ) {
						setAttributes( { sizeMode: v } );
					}
				} ),
				'maxwidth' === a.sizeMode
					? el( RangeControl, {
						label: __( 'Max width (px)', 'coywolf-video-manager' ),
						value: a.maxWidth,
						min: 200,
						max: 1920,
						__nextHasNoMarginBottom: true,
						onChange: function ( v ) {
							setAttributes( { maxWidth: v } );
						}
					} )
					: null
			),
			el(
				PanelBody,
				{ title: __( 'Poster', 'coywolf-video-manager' ), initialOpen: false },
				el( SelectControl, {
					label: __( 'Poster source', 'coywolf-video-manager' ),
					value: a.posterMode,
					options: [
						{ label: __( 'Timestamp', 'coywolf-video-manager' ), value: 'timestamp' },
						{ label: __( 'Image (Media Library)', 'coywolf-video-manager' ), value: 'media' }
					],
					__nextHasNoMarginBottom: true,
					onChange: function ( v ) {
						setAttributes( { posterMode: v } );
					}
				} ),
				'timestamp' === a.posterMode
					? el( TimeField, {
						label: __( 'Timestamp', 'coywolf-video-manager' ),
						value: a.posterTime,
						max: durationMax > 1 ? durationMax : 0,
						help: __( 'Minutes:seconds (4:26), hours:minutes:seconds (1:02:15), or seconds (266).', 'coywolf-video-manager' ),
						onChange: function ( v ) {
							setAttributes( { posterTime: v } );
						}
					} )
					: el(
						'div',
						{ className: 'coywolf-cvm-poster-media' },
						el(
							MediaUploadCheck,
							{},
							el( MediaUpload, {
								allowedTypes: [ 'image' ],
								value: a.posterId,
								onSelect: function ( m ) {
									setAttributes( { posterId: m.id, posterUrl: m.url } );
								},
								render: function ( o ) {
									return el(
										Button,
										{ variant: 'secondary', onClick: o.open },
										a.posterUrl ? __( 'Change image', 'coywolf-video-manager' ) : __( 'Select image', 'coywolf-video-manager' )
									);
								}
							} )
						),
						el(
							'p',
							{ className: 'components-base-control__help', style: { marginTop: '8px' } },
							__( 'Recommended size:', 'coywolf-video-manager' ) + ' 1200 × ' + ( a.aspectRatio > 0 ? Math.round( 1200 * a.aspectRatio ) : 675 ) + 'px'
						)
					)
			),
			el(
				PanelBody,
				{ title: __( 'Playback', 'coywolf-video-manager' ), initialOpen: false },
				el( TimeField, {
					label: __( 'Start time', 'coywolf-video-manager' ),
					value: a.startTime,
					max: durationMax > 1 ? durationMax : 0,
					help: __( 'Minutes:seconds (4:26), hours:minutes:seconds (1:02:15), or seconds (266).', 'coywolf-video-manager' ),
					onChange: function ( v ) {
						setAttributes( { startTime: v } );
					}
				} ),
				inheritToggle( 'controls', __( 'Controls', 'coywolf-video-manager' ) ),
				inheritToggle( 'autoplay', __( 'Autoplay', 'coywolf-video-manager' ) ),
				inheritToggle( 'loop', __( 'Loop', 'coywolf-video-manager' ) ),
				inheritToggle( 'mute', __( 'Mute', 'coywolf-video-manager' ) ),
				inheritToggle( 'lazy', __( 'Lazy-load', 'coywolf-video-manager' ) ),
				el( SelectControl, {
					label: __( 'Preload', 'coywolf-video-manager' ),
					value: undefined === a.preload ? '' : a.preload,
					options: [
						{ label: __( 'Inherit', 'coywolf-video-manager' ) + ' (' + ( defaults.preload || 'metadata' ) + ')', value: '' },
						{ label: 'none', value: 'none' },
						{ label: 'metadata', value: 'metadata' },
						{ label: 'auto', value: 'auto' }
					],
					__nextHasNoMarginBottom: true,
					onChange: function ( v ) {
						setAttributes( { preload: '' === v ? undefined : v } );
					}
				} )
			),
			el(
				PanelBody,
				{ title: __( 'Appearance', 'coywolf-video-manager' ), initialOpen: false },
				inheritToggle( 'showName', __( 'Show video name', 'coywolf-video-manager' ) ),
				a.videoId && resolvedBool( 'showName' )
					? el(
						'div',
						{ className: 'coywolf-cvm-video-field' },
						el( TextareaControl, {
							label: __( 'Video name', 'coywolf-video-manager' ),
							value: videoNameValue,
							rows: 2,
							help: __( 'Saved to the video when the post is saved.', 'coywolf-video-manager' ),
							__nextHasNoMarginBottom: true,
							onChange: function ( v ) {
								setStaged( function ( prev ) {
									return { name: v, description: prev.description };
								} );
								// Keep the block's stored name (preview +
								// front-end fallback) in sync.
								setAttributes( { videoName: v } );
							}
						} )
					)
					: null,
				inheritToggle( 'showDescription', __( 'Show video description', 'coywolf-video-manager' ) ),
				a.videoId && resolvedBool( 'showDescription' )
					? el(
						'div',
						{ className: 'coywolf-cvm-video-field' },
						el( TextareaControl, {
							label: __( 'Video description', 'coywolf-video-manager' ),
							value: videoDescValue,
							rows: 4,
							help: __( 'Saved to the video when the post is saved.', 'coywolf-video-manager' ),
							__nextHasNoMarginBottom: true,
							onChange: function ( v ) {
								setStaged( function ( prev ) {
									return { name: prev.name, description: v };
								} );
								// Stored on the block too, so the edit marks
								// the post as needing a save.
								setAttributes( { videoDescription: v } );
							}
						} )
					)
					: null,
				el( SelectControl, {
					label: __( 'Name & description alignment', 'coywolf-video-manager' ),
					value: a.contentAlign || '',
					options: [
						{ label: __( 'Inherit', 'coywolf-video-manager' ) + ' (' + ( defaults.align || 'left' ) + ')', value: '' },
						{ label: __( 'Left', 'coywolf-video-manager' ), value: 'left' },
						{ label: __( 'Center', 'coywolf-video-manager' ), value: 'center' },
						{ label: __( 'Right', 'coywolf-video-manager' ), value: 'right' }
					],
					__nextHasNoMarginBottom: true,
					onChange: function ( v ) {
						setAttributes( { contentAlign: v || undefined } );
					}
				} ),
				el( SelectControl, {
					label: __( 'Like / views / date alignment', 'coywolf-video-manager' ),
					value: a.metaAlign || '',
					options: [
						{ label: __( 'Inherit', 'coywolf-video-manager' ) + ' (' + ( defaults.meta_align || 'left' ) + ')', value: '' },
						{ label: __( 'Left', 'coywolf-video-manager' ), value: 'left' },
						{ label: __( 'Center', 'coywolf-video-manager' ), value: 'center' },
						{ label: __( 'Right', 'coywolf-video-manager' ), value: 'right' }
					],
					__nextHasNoMarginBottom: true,
					onChange: function ( v ) {
						setAttributes( { metaAlign: v || undefined } );
					}
				} ),
				inheritToggle( 'showPlays', __( 'Show view count', 'coywolf-video-manager' ) ),
				inheritToggle( 'enableLikes', __( 'Show like button', 'coywolf-video-manager' ) ),
				inheritToggle( 'showLikeCount', __( 'Show like count', 'coywolf-video-manager' ) ),
				inheritToggle( 'showDate', __( 'Show upload date', 'coywolf-video-manager' ) ),
				inheritToggle( 'dateFromPost', __( 'Use the post/page date as the upload date', 'coywolf-video-manager' ) ),
				el(
					BaseControl,
					{ label: __( 'Play button color', 'coywolf-video-manager' ), __nextHasNoMarginBottom: true },
					el( ColorPalette, {
						colors: [],
						value: a.primaryColor || undefined,
						clearable: true,
						disableCustomColors: false,
						onChange: function ( v ) {
							setAttributes( { primaryColor: v || '' } );
						}
					} )
				),
				el( RangeControl, {
					label: __( 'Player corner radius (px)', 'coywolf-video-manager' ),
					value: ( undefined === a.radius || null === a.radius ) ? ( defaults.radius || 0 ) : a.radius,
					min: 0,
					max: 48,
					step: 1,
					allowReset: true,
					__nextHasNoMarginBottom: true,
					help: ( undefined === a.radius || null === a.radius ) ? __( 'Inheriting the site default.', 'coywolf-video-manager' ) : __( 'Overriding the site default.', 'coywolf-video-manager' ),
					onChange: function ( v ) {
						setAttributes( { radius: ( undefined === v ) ? undefined : v } );
					}
				} ),
				inheritToggle( 'showBorder', __( 'Show a border', 'coywolf-video-manager' ) ),
				resolvedBool( 'showBorder' )
					? el( RangeControl, {
						label: __( 'Border width (px)', 'coywolf-video-manager' ),
						value: ( undefined === a.borderWidth || null === a.borderWidth ) ? ( defaults.border_width || 1 ) : a.borderWidth,
						min: 0,
						max: 20,
						step: 1,
						allowReset: true,
						__nextHasNoMarginBottom: true,
						help: ( undefined === a.borderWidth || null === a.borderWidth ) ? __( 'Inheriting the site default.', 'coywolf-video-manager' ) : __( 'Overriding the site default.', 'coywolf-video-manager' ),
						onChange: function ( v ) {
							setAttributes( { borderWidth: ( undefined === v ) ? undefined : v } );
						}
					} )
					: null,
				resolvedBool( 'showBorder' )
					? el(
						BaseControl,
						{ label: __( 'Border color', 'coywolf-video-manager' ), __nextHasNoMarginBottom: true },
						el( ColorPalette, {
							colors: [],
							value: a.borderColor || ( defaults.border_color || '#eeeeee' ),
							clearable: true,
							disableCustomColors: false,
							onChange: function ( v ) {
								setAttributes( { borderColor: v || '' } );
							}
						} )
					)
					: null
			)
		);

		var body;
		if ( ! a.videoId ) {
			body = el(
				Placeholder,
				{
					icon: 'video-alt3',
					label: __( 'Coywolf Video', 'coywolf-video-manager' ),
					instructions: __( 'Choose a video from your Cloudflare Stream library, or upload a new one.', 'coywolf-video-manager' )
				},
				el(
					'div',
					{ className: 'coywolf-cvm-placeholder-actions' },
					el(
						Button,
						{
							variant: 'primary',
							onClick: function () {
								setPickerOpen( true );
							}
						},
						__( 'Select video', 'coywolf-video-manager' )
					),
					el(
						Button,
						{
							variant: 'secondary',
							onClick: function () {
								setUploaderOpen( true );
							}
						},
						__( 'Upload video', 'coywolf-video-manager' )
					)
				)
			);
		} else {
			body = el( ServerSideRender, {
				block: 'coywolf/video',
				attributes: ssrAttributes
			} );
		}

		// Point the block at a video (from the picker or a fresh upload).
		function applyVideo( v ) {
			var ratio = ( v.width > 0 && v.height > 0 ) ? ( v.height / v.width ) : 0;
			setAttributes( {
				videoId: v.uid,
				videoName: v.name || v.uid,
				duration: v.duration || 0,
				aspectRatio: ratio,
				uploaded: v.created || ''
			} );
		}

		var modal = pickerOpen
			? el( Picker, {
				onClose: function () {
					setPickerOpen( false );
				},
				onSelect: function ( v ) {
					applyVideo( v );
					setPickerOpen( false );
				}
			} )
			: null;

		var uploaderModal = uploaderOpen
			? el( Uploader, {
				onClose: function () {
					setUploaderOpen( false );
				},
				onUploaded: function ( v ) {
					applyVideo( v );
					setUploaderOpen( false );
				}
			} )
			: null;

		return el( Fragment, {}, inspector, el( 'div', blockProps, body ), modal, uploaderModal );
	}

	/**
	 * Static fallback saved into post content (shown only if the plugin is gone).
	 */
	function save( props ) {
		var a = props.attributes;
		var blockProps = useBlockProps.save();
		if ( ! a.videoId ) {
			return null;
		}
		return el(
			'div',
			blockProps,
			el(
				'a',
				{ href: 'https://iframe.videodelivery.net/' + encodeURIComponent( a.videoId ) },
				a.videoName || a.videoId
			)
		);
	}

	wp.blocks.registerBlockType( 'coywolf/video', {
		edit: Edit,
		save: save
	} );
} )( window.wp );
