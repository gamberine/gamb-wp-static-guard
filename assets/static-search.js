(function () {
  'use strict';
  var script = document.getElementById('gamb-static-search');
  if (!script) return;
  var index;
  function load() {
    if (!index) {
      index = fetch(script.getAttribute('data-index')).then(function (response) {
        if (!response.ok) throw new Error('Busca indisponível');
        return response.json();
      });
    }
    return index;
  }
  function normalize(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  }
  function initialize() {
    document.querySelectorAll('input[name="s"], input[type="search"], .search-field').forEach(function (input, number) {
      var box = document.createElement('div');
      box.id = 'gamb-search-results-' + number;
      box.setAttribute('aria-live', 'polite');
      box.style.cssText = 'display:none;position:absolute;z-index:99999;background:#fff;color:#1e293b;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,.12);max-height:340px;overflow:auto;width:100%;min-width:260px;margin-top:4px;padding:8px';
      input.setAttribute('aria-controls', box.id);
      input.parentNode.style.position = 'relative';
      input.parentNode.appendChild(box);
      function search() {
        var query = normalize(input.value.trim());
        box.replaceChildren();
        if (query.length < 2) { box.style.display = 'none'; return; }
        load().then(function (items) {
          if (query !== normalize(input.value.trim())) return;
          box.replaceChildren();
          var hits = items.filter(function (item) {
            return normalize(item.title + ' ' + item.content).indexOf(query) !== -1;
          }).slice(0, 10);
          hits.forEach(function (hit) {
            var row = document.createElement('div');
            row.style.cssText = 'padding:8px;border-bottom:1px solid #f1f5f9';
            var link = document.createElement('a');
            var url = new URL(hit.url, location.href);
            if (url.origin !== location.origin) return;
            link.href = url.href;
            link.textContent = hit.title;
            link.style.cssText = 'font-weight:600;display:block';
            var excerpt = document.createElement('div');
            excerpt.textContent = hit.excerpt || '';
            row.append(link, excerpt);
            box.appendChild(row);
          });
          if (!hits.length) box.textContent = 'Nenhum resultado encontrado.';
          box.style.display = 'block';
        }).catch(function () {
          index = null;
          box.textContent = 'Busca indisponível. Tente novamente.';
          box.style.display = 'block';
        });
      }
      input.addEventListener('input', search);
      input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') box.style.display = 'none';
      });
      if (input.form) input.form.addEventListener('submit', function (event) {
        event.preventDefault();
        search();
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
