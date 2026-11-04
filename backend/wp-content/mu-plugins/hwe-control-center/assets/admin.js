/**
 * HWE Control Center — Admin JS
 *
 * Navegación por pestañas, bloques plegables, color picker, selectores de medios, editores de
 * filas, huecos opcionales, pasarela activa, estado del guardado y vista previa del correo.
 */
(function () {
    'use strict';

    // =========================================================================
    // Vista: lo que depende del estado de los campos (resúmenes, etiqueta de «abrir todo»…).
    // Cada módulo registra su función con `onView`; `refreshView` las ejecuta tras cualquier
    // cambio (un campo, otra pestaña, un hueco).
    // =========================================================================

    var viewHooks = [];

    function onView(fn) { viewHooks.push(fn); }

    function refreshView() { viewHooks.forEach(function (fn) { fn(); }); }

    function activePanel() { return document.querySelector('[data-hwe-panel]:not([hidden])'); }

    // =========================================================================
    // Navegación lateral: un enlace por pestaña, un panel por enlace.
    // =========================================================================

    function initTabs() {
        var nav = document.querySelector('.hwe-nav');
        var links = document.querySelectorAll('[data-hwe-tab]');
        var panels = document.querySelectorAll('[data-hwe-panel]');
        if (!nav || !links.length || !panels.length) return;

        var toggle = nav.querySelector('.hwe-nav__toggle');
        var current = nav.querySelector('[data-hwe-nav-current]');
        var wrap = document.querySelector('.hwe-wrap');
        var form = document.querySelector('[data-hwe-form]');
        var baseAction = form ? form.getAttribute('action').split('#')[0] : '';

        function exists(tab) {
            return !!tab && Array.prototype.some.call(panels, function (p) { return p.dataset.hwePanel === tab; });
        }

        // La pestaña va en la URL (#tab) y en la acción del formulario: sobrevive al guardado.
        function remember(tab) {
            try { history.replaceState(null, '', '#' + tab); } catch (e) {}
            if (form) form.setAttribute('action', baseAction + '#' + tab);
        }

        function show(tab) {
            var link = null;
            links.forEach(function (l) {
                var on = l.dataset.hweTab === tab;
                if (on) { l.setAttribute('aria-current', 'true'); link = l; } else { l.removeAttribute('aria-current'); }
            });
            panels.forEach(function (p) { p.hidden = p.dataset.hwePanel !== tab; });
            if (current && link) current.textContent = link.querySelector('.hwe-nav__label').textContent;
            refreshView();
        }

        function setOpen(open) {
            nav.classList.toggle('is-open', open);
            if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        links.forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                show(link.dataset.hweTab);
                remember(link.dataset.hweTab);
                setOpen(false);
            });
        });
        if (toggle) {
            toggle.addEventListener('click', function () {
                setOpen(!nav.classList.contains('is-open'));
            });
        }

        // Atrás/adelante o un #tab pegado a mano.
        window.addEventListener('hashchange', function () {
            var tab = window.location.hash.slice(1);
            if (exists(tab)) {
                show(tab);
                if (form) form.setAttribute('action', baseAction + '#' + tab);
            }
        });

        // Al cargar manda la URL; si no trae pestaña, la que eligió el servidor (p. ej. tras una prueba de correo).
        var fromUrl = window.location.hash.slice(1);
        var start = exists(fromUrl) ? fromUrl : (wrap && wrap.dataset.hweInitialTab) || '';
        if (exists(start)) {
            show(start);
            if (form) form.setAttribute('action', baseAction + '#' + start);
        }
    }

    // =========================================================================
    // Un logo que no carga no deja un hueco roto en la cabecera.
    // =========================================================================

    function initHeaderLogo() {
        var logo = document.querySelector('.hwe-header__logo');
        if (!logo) return;
        logo.addEventListener('error', function () { logo.hidden = true; });
        if (logo.complete && logo.naturalWidth === 0) logo.hidden = true;
    }

    // =========================================================================
    // Color Picker — sync color input, text input, and preview swatch
    // =========================================================================

    function initColorPickers() {
        document.querySelectorAll('.hwe-color-picker').forEach(function (wrapper) {
            var colorInput = wrapper.querySelector('input[type="color"]');
            var textInput = wrapper.querySelector('input.small-text');
            var preview = wrapper.querySelector('.hwe-color-preview');
            if (!colorInput) return;

            var id = colorInput.id;

            // Sync color → text + preview.
            colorInput.addEventListener('input', function () {
                if (textInput) textInput.value = this.value;
                if (preview) preview.style.background = this.value;
            });

            // Sync text → color + preview.
            if (textInput) {
                textInput.addEventListener('input', function () {
                    if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                        colorInput.value = this.value;
                        if (preview) preview.style.background = this.value;
                    }
                });
            }

            // Initialize preview from current value.
            if (preview) {
                preview.style.background = colorInput.value;
            }
        });
    }

    // =========================================================================
    // Cambios sin guardar: la barra de guardado dice en qué pestañas hay, la nav las marca
    // con un punto y salir de la página con cambios pide confirmación.
    //
    // Cada control del formulario principal se compara con su valor al cargar (volver al
    // valor original limpia la marca). Los controles de otros formularios (`form="…"`: prueba
    // de correo, copias) no cuentan: no se guardan con este botón.
    // =========================================================================

    var submitting = false;

    function initSaveBar() {
        var form = document.querySelector('[data-hwe-form]');
        var saveBtn = document.querySelector('.hwe-savebar__btn');
        var bar = document.querySelector('.hwe-savebar');
        var status = document.querySelector('[data-hwe-status]');
        if (!form || !saveBtn || !bar || !status) return;

        function signature(el) {
            return el.type === 'checkbox' || el.type === 'radio' ? (el.checked ? '1' : '0') : el.value;
        }

        function tracked() {
            return Array.prototype.filter.call(form.elements, function (el) {
                return el.name && el.form === form && el.type !== 'submit' && el.type !== 'button' && el.type !== 'file';
            });
        }

        var initial = new Map();
        tracked().forEach(function (el) { initial.set(el, signature(el)); });

        // Pestañas y bloques plegables con algún control cambiado: un bloque cerrado sigue delatando lo que guarda dentro.
        var folds = [];

        function dirty() {
            var tabs = [];
            var blocks = [];
            tracked().forEach(function (el) {
                if (initial.has(el) && initial.get(el) === signature(el)) return;
                var panel = el.closest('[data-hwe-panel]');
                var tab = panel ? panel.dataset.hwePanel : '';
                if (tab && tabs.indexOf(tab) === -1) tabs.push(tab);
                for (var d = el.closest('details[data-hwe-fold]'); d; d = d.parentElement && d.parentElement.closest('details[data-hwe-fold]')) {
                    if (blocks.indexOf(d) === -1) blocks.push(d);
                }
            });
            return { tabs: tabs, blocks: blocks };
        }

        function markFolds(blocks) {
            folds.forEach(function (d) {
                if (blocks.indexOf(d) !== -1) return;
                var mark = d.firstElementChild && d.firstElementChild.querySelector('[data-hwe-fold-dirty]');
                if (mark) mark.hidden = true;
            });
            blocks.forEach(function (d) {
                var mark = d.firstElementChild && d.firstElementChild.querySelector('[data-hwe-fold-dirty]');
                if (mark) mark.hidden = false;
            });
            folds = blocks;
        }

        function refresh() {
            if (submitting) return;
            var changes = dirty();
            var tabs = changes.tabs;
            markFolds(changes.blocks);
            var labels = [];
            document.querySelectorAll('[data-hwe-tab]').forEach(function (link) {
                var on = tabs.indexOf(link.dataset.hweTab) !== -1;
                var mark = link.querySelector('[data-hwe-dirty-mark]');
                if (mark) mark.hidden = !on;
                if (on) labels.push(link.querySelector('.hwe-nav__label').textContent);
            });
            // Con muchas pestañas tocadas la barra no crece: se nombran tres y se cuenta el resto.
            var shown = labels.length > 3
                ? (status.dataset.more || '%s y %d más').replace('%s', labels.slice(0, 3).join(', ')).replace('%d', String(labels.length - 3))
                : labels.join(', ');
            bar.classList.toggle('is-dirty', tabs.length > 0);
            status.textContent = tabs.length
                ? (status.dataset.dirty || '').replace('%s', shown)
                : (status.dataset.clean || '');
        }

        // Tras el evento: los manejadores de cada tipo de campo (filas, colores, huecos) ya han actualizado su valor.
        function schedule() { setTimeout(refresh, 0); }
        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);

        form.addEventListener('submit', function () {
            submitting = true;
            saveBtn.classList.add('is-busy');
            saveBtn.disabled = true;
            status.textContent = status.dataset.saving || '';
        });

        window.addEventListener('beforeunload', function (e) {
            if (submitting || !bar.classList.contains('is-dirty')) return;
            e.preventDefault();
            e.returnValue = '';
        });
    }

    // Avisa al formulario de un cambio hecho por código (medioteca, huecos).
    function announce(field) {
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // =========================================================================
    // Payment provider toggle
    // =========================================================================

    function initProviderToggle() {
        var providerSelect = document.querySelector(
            '#hwe_config_payments_provider'
        );
        if (!providerSelect) return;

        // Con «Activar pago en línea» apagado sobran la pasarela y sus credenciales.
        var gateway = document.querySelector('#hwe_config_payments_gateway_enabled');
        var providerRow = providerSelect.closest('.hwe-field');
        var groups = document.querySelectorAll('.hwe-subgroup[data-provider]');
        var credentials = document.querySelector('.hwe-section--provider');

        function toggleGroups() {
            var online = !gateway || gateway.checked;
            var selected = providerSelect.value;
            if (providerRow) providerRow.hidden = !online;
            if (credentials) credentials.hidden = !online;
            groups.forEach(function (group) {
                group.classList.toggle('is-active', online && group.dataset.provider === selected);
            });
        }

        providerSelect.addEventListener('change', toggleGroups);
        if (gateway) gateway.addEventListener('change', toggleGroups);
        toggleGroups();
    }

    // =========================================================================
    // Huecos opcionales (grupos con `slots`, p. ej. Legal → Sedes)
    //
    // Se ocultan los huecos sin datos y «añadir» muestra el primero libre.
    // «Quitar» vacía sus campos y lo oculta: al guardar, el hueco queda vacío
    // y el frontend lo ignora. Sin JS se ven todos, como cualquier grupo.
    // =========================================================================

    function slotFields(slot) {
        return slot.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]):not([type="button"]), textarea, select');
    }

    function slotHasData(slot) {
        return Array.prototype.some.call(slotFields(slot), function (field) {
            return field.value.trim() !== '';
        });
    }

    function initSlots() {
        document.querySelectorAll('[data-hwe-slots]').forEach(function (box) {
            var slots = Array.prototype.slice.call(box.querySelectorAll('[data-hwe-slot]'));
            var addBtn = box.querySelector('[data-hwe-slots-add]');
            var empty = box.querySelector('[data-hwe-slots-empty]');
            if (!slots.length || !addBtn) return;

            function sync() {
                var free = slots.filter(function (slot) { return slot.hidden; });
                addBtn.hidden = false;
                addBtn.disabled = free.length === 0;
                if (empty) empty.hidden = free.length !== slots.length;
            }

            slots.forEach(function (slot) {
                slot.hidden = !slotHasData(slot);
                var remove = slot.querySelector('[data-hwe-slot-remove]');
                if (!remove) return;
                remove.hidden = false;
                remove.addEventListener('click', function () {
                    slotFields(slot).forEach(function (field) { field.value = ''; announce(field); });
                    slot.hidden = true;
                    sync();
                    addBtn.focus();
                });
            });

            addBtn.addEventListener('click', function () {
                var next = slots.filter(function (slot) { return slot.hidden; })[0];
                if (!next) return;
                next.hidden = false;
                sync();
                var first = slotFields(next)[0];
                if (first) first.focus();
            });

            sync();
        });
    }

    // =========================================================================
    // Los avisos de éxito se retiran solos; los de error y advertencia se quedan hasta leerlos.
    // =========================================================================

    function initNotices() {
        document.querySelectorAll('.hwe-wrap .notice-success.is-dismissible').forEach(function (notice) {
            setTimeout(function () { notice.remove(); }, 6000);
        });
    }

    // =========================================================================
    // Media library picker (tipos `image` / `file`)
    // =========================================================================

    function initMediaPickers() {
        if (typeof wp === 'undefined' || !wp.media) return;

        document.querySelectorAll('.hwe-media').forEach(function (row) {
            if (row.dataset.hweMediaBound) return;
            row.dataset.hweMediaBound = '1';

            var pick = row.querySelector('.hwe-media-pick');
            var clear = row.querySelector('.hwe-media-clear');
            var input = row.querySelector('.hwe-media-url');
            var preview = row.querySelector('.hwe-media-preview');
            if (!pick || !input) return;

            // `data-accept` (extensiones separadas por coma) marca los campos que NO son
            // de imagen (p. ej. un PDF): la medioteca filtrada por `type: image` no los
            // listaría. Sin él, el comportamiento es el de imagen de siempre.
            var accept = (row.dataset.accept || '')
                .split(',')
                .map(function (ext) { return ext.trim().toLowerCase(); })
                .filter(Boolean);

            pick.addEventListener('click', function (e) {
                e.preventDefault();
                var frame = wp.media({
                    title: accept.length ? 'Seleccionar archivo' : 'Seleccionar imagen',
                    multiple: false,
                    library: accept.length ? {} : { type: 'image' },
                });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    var url = att.url || '';
                    if (accept.length) {
                        var ext = (url.split('?')[0].split('.').pop() || '').toLowerCase();
                        if (accept.indexOf(ext) === -1) {
                            window.alert('Elige un archivo ' + accept.map(function (e2) { return '.' + e2; }).join(' o '));
                            return;
                        }
                    }
                    // Persistir relativa a `/wp-content/uploads/` para no atar la BD a localhost.
                    if (url.indexOf('wp-content/uploads/') !== -1) {
                        var idx = url.indexOf('wp-content/uploads/');
                        url = '/' + url.slice(idx);
                    }
                    input.value = url;
                    announce(input);
                    if (preview) {
                        preview.replaceChildren();
                        if (url) {
                            if (accept.length) {
                                // Un archivo que no es imagen en un <img> se vería roto:
                                // se enseña el nombre del archivo.
                                var code = document.createElement('code');
                                code.textContent = url.split('/').pop();
                                preview.appendChild(code);
                            } else {
                                var img = document.createElement('img');
                                img.src = url;
                                img.alt = '';
                                preview.appendChild(img);
                            }
                        }
                    }
                });
                frame.open();
            });

            if (clear) {
                clear.addEventListener('click', function (e) {
                    e.preventDefault();
                    input.value = '';
                    announce(input);
                    if (preview) preview.replaceChildren();
                });
            }
        });
    }

    // =========================================================================
    // Editores de filas (tipos `lines` / `pairs`)
    //
    // El valor real es el textarea `.hwe-rows-editor-value` (con `name`): una
    // entrada por línea, y en `pairs` la forma `valor | etiqueta`. Aquí solo
    // se reescribe tras cada cambio de las filas; con JS el textarea se oculta
    // (clase `is-enhanced`), sin JS se edita a mano como siempre.
    // =========================================================================

    function initRowsEditors() {
        document.querySelectorAll('.hwe-rows-editor').forEach(function (box) {
            var list = box.querySelector('.hwe-rows-editor-rows');
            var tpl = box.querySelector('.hwe-rows-editor-tpl');
            var addBtn = box.querySelector('.hwe-rows-editor-add');
            var value = box.querySelector('.hwe-rows-editor-value');
            if (!list || !tpl || !addBtn || !value) return;

            var isPairs = box.dataset.kind === 'pairs';
            var max = parseInt(box.dataset.max || '0', 10) || 0;
            box.classList.add('is-enhanced');

            function serialize() {
                var lines = [];
                list.querySelectorAll('.hwe-rows-editor-row').forEach(function (row) {
                    var inputs = row.querySelectorAll('.hwe-rows-editor-input');
                    // Un `|` dentro del texto partiría la línea al leerla.
                    var first = (inputs[0] ? inputs[0].value : '').trim();
                    if (isPairs) first = first.replace(/\|/g, '/');
                    if (isPairs) {
                        var second = (inputs[1] ? inputs[1].value : '').replace(/\|/g, '/').trim();
                        if (first === '' && second === '') return;
                        lines.push(second !== '' ? first + ' | ' + second : first);
                    } else if (first !== '') {
                        lines.push(first);
                    }
                });
                value.value = lines.join('\n');
                syncAdd();
            }

            function syncAdd() {
                var count = list.querySelectorAll('.hwe-rows-editor-row').length;
                addBtn.disabled = max > 0 && count >= max;
            }

            addBtn.addEventListener('click', function () {
                var wrap = document.createElement('div');
                wrap.innerHTML = tpl.innerHTML.trim();
                var row = wrap.firstElementChild;
                if (!row) return;
                list.appendChild(row);
                syncAdd();
                var input = row.querySelector('.hwe-rows-editor-input');
                if (input) input.focus();
            });

            list.addEventListener('input', serialize);

            list.addEventListener('click', function (e) {
                var btn = e.target.closest('button');
                if (!btn) return;
                var row = btn.closest('.hwe-rows-editor-row');
                if (!row) return;
                if (btn.classList.contains('hwe-rows-editor-remove')) {
                    row.remove();
                } else if (btn.classList.contains('hwe-rows-editor-up') && row.previousElementSibling) {
                    list.insertBefore(row, row.previousElementSibling);
                } else if (btn.classList.contains('hwe-rows-editor-down') && row.nextElementSibling) {
                    list.insertBefore(row.nextElementSibling, row);
                } else {
                    return;
                }
                serialize();
            });

            // Enter en una fila abre la siguiente (en vez de enviar el form).
            list.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || !e.target.classList.contains('hwe-rows-editor-input')) return;
                e.preventDefault();
                if (!addBtn.disabled) addBtn.click();
            });

            syncAdd();
        });
    }

    // =========================================================================
    // Bloques plegables (`<details data-hwe-fold>`): secciones de cada pestaña y subgrupos.
    //
    // Sin JS el navegador ya los pliega. Aquí: un botón que abre o cierra todos los de la pestaña
    // activa (su etiqueta dice lo que hará), el estado de cada pestaña recordado en este
    // navegador, y la regla de que lo que tiene el foco o un error de validación nunca queda
    // escondido. Sin animación: es una superficie de uso diario.
    // =========================================================================

    var FOLD_STORE = 'hwe-cc-fold:';

    // Un bloque que no se ve (otro idioma, apagado, hueco vacío…) no cuenta para «abrir todo».
    function foldShown(d) {
        if (d.closest('[hidden], .is-off-lang, .is-off-cond, .is-lang-empty')) return false;
        return !(d.parentElement && d.parentElement.closest('details.is-off'));
    }

    function foldsOf(panel) {
        return Array.prototype.filter.call(panel.querySelectorAll('details[data-hwe-fold]'), foldShown);
    }

    // `reveal`: además de abrir, enseña lo que un bloque apagado esconde (solo para un control inválido).
    function openAncestors(el, reveal) {
        for (var d = el.closest('details[data-hwe-fold]'); d; d = d.parentElement && d.parentElement.closest('details[data-hwe-fold]')) {
            d.open = true;
            if (reveal) d.classList.remove('is-off');
        }
    }

    function initFold() {
        var form = document.querySelector('[data-hwe-form]');
        var btn = document.querySelector('[data-hwe-fold-all]');
        if (!form) return;

        function allOpen(folds) {
            return folds.length > 0 && folds.every(function (d) { return d.open; });
        }

        function label() {
            var panel = activePanel();
            if (!btn || !panel) return;
            var folds = foldsOf(panel);
            var everyOpen = allOpen(folds);
            btn.hidden = folds.length === 0;
            btn.textContent = everyOpen ? (btn.dataset.close || 'Colapsar todo') : (btn.dataset.open || 'Abrir todo');
            btn.dataset.state = everyOpen ? 'open' : 'closed';
        }

        // Estado por pestaña: una cadena 0/1 con el orden del DOM (si el panel cambia de forma, se descarta).
        function store(panel) {
            try {
                var folds = panel.querySelectorAll('details[data-hwe-fold]');
                var bits = Array.prototype.map.call(folds, function (d) { return d.open ? '1' : '0'; }).join('');
                window.localStorage.setItem(FOLD_STORE + panel.dataset.hwePanel, bits);
            } catch (e) {}
        }

        function restore(panel) {
            var bits = '';
            try { bits = window.localStorage.getItem(FOLD_STORE + panel.dataset.hwePanel) || ''; } catch (e) {}
            var folds = panel.querySelectorAll('details[data-hwe-fold]');
            if (bits.length !== folds.length) return;
            Array.prototype.forEach.call(folds, function (d, i) { d.open = bits.charAt(i) === '1'; });
        }

        document.querySelectorAll('[data-hwe-panel]').forEach(restore);

        if (btn) {
            btn.addEventListener('click', function () {
                var panel = activePanel();
                if (!panel) return;
                // El estado real al pulsar, no el de la última etiqueta: `toggle` llega con retraso.
                var folds = foldsOf(panel);
                var open = !allOpen(folds);
                folds.forEach(function (d) { d.open = open; });
                store(panel);
                label();
            });
        }

        // `toggle` no burbujea: se escucha en captura.
        form.addEventListener('toggle', function (e) {
            if (!e.target.matches || !e.target.matches('details[data-hwe-fold]')) return;
            var panel = e.target.closest('[data-hwe-panel]');
            if (panel) store(panel);
            label();
        }, true);

        // Lo que recibe el foco no se queda escondido; un control inválido tampoco (el navegador
        // no puede enseñar el aviso de uno dentro de un bloque cerrado y el envío se quedaría mudo).
        form.addEventListener('focusin', function (e) {
            // Pulsar una cabecera la enfoca: abrirla de nuevo impediría cerrarla.
            if (!e.target.matches('summary')) openAncestors(e.target);
        });
        form.addEventListener('invalid', function (e) {
            openAncestors(e.target, true);
            var row = e.target.closest('.hwe-field');
            if (row) row.classList.add('is-forced');
        }, true);

        onView(label);
    }

    // =========================================================================
    // Init on DOMContentLoaded
    // =========================================================================

    // =========================================================================
    // Vista previa de la prueba de email: pide el HTML final por AJAX
    // (hwe-email-test.php) y lo pinta en un <iframe sandbox> con `srcdoc`.
    // =========================================================================

    function initEmailPreview() {
        var trigger = document.querySelector('[data-hwe-email-preview]');
        var modal = document.querySelector('[data-hwe-email-preview-modal]');
        if (!trigger || !modal) return;
        document.body.appendChild(modal);

        var frame = modal.querySelector('[data-hwe-email-preview-frame]');
        var status = modal.querySelector('[data-hwe-email-preview-status]');
        var label = modal.querySelector('[data-hwe-email-preview-label]');
        var subject = modal.querySelector('[data-hwe-email-preview-subject]');
        var from = modal.querySelector('[data-hwe-email-preview-from]');
        var deviceBtns = modal.querySelectorAll('[data-hwe-email-preview-device]');
        var lastFocus = null;
        var request = 0;

        function setDevice(device) {
            modal.dataset.device = device;
            deviceBtns.forEach(function (b) {
                b.setAttribute('aria-pressed', b.dataset.hweEmailPreviewDevice === device ? 'true' : 'false');
            });
        }

        function close() {
            modal.hidden = true;
            document.body.classList.remove('hwe-email-preview-open');
            frame.srcdoc = '';
            if (lastFocus) lastFocus.focus();
        }

        function showStatus(text) {
            status.textContent = text;
            status.hidden = !text;
            frame.hidden = !!text;
        }

        function open() {
            var select = document.querySelector('[name="hwe_smtp_test_template"]');
            var real = document.querySelector('[name="hwe_smtp_test_real_order"]');
            var params = new URLSearchParams({
                action: 'hwe_email_preview',
                _ajax_nonce: trigger.dataset.nonce,
                template: select ? select.value : 'basic',
                real: real && real.checked ? '1' : ''
            });

            lastFocus = document.activeElement;
            label.textContent = select ? select.options[select.selectedIndex].text : '';
            subject.textContent = '…';
            from.textContent = '…';
            showStatus('Generando vista previa…');
            modal.hidden = false;
            document.body.classList.add('hwe-email-preview-open');
            modal.querySelector('.hwe-email-preview__close').focus();

            var current = ++request;
            fetch(trigger.dataset.url + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    if (current !== request) return;
                    if (!json || !json.success) {
                        throw new Error(json && json.data && json.data.message ? json.data.message : 'No se pudo generar la vista previa.');
                    }
                    label.textContent = json.data.label;
                    subject.textContent = json.data.subject;
                    from.textContent = json.data.from;
                    // Los enlaces se abren fuera del iframe (sandbox con allow-popups).
                    frame.srcdoc = json.data.html.replace(/<head([^>]*)>/i, '<head$1><base target="_blank">');
                    showStatus('');
                })
                .catch(function (err) {
                    if (current !== request) return;
                    showStatus(err.message || 'No se pudo generar la vista previa.');
                });
        }

        trigger.addEventListener('click', open);
        modal.querySelectorAll('[data-hwe-email-preview-close]').forEach(function (el) {
            el.addEventListener('click', close);
        });
        deviceBtns.forEach(function (b) {
            b.addEventListener('click', function () { setDevice(b.dataset.hweEmailPreviewDevice); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) close();
        });
        setDevice('desktop');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    function boot() {
        initTabs();
        initHeaderLogo();
        initColorPickers();
        initMediaPickers();
        initRowsEditors();
        initSaveBar();
        initFold();
        initProviderToggle();
        initSlots();
        initNotices();
        initEmailPreview();
        refreshView();
    }
})();
