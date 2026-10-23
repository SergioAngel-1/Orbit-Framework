/**
 * «Probar conexión» del Control Center: llama a la ruta de wp-admin que hace una petición real al
 * proveedor de mapas configurado (sin caché ni respaldo) y enseña el resultado y la pista.
 *
 * Sin JS el panel sigue guardando igual: el control no tiene `name`, así que no viaja con el
 * formulario. El estado se anuncia por `aria-live`; `wp.apiFetch` ya pone la raíz y el nonce.
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-hwe-maps-test]');
	if (!root) {
		return;
	}

	var button = root.querySelector('[data-hwe-maps-test-button]');
	var address = root.querySelector('[data-hwe-maps-test-address]');
	var result = root.querySelector('[data-hwe-maps-test-result]');
	if (!button || !address || !result) {
		return;
	}

	function show(message, isError) {
		result.textContent = message;
		result.classList.toggle('hwe-maps-test-result--error', !!isError);
		result.classList.toggle('hwe-maps-test-result--ok', !!message && !isError);
	}

	function finish() {
		button.disabled = false;
		button.removeAttribute('aria-busy');
	}

	address.addEventListener('input', function () {
		show('', false);
	});

	button.addEventListener('click', function () {
		var query = address.value.trim();
		if (query === '') {
			show('Escribe una dirección de prueba.', true);
			address.focus();
			return;
		}
		if (!window.wp || !window.wp.apiFetch) {
			show('No se pudo cargar el cliente de la API de WordPress; recarga la página.', true);
			return;
		}

		button.disabled = true;
		button.setAttribute('aria-busy', 'true');
		show('Probando la conexión con el proveedor de mapas…', false);

		window.wp.apiFetch({
			path: '/hwe-shipping/v1/admin/maps-test',
			method: 'POST',
			data: { address: query }
		}).then(function (body) {
			var message = body && body.message ? body.message : 'No se pudo comprobar la conexión.';
			if (body && body.hint) {
				message += ' ' + body.hint;
			}
			show(message, !body || !body.ok);
			finish();
		}, function (error) {
			show(error && error.message ? error.message : 'No se pudo comprobar la conexión.', true);
			finish();
		});
	});
})();
