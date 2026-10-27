# Gerador de Orçamentos — preparação do lançamento público

Data: 27/10/2026. Implementação local; publicação e aceite em produção ainda não executados.

## Resultado e auditoria

A rota `/gerador-de-orcamentos` já tinha editor Angular 21 standalone, cálculo inteiro em centavos, prévia dos dois modelos, upload, IndexedDB com prazo de retenção e separação por conta, integração Sanctum/CSRF, geração assíncrona, polling limitado, retry idempotente e download privado. Esses recursos foram inspecionados e reutilizados. Os templates, o renderizador, o sandbox, as migrations, o ledger, a fila e os serviços operacionais foram preservados.

As lacunas corrigidas nesta entrega são:

- Home, catálogo, busca, menu compartilhado e destaque com **Gerador de Orçamentos**, **Grátis para começar**, descrição solicitada e CTA **Criar orçamento**. A ferramenta não está marcada como “Em breve”.
- Cabeçalho público, saldo da API no início da ferramenta, resumo e confirmação explícita da origem do consumo antes de emitir. Abrir ou cancelar a confirmação não reserva unidade. Cliques repetidos são bloqueados.
- Máscaras de CPF/CNPJ numérico e telefone brasileiro ao sair do campo, sem modificar documentos alfanuméricos ou forçar formato brasileiro em contatos de outro formato. Campos e limites seguem o contrato existente; nenhum título ou campo incompatível foi inventado.
- Lista paginada de rascunhos vigentes, novo orçamento preservando o anterior e abertura de rascunho com logotipo. Exclusão continua pelo botão existente **Descartar rascunho**. Chaves de geração não são copiadas entre documentos.
- Link da ferramenta para o histórico existente da conta e ação **Consultar orçamento** no histórico para abrir uma emissão específica, consultar seu estado e tentar novamente quando a API permitir. Não existe uma segunda tabela de histórico.
- Correção da origem selecionada quando a franquia Pro se esgota: não oferecer a franquia Free a uma assinatura Pro ativa. Crédito continua exigindo escolha explícita.
- Mensagens distintas para esgotamento Free/Pro/créditos, período inválido e indisponibilidade comercial. Checkout desabilitado quando a oferta está indisponível ou retorna 503, preservando o editor e os benefícios Free/Pro.
- SEO público com title/description, canonical sem query privada, Open Graph/Twitter, FAQ visível e estruturada, links internos, sitemap, prerender e remoção do antigo `X-Robots-Tag: noindex` da rota pública. O HTML estático não contém os dados da conta, rascunhos ou documentos.

O responsável informou que migrations, worker dedicado, scheduler, templates e `feitoo:quotation-sandbox-check` já foram homologados na VPS. Esta sessão não acessou a VPS, não comprovou o estado remoto atual nem repetiu seu provisionamento.

## Contratos utilizados

Todos os endpoints abaixo já existiam. A única extensão backend é a paginação opcional de rascunhos no mesmo GET, sem quebrar a consulta ao último rascunho.

| Método e rota                                             | Uso                                                                                                                              |
| --------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `GET /api/quotations/entitlements`                        | Flag global, saldo Free/Pro/créditos, período, oferta e emissão ativa/recente                                                    |
| `GET /api/quotations/drafts`                              | Rascunho vigente mais recente; resposta anterior preservada                                                                      |
| `GET /api/quotations/drafts?page=1`                       | Lista de 10 resumos da própria conta, sem payload completo nem caminhos privados; `data[]` e `meta.current_page/last_page/total` |
| `POST /api/quotations/drafts`                             | Criação/importação com `schema_version: 1`, payload parcial e `Idempotency-Key`                                                  |
| `GET /api/quotations/drafts/{uuid}`                       | Recuperação privada do rascunho                                                                                                  |
| `PATCH /api/quotations/drafts/{uuid}`                     | Atualização por revisão otimista                                                                                                 |
| `DELETE /api/quotations/drafts/{uuid}`                    | Exclusão por revisão                                                                                                             |
| `PUT`, `GET`, `DELETE /api/quotations/drafts/{uuid}/logo` | Upload, consulta e remoção privada; multipart usa POST com `_method=PUT`                                                         |
| `POST /api/quotations/drafts/{uuid}/generations`          | Reserva/geração com revisão, origem explícita e chave idempotente                                                                |
| `GET /api/processings/{uuid}`                             | Estados reais `queued`, `processing`, `completed`, `failed`, `expired`; sem porcentagem simulada                                 |
| `POST /api/quotations/generations/{uuid}/retry`           | Retry da mesma emissão com tentativa e chave idempotente                                                                         |
| `GET /api/processings/{uuid}/result`                      | Download privado autenticado                                                                                                     |
| `GET /api/processings?page=1`                             | Histórico existente da conta                                                                                                     |
| `DELETE /api/processings/{uuid}/files`                    | Exclusão de arquivos pelo histórico existente                                                                                    |
| `POST`, `GET /api/quotations/credit-orders`               | Checkout idempotente e consulta paginada de pedidos de créditos                                                                  |
| `GET /api/quotations/credit-orders/{uuid}`                | Confirmação do pedido exclusivamente pelo backend                                                                                |

Login/cadastro/OAuth e assinatura continuam nos serviços existentes. O interceptor mantém cookies, cabeçalho XSRF e requisições com credenciais. Os clientes de orçamentos usam também `X-Quotation-Owner` como verificação adicional de contexto; esse cabeçalho não autentica nem substitui ownership no servidor.

## Regras efetivamente confirmadas no código e nos testes

| Benefício              | Regra                                                                                                                                                                                                                                   |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Free                   | Uma geração **concluída** por conta por dia, virada à meia-noite em `America/Sao_Paulo`. Cadastro obrigatório para gerar. Ambos os modelos.                                                                                             |
| Pro                    | 100 por **período vigente da assinatura**, não por dia. Bucket vinculado a `subscription_id`, início e fim do período. Assinatura precisa conceder Pro e ter período válido. Não existe nova assinatura para o gerador.                 |
| Prioridade             | Havendo Pro disponível, usar Pro. Free vale para conta sem Pro ativo. Após o esgotamento, crédito pago somente mediante escolha explícita; não há débito automático de créditos.                                                        |
| Créditos               | Pacote `quotation_5`: cinco gerações por 990 centavos em BRL. Grants sem expiração comercial; reservas e consumo rastreados.                                                                                                            |
| Confirmação do consumo | A geração reserva; conclusão confirma. Falha terminal/deadline libera. Retry conserva emissão e origem e não duplica consumo. Falha transitória pode manter a reserva durante a recuperação.                                            |
| Worker parado          | Não há heartbeat no contrato de entitlements. O prazo pendente é 30 minutos; o reconciliador libera a reserva expirada. Isso exige scheduler saudável. A flag não prova saúde do worker.                                                |
| Documentos             | PDF disponível por 120 minutos após concluir; rascunho por 24 horas após a última edição. Leitura não renova prazo. Exclusão física depende das rotinas de limpeza.                                                                     |
| Checkout               | O gateway de créditos continua **TEST-only**. Requer modo/chave/webhook TEST coerentes e `STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID`. Antes da Session, confere Price ativo, pagamento único, BRL e 990 centavos. Live permanece recusado. |

Não há whitelist. A flag `QUOTATION_GENERATION_ENABLED` é global. Quando habilitada e com a infraestrutura saudável, qualquer usuário autenticado pode gerar dentro dos direitos da própria conta.

## Arquivos da entrega

Backend, no repositório raiz:

- `feitoo-api/app/Http/Controllers/QuotationDraftController.php`: paginação opcional, filtros de conta/expiração e resumos mínimos.
- `feitoo-api/tests/Feature/QuotationDraftTest.php`: regressão de paginação, contrato anterior, isolamento, expiração, privacidade e autenticação.

Frontend, no repositório próprio `feitoo-web`:

- Novos: `src/app/features/quotations/components/quotation-drafts.ts` e `quotation-drafts.spec.ts`.
- Editor: `features/quotations/pages/quotation-page.ts`, `.html`, `.scss`, `.spec.ts` e `quotation-draft.scss`.
- Componentes: `quotation-party.ts`, `quotation-template-picker.ts`, `quotation-commerce.ts` e `.spec.ts`.
- Serviços: `quotation-draft-api.service.ts`, `quotation-draft.coordinator.ts`, `quotation-generation.coordinator.ts` e respectivos `.spec.ts`.
- Home: `features/public/home-tools.ts`, `home.ts`, `home.html`, `home.spec.ts` e `tool-card.html`.
- Conta: `features/account/pages/account-page.html`.
- SEO: `core/public-pages.ts`, `core/seo.service.ts`, `core/tool-faqs.ts`, `scripts/generate-public-assets.mjs` e `vercel.json`.
- Regressões: `app.spec.ts`, `core/public-launch.spec.ts`, `scripts/public-assets.test.mjs`, `scripts/quotations-public.test.mjs`, `scripts/check-tool-browser.mjs`, `scripts/check-quotation-commerce-browser.mjs` e `scripts/check-quotation-generation-browser.mjs`.

Os caminhos iniciados por `features/` e `core/` são relativos a `feitoo-web/src/app/`. Artefatos em `dist/` e os bancos/arquivos privados de validação não pertencem ao pacote público. `launch.generated.ts` é gerado pelo build; não contém secrets.

## Validação local

| Verificação executada                                                                                                                                                     | Resultado                                                                                                                                                                                                                                                                                                        |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `npm test -- --watch=false`                                                                                                                                               | 342 testes, 29 arquivos, aprovados                                                                                                                                                                                                                                                                               |
| Build Angular de produção                                                                                                                                                 | Aprovado; 15 rotas prerenderizadas. Avisos de tamanho de SCSS, sem erros de compilação; nenhum budget foi aumentado                                                                                                                                                                                              |
| `node --test --test-concurrency=1 scripts/public-assets.test.mjs scripts/home-docx-seo.test.mjs scripts/tools-experience-seo.test.mjs scripts/quotations-public.test.mjs` | 15 testes de publicação/SEO aprovados                                                                                                                                                                                                                                                                            |
| PHPUnit completo, Windows/PHP 8.2                                                                                                                                         | 504 testes, 4.816 assertions, sem falhas; 7 testes condicionais ignorados pelo ambiente                                                                                                                                                                                                                          |
| `check-quotation-sandbox-linux.ps1 -Mode tests -Filter Quotation`                                                                                                         | 209 testes, 1.925 assertions, sem falhas nem skips, em Linux/PHP 8.5, com Bubblewrap real                                                                                                                                                                                                                        |
| `check-quotation-sandbox-linux.ps1 -Mode check`                                                                                                                           | Exit 0. Clássico: 1 página; Moderno: 4 páginas com imagem. Checksum, tamanho, páginas, negação de rede/leitura privada/symlink e limpeza aprovados                                                                                                                                                               |
| `php -d extension=soap scripts/check-quotation-concurrency.php`                                                                                                           | 5 pares de conexões independentes aprovados em MariaDB 10.4.32 local descartável                                                                                                                                                                                                                                 |
| `php -d extension=soap scripts/check-quotation-commerce-concurrency.php`                                                                                                  | 13 pares de conexões independentes aprovados em MariaDB 10.4.32; Stripe simulado, nenhuma requisição remota                                                                                                                                                                                                      |
| `node scripts/check-quotation-generation-browser.mjs`                                                                                                                     | Laravel/Sanctum/CSRF reais, SQLite isolado, IndexedDB real, login e restauração de logo, confirmação sem consumo antecipado, clique duplicado, fila database, subprocesso/PDF real, snapshot imutável, download repetido, limite Free, expiração, múltiplos rascunhos e histórico único; 4 larguras sem overflow |
| `node scripts/check-quotation-browser.mjs`                                                                                                                                | 17 verificações responsivas; arredondamento, modelos, PNG/JPEG, texto, 100 itens e privacidade aprovados                                                                                                                                                                                                         |
| `node scripts/check-quotation-drafts-browser.mjs`                                                                                                                         | 24 verificações com IndexedDB real e API local simulada, incluindo duas abas, conflito, login, expiração e separação de contas                                                                                                                                                                                   |
| `node scripts/check-quotation-commerce-browser.mjs`                                                                                                                       | Crédito explícito, preservação de logo/rascunho, falha e indisponibilidade de checkout, cancelamento, pendente/pago e emissão manual aprovados; API simulada e nenhuma chamada Stripe remota                                                                                                                     |
| `node scripts/check-tools-experience-browser.mjs`                                                                                                                         | 6 ferramentas anteriores, 96 verificações de layout e 8 downloads; nenhum erro de execução                                                                                                                                                                                                                       |

O smoke geral `node scripts/check-tool-browser.mjs` também passou: Home/busca/navegação, páginas públicas, login/cadastro, conta, assinatura, histórico, XML/Produtos, downloads e exclusão; zero erros de execução. O teste antigo não simulava os endpoints comerciais de orçamentos na conta e devolvia um processamento fiscal no lugar da lista de pedidos; a fixture foi corrigida para os contratos reais. Após timeouts do CDP dentro do sandbox, a execução foi repetida fora dele, sempre com API local simulada, e aprovada.

Artefatos locais ignorados pelo Git: `feitoo-web/dist/validation/quotation-generation/report.json`, `real-quotation.pdf`, `launch-390.png`, `launch-1440.png` e relatórios das outras suítes em `dist/validation/`. As duas capturas do editor foram inspecionadas visualmente. Todos os dados dos ensaios são fictícios e ficam fora dos assets públicos.

Os testes concorrentes criaram e removeram apenas bancos com nomes descartáveis, depois de verificar o datadir privado e a porta reservada do daemon de teste. Os dois daemons foram encerrados. O Docker Desktop iniciado para os ensaios Linux também foi encerrado, restaurando seu estado anterior. Nenhum daemon/banco da VPS foi acessado.

Uma primeira tentativa dos testes de navegador/SEO falhou por falta de memória/paginação do Windows após iniciar o Docker local. Depois de encerrar o Docker, as verificações foram repetidas e aprovadas. Isso não foi tratado como aprovação da tentativa que falhou. Os resultados acima são das execuções posteriores bem-sucedidas.

Limites desta evidência: SQLite e MariaDB não homologam MySQL 8.4; o sandbox Linux local não prova as permissões atuais da VPS; Stripe usa contratos simulados nos testes, sem venda Live. Aceite em produção, publicação Vercel e configuração operacional continuam pendentes. A venda Live de créditos exige trabalho/validação e autorização próprios; não impede o lançamento Free/Pro.

## Publicação proposta — executar pelo procedimento operacional existente

Não foram executados deploy, migrations remotas, alteração de `.env` de produção, Supervisor, cron ou Stripe Live. Não restaurar stashes antigos. Como o frontend tem Git próprio, identificar a revisão dele e a revisão da API separadamente antes de formar a release.

1. Preparar a release revisada e backup pelo mecanismo já utilizado. Instalar dependências pelo lock, sem `composer update` e sem `composer setup`.
2. Publicar a API mantendo inicialmente `QUOTATION_GENERATION_ENABLED=false`. Esta entrega não cria migration. Conferir que as migrations já homologadas constam como executadas; não reaplicá-las como parte deste roteiro.
3. Verificar worker exclusivo, scheduler, storage privado e sandbox já instalados, sem mudar a configuração dos serviços.
4. Ativar a flag global pelo mecanismo de configuração aprovado, regenerar cache e carregar o código novo somente no worker de orçamentos pelo procedimento existente.
5. Publicar o frontend na Vercel com a release aprovada. Conferir a Home, rota, SEO e fluxo com duas contas de teste controladas antes de anunciar o lançamento.

Comandos Linux de referência, **na release correta da API**, para o operador executar:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan migrate:status
php artisan route:list --path=quotations
php artisan schedule:list
php artisan feitoo:quotation-sandbox-check
php artisan config:cache
php artisan view:cache
```

Depois de definir `QUOTATION_GENERATION_ENABLED=true` no mecanismo de configuração da produção, executar novamente `php artisan config:cache`. Não usar `php artisan queue:restart` global, `supervisorctl restart all` ou editar o cron. Se a release exigir recarga do worker, o operador deve usar o nome **já existente** do programa exclusivo de orçamentos. O nome remoto não foi inspecionado nesta sessão e não é inventado neste documento.

Configuração a conferir, sem imprimir segredos:

```dotenv
QUOTATION_GENERATION_ENABLED=true
QUOTATION_SANDBOX=bubblewrap
QUOTATION_PHP_BINARY=/usr/bin/php
QUOTATION_BWRAP_BINARY=/usr/bin/bwrap
QUOTATION_PRLIMIT_BINARY=/usr/bin/prlimit
```

Os binários devem refletir os caminhos efetivamente homologados na VPS. Manter fila `quotations` em database/jobs, mesma conexão transacional, `after_commit=false`, `retry_after=180`. Worker dedicado: comando equivalente a `queue:work quotations --queue=quotations --timeout=60 --tries=0`. Preservar timeout do job 60 s, lease 90 s e retry_after 180 s. Reconcile a cada minuto; purge por hora. Não duplicar scheduler nem alterar workers fiscais/documentais.

Conferir `APP_URL`, `FRONTEND_URL`, CORS com origem exata, `SANCTUM_STATEFUL_DOMAINS`, domínio de cookie compartilhado apropriado, cookie seguro e HTTPS entre `feitoo.com.br` e `api.feitoo.com.br`. Não mudar a configuração global Stripe da instalação para habilitar créditos: Free/Pro não dependem da venda de créditos. Com Live na instalação, a oferta de créditos retorna indisponível e deve continuar desabilitada.

Comandos de frontend, na revisão aprovada:

```sh
npm ci
npm test -- --watch=false
FEITOO_BUILD_ENV=production npm run build
node --test --test-concurrency=1 scripts/public-assets.test.mjs scripts/home-docx-seo.test.mjs scripts/tools-experience-seo.test.mjs scripts/quotations-public.test.mjs
```

Na Vercel, preservar `buildCommand: npm run build` e `outputDirectory: dist/feitoo-web/browser`. Em produção, `VERCEL_ENV=production` configura a origem pública; `FEITOO_PUBLIC_ORIGIN=https://feitoo.com.br` pode explicitá-la. Previews permanecem sem origem indexável. O bundle atual usa `https://api.feitoo.com.br`: um preview Vercel **não é um backend isolado de staging**. Para homologação remota isolada, preparar um build com API/CSRF de staging e acesso controlado antes de testar; não fazer testes destrutivos via preview apontado à API operacional. O proxy de substituição usado no E2E é exclusivo do teste local.

Publicar pelo projeto Vercel já vinculado e fluxo Git existente; se o procedimento da equipe usar CLI, executar `vercel --prod` a partir de `feitoo-web` somente após conferir o projeto/alvo. Esse comando não foi executado. Não há endereço/nome de projeto remoto confirmado nesta sessão.

## Checklist de aceite em produção

- [ ] Revisões da API/frontend registradas; releases e backup conferidos; MySQL **8.4** e schema/migrations reais confirmados. Os ensaios concorrentes locais usam MariaDB e não substituem essa conferência.
- [ ] Sandbox check com exit 0 pelo usuário real do worker, fila dedicada saudável, scheduler executando e storage privado gravável. Sem intervenção nos workers de outras ferramentas.
- [ ] API de entitlements retorna `enabled=true`, franquia correta e oferta comercial indisponível quando não habilitada. Sem whitelist.
- [ ] Home mostra o card, busca encontra “orçamento”, CTA abre `/gerador-de-orcamentos`, menu funciona em celular, sem “Em breve”.
- [ ] Visitante preenche, faz login/cadastro, recupera dados e logotipo no mesmo navegador; nenhuma emissão ocorre automaticamente após autenticação.
- [ ] Confirmação mostra total, modelo e origem. Duplo clique/retry não duplica emissão nem consumo. Free conclui uma vez e bloqueia a segunda geração no mesmo dia.
- [ ] Conta Pro válida mostra saldo e fim do período de 100 gerações; usa Pro primeiro. Crédito de teste só é usado explicitamente, em ambiente separado com webhook TEST validado. Não fazer cobranças Live para validar esta entrega.
- [ ] Clássico e Moderno concluem, status real muda de fila para processamento e conclusão, PDF contém dados/totais corretos, logo e checksum/páginas válidos. Download privado funciona repetidamente durante a janela.
- [ ] Duas contas controladas não acessam rascunho/logo/processamento/PDF uma da outra. Sessão expirada pede autenticação sem expor conteúdo da conta anterior.
- [ ] Novo rascunho preserva o anterior, lista/abertura/exclusão funcionam, histórico único da conta abre a emissão correspondente.
- [ ] Depois do prazo, download retorna expiração. Reconciliador resolve reservas vencidas; limpeza remove temporários/expirados sem devolver consumo já concluído. Não interromper workers de produção para simular falha; usar ambiente isolado.
- [ ] HTML público contém H1 único, instruções e FAQ; canonical/OG corretos; sitemap inclui a ferramenta. `/conta`, autenticação e API/documentos continuam privados/noindex.
- [ ] Smoke das seis ferramentas anteriores, login e assinatura existentes aprovado. Frontend servido pela Vercel corresponde à revisão escolhida; não confundir build local com deploy concluído.

Em incidente, desabilitar novas emissões com `QUOTATION_GENERATION_ENABLED=false`, atualizar cache e seguir a recarga específica já aprovada. Preservar status, downloads vigentes, ledger, jobs aceitos, reconciliador e limpeza. Não apagar reservas/saldo nem executar rollback de migrations como contenção. A flag não cancela jobs já aceitos. Considerar o lançamento concluído somente depois de registrar esse aceite em produção.
