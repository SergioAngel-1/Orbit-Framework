/**
 * HWE Control Center — Admin JS
 *
 * Tab navigation, color picker sync, media pickers, rows editors, optional slots,
 * payment provider toggle, save spinner, and notice auto-dismiss.
 */
(function () {
    'use strict';

    // =========================================================================
    // Tab Navigation
    // =========================================================================

    function initTabs() {
        var tabBtns = document.querySelectorAll('.hwe-tab-btn');
        var tabPanels = document.querySelectorAll('.hwe-tab-panel');
        if (!tabBtns.length || !tabPanels.length) return;

        tabBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tabId = this.dataset.tab;

                tabBtns.forEach(function (b) { b.classList.remove('active'); });
                tabPanels.forEach(function (p) { p.classList.remove('active'); });

                this.classList.add('active');
                var panel = document.querySelector('[data-panel="' + tabId + '"]');
                if (panel) panel.classList.add('active');

                try { localStorage.setItem('hwe_active_tab', tabId); } catch (e) {}
            });
        });

        // Restore saved tab.
        try {
            var saved = localStorage.getItem('hwe_active_tab');
            if (saved) {
                var btn = document.querySelector('[data-tab="' + saved + '"]');
                var panel = document.querySelector('[data-panel="' + saved + '"]');
                if (btn && panel) {
                    tabBtns.forEach(function (b) { b.classList.remove('active'); });
                    tabPanels.forEach(function (p) { p.classList.remove('active'); });
                    btn.classList.add('active');
                    panel.classList.add('active');
                }
            }
        } catch (e) {}
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
    // Save Spinner
    // =========================================================================

    function initSaveSpinner() {
        var form = document.querySelector('.hwe-form');
        var saveBtn = document.querySelector('.hwe-save-btn');
        if (!form || !saveBtn) return;

        form.addEventListener('submit', function () {
            saveBtn.classList.add('saving');
            saveBtn.disabled = true;
        });
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

        function toggleGroups() {
            var online = !gateway || gateway.checked;
            var selected = providerSelect.value;
            if (providerRow) providerRow.hidden = !online;
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
                    slotFields(slot).forEach(function (field) { field.value = ''; });
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
    // Auto-dismiss notices
    // =========================================================================

    function initNotices() {
        document.querySelectorAll('.notice.is-dismissible').forEach(function (notice) {
            setTimeout(function () {
                notice.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                notice.style.opacity = '0';
                notice.style.transform = 'translateY(-8px)';
                setTimeout(function () { notice.remove(); }, 300);
            }, 5000);
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
        initColorPickers();
        initMediaPickers();
        initRowsEditors();
        initSaveSpinner();
        initProviderToggle();
        initSlots();
        initNotices();
        initEmailPreview();
    }
})();
