/**
 * Starter Referrals & Points - Admin JavaScript
 * 
 * Este archivo contiene las funcionalidades JavaScript para la interfaz de administración
 * del plugin de Referidos y Virtual Coins.
 */

(function($) {
    'use strict';

    // Función para inicializar las pestañas en la página de configuración
    function initTabs() {
        // Cambio de pestañas en la configuración
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            
            // Remover clase activa de todas las pestañas y contenidos
            $('.nav-tab').removeClass('nav-tab-active');
            $('.tab-content').removeClass('active');
            
            // Añadir clase activa a la pestaña seleccionada
            $(this).addClass('nav-tab-active');
            
            // Mostrar el contenido correspondiente
            var target = $(this).attr('href');
            $(target).addClass('active');
        });
    }

    // Inicializar cuando el DOM esté listo
    $(document).ready(function() {
        initTabs();
        
        // Inicializar datepickers si existen
        if ($.fn.datepicker) {
            $('.starter-rp-datepicker').datepicker({
                dateFormat: 'yy-mm-dd'
            });
        }
        
        // Inicializar tooltips si existen
        if ($.fn.tooltip) {
            $('.starter-rp-tooltip').tooltip();
        }
    });

})(jQuery);
