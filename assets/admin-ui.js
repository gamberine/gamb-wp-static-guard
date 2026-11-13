(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var root = document.getElementById('gamb-guard-admin-app');
    if (!root || !window.gambGuardData) return;

    var cfg = window.gambGuardData;
    var toast = document.getElementById('gamb-toast');

    function showToast(msg, isError) {
      if (!toast) return;
      toast.textContent = msg;
      toast.style.background = isError ? '#dc2626' : '#059669';
      toast.style.display = 'block';
      setTimeout(function() { toast.style.display = 'none'; }, 4000);
    }

    // Tab navigation
    var navButtons = root.querySelectorAll('.gamb-nav-item');
    var panels = root.querySelectorAll('.gamb-tab-panel');
    navButtons.forEach(function(btn) {
      btn.addEventListener('click', function() {
        var target = btn.getAttribute('data-tab');
        navButtons.forEach(function(b) { b.classList.remove('active'); });
        panels.forEach(function(p) {
          if (p.id === 'panel-' + target) p.classList.add('active');
          else p.classList.remove('active');
        });
        btn.classList.add('active');
      });
    });

    // Sync scope selectors (sidebar quick selector + main form selector)
    var quickScope = document.getElementById('gamb-quick-scope');
    var formScope = document.getElementById('field-generate_type');
    if (quickScope && formScope) {
      quickScope.addEventListener('change', function() { formScope.value = quickScope.value; });
      formScope.addEventListener('change', function() { quickScope.value = formScope.value; });
    }

    // Save Settings
    var saveBtns = root.querySelectorAll('.gamb-save-settings-btn');
    saveBtns.forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        btn.disabled = true;
        var origText = btn.textContent;
        btn.textContent = 'Salvando...';

        var payload = {};
        var inputs = root.querySelectorAll('[data-opt]');
        inputs.forEach(function(el) {
          var key = el.getAttribute('data-opt');
          if (el.type === 'checkbox') {
            payload[key] = el.checked;
          } else {
            payload[key] = el.value;
          }
        });

        // Post types checkboxes
        var ptBoxes = root.querySelectorAll('.gamb-pt-checkbox');
        var pts = [];
        ptBoxes.forEach(function(cb) { if (cb.checked) pts.push(cb.value); });
        payload['post_types'] = pts;

        fetch(cfg.restUrl + 'settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
          body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          btn.disabled = false;
          btn.textContent = origText;
          showToast('Configurações salvas e cache atualizado com sucesso!');
        })
        .catch(function() {
          btn.disabled = false;
          btn.textContent = origText;
          showToast('Erro ao salvar configurações.', true);
        });
      });
    });

    // Run Static Export
    var exportBtns = root.querySelectorAll('.gamb-run-export-btn');
    exportBtns.forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        var scopeVal = quickScope ? quickScope.value : 'theme';
        exportBtns.forEach(function(b) { b.disabled = true; b.textContent = 'Processando Exportação...'; });
        showToast('Iniciando conversão estática (' + scopeVal + ')...');

        fetch(cfg.restUrl + 'start-export', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
          body: JSON.stringify({ type: scopeVal })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          exportBtns.forEach(function(b) { b.disabled = false; b.textContent = 'Converter & Publicar Agora'; });
          if (res && res.success) {
            showToast('Exportação concluída: ' + res.files_count + ' arquivos processados!');
            var statTime = document.getElementById('stat-last-time');
            var statScope = document.getElementById('stat-last-scope');
            var statCount = document.getElementById('stat-last-count');
            if (statTime) statTime.textContent = res.exported_at;
            if (statScope) statScope.textContent = res.scope_label;
            if (statCount) statCount.textContent = res.files_count + ' arquivos';

            if (res.download_url) {
              var dlWrap = document.getElementById('gamb-latest-zip-wrap');
              if (dlWrap) {
                dlWrap.innerHTML = '<a href="' + res.download_url + '" class="gamb-btn-primary" style="max-width:340px;">⬇ Baixar Último Pacote Estático ZIP (' + (res.zip_size || '') + ')</a>';
              }
            }
            if (Array.isArray(res.messages)) {
              var logBox = document.getElementById('gamb-activity-log-box');
              if (logBox) {
                logBox.innerHTML = res.messages.map(function(m) {
                  var lnk = m.link && m.link.url ? '<a class="gamb-log-link" href="' + m.link.url + '">' + m.link.label + '</a>' : '';
                  return '<div class="gamb-log-item"><span class="gamb-log-time">[' + m.datetime + ']</span><span>' + m.message + '</span>' + lnk + '</div>';
                }).join('');
              }
            }
          } else {
            showToast('Falha na exportação.', true);
          }
        })
        .catch(function() {
          exportBtns.forEach(function(b) { b.disabled = false; b.textContent = 'Converter & Publicar Agora'; });
          showToast('Erro de comunicação durante a exportação.', true);
        });
      });
    });

    // Create Backup (Duplicator style)
    var backupBtns = root.querySelectorAll('.gamb-create-backup-btn');
    backupBtns.forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        var bType = btn.getAttribute('data-backup-type') || 'full';
        btn.disabled = true;
        var orig = btn.textContent;
        btn.textContent = 'Gerando Backup...';
        showToast('Gerando pacote de backup (' + (bType === 'db' ? 'Banco SQL' : 'Completo SQL + Arquivos') + ')...');

        fetch(cfg.restUrl + 'backup', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
          body: JSON.stringify({ type: bType })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          btn.disabled = false;
          btn.textContent = orig;
          if (res && res.success) {
            showToast('Backup criado com sucesso: ' + res.filename + ' (' + res.size_formatted + ')');
            window.location.reload();
          } else {
            showToast('Erro ao criar backup.', true);
          }
        })
        .catch(function() {
          btn.disabled = false;
          btn.textContent = orig;
          showToast('Erro ao criar backup.', true);
        });
      });
    });

    // Delete Backup
    var delBtns = root.querySelectorAll('.gamb-delete-backup-btn');
    delBtns.forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        var fname = btn.getAttribute('data-file');
        if (!confirm('Deseja realmente excluir o backup ' + fname + '?')) return;
        fetch(cfg.restUrl + 'backup/delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
          body: JSON.stringify({ filename: fname })
        })
        .then(function() { window.location.reload(); });
      });
    });

    // Restore Factory Secure Profile
    var resetBtn = document.getElementById('gamb-restore-defaults-btn');
    if (resetBtn) {
      resetBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (!confirm('Restaurar todas as configurações para o Perfil Padrão Seguro de Fábrica?')) return;
        fetch(cfg.restUrl + 'settings/reset', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }
        })
        .then(function() {
          showToast('Perfil Padrão Seguro restaurado!');
          setTimeout(function() { window.location.reload(); }, 800);
        });
      });
    }
  });
})();
