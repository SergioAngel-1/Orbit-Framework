/**
 * Editor de ciudad de HWE Shipping: repetidor de opciones de entrega (añadir, quitar, subir, bajar)
 * y visibilidad de los campos según el tipo de tarifa. Sin dependencias y sin JS inline: el HTML
 * ya trae todos los campos, así que sin este script el guardado funciona igual.
 */
(function () {
	'use strict';

	var root = document.getElementById('hwe-shipping-city');
	if (!root) {
		return;
	}

	var typeSelect = document.getElementById('hwe_shipping_city_type');
	var list = document.getElementById('hwe-shipping-option-list');
	var addButton = document.getElementById('hwe-shipping-option-add');
	var template = document.getElementById('hwe-shipping-option-template');

	/**
	 * «Validar»: geocodifica la dirección de referencia con la ruta del panel y rellena latitud,
	 * longitud y la dirección resuelta. Latitud y longitud quedan editables para corregirlas a
	 * mano; el resultado se anuncia en el estado `aria-live` que hay junto al botón.
	 */
	(function setupValidate() {
		var button = document.getElementById('hwe_shipping_city_validate');
		var address = document.getElementById('hwe_shipping_city_origin_address');
		var lat = document.getElementById('hwe_shipping_city_origin_lat');
		var lng = document.getElementById('hwe_shipping_city_origin_lng');
		var label = document.getElementById('hwe_shipping_city_origin_label');
		var status = document.getElementById('hwe_shipping_city_validate_status');
		if (!button || !address || !lat || !lng || !label || !status) {
			return;
		}

		function setStatus(message, isError) {
			status.textContent = message;
			status.classList.toggle('hwe-shipping-validate-status--error', !!isError);
		}

		function sync() {
			button.disabled = address.value.trim() === '';
		}

		function providerName(provider) {
			if (provider === 'google_maps') {
				return 'Google';
			}
			if (provider === 'openrouteservice') {
				return 'OpenRouteService';
			}
			if (provider === 'nominatim') {
				return 'Nominatim (respaldo)';
			}
			return 'el proveedor de mapas';
		}

		function finish() {
			button.removeAttribute('aria-busy');
			sync();
		}

		address.addEventListener('input', function () {
			setStatus('', false);
			sync();
		});

		button.addEventListener('click', function () {
			var query = address.value.trim();
			if (query === '') {
				return;
			}
			if (!window.wp || !window.wp.apiFetch) {
				setStatus('No se pudo cargar el cliente de la API de WordPress; recarga la página.', true);
				return;
			}

			button.disabled = true;
			button.setAttribute('aria-busy', 'true');
			setStatus('Buscando la dirección…', false);

			window.wp.apiFetch({
				path: '/hwe-shipping/v1/admin/geocode',
				method: 'POST',
				data: { address: query }
			}).then(function (result) {
				lat.value = String(result.lat);
				lng.value = String(result.lng);
				label.value = result.label || '';
				setStatus('Dirección validada con ' + providerName(result.provider) + '. Revisa la latitud y la longitud: puedes corregirlas a mano.', false);
				finish();
			}, function (error) {
				setStatus(error && error.message ? error.message : 'No se pudo validar la dirección.', true);
				finish();
			});
		});

		sync();
	})();

	if (!list || !addButton || !template) {
		return;
	}
	var optionLabel = list.getAttribute('data-option-label') || 'Opción';

	function currentType() {
		return typeSelect ? typeSelect.value : '';
	}

	/** Muestra solo los campos del tipo elegido (fixed | distance). */
	function applyType() {
		var type = currentType();
		root.querySelectorAll('[data-hwe-for]').forEach(function (element) {
			element.hidden = element.getAttribute('data-hwe-for') !== type;
		});
	}

	function rows() {
		return Array.prototype.slice.call(list.querySelectorAll('[data-hwe-option]'));
	}

	/** Renumera los títulos «Opción N» según el orden actual. Los `name` no cambian: el orden del formulario ya es el de las filas. */
	function renumber() {
		rows().forEach(function (row, index) {
			var legend = row.querySelector('legend');
			if (legend) {
				legend.textContent = optionLabel + ' ' + (index + 1);
			}
		});
	}

	function addRow() {
		var index = Number(list.getAttribute('data-next-index') || 0);
		list.setAttribute('data-next-index', String(index + 1));

		var holder = document.createElement('div');
		holder.innerHTML = template.innerHTML.replace(/__INDEX__/g, String(index));
		var row = holder.querySelector('[data-hwe-option]');
		if (!row) {
			return;
		}
		list.appendChild(row);
		renumber();
		applyType();

		var nameInput = row.querySelector('input[type="text"]');
		if (nameInput) {
			nameInput.focus();
		}
	}

	/** Quitar, subir y bajar. El foco vuelve a un control de la lista para no perderse con el teclado. */
	function onListClick(event) {
		var button = event.target.closest('[data-hwe-action]');
		if (!button || !list.contains(button)) {
			return;
		}
		var row = button.closest('[data-hwe-option]');
		var action = button.getAttribute('data-hwe-action');

		if (action === 'remove') {
			var neighbour = row.nextElementSibling || row.previousElementSibling;
			var focusTarget = neighbour ? neighbour.querySelector('[data-hwe-action="remove"]') : addButton;
			row.remove();
			renumber();
			if (focusTarget) {
				focusTarget.focus();
			}
		} else if (action === 'up' && row.previousElementSibling) {
			list.insertBefore(row, row.previousElementSibling);
			renumber();
			button.focus();
		} else if (action === 'down' && row.nextElementSibling) {
			list.insertBefore(row.nextElementSibling, row);
			renumber();
			button.focus();
		}
	}

	if (typeSelect) {
		typeSelect.addEventListener('change', applyType);
	}
	addButton.addEventListener('click', addRow);
	list.addEventListener('click', onListClick);

	applyType();
	renumber();
})();
