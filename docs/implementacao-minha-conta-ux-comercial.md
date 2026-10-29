# Feitoo — implementação da nova Minha Conta

**Entrega local de 29/10/2026.** Redesign e correções implementados no Angular e no Laravel. Sem deploy, commit, push, migração no banco operacional, alteração de preços ou operação financeira real. O frontend já tinha HEAD diferente do registrado pela raiz; essa referência e as evidências da auditoria foram preservadas.

## Resultado e revisão visual

A Conta agora tem cinco páginas autenticadas. A visão geral mostra plano, créditos, franquia e até três arquivos. Assinatura, compra direta, histórico completo e formulários têm destinos próprios. As ações financeiras usam as capacidades devolvidas pelo servidor.

**[Abrir galeria comparativa e capturas](implementacao-minha-conta-evidencias/index.html)**. São 65 capturas, 97 verificações no navegador e seis larguras: 320, 375, 390, 768, 1024 e 1440 px, sempre com 900 px de altura. Dados pessoais, pedidos e respostas do navegador são simulados.

| Indicador                                | Antes                                   | Depois                               |
| ---------------------------------------- | --------------------------------------- | ------------------------------------ |
| Página completa Free vazia, 1440 px      | 3.242 px                                | 1.318 px                             |
| Página completa Free vazia, 390 px       | 5.241 px                                | 1.769 px                             |
| Palavras no conteúdo da Conta Free vazia | 411                                     | 55 na visão geral                    |
| CTA Pro em 390 px                        | Aproximadamente y=1.997 na página única | y=482–527 na página de assinatura    |
| Compra de créditos                       | Não iniciava compra na Conta            | Botão direto em y=692–737, em 390 px |
| Visão geral com arquivos                 | Dez registros e demais seções           | No máximo três registros             |

As alturas completas incluem o rodapé compartilhado. A área da Conta, incluindo seu menu, mede 714 px no desktop vazio e 883 px em 390 px. As novas capturas usam o build de produção; a auditoria usou development, que incluía o diagnóstico “API conectada” fora do conteúdo da Conta. A redução decorre da separação das jornadas, não da remoção de direitos ou históricos.

Referências diretas: [antes mobile](auditoria-minha-conta-evidencias/free-zero-390.png), [nova visão geral mobile](implementacao-minha-conta-evidencias/visao-geral-390.png), [assinatura mobile](implementacao-minha-conta-evidencias/assinatura-390.png), [compra mobile](implementacao-minha-conta-evidencias/creditos-390.png), [visão geral desktop](implementacao-minha-conta-evidencias/visao-geral-1440.png), [créditos tablet](implementacao-minha-conta-evidencias/creditos-768.png).

## Problemas corrigidos

| Prioridade | Correção                                                                                                                                |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| P1         | Substituídas âncoras quebradas por RouterLink. Fragmentos antigos têm redirecionamento explícito.                                       |
| P1         | Compra direta de cinco créditos por R$ 9,90 na Conta, tanto para Free quanto para Pro com franquia disponível.                          |
| P1         | Tentativa Pro local incompleta pode retomar o mesmo fluxo. Contrato remoto incompleto não libera segunda assinatura.                    |
| P1         | Portal separado da disponibilidade de uma nova oferta Pro. Price ausente não bloqueia seu cliente Stripe.                               |
| P1         | Confirmação Pro invalida XML e orçamentos e atualiza o CreditBalanceStore; não mantém uma franquia Free antiga junto de Pro confirmado. |
| P1         | Falha de consulta da assinatura tem retry próprio. Retorno, pendência, atraso e indisponibilidade recebem mensagens distintas.          |
| P1         | Sessões 401/419 removem os dados visíveis e preservam destino seguro e tentativa técnica; login não reenvia uma compra.                 |
| P1         | Rotas Pro agora usam sessão e CSRF obrigatórios mesmo sem Origin/Referer. Checkout e Portal consultam o cookie CSRF antes do POST.      |
| P2         | Visão geral curta, navegação compacta de uma linha e carregamento por área. Sem sidebar administrativa ou Bootstrap Reboot global.      |
| P2         | Históricos têm estado vazio apenas após sucesso. Pendências permitem consultar e, quando autorizado, retomar o pagamento existente.     |
| P2         | Perfil, senha e Google isolados; prazo de cada arquivo preservado; rótulos distinguem resultados PDF/ZIP e Excel.                       |
| P2         | Exclusão conserva confirmação explícita e devolve foco ao botão somente depois que Angular remove sua desabilitação.                    |
| P2         | Subrotas recebem shell CSR e noindex HTTP na Vercel, inclusive em acesso direto.                                                        |

## Arquitetura final

| Rota                | Componente                | Consultas e função                                                                                       |
| ------------------- | ------------------------- | -------------------------------------------------------------------------------------------------------- |
| `/conta`            | `AccountPage`             | Assinatura, benefícios e `processings?per_page=3`; sem pedidos ou formulários.                           |
| `/conta/assinatura` | `AccountSubscriptionPage` | Resumo contratual, Checkout/Portal, confirmação limitada e atualização dos benefícios.                   |
| `/conta/creditos`   | `AccountCreditsPage`      | Saldo, oferta, compra, retorno e pedidos de orçamentos. Compras XML só carregam ao abrir seu disclosure. |
| `/conta/arquivos`   | `AccountFilesPage`        | Histórico paginado, status, download privado, exclusão e acesso à ferramenta correspondente.             |
| `/conta/perfil`     | `AccountProfilePage`      | Reactive Forms de nome/senha, e-mail somente leitura, vínculo Google, data e links legais.               |

`AccountShell` mantém o menu e o outlet. O guard protege pai e filhos. A seleção usa `aria-current="page"`; o H1 recebe foco após a navegação; no mobile, a navegação rola horizontalmente e conserva o item selecionado visível. Uma troca de proprietário desmonta o conteúdo anterior.

Os componentes reutilizam os serviços de histórico, processamento, autenticação e cobrança. Estilos comuns usam os tokens existentes e estilos por página. Nenhuma biblioteca visual foi adicionada; Bootstrap continua isolado no gerador. A nova Conta não ultrapassa o budget de estilos de componente.

Fragmentos preservados: `account-overview`, `overview-title`, `credits-title`, `buy-credits-title`, `quotation-credits-title`, `purchases-title`, `plan-title`, `history-title`, `profile-title`, `security-title` e `privacy-title`. Retornos antigos `/conta?subscription=...` vão à assinatura; erros antigos de Google vão ao perfil. Os destinos de login e OAuth têm allowlist para as cinco rotas. O UUID de retorno de créditos é contexto para GET autenticado, nunca prova de pagamento.

## Contratos HTTP

As URLs comerciais existentes foram conservadas.

| Contrato                                  | Alteração                                                                                                                                                                                                                                                   |
| ----------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /api/account/subscription`           | Acrescenta `state`, `actions.{checkout,resume_checkout,portal,refresh}` e preço contratado opcional em `subscription.{amount,currency}`. Mantém oferta, status, período, cancelamento e `grants_pro`. Não cria Checkout nem chama Stripe ao abrir a página. |
| `POST /api/account/subscription/checkout` | Continua sem preço do navegador e reutiliza o registro/chave Stripe existentes. Erros comerciais estáveis; rejeita sessão já concluída e tentativa antiga ambígua sem Session.                                                                              |
| `POST /api/account/subscription/portal`   | Mantém proprietário e Customer autenticados. Não exige Price de nova oferta. Retorna a `/conta/assinatura`.                                                                                                                                                 |
| Rotas `/api/account/subscription/*`       | Movidas do grupo API para `web.php`, mantendo os caminhos, autenticação e throttles. Sessão e CSRF passam a ser obrigatórios independentemente de Origin.                                                                                                   |
| `POST /api/quotations/credit-orders`      | Corpo padrão `{}` permanece válido para o editor. Aceita apenas o novo `return_destination: "account"                                                                                                                                                       | "editor"`, além da chave UUID no header. Preço, moeda, quantidade e outros campos continuam recusados. |
| Recursos de pedido de créditos            | Acrescentam `expires_at` e `actions.{refresh,resume_checkout}`. Checkout URL não é exposta para estados encerrados ou prazo vencido. Consulta individual confirma se a Session ainda permite retomada.                                                      |
| `GET /api/processings`                    | `per_page=3` permite o resumo. Outros valores mantêm o limite de dez; proprietário e projeção dos dados continuam iguais.                                                                                                                                   |
| Google `return_to`                        | Acrescenta as subrotas à allowlist. Vínculo e erro de vínculo voltam a `/conta/perfil`.                                                                                                                                                                     |

Códigos Pro: `checkout_unavailable` (503), `subscription_exists` (409), `subscription_confirmation_pending` (409), `subscription_reconciliation_required` (409) e `portal_unavailable` (409). Erros de ambiente Stripe existentes continuam válidos. 401/419, 422, 429, rede e timeout têm recuperação distinta na interface.

Há **uma migration aditiva nova**: `2026_10_29_000001_add_account_commerce_context.php`. Ela acrescenta `quotation_credit_orders.return_destination` com default `editor` e preço/moeda contratados opcionais em `subscriptions`. Pedidos existentes mantêm destino e payload Stripe originais. Nenhum ledger ou plano novo foi criado.

## Matriz da assinatura

| Estado                                        | Ação segura                                                                                 |
| --------------------------------------------- | ------------------------------------------------------------------------------------------- |
| Sem contrato                                  | Assinar se a oferta estiver disponível.                                                     |
| Active com período vigente                    | Gerenciar.                                                                                  |
| Cancelamento agendado e acesso vigente        | Gerenciar e informar fim do acesso.                                                         |
| Past due / unpaid                             | Regularizar no Portal; sem segundo Checkout.                                                |
| Incomplete local, sem contrato remoto         | Retomar a tentativa existente quando recuperável.                                           |
| Incomplete com contrato remoto                | Gerenciar o pagamento existente.                                                            |
| Canceled / incomplete expired                 | Nova contratação quando a oferta permitir.                                                  |
| Active com período encerrado                  | Orientação de conciliação e Portal; não libera nova assinatura.                             |
| Paused / trialing                             | Mostrar estado contratual e gerenciamento; não inventar acesso Pro.                         |
| Tentativa ambígua sem Session com 23h ou mais | Bloquear replay além da janela conservadora da chave Stripe e orientar conciliação/suporte. |
| Erro de leitura                               | Retry específico sem ação de contratação presumida.                                         |

Preço ofertado vem da configuração comercial. Novas tentativas registram o valor e os webhooks o atualizam pelo item remoto validado. Contratos antigos sem valor persistido mostram que o valor está no gerenciamento; a oferta atual não é apresentada como sua mensalidade histórica.

## Stripe e sincronização

### Pro

O clique explícito executa CSRF e POST. O backend serializa por proprietário, preserva Customer, registro local e chave `feitoo-pro-<id>`. Uma Session aberta pode ser reutilizada; uma concluída aguarda confirmação; somente expiração remota comprovada permite outro fluxo quando elegível. A URL de retorno não concede Pro.

O resumo continua local e a confirmação vem do webhook com validação remota existente. O polling é de três segundos, até dez consultas ou trinta segundos, sem sobreposição. Navegação, destruição, limite ou erro interrompem o ciclo. O Portal não depende da venda de uma nova assinatura.

`AccountBenefits.refresh(expectedPro)` invalida o snapshot XML e o saldo de orçamentos antes de consultar os dois contratos. Publicação considera proprietário e sequência da atualização. Se os dados não concordarem com o estado confirmado, mostra sincronização/retry e oculta o snapshot antigo. O `QuotationGenerationApi` evita publicar no store uma resposta anterior a outra consulta mais recente. A navbar lê o store compartilhado; o gerador consulta benefícios novamente ao ser aberto e mantém a prioridade Pro e a escolha explícita de crédito.

### Créditos

O botão compra um pacote fixo do servidor: cinco créditos, 990 centavos, BRL, pagamento único. Não exige orçamento, rascunho ou esgotamento da franquia. A chave por proprietário fica em sessionStorage antes do POST e sobrevive a timeout, refresh e login. A URL só é usada após validação HTTPS/hostname Stripe.

O destino é persistido no primeiro pedido. Repetir a mesma chave com outra origem não muda os parâmetros da Session. O editor continua voltando ao editor; compras da Conta voltam a `/conta/creditos?quotation_order=<uuid>`. Não há parâmetro de URL arbitrária.

No retorno, somente GET do pedido, até oito consultas automáticas de cinco segundos. O backend pode conciliar o pagamento remoto ou recebê-lo por webhook. Só confirmação validada concede o grant existente. Nenhum retorno gera PDF ou executa outro POST de compra. Retomar um pedido listado faz GET antes de abrir sua Session. Pedido confirmado atualiza saldo; retorno antigo não apaga a chave de uma tentativa mais recente.

Refund, disputa, reservas, consumo e isolamento TEST/LIVE continuam no ledger existente. Reembolso parcial/integral bloqueia saldo restante; consumo concluído permanece. A regra de reembolso é visível junto da oferta; detalhes adicionais ficam no disclosure. Crédito não expira comercialmente; PDF de orçamento mantém duas horas após concluir.

## Erros, sessão e acessibilidade

- 401/419 limpam autenticação e saldo e abrem login com destino permitido. Não reapresentam dados de outro proprietário nem reenviam compra.
- UUIDs de pedido são validados e consultados sob o proprietário autenticado. No retorno por Google, o contexto técnico fica na sessão do navegador; outra conta continua sujeita à autorização da API.
- 409 atualiza o contexto e orienta retomada/gestão; 422 pede revisão dos dados; 503 comunica indisponibilidade, sem representar pendência como falha de pagamento.
- 429 conserva `Retry-After`, inclusive como data HTTP; há bloqueio de novas operações durante a espera. Polling tem orçamento e para em erro.
- Timeout/rede conservam a tentativa e a chave. Nenhuma repetição é automática após autenticação.
- Formulários mantêm validação, autocomplete, e-mail readonly e feedback sem expor mensagens remotas sensíveis. Exclusão tem confirmação e restauração de foco.

## Validação executada

| Ensaio                                                                   | Resultado                                                                                                                                                                                                                              |
| ------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Laravel completo final, SQLite `:memory:`, chaves e transportes de teste | **531 aprovados, 7 skips, 5.157 assertions**, sem falhas. [JUnit](implementacao-minha-conta-evidencias/phpunit.xml).                                                                                                                   |
| Angular completo final                                                   | **360 testes, 35 arquivos**, aprovados. [Log](implementacao-minha-conta-evidencias/frontend-tests.txt).                                                                                                                                |
| Build de produção padrão                                                 | Aprovado, 15 rotas prerenderizadas. Sem novo budget excedido na Conta. [Log](implementacao-minha-conta-evidencias/build.txt).                                                                                                          |
| SEO, assets e regras Vercel                                              | Cinco testes aprovados, incluindo CSR/noindex das quatro novas subrotas.                                                                                                                                                               |
| Navegador da nova Conta                                                  | **97 verificações**, **65 capturas**; navegação real, teclado, fragmentos, foco, perfil, Google simulado, compra, confirmação, loading/erro/vazio, sessão e seis larguras. [Checks](implementacao-minha-conta-evidencias/checks.json). |
| Regressão visual/funcional das seis ferramentas                          | **96 layouts, oito downloads simulados, zero erros de runtime**. [Relatório](implementacao-minha-conta-evidencias/tools-report.json).                                                                                                  |
| Formatação e diff                                                        | Pint/Prettier e conferência de whitespace.                                                                                                                                                                                             |

A suíte backend cobre contratação, tentativa ambígua, contrato remoto, cancelamento, atraso/recuperação, Portal sem oferta, CSRF sem Origin, throttles, confirmação sem pagamento, duplicação de webhook, refund/disputa e isolamento de usuário/ambiente, além da regressão fiscal e DOCX. Os skips são o teste opcional de LibreOffice e seis controles que exigem recursos Linux/POSIX/symlink ausentes neste Windows. Nenhuma alteração no sandbox de execução foi feita.

O navegador usa build de produção servido localmente. Somente no transporte do servidor de teste, a origem da API do bundle é substituída por localhost, sem editar o artefato. A interceptação de requisições inclui navegação de documento para que URLs fictícias Stripe sejam atendidas por HTML local. Uma primeira execução deixou a página pública da URL fictícia carregar e falhou em chunks bloqueados; não criou Session real nem pagamento. A execução final usa interceptação integral e passou sem exceções. Os contratos Stripe SDK usam transportes simulados, inclusive nos casos chamados “LIVE simulated”. Isso não equivale a ensaio financeiro Live.

Permanecem os avisos anteriores de Sass `@import` no Bootstrap e budgets de `app.scss`, home, gerador e ferramentas. Os defaults locais continuam com orçamentos/compra desligados e sem Price de créditos, conforme [presença de configuração sem segredos](implementacao-minha-conta-evidencias/config-presence.json). As capturas simulam oferta disponível para validar a implementação independentemente dessa configuração.

## Dependências e riscos financeiros remanescentes

1. **Checkout externo TEST ainda precisa de aceite operacional.** Não há Price de créditos configurado localmente. Antes de vender, conferir produto/Price BRL 990, metadata, ambiente, flags, webhook e cron conforme o documento de lançamento existente. Não trocar modo global de uma instalação operacional para homologar.
2. Recursos de cartão, cancelamento e faturas dependem da configuração padrão do Portal Stripe. Não foram inspecionados ou alterados em Live.
3. Confirmação Pro depende da entrega e conciliação do webhook existente. Período encerrado ou tentativa antiga ambígua recebe recuperação conservadora, sem nova cobrança automática. A consulta do resumo não faz reconciliação remota nova.
4. Preços históricos sem snapshot ficam sem valor local até atualização válida pelo provedor; a interface encaminha ao gerenciamento.
5. Nenhum ensaio concorrente novo em MySQL/MariaDB ou execução Linux foi feito nesta entrega. Locks/constraints e ledger existentes foram conservados; os ensaios concorrentes anteriores estão documentados no lançamento do gerador.
6. Edge com viewports estreitos não substitui iOS/Android físicos ou leitor de tela. Esses aceites e Google externo real permanecem na homologação controlada.

## Publicação controlada — comandos não executados

Preservar `.env`, APP_KEY, cookies/Sanctum/CORS, storage, modo Stripe, flags e configuração comercial existentes. Conferir backup e revisão aprovada. Aplicar a migration aditiva **antes de servir o backend novo**, pois o resumo passa a ler suas colunas. Não remover as colunas para fazer rollback de release.

```bash
# Na release preparada da API, com caminho e usuário reais confirmados:
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan migrate:status
php artisan migrate --pretend --path=database/migrations/2026_10_29_000001_add_account_commerce_context.php
# Na janela aprovada, após conferir o plano e o backup:
php artisan migrate --force --path=database/migrations/2026_10_29_000001_add_account_commerce_context.php
php artisan route:clear
php artisan config:cache
php artisan view:cache
php artisan route:list --path=account/subscription
php artisan route:list --path=quotations/credit-orders
```

Recarregar FPM/OPcache pelo procedimento existente e pelo nome de serviço efetivamente instalado. Não reiniciar workers globais ou banco. O código comercial continua compatível com os pedidos existentes. Novas tentativas Pro conservadoras com mais de 23h sem Session devem ser conciliadas, não apagadas.

```powershell
# Verificação local frontend, antes da publicação autorizada:
Set-Location feitoo-web
npm.cmd ci
npm.cmd test -- --watch=false
npm.cmd run build
node --test scripts/public-assets.test.mjs
node scripts/check-tools-experience-browser.mjs
Set-Location ..
node docs/implementacao-minha-conta-evidencias/capture.mjs
```

Publicar o frontend pelo projeto Vercel existente, depois da API, com os valores de Production já aprovados. Incluir `vercel.json` novo. Os flags locais de revisão não devem substituir a configuração operacional. O documento de lançamento do gerador continua sendo a referência para ativação e preflight de vendas, workers e webhooks.

## Verificações posteriores ao deploy

- Abrir diretamente e atualizar as cinco rotas; conferir CSR, noindex, sessão, voltar após login, menu mobile e teclado.
- Conferir plano, preço contratado/ofertado, período, retomada local e Portal de uma assinatura existente, sem criar contrato adicional.
- Confirmar que ativação real por webhook atualiza XML, franquia Pro de orçamentos e navbar; falha em benefícios deve oferecer atualização.
- Em laboratório TEST separado, concluir compra, verificar exatamente um grant de cinco, retorno sem pagamento, retorno repetido, expiração e webhook duplicado. Refazer com Free e Pro com franquia disponível.
- Conferir pedidos, download e expiração por arquivo, exclusão, perfil e vínculo Google. Validar as seis ferramentas sem alterar limites.
- Conferir logs sanitizados, webhook e configuração do Portal. Live, reembolso, mudança de configuração e publicação exigem sua janela/autorização própria; não foram executados aqui.
