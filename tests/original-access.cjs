const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const events={},dialogEvents={},controls={},posts=[];
function control(){return {disabled:false,textContent:'',attributes:{},setAttribute(k,v){this.attributes[k]=v},addEventListener(k,fn){this[k]=fn},focus(){}}}
for(const name of ['error','confirm','cancel'])controls[name]=control();
const dialog={open:false,querySelector(s){return controls[/original-(\w+)/.exec(s)[1]]},showModal(){this.open=true},close(){this.open=false},addEventListener(k,fn){dialogEvents[k]=fn}};
const toggle=control();toggle.closest=()=>toggle;
const document={readyState:'complete',body:{classList:{toggle(){}},appendChild(){}},querySelector(s){return s==='#gamb-original-confirm'?dialog:null},querySelectorAll(){return []},addEventListener(k,fn){events[k]=fn}};
const window={gambOriginalAccess:{status:{enabled:false},endpoint:'https://example.test/api',nonce:'session-nonce',isOriginal:false},location:{assign(){}}};
const context={document,window,Date,clearTimeout(){},setTimeout(){},fetch:async(url,args)=>{posts.push(args);return {ok:false,json:async()=>({message:'erro controlado'})}}};
vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/original-access.js','utf8'),context);
(async()=>{
 events.click({target:toggle,preventDefault(){}});assert.equal(dialog.open,true);assert.equal(posts.length,0,'Abrir confirmação não ativa acesso.');
 controls.cancel.click();assert.equal(dialog.open,false);assert.equal(posts.length,0,'Cancelar não envia pedido.');
 events.click({target:toggle,preventDefault(){}});controls.confirm.click();await new Promise(setImmediate);
 assert.equal(posts.length,1);assert.deepEqual(JSON.parse(posts[0].body),{enabled:true,confirmed:true});assert.equal(posts[0].credentials,'same-origin');assert.equal(posts[0].cache,'no-store');assert.equal(posts[0].headers['X-WP-Nonce'],'session-nonce');assert.equal(controls.error.textContent,'erro controlado');
 console.log('Modal, cancelamento, confirmação explícita e nonce aprovados.');
})().catch(error=>{console.error(error);process.exitCode=1});
