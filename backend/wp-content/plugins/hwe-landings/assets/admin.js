/**
 * Editor del registro de landings (Control Center → Landings).
 *
 * Añade y quita filas. El valor viaja en los `<input>`/`<select>` de cada fila con
 * `name="hwe_config[landings][registry][<índice>][…]"`; el servidor lo vuelve a serializar a su
 * forma canónica. Sin JS el formulario conserva las filas guardadas.
 */
(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;

    var add = target.closest('.hwe-landing-registry-add');
    if (add) {
      var root = add.closest('.hwe-landing-registry');
      var tpl = root && root.querySelector('.hwe-landing-registry-tpl');
      if (!root || !tpl) return;
      var index = parseInt(root.getAttribute('data-next-index') || '0', 10);
      root.setAttribute('data-next-index', String(index + 1));
      var holder = document.createElement('div');
      holder.innerHTML = tpl.textContent.replace(/__INDEX__/g, String(index));
      var row = holder.firstElementChild;
      if (row) {
        root.querySelector('.hwe-landing-registry-rows').appendChild(row);
        var first = row.querySelector('input');
        if (first) first.focus();
      }
      return;
    }

    var remove = target.closest('.hwe-landing-registry-remove');
    if (remove) {
      var item = remove.closest('.hwe-landing-registry-row');
      if (!item) return;
      // Una fila nueva (sin nada guardado) se quita sin preguntar; una guardada pide confirmar:
      // su contenido se conserva pero la landing deja de existir en la web.
      if (item.hasAttribute('data-new')) {
        item.remove();
      } else if (window.confirm('¿Quitar esta landing? Su contenido se conserva, pero dejará de mostrarse en la web.')) {
        item.remove();
      }
    }
  });
})();
