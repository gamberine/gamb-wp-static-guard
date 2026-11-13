(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var config = window.gambGuardData;
    if (!config) return;
    function request(route, payload) {
      return fetch(config.restUrl + route, {method:payload === undefined ? 'GET' : 'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':config.nonce},body:payload === undefined ? undefined : JSON.stringify(payload)}).then(function (response) {
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
    function loadBackups() {
      var container = root.querySelector('[data-backup-list]');
      return request('backups').then(function (backups) {
        container.replaceChildren();
        if (!backups.length) { container.textContent = 'Nenhuma cópia de segurança registrada.'; return; }
        var table = document.createElement('table');
        var header = document.createElement('tr');
        ['Arquivo', 'Data', 'Tamanho', 'Ações'].forEach(function (label) { var cell = document.createElement('th'); cell.scope = 'col'; cell.textContent = label; header.appendChild(cell); });
        var head = document.createElement('thead'); head.appendChild(header); table.appendChild(head);
        var body = document.createElement('tbody');
        backups.forEach(function (backup) {
          var row = document.createElement('tr');
          [backup.filename, backup.created_at, backup.size_formatted].forEach(function (value) { var cell = document.createElement('td'); cell.textContent = value; row.appendChild(cell); });
          var actions = document.createElement('td'); var download = document.createElement('a'); download.href = backup.download_url; download.textContent = 'Baixar'; actions.appendChild(download);
          var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button'; remove.textContent = 'Excluir'; remove.setAttribute('aria-label', 'Excluir ' + backup.filename); remove.addEventListener('click', function () { if (!confirm('Excluir esta cópia de segurança?')) return; remove.disabled = true; request('backup/delete', {filename:backup.filename}).then(loadBackups).catch(function (error) { notify(error.message, true); remove.disabled = false; }); }); actions.appendChild(document.createTextNode(' ')); actions.appendChild(remove); row.appendChild(actions); body.appendChild(row);
        });
        table.appendChild(body); container.appendChild(table);
      }).catch(function (error) { notify(error.message, true); });
    }
    root.querySelectorAll('[data-tab]').forEach(function (button) {
      button.addEventListener('click', function () {
        root.querySelectorAll('[data-tab]').forEach(function (item) { item.setAttribute('aria-pressed', String(item === button)); });
        root.querySelectorAll('.gamb-panel').forEach(function (panel) { panel.hidden = panel.id !== button.getAttribute('aria-controls'); });
        if (button.dataset.tab === 'backup') loadBackups();
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
          if (result.first_full_export_completed) { root.querySelectorAll('#gamb-quick-scope option').forEach(function (option) { option.disabled = false; }); var initial = root.querySelector('[data-first-full-notice]'); if (initial) initial.hidden = true; }
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
          loadBackups();
          if (result.download_url) { var link = document.createElement('a'); link.href = result.download_url; link.textContent = 'Baixar cópia de segurança'; link.className = 'button'; button.parentNode.appendChild(link); }
        }).catch(function (error) { notify(error.message, true); }).finally(function () { button.disabled = false; });
      });
    });
  });
})();
