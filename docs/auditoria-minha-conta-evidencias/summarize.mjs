import { readFile, writeFile, readdir } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
const dir = dirname(fileURLToPath(import.meta.url));
const metrics = JSON.parse(await readFile(resolve(dir, 'measurements.json'), 'utf8'));
const checks = JSON.parse(await readFile(resolve(dir, 'checks.json'), 'utf8'));
const pngs = (await readdir(dir)).filter(x => x.endsWith('.png')).sort();
const luminance = hex => {
  const rgb = hex.replace('#','').match(/../g).map(x => parseInt(x,16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);
  return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;
};
const contrast = (a,b) => {const x=luminance(a),y=luminance(b);return +((Math.max(x,y)+.05)/(Math.min(x,y)+.05)).toFixed(2)};
const summary = { screenshots: pngs.length, measuredScreenshots: metrics.length, browserChecks: checks.length, simulation: 'All account, order, entitlement and payment data are synthetic. Unmodified local generation flag is false.', overflowPages: metrics.filter(x=>x.scrollWidth>x.width).map(x=>x.name), contrast: [['#125d54','#ffffff'],['#57665f','#ffffff'],['#243631','#ffffff'],['#76520c','#fff4db'],['#d7e2d9','#ffffff']].map(([a,b])=>({foreground:a,background:b,ratio:contrast(a,b)})), files: [] };
for(const file of pngs){const bytes=await readFile(resolve(dir,file));summary.files.push({file,bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')})}
await writeFile(resolve(dir,'summary.json'),JSON.stringify(summary,null,2));
await writeFile(resolve(dir,'index.html'),`<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Evidências da auditoria Minha Conta</title><style>body{font:16px system-ui;margin:24px;color:#243631;background:#fcfdfb}header{max-width:65rem}select{font:inherit;padding:10px;max-width:100%}img{display:block;max-width:100%;height:auto;border:1px solid #d7e2d9;margin:16px 0}a{color:#125d54}label{display:block;margin:16px 0}</style><header><h1>Minha Conta: capturas reais da interface local</h1><p>Dados de conta, saldo, compras e respostas de pagamento inteiramente simulados. Angular e estilos atuais, sem alteração de código ou flags. Nenhum checkout Stripe real.</p><p>77 arquivos PNG. As cinco larguras usam viewport de 900 px de altura e escala 1. Capturas com nome viewport mostram apenas a primeira dobra; as demais mostram a página inteira.</p><label>Captura <select id="pick">${pngs.map(x=>`<option>${x}</option>`).join('')}</select></label><a id="link">Abrir PNG na resolução original</a></header><img id="photo" alt="Screenshot real do frontend local com dados simulados"><script>const pick=document.querySelector('#pick'),photo=document.querySelector('#photo'),link=document.querySelector('#link');function show(){photo.src=pick.value;link.href=pick.value;photo.alt='Captura real com dados simulados: '+pick.value;history.replaceState(null,'','#'+pick.value)}if(location.hash&&[...pick.options].some(x=>x.value===location.hash.slice(1)))pick.value=location.hash.slice(1);pick.onchange=show;show();</script></html>`);
console.log(JSON.stringify({screenshots:summary.screenshots,browserChecks:summary.browserChecks,overflow:summary.overflowPages,contrast:summary.contrast}));
