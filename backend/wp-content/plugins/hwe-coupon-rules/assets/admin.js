/**
 * Pantalla de edición del cupón: traslada los campos nativos de WooCommerce a
 * las pestañas por tema de `admin.php`, muestra u oculta lo que depende de otra
 * opción, avisa de las combinaciones que no hacen lo que parecen y mantiene el
 * «Resumen del cupón» al día.
 *
 * Se mueve la FILA entera de cada campo (con su `name`), así que el guardado
 * del core no cambia. Los eventos van con jQuery a propósito: select2 y el
 * datepicker de WooCommerce disparan `change` con `.trigger()`, que un
 * `addEventListener` nativo no recibe.
 */
/* global jQuery, hweCouponAdmin */
( function ( $ ) {
	'use strict';

	var cfg = window.hweCouponAdmin || { currency: '$', decimals: 0, today: '' };

	/* ------------------------------------------------------------------ */
	/* Traslado de campos nativos                                          */
	/* ------------------------------------------------------------------ */

	/** Fila (`p.form-field`) de un campo, a partir de su selector. */
	function rowOf( selector ) {
		var field = document.querySelector( selector );
		return field ? field.closest( '.form-field' ) : null;
	}

	// Hueco → filas nativas, en el orden en que se pintan.
	var SLOTS = {
		'scope-lists': [ 'select[name="product_ids[]"]', '#product_categories' ],
		exclusions: [ 'select[name="exclude_product_ids[]"]', '#exclude_product_categories', '#exclude_sale_items' ],
		customers: [ '#customer_email', '#usage_limit_per_user' ],
		validity: [ '#expiry_date' ],
		cart: [ '#minimum_amount', '#maximum_amount', '#individual_use' ],
		usage: [ '#usage_limit', '#limit_usage_to_x_items' ],
		// WooCommerce Brands (9.6+): solo existen si la integración está activa.
		brands: [ '#product_brands', '#exclude_product_brands' ],
	};

	var NATIVE_PANELS = [
		[ 'usage_restriction_coupon_data', 'usage_restriction_options' ],
		[ 'usage_limit_coupon_data', 'usage_limit_options' ],
	];

	function hasFields( node ) {
		return !! node.querySelector( 'input:not([type="hidden"]), select, textarea' );
	}

	function moveNativeFields() {
		Object.keys( SLOTS ).forEach( function ( slot ) {
			var target = document.querySelector( '[data-hwe-slot="' + slot + '"]' );
			if ( ! target ) {
				return;
			}
			SLOTS[ slot ].forEach( function ( selector ) {
				var row = rowOf( selector );
				if ( row ) {
					target.appendChild( row );
				}
			} );
		} );

		// Sección propia cuyo hueco quedó vacío (p. ej. Marcas sin la integración).
		document.querySelectorAll( '[data-hwe-section]' ).forEach( function ( section ) {
			var slot = section.querySelector( '[data-hwe-slot]' );
			if ( slot && ! hasFields( slot ) ) {
				toggle( section, false );
			}
		} );

		NATIVE_PANELS.forEach( function ( pair ) {
			var panel = document.getElementById( pair[ 0 ] );
			var tab = document.querySelector( '.coupon_data_tabs li.' + pair[ 1 ] );
			if ( ! panel ) {
				return;
			}
			// Cada grupo nativo que se quedó sin campos se retira entero, con su
			// separador «Y»: si no, la pestaña enseña una pila de «Y» sin nada.
			panel.querySelectorAll( '.options_group' ).forEach( function ( group ) {
				if ( ! hasFields( group ) ) {
					toggle( group, false );
				}
			} );
			// Y la pestaña, si no le queda nada. Si otro plugin añadió campos en
			// ella, se queda (solo con lo suyo).
			if ( tab && ! hasFields( panel ) ) {
				toggle( tab, false );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Lectura del formulario                                              */
	/* ------------------------------------------------------------------ */

	function radio( name ) {
		var checked = document.querySelector( 'input[name="' + name + '"]:checked' );
		return checked ? checked.value : '';
	}

	function val( selector ) {
		var el = document.querySelector( selector );
		return el ? String( el.value || '' ).trim() : '';
	}

	function checked( selector ) {
		var el = document.querySelector( selector );
		return !! ( el && el.checked );
	}

	/** Textos de las opciones elegidas de un select múltiple (select2 incluido). */
	function selectedLabels( selector ) {
		var el = document.querySelector( selector );
		if ( ! el ) {
			return [];
		}
		return Array.prototype.filter
			.call( el.options, function ( o ) {
				return o.selected;
			} )
			.map( function ( o ) {
				// Los productos llegan como «Nombre (#123)» o «SKU – Nombre»: se quita el id.
				return o.textContent.replace( /\s*\(#\d+\)\s*$/, '' ).trim();
			} );
	}

	function num( value ) {
		var n = parseFloat( String( value ).replace( /\s/g, '' ).replace( ',', '.' ) );
		return isFinite( n ) ? n : 0;
	}

	function money( value ) {
		var formatted = new Intl.NumberFormat( 'es-CO', { maximumFractionDigits: cfg.decimals } ).format( num( value ) );
		return cfg.currency + formatted;
	}

	/** AAAA-MM-DD → DD/MM/AAAA (sin pasar por `Date`, que la leería en UTC). */
	function dmy( iso ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( iso );
		return m ? m[ 3 ] + '/' + m[ 2 ] + '/' + m[ 1 ] : iso;
	}

	function isDate( value ) {
		return /^\d{4}-\d{2}-\d{2}$/.test( value );
	}

	/** «A, B y 3 más». */
	function listText( items, max ) {
		max = max || 3;
		if ( items.length <= max ) {
			return items.join( ', ' );
		}
		return items.slice( 0, max ).join( ', ' ) + ' y ' + ( items.length - max ) + ' más';
	}

	function readState() {
		return {
			code: val( '#title' ),
			status: val( '#original_post_status' ),
			type: val( '#discount_type' ),
			typeLabel: ( function () {
				var el = document.getElementById( 'discount_type' );
				return el && el.selectedOptions[ 0 ] ? el.selectedOptions[ 0 ].textContent.trim() : '';
			} )(),
			amount: val( '#coupon_amount' ),
			freeShipping: checked( '#free_shipping' ),
			scope: radio( 'hwe_coupon_scope' ) || 'all',
			products: selectedLabels( 'select[name="product_ids[]"]' ),
			categories: selectedLabels( '#product_categories' ),
			exProducts: selectedLabels( 'select[name="exclude_product_ids[]"]' ),
			exCategories: selectedLabels( '#exclude_product_categories' ),
			exSale: checked( '#exclude_sale_items' ),
			brands: selectedLabels( '#product_brands' ),
			exBrands: selectedLabels( '#exclude_product_brands' ),
			audience: radio( 'hwe_coupon_audience' ) || 'anyone',
			firstSince: val( '#_hwe_first_order_since' ),
			emails: val( '#customer_email' ),
			perUser: val( '#usage_limit_per_user' ),
			starts: val( '#_hwe_coupon_starts' ),
			expires: val( '#expiry_date' ),
			isPublic: radio( '_hwe_coupon_public' ) === 'yes',
			minAmount: val( '#minimum_amount' ),
			maxAmount: val( '#maximum_amount' ),
			individual: checked( '#individual_use' ),
			usageLimit: val( '#usage_limit' ),
			maxItems: val( '#limit_usage_to_x_items' ),
		};
	}

	/* ------------------------------------------------------------------ */
	/* Dependencias y avisos                                               */
	/* ------------------------------------------------------------------ */

	function toggle( el, visible ) {
		if ( el ) {
			el.hidden = ! visible;
			el.style.display = visible ? '' : 'none';
		}
	}

	function syncFields( s ) {
		toggle( rowOf( 'select[name="product_ids[]"]' ), s.scope === 'products' );
		toggle( rowOf( '#product_categories' ), s.scope === 'categories' );

		document.querySelectorAll( '[data-hwe-when="audience-first"]' ).forEach( function ( el ) {
			toggle( el, s.audience === 'first' );
		} );
		document.querySelectorAll( '[data-hwe-when="public"]' ).forEach( function ( el ) {
			toggle( el, s.isPublic );
		} );

		var scopeEmpty =
			( s.scope === 'products' && s.products.length === 0 ) ||
			( s.scope === 'categories' && s.categories.length === 0 );

		var warnings = {
			'scope-empty': scopeEmpty,
			'first-since-empty': s.audience === 'first' && ! isDate( s.firstSince ),
			'dates-reversed': isDate( s.starts ) && isDate( s.expires ) && s.starts > s.expires,
			'public-products-hidden': s.isPublic && s.scope === 'products',
		};
		Object.keys( warnings ).forEach( function ( key ) {
			document.querySelectorAll( '[data-hwe-warning="' + key + '"]' ).forEach( function ( el ) {
				toggle( el, warnings[ key ] );
			} );
		} );

		return warnings;
	}

	/* ------------------------------------------------------------------ */
	/* Resumen                                                             */
	/* ------------------------------------------------------------------ */

	function statusOf( s ) {
		var today = cfg.today;
		if ( s.status && s.status !== 'publish' && s.status !== 'future' ) {
			return { tone: 'draft', text: 'Borrador: no funciona hasta publicarlo' };
		}
		if ( today && isDate( s.expires ) && s.expires < today ) {
			return { tone: 'off', text: 'Caducado el ' + dmy( s.expires ) };
		}
		if ( today && isDate( s.starts ) && s.starts > today ) {
			return { tone: 'soon', text: 'Programado: empieza el ' + dmy( s.starts ) };
		}
		return { tone: 'on', text: 'Activo' };
	}

	function discountText( s ) {
		var amount = num( s.amount );
		var parts = [];
		if ( amount > 0 ) {
			if ( s.type === 'percent' ) {
				parts.push( amount.toLocaleString( 'es-CO' ) + ' % de descuento' );
			} else if ( s.type === 'fixed_cart' ) {
				parts.push( money( amount ) + ' de descuento sobre el carrito' );
			} else if ( s.type === 'fixed_product' ) {
				parts.push( money( amount ) + ' de descuento por cada producto' );
			} else {
				parts.push( s.typeLabel + ': ' + s.amount );
			}
		}
		if ( s.freeShipping ) {
			parts.push( 'envío gratis' );
		}
		return parts.length ? parts.join( ' + ' ) : '';
	}

	function scopeText( s ) {
		var base = scopeBaseText( s );
		return s.brands.length ? base + '; solo de la marca ' + listText( s.brands ) : base;
	}

	function scopeBaseText( s ) {
		if ( s.scope === 'products' && s.products.length ) {
			return ( s.products.length === 1 ? 'El producto ' : 'Los productos ' ) + listText( s.products );
		}
		if ( s.scope === 'categories' && s.categories.length ) {
			return ( s.categories.length === 1 ? 'La categoría ' : 'Las categorías ' ) + listText( s.categories ) + ', con sus subcategorías';
		}
		return 'Todo el catálogo';
	}

	function exclusionsText( s ) {
		var parts = [];
		if ( s.exProducts.length ) {
			parts.push( listText( s.exProducts ) );
		}
		if ( s.exCategories.length ) {
			parts.push( 'la categoría ' + listText( s.exCategories ) );
		}
		if ( s.exBrands.length ) {
			parts.push( 'la marca ' + listText( s.exBrands ) );
		}
		if ( s.exSale ) {
			parts.push( 'los productos rebajados' );
		}
		return parts.length ? 'Excepto ' + parts.join( '; ' ) : '';
	}

	function audienceText( s ) {
		var base;
		if ( s.audience === 'first' ) {
			base = 'Solo en la primera compra, con sesión iniciada';
			base += isDate( s.firstSince ) ? ' (cuentan los pedidos desde el ' + dmy( s.firstSince ) + ')' : '';
		} else if ( s.audience === 'login' ) {
			base = 'Clientes con sesión iniciada';
		} else {
			base = 'Cualquier cliente, también invitados';
		}
		var extra = [];
		if ( s.emails ) {
			extra.push( 'solo los correos indicados' );
		}
		if ( num( s.perUser ) > 0 ) {
			extra.push( num( s.perUser ) === 1 ? 'una vez por cliente' : num( s.perUser ) + ' veces por cliente' );
		}
		return extra.length ? base + '; ' + extra.join( ', ' ) : base;
	}

	function datesText( s ) {
		var from = isDate( s.starts );
		var to = isDate( s.expires );
		if ( from && to ) {
			return 'Del ' + dmy( s.starts ) + ' al ' + dmy( s.expires ) + ', ambos incluidos';
		}
		if ( from ) {
			return 'Desde el ' + dmy( s.starts ) + ', sin fecha de fin';
		}
		if ( to ) {
			return 'Hasta el ' + dmy( s.expires ) + ', incluido';
		}
		return 'Sin fechas: funciona siempre';
	}

	function limitsText( s ) {
		var parts = [];
		if ( num( s.minAmount ) > 0 ) {
			parts.push( 'compra mínima de ' + money( s.minAmount ) );
		}
		if ( num( s.maxAmount ) > 0 ) {
			parts.push( 'compra máxima de ' + money( s.maxAmount ) );
		}
		if ( s.individual ) {
			parts.push( 'no se combina con otros cupones' );
		}
		if ( num( s.usageLimit ) > 0 ) {
			parts.push( num( s.usageLimit ) + ' usos en total' );
		}
		if ( num( s.maxItems ) > 0 && s.type !== 'fixed_cart' ) {
			parts.push( 'hasta ' + num( s.maxItems ) + ' artículos por pedido' );
		}
		if ( ! parts.length ) {
			return 'Sin límites';
		}
		var text = parts.join( '; ' );
		return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function renderSummary( s, warnings ) {
		var body = document.getElementById( 'hwe-coupon-summary-body' );
		if ( ! body ) {
			return;
		}

		var frag = document.createDocumentFragment();
		var status = statusOf( s );

		var head = el( 'div', 'hwe-summary-head' );
		head.appendChild( el( 'code', 'hwe-summary-code', s.code || 'Sin código' ) );
		head.appendChild( el( 'span', 'hwe-summary-status is-' + status.tone, status.text ) );
		frag.appendChild( head );

		var discount = discountText( s );
		var rows = [
			[ 'Descuento', discount || 'Falta el importe', ! discount ],
			[ 'Aplica a', scopeText( s ) ],
		];
		var exclusions = exclusionsText( s );
		if ( exclusions ) {
			rows.push( [ '', exclusions ] );
		}
		rows.push(
			[ 'Quién', audienceText( s ) ],
			[ 'Cuándo', datesText( s ) ],
			[ 'Visibilidad', s.isPublic ? 'Público: se anuncia en la web' : 'Privado: solo con el código' ],
			[ 'Límites', limitsText( s ) ]
		);

		var dl = el( 'dl', 'hwe-summary-list' );
		rows.forEach( function ( row ) {
			if ( row[ 0 ] ) {
				dl.appendChild( el( 'dt', '', row[ 0 ] ) );
			}
			dl.appendChild( el( 'dd', row[ 2 ] ? 'is-missing' : '', row[ 1 ] ) );
		} );
		frag.appendChild( dl );

		var issues = [];
		if ( warnings[ 'dates-reversed' ] ) {
			issues.push( 'Las fechas están al revés: nunca funcionaría.' );
		}
		if ( warnings[ 'scope-empty' ] ) {
			issues.push( 'Elegiste una lista de productos o categorías vacía: aplica a todo.' );
		}
		if ( warnings[ 'first-since-empty' ] ) {
			issues.push( 'Falta «Contar pedidos desde» (pestaña Clientes).' );
		}
		if ( issues.length ) {
			var box = el( 'ul', 'hwe-summary-issues' );
			issues.forEach( function ( text ) {
				box.appendChild( el( 'li', '', text ) );
			} );
			frag.appendChild( box );
		}

		body.replaceChildren( frag );
	}

	/* ------------------------------------------------------------------ */
	/* Arranque                                                            */
	/* ------------------------------------------------------------------ */

	function refresh() {
		var state = readState();
		renderSummary( state, syncFields( state ) );
	}

	$( function () {
		moveNativeFields();
		refresh();
		// Un solo refresco por evento, sea del campo que sea: el resumen lee todo el
		// formulario y es barato. `input` cubre la escritura; `change`, select2 y el datepicker.
		$( '#post' ).on( 'change input', refresh );
		// El botón «Generar código» del core rellena el título sin disparar eventos.
		$( document ).on( 'click', '.generate-coupon-code', function () {
			window.setTimeout( refresh, 0 );
		} );
	} );
} )( jQuery );
