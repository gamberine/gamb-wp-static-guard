(function(){
  'use strict';
  window.gambDestinationAudit=function(api){
    var root=api.root,box=root.querySelector('[data-destination-audit-report]'),message=root.querySelector('[data-destination-audit-message]');
    var labels={approved:'Aprovado',attention:'Atenção',failed:'Falhou',unverified:'Não verificado'},groups={publication:'Publicação: canonical e indexação',export:'Exportação: arquivos e integridade',server:'Servidor: HTTP e cabeçalhos'};
    function node(tag,text,className){var el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(className)el.className=className;return el;}
    function time(value){return window.gambAdminTime?window.gambAdminTime(value):value||'Não informada';}
    function render(data){box.replaceChildren();var r=data.report||{},c=(data.connectors||{}).tenable||{};
      root.querySelector('[data-tenable-enabled]').checked=!!c.enabled;root.querySelector('[data-tenable-scan]').value=c.scan_id||'';root.querySelector('[data-tenable-readonly]').checked=!!c.readonly_confirmed;
      root.querySelector('[data-tenable-status]').textContent='Credenciais no servidor: '+(c.credentials_configured?'configuradas':'não configuradas')+'. Associação: '+(c.target||'nenhuma')+' · '+(c.environment||'ambiente não associado')+'.';
      if(!r.checked_at){message.textContent='Nenhuma auditoria defensiva executada. O botão usa exclusivamente o destino salvo.';return;}
      message.textContent='Auditoria local: '+time(r.checked_at)+' · Plugin '+r.version;
      var d=r.destination||{},e=r.export||{};box.appendChild(node('p','Destino auditado: '+d.url+' · Ambiente: '+d.environment,'gamb-destination-summary'));
      box.appendChild(node('p','Exportação: '+time(e.published_at)+' · Pasta: '+d.directory+' · '+e.processed_units+' unidades · '+e.manifest_files+' arquivos no manifesto.'));
      box.appendChild(node('p','Identificação do manifesto SHA-256: '+(e.manifest_sha256||'não disponível'),'gamb-wrap-anywhere'));
      var stats=node('dl',undefined,'gamb-stats');Object.keys(labels).forEach(function(key){var n=node('div');n.appendChild(node('dt',labels[key]));n.appendChild(node('dd',String((r.summary||{})[key]||0)));stats.appendChild(n);});box.appendChild(stats);
      box.appendChild(node('p',r.reference));
      Object.keys(groups).forEach(function(group){box.appendChild(node('h3',groups[group]));var count=0;(r.checks||[]).filter(function(c){return c.group===group;}).forEach(function(c){count++;var row=node('details'),title=node('summary',labels[c.state]+' · '+c.id+(c.path?' · '+c.path:''));row.appendChild(title);[['Esperado',c.expected],['Evidência',c.observed],['Como proceder',c.action],['Referência',c.reference||'Critério defensivo de publicação/operação']].forEach(function(pair){var p=node('p');p.appendChild(node('strong',pair[0]+': '));p.appendChild(node('span',pair[1]));row.appendChild(p);});if(c.state==='failed')row.open=true;box.appendChild(row);});if(!count)box.appendChild(node('p','Não verificado neste contexto.'));});
      var http=node('details');http.appendChild(node('summary','Amostra HTTP: status e cabeçalhos observados'));Object.keys(r.http||{}).forEach(function(path){var item=r.http[path];http.appendChild(node('h4',path));http.appendChild(node('pre',item.verified?'HTTP '+item.status+'\n'+Object.keys(item.headers).map(function(k){return k+': '+item.headers[k];}).join('\n'):item.reason,'gamb-monitor-result'));});box.appendChild(http);
      box.appendChild(node('h3','Relatórios externos existentes'));
      (r.integrations||[]).forEach(function(i){var row=node('div',undefined,'gamb-callout');row.appendChild(node('strong',i.provider+' · '+labels[i.state]));row.appendChild(node('p','Alvo associado: '+i.target+' · Ambiente: '+i.environment));row.appendChild(node('p','Data da avaliação externa: '+time(i.report_at)+' · Consulta: '+time(i.queried_at)));if(i.report_generated_at)row.appendChild(node('p','Arquivo externo gerado em: '+time(i.report_generated_at)));row.appendChild(node('p',i.reason));if(i.findings&&Object.keys(i.findings).length)row.appendChild(node('p',Object.keys(i.findings).map(function(k){return k+': '+i.findings[k];}).join(' · ')));box.appendChild(row);});
      var limits=node('ul');(r.limitations||[]).forEach(function(text){limits.appendChild(node('li',text));});box.appendChild(node('h3','Limites e cobertura'));box.appendChild(limits);
    }
    function refresh(){return api.request('destination-audit').then(render);}
    root.querySelector('[data-run-destination-audit]').addEventListener('click',function(){api.task('destination-audit','destination-audit',{},render,function(e){message.textContent=e.message+' O resultado anterior foi preservado.';});});
    root.querySelector('[data-save-audit-connectors]').addEventListener('click',function(){api.task('audit-connectors','audit-connectors',{enabled:root.querySelector('[data-tenable-enabled]').checked,scan_id:root.querySelector('[data-tenable-scan]').value.trim(),readonly_confirmed:root.querySelector('[data-tenable-readonly]').checked},refresh,function(e){message.textContent=e.message;});});
    return {refresh:refresh};
  };
})();
