/**
 * HWE Control Center — Admin JS
 *
 * Tab navigation, color picker sync, save spinner, and notice auto-dismiss.
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
        initSaveSpinner();
        initProviderToggle();
        initNotices();
    }
})();
