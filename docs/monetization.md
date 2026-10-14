# Monetização — Etapa 12A

## Auditoria e decisões

O fluxo anterior criava um Processing, enviava no máximo 20 XMLs e chamava `/start` explicitamente. Sanctum, ownership, armazenamento privado, checksums, fila, parser, XLSX, preview, TTL e histórico foram reutilizados. Não existia integração Stripe ou plano persistido. Free é inferido; não existe registro de plano por usuário.

O uso diário fica no próprio Processing, com `entitlement_source`, `usage_date` e `usage_files_count`. O índice composto evita varrer arquivos ou resultados. Exclusão e purge preservam esses marcadores. Processamentos anteriores à implantação não são retroativamente cobrados da cota.

## Free, consumo e reset

`FREE_MAX_FILES_PER_PROCESSING=20`, `FREE_DAILY_FILES=50`: hipóteses configuráveis em `config/monetization.php`. Reset às 00:00 de `America/Sao_Paulo`, independentemente do timezone do servidor. A resposta de uso contém plano, limites, usado, restante e `resets_at` com offset.

A aceitação transacional de `/start` consome a quantidade de inputs escolhidos, depois da validação de propriedade, estado, TTL e presença de arquivos. Seleção, upload, tentativas rejeitadas e XML rejeitado na validação de upload não consomem. XML aceito para processamento que depois falha na interpretação fiscal consome capacidade. Retry de start é bloqueado pelo estado; retry do worker não altera consumo. Falha de despacho libera a reserva e marca falha. Exclusão de arquivos não devolve uso já aceito.

`EntitlementService` centraliza a decisão. O start trava Processing e User; a leitura corrente do uso diário também usa locks para não reutilizar um snapshot anterior do MySQL. Transações podem repetir em deadlock. Uma compra paga, com volume compatível e não consumida, libera apenas seu Processing e não consome Free.

## Compra avulsa e pacotes

| Código | Capacidade | Preço inicial | Estado |
| --- | ---: | ---: | --- |
| xml_100 | 100 XMLs | R$ 4,90 | Habilitado |
| xml_250 | 250 XMLs | R$ 6,90 | Habilitado |
| xml_500 | 500 XMLs | R$ 9,90 | Desabilitado por limite técnico |
| xml_1000 | 1.000 XMLs | R$ 14,90 | Desabilitado por limite técnico |

Os preços ficam em centavos inteiros nas variáveis `SINGLE_PURCHASE_*_CENTS`. O backend escolhe o menor pacote suficiente: 147 XMLs → xml_250 → 690 centavos BRL. O cliente não determina preço, moeda ou capacidade. A sobra não vira crédito. A compra libera trabalho; não garante validade fiscal nem provoca reembolso automático por XML inválido.

Uma Purchase por Processing, com UUID público, snapshot de volume/preço, status enum, expiração, confirmação e consumo. IDs de sessão/intenção, URL interna de Checkout e campos de banco não aparecem no resource. Não há dados de cartão. Status: pending, paid, failed, expired, refunded.

## Limites técnicos e upload em lotes

Separados do limite comercial: até 20 arquivos e 30 MiB por request; 250 arquivos, 40 MiB e 4.000 itens por Processing. Variáveis `FEITOO_MAX_FILES`, `FEITOO_MAX_REQUEST_SIZE_KB`, `FEITOO_MAX_PROCESSING_FILES`, `FEITOO_MAX_TOTAL_SIZE_KB`, `FEITOO_MAX_PROCESSING_ITEMS` permitem reduzir os limites. Os tetos superiores exigem novo benchmark e mudança deliberada no código. Limite por arquivo permanece configurado em `FEITOO_MAX_FILE_SIZE_KB`.

O benchmark local `php artisan feitoo:benchmark-xml-to-excel --documents=250 --items=16` produziu 4.000 itens, pico de 132 MiB e duração de 31,56 s sob PHP de 512 MiB. É um benchmark sintético; não representa garantia universal para XMLs reais. O total de itens é verificado antes de gerar XLSX; lote acima da capacidade termina com mensagem segura. Pacotes 500/1.000 não são expostos.

Angular envia lotes sequenciais, informa quantidade enviada e só inicia após o último lote. Retenta apenas 429 com Retry-After limitado; falhas de rede ambíguas não são reenviadas para evitar duplicação. Rollback de cada request, checksums e ownership continuam existentes. O lote fica congelado para novos uploads quando uma compra é criada.

## API

| Método e rota | Contrato |
| --- | --- |
| GET /api/tools/xml-to-excel/config | Limites técnicos, Free, pacotes disponíveis e disponibilidade de Checkout; sem secrets |
| GET /api/account/usage | Uso diário do próprio usuário |
| GET /api/account/purchases?page=1 | Compras próprias, 10 por página, mais recentes primeiro |
| POST /api/processings/{uuid}/purchase | Backend determina pacote/preço; retorna Purchase e URL hospedada |
| GET /api/processings/{uuid}/purchase | Estado confirmado pelo backend |
| POST /api/processings/{uuid}/purchase/cancel | Encerra a sessão na Stripe antes de liberar alternativa Free |
| POST /api/processings/{uuid}/start | Aceitação Free ou compra paga; `free_files_count` opcional representa escolha explícita dos primeiros N inputs |
| POST /api/stripe/webhook | Público, sem CSRF, assinatura Stripe obrigatória |

Mutações de browser mantêm sessão HttpOnly, CSRF e ownership. Códigos de negócio incluem purchase_required, usage_limit_exceeded, payment_pending, purchase_expired, technical_limit_exceeded e checkout_unavailable. Não existem URLs públicas de resultado.

## Stripe Checkout, assinatura e idempotência

SDK oficial `stripe/stripe-php` 22.0.0; sem Cashier, Billing, formulário de cartão ou Stripe.js no frontend. Somente `sk_test_*` é aceito. Webhook requer `whsec_*`; Checkout fica indisponível sem ambas as configurações. A URL de retorno nunca concede acesso.

A Purchase é persistida antes da chamada externa. A sessão usa a chave estável `purchase-{uuid}`, preço/volume/expiração imutáveis e metadata mínima: purchase_uuid, processing_uuid, package_code. Nenhum XML, nome de arquivo ou dado fiscal vai para a Stripe. Sessão padrão expira em 35 minutos; não é criada perto do fim do TTL do lote.

Eventos necessários: checkout.session.completed, checkout.session.expired, payment_intent.payment_failed, charge.refunded. Checkout usa somente cartão; confirmação exige payment_status=paid, mode=payment, test mode, session ID, correlação, moeda e total exatos. Refund integral revoga a compra; refund parcial e administração financeira estão fora do escopo. Evento expirado/falha não rebaixa uma compra paga. Refund não é revertido por completed tardio.

`Webhook::constructEvent` valida assinatura sobre corpo bruto; assinatura inválida retorna 400. Um registro único por event ID, na mesma transação da alteração de estado, elimina replay. Não armazena payload completo. Evento recebido antes de persistir Session causa retry seguro, sem marcar evento como processado.

Expiração local não prova que a Stripe encerrou Checkout. `checkout_closed_at` só é definido por confirmação remota ou webhook signed de expiração. Free não é liberado enquanto a sessão puder aceitar pagamento. Cancelamento recupera sessão ambígua com a mesma chave antes de encerrá-la. Scheduler `feitoo:expire-pending-purchases`, a cada cinco minutos, marca abandono sem inventar encerramento remoto.

## UX, conta e analytics

Free aparece discretamente com restante diário. Paywall sugere menor pacote; usuário escolhe explicitamente primeiros N grátis ou todos pagos. Arquivos excedentes locais permanecem recuperáveis ao iniciar outra conversão; após retorno externo, inputs excedentes já enviados ficam privados até exclusão/TTL e não são processados. Compra não cria saldo reutilizável.

Retorno success consulta Processing/Purchase e faz polling por até aproximadamente 90 s, sem confiar na query string. Só compra paga inicia. Cancelled mostra feedback e não inicia automaticamente. Há consulta manual e retomada de compra paga em `/conta`. Checkout encerrado exige novo lote para outra compra; não existe segunda Purchase no mesmo lote.

Conta mostra uso e compras paginadas, sem detalhes Stripe. Analytics: free_limit_reached, paywall_viewed, single_purchase_started, single_purchase_completed, single_purchase_failed. Allowlist com tool, files_count_bucket, package_code, amount em centavos e currency; sem UUID, e-mail, dados fiscais ou nomes. Consentimento continua obrigatório. Evento completed é deduplicado em sessionStorage por compra; webhooks não enviam analytics. Isso não substitui reconciliação financeira ou mensuração entre dispositivos.

O saldo é atualizado também após o horário de reset enquanto a página permanece aberta. A ação de retomada na conta depende da flag segura `can_resume_processing`, calculada pelo backend considerando pagamento, consumo, status do lote e TTL.

## Configuração manual em TEST MODE

Não houve alteração do `.env` real nem inclusão de chaves no frontend. Configure localmente `STRIPE_SECRET_KEY=sk_test_...` e `STRIPE_WEBHOOK_SECRET=whsec_...`. Não precisa de public key no fluxo hospedado. Depois limpe cache com `php artisan config:clear`. Mantenha FRONTEND_URL correto, worker e scheduler ativos.

Instale a CLI oficial; confira sua versão e ajuda. Comandos documentados na [Stripe CLI](https://github.com/stripe/stripe-cli/wiki/Listen-command):

```powershell
stripe --version
stripe login
stripe listen --events checkout.session.completed,checkout.session.expired,payment_intent.payment_failed,charge.refunded --forward-to http://127.0.0.1:8000/api/stripe/webhook
```

Copie o whsec temporário exibido pelo listen para o ambiente local. No Dashboard de teste, crie endpoint equivalente e inscreva somente esses eventos. CLI forwarding e Dashboard têm secrets diferentes. Mantenha a API/schema compatível com a versão do SDK; não altere o formato de eventos sem verificar fixtures/testes.

Use o [cartão de teste oficial](https://docs.stripe.com/testing): 4242 4242 4242 4242, validade futura e CVC de teste. Para recusa, use os cenários oficiais, como 4000 0000 0000 0002. Nunca use cartão real. Eventos genéricos de `stripe trigger` sem a metadata/correlação da compra não comprovam fulfillment; faça a compra pelo aplicativo.

Checklist manual: 10 Free → uso +10; uso45 +seleção10 → escolha5 grátis; seleção147 → pacote250 → Checkout TEST → webhook → start sem Free; cancelamento → nenhum start; reenviar mesmo evento no Dashboard → nenhum consumo/compra duplicado.

Referências oficiais: [SDK PHP](https://github.com/stripe/stripe-php), [Checkout fulfillment](https://docs.stripe.com/checkout/fulfillment), [webhooks](https://docs.stripe.com/webhooks), [criação da sessão](https://docs.stripe.com/api/checkout/sessions/create).

## Validação e limitações

Resultado local em 08/10/2026: 201 testes backend e 99 frontend aprovados; migration MySQL aplicada; Pint, Prettier, build e route:list aprovados. Concorrência MySQL: um lote aceito, um rejeitado, uso total 5/5. Navegador Edge: ferramenta/conta/paywall sem overflow em 320, 375, 768, 1024 e 1440 px; cancelamento não inicia; retorno success pendente não inicia; confirmação sintética inicia uma vez. Não houve erros de runtime. Relatórios e screenshots locais ficam em `feitoo-web/dist/validation/`.

Chaves TEST e webhook não estavam configuradas; Stripe CLI não estava instalada. Portanto, o Checkout remoto com pagamento de teste e forwarding real não foi executado. A confirmação foi coberta com SDK de assinatura, eventos assinados sintéticos e gateway mockado. Requisições de consulta possuem timeout e polling limitado; falha ambígua no Checkout mantém a compra recuperável pela mesma chave.

Backend: migrate, artisan test, pint --test, route:list --path=api. Frontend: npm.cmd test -- --watch=false, npm.cmd run build e Prettier. `php scripts/check-usage-concurrency.php` usa dois processos e conexões MySQL reais, saldo de 5 e dois lotes de 5: um aceita, um rejeita. Cria apenas fixtures temporárias e remove seus próprios registros; não executa jobs nem lê arquivos fiscais.

O smoke test de navegador usa respostas sintéticas, jamais dados ou pagamentos reais. Checkout real depende de credenciais TEST e forwarding ativo. A ausência dessas configurações mantém compra indisponível. Recibo não é exposto nesta etapa. Não há assinatura Pro, créditos, Pix, boleto, cupons, Portal, painel financeiro ou reembolso administrativo. A camada de entitlement permite adicionar outra fonte de acesso futuramente sem espalhar regras nos controllers.

## Arquivos criados nesta etapa

- Backend: `config/monetization.php`; migration `2026_10_08_120000_add_processing_entitlements_and_purchases.php`; `Enums/PurchaseStatus.php`; `Exceptions/BillingException.php`; `Models/Purchase.php`.
- Serviços: `EntitlementService.php`, `PurchaseService.php`, `StripeCheckoutGateway.php`, `StripeWebhookService.php`.
- HTTP: `BillingController.php`, `StripeWebhookController.php`, `PurchaseResource.php`.
- Operação/testes: `Console/Commands/ExpirePendingPurchases.php`, `tests/Feature/MonetizationTest.php`, `scripts/check-usage-concurrency.php`.
- Frontend: `features/processing/services/billing.service.ts` e `billing.service.spec.ts`.
- Documentação: `docs/monetization.md`.

## Arquivos modificados nesta etapa

- Backend: `.env.example`, `composer.json`, `composer.lock`, `config/feitoo.php`, `bootstrap/app.php`, `routes/api.php`, `routes/web.php`, `routes/console.php`.
- Backend existente: `Models/Processing.php`, `Services/ProcessingService.php`, `Services/FiscalProcessingService.php`, `Http/Controllers/ProcessingController.php`, `Http/Resources/ProcessingResource.php`, `Http/Requests/UploadProcessingFilesRequest.php`.
- Testes existentes: `ProcessingTest.php`, `FiscalProcessingTest.php`, `XmlToExcelRefinementTest.php`.
- Frontend: modelo/service de Processing; página XML para Excel TS/HTML/SCSS/spec; página Conta TS/HTML/SCSS/spec; `core/analytics.service.ts`, `core/public-launch.spec.ts`; `scripts/check-tool-browser.mjs`.

Arquivos anteriores das etapas 10 e 11 permanecem no workspace. O parser fiscal e a geração do workbook não foram alterados nesta etapa.
