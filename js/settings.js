/**
 * Coywolf Video Manager — Settings → Appearance: jscolorpicker init + live preview.
 * Vanilla JS, no build step.
 */
( function () {
	'use strict';

	var cfg = window.coywolfCVMSettings || {};
	var defaults = cfg.defaults || {};

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function byId( id ) {
		return document.getElementById( id );
	}

	function fieldValue( key ) {
		var color = document.querySelector( '.coywolf-cvm-color-field[data-key="' + key + '"] .coywolf-cvm-color-value' );
		if ( color ) {
			return color.value;
		}
		var input = document.querySelector( '.coywolf-cvm-field[data-key="' + key + '"]' );
		return input ? input.value : '';
	}

	function fieldChecked( key ) {
		var box = document.querySelector( '.coywolf-cvm-toggle[data-key="' + key + '"]' );
		return box ? box.checked : true;
	}

	function currentScheme() {
		var s = document.querySelector( '.coywolf-cvm-scheme' );
		return s ? s.value : 'off';
	}

	function setVar( el, name, value ) {
		if ( null === value ) {
			el.style.removeProperty( name );
		} else {
			el.style.setProperty( name, value );
		}
	}

	function applyColor( preview, key, name ) {
		var value = ( fieldValue( key ) || '' ).trim();
		var def = ( '' + ( defaults[ key ] || '' ) ).toLowerCase();
		if ( '' === value || value.toLowerCase() === def ) {
			setVar( preview, name, null ); // fall back to scheme / default.
		} else {
			setVar( preview, name, value );
		}
	}

	function applySize( preview, key, name ) {
		var value = parseFloat( fieldValue( key ) );
		var def = parseFloat( defaults[ key ] );
		if ( ! isNaN( value ) && Math.abs( value - def ) > 0.001 ) {
			setVar( preview, name, value + 'rem' );
		} else {
			setVar( preview, name, null );
		}
	}

	function applyChoice( preview, key, name ) {
		var value = fieldValue( key );
		if ( value && value !== '' + defaults[ key ] ) {
			setVar( preview, name, value );
		} else {
			setVar( preview, name, null );
		}
	}

	// Like background: an explicitly empty value means "no background".
	function applyLikeBg( preview ) {
		var value = ( fieldValue( 'like_bg' ) || '' ).trim();
		if ( '' === value ) {
			setVar( preview, '--cvm-like-bg', 'transparent' );
			return;
		}
		var def = ( '' + ( defaults.like_bg || '' ) ).toLowerCase();
		setVar( preview, '--cvm-like-bg', value.toLowerCase() === def ? null : value );
	}

	// Swap the preview icon (heart / thumbs / star) from the localized SVGs.
	function applyIcon( preview ) {
		var icons = cfg.icons || {};
		var sel = document.querySelector( '.coywolf-cvm-field[data-key="like_icon"]' );
		var svg = icons[ sel ? sel.value : 'heart' ] || icons.heart;
		var old = preview.querySelector( '.coywolf-cvm-thumb' );
		if ( ! svg || ! old ) {
			return;
		}
		var tmp = document.createElement( 'div' );
		tmp.innerHTML = svg;
		var node = tmp.firstChild;
		if ( node ) {
			old.parentNode.replaceChild( node, old );
		}
	}

	function updatePreview() {
		var preview = byId( 'coywolf-cvm-preview' );
		if ( ! preview ) {
			return;
		}

		applyColor( preview, 'title_color', '--cvm-title-color' );
		applyColor( preview, 'like_color', '--cvm-like-color' );
		applyColor( preview, 'like_active_color', '--cvm-like-active' );
		applyLikeBg( preview );
		applyColor( preview, 'meta_color', '--cvm-meta-color' );
		applySize( preview, 'title_size', '--cvm-title-size' );
		applySize( preview, 'meta_size', '--cvm-meta-size' );
		applyChoice( preview, 'title_weight', '--cvm-title-weight' );
		applyChoice( preview, 'desc_weight', '--cvm-desc-weight' );
		applyChoice( preview, 'align', '--cvm-align' );
		applyIcon( preview );

		// Show/hide the name, description, and their separator.
		var showName = fieldChecked( 'show_title' );
		var showDesc = fieldChecked( 'show_desc' );
		var nameEl = preview.querySelector( '.coywolf-cvm-name' );
		var descEl = preview.querySelector( '.coywolf-cvm-desc' );
		var sepEl = preview.querySelector( '.coywolf-cvm-sep' );
		if ( nameEl ) {
			nameEl.style.display = showName ? '' : 'none';
		}
		if ( descEl ) {
			descEl.style.display = showDesc ? '' : 'none';
		}
		if ( sepEl ) {
			sepEl.style.display = ( showName && showDesc ) ? '' : 'none';
		}

		// A configured clicked color enables the hover + filled-when-liked styling.
		preview.classList.toggle( 'coywolf-cvm-active-like', '' !== ( fieldValue( 'like_active_color' ) || '' ).trim() );

		var scheme = currentScheme();
		preview.className = preview.className.replace( /\s*coywolf-cvm-scheme-(off|auto|light|dark)/g, '' );
		if ( 'off' !== scheme ) {
			preview.classList.add( 'coywolf-cvm-scheme-' + scheme );
		}

		var stage = byId( 'coywolf-cvm-preview-stage' );
		if ( stage ) {
			var dark = 'dark' === scheme ||
				( 'auto' === scheme && window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches );
			stage.classList.toggle( 'is-dark', !! dark );
		}
	}

	function initPickers() {
		if ( 'undefined' === typeof ColorPicker ) {
			return;
		}
		var fields = document.querySelectorAll( '.coywolf-cvm-color-field' );
		Array.prototype.forEach.call( fields, function ( field ) {
			var hidden = field.querySelector( '.coywolf-cvm-color-value' );
			var mount = field.querySelector( '.coywolf-cvm-color-mount' );
			if ( ! hidden || ! mount ) {
				return;
			}
			var picker = new ColorPicker( mount, {
				color: hidden.value || null,
				submitMode: 'instant',
				enableAlpha: false,
				formats: [ 'hex' ],
				defaultFormat: 'hex',
				showClearButton: true
			} );
			picker.on( 'pick', function ( color ) {
				var hex = '';
				if ( color ) {
					hex = color.string( 'hex' );
					if ( hex && '#' !== hex.charAt( 0 ) ) {
						hex = '#' + hex;
					}
					if ( hex.length > 7 ) {
						hex = hex.substring( 0, 7 ); // drop any alpha.
					}
				}
				hidden.value = hex;
				updatePreview();
			} );
		} );
	}

	function wireInputs() {
		var inputs = document.querySelectorAll( '.coywolf-cvm-field, .coywolf-cvm-scheme, .coywolf-cvm-toggle' );
		Array.prototype.forEach.call( inputs, function ( input ) {
			input.addEventListener( 'change', updatePreview );
			input.addEventListener( 'input', updatePreview );
		} );

		// Let the preview's like button demonstrate the outline → filled toggle.
		var like = document.querySelector( '#coywolf-cvm-preview .coywolf-cvm-like' );
		if ( like ) {
			like.addEventListener( 'click', function () {
				like.classList.toggle( 'is-liked' );
				like.setAttribute( 'aria-pressed', like.classList.contains( 'is-liked' ) ? 'true' : 'false' );
				like.classList.remove( 'is-animating' );
				void like.offsetWidth;
				like.classList.add( 'is-animating' );
			} );
		}
	}

	ready( function () {
		initPickers();
		wireInputs();
		updatePreview();
	} );
} )();
