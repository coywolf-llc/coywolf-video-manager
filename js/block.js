/**
 * Coywolf Video Manager — editor block (no build step; uses window.wp.*).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var apiFetch = wp.apiFetch;
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
		playsInSchema: 'plays_in_schema',
		enableLikes: 'likes_enabled',
		showLikeCount: 'likes_show_count',
		showName: 'show_title',
		showDescription: 'show_desc'
	};

	function defaultBool( attr ) {
		return !! defaults[ defaultKey[ attr ] ];
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
	 * Block edit.
	 */
	function Edit( props ) {
		var a = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps = useBlockProps();
		var pickerState = useState( false );
		var pickerOpen = pickerState[ 0 ];
		var setPickerOpen = pickerState[ 1 ];

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
					? el( RangeControl, {
						label: __( 'Timestamp (seconds)', 'coywolf-video-manager' ),
						value: a.posterTime,
						min: 0,
						max: durationMax > 1 ? durationMax : 600,
						__nextHasNoMarginBottom: true,
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
				el( RangeControl, {
					label: __( 'Start time (seconds)', 'coywolf-video-manager' ),
					value: a.startTime,
					min: 0,
					max: durationMax > 1 ? durationMax : 3600,
					__nextHasNoMarginBottom: true,
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
				inheritToggle( 'showDescription', __( 'Show video description', 'coywolf-video-manager' ) ),
				el( SelectControl, {
					label: __( 'Alignment', 'coywolf-video-manager' ),
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
				inheritToggle( 'showPlays', __( 'Show view count', 'coywolf-video-manager' ) ),
				inheritToggle( 'playsInSchema', __( 'Include views in schema', 'coywolf-video-manager' ) ),
				inheritToggle( 'enableLikes', __( 'Show like button', 'coywolf-video-manager' ) ),
				inheritToggle( 'showLikeCount', __( 'Show like count', 'coywolf-video-manager' ) ),
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
				} )
			)
		);

		var body;
		if ( ! a.videoId ) {
			body = el(
				Placeholder,
				{
					icon: 'video-alt3',
					label: __( 'Coywolf Video', 'coywolf-video-manager' ),
					instructions: __( 'Choose a video from your Cloudflare Stream library.', 'coywolf-video-manager' )
				},
				el(
					Button,
					{
						variant: 'primary',
						onClick: function () {
							setPickerOpen( true );
						}
					},
					__( 'Select video', 'coywolf-video-manager' )
				)
			);
		} else {
			body = el( ServerSideRender, {
				block: 'coywolf/video',
				attributes: a
			} );
		}

		var modal = pickerOpen
			? el( Picker, {
				onClose: function () {
					setPickerOpen( false );
				},
				onSelect: function ( v ) {
					var ratio = ( v.width > 0 && v.height > 0 ) ? ( v.height / v.width ) : 0;
					setAttributes( {
						videoId: v.uid,
						videoName: v.name || v.uid,
						duration: v.duration || 0,
						aspectRatio: ratio,
						uploaded: v.created || ''
					} );
					setPickerOpen( false );
				}
			} )
			: null;

		return el( Fragment, {}, inspector, el( 'div', blockProps, body ), modal );
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
