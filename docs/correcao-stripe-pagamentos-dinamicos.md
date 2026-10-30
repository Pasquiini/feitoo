# Correção Stripe — pagamentos dinâmicos

Data: 29/10/2026. Implementação local, preparada para publicação controlada. Nenhum deploy, commit, push, pagamento real, POST Stripe Live, alteração de Dashboard, `.env`, APP_KEY, chave, preço ou banco operacional foi executado. Os pedidos Live internos **#1 e #2 não foram consultados nem alterados**. Não há migration nova.

## 1. Causa raiz e correções

O SDK instalado é `stripe/stripe-php` **22.0.0**; `vendor/stripe/stripe-php/lib/Util/ApiVersion.php` define a API padrão **2026-09-30.endive**. Essa versão removeu `payment_method_types` da criação de Checkout Sessions. O parâmetro estava presente nas compras de créditos e fiscais. A documentação oficial confirma a incompatibilidade: [changelog Checkout Endive](https://docs.stripe.com/changelog/endive/2026-09-30/remove-payment-method-types-checkout-sessions).

Foram encontradas três criações de Checkout: `QuotationStripeGateway::checkout`, `StripeCheckoutGateway::create` e `StripeSubscriptionGateway::checkout`. As duas primeiras deixam de enviar o parâmetro; a terceira já o omitia e ganhou cobertura explícita no nível HTTP do SDK. Não foi introduzido `allowed_payment_method_types`, `excluded_payment_method_types`, lista de alternativas ou parâmetro de PaymentIntents dentro de Checkout. O Portal continua usando sua API própria. Não havia configuração de métodos de invoices/subscriptions a remover.

O Stripe passa a selecionar os métodos elegíveis dentre os habilitados no Dashboard, considerando modalidade, moeda, valor, conta e cliente. Isso não garante a exibição de todos os métodos em cada compra. [Métodos dinâmicos](https://docs.stripe.com/payments/payment-methods/dynamic-payment-methods).

Foram preservados os Prices/Products existentes, `price_data` fiscal, quantidades, moeda, valores, metadata, referências técnicas, URLs, Customer Pro, chaves idempotentes e separação TEST/LIVE. Os créditos continuam sendo cinco por R$ 9,90; Pro mantém R$ 29,90/mês e cobrança recorrente. Pacotes fiscais mantêm sua configuração comercial.

Além da incompatibilidade:

- Compras fiscais passam a consumir os eventos de sucesso/falha assíncronos, com a mesma aplicação financeira usada pela reconciliação. Sucesso exige `payment_status=paid`, identidade do pedido/lote, total, moeda, quantidade e preço unitário do item. Fiscais usam Price inline, sem ID de Price persistido; a comparação é com o contrato imutável da compra.
- Créditos passam a conferir também o ID do Price persistido, quantidade, valor unitário, moeda e ambiente do item. Webhooks normalmente omitem `line_items`; nesse caso há GET dos itens. GET de Session expande os itens. A reconciliação também pode recuperar um pedido `failed` posteriormente pago.
- Uma Session fiscal criada com resposta perdida pode ser registrada por webhook assinado, após validar sua identidade, ou recuperada por listagem GET limitada à janela original, com limite de 200 resultados e rejeição de duplicidade. Não se cria outra cobrança durante essa busca.
- Pro consulta a assinatura atual com `latest_invoice` expandida. Para conceder acesso, exige status contratual `active`, `collection_method=charge_automatically`, última invoice `paid`, Customer/referência de assinatura, total/moeda e item compatíveis com o contrato. Não reescreve valores contratados a partir de item divergente. A entrega de um evento antigo consulta o estado atual, incluindo renovações e recuperação.

O cuidado com Pro é necessário porque métodos com confirmação posterior podem deixar a assinatura remota `active` com invoice ainda não paga, inclusive após falha e anulação da invoice. [Ciclo e estados das assinaturas](https://docs.stripe.com/billing/subscriptions/overview).

## 2. Arquivos modificados

Backend, em `feitoo-api/`:

- `app/Services/Quotations/QuotationStripeGateway.php`: criação com métodos dinâmicos.
- `app/Services/Quotations/QuotationOrderService.php`: validação de itens/Price e reconciliação de falha recuperável.
- `app/Services/StripeCheckoutGateway.php`: criação fiscal, GET de itens/PaymentIntent e recuperação limitada de Session fiscal.
- `app/Services/PurchaseService.php`: aplicação/reconciliação fiscal, espera assíncrona e proteção de cancelamento.
- `app/Services/StripeWebhookService.php`: eventos fiscais assíncronos e isolamento pelo ambiente da Session persistida.
- `app/Services/StripeSubscriptionGateway.php`: expansão da última invoice.
- `app/Services/SubscriptionService.php`: confirmação financeira e contrato Pro.
- `app/Services/ProcessingService.php`: reconciliação antes do descarte automático de um lote vinculado a pagamento incerto.
- `app/Models/Purchase.php`: identificação da compra aguardando liquidação.
- `app/Http/Resources/PurchaseResource.php`: pagamento assíncrono não aparece como expirado pelo relógio do Checkout.
- `app/Http/Controllers/BillingController.php`: consulta fiscal reconciliada.
- `app/Console/Commands/ExpirePendingPurchases.php`: não expira pagamento em liquidação como abandono de Checkout.
- `tests/TestCase.php`: transporte Stripe bloqueado por padrão; todo tráfego de teste exige simulação explícita.
- `tests/Feature/StripeDynamicPaymentsTest.php`: novos cenários de pagamentos dinâmicos e assíncronos.
- `tests/Feature/{MonetizationTest,QuotationCheckoutFailureTest,QuotationCommerceTest,QuotationStripeGatewayTest,StripeEnvironmentTest,SubscriptionTest}.php`: contratos de payload, fixtures financeiros e regressão.

Frontend, no repositório próprio `feitoo-web/`:

- `src/app/features/account/pages/account-credits.ts`: texto de espera esclarece confirmação posterior de PIX/boleto.
- `src/app/features/account/pages/account-credits.spec.ts`: retorno pendente preserva tentativa sem nova compra.

Este relatório também é novo. A entrega deve incluir a alteração do frontend no seu próprio repositório; o Git da raiz mostra esse diretório como repositório modificado.

## 3. Métodos por modalidade

| Método informado no Dashboard | Créditos, R$ 9,90 | Compras fiscais existentes | Pro recorrente |
| --- | --- | --- | --- |
| Cartão | Elegível conforme Stripe | Elegível conforme Stripe | Conforme compatibilidade com cobrança automática |
| Apple Pay / Google Pay | Conforme dispositivo, conta e elegibilidade | Mesmas condições | Conforme suporte do Stripe à recorrência e à carteira |
| Link | Conforme conta/cliente | Conforme conta/cliente | Conforme elegibilidade recorrente |
| PIX | Se oferecido pelo Stripe; confirmação obrigatória | Se oferecido; lote aguarda confirmação | Somente quando elegível para a modalidade automática oferecida pelo Stripe |
| Boleto, hoje desabilitado | Apenas após habilitação manual e elegibilidade | Idem; pacote de R$ 4,90 fica abaixo do mínimo documentado de R$ 5,00 | Não converter para `send_invoice`; verificar compatibilidade automática no Dashboard para esta conta |

Nenhum método desabilitado é ativado por código. Suporte de um método no produto Billing não significa que ele esteja disponível para qualquer assinatura automática desta conta. O código mantém `mode=subscription` e não muda a collection method para manual. A documentação atual deve ser consultada durante a configuração: [PIX](https://docs.stripe.com/payments/pix), [boleto](https://docs.stripe.com/payments/boleto).

### Prazo dos lotes fiscais durante espera

O Checkout fiscal mantém sua expiração original. Para um Checkout concluído ainda sem pagamento, os XMLs privados aguardam a data limite de QR/voucher retornada no PaymentIntent, acrescida de três dias úteis para liquidação. Sem essa data, o primeiro prazo de consulta é sete dias. Reentregas não renovam continuamente esse prazo.

Antes de remover automaticamente um lote vencido com compra pendente/falha/expirada, o purge consulta a Session existente ou busca a Session cuja resposta se perdeu. Se o Stripe ainda indicar liquidação em andamento, os arquivos ficam para nova consulta em um dia. Erro de comunicação impede o descarte e é reportado pelo comando. Isso também cobre webhook atrasado antes do primeiro registro local da conclusão.

Quando o pagamento assíncrono é confirmado, volta a valer o TTL fiscal normal configurado, contado dessa confirmação. A espera não libera processamento, saldo ou ampliação de franquia. Não há renovação da expiração/chave do pedido. Exclusão explícita de arquivos pelo proprietário continua seguindo o contrato existente; arquivos já excluídos não são recriados.

## 4. Estados financeiros e eventos

| Evento | Créditos | Compra fiscal | Pro |
| --- | --- | --- | --- |
| `checkout.session.completed` | Concede apenas com `paid`; `unpaid` fica pendente | Mesma regra; registra espera e protege os inputs | Consulta assinatura e invoice; concluir Checkout não ativa sozinho |
| `checkout.session.async_payment_succeeded` | Concede com todas as validações | Confirma a compra validada, sem iniciar trabalho no webhook | Confirmação financeira vem de Billing/invoice |
| `checkout.session.async_payment_failed` | `failed`, sem concessão | `failed`, sem concessão | Atualização pelos eventos de Billing |
| `checkout.session.expired` | Cancela somente pedido não pago | Expira somente compra não paga | Não concede acesso; retomada usa o fluxo contratual existente |
| `payment_intent.payment_failed` | Registra falha, sem rebaixar pago/reembolsado/disputado | Registra falha, sem rebaixar pago/reembolsado | Não concede acesso; usa Billing |
| `payment_intent.succeeded` | Ignorado para concessão | Ignorado para concessão | Ignorado para ativação |
| `invoice.paid`, `invoice.payment_failed` | Sem concessão | Sem concessão | Reconsulta assinatura e última invoice atuais |
| `customer.subscription.created/updated/deleted` | Sem concessão | Sem concessão | Sincroniza contrato, período, atraso e cancelamento |
| `charge.refunded` | Mantém bloqueio/revogação existente | Mantém reembolso integral existente | Sem novo fluxo de concessão |
| `charge.dispute.created/closed` | Mantém bloqueio e recuperação de unidades não consumidas conforme disputa atual | Sem implementação paralela de disputa fiscal | Sem implementação paralela |

`stripe_webhook_events` deduplica pelo ID do evento na transação. A concessão de créditos continua única por `order_id`, sob os mesmos locks de usuário/pedido; eventos distintos não criam outro grant. `paid_at` é preservado. Pagamento confirmado não é desfeito por conclusão pendente, falha ou expiração atrasada. Reembolso/disputa não são reativados por sucesso atrasado. Reservas e consumo continuam no ledger existente.

Invoices pendentes de uma assinatura remota ativa são representadas localmente como `incomplete` no pagamento inicial e `past_due` em renovação, impedindo benefícios. Cancelamento programado mantém o período quitado; cancelamento efetivo, período encerrado e estados inadimplentes mantêm as regras de suspensão.

A política de concessão segue a orientação de validar a Session e seu pagamento antes de cumprir a compra: [fulfillment de Checkout](https://docs.stripe.com/payments/checkout/fulfill-orders).

### Minha Conta

`/conta/creditos` já consulta o servidor, limita polling, representa pendente, confirmado, falho, expirado, reembolso, disputa e erro de comunicação, e preserva a chave da tentativa. Foi alterado somente o texto de espera. Pedidos falhos com Session podem ser consultados para recuperação. Refresh, retorno, timeout e login não criam automaticamente outro pedido.

`/conta/assinatura` já distingue confirmação pendente, contrato incompleto, atraso, cancelamento, erro e tentativa recuperável. A mensagem de Pro ativado exige `grants_pro` confirmado pelo servidor. Não houve alteração visual. O retorno fiscal também aguarda o servidor antes de iniciar o lote; a query de sucesso não confirma pagamento.

## 5. Testes

Somente Stripe simulado, chaves sintéticas e SQLite `:memory:`. `Tests\TestCase` instala um transporte HTTP simulado sem respostas por padrão: uma chamada Stripe não prevista falha no teste, sem acesso à rede. TEST e LIVE nos testes são apenas respostas e prefixes simulados.

Comandos no PowerShell, dentro de `feitoo-api`:

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter 'StripeDynamicPaymentsTest|MonetizationTest|QuotationCheckoutFailureTest|QuotationCommerceTest|SubscriptionTest|QuotationStripeGatewayTest|StripeEnvironmentTest'
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
php vendor/bin/pint --dirty --test
```

Dentro de `feitoo-web`:

```powershell
npm.cmd test -- --watch=false
npm.cmd test -- --watch=false --include=src/app/features/account/pages/account-credits.spec.ts
git diff --check
```

Na raiz: `git diff --check`.

Resultados da validação sequencial:

- Backend completo: **572 testes, 5.562 assertions, nenhuma falha/erro, 7 skips** (2min49s).
- Recorte comercial final: **113 testes, 1.461 assertions, aprovado**.
- Frontend completo final: **35 arquivos / 361 testes, aprovado**. O arquivo de créditos também foi validado isoladamente com **14 testes aprovados**.
- Pint: aprovado. `git diff --check` na raiz e no frontend: aprovado, com avisos de normalização LF/CRLF do Git no frontend.

Uma tentativa anterior de rodar dois processos PHP ao mesmo tempo apresentou interferência entre diretórios de `Storage::fake`, causando arquivos ausentes. Esses resultados foram descartados; o recorte comercial e a suíte completa acima foram repetidos sem concorrência e passaram. Executar estas suítes sequencialmente neste workspace.

| Exigência | Cobertura |
| --- | --- |
| 1. Avulso sem parâmetro rejeitado | `QuotationStripeGatewayTest`, teste HTTP fiscal em `StripeDynamicPaymentsTest` |
| 2. Pro compatível | `StripeEnvironmentTest` inspeciona POST real do SDK simulado; `SubscriptionTest` |
| 3. Fiscais sem regressão | `MonetizationTest`, processamento fiscal e ferramentas na suíte completa |
| 4–6. PIX/boleto pendente, sucesso único e falha | Data providers PIX/boleto em `StripeDynamicPaymentsTest`, `QuotationCommerceTest` |
| 7. Duplicidade e desordem | Eventos repetidos, IDs distintos, conclusão/falha antigas após pagamento; estado atual de Billing |
| 8. Retorno antes da confirmação | Testes de API com `payment=success` pendente; teste Angular adicional |
| 9–12. Timeout, resposta perdida, pedido sem Session e 5xx | `QuotationCheckoutFailureTest`, `QuotationCommerceTest`; recuperação fiscal por webhook e GET sem nova criação |
| 13. TEST/LIVE | `StripeEnvironmentTest`, `QuotationStripeGatewayTest`, `QuotationCommerceTest` |
| 14. Divergências | Total, moeda, Price, quantidade, ambiente, metadata, Customer e invoice incompatíveis |
| 15. Pro inicial/renovação | Invoice aberta/anulada não concede; pagamento, renovação, evento antigo, atraso/cancelamento/recuperação |
| 16. Reembolso/disputa/reserva/consumo | Regressão de `QuotationCommerceTest` e `MonetizationTest` |
| Retenção fiscal e comunicação | Pagamento ainda pendente após oito dias, cancelamento bloqueado, purge reconciliado e arquivos conservados em falha de rede |

O PHP CLI disponível é **8.2.12 / PHPUnit 11.5.57**; não foi possível validar PHP 8.5/Linux localmente porque o daemon Docker Desktop não estava disponível. Os sete skips correspondem a LibreOffice real opcional (1), suporte a symlink (1), sinais POSIX (3) e verificações Linux/Bubblewrap/prlimit (2). A compilação dos testes Angular emite avisos existentes de depreciação Sass/Bootstrap.

## 6. Dashboard Stripe — conferência manual necessária

Não há conector Stripe nesta sessão nem leitura autenticada do endpoint Live realizada nesta tarefa. Portanto, a inscrição Live **não está confirmada**. O preflight comercial anterior `ok=true` verifica configuração/Price, não substitui a conferência de webhooks. Nenhuma configuração do Dashboard foi modificada.

Antes da publicação, o operador deve abrir o destino Live de `https://api.feitoo.com.br/api/stripe/webhook` (conferir o hostname efetivamente instalado), confirmar `enabled`, modo Live e a versão de eventos compatível com o formato utilizado. Comparar os eventos selecionados com:

```text
checkout.session.completed
checkout.session.async_payment_succeeded
checkout.session.async_payment_failed
checkout.session.expired
payment_intent.payment_failed
invoice.paid
invoice.payment_failed
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
charge.refunded
charge.dispute.created
charge.dispute.closed
```

Se houver `*`, os eventos estão incluídos. Se faltar algum, configurar manualmente sua inscrição no destino existente durante a janela aprovada. `payment_intent.succeeded` não é necessário para concessão; pode permanecer inscrito para observação, pois o handler o ignora financeiramente. Não alterar o segredo de assinatura e não criar um segundo endpoint como substituição.

Em Payment methods, manter os métodos já habilitados. Boleto só passa a participar após habilitação manual e aprovação/elegibilidade do Stripe. Em Billing/Portal, conservar cobrança automática, o Price mensal existente e a política de recuperação/cancelamento aprovada. Conferir as regras/configurações específicas da modalidade recorrente sem ativar coleta manual para ampliar opções.

## 7. Riscos e limites

- As permissões da chave precisam permitir GET de Sessions/itens, PaymentIntents, assinatura/invoice e os GETs já existentes de Price/Product/disputa. Erro de leitura não libera benefícios; o webhook falha para permitir nova entrega.
- Testes locais não comprovam TLS, permissões Live, elegibilidade real de cada método, MySQL/InnoDB ou PHP-FPM 8.5. Não foi feito POST de diagnóstico Live. Fazer a validação de plataforma no ambiente isolado de homologação antes da publicação.
- Requisições recusadas ou respostas perdidas podem ter estado idempotente remoto. Se uma chave antiga retiver parâmetros anteriores, o Stripe pode rejeitar replay com payload atualizado. Não substituir a chave para contornar isso: usar recuperação GET e análise dos Request Logs, sem presumir ausência remota.
- A proteção temporária de XMLs depende de scheduler operacional e espaço em storage. Se a comunicação com Stripe falhar, o descarte automático é adiado; monitorar falhas e retenção prolongada. Exclusão explícita pelo usuário continua definitiva.
- Assinatura com último total/item diferente do contrato fica sem nova concessão e exige análise; valores contratados não são reescritos para aceitar divergência. Ajustes comerciais futuros, prorrateios ou impostos devem ter seu próprio contrato e testes.
- Os pedidos #1/#2 continuam sob o mecanismo existente. **Não executar GET individual de reconciliação desses pedidos nesta publicação**, pois esse GET pode atualizar estado local conforme o Stripe. Não preencher Session/URL, alterar status/expiração/chaves ou conceder saldo manualmente.

## 8. Publicação controlada na VPS — comandos

Procedimento para o operador, **não executado**. Usar o artefato/revisão aprovada com estes arquivos. O caminho abaixo segue `docs/production.md`; se a instalação usar releases, definir `FEITOO_API_DIR` para a release preparada. `.env`, storage e chaves existentes devem ser preservados pelo mecanismo atual. Não executar `composer update` ou migrations nesta correção.

```bash
set -euo pipefail
export FEITOO_API_DIR=/var/www/feitoo/feitoo-api
cd "$FEITOO_API_DIR"
test -f artisan
test -f composer.lock
/usr/bin/php -v
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
composer show stripe/stripe-php
/usr/bin/php artisan migrate:status
/usr/bin/php artisan route:list --path=stripe/webhook
/usr/bin/php artisan route:list --path=quotations/credit-orders
/usr/bin/php artisan schedule:list
sudo supervisorctl status
sudo systemctl status php8.5-fpm --no-pager
```

Confirmar os pré-requisitos já existentes de schema, sem aplicar migrações em lote. Ativar a release completa pelo mecanismo de publicação já adotado. Para uma instalação no mesmo diretório, o bloco abaixo aplica os arquivos de produção desta correção a partir de um artefato já revisado e extraído na VPS. O lock deve ser idêntico, pois não houve mudança de dependência. O backup contém apenas código e manifests. Executar somente na janela aprovada; se um comando falhar depois de `down`, corrigir/restaurar o código antes de retirar a manutenção.

```bash
read -r -p 'Diretório absoluto do artefato revisado (contém feitoo-api): ' FEITOO_RELEASE_DIR
read -r -p 'Diretório absoluto existente para backup de código: ' FEITOO_CODE_BACKUP_DIR
test -f "$FEITOO_RELEASE_DIR/feitoo-api/app/Services/PurchaseService.php"
test -d "$FEITOO_CODE_BACKUP_DIR"
cmp --silent "$FEITOO_RELEASE_DIR/feitoo-api/composer.lock" "$FEITOO_API_DIR/composer.lock"
cd "$FEITOO_API_DIR"
tar -czf "$FEITOO_CODE_BACKUP_DIR/stripe-dynamic-before-$(date -u +%Y%m%dT%H%M%SZ).tar.gz" app composer.json composer.lock
rsync -a --checksum --dry-run --itemize-changes "$FEITOO_RELEASE_DIR/feitoo-api/app/" "$FEITOO_API_DIR/app/"
# Após conferir a lista acima na janela aprovada:
/usr/bin/php artisan down --retry=60
rsync -a --checksum "$FEITOO_RELEASE_DIR/feitoo-api/app/" "$FEITOO_API_DIR/app/"
```

Em uma instalação com troca de releases, usar a ativação atômica habitual no lugar desse bloco de cópia. Depois da ativação/cópia:

```bash
cd "$FEITOO_API_DIR"
/usr/bin/php artisan config:cache
/usr/bin/php artisan route:cache
/usr/bin/php artisan view:cache
sudo systemctl reload php8.5-fpm
read -r -p 'Programa Supervisor fiscal confirmado no status: ' FEITOO_FISCAL_WORKER
read -r -p 'Programa Supervisor de quotations confirmado no status: ' FEITOO_QUOTATION_WORKER
test -n "$FEITOO_FISCAL_WORKER"
test -n "$FEITOO_QUOTATION_WORKER"
sudo supervisorctl status "$FEITOO_FISCAL_WORKER"
sudo supervisorctl status "$FEITOO_QUOTATION_WORKER"
sudo supervisorctl restart "$FEITOO_FISCAL_WORKER"
sudo supervisorctl restart "$FEITOO_QUOTATION_WORKER"
sudo supervisorctl status "$FEITOO_FISCAL_WORKER"
sudo supervisorctl status "$FEITOO_QUOTATION_WORKER"
/usr/bin/php artisan up
```

Os nomes Supervisor devem ser os programas reais dedicados, sem `all` e sem curingas. Se a instalação usar grupos, usar os identificadores específicos que foram conferidos. O serviço FPM deve ser confirmado antes do reload. Não executar `queue:clear`, retry global, `migrate:fresh`, `key:generate` ou comandos financeiros de reparação.

Publicar o frontend pela Vercel existente, a partir da revisão própria aprovada de `feitoo-web`, mantendo variáveis e configuração atuais. A alteração visual é apenas uma mensagem; não trocar o mecanismo de hospedagem. Manter a release anterior para retorno de código, sem rollback de banco ou de ledger.

## 9. Verificações pós-deploy sem operações financeiras automáticas

```bash
curl --fail --silent --show-error https://api.feitoo.com.br/api/health
cd "$FEITOO_API_DIR"
/usr/bin/php artisan route:list --path=stripe/webhook
/usr/bin/php artisan schedule:list
sudo systemctl is-active php8.5-fpm
sudo supervisorctl status "$FEITOO_FISCAL_WORKER"
sudo supervisorctl status "$FEITOO_QUOTATION_WORKER"
```

Confirmar o host real antes do `curl`. Consultar `/conta/creditos` **sem parâmetros de retorno** e `/conta/assinatura` com uma sessão legítima; conferir apenas listagem/saldo/resumo e ausência de mensagem de aprovação indevida. Não clicar em comprar/assinar/retomar, não abrir retorno de pedido #1/#2, não usar POST Checkout e não reenviar eventos históricos durante essa verificação.

Ler logs técnicos, erros de webhook e histórico de entregas no Dashboard, sem copiar dados pessoais/secrets. Conferir a versão do endpoint, eventos inscritos, scheduler e leitura dos itens. O primeiro pagamento real futuro, iniciado voluntariamente pelo usuário após a publicação, poderá ser acompanhado pelo operador; esta tarefa não o executa nem o automatiza.
