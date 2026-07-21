/* global jQuery, wp */
( function ( $ ) {
	'use strict';

	function bindMedia( $scope ) {
		$scope.find( '.hwe-media-pick' ).off( 'click.hwe' ).on( 'click.hwe', function ( e ) {
			e.preventDefault();
			var $row = $( this ).closest( '.hwe-media' );
			var frame = wp.media( { title: 'Seleccionar imagen', multiple: false, library: { type: 'image' } } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				$row.find( '.hwe-media-url' ).val( att.url );
				$row.find( '.hwe-media-preview' ).html( '<img src="' + att.url + '" alt="">' );
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

	$( function () {
		var $list = $( '#hwe-slides' );
		bindMedia( $list );

		$( '#hwe-add-slide' ).on( 'click', function () {
			var index = parseInt( $list.attr( 'data-next-index' ), 10 ) || 0;
			var tpl = $( '#hwe-slide-template' ).html().replace( /__INDEX__/g, String( index ) );
			var $slide = $( tpl );
			$list.append( $slide );
			$list.attr( 'data-next-index', String( index + 1 ) );
			bindMedia( $slide );
		} );

		$list.on( 'click', '.hwe-remove-slide', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.hwe-slide' ).remove();
		} );
	} );
}( jQuery ) );
