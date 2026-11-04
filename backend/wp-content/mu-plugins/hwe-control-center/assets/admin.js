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

    // `rank` ordena las funciones: primero las condiciones, luego los resúmenes, el idioma y,
    // al final, lo que mira el resultado (la etiqueta de «abrir todo»).
    function onView(fn, rank) {
        viewHooks.push({ fn: fn, rank: rank || 50 });
        viewHooks.sort(function (a, b) { return a.rank - b.rank; });
    }

    function refreshView() { viewHooks.forEach(function (hook) { hook.fn(); }); }

    function activePanel() { return document.querySelector('[data-hwe-panel]:not([hidden])'); }

    // =========================================================================
    // Navegación lateral: un enlace por pestaña, un panel por enlace.
    // =========================================================================

    // La ruta del panel vive en la URL: `#pestaña` o `#pestaña/elegido` (p. ej. `#landings/guia`, la
    // landing que se está editando). Va también en la acción del formulario, así sobrevive al guardado.
    var route = { tab: '', subs: {}, form: null, base: '' };

    function readRoute(hash) {
        var parts = String(hash || '').replace(/^#/, '').split('/');
        return { tab: parts[0] || '', sub: parts.slice(1).join('/') };
    }

    function writeRoute() {
        if (!route.tab) return;
        var sub = route.subs[route.tab];
        var hash = '#' + route.tab + (sub ? '/' + sub : '');
        try { history.replaceState(null, '', hash); } catch (e) {}
        if (route.form) route.form.setAttribute('action', route.base + hash);
    }

    // Recuerda lo elegido dentro de una pestaña (p. ej. la landing) y lo pone en la URL.
    function chooseSub(tab, sub) {
        route.subs[tab] = sub;
        if (route.tab === tab) writeRoute();
    }

    function initTabs() {
        var nav = document.querySelector('.hwe-nav');
        var links = document.querySelectorAll('[data-hwe-tab]');
        var panels = document.querySelectorAll('[data-hwe-panel]');
        if (!nav || !links.length || !panels.length) return;

        var toggle = nav.querySelector('.hwe-nav__toggle');
        var current = nav.querySelector('[data-hwe-nav-current]');
        var wrap = document.querySelector('.hwe-wrap');
        var form = document.querySelector('[data-hwe-form]');
        route.form = form;
        route.base = form ? form.getAttribute('action').split('#')[0] : '';

        function exists(tab) {
            return !!tab && Array.prototype.some.call(panels, function (p) { return p.dataset.hwePanel === tab; });
        }

        function show(tab) {
            var link = null;
            links.forEach(function (l) {
                var on = l.dataset.hweTab === tab;
                if (on) { l.setAttribute('aria-current', 'true'); link = l; } else { l.removeAttribute('aria-current'); }
            });
            panels.forEach(function (p) { p.hidden = p.dataset.hwePanel !== tab; });
            if (current && link) current.textContent = link.querySelector('.hwe-nav__label').textContent;
            route.tab = tab;
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
                writeRoute();
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
            var next = readRoute(window.location.hash);
            if (!exists(next.tab)) return;
            if (next.sub) route.subs[next.tab] = next.sub;
            show(next.tab);
            if (route.form) route.form.setAttribute('action', route.base + '#' + next.tab + (route.subs[next.tab] ? '/' + route.subs[next.tab] : ''));
            document.dispatchEvent(new CustomEvent('hwe:route', { detail: { tab: next.tab, sub: route.subs[next.tab] || '' } }));
        });

        // Al cargar manda la URL; si no trae pestaña, la que eligió el servidor (p. ej. tras una prueba de correo).
        var fromUrl = readRoute(window.location.hash);
        if (exists(fromUrl.tab) && fromUrl.sub) route.subs[fromUrl.tab] = fromUrl.sub;
        var start = exists(fromUrl.tab) ? fromUrl.tab : (wrap && wrap.dataset.hweInitialTab) || '';
        if (exists(start)) {
            show(start);
            if (form) form.setAttribute('action', route.base + '#' + start + (route.subs[start] ? '/' + route.subs[start] : ''));
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

    // Los controles con los que se rellena un hueco. Un desplegable no cuenta: siempre tiene valor
    // (la disposición, el orden…) y haría «ocupados» los huecos que nadie ha tocado.
    function slotFields(slot) {
        return slot.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="button"]), textarea');
    }

    // Ocupado = algún texto con contenido o alguna casilla marcada (p. ej. «Mostrar»).
    function slotHasData(slot) {
        var text = Array.prototype.some.call(slotFields(slot), function (field) {
            return field.value.trim() !== '';
        });
        return text || !!slot.querySelector('input[type="checkbox"]:checked');
    }

    function clearSlot(slot) {
        slotFields(slot).forEach(function (field) {
            if (field.value === '') return;
            field.value = '';
            announce(field);
        });
        slot.querySelectorAll('input[type="checkbox"]:checked').forEach(function (box) {
            box.checked = false;
            announce(box);
        });
        slot.querySelectorAll('.hwe-media-preview').forEach(function (preview) { preview.replaceChildren(); });
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
                refreshView();
            }

            slots.forEach(function (slot) {
                slot.hidden = !slotHasData(slot);
                var remove = slot.querySelector('[data-hwe-slot-remove]');
                if (!remove) return;
                remove.hidden = false;
                remove.addEventListener('click', function () {
                    clearSlot(slot);
                    slot.hidden = true;
                    sync();
                    addBtn.focus();
                });
            });

            addBtn.addEventListener('click', function () {
                var next = slots.filter(function (slot) { return slot.hidden; })[0];
                if (!next) return;
                next.hidden = false;
                // Quien añade un bloque quiere verlo: abierto y, si tiene «Mostrar», encendido.
                var block = next.querySelector('details[data-hwe-fold]');
                if (block) {
                    block.open = true;
                    var master = block.dataset.hweMaster ? byId(block.dataset.hweMaster) : null;
                    if (master && !master.checked) { master.checked = true; announce(master); }
                }
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
            revealInvalid(e.target);
            openAncestors(e.target, true);
            var row = e.target.closest('.hwe-field');
            if (row) row.classList.add('is-forced');
        }, true);

        onView(label, 90);
    }

    // =========================================================================
    // Idioma de edición («ES | EN»), en toda pestaña con campos traducibles.
    //
    // El formulario lleva siempre todos los campos: aquí solo se decide cuáles se ven.
    //   · Idioma por defecto: los campos base; las variantes `<clave>_<idioma>` se esconden.
    //   · Idioma secundario: solo su variante de cada texto traducible, con el original como
    //     referencia encima. Los ajustes que no se traducen (colores, interruptores, imágenes,
    //     selectores) se ESCONDEN, no se atenúan: editarlos desde «EN» sugiere que valen solo
    //     para ese idioma y no es así, y esconderlos deja la pantalla en lo único que hay que
    //     hacer ahí, traducir. Una sección o bloque que se queda sin nada que enseñar desaparece.
    // Las pestañas sin textos traducibles no tienen conmutador y siempre muestran todo.
    // =========================================================================

    var LANG_STORE = 'hwe-cc-lang';
    var lang = '';

    function defaultLang() {
        var wrap = document.querySelector('.hwe-wrap');
        return (wrap && wrap.dataset.hweDefaultLang) || '';
    }

    function byId(id) {
        return id ? (document.getElementById(id) || document.getElementById(id.toLowerCase())) : null;
    }

    // El original de una variante, en texto plano (los textos enriquecidos llevan HTML).
    function plainText(value) {
        return String(value || '')
            .replace(/<\/(p|li|h[1-6])>|<br\s*\/?>/gi, '\n')
            .replace(/<[^>]*>/g, '')
            .replace(/[ \t]+/g, ' ')
            .replace(/\n\s*\n+/g, '\n')
            .trim();
    }

    function fieldHidden(field) {
        return field.classList.contains('is-off-lang') || field.classList.contains('is-off-cond');
    }

    // ¿Queda algún campo a la vista dentro de `box`? Un hueco vacío o un bloque ya vacío no cuentan.
    function hasVisibleField(box) {
        var fields = box.querySelectorAll('.hwe-field');
        for (var i = 0; i < fields.length; i++) {
            var field = fields[i];
            if (fieldHidden(field)) continue;
            var blocker = field.parentElement.closest('[hidden], .is-lang-empty');
            if (blocker && blocker !== box && box.contains(blocker)) continue;
            return true;
        }
        return false;
    }

    function showReference(row, sw) {
        var control = row.querySelector('.hwe-field-control');
        if (!control) return;
        var ref = control.querySelector('.hwe-ref');
        if (!ref) {
            ref = document.createElement('div');
            ref.className = 'hwe-ref';
            var label = document.createElement('span');
            label.className = 'hwe-ref__label';
            label.textContent = sw.dataset.refLabel || '';
            var body = document.createElement('div');
            body.className = 'hwe-ref__text';
            ref.appendChild(label);
            ref.appendChild(body);
            control.insertBefore(ref, control.firstChild);
        }
        var base = byId(row.dataset.hweBase);
        var text = plainText(base ? base.value : '');
        var body2 = ref.querySelector('.hwe-ref__text');
        body2.textContent = text || sw.dataset.refEmpty || '';
        body2.classList.toggle('is-empty', text === '');
    }

    function applyLang() {
        var def = defaultLang();
        var buttons = document.querySelectorAll('button[data-hwe-lang]');
        Array.prototype.forEach.call(buttons, function (b) {
            b.setAttribute('aria-pressed', b.dataset.hweLang === lang ? 'true' : 'false');
        });

        var panel = activePanel();
        var sw = panel && panel.querySelector('[data-hwe-lang-switch]');
        if (!panel || !sw) return;

        var secondary = lang !== def;
        var hint = sw.querySelector('[data-hwe-lang-hint]');
        if (hint) hint.hidden = !secondary;
        panel.classList.toggle('is-translating', secondary);

        Array.prototype.forEach.call(panel.querySelectorAll('.hwe-field'), function (row) {
            var locale = row.dataset.hweLocale;
            var off = secondary ? locale !== lang : !!locale;
            row.classList.toggle('is-off-lang', off);
            if (secondary && !off) showReference(row, sw);
        });

        var boxes = panel.querySelectorAll('.hwe-section, details.hwe-subgroup, .hwe-slot, [data-hwe-switch-item]');
        for (var i = boxes.length - 1; i >= 0; i--) {
            boxes[i].classList.toggle('is-lang-empty', secondary && !hasVisibleField(boxes[i]));
        }
    }

    function initLanguage() {
        var switches = document.querySelectorAll('[data-hwe-lang-switch]');
        if (!switches.length) return;
        var def = defaultLang();
        var saved = '';
        try { saved = window.localStorage.getItem(LANG_STORE) || ''; } catch (e) {}
        var known = Array.prototype.map.call(document.querySelectorAll('button[data-hwe-lang]'), function (b) { return b.dataset.hweLang; });
        lang = known.indexOf(saved) !== -1 ? saved : def;

        Array.prototype.forEach.call(switches, function (sw) {
            sw.hidden = false;
            sw.addEventListener('click', function (e) {
                var button = e.target.closest('button[data-hwe-lang]');
                if (!button) return;
                lang = button.dataset.hweLang;
                try { window.localStorage.setItem(LANG_STORE, lang); } catch (err) {}
                refreshView();
            });
        });
        onView(applyLang, 30);
    }

    // Un control inválido escondido por el idioma no puede avisar: se vuelve al idioma por defecto.
    function revealInvalid(el) {
        if (el.closest('.is-off-lang') && lang !== defaultLang()) {
            lang = defaultLang();
            refreshView();
        }
    }

    // =========================================================================
    // Solo lo que aplica: condiciones sobre el valor actual de otros campos.
    //
    //   · `data-hwe-show-if` (una fila): se ve solo mientras su hermano tenga ciertos valores.
    //   · `data-hwe-master` (un bloque): con su interruptor apagado el bloque solo enseña ese
    //     interruptor. Al traducir no se aplica: un bloque oculto también se puede traducir de antemano.
    // Esconder no borra: los controles siguen en el formulario con su valor, y sin JS se ve todo.
    // =========================================================================

    var jsonCache = typeof WeakMap === 'function' ? new WeakMap() : null;

    function readJson(el, attr) {
        if (jsonCache && jsonCache.has(el)) return jsonCache.get(el);
        var spec = null;
        try { spec = JSON.parse(el.getAttribute(attr) || 'null'); } catch (e) {}
        if (jsonCache) jsonCache.set(el, spec);
        return spec;
    }

    function translating(panel) {
        return !!panel.querySelector('[data-hwe-lang-switch]') && lang !== defaultLang();
    }

    function applyConditions() {
        var panel = activePanel();
        if (!panel) return;
        var secondary = translating(panel);

        Array.prototype.forEach.call(panel.querySelectorAll('[data-hwe-show-if]'), function (row) {
            var spec = readJson(row, 'data-hwe-show-if');
            var control = spec && byId(spec.f);
            if (!control) return;
            var value = control.type === 'checkbox' ? (control.checked ? '1' : '0') : control.value;
            var show = spec.in ? spec.in.indexOf(value) !== -1 : spec.not_in ? spec.not_in.indexOf(value) === -1 : true;
            row.classList.toggle('is-off-cond', !show);
        });

        Array.prototype.forEach.call(panel.querySelectorAll('[data-hwe-master]'), function (block) {
            var control = byId(block.dataset.hweMaster);
            if (!control) return;
            var row = control.closest('.hwe-field');
            if (row) row.classList.add('is-master');
            block.classList.toggle('is-off', !control.checked && !secondary);
        });
    }

    // =========================================================================
    // Resumen de un bloque cerrado: «Visible · Imagen a la derecha · «Título»».
    // La cabecera cuenta el estado sin abrirlo; el servidor describe qué campos lo componen
    // (`data-hwe-summary`) y aquí se lee su valor actual.
    // =========================================================================

    function clip(text, max) {
        text = String(text).replace(/\s+/g, ' ').trim();
        return text.length > max ? text.slice(0, max - 1).trim() + '…' : text;
    }

    function summaryOf(block, spec) {
        var bits = [];
        if (spec.t) {
            var toggle = byId(spec.t);
            if (toggle) {
                if (!toggle.checked) return spec.off || '';
                bits.push(spec.on || '');
            }
        }
        (spec.p || []).forEach(function (part) {
            var value = '';
            if (part.as === 'slots') {
                var all = block.querySelectorAll('[data-hwe-slot]');
                var used = Array.prototype.filter.call(all, function (slot) { return !slot.hidden; });
                if (used.length) value = used.length + ' de ' + all.length + (part.unit ? ' ' + part.unit : '');
            } else {
                var control = byId(part.f);
                if (!control) return;
                if (part.as === 'select') {
                    var option = control.options && control.options[control.selectedIndex];
                    value = control.value !== '' && option ? option.text : '';
                } else {
                    value = clip(plainText(control.value), 48);
                    if (value && part.q) value = '«' + value + '»';
                }
            }
            if (value) bits.push(value);
        });
        return bits.length ? bits.join(' · ') : (spec.empty || '');
    }

    function applySummaries() {
        var panel = activePanel();
        if (!panel) return;
        Array.prototype.forEach.call(panel.querySelectorAll('details[data-hwe-summary]'), function (block) {
            var spec = readJson(block, 'data-hwe-summary');
            var out = spec && block.firstElementChild && block.firstElementChild.querySelector('[data-hwe-summary-out]');
            if (out) out.textContent = summaryOf(block, spec);
        });
    }

    // =========================================================================
    // Selector de elementos (`data-hwe-switcher`): una landing a la vez.
    //
    // Los demás elementos siguen en el DOM (ocultos) y se guardan con el resto. La elegida va en la
    // URL (`#landings/guia`) y sobrevive al guardado. Con uno solo no hay selector.
    // =========================================================================

    function initSwitchers() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-hwe-switcher]'), function (box) {
            var items = Array.prototype.filter.call(box.children, function (child) { return child.hasAttribute('data-hwe-switch-item'); });
            var bar = box.querySelector('[data-hwe-switcher-bar]');
            var select = box.querySelector('[data-hwe-switcher-select]');
            var panel = box.closest('[data-hwe-panel]');
            var tab = panel ? panel.dataset.hwePanel : '';
            if (!items.length || !bar || !select) return;

            box.classList.add('is-enhanced');
            if (items.length < 2) return;
            bar.hidden = false;

            function choose(id, write) {
                var known = items.some(function (item) { return item.dataset.hweSwitchItem === id; });
                var current = known ? id : items[0].dataset.hweSwitchItem;
                items.forEach(function (item) { item.hidden = item.dataset.hweSwitchItem !== current; });
                select.value = current;
                if (write) chooseSub(tab, current); else route.subs[tab] = current;
                refreshView();
            }

            select.addEventListener('change', function () { choose(select.value, true); });
            document.addEventListener('hwe:route', function (e) {
                if (e.detail.tab === tab && e.detail.sub) choose(e.detail.sub, false);
            });
            // Un control inválido de un elemento oculto no puede avisar: se pasa a ese elemento.
            box.addEventListener('invalid', function (e) {
                var item = e.target.closest('[data-hwe-switch-item]');
                if (item && item.hidden) choose(item.dataset.hweSwitchItem, true);
            }, true);

            choose(route.subs[tab] || items[0].dataset.hweSwitchItem, true);
        });
    }

    // Cualquier cambio de un campo puede mover resúmenes, referencias o la etiqueta de «abrir todo».
    function initView() {
        var form = document.querySelector('[data-hwe-form]');
        if (!form) return;
        form.addEventListener('input', refreshView);
        form.addEventListener('change', refreshView);
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
        initLanguage();
        initProviderToggle();
        onView(applyConditions, 10);
        onView(applySummaries, 20);
        initSwitchers();
        initSlots();
        initNotices();
        initEmailPreview();
        initView();
        refreshView();
    }
})();
