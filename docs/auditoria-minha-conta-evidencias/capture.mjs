// Audit-only harness. Run from monorepo root. No production API or Stripe access.
import { createServer } from 'node:http';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { resolve, extname } from 'node:path';
import { withBrowser } from '../../feitoo-web/scripts/browser-smoke.mjs';

const out = resolve('docs/auditoria-minha-conta-evidencias');
const root = resolve('feitoo-web/dist/account-audit/browser');
await mkdir(out, { recursive: true });
let scenario = 'free-zero', mode = 'normal';
const extras = process.argv.includes('--extras') || process.env.FEITOO_ACCOUNT_AUDIT_EXTRAS === '1';
const previous = async name => extras ? JSON.parse(await readFile(resolve(out, name + '.json'), 'utf8')) : [];
const log = await previous('requests'), measurements = await previous('measurements'), checks = await previous('checks');
const user = { id: 900001, name: 'Conta de auditoria simulada', email: 'auditoria@example.test', has_password: true, google_linked: false, created_at: '2026-10-01T12:00:00Z' };
const orderId = '12345678-1234-4234-8234-123456789abc';
function subscription() {
  const states = { 'pro-active': 'active', 'pro-pending': 'incomplete', 'past-due': 'past_due', 'unpaid': 'unpaid', 'canceled': 'canceled', 'incomplete-expired': 'incomplete_expired', 'scheduled': 'active', 'period-expired': 'active', 'paused': 'paused', 'trialing': 'trialing', 'return-confirming': 'incomplete', 'return-cancelled': 'incomplete' };
  const status = states[scenario];
  return status ? { status, grants_pro: ['pro-active', 'scheduled'].includes(scenario), current_period_end: scenario === 'period-expired' ? '2026-10-01T00:00:00-03:00' : '2026-11-28T20:00:00-03:00', cancel_at_period_end: scenario === 'scheduled' } : null;
}
function quota() {
  const active = subscription()?.grants_pro ?? false;
  const credits = scenario === 'free-credits' ? 5 : 0;
  return { source: active ? 'pro' : 'free', policy: 'explicit_source', enabled: true, daily_limit: 1, remaining: scenario === 'free-zero' ? 1 : 0, reserved: 0, consumed: scenario === 'free-zero' ? 0 : 1, resets_at: '2026-10-30T00:00:00-03:00', active_processing: null, latest_processing: null, credits: { granted: credits, available: credits, reserved: 0, consumed: 0, revoked: 0 }, pro: { active, limit: 100, remaining: active ? 82 : 0, reserved: 0, consumed: active ? 18 : 0, period_end: '2026-11-28T20:00:00-03:00' }, offer: { available: scenario !== 'offer-unavailable', credits: 5, amount: 990, code: 'quotation_5', currency: 'brl' } };
}
function history(page) {
  return Array.from({ length: 10 }, (_, i) => ({ id: `00000000-0000-4000-8000-${String(page * 10 + i).padStart(12, '0')}`, tool: ['xml-to-excel', 'products-from-xml', 'docx_to_pdf', 'quotation-generator'][i % 4], status: 'completed', created_at: '2026-10-28T15:00:00-03:00', expires_at: '2099-01-01T00:00:00Z', files_count: i % 4 === 3 ? 1 : 3, successful_files_count: i % 4 === 3 ? 1 : 3, failed_files_count: 0, total_value: i % 4 < 2 ? '12345.67' : null, products_summary: i % 4 === 1 ? { items_count: 12, products_count: 8, total_value: '12345.67' } : null, files_deleted: false, can_delete_files: true, result: { available: true, file_name: 'resultado-simulado.pdf' } }));
}
const api = createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost:8000');
  res.setHeader('Access-Control-Allow-Origin', req.headers.origin || 'http://localhost:4317');
  res.setHeader('Access-Control-Allow-Credentials', 'true');
  res.setHeader('Access-Control-Allow-Headers', 'Accept,Content-Type,X-Quotation-Owner,Idempotency-Key,X-XSRF-TOKEN');
  res.setHeader('Access-Control-Allow-Methods', 'GET,POST,PATCH,DELETE,OPTIONS');
  if (req.method === 'OPTIONS') return res.writeHead(204).end();
  log.push({ scenario, mode, method: req.method, path: url.pathname, query: url.search, owner: req.headers['x-quotation-owner'] ?? null });
  const json = (status, value) => { res.setHeader('Content-Type', 'application/json'); res.setHeader('Cache-Control', 'no-store, private'); res.writeHead(status).end(JSON.stringify(value)); };
  if (url.pathname === '/api/health') return json(200, { status: 'ok', service: 'feitoo-api' });
  if (url.pathname === '/sanctum/csrf-cookie') { res.setHeader('Set-Cookie', 'XSRF-TOKEN=synthetic-csrf; Path=/; SameSite=Lax'); return res.writeHead(204).end(); }
  if (url.pathname === '/api/me') return json(scenario === 'session-expired' ? 401 : 200, { data: user });
  if (scenario === 'loading') await new Promise(r => setTimeout(r, 6500));
  if (scenario === 'api-error' || (scenario === 'subscription-error' && url.pathname === '/api/account/subscription')) return json(500, { message: 'Falha sintética de API.' });
  if (mode === 'quota-error' && url.pathname === '/api/quotations/entitlements') return json(500, { message: 'Falha sintética de saldo.' });
  if (mode === 'billing-expired' && url.pathname.startsWith('/api/account/subscription')) return json(401, { message: 'Sessão simulada expirada.' });
  if (mode === 'history-expired' && url.pathname === '/api/processings') return json(401, { message: 'Sessão simulada expirada.' });
  if (url.pathname === '/api/account/usage') {
    const active = subscription()?.grants_pro ?? false;
    return json(200, { data: { plan: active ? 'pro' : 'free', limits: { per_processing: active ? 250 : 3, daily: active ? 500 : 6, used_today: active ? 32 : 0, remaining_today: active ? 468 : 6, resets_at: '2099-01-01T00:00:00Z' } } });
  }
  if (url.pathname === '/api/account/subscription') return json(200, { data: { offer: { amount: 2990, currency: 'brl', per_processing: 250, daily: 500, available: scenario !== 'offer-unavailable' }, subscription: subscription() } });
  if (url.pathname.endsWith('/subscription/checkout') || url.pathname.endsWith('/subscription/portal')) return json(mode === 'checkout-409' ? 409 : 503, { code: 'checkout_unavailable', message: 'Resposta simulada: nenhuma sessão Stripe criada.' });
  if (url.pathname === '/api/quotations/entitlements') return json(200, { data: quota() });
  if (url.pathname === '/api/quotations/credit-orders') return json(200, { data: ['purchase-pending', 'many-history'].includes(scenario) ? [{ id: orderId, status: 'pending', amount: 990, credits: 5, currency: 'brl', checkout_url: 'https://checkout.stripe.com/c/pay/SIMULATED_DO_NOT_OPEN', created_at: '2026-10-28T16:00:00-03:00', paid_at: null, refunded_amount: 0 }] : [], meta: { current_page: 1, last_page: 1 } });
  if (url.pathname === '/api/account/purchases') return json(200, { data: scenario === 'many-history' ? [{ id: 'xml-synthetic', processing_id: orderId, tool: 'xml-to-excel', package_code: 'xml_100', files_limit: 100, files_count: 75, amount: 490, currency: 'brl', status: 'paid', consumed: false, paid_at: '2026-10-28T14:00:00Z', expires_at: '2099-01-01T00:00:00Z', created_at: '2026-10-28T14:00:00Z', can_resume_processing: true }] : [], meta: { current_page: 1, last_page: 1 } });
  if (url.pathname === '/api/processings') { const page = Number(url.searchParams.get('page') || 1); return json(200, { data: scenario === 'many-history' ? history(page) : [], meta: { current_page: page, last_page: scenario === 'many-history' ? 5 : 1, total: scenario === 'many-history' ? 45 : 0, per_page: 10 } }); }
  if (url.pathname === '/api/quotations/drafts') return json(200, { data: null });
  return json(503, { message: 'Endpoint sem fixture: nenhuma chamada real permitida.' });
});
const web = createServer(async (req, res) => {
  const path = decodeURIComponent(new URL(req.url, 'http://localhost').pathname);
  const file = resolve(root, '.' + path);
  if (!file.startsWith(root)) return res.writeHead(403).end();
  try {
    const data = await readFile(extname(path) ? file : resolve(root, 'index.csr.html'));
    res.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png' })[extname(path)] || 'text/html');
    res.end(data);
  } catch { res.writeHead(404).end(); }
});
await new Promise((r, reject) => { api.once('error', reject); api.listen(8000, 'localhost', r); });
await new Promise((r, reject) => { web.once('error', reject); web.listen(4317, 'localhost', r); });
// Keep disposable browser profiles inside the frontend's ignored build directory.
const originalCwd = process.cwd();
process.chdir(resolve('feitoo-web'));
try {
  await withBrowser('account-audit', async ({ command, evaluate, waitFor, errors }) => {
    await command('Network.setBlockedURLs', { urls: ['https://*', 'http://api.feitoo.com.br/*'] });
    await command('Emulation.setTimezoneOverride', { timezoneId: 'America/Sao_Paulo' });
    const navigate = async (path = '/conta') => {
      await command('Page.navigate', { url: 'http://localhost:4317' + path });
      await waitFor("!!document.querySelector('app-account-page,app-auth-page,app-quotation-page,app-plans-page')");
      if (scenario !== 'loading' && scenario !== 'session-expired') await waitFor("!document.body.innerText.includes('Consultando seu plano…') && !document.body.innerText.includes('Carregando seus processamentos…')");
      await new Promise(r => setTimeout(r, scenario === 'loading' ? 120 : 180));
    };
    const snap = async (name, width, full = true) => {
      await evaluate('window.scrollTo(0,0)');
      const metrics = await command('Page.getLayoutMetrics');
      const height = Math.ceil(metrics.cssContentSize.height);
      const { data } = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: full, clip: { x: 0, y: 0, width, height: full ? height : 900, scale: 1 } });
      await writeFile(resolve(out, name + '.png'), Buffer.from(data, 'base64'));
      const detail = await evaluate(`(() => {
        const visible = e => !!(e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden');
        const box = e => { const b=e.getBoundingClientRect(); return {text:e.innerText?.trim().slice(0,110), y:Math.round(b.top+scrollY), height:Math.round(b.height), width:Math.round(b.width)}; };
        return {url:location.pathname+location.search+location.hash, width:innerWidth, scrollWidth:document.documentElement.scrollWidth, height:document.documentElement.scrollHeight,
          words:document.querySelector('app-account-page')?.innerText.trim().split(/\\s+/).length,
          headings:[...document.querySelectorAll('app-account-page h2')].map(box),
          actions:[...document.querySelectorAll('app-account-page button,app-account-page a')].filter(visible).map(e=>({...box(e),disabled:!!e.disabled,href:e.getAttribute('href')})),
          overflowing:[...document.querySelectorAll('body *')].filter(visible).filter(e=>{const b=e.getBoundingClientRect();return b.right>innerWidth+1||b.left< -1}).map(e=>({tag:e.tagName,class:e.className,text:e.innerText?.slice(0,65)})).slice(0,20),
          statuses:[...document.querySelectorAll('[role="status"],[role="alert"]')].filter(visible).map(e=>e.innerText)
        };
      })()`);
      measurements.push({ scenario, name, ...detail });
    };
    if (extras) {
      scenario = 'free-zero';
      for (const width of [1440, 1024, 768, 390, 320]) {
        await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
        await navigate();
        await snap(`viewport-free-${width}`, width, false);
      }
      await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: false });
      await navigate();
      checks.push({test:'Native fragment URL resolution', base:await evaluate('document.baseURI'), links:await evaluate("[...document.querySelectorAll('app-account-page a[href^=\"#\"]')].map(a=>({text:a.innerText,attribute:a.getAttribute('href'),resolved:a.href}))")});
      await evaluate("document.querySelector('a[href=\"#credits-title\"]').click()");
      await new Promise(r => setTimeout(r,1200));
      checks.push({test:'Real click Meus créditos anchor', url:await evaluate('location.href'), account:await evaluate("!!document.querySelector('app-account-page')"),title:await evaluate('document.querySelector("h1")?.innerText')});
      await snap('anchor-meus-creditos-result-390',390);
      scenario='subscription-error'; await navigate();
      checks.push({test:'Subscription-only API error recovery controls',actions:await evaluate("[...document.querySelector('#plan-title').closest('section').querySelectorAll('button,a')].map(e=>e.innerText)")});
      scenario='free-zero'; await navigate();
      await evaluate("document.querySelector('.account-menu summary').click()");
      await snap('account-menu-390',390,false);
      await evaluate("document.querySelector('.account-menu summary').focus()");
      await command('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
      await command('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
      checks.push({test:'Escape in account menu with summary focused',open:await evaluate("document.querySelector('.account-menu').open"),focus:await evaluate('document.activeElement?.tagName')});
      await evaluate("document.querySelector('.mobile-menu-toggle').click()");
      await snap('mobile-menu-390',390,false);
      await command('Emulation.setDeviceMetricsOverride',{width:768,height:900,deviceScaleFactor:1,mobile:false});
      await navigate();
      const style = await evaluate(`(() => {
        const selectors=['.account-panel','.plan-panel','.upgrade-offer button','.purchases-panel','.account-navigation','.account-top','.history-section','app-quotation-commerce h2','app-quotation-commerce button'];
        return selectors.map(selector=>{const e=document.querySelector(selector);const s=getComputedStyle(e), b=e.getBoundingClientRect();return{selector,width:b.width,height:b.height,fontSize:s.fontSize,lineHeight:s.lineHeight,color:s.color,background:s.backgroundColor,padding:s.padding,grid:s.gridTemplateColumns}});
      })()`);
      checks.push({test:'Computed styles at 768px',style});
      // A slow request allows an actual duplicate click and loading capture.
      await command('Network.emulateNetworkConditions',{offline:false,latency:800,downloadThroughput:-1,uploadThroughput:-1});
      await evaluate("[...document.querySelectorAll('button')].find(e=>e.innerText==='Assinar Feitoo Pro').click()");
      await waitFor("document.body.innerText.includes('Abrindo…')");
      await snap('checkout-loading-768',768,false);
      await evaluate("document.querySelector('.upgrade-offer button').click()");
      checks.push({test:'Checkout loading button',disabled:await evaluate("document.querySelector('.upgrade-offer button').disabled"),label:await evaluate("document.querySelector('.upgrade-offer button').innerText")});
      await command('Network.emulateNetworkConditions',{offline:false,latency:0,downloadThroughput:-1,uploadThroughput:-1});
      await waitFor("document.body.innerText.includes('Não foi possível abrir a Stripe.')");
      mode='normal'; await navigate(); mode='quota-error';
      await evaluate("[...document.querySelectorAll('app-quotation-commerce button')].find(e=>e.innerText==='Atualizar saldo e compras').click()");
      await waitFor("document.querySelector('app-quotation-commerce').innerText.includes('Não foi possível consultar os créditos.')");
      mode='normal';
      await evaluate("[...document.querySelectorAll('app-quotation-commerce button')].find(e=>e.innerText==='Atualizar saldo e compras').click()");
      await new Promise(r=>setTimeout(r,350));
      checks.push({test:'Credit error persists after successful retry',text:await evaluate("document.querySelector('app-quotation-commerce').innerText"),requests:log.slice(-3)});
      await snap('credit-stale-error-768',768);
      scenario='return-confirming'; await navigate('/conta?subscription=confirming');
      scenario='pro-active';
      await waitFor("document.querySelector('.plan-heading')?.innerText.includes('Feitoo Pro')");
      checks.push({test:'Pro activation does not refresh quotation entitlements',plan:await evaluate("document.querySelector('.plan-heading').innerText"),quota:await evaluate("document.querySelector('app-quotation-commerce').innerText"),requests:log.slice(-4)});
      await snap('activated-pro-stale-quota-768',768);
      await writeFile(resolve(out,'extra-browser-errors.json'),JSON.stringify(errors,null,2));
      return;
    }
    const matrix = ['free-zero', 'free-credits', 'pro-active', 'pro-pending', 'purchase-pending', 'api-error', 'empty-history', 'many-history', 'loading', 'session-expired'];
    for (const state of matrix) {
      scenario = state; mode = 'normal';
      for (const width of [1440, 1024, 768, 390, 320]) {
        await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
        await navigate();
        await snap(`${state}-${width}`, width);
      }
    }
    await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    for (const state of ['past-due', 'unpaid', 'canceled', 'incomplete-expired', 'scheduled', 'period-expired', 'paused', 'trialing', 'offer-unavailable', 'subscription-error', 'return-confirming', 'return-cancelled']) {
      scenario = state; mode = 'normal';
      await navigate('/conta' + (state === 'return-confirming' ? '?subscription=confirming' : state === 'return-cancelled' ? '?subscription=cancelled' : ''));
      await snap(`${state}-1440`, 1440);
    }
    // Real DOM clicks, only against synthetic API responses. External HTTPS is blocked.
    scenario = 'free-zero'; mode = 'normal'; await navigate();
    await evaluate("[...document.querySelectorAll('button')].find(e=>e.innerText==='Assinar Feitoo Pro').click()");
    await waitFor("document.body.innerText.includes('Não foi possível abrir a Stripe. Tente novamente.')");
    checks.push({ test: 'Free subscribe click', requests: log.slice(-2), error: await evaluate("document.querySelector('#plan-title').closest('section').innerText") });
    await snap('checkout-error-1440', 1440);
    scenario = 'pro-active'; await navigate();
    await evaluate("[...document.querySelectorAll('button')].find(e=>e.innerText==='Gerenciar assinatura').click()");
    await waitFor("document.body.innerText.includes('Não foi possível abrir a Stripe. Tente novamente.')");
    checks.push({ test: 'Pro portal click', requests: log.slice(-2) });
    scenario = 'purchase-pending'; await navigate();
    checks.push({ test: 'Account pending credit order', orderActions: await evaluate("[...document.querySelectorAll('app-quotation-commerce button,app-quotation-commerce a')].map(e=>({text:e.innerText,href:e.getAttribute('href')}))") });
    await evaluate("document.querySelector('a[href*=\"etapa=revisar\"]').click()");
    await waitFor("!!document.querySelector('app-quotation-page')");
    await waitFor("!document.body.innerText.includes('Carregando rascunho')");
    await new Promise(r => setTimeout(r, 500));
    checks.push({ test: 'Buy card click, unchanged local generation flag', url: await evaluate('location.href'), commerce: await evaluate("!!document.querySelector('app-quotation-page app-quotation-commerce')"), text: await evaluate("document.querySelector('.editor-finish')?.innerText"), requests: log.slice(-8) });
    await snap('editor-from-buy-card-1440', 1440);
    scenario = 'free-zero'; await navigate('/planos');
    await snap('plans-1440', 1440);
    await evaluate("[...document.querySelectorAll('a')].find(e=>e.innerText==='Assinar Pro').click()");
    await waitFor("!!document.querySelector('app-account-page')");
    checks.push({ test: 'Plans Pro CTA', url: await evaluate('location.pathname'), focused: await evaluate('document.activeElement?.outerHTML'), scrollY: await evaluate('scrollY') });
    // Billing-only session expiry: interceptor clears auth, no AccountPage handleSession here.
    mode = 'billing-expired';
    await evaluate("[...document.querySelectorAll('button')].find(e=>e.innerText==='Assinar Feitoo Pro').click()");
    await waitFor("!!document.querySelector('a.account-link')");
    checks.push({ test: 'Session expires during billing click', url: await evaluate('location.pathname'), remainsAccount: await evaluate("!!document.querySelector('app-account-page')"), planText: await evaluate("document.querySelector('#plan-title').closest('section').innerText") });
    await snap('session-expired-billing-1440', 1440);
    mode = 'normal'; await navigate(); mode = 'history-expired';
    await evaluate("[...document.querySelectorAll('button')].find(e=>e.innerText==='Atualizar histórico').click()");
    await waitFor("location.pathname==='/login'");
    checks.push({ test: 'Session expires during history refresh', url: await evaluate('location.pathname+location.search') });
    mode = 'normal'; await navigate();
    const focus = [];
    await command('Page.navigate', { url: 'http://localhost:4317/conta' });
    await waitFor("!!document.querySelector('app-account-page')");
    for (let i=0;i<32;i++) {
      await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
      await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
      focus.push(await evaluate("({tag:document.activeElement.tagName,text:document.activeElement.innerText?.slice(0,70),id:document.activeElement.id,outline:getComputedStyle(document.activeElement).outlineStyle})"));
    }
    checks.push({ test: 'Keyboard 32 Tab presses', focus });
    const ax = await command('Accessibility.getFullAXTree');
    await writeFile(resolve(out,'accessibility-tree.json'),JSON.stringify(ax,null,2));
    await writeFile(resolve(out,'browser-errors.json'),JSON.stringify(errors,null,2));
    checks.push({ test: 'Browser', version: await command('Browser.getVersion') });
  });
} finally {
  process.chdir(originalCwd);
  await writeFile(resolve(out, 'measurements.json'), JSON.stringify([...new Map(measurements.map(x=>[x.name,x])).values()], null, 2));
  await writeFile(resolve(out, 'requests.json'), JSON.stringify(log, null, 2));
  await writeFile(resolve(out, 'checks.json'), JSON.stringify([...new Map(checks.filter(x=>x.test!=='Escape in account menu').map(x=>[x.test,x])).values()], null, 2));
  api.closeAllConnections(); web.closeAllConnections(); api.close(); web.close();
}
console.log(JSON.stringify({ screenshots: measurements.length, checks: checks.length, requests: log.length, out }));
