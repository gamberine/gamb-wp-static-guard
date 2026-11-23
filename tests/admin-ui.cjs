const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname,'../assets/admin-ui.js'),'utf8');
// Pequena fixture DOM: verifica contratos dos controles sem acessar servidor ou backups reais.
function element(tag) {
  return {tag,children:[],dataset:{},attributes:{},listeners:{},textContent:'',title:'',
    appendChild(child){this.children.push(child);return child;},replaceChildren(){this.children=[];},
    setAttribute(name,value){this.attributes[name]=value;},addEventListener(name,callback){this.listeners[name]=callback;}};
}
const nodes={};
for(const selector of ['[data-publication-badge]','[data-publication-message]','[data-section-badge="static"]','[data-section-badge="guard"]','[data-backup-list]'])nodes[selector]=element('div');
const publication={label:'Atualizado',tone:'success',message:'Entrega registrada.',sections:{static:{label:'Atenção',tone:'warning',message:'Diagnóstico com alerta.'},guard:{label:'Impedimento',tone:'error',message:'Auditoria reprovada.'}}};
const rows=[{filename:'fixture.sql',created_at:'23/11/2026',size_formatted:'1 MB',download_url:'https://example.test/download?file=fixture.sql'}];
let confirmations=0,deletions=0;
const context=vm.createContext({
  root:{querySelector:selector=>nodes[selector]},document:{createElement:element},busy:false,
  request:async route=>{assert(['publication-status','backups'].includes(route));return route==='backups'?rows:publication;},
  confirm:()=>{confirmations++;return false;},task:()=>{deletions++;},
});
for(const name of ['sectionBadges','publication','backups']){
  const line=source.split('\n').find(line=>line.startsWith('    function '+name+'('));
  assert(line,'Função ausente: '+name);vm.runInContext(line,context);
}
(async()=>{
  await context.publication();
  assert.equal(nodes['[data-section-badge="static"]'].dataset.tone,'warning');
  assert.equal(nodes['[data-section-badge="guard"]'].textContent,'Impedimento');
  assert.equal(nodes['[data-section-badge="guard"]'].title,'Auditoria reprovada.');
  assert.equal(nodes['[data-publication-badge]'].textContent,'Atualizado');
  context.sectionBadges(undefined);
  publication.sections.guard={label:'Verificado',tone:'success',message:'Auditoria aprovada.'};
  await context.publication();assert.equal(nodes['[data-section-badge="guard"]'].dataset.tone,'success');
  await context.backups();
  const table=nodes['[data-backup-list]'].children[0],row=table.children[1].children[0];
  const cell=row.children[3],controls=cell.children[0],[download,remove]=controls.children;
  assert.equal(cell.tag,'td');assert.equal(controls.className,'gamb-backup-actions');
  assert.equal(download.className,'button');assert.equal(remove.className,'button');
  assert.equal(download.href,rows[0].download_url);
  assert.equal(download.attributes['aria-label'],'Baixar fixture.sql');
  assert.equal(remove.attributes['aria-label'],'Excluir fixture.sql');
  remove.listeners.click();assert.equal(confirmations,1);assert.equal(deletions,0,'Não excluir após cancelamento.');
  context.busy=true;remove.listeners.click();assert.equal(confirmations,1,'Não iniciar exclusão durante outra operação.');
  rows.length=0;await context.backups();assert.equal(nodes['[data-backup-list]'].children.length,0);assert.equal(nodes['[data-backup-list]'].textContent,'Nenhuma cópia de segurança registrada.');
  console.log('Regressões de badges, controles de backup e confirmação aprovadas.');
})().catch(error=>{console.error(error);process.exitCode=1;});
