// Artefato de auditoria: executa o build atual sem alterar fontes/flags.
// Todas as APIs abaixo são simuladas, em localhost; nenhum pagamento é criado.
import { createServer } from 'node:http';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { resolve, dirname, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { withBrowser } from '../../feitoo-web/scripts/browser-smoke.mjs';

const output = dirname(fileURLToPath(import.meta.url));
const root = resolve(output, '../../feitoo-web/dist/account-audit/browser');
await mkdir(resolve(output, 'screenshots'), { recursive: true });
const requests = [], results = [], interactions = [];
let scenario = 'free-sem-creditos';
const now = new Date();
const future = new Date(+now + 25 * 86400000).toISOString();
const previous = new Date(+now - 5 * 86400000).toISOString();
const orderId = '12345678-1234-4234-8234-123456789abc';
const user = { id: 900001, name: 'Pessoa de Demonstração', email: 'auditoria@example.test', google_linked: false, has_password: true, created_at: previous };
const statusFor = () => ({ 'pro-ativo':'active', 'pro-pagamento-pendente':'past_due', 'assinatura-incomplete':'incomplete', 'assinatura-cancelada':'canceled', 'cancelamento-agendado':'active', 'periodo-expirado':'active', 'incomplete-expired':'incomplete_expired', 'assinatura-unpaid':'unpaid', 'assinatura-paused':'paused', 'assinatura-trialing':'trialing' })[scenario];
const proActive = () => ['pro-ativo', 'cancelamento-agendado'].includes(scenario);
const quota = () => ({ source: proActive() ? 'pro' : 'free', policy:'explicit_source', enabled:true, daily_limit:1, remaining:scenario === 'free-com-creditos' ? 1 : 0, reserved:0, consumed:scenario === 'free-com-creditos' ? 0 : 1, resets_at:new Date(+now+86400000).toISOString(), active_processing:null, latest_processing:null, credits:{ granted:scenario === 'free-com-creditos' ? 5 : 0, available:scenario === 'free-com-creditos' ? 5 : 0, consumed:0, reserved:0, revoked:0 }, pro: proActive() ? { active:true, remaining:83, limit:100, reserved:2, consumed:15, period_end:future } : {active:false, remaining:0}, offer:{available:scenario !== 'oferta-indisponivel', credits:5, amount:990, currency:'brl', code:'quotation_5'} });
const order = (i=0) => ({ id:i ? `order-audit-${i}` : orderId, credits:5, amount:990, currency:'brl', status:scenario === 'compra-pendente' ? 'pending' : ['paid','pending','failed','cancelled','refunded','disputed'][i%6], checkout_url:null, created_at:previous, paid_at:null, refunded_amount:0 });
const history = () => Array.from({length:scenario === 'historico-muitos' ? 10 : 0}, (_,i) => ({id:`hist-audit-${i}`, tool:['xml-to-excel','quotation-generator','products-from-xml','docx_to_pdf'][i%4], status:i%3 === 1 ? 'failed' : 'completed', created_at:previous, expires_at:new Date(+now + (i%3 === 0 ? 86400000 : -86400000)).toISOString(), files_count:i%4 === 1 ? 1 : 3, successful_files_count:i%3 === 1 ? 0 : 3, failed_files_count:i%3 === 1 ? 3 : 0, total_value:i%4 === 0 ? '1234.50' : null, files_deleted:false, can_delete_files:true, result:{available:i%3 === 0, file_name:'demonstracao.xlsx'}}));
const server = createServer(async (req,res) => {
  const path = new URL(req.url, 'http://localhost:8000').pathname;
  const activeScenario = scenario;
  const json = (status,data) => { if (res.destroyed) return; res.setHeader('Content-Type','application/json; charset=utf-8'); res.setHeader('Cache-Control','no-store, private'); res.writeHead(status).end(JSON.stringify(data)); };
  try {
    if (path.startsWith('/api/') || path.startsWith('/sanctum/')) {
      requests.push({scenario:activeScenario, path:req.url, method:req.method});
      if (path === '/sanctum/csrf-cookie') {res.setHeader('Set-Cookie','XSRF-TOKEN=auditoria-simulada; Path=/; SameSite=Lax');return res.writeHead(204).end();}
      if (path === '/api/health') return json(200,{status:'ok',service:'feitoo-api'});
      if (path === '/api/me') return json(scenario === 'sessao-expirada' ? 401 : 200, {data:user});
      if (scenario === 'loading') await new Promise(done => setTimeout(done,6000));
      if (activeScenario === 'falha-api') return json(503,{message:'Falha simulada de API'});
      if (activeScenario === 'sessao-expira-durante-uso' && path === '/api/account/subscription/checkout') return json(401,{message:'Autenticação necessária.'});
      if (path === '/api/account/usage') return json(200,{data:{plan:proActive() ? 'pro' : 'free', limits:{per_processing:proActive() ? 250 : 3, daily:proActive() ? 500 : 6, used_today:2, remaining_today:proActive() ? 498 : 4, resets_at:new Date(+now+86400000).toISOString()}}});
      if (path === '/api/account/subscription') {const status = statusFor(); return json(200,{data:{offer:{amount:2990,currency:'brl',per_processing:250,daily:500,available:scenario !== 'oferta-indisponivel'}, subscription:status ? {status, current_period_end:scenario === 'periodo-expirado' ? previous : future, cancel_at_period_end:scenario === 'cancelamento-agendado', grants_pro:proActive()} : null}});}
      if (path === '/api/account/subscription/checkout' || path === '/api/account/subscription/portal') return json(503,{code:'checkout_unavailable',message:'Indisponibilidade simulada'});
      if (path === '/api/account/purchases') return json(200,{data:scenario === 'historico-muitos' ? Array.from({length:10},(_,i)=>({id:`xml-audit-${i}`, processing_id:`processing-audit-${i}`,tool:'xml-to-excel',package_code:'xml_100',files_limit:100,files_count:50,amount:490,currency:'brl',status:['paid','pending','failed','expired','refunded'][i%5],consumed:false,can_resume_processing:false,paid_at:null,expires_at:future,created_at:previous})) : [],meta:{current_page:Number(new URL(req.url,'http://localhost').searchParams.get('page')||1),last_page:scenario === 'historico-muitos' ? 3 : 1}});
      if (path === '/api/processings') return json(200,{data:history(),meta:{current_page:Number(new URL(req.url,'http://localhost').searchParams.get('page')||1),last_page:scenario === 'historico-muitos' ? 5 : 1,total:scenario === 'historico-muitos' ? 50 : 0,per_page:10}});
      if (path === '/api/quotations/entitlements') return json(200,{data:quota()});
      if (path === '/api/quotations/credit-orders') return json(200,{data:scenario === 'compra-pendente' ? [order()] : scenario === 'historico-muitos' ? Array.from({length:20},(_,i)=>order(i)) : [],meta:{current_page:Number(new URL(req.url,'http://localhost').searchParams.get('page')||1),last_page:scenario === 'historico-muitos' ? 4 : 1}});
      if (path === '/api/quotations/drafts') return json(200,{data:null});
      return json(501,{message:'Endpoint não simulado',path});
    }
    const file = resolve(root, '.'+decodeURIComponent(path));
    if (!file.startsWith(root)) return res.writeHead(403).end();
    const actual = extname(path) ? file : resolve(root,'index.csr.html');
    const content = await readFile(actual);
    res.setHeader('Content-Type',({'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.svg':'image/svg+xml','.png':'image/png','.woff2':'font/woff2'})[extname(actual)] || 'application/octet-stream');
    res.writeHead(200).end(content);
  } catch(error) { res.writeHead(404).end('Local audit: '+error.message); }
});
await new Promise((done,reject)=>{server.once('error',reject);server.listen(8000,'127.0.0.1',done);});
const matrix = ['free-sem-creditos','free-com-creditos','pro-ativo','pro-pagamento-pendente','compra-pendente','falha-api','historico-vazio','historico-muitos','loading','sessao-expirada'];
const extra = ['assinatura-incomplete','assinatura-cancelada','cancelamento-agendado','periodo-expirado','incomplete-expired','assinatura-unpaid','assinatura-paused','assinatura-trialing','oferta-indisponivel'];
try {
  await withBrowser('auditoria-minha-conta',async ({command,evaluate,waitFor,errors})=>{
    await command('Network.setBlockedURLs',{urls:['*stripe.com*','*api.feitoo.com.br*','*google-analytics.com*','*googletagmanager.com*']});
    const metrics = () => evaluate(`(() => {const visible=e=>!!(e.offsetWidth||e.offsetHeight||e.getClientRects().length); const rect=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y+scrollY,width:r.width,height:r.height}};return {url:location.href,width:innerWidth,scrollWidth:document.documentElement.scrollWidth,height:document.documentElement.scrollHeight,headings:[...document.querySelectorAll('main h1,main h2,main h3')].filter(visible).map(e=>({text:e.innerText,level:e.tagName,...rect(e)})),buttons:[...document.querySelectorAll('main button, main a.btn')].filter(visible).map(e=>({text:e.innerText,disabled:e.disabled||false,...rect(e)})),overflows:[...document.querySelectorAll('body *')].filter(e=>visible(e)&&e.getBoundingClientRect().right>innerWidth+1).slice(0,25).map(e=>({tag:e.tagName,class:e.className,text:e.innerText?.slice(0,60),...rect(e)})),paragraphs:document.querySelectorAll('app-account-page p').length,words:document.querySelector('app-account-page')?.innerText.split(/\\s+/).length||0,bodyText:document.querySelector('main')?.innerText};})()`);
    const navigate = async (state,width,url='/conta') => {scenario=state; await command('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});await command('Page.navigate',{url:'http://localhost:8000'+url});await waitFor(state==='sessao-expirada' ? `!!document.querySelector('app-auth-page')` : `!!document.querySelector('app-account-page')`);if(state!=='loading' && state!=='sessao-expirada')await waitFor(`!document.querySelector('app-account-page').innerText.includes('Consultando seu plano')`);await new Promise(done=>setTimeout(done,state==='loading'?250:300));await evaluate('window.scrollTo(0,0)');};
    const capture = async (name,width) => {const m=await metrics();const shot=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width,height:m.height,scale:1}});await writeFile(resolve(output,'screenshots',`${name}-${width}.png`),Buffer.from(shot.data,'base64'));results.push({scenario:name,...m});};
    for(const state of [...matrix,...extra]) {
      for(const width of matrix.includes(state)?[1440,1024,768,390,320]:[1440,390]) {
        await navigate(state,width);await capture(state,width);
      }
      console.log('Capturado:',state);
    }
    await navigate('oferta-indisponivel',390);
    interactions.push({test:'oferta-indisponivel-cta',before:await metrics()});
    await evaluate(`[...document.querySelectorAll('main button')].find(e=>e.innerText==='Assinar Feitoo Pro').click()`);
    await waitFor(`document.querySelector('main').innerText.includes('Não foi possível abrir a Stripe')`);
    await capture('erro-checkout-pro',390);
    await navigate('pro-ativo',390);
    await evaluate(`[...document.querySelectorAll('main button')].find(e=>e.innerText==='Gerenciar assinatura').click()`);
    await waitFor(`document.querySelector('main').innerText.includes('Não foi possível abrir a Stripe')`);
    await capture('erro-portal',390);
    await navigate('free-sem-creditos',390);
    await evaluate(`document.querySelector('[aria-labelledby="buy-credits-title"] a.btn').click()`);
    await waitFor(`!!document.querySelector('app-quotation-page')`);
    await new Promise(done=>setTimeout(done,700));
    await capture('editor-via-comprar-creditos-flag-atual',390);
    interactions.push({test:'editor-via-conta',result:await metrics()});
    await navigate('free-sem-creditos',390);
    const beforeHash=requests.length;
    await evaluate(`document.querySelector('.account-navigation a[href="#buy-credits-title"]').click()`);
    await new Promise(done=>setTimeout(done,300));
    interactions.push({test:'ancora-comprar-creditos',result:await metrics(),requestsAfterClick:requests.slice(beforeHash)});
    await navigate('historico-muitos',390);
    const histBefore=requests.length;
    await evaluate(`[...document.querySelectorAll('.history-pagination button')].find(e=>e.innerText==='Próxima').click()`);
    await waitFor(`document.querySelector('.history-pagination').innerText.includes('Página 2')`);
    interactions.push({test:'paginacao-historico',requests:requests.slice(histBefore)});
    await evaluate(`document.querySelector('.history-actions button.btn-outline-secondary').click()`);
    await waitFor(`!!document.querySelector('.delete-confirmation')`);
    interactions.push({test:'foco-confirmacao-exclusao-sem-excluir',active:await evaluate(`document.activeElement.innerText`)});
    await evaluate(`[...document.querySelectorAll('.delete-confirmation button')].find(e=>e.innerText==='Cancelar').click()`);
    interactions.push({test:'foco-retorno-exclusao',active:await evaluate(`document.activeElement.innerText`)});
    await navigate('free-sem-creditos',390);
    await evaluate(`document.querySelector('.password-disclosure summary').focus()`);
    await command('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
    await command('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
    interactions.push({test:'senha-teclado-enter',open:await evaluate(`document.querySelector('.password-disclosure').open`)});
    await command('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    await command('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    interactions.push({test:'senha-tab-foco',active:await evaluate(`({id:document.activeElement.id,outline:getComputedStyle(document.activeElement).outline})`)});
    await navigate('free-sem-creditos',390,'/conta?subscription=cancelled');
    await capture('retorno-pro-cancelled',390);
    await navigate('assinatura-incomplete',390,'/conta?subscription=confirming');
    await capture('retorno-pro-confirming',390);
    await evaluate(`[...document.querySelectorAll('main button')].find(e=>e.innerText==='Parar confirmação automática').click()`);
    await navigate('pro-ativo',390,'/conta?subscription=confirming');
    await capture('retorno-pro-ativo',390);
    await navigate('sessao-expira-durante-uso',390);
    await evaluate(`[...document.querySelectorAll('main button')].find(e=>e.innerText==='Assinar Feitoo Pro').click()`);
    await waitFor(`document.querySelector('main').innerText.includes('Não foi possível abrir a Stripe')`);
    await capture('sessao-expira-no-checkout',390);
    interactions.push({test:'sessao-expira-no-checkout',result:await metrics()});
    const colors=await evaluate(`(() => {let cs=getComputedStyle(document.body);return {text:cs.color,background:cs.backgroundColor,muted:getComputedStyle(document.querySelector('.text-secondary')).color};})()`);
    await writeFile(resolve(output,'browser-report.json'),JSON.stringify({generatedAt:new Date().toISOString(),notice:'Screenshots reais do Edge; APIs e identidades simuladas; flags e código-fonte preservados. Viewport de layout, sem emulação de aparelho físico.',build:'development',quotationEnabled:false,results,interactions,requests,errors,colors},null,2));
    console.log(JSON.stringify({screenshots:results.length,runtimeErrors:errors,overflows:results.filter(r=>r.scrollWidth>r.width).map(r=>({scenario:r.scenario,width:r.width,scrollWidth:r.scrollWidth})),requests:requests.length}));
  });
}finally{server.closeAllConnections();await new Promise(done=>server.close(done));}
