(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var config = window.gambGuardData;
    if (!config) return;
    function request(route, payload) {
      return fetch(config.restUrl + route, {method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':config.nonce},body:JSON.stringify(payload)}).then(function (response) {
        return response.json().then(function (result) {
          if (!response.ok || !result || result.success === false || result.code) throw new Error(result && result.message ? result.message : 'Não foi possível concluir a operação.');
          return result;
        });
      });
    }
    var single = document.querySelector('[data-gamb-single]');
    if (single) single.addEventListener('click', function () {
      single.disabled = true;
      var message = document.querySelector('[data-gamb-single-message]');
      message.textContent = 'Publicação em andamento.';
      request('apply-single', {post_id:Number(single.dataset.gambSingle)}).then(function () { message.textContent = 'Publicação concluída.'; }).catch(function (error) { message.textContent = error.message; }).finally(function () { single.disabled = false; });
    });
    var root = document.getElementById('gamb-guard-admin-app');
    if (!root) return;
    var notice = root.querySelector('#gamb-toast');
    function notify(message, error) { notice.textContent = message; notice.hidden = false; notice.classList.toggle('gamb-error', !!error); }
    root.querySelectorAll('[data-tab]').forEach(function (button) {
      button.addEventListener('click', function () {
        root.querySelectorAll('[data-tab]').forEach(function (item) { item.setAttribute('aria-pressed', String(item === button)); });
        root.querySelectorAll('.gamb-panel').forEach(function (panel) { panel.hidden = panel.id !== button.getAttribute('aria-controls'); });
      });
    });
    root.querySelectorAll('.gamb-save-settings-btn').forEach(function (button) {
      button.addEventListener('click', function () {
        var payload = {};
        root.querySelectorAll('[data-opt]').forEach(function (field) { payload[field.dataset.opt] = field.type === 'checkbox' ? field.checked : field.value; });
        payload.post_types = Array.from(root.querySelectorAll('.gamb-pt-checkbox:checked')).map(function (field) { return field.value; });
        button.disabled = true;
        request('settings', payload).then(function () { notify('Configurações salvas.'); }).catch(function (error) { notify(error.message, true); }).finally(function () { button.disabled = false; });
      });
    });
    root.querySelectorAll('.gamb-run-export-btn').forEach(function (button) {
      button.addEventListener('click', function () {
        button.disabled = true; notify('Publicação em andamento. Aguarde a conclusão.');
        request('start-export', {type:root.querySelector('#gamb-quick-scope').value}).then(function (result) {
          notify('Publicação concluída. Arquivos processados: ' + result.files_count + '.');
          root.querySelector('#stat-last-time').textContent = result.exported_at;
          root.querySelector('#stat-last-scope').textContent = result.scope_label;
          root.querySelector('#stat-last-count').textContent = result.files_count;
          var log = root.querySelector('#gamb-activity-log-box'); log.replaceChildren();
          (result.messages || []).forEach(function (entry) { var row = document.createElement('p'); row.textContent = entry.datetime + ' - ' + entry.message; log.appendChild(row); });
          if (result.download_url) { var link = document.createElement('a'); link.href = result.download_url; link.textContent = 'Baixar pacote estático'; link.className = 'button'; root.querySelector('#gamb-latest-zip-wrap').replaceChildren(link); }
        }).catch(function (error) { notify(error.message, true); }).finally(function () { button.disabled = false; });
      });
    });
    root.querySelectorAll('.gamb-create-backup-btn').forEach(function (button) {
      button.addEventListener('click', function () {
        button.disabled = true; notify('Geração da cópia de segurança em andamento.');
        request('backup', {type:button.dataset.backupType}).then(function (result) {
          notify('Cópia de segurança gerada.');
          if (result.download_url) { var link = document.createElement('a'); link.href = result.download_url; link.textContent = 'Baixar cópia de segurança'; link.className = 'button'; button.parentNode.appendChild(link); }
        }).catch(function (error) { notify(error.message, true); }).finally(function () { button.disabled = false; });
      });
    });
  });
})();
