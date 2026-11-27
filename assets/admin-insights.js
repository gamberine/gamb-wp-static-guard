(function () {
  'use strict';
  window.gambAdminTime=function(value){if(!value)return 'Não registrada';var zone=(window.gambGuardData||{}).timezone||'UTC',text=String(value);if(/^\d{4}-\d{2}-\d{2}T/.test(text)){try{text=new Intl.DateTimeFormat('pt-BR',{timeZone:zone,dateStyle:'short',timeStyle:'medium',hour12:false}).format(new Date(text));}catch(e){text+=' (ISO; fuso no próprio valor)';}}else{text=text.replace(/^(\d{4})-(\d{2})-(\d{2}) /,'$3/$2/$1 ');}return text+' · '+zone;};
  window.gambAdminInsights = function (api) {
    var time=window.gambAdminTime,root=api.root, saved, page=1, create=root.querySelector('[data-destination-create]'), path=root.querySelector('[data-opt=local_dir]');
    function node(tag,text,className){var n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(className)n.className=className;return n;}
    function put(selector,text){root.querySelectorAll(selector).forEach(function(n){n.textContent=text;});}
    function list(selector,rows,empty){var box=root.querySelector(selector);box.replaceChildren();if(!rows.length)box.appendChild(node('li',empty));rows.forEach(function(row){var li=node('li');li.appendChild(node('strong',row.label||row.id||'Informação'));li.appendChild(node('p',row.message||row.description||''));if(row.how_to_fix)li.appendChild(node('p','Como proceder: '+row.how_to_fix));box.appendChild(li);});}
    function report(which,r){var box=root.querySelector('[data-audit-report="'+which+'"]');box.replaceChildren();if(!r||!r.checked_at){box.appendChild(node('p','Nenhum relatório detalhado neste escopo. Execute a verificação correspondente.'));return;}
      var scope=r.scope==='origin'?'Origem: Home e página inexistente':r.context==='delivered'?'HTML da pasta local publicada':'HTML preparado no fluxo de exportação';
      box.appendChild(node('h3',r.success?'Aprovada nos controles executados':'Impedimentos encontrados',r.success?'gamb-result-ok':'gamb-result-error'));
      box.appendChild(node('p',scope+' · '+time(r.checked_at)+' · Plugin '+r.version));
      box.appendChild(node('p',(r.documents||0)+' documento(s) final(is) aprovado(s)'+(r.expected_documents?' de '+r.expected_documents+' esperado(s)':'')+'. Aprovação limitada ao escopo e aos controles abaixo.'));
      (r.findings||[]).forEach(function(f){var issue=node('div',undefined,'gamb-callout gamb-result-error');issue.appendChild(node('strong',(f.severity||'Impedimento')+' · '+(f.label||f.code)));issue.appendChild(node('p','Documento: '+f.source+' · '+f.code));issue.appendChild(node('p',f.description||''));issue.appendChild(node('p','Como proceder: '+(f.how_to_fix||'Revise a origem e execute novamente.')));box.appendChild(issue);});
      if(!(r.checks||[]).length)box.appendChild(node('p','Relatório anterior sem cobertura detalhada. Reexecute a verificação para registrar os controles.'));
      var checks=node('details');checks.open=!r.success;checks.appendChild(node('summary','Controles executados ('+(r.checks||[]).length+')'));
      (r.checks||[]).forEach(function(c){var item=node('div',undefined,'gamb-report-control');item.appendChild(node('strong',(c.failed?'Impedimento: ':'Verificado: ')+c.label));item.appendChild(node('p',c.description+' '+c.checked+' aplicação(ões), '+c.failed+' falha(s).'));if(c.failed)item.appendChild(node('p','Como proceder: '+c.how_to_fix));checks.appendChild(item);});box.appendChild(checks);
      var records=node('details');records.appendChild(node('summary','Documentos e etapas inspecionados ('+(r.records||[]).length+' registros; limite de 5.000)'));var ul=node('ul');(r.records||[]).forEach(function(f){ul.appendChild(node('li',f.source+' · '+(f.stage==='origin'?'buffer original':'HTML final')+' · '+f.checks+' controles · '+(f.success?'aprovado':(f.failures||[]).join(', '))));});records.appendChild(ul);box.appendChild(records);
      var limits=node('ul');(r.limitations||[]).forEach(function(t){limits.appendChild(node('li',t));});box.appendChild(node('h3','Limites da verificação'));box.appendChild(limits);
    }
    function files(p){var body=root.querySelector('[data-inventory-files]'),query=root.querySelector('#gamb-file-search').value.toLowerCase();body.replaceChildren();var rows=(p.files||[]).filter(function(f){return f.path.toLowerCase().includes(query);});rows.forEach(function(f){var tr=node('tr'),name=node('td');if(f.url){var link=node('a',f.path);link.href=f.url;link.target='_blank';link.rel='noopener noreferrer';name.appendChild(link);}else name.textContent=f.path;tr.appendChild(name);tr.appendChild(node('td',f.size===null?'—':new Intl.NumberFormat('pt-BR').format(f.size)+' bytes'));tr.appendChild(node('td',f.exists?(f.url?'Arquivo público':'Arquivo de configuração; sem link público'):'Ausente no disco'));body.appendChild(tr);});if(!rows.length){var td=node('td','Nenhum arquivo nesta página para o filtro informado.');td.colSpan=3;var tr=node('tr');tr.appendChild(td);body.appendChild(tr);}
      var pages=Math.max(1,Math.ceil(p.count/p.per_page));put('[data-files-page]','Página '+p.page+' de '+pages+' · até 100 arquivos por página');root.querySelector('[data-files-prev]').disabled=p.page<=1;root.querySelector('[data-files-next]').disabled=p.page>=pages;
    }
    function refresh(next){page=next||page;return api.request('insights?page='+page).then(function(d){saved=d;if(d.presentation)put('[data-public-site-status]',d.presentation.message);var p=d.publication||{},dep=d.dependencies||{};
      root.querySelectorAll('[data-publication-link],[data-publication-url]').forEach(function(a){a.hidden=!p.public_url;if(p.public_url){a.href=p.public_url;if(a.hasAttribute('data-publication-url'))a.textContent=p.public_url;}});
      put('[data-publication-location]','Destino salvo: '+(p.public_url||'URL pública não configurada')+' · '+(p.method==='local'?'Pasta: '+p.directory:'Entrega em ZIP')+' · Ambiente: '+p.environment);
      put('[data-inventory-location]','Pasta: '+(p.directory||'não configurada')+' · URL pública: '+(p.public_url||'não configurada'));
      put('[data-inventory-summary]',p.processed_units+' unidades na última execução; '+p.count+' arquivos no manifesto. '+p.message);
      list('[data-analysis-alerts]',d.alerts||[],'Nenhum alerta no diagnóstico registrado. Confira a data e a cobertura antes de publicar.');
      list('[data-installation-checks]',(d.installation.checks||[]).map(function(c){return Object.assign({},c,{label:({ok:'Verificado',warning:'Atenção',error:'Impedimento'}[c.status]||'Informação')+' · '+(c.id||''),how_to_fix:(d.alerts.find(function(a){return a.id===c.id;})||{}).how_to_fix});}),'Diagnóstico ainda não realizado.');put('[data-diagnostics-time]',time(d.installation.checked_at)||'Não realizada');
      report('publication',d.publication_audit);report('origin',d.origin_audit);var audit=d.publication_audit||{};put('[data-analysis-audit]',audit.checked_at?(audit.success?'Aprovada nos controles executados. ':'Há impedimentos. ')+(audit.documents||0)+' documento(s) final(is) aprovado(s). Verificação: '+time(audit.checked_at)+'.':d.previous_audit.checked_at?'Existe um relatório anterior sem distinção de escopo. Revalide a publicação para obter evidências detalhadas.':'A publicação ainda não possui relatório neste escopo.');
      put('[data-dependency-count]',String((dep.dependencies||[]).length));put('[data-navigation-count]',String(dep.external_navigation_count||0));put('[data-dependency-state]',dep.limited?'Inspeção incompleta':dep.checked_at||dep.scope?'Escopo limitado a HTML e CSS':'Ainda não verificado');put('[data-dependency-summary]',(dep.checked_at?'Verificação: '+time(dep.checked_at)+'. ':'')+(dep.scope||'Execute o diagnóstico ou uma publicação completa para atualizar a inspeção.')+(dep.limited?' O limite da inspeção foi atingido; o resultado não é completo.':''));
      var links=root.querySelector('[data-dependency-list]');links.replaceChildren();(dep.dependencies||[]).forEach(function(r){links.appendChild(node('li',r.kind+': '+r.url+(r.source?' · Encontrado em '+r.source:'')));});if(!(dep.dependencies||[]).length)links.appendChild(node('li','Nenhum recurso externo encontrado no escopo inspecionado.'));
      var nav=root.querySelector('[data-navigation-list]');nav.replaceChildren();(dep.external_navigation||[]).forEach(function(url){nav.appendChild(node('li',url));});nav.appendChild(node('li',(dep.external_navigation||[]).length?'Lista limitada a '+(dep.navigation_limit||200)+' endereços; parâmetros sensíveis são omitidos.':'O relatório anterior não contém a lista de URLs. Execute novamente o diagnóstico ou a publicação.'));
      root.querySelectorAll('[data-report-download]').forEach(function(a){a.href=d.downloads[a.dataset.reportDownload];a.removeAttribute('aria-disabled');});root.querySelector('[data-run-publication-audit]').disabled=p.method!=='local';files(p);proposed();return d;
    });}
    var projectButton=root.querySelector('[data-use-project-url]');
    var project=new URL(projectButton.dataset.projectUrl);
    var scheme=root.querySelector('[data-opt=destination_scheme]');
    var hostField=root.querySelector('[data-opt=destination_host]');
    hostField.placeholder=project.host;
    function proposed(){
      var get=function(key){return root.querySelector('[data-opt="'+key+'"]').value;};
      var host=hostField.value.trim(), trim=function(value){return value.replace(/^\/+|\/+$/g,'');};
      var savedBox=root.querySelector('[data-saved-address]');
      var savedUrl=root.querySelector('[data-public-url]')?root.querySelector('[data-public-url]').defaultValue:savedBox.querySelector('a').textContent.trim();
      var url, invalid;
      try{
        var rawHost=host.split('/')[0];
        var target=new URL('https://'+(rawHost||project.host));
        var name=target.hostname.toLowerCase().replace(/\.$/,'');
        var projectHost=project.hostname.toLowerCase().replace(/\.$/,'');
        var local=/^(localhost|\[::1\]|127\.\d+\.\d+\.\d+)$|\.(?:localhost|local|test)$/.test(name);
        if(!local && !/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)/.test(name) && scheme.value==='http://')throw Error('Use HTTPS para o destino público.');
        var homePath=trim(project.pathname), hostPath=trim(host.slice(rawHost.length));
        var relative=trim(get('relative_path'));
        if(!host&&!relative&&get('delivery_method')==='local')relative=encodeURIComponent(trim(path.value.replace(/\\/g,'/')).split('/').pop());
        var publicPath=hostPath||relative;
        if(name!==projectHost&&projectHost.endsWith('.'+name))throw Error('Use o subdomínio da instalação.');
        if(name===projectHost&&homePath&&publicPath!==homePath&&!publicPath.startsWith(homePath+'/')){
          if(hostPath)throw Error('Use um caminho dentro da instalação.');
          publicPath=homePath+(publicPath?'/'+publicPath:'');
        }
        url=scheme.value+name+(target.port?':'+target.port:'')+'/'+(publicPath?publicPath+'/':'');
      }catch(error){invalid=error.message;}
      var changed=Boolean(url&&savedUrl&&url!==savedUrl);
      savedBox.hidden=!changed;
      put('[data-proposed-url]',invalid||(changed?'Endereço proposto: '+url+' · Salve para aplicar.':'Endereço público: '+url));
    }
    ['destination_host','relative_path','destination_scheme','delivery_method','local_dir'].forEach(function(key){root.querySelector('[data-opt="'+key+'"]').addEventListener('input',proposed);});
    proposed();
    function invalidate(){create.disabled=false;put('[data-destination-result]','Caminho alterado. Verifique ou crie a pasta antes de salvar.');}
    var publicInput=root.querySelector('[data-public-url]');
    function applyPublicUrl(){if(!publicInput)return;try{var u=new URL(publicInput.value);if(!['http:','https:'].includes(u.protocol)||u.username||u.password||u.search||u.hash)throw Error('Informe uma URL HTTP/HTTPS completa, sem credenciais ou parâmetros.');publicInput.setCustomValidity('');hostField.value=u.host;scheme.value=u.protocol+'//';root.querySelector('[data-opt=relative_path]').value=u.pathname.replace(/^\/+|\/+$/g,'');proposed();}catch(error){publicInput.setCustomValidity(error.message);}}
    if(publicInput)publicInput.addEventListener('input',applyPublicUrl);
    root.querySelector('[data-use-project-url]').addEventListener('click',function(){var u=new URL(this.dataset.projectUrl);if(publicInput)publicInput.value=u.href;root.querySelector('[data-opt=destination_host]').value=u.host;root.querySelector('[data-opt=destination_scheme]').value=u.protocol+'//';root.querySelector('[data-opt=relative_path]').value=u.pathname.replace(/^\/+|\/+$/g,'');root.querySelector('[data-opt=destination_url_type]').value='relative';if(publicInput)publicInput.setCustomValidity('');proposed();});
    path.addEventListener('input',invalidate);root.querySelector('[data-destination-suggest]').addEventListener('click',function(){path.value=api.config.suggestedDirectory;invalidate();proposed();});
    function destination(make){var requested=path.value;return api.task('destination','destination',{path:requested,create:make},function(d){if(path.value!==requested)return;path.value=d.path;proposed();put('[data-destination-result]',d.message+' Caminho: '+d.path);create.disabled=d.exists;},function(e){if(path.value===requested){create.disabled=false;put('[data-destination-result]','Pasta recusada: '+e.message);}});}
    root.querySelector('[data-destination-check]').addEventListener('click',function(){destination(false);});create.addEventListener('click',function(){destination(true);});
    root.querySelector('[data-run-publication-audit]').addEventListener('click',function(){api.task('publication-audit','publication-audit',{},function(){return refresh(1);});});
    root.querySelectorAll('[data-open-panel]').forEach(function(b){b.addEventListener('click',function(){var tab=root.querySelector('[data-tab="'+b.dataset.openPanel+'"]');api.select(tab,true);root.querySelector('#'+tab.getAttribute('aria-controls')).focus({preventScroll:true});});});
    root.querySelector('#gamb-file-search').addEventListener('input',function(){if(saved)files(saved.publication);});root.querySelector('[data-files-prev]').addEventListener('click',function(){api.load('Inventário da publicação',function(){return refresh(Math.max(1,page-1));});});root.querySelector('[data-files-next]').addEventListener('click',function(){api.load('Inventário da publicação',function(){return refresh(page+1);});});
    root.querySelectorAll('[data-report-download]').forEach(function(a){a.setAttribute('aria-disabled','true');a.addEventListener('click',function(e){if(!a.hasAttribute('href'))e.preventDefault();});});
    function help(field,key,label){var info=(api.config.fieldHelp||{})[key];if(!info||!label)return;var wrap=node('div',undefined,'gamb-field-heading'),button=node('button','i','gamb-info'),description=node('div',undefined,'gamb-field-help'),id='gamb-help-'+key;if(label.tagName==='LEGEND'){wrap=label;wrap.appendChild(button);}else{label.parentNode.insertBefore(wrap,label);wrap.appendChild(label);wrap.appendChild(button);}button.type='button';button.title='Detalhes e exemplo';button.setAttribute('aria-label','Informações sobre '+label.textContent.trim());button.setAttribute('aria-expanded','false');button.setAttribute('aria-controls',id);description.id=id;description.hidden=true;description.appendChild(node('p',info.description));description.appendChild(node('p','Exemplo: '+info.example));wrap.insertAdjacentElement('afterend',description);if(field)field.setAttribute('aria-describedby',((field.getAttribute('aria-describedby')||'')+' '+id).trim());button.addEventListener('click',function(){description.hidden=!description.hidden;button.setAttribute('aria-expanded',String(!description.hidden));});}
    root.querySelectorAll('[data-opt]').forEach(function(f){help(f,f.dataset.opt,(f.labels||[])[0]||f.closest('label'));});help(publicInput,'public_url',root.querySelector('label[for=gamb-public-url]'));help(null,'post_types',root.querySelector('#panel-conversion legend'));help(root.querySelector('#gamb-quick-scope'),'generate_type',root.querySelector('label[for=gamb-quick-scope]'));
    return {refresh:refresh};
  };
})();
