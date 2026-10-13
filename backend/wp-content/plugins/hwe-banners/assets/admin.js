/* global jQuery, wp, HWEBannersConfig */
( function ( $ ) {
	'use strict';

	var TYPES = ( typeof HWEBannersConfig !== 'undefined' && HWEBannersConfig.types ) ? HWEBannersConfig.types : {};

	function bindMedia( $scope ) {
		$scope.find( '.hwe-media-pick' ).off( 'click.hwe' ).on( 'click.hwe', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var $row = $btn.closest( '.hwe-media' );
			var svgOnly = $btn.hasClass( 'hwe-media-pick-svg' );
			var frame = wp.media( {
				title: svgOnly ? 'Seleccionar SVG' : 'Seleccionar imagen',
				multiple: false,
				library: { type: 'image' },
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url = att.url || '';
				if ( svgOnly && !/\.svg(\?|$)/i.test( url ) ) {
					window.alert( 'Elige un archivo SVG.' );
					return;
				}
				$row.find( '.hwe-media-url' ).val( url );
				$row.find( '.hwe-media-preview' ).html( url ? '<img src="' + url + '" alt="">' : '' );
			} );
			frame.open();
		} );
		$scope.find( '.hwe-media-clear' ).off( 'click.hwe' ).on( 'click.hwe', function ( e ) {
			e.preventDefault();
			var $row = $( this ).closest( '.hwe-media' );
			$row.find( '.hwe-media-url' ).val( '' );
			$row.find( '.hwe-media-preview' ).empty();
		} );
	}

	function applyDecorMode( $slide ) {
		var $mode = $slide.find( '.hwe-decor-mode' );
		if ( !$mode.length ) {
			return;
		}
		// Si el bloque decor_mode está oculto por plantilla, no tocar imagen/SVG.
		var modeField = $slide.find( '[data-hwe-field="decor_mode"]' )[0];
		if ( modeField && modeField.style.display === 'none' ) {
			return;
		}
		var mode = $mode.val();
		var isDecor = mode === 'default' || mode === 'custom';
		var custom = mode === 'custom';
		$slide.find( '[data-hwe-field="decor_svg"]' ).each( function () {
			this.style.display = custom ? '' : 'none';
		} );
		// El selector de visual decorativo de plantilla solo tiene sentido con el visual
		// «por defecto»: en `custom` manda el SVG subido y en `none` no hay
		// decorativo ninguno. (Puede no existir: la instancia declara los presets,
		// y con menos de dos el metabox no pinta el campo.)
		$slide.find( '[data-hwe-field="decor_preset"]' ).each( function () {
			this.style.display = mode === 'default' ? '' : 'none';
		} );
		// XOR: visual decorativo ↔ imagen (desktop/móvil, incl. overrides i18n).
		$slide.find( '[data-hwe-field="image"], [data-hwe-field="image_mobile"], [data-hwe-field="image_alt"]' ).each( function () {
			this.style.display = isDecor ? 'none' : '';
		} );
	}

	// Muestra/oculta los campos del slide según el tipo elegido y actualiza la
	// descripción de estructura y el chip del tipo.
	function applyType( $slide ) {
		var type = $slide.find( '.hwe-type-select' ).val();
		var cfg = TYPES[ type ] || null;
		var fields = ( cfg && cfg.fields ) ? cfg.fields : null; // null = mostrar todos

		$slide.find( '[data-hwe-field]' ).each( function () {
			var key = this.getAttribute( 'data-hwe-field' );
			var show = !fields || fields.indexOf( key ) !== -1;
			this.style.display = show ? '' : 'none';
		} );

		// Oculta un fieldset i18n si ninguno de sus campos queda visible.
		$slide.find( '.hwe-i18n' ).each( function () {
			var visible = $( this ).find( '[data-hwe-field]' ).filter( function () {
				return this.style.display !== 'none';
			} ).length;
			this.style.display = visible ? '' : 'none';
		} );

		$slide.find( '.hwe-type-desc' ).text( ( cfg && cfg.description ) ? cfg.description : '' );
		$slide.find( '.hwe-slide-typechip' ).text( ( cfg && cfg.label ) ? cfg.label : type );

		applyDecorMode( $slide );
		applyShowCopy( $slide );
	}

	/** Landing: si "habilitar copy" está off, oculta campos de texto del panel. */
	function applyShowCopy( $slide ) {
		var $field = $slide.find( '[data-hwe-field="show_copy"]' );
		if ( !$field.length || $field[0].style.display === 'none' ) {
			return;
		}
		var on = $slide.find( '.hwe-show-copy' ).is( ':checked' );
		var copyKeys = [ 'badge', 'title', 'subtitle', 'cta', 'cta_href', 'cta2', 'cta2_href' ];
		copyKeys.forEach( function ( key ) {
			$slide.find( '[data-hwe-field="' + key + '"]' ).each( function () {
				this.style.display = on ? '' : 'none';
			} );
		} );
	}

	function updatePreview( $slide ) {
		$slide.find( '.hwe-slide-preview' ).text( $slide.find( '.hwe-title-input' ).val() || '' );
	}

	function setCollapsed( $slide, collapsed ) {
		$slide.toggleClass( 'hwe-collapsed', !!collapsed );
		$slide.find( '.hwe-slide-toggle' ).attr( 'aria-expanded', collapsed ? 'false' : 'true' );
	}

	function refreshAddButton( $list ) {
		var max = parseInt( $list.attr( 'data-max-slides' ), 10 );
		if ( !max ) {
			return;
		}
		$( '#hwe-add-slide' ).prop( 'disabled', $list.find( '.hwe-slide' ).length >= max );
	}

	function initSlide( $slide ) {
		applyType( $slide );
		updatePreview( $slide );
		bindMedia( $slide );
	}

	$( function () {
		var $list = $( '#hwe-slides' );

		$list.find( '.hwe-slide' ).each( function () {
			var $s = $( this );
			initSlide( $s );
			setCollapsed( $s, true );
		} );
		refreshAddButton( $list );

		$( '#hwe-add-slide' ).on( 'click', function () {
			var index = parseInt( $list.attr( 'data-next-index' ), 10 ) || 0;
			var tpl = $( '#hwe-slide-template' ).html().replace( /__INDEX__/g, String( index ) );
			var $slide = $( tpl );
			$list.append( $slide );
			$list.attr( 'data-next-index', String( index + 1 ) );
			initSlide( $slide );
			setCollapsed( $slide, false );
			refreshAddButton( $list );
		} );

		$list.on( 'click', '.hwe-remove-slide', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.hwe-slide' ).remove();
			refreshAddButton( $list );
		} );

		$list.on( 'click', '.hwe-slide-toggle', function () {
			var $slide = $( this ).closest( '.hwe-slide' );
			setCollapsed( $slide, !$slide.hasClass( 'hwe-collapsed' ) );
		} );

		$list.on( 'change', '.hwe-type-select', function () {
			applyType( $( this ).closest( '.hwe-slide' ) );
		} );

		$list.on( 'change', '.hwe-decor-mode', function () {
			applyDecorMode( $( this ).closest( '.hwe-slide' ) );
		} );

		$list.on( 'change', '.hwe-show-copy', function () {
			applyShowCopy( $( this ).closest( '.hwe-slide' ) );
		} );

		$list.on( 'input', '.hwe-title-input', function () {
			updatePreview( $( this ).closest( '.hwe-slide' ) );
		} );

		$( '#hwe-expand-all' ).on( 'click', function ( e ) {
			e.preventDefault();
			$list.find( '.hwe-slide' ).each( function () { setCollapsed( $( this ), false ); } );
		} );
		$( '#hwe-collapse-all' ).on( 'click', function ( e ) {
			e.preventDefault();
			$list.find( '.hwe-slide' ).each( function () { setCollapsed( $( this ), true ); } );
		} );
	} );
}( jQuery ) );
