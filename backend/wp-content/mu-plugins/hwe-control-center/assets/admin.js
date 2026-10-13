/**
 * HWE Control Center — Admin JS
 *
 * Tab navigation, color picker sync, media pickers, rows editors, save spinner,
 * and notice auto-dismiss.
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

        var groups = document.querySelectorAll('.hwe-subgroup[data-provider]');

        function toggleGroups() {
            var selected = providerSelect.value;
            groups.forEach(function (group) {
                if (group.dataset.provider === selected) {
                    group.classList.add('is-active');
                } else {
                    group.classList.remove('is-active');
                }
            });
        }

        providerSelect.addEventListener('change', toggleGroups);
        toggleGroups();
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
        initNotices();
    }
})();
