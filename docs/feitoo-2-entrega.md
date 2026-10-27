# Feitoo 2.0 — implementação local e preparação da publicação

27/10/2026. UX implementada e validada localmente. Carteira universal permanece proposta para aprovação. Publicação e validação da nova versão em produção NÃO realizadas; portanto o programa Feitoo 2.0 não está concluído em produção.

## Documentos

- [Auditoria inicial, anterior às mudanças](feitoo-2-auditoria-inicial.md), com P0–P3 e evidências do código.
- [Design system](feitoo-2-design-system.md), consolidando os componentes e tokens existentes.
- [Carteira universal: regras, modelo aditivo, endpoints futuros, custos a medir e rollback](feitoo-2-carteira-proposta.md). Não há valores definitivos ou migration financeira executável nesta entrega.

## O que mudou

Orçamentos tem quatro etapas: Minha empresa (incluindo logotipo), Cliente, Itens e valores, Revisar e gerar (identificação, datas, modelo, resumo e fonte de consumo). Avançar valida a etapa atual; navegação direta permite revisar livremente. Erro na validação final abre a etapa correta, expande contatos quando necessário e foca o campo. Os campos permanecem montados, preservando texto monetário parcial, FormArray, IndexedDB, restauração e logotipo. Mantidos cálculo em centavos, modelos Clássico/Moderno, snapshot privado, ownership, idempotência e polling.

Desktop mantém prévia lateral; mobile mantém botões editor/prévia. Total acompanha o editor. Fonte de consumo está na revisão; confirmação explícita continua antes da reserva. Login e envio levam o foco ao resultado/status. Rascunhos salvos foram movidos para depois da área de edição, acessíveis por atalho; removidos avisos repetidos antes do formulário. A inspeção visual motivou essa redução de conteúdo antes da tarefa no celular.

Navbar autenticada mostra somente créditos pagos de orçamentos, claramente identificados. O menu detalha Free/Pro e reserva, com links para saldo e compra. Não soma XML, assinatura ou moedas incompatíveis. Não usa zero provisório: sem resposta válida, mostra Saldo/consulta/erro. `CreditBalanceStore` recebe as consultas reais de entitlements usadas após reserva, consumo e pagamento; respostas de outra conta são descartadas. Não grava saldo em storage. Nenhuma consulta financeira é feita durante prerender.

Minha conta ganhou navegação local, visão geral, saldo tipado, área de compra e acesso direto aos resultados. Preço e quantidade do pacote de orçamentos vêm da oferta da API, com indicação de indisponibilidade. Link do pacote abre a etapa de revisão do editor (`etapa=revisar`); consulta de orçamento no histórico aponta para Seu PDF. Compras XML continuam vinculadas ao lote. Movimentações nesta etapa são compras e contadores existentes: **não é um extrato de ledger universal**.

Home dá prioridade ao h1 e à busca, mantém sete ferramentas/categorias e o destaque de orçamentos, acrescenta FAQ sobre uso gratuito, escopo dos créditos, histórico e Pro, e reduz a repetição de CTAs finais. Metadados, URLs, canonical, schemas e sitemap preservados. Login passa a dizer “Voltar à ferramenta” nas rotas aplicáveis. Planos possui um único main global. Foco e herança de fonte incluem select e textarea.

## Componentes, arquivos e contratos

Novos:

- `feitoo-web/src/app/features/account/components/navbar-credits.ts` e `.scss`.
- `feitoo-web/src/app/features/account/services/credit-balance.store.ts` e `.spec.ts`.
- `feitoo-web/scripts/feitoo2-public.test.mjs`.
- Documentos e evidências `docs/feitoo-2-*`.

Modificados:

- Shell: `src/app/app.ts`, `.html`, `.scss`, `.spec.ts`; `src/styles.scss`.
- Conta: `features/account/pages/account-page.ts`, `.html`, `.scss`, `.spec.ts`.
- Orçamentos: `pages/quotation-page.ts`, `.html`, `.scss`, `.spec.ts`; `pages/quotation-draft.scss`; `components/quotation-items.html`; `components/quotation-commerce.ts`, `.spec.ts`; `services/quotation-generation-api.service.ts`, `.spec.ts`.
- Público/autenticação: `features/public/home.html`, `.scss`, `.spec.ts`; `features/public/plans.ts`; `features/auth/pages/auth-page.html`.
- Navegador: `scripts/check-quotation-browser.mjs`, `check-quotation-generation-browser.mjs`, `check-quotation-commerce-browser.mjs`.

Paths de frontend acima são relativos a `feitoo-web/src/app`, exceto quando indicados. Nenhum arquivo Laravel de aplicação/configuração/rota/migration foi alterado. Nenhum endpoint backend novo/modificado. Consumidos os contratos existentes de `/quotations/entitlements`, rascunhos/gerações/pedidos de orçamentos, `/account/usage`, `/account/subscription`, `/account/purchases` e `/processings`. A alteração do cliente de entitlements somente publica a resposta no store da navbar.

## Testes e resultados

| Verificação                                                                                                                                                                          | Resultado e alcance                                                                                                                                                                                            |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `npm.cmd test -- --watch=false`                                                                                                                                                      | 346 passaram, 30 arquivos; inclui etapas, campos parciais, ownership do saldo e atualização após consumo                                                                                                       |
| Build com `FEITOO_BUILD_ENV=production`                                                                                                                                              | Sucesso; 15 rotas prerenderizadas; bundle inicial 389,98 kB (110,97 kB estimados em transferência)                                                                                                             |
| `php artisan test`                                                                                                                                                                   | 497 passaram, 4.816 assertions, 7 pulados. SQLite isolado conforme phpunit.xml; testes de pagamento/refund/disputa existentes usam mocks                                                                       |
| `node --test scripts/public-assets.test.mjs scripts/home-docx-seo.test.mjs scripts/quotations-public.test.mjs scripts/tools-experience-seo.test.mjs scripts/feitoo2-public.test.mjs` | 17 passaram; main/h1, canonical, robots, schemas, sitemap, CSR privado e ausência de saldo/conta no prerender                                                                                                  |
| `node scripts/check-quotation-browser.mjs`                                                                                                                                           | 17 verificações responsivas, centavos/arredondamento, dois modelos, PNG/JPEG, 100 itens, texto seguro, foco de erro e privacidade                                                                              |
| `node scripts/check-quotation-generation-browser.mjs`                                                                                                                                | Fluxo real Angular → Laravel/Sanctum/CSRF → banco/fila → subprocesso PDF → download repetido → expiração/histórico. Também testa link de compra, navbar/Escape, áreas da conta e capturas de Home/editor/conta |
| `node scripts/check-quotation-drafts-browser.mjs`                                                                                                                                    | 24 cenários com IndexedDB real e API local simulada: restauração, texto parcial, logotipo, autenticação, duas abas, conflitos e troca de conta                                                                 |
| `node scripts/check-quotation-commerce-browser.mjs`                                                                                                                                  | Seleção explícita de crédito, falha/repetição do checkout com mesma chave, cancelado/pendente/pago e preservação do rascunho; API local simulada, sem Stripe remoto                                            |
| `node scripts/check-tools-experience-browser.mjs`                                                                                                                                    | Seis ferramentas, 96 layouts, oito downloads, fila/processando/parcial/concluído/erro/retry/expirado; contratos locais simulados, zero erro de runtime                                                         |
| `node scripts/check-monetization-browser.mjs`                                                                                                                                        | Duas ferramentas, 12 layouts, zero erro de runtime; fluxos de compra existentes simulados                                                                                                                      |
| `php scripts/check-quotation-commerce-concurrency.php`                                                                                                                               | 13 pares de conexões independentes em MariaDB 10.4.32 descartável, incluindo última unidade, refund vs finalização, eventos duplicados, período Pro, replay e claim duplicado; Stripe simulado                 |
| `git diff --check`                                                                                                                                                                   | Sem erros de whitespace                                                                                                                                                                                        |

Concorrência testada é a dos créditos específicos existentes. Não foi testada uma carteira universal que ainda não foi implementada. O daemon descartável usado nos testes foi encerrado; o script conferiu datadir e nome do banco antes de criar/remover apenas sua base temporária.

Os sete skips correspondem a LibreOffice real opcional e cenários de Linux, sinais/POSIX, Bubblewrap/prlimit ou symlink sem privilégios. O PDF E2E desta rodada usou o subprocesso PHP local de Windows; não substitui a homologação Linux já existente nem uma nova verificação do sandbox na VPS. A conversão real DOCX por LibreOffice deve ser validada no ambiente próprio antes da publicação; browser usa contrato simulado e os testes Laravel cobrem parsing/geração fiscal real e contratos DOCX.

Acessibilidade verificada: labels visíveis, foco de erro/etapa, teclado Enter para geração, Escape nos créditos, main único, áreas identificadas, alvos selecionados >=44px e overflow nos tamanhos testados. Não houve certificação WCAG, teste com leitor de tela real ou auditoria completa de contraste em todas as combinações.

Build conserva avisos de orçamento de CSS: app 4,25 kB, conta 4,52 kB, orçamento 5,79 kB, Home 6,80 kB, ferramentas 7,47 kB, acima do aviso de 4 kB e abaixo do erro de 8 kB. Não foram aumentados budgets para esconder os avisos. A prévia mantém conteúdo/identidade dos modelos, mas não promete paginação pixel a pixel igual ao Dompdf; o PDF gerado continua sendo o resultado definitivo.

## Evidências reais

Dados inteiramente fictícios, navegador Edge local. Screenshots são capturas de execução, sem mockup de imagem. JSONs acompanham as capturas. Pasta: [feitoo-2-evidencias](feitoo-2-evidencias/).

| Área                     | Desktop                                                       | Mobile                                                       |
| ------------------------ | ------------------------------------------------------------- | ------------------------------------------------------------ |
| Editor anterior          | [Antes](feitoo-2-evidencias/antes/filled-edit-1440.png)       | [Antes](feitoo-2-evidencias/antes/filled-edit-390.png)       |
| Editor, entrada          | [Depois](feitoo-2-evidencias/depois/feitoo2-editor-1440.png)  | [Depois](feitoo-2-evidencias/depois/feitoo2-editor-390.png)  |
| Minha empresa            | [Etapa 1](feitoo-2-evidencias/depois/feitoo2-step-1-1440.png) | [Etapa 1](feitoo-2-evidencias/depois/feitoo2-step-1-390.png) |
| Cliente                  | [Etapa 2](feitoo-2-evidencias/depois/feitoo2-step-2-1440.png) | [Etapa 2](feitoo-2-evidencias/depois/feitoo2-step-2-390.png) |
| Itens e valores          | [Etapa 3](feitoo-2-evidencias/depois/feitoo2-step-3-1440.png) | [Etapa 3](feitoo-2-evidencias/depois/feitoo2-step-3-390.png) |
| Revisão e prévia lateral | [Etapa 4](feitoo-2-evidencias/depois/feitoo2-step-4-1440.png) | [Etapa 4](feitoo-2-evidencias/depois/feitoo2-step-4-390.png) |
| Conta                    | [Conta](feitoo-2-evidencias/depois/feitoo2-account-1440.png)  | [Conta](feitoo-2-evidencias/depois/feitoo2-account-390.png)  |
| Créditos                 | [Navbar](feitoo-2-evidencias/depois/feitoo2-credits-1440.png) | [Navbar](feitoo-2-evidencias/depois/feitoo2-credits-390.png) |
| Home                     | [Home](feitoo-2-evidencias/depois/feitoo2-home-1440.png)      | [Home](feitoo-2-evidencias/depois/feitoo2-home-390.png)      |

Também incluídos PDF real fictício, prévia mobile e relatórios JSON de geração, responsividade, rascunhos, monetização e concorrência.

## Pendências e riscos

- Validar modelo e regras de carteira, custos operacionais, preços/pacotes, política de reembolso parcial e recuperação, prioridade de fonte e expiração. Só depois implementar migrations aditivas e endpoints universais.
- O gateway de créditos de orçamento atual é explicitamente Test-only. A nova UI não habilita Live nem relaxa essa proteção; disponibilidade continua vindo do backend. Ativar comercialmente exige trabalho/configuração separados e validados.
- Falta tratamento uniforme de chargebacks/reembolso parcial entre compras XML e orçamento; não unificar concessões antes de resolver a política.
- Provar locks em MySQL 8 alvo antes de ativar carteira; MariaDB não é prova equivalente de todos os comportamentos do MySQL 8.
- Revisar CSS acima de budgets, testar leitor de tela real e os testes Linux/LibreOffice pulados.
- Publicação, monitoramento e validação da versão em produção pendentes. Nenhum crédito real consumido; nenhum deploy, alteração de `.env` de produção, Stripe Live, Supervisor, cron, stash ou dado financeiro existente.

## Checklist de publicação, sem execução automática

- [ ] Revisar diff do frontend e documentos; escolher commit/release imutável. O frontend é submódulo: coordenar referência no repositório pai na revisão/publicação.
- [ ] Revisar configurações públicas de build na Vercel; produzir build de produção com origem/canonical corretos, sem mudar `.env` da VPS. A configuração pública gerada de desenvolvimento foi preservada no workspace após a validação.
- [ ] Rodar preview isolado apontando para API de homologação; nunca Checkout Test contra backend Live. Validar navbar com erro/sem crédito/com crédito, mobile 320–390 e desktop, login, rascunho, confirmação de consumo, PDF e histórico.
- [ ] Validar ambiente Linux/sandbox e DOCX/LibreOffice na homologação; sem alterar Supervisor/cron. Reconfirmar API privada sem cache e ownership.
- [ ] Autorizar explicitamente a publicação de UX. Esta versão não precisa de migration backend.
- [ ] Após publicar, validar Home/SEO/prerender/conta/erros sem consumir créditos reais; fluxos que consomem usam conta/ambiente de homologação. Registrar versão e evidências. Não declarar etapa de produção concluída antes disso.
- [ ] Monitorar erros JS/HTTP, consultas de entitlements, autenticação, falha de geração/download e navegação para histórico.
- [ ] Tratar implantação financeira universal como release separado, depois da aprovação da proposta, migrations testadas, custos definidos e validação Stripe Test/Live.

## Rollback

Para esta etapa de UX: republicar o artefato frontend anterior e restaurar a referência revisada do submódulo. Não executar migrations/down ou restauração de banco; os contratos e formatos de rascunho financeiros permanecem os mesmos. Conferir que rascunhos abrem na versão anterior e que histórico/download continuam acessíveis.

Para a carteira futura: procedimento detalhado na proposta. Suspender novas compras/reservas, continuar conciliando pedidos e liquidando reservas existentes, reconciliar ledger e projeções, manter compatibilidade de liquidação e corrigir somente por entradas compensatórias. Nunca descartar ledger, grants, eventos ou consumo para fazer rollback.
