(function () {
  'use strict';
  function initialize() {
    var config = window.gambOriginalAccess, dialog = document.querySelector('#gamb-original-confirm');
    if (!config || !dialog) return;
    var state = config.status, busy = false, opener, timer;
    function showError(message) { dialog.querySelector('[data-gamb-original-error]').textContent = message; }
    function render(value) {
      state = value; document.body.classList.toggle('gamb-original-enabled', value.enabled);
      document.querySelectorAll('[data-gamb-original-toggle]').forEach(function (button) { button.setAttribute('aria-checked', String(value.enabled)); button.disabled = busy; });
      document.querySelectorAll('[data-gamb-original-state]').forEach(function (label) { label.textContent = value.enabled ? 'Ativado' : 'Desativado'; });
      document.querySelectorAll('[data-gamb-original-message]').forEach(function (label) { label.textContent = value.message; });
      document.querySelectorAll('[data-gamb-original-open],#wp-admin-bar-gamb-original-open a').forEach(function (link) { link.hidden = !value.enabled; link.href = value.url || '#'; link.closest('#wp-admin-bar-gamb-original-open')?.classList.toggle('gamb-original-link-disabled', !value.enabled); });
      var notice = document.querySelector('.gamb-original-notice');
      if (value.enabled && !notice && !config.isOriginal) {
        notice = document.createElement('div'); notice.className = 'notice notice-warning gamb-original-notice'; notice.setAttribute('role', 'status');
        var strong = document.createElement('strong'); strong.textContent = 'Acesso WP original ATIVADO por até uma hora. ';
        var text = document.createElement('span'); text.textContent = 'A URL principal continua estática. Desative após validar os arquivos. ';
        var disable = document.createElement('button'); disable.type = 'button'; disable.className = 'button'; disable.dataset.gambOriginalToggle = ''; disable.textContent = 'Desativar acesso WP';
        notice.append(strong, text, disable); document.body.appendChild(notice);
      } else if (!value.enabled && notice) notice.remove();
      if (window.dispatchEvent) window.dispatchEvent(new Event('resize'));
      clearTimeout(timer);
      if (value.enabled && value.expires_at) timer = setTimeout(refresh, Math.max(1000, value.expires_at * 1000 - Date.now() + 1000));
      if (!value.enabled && config.isOriginal) window.location.assign(config.staticUrl);
    }
    async function call(method, body) {
      var response = await fetch(config.endpoint, { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined, cache: 'no-store' });
      var result = await response.json(); if (!response.ok) throw Error(result.message || 'Não foi possível alterar o acesso. Recarregue o painel.'); return result;
    }
    async function refresh() { try { render(await call('GET')); } catch (error) { showError(error.message); } }
    async function update(enabled) {
      if (busy) return; busy = true; dialog.querySelector('[data-gamb-original-confirm]').disabled = true;
      try { var result = await call('POST', { enabled: enabled, confirmed: enabled }); dialog.close(); render(result); }
      catch (error) { showError(error.message); if (!dialog.open) dialog.showModal(); }
      finally { busy = false; dialog.querySelector('[data-gamb-original-confirm]').disabled = false; document.querySelectorAll('[data-gamb-original-toggle]').forEach(function (button) { button.disabled = false; }); }
    }
    document.addEventListener('click', function (event) {
      var button = event.target.closest('[data-gamb-original-toggle]'); if (!button) return; event.preventDefault(); if (busy) return;
      if (state.enabled) update(false); else { opener = button; showError(''); dialog.showModal(); dialog.querySelector('[data-gamb-original-cancel]').focus(); }
    });
    dialog.querySelector('[data-gamb-original-cancel]').addEventListener('click', function () { dialog.close(); });
    dialog.querySelector('[data-gamb-original-confirm]').addEventListener('click', function () { update(true); });
    dialog.addEventListener('close', function () { if (opener) opener.focus(); }); render(state);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize); else initialize();
})();
