/**
 * JavaScript de administración para Starter Memberships
 */

(function($) {
    'use strict';

    // Inicializar cuando el DOM esté listo
    $(document).ready(function() {
        initMembershipProductPanel();
        initLevelPreview();
    });

    /**
     * Inicializar panel de membresía en productos
     */
    function initMembershipProductPanel() {
        var $checkbox = $('#_is_membership_product');
        var $fields = $('.membership-fields');

        if (!$checkbox.length) {
            return;
        }

        // Toggle inicial
        if ($checkbox.is(':checked')) {
            $fields.show();
        } else {
            $fields.hide();
        }

        // Toggle al cambiar
        $checkbox.on('change', function() {
            if ($(this).is(':checked')) {
                $fields.slideDown(200);
                // Marcar como virtual automáticamente
                $('#_virtual').prop('checked', true).trigger('change');
            } else {
                $fields.slideUp(200);
            }
        });
    }

    /**
     * Inicializar vista previa de nivel
     */
    function initLevelPreview() {
        var $levelSelect = $('#_membership_level');
        var $preview = $('#membership-preview-content');
        var $pointsInput = $('#_membership_monthly_points');

        if (!$levelSelect.length) {
            return;
        }

        // Datos de niveles (se podrían cargar vía AJAX si es necesario)
        var levels = {
            1: { name: 'Zanahoria Bronce', icon: '🥉', color: '#CD7F32', points: 52000 },
            2: { name: 'Zanahoria Plateada', icon: '🥈', color: '#C0C0C0', points: 102000 },
            3: { name: 'Zanahoria Dorada', icon: '🥇', color: '#FFD700', points: 202000 },
            4: { name: 'Zanahoria Diamante', icon: '💎', color: '#B9F2FF', points: 502000 }
        };

        // Actualizar vista previa al cambiar nivel
        $levelSelect.on('change', function() {
            var level = $(this).val();
            var levelData = levels[level];

            if (levelData) {
                var html = '<p>' +
                    '<strong style="color: ' + levelData.color + ';">' + 
                    levelData.icon + ' ' + levelData.name + 
                    '</strong><br>' +
                    '<span>' + formatFC(levelData.points) + ' Virtual Coins/mes</span>' +
                    '</p>';
                
                $preview.html(html);

                // Actualizar placeholder de puntos
                if (!$pointsInput.val()) {
                    $pointsInput.attr('placeholder', levelData.points);
                }
            }
        });

        // Trigger inicial
        $levelSelect.trigger('change');
    }

    /**
     * Formatear Virtual Coins
     */
    function formatFC(amount) {
        return amount.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

})(jQuery);
