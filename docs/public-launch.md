# Preparação pública do Feitoo — etapa 08

## Estado e escopo

A auditoria encontrou Angular 21 em SPA, uma ferramenta lazy, shell de desenvolvimento, home vazia, redirecionamento silencioso de 404 e ausência de SEO e analytics. O processamento Laravel, a privacidade do storage, a fila, o parser e a geração XLSX já estavam funcionais. Esta etapa não modifica o motor fiscal.

As páginas públicas são `/`, `/xml-para-excel`, `/seguranca`, `/privacidade` e `/termos`. Login e cadastro são rotas de conta, não páginas de aquisição. Uma rota desconhecida mostra uma página 404 com links úteis.

## Identidade e experiência

Marca Feitoo, comunicação concreta sobre tarefas e arquivos. Verde principal `#125d54`, apoio `#e5f7ce`, fundo `#fcfdfb`, texto `#243631`, texto secundário `#57665f` e erro `#a72e2e`. Tipografia do sistema, sem fontes remotas. Radius de aproximadamente 10–16 px, sombras discretas, espaçamentos de 4/8/16/24 px e foco visível.

O shell contém marca, Ferramentas, Segurança e Entrar/Minha conta. Conta preserva sair e vincular Google. O rodapé contém somente links existentes, preferências de cookies e contato quando configurado. A home destaca apenas XML para Excel. Não há cards de ferramentas inexistentes.

Na ferramenta, a seleção continua acima da dobra nos tamanhos verificados. Login gratuito é informado antes de processar. Seleção e continuação com e-mail permanecem em memória. OAuth Google troca a página e exige nova seleção. Não há persistência de arquivos no navegador. FAQ visível, sem schema de FAQ ou promessa de rich result.

## Indexação e SEO

Usamos **prerender estático nativo do Angular**, com `@angular/ssr` e `@angular/platform-server`. Não há servidor Node obrigatório em produção. O build gera HTML das cinco páginas públicas e da página 404. Login, cadastro e o fallback usam renderização cliente.

Title, description, robots e Open Graph básicos mudam por página. Canonical e `og:url` usam somente a origem configurada, sem query strings. Structured data descreve WebSite na home, WebApplication na ferramenta e WebPage nas páginas informativas. Não há avaliações, preços ou certificações inventados. O Open Graph inicial contém título, descrição, tipo, URL, idioma e nome do site; uma imagem social dedicada pode ser adicionada posteriormente.

Sem domínio configurado, o build usa `noindex,follow`, não cria canonical e gera `robots.txt` com `Disallow: /`. O sitemap fica vazio. Isso permite revisar o produto sem inventar endereços públicos. **Não publicar assim para aquisição orgânica.** Com domínio, o sitemap contém exatamente as cinco páginas públicas. Auth e 404 têm noindex e ficam fora do sitemap.

Não consulte sessões, contas ou dados privados no prerender. As consultas de sessão e limites ocorrem somente no navegador. Consentimento é restaurado após a renderização inicial, evitando diferenças de hidratação. O build deve executar `npm.cmd run build`, que prepara configuração e gera os assets finais; `ng build` isolado não gera sitemap/robots.

## Configuração pública

As variáveis abaixo são lidas pelo script de build e materializadas em `src/environments/launch.generated.ts`. Todas são públicas. Não coloque segredos nesse arquivo. Os environments existentes continuam definindo os endpoints de API/CSRF.

| Variável | Padrão | Finalidade |
| --- | --- | --- |
| `FEITOO_PUBLIC_ORIGIN` | vazio | Origem HTTPS real, sem caminho, query ou credenciais |
| `FEITOO_CONTACT_EMAIL` | vazio | Contato do responsável, usado também no rodapé |
| `FEITOO_RESPONSIBLE_NAME` | vazio | Identificação do responsável pelo serviço e tratamento |
| `FEITOO_PUBLIC_TTL_MINUTES` | `120` | Retenção para HTML estático; deve coincidir com o backend |
| `FEITOO_GA4_ID` | vazio | Measurement ID real da propriedade GA4 |
| `FEITOO_ANALYTICS_ENABLED` | `false` | Habilita medição opcional; exige domínio e ID válidos |

Exemplo PowerShell, substituindo os valores pelo domínio e contato reais:

```powershell
Set-Location D:\backup\Projetos\feitoo\feitoo-web
$env:FEITOO_PUBLIC_ORIGIN = 'https://SEU-DOMINIO-REAL'
$env:FEITOO_CONTACT_EMAIL = 'CONTATO-REAL'
$env:FEITOO_RESPONSIBLE_NAME = 'RESPONSAVEL-REAL'
$env:FEITOO_PUBLIC_TTL_MINUTES = '120'
# Inicialmente sem medição opcional:
$env:FEITOO_ANALYTICS_ENABLED = 'false'
npm.cmd run build
```

Não inventar Measurement ID. Após configurar e revisar a propriedade real, atribuir `FEITOO_GA4_ID` e habilitar a variável. `npm start` usa a configuração pública gerada existente; para testar alterações locais executar `node scripts/configure-launch.mjs` antes de iniciar o servidor. Não reutilizar build de produção em staging sem trocar configuração.

## Retenção real

O backend é a autoridade: `GET /api/tools/xml-to-excel/config` expõe somente limites e `ttl_minutes`. A ferramenta consulta esse contrato, e as páginas institucionais atualizam a retenção pelo mesmo endpoint no navegador. O HTML prerenderizado usa o valor central do build; ao alterar TTL no backend, atualizar a variável pública e reconstruir o frontend.

O prazo começa na **criação do lote**, não na conclusão do upload. Vencimento bloqueia o acesso. Purge periódico remove XMLs, outputs e resumos fiscais e apaga nomes originais e erros dos registros. Metadados técnicos do lote, como contagens, tamanhos e datas, permanecem. Contas não são removidas pelo purge. O responsável deve definir a conservação de contas, metadados técnicos, logs e backups; não prometemos exclusão absoluta de todos os dados em 120 minutos.

## Analytics e consentimento

`AnalyticsService` é a única camada que chama gtag. Desabilitado ou sem configuração válida, permanece inerte e não aparece banner de aceitação para um recurso inexistente. O rodapé permite abrir as preferências e explica quando a medição está desligada.

Com GA4 habilitado, a escolha inicial é desconhecida. **Não carregamos script nem emitimos eventos de analytics antes da aceitação.** É uma implementação conservadora de consentimento básico, sem pings sem cookies para usuários que recusam. Cookies necessários de sessão/CSRF não dependem dessa escolha.

Aceitar carrega o script; recusar mantém o fluxo funcional. A escolha versionada é guardada em localStorage por até 180 dias. Revogar bloqueia novos eventos, ativa `ga-disable`, remove o elemento de script e limpa cookies `_ga`/`_ga_*` acessíveis ao domínio atual. Código externo já baixado não pode ser desbaixado; o opt-out da propriedade bloqueia novas coletas. Uma nova aceitação pode carregar novamente o script. Storage indisponível mantém a escolha apenas na página atual. Não há fila retroativa de eventos recusados.

Advertising consent, Google Signals e personalização de anúncios permanecem desabilitados. Não definimos `user_id` nem enviamos dados fornecidos pelo usuário. A escolha técnica de opt-in não substitui a revisão das bases legais, das transferências internacionais e dos operadores pelo responsável. O texto de Privacidade marca essas pendências.

### Configuração obrigatória na propriedade GA4 antes de habilitar

Desabilitar **todos os eventos de medição otimizada/enhanced measurement**, especialmente coleta automática de formulários, downloads, pesquisas e page views baseadas no histórico. Desabilitar Google Signals, coleta de dados fornecidos pelo usuário e recursos de publicidade. Não adicionar GTM, pixels, tags paralelas ou scripts automáticos na hospedagem. A API do gtag não controla todas as opções da propriedade; a configuração no console é indispensável para impedir coleta automática de nomes, links de Blob e valores de formulários.

Page views são manuais, com `send_page_view: false`. Tanto config como eventos usam URL sanitizada, título fixo seguro e referrer vazio. Caminhos desconhecidos viram `/404`; parâmetros e fragmentos nunca são enviados. Isso reduz a coleta incidental do contexto de navegação. O GA4 continua podendo tratar IP e outros dados técnicos conforme suas políticas, o que é explicado na política pública.

### Eventos

| Evento | Momento |
| --- | --- |
| `page_view` | Entrada/navegação após consentimento, URL sanitizada |
| `tool_view` | Ferramenta aberta com medição autorizada |
| `files_selected` | Seleção válida aceita, sem nomes |
| `upload_started` | Início do fluxo autenticado de envio |
| `upload_completed` | API confirma upload |
| `processing_started` | API confirma início na fila |
| `processing_completed` | Lote concluído, uma vez por execução observada |
| `processing_failed` | Backend informa falha global, não falha parcial nem erro de conexão |
| `result_viewed` | Resultado concluído é exibido |
| `download_clicked` | Usuário pede download autenticado |
| `signup_started` | Envio de cadastro por e-mail ou início de autenticação Google; no Google pode terminar em login existente |
| `signup_completed` | API confirma cadastro por e-mail |
| `google_signup_completed` | Conta realmente nova criada por OAuth, sinal único da sessão |
| `files_deleted` | API confirma exclusão manual |

O sinal Google é um campo adicional opcional de `/api/me`, consumido uma vez na sessão. Não informa identidade, token ou dados fiscais. Login Google existente e vinculação não emitem cadastro concluído. Se não houver consentimento no momento, não há backfill.

Payloads são reconstruídos por allowlist: ferramenta fixa, contagens inteiras limitadas, autenticação booleana, método email/google e buckets fechados. Tamanho: abaixo de 1 MiB, 1–10 MiB, 10–50 MiB, 50 MiB ou mais. Duração: abaixo de 10 s, 10–60 s, 1–5 min, 5 min ou mais. Duração mede o fluxo observado pelo navegador, incluindo envio/espera; não é benchmark do backend. Nenhum arquivo, XML, CNPJ/CPF, chave, nome, produto, valor fiscal, UUID, path ou e-mail é enviado pela camada.

## Hospedagem estática e 404

Publicar somente `dist/feitoo-web/browser`. O servidor deve servir cada `rota/index.html` sem redirecionar para a home. Recursos JS/CSS recebem MIME correto. `/api`, `/sanctum` e callbacks OAuth continuam encaminhados ao Laravel com as configurações de sessão, CSRF e proxy já existentes.

Login e cadastro devem servir `index.csr.html`. Rotas inexistentes precisam retornar **HTTP 404**, com o conteúdo de `404.html`, evitando soft 404. A navegação cliente também mostra a página 404. Exemplo conceitual Nginx a adaptar, sem substituir a configuração real de API/TLS:

```nginx
location = /login { try_files /index.csr.html =404; }
location = /cadastro { try_files /index.csr.html =404; }
location / { try_files $uri $uri/index.html =404; }
error_page 404 /404.html;
location = /404.html { internal; }
```

Configurar redirecionamento HTTP→HTTPS, compressão, caching por hash de assets e política de segurança compatível com os domínios GA4 somente se a medição estiver habilitada. Não servir cache compartilhado de respostas autenticadas da API. Links `/seguranca`, `/privacidade` e `/termos` devem funcionar por acesso direto e refresh.

## Search Console

Criar propriedade de domínio real, validar via TXT DNS fornecido pelo Search Console e enviar `https://DOMINIO-REAL/sitemap.xml`. Não há token fictício no código. Inspecionar a home e `/xml-para-excel`: HTML renderizado, canonical reconhecida, indexação permitida, sitemap lido e status HTTP correto. Acompanhar cobertura/indexação e consultas reais após publicação. Não prometer prazo ou posição nos resultados.

## Validação e checklist

```powershell
Set-Location D:\backup\Projetos\feitoo\feitoo-api
php artisan test
vendor\bin\pint --test
Set-Location ..\feitoo-web
npm.cmd test -- --watch=false
npm.cmd run test:public
npm.cmd run build
npx.cmd prettier --check src/app src/environments src/index.html src/styles.scss angular.json package.json scripts/configure-launch.mjs scripts/generate-public-assets.mjs scripts/public-assets.test.mjs scripts/check-tool-browser.mjs
node scripts/check-tool-browser.mjs
```

O smoke check usa Edge headless e API sintética, sem conta real ou XML fiscal. Verifica desktop 1440×900, tablet 768×1024 e mobile 390×844, sem overflow, seletor acima da dobra e foco visível. Verifica páginas públicas no mobile, login com seleção preservada, resultado parcial e exclusão. Screenshots e relatório ficam em `feitoo-web/dist/validation`, fora do Git.

Na validação da etapa: backend 171 testes/1258 assertions; frontend 53 testes; assets públicos 2 testes. O build final tem aproximadamente 315 kB iniciais, sem warning de budget (limite preservado em 500 kB), e seis páginas prerenderizadas. Bootstrap e Bootstrap Icons foram removidos das dependências e do carregamento global; a identidade usa CSS local com os estilos necessários ao fluxo existente. Build e HTML também foram verificados com uma origem sintética configurada. O smoke check passou com GA4 sintético habilitado, destinos Google bloqueados, aceitação/recusa/revogação e payloads sem dados proibidos. Nenhum evento foi enviado ao Google.

Antes do deploy:

- Preencher domínio, responsável e contato; revisar Privacidade/Termos e operacionalizar direitos/exclusão de conta.
- Alinhar TTL público ao backend; confirmar scheduler/purge, worker e limites de servidor.
- Configurar HTTPS, cookies seguros, Sanctum, CSRF, origens permitidas e URLs OAuth reais.
- Definir retenção de metadados técnicos, contas, logs e backups.
- Configurar hospedagem de HTML prerenderizado e HTTP 404; verificar favicon e metadata no HTML entregue.
- Se habilitar GA4, configurar a propriedade conforme acima, testar opt-in, recusa, revogação e DebugView com dados sintéticos. Confirmar ausência de requisições GA antes da aceitação e de dados proibidos após ela.
- Verificar domínio no Search Console e enviar sitemap real.

Nenhum deploy, conta GA4, DNS, Search Console ou envio de analytics real é feito nesta etapa. Sem esses valores, o build final permanece não indexável e com analytics desligado.

## Referências oficiais

- [Angular: renderização híbrida e prerender estático](https://angular.dev/guide/ssr)
- [Google: modos de consentimento](https://developers.google.com/tag-platform/security/concepts/consent-mode)
- [Google: page views manuais](https://developers.google.com/analytics/devguides/collection/ga4/views)
- [ANPD: guia de cookies e proteção de dados](https://www.gov.br/anpd/pt-br/centrais-de-conteudo/materiais-educativos-e-publicacoes/guia-orientativo-cookies-e-protecao-de-dados-pessoais.pdf)
