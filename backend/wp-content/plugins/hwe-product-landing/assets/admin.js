/* global jQuery, wp */
(function ($) {
  'use strict';

  // Construye la previsualización con `attr`, no concatenando en `.html()`: el
  // URL viene de la medioteca propia, pero interpolarlo en una cadena de HTML
  // es el patrón que abre el hueco el día que la fuente cambie. `Render::media()`
  // ya usa `esc_url` del lado PHP.
  function setPreview($row, url) {
    var $preview = $row.find('.hwe-media-preview').empty();
    if (url) $preview.append($('<img>', { src: url, alt: '' }));
  }

  function bindMedia($scope) {
    $scope.find('.hwe-media-pick').off('click.hwl').on('click.hwl', function (e) {
      e.preventDefault();
      var $row = $(this).closest('.hwe-media');
      var frame = wp.media({ title: 'Seleccionar imagen', multiple: false, library: { type: 'image' } });
      frame.on('select', function () {
        var url = frame.state().get('selection').first().toJSON().url || '';
        $row.find('.hwe-media-url').val(url);
        setPreview($row, url);
      });
      frame.open();
    });
    $scope.find('.hwe-media-clear').off('click.hwl').on('click.hwl', function (e) {
      e.preventDefault();
      var $row = $(this).closest('.hwe-media');
      $row.find('.hwe-media-url').val('');
      setPreview($row, '');
    });
  }

  // Refleja el tope `data-max-rows` (si lo hay) en el botón "+ Añadir": lo
  // deshabilita al llegar al máximo en vez de dejar que un editor cargue
  // filas que el frontend nunca va a mostrar (el tope real de cada
  // repeater lo declara `max_rows` en `Fields::REPEATERS`).
  function refreshAddButton($rep) {
    var max = parseInt($rep.attr('data-max-rows'), 10);
    if (!max) return;
    var count = $rep.find('.hwe-landing-rows > .hwe-landing-row').length;
    $rep.find('.hwe-landing-add').prop('disabled', count >= max);
  }

  // Selector de color (Iris) para los acentos de la landing. La paleta de
  // sugerencias viaja en `data-palette` (JSON), derivada de la paleta de marca
  // por `Palette::suggestions()`. Sin valor guardado el picker arranca vacío
  // (= "usar el color de la plantilla"); el botón "Limpiar" de Iris lo
  // devuelve a ese estado.
  function bindColor($scope) {
    if (!$.fn.wpColorPicker) return;
    $scope.find('.hwe-landing-color').each(function () {
      var $input = $(this);
      var palette = true;
      try {
        var parsed = JSON.parse($input.attr('data-palette') || '[]');
        if (Array.isArray(parsed) && parsed.length) palette = parsed;
      } catch (e) {
        /* paleta por defecto de Iris */
      }
      $input.wpColorPicker({ palettes: palette, defaultColor: false });
    });
  }

  $(function () {
    bindMedia($(document));
    bindColor($(document));
    $('.hwe-landing-repeater[data-max-rows]').each(function () {
      refreshAddButton($(this));
    });

    $('.hwe-landing-add').on('click', function (e) {
      e.preventDefault();
      var $rep = $(this).closest('.hwe-landing-repeater');
      var key = $rep.data('repeater');
      var max = parseInt($rep.attr('data-max-rows'), 10);
      var count = $rep.find('.hwe-landing-rows > .hwe-landing-row').length;
      if (max && count >= max) return;
      var idx = parseInt($rep.attr('data-next-index'), 10) || 0;
      var html = $('#hwe-landing-tpl-' + key).html().replace(/__INDEX__/g, String(idx));
      var $row = $(html);
      $rep.find('.hwe-landing-rows').append($row);
      $rep.attr('data-next-index', String(idx + 1));
      bindMedia($row);
      // También el picker de color: hoy ningún `row_field` es de tipo `color`,
      // pero el día que se añada uno, sin esta línea el campo queda como un
      // input de texto mudo y el fallo no se ve hasta abrir el metabox.
      bindColor($row);
      refreshAddButton($rep);
    });

    $(document).on('click', '.hwe-landing-remove', function (e) {
      e.preventDefault();
      var $rep = $(this).closest('.hwe-landing-repeater');
      $(this).closest('.hwe-landing-row').remove();
      refreshAddButton($rep);
    });
  });
})(jQuery);
