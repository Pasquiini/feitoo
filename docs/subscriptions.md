# Feitoo Pro — assinaturas Stripe em TEST MODE

## Auditoria e decisões

Antes desta etapa existiam Free implícito, compras avulsas, Purchase,
StripeCheckoutGateway, StripeWebhookService e EntitlementService. Não havia
Subscription, Customer persistido nem Portal. Não existe UsageLimitService neste
repositório: a resolução de limites e a reserva diária pertencem ao EntitlementService.
O histórico de compras avulsas continua independente das assinaturas.

O benchmark sintético documentado em monetization.md suporta 250 XMLs / 4.000
itens, 40 MiB, pico de 132 MiB e 31,56 s. Isso valida o envelope por processamento,
não capacidade diária agregada nem SLA de produção. O Pro começa com 500 XMLs/dia,
conservador e configurável, sujeito aos mesmos limites técnicos e à fila existente.
Monitorar CPU, memória, fila e espaço antes de aumentar limites ou divulgar SLA.

## Configuração local

Criar um Product Feitoo Pro no ambiente de teste da Stripe e um Price recorrente
mensal de BRL 29,90, sem trial. Copiar o Price para STRIPE_PRO_PRICE_ID no .env do
backend. O frontend nunca escolhe o Price.

```dotenv
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRO_PRICE_ID=price_...
PRO_MONTHLY_CENTS=2990
PRO_MAX_FILES_PER_PROCESSING=250
PRO_DAILY_FILES=500
PRO_RETENTION_MINUTES=1440
```

PRO_MONTHLY_CENTS controla a oferta pública; antes de criar Checkout o backend
verifica que o Price ativo da Stripe é TEST, BRL, mensal, intervalo 1 e possui
exatamente esse valor. Para mudar o preço, criar/configurar o Price correspondente.
Não há Price anual, trial, cupom, plano Business ou créditos.

Aplicar `php artisan migrate`, limpar cache de configuração se aplicável e
reiniciar workers. Não versionar segredos. A integração usa stripe-php 22 e a
versão padrão instalada `2026-09-30.endive`. Configurar o destino do webhook com
a mesma versão. Períodos são lidos dos subscription items; invoices usam
`parent.subscription_details.subscription`, com compatibilidade para `subscription`.

## Endpoints e Checkout

Todos os endpoints abaixo exigem sessão Sanctum e possuem throttle. O status
usa `subscription-status`: 60 consultas/minuto por usuário. Checkout e Portal
mantêm 10 operações/minuto, separados da leitura do status:

- GET /api/account/subscription: oferta configurada e resumo seguro da assinatura.
- POST /api/account/subscription/checkout: cria/reutiliza Checkout subscription.
- POST /api/account/subscription/portal: cria Portal para o Customer do usuário.
- GET /api/account/usage: plano atual e limites resolvidos.

O Customer é persistido em users.stripe_customer_id, oculto na serialização, com
índice único. Um lock do usuário serializa criações concorrentes. A criação de
Customer usa chave idempotente estável. Cada tentativa de assinatura é persistida
antes da chamada externa de Checkout; retries usam a mesma chave idempotente.
Session vigente é reutilizada. Uma Session antiga precisa estar expirada na
Stripe antes de criar outra. Assinatura existente com cobrança pendente direciona
ao gerenciamento em vez de iniciar nova cobrança.

Redirect de sucesso retorna a /conta?subscription=confirming, sem ativar direitos.
A conta consulta imediatamente e aguarda 3 segundos após cada resposta pendente,
sem requests simultâneas. São no máximo 10 consultas e 30 segundos totais. Depois
mostra que o pagamento ainda está em confirmação, com consulta manual única.
O botão manual fica desabilitado durante a rotina automática e durante requests.
Active, canceled, past_due, unpaid, incomplete_expired, paused e trialing encerram
o polling; apenas ausência de assinatura ou incomplete aguardam novas consultas.
Erro HTTP encerra a rotina. HTTP 429 tem mensagem própria e bloqueia consulta
manual pelo Retry-After (segundos ou data HTTP), com mínimo de 3 segundos.
Timeout, destruição, início de navegação e parada manual cancelam timers e request
em andamento. Polling consulta somente assinatura; ativação atualiza uso uma vez.
Só a confirmação de webhook altera o estado local.
O cancelamento do Checkout retorna a /conta?subscription=cancelled.

## Webhook e estados

O endpoint existente POST /api/stripe/webhook mantém verificação da assinatura,
limite de payload e rejeição de eventos live. Novos eventos aceitos:

- checkout.session.completed em mode subscription;
- customer.subscription.created / updated / deleted;
- invoice.paid / invoice.payment_failed.

O webhook trava o usuário e consulta o estado atual da assinatura na Stripe,
evitando regressão por ordem de entrega. Confere Customer, metadata da tentativa
local, Price e quantidade de subscription items. A deduplicação usa a tabela
stripe_webhook_events existente. Falha de rede ou registro ainda não disponível
desfaz a transação, permitindo retry. Não existe parsing ou leitura de XML.

SubscriptionStatus mapeia incomplete, incomplete_expired, trialing, active,
past_due, canceled, unpaid e paused. Apenas active com current_period_end futuro
concede Pro. Trialing está representado para compatibilidade, mas não concede
Pro e nenhum trial é criado. Past_due/unpaid removem Pro; regularizar pelo Portal.

cancel_at_period_end=true mantém acesso enquanto active e dentro do período.
A conta exibe a data de encerramento; canceled volta ao Free. O Feitoo não
implementa cancelamento manual, emissão de fatura nem telas de cartão.

## Customer Portal

Habilitar o Portal em TEST MODE no Dashboard Stripe. Permitir atualizar método
de pagamento, consultar cobranças e cancelar ao fim do período. Desabilitar
trocas de planos e recursos fora do escopo. Configurar política de cancelamento
ao fim do período. O backend usa apenas o Customer persistido do usuário atual,
ignora identificadores enviados pelo navegador e define return_url para /conta.
O frontend valida HTTPS e domínio billing.stripe.com; Checkout usa checkout.stripe.com.

## Entitlement e retenção

Free: 20 por processamento, 50/dia e retenção existente (120 minutos por padrão).
Pro: 250 por processamento, 500/dia e 1.440 minutos por padrão. Todos configuráveis;
o limite por lote é limitado também pelo teto técnico. Retenção é resolvida na
criação do lote, sem estender retroativamente arquivos existentes. Upload continua
em requests de até 20 XMLs, 30 MiB/request e 40 MiB/lote; máximo de 4.000 itens.

Usage permanece nos processings por usuário + data São Paulo + ferramenta.
Free e Pro entram na mesma soma; avulso continua separado. Upgrade após 50 usados
deixa 450 dos 500 disponíveis. Com PRO_DAILY_FILES=1000 seriam 950. Downgrade não
zera consumo: remaining=max(0, limite atual - usados). Dispatch failure mantém a
compensação existente. Purchase paga continua autorizando apenas o lote comprado.
Pro não recebe oferta de compra avulsa complementar; o backend bloqueia a compra
para lote comportado pelo Pro, inclusive quando o saldo diário acabou.

## Conta, paywall e analytics

/conta exibe plano, uso, oferta/preço, estado, próxima cobrança ou encerramento,
e Portal. Falha de pagamento apresenta mensagem e CTA para atualizar pagamento.
O paywall oferece saldo Free, compra avulsa e acesso ao upgrade em /conta; o
serviço de seleção mantém arquivos durante navegação. Não foi criada /planos.

Eventos: pro_offer_viewed, pro_checkout_started, pro_subscription_activated,
pro_subscription_failed, pro_portal_opened. Usam a camada de consentimento e
allowlist existente, sem email, Customer, Subscription ou UUID. Ativação no
analytics é observada na confirmação frontend; não é registro financeiro e não
garante entrega se o usuário fechar a página ou negar consentimento.

## Testes e validação Stripe

### Correção do polling e HTTP 429

O fluxo anterior enviava uma consulta inicial e até 20 adicionais a cada três
segundos, enquanto o grupo do backend permitia somente dez/minuto e compartilhava
o orçamento com Checkout/Portal. O botão manual podia consumir o mesmo orçamento
entre tentativas. Erros HTTP e estados finais não encerravam a confirmação.
Não havia retry de interceptor, effect consultando assinatura nem reload completo
da conta a cada tentativa. A proteção anterior impedia só requests simultâneas.

A correção mantém uma única rotina, no máximo dez consultas e deadline de trinta
segundos. O intervalo começa depois da conclusão da resposta anterior. A leitura
de uso inicial é cancelada se ainda estiver pendente quando Pro for ativado, para
evitar resposta Free antiga sobrescrever a UI. Somente uso é atualizado após
ativação; histórico e compras não são recarregados pelo polling.

Validação da correção: 212 testes backend, 119 testes frontend, Pint e build
aprovados. Testes com relógio simulado cobrem primeira consulta, intervalo, estados
finais, timeout, consulta manual, cleanup, navegação, 429 e atualização de uso/UI.
O backend valida autenticação, ownership, 60 leituras/minuto, 429, Retry-After e
isolamento em relação às operações sensíveis.

No navegador com API sintética foram medidos intervalos de 3.008 e 3.017 ms,
com parada após active/429, botão manual desabilitado e sem recarga repetida da
conta. Relatório local: feitoo-web/dist/validation/subscription-polling-browser-report.json.
Essa validação de cadência não depende de pagamento real na Stripe.

SubscriptionTest cobre limites, upgrade/downgrade, estados, cancelamento
agendado, Customer/Session reutilizados, Price proibido no frontend, webhook
idempotente, ordem de eventos, invoice failed/paid, cancelamento e ownership do
Portal. A suíte frontend cobre oferta, Checkout, confirmação, Pro, próxima
cobrança, encerramento agendado e pagamento problemático.

```powershell
php artisan migrate
php artisan test
php vendor/bin/pint --test
php artisan route:list --path=api
npm.cmd test -- --watch=false
npm.cmd run build
stripe.cmd listen --forward-to localhost:8000/api/stripe/webhook --events checkout.session.completed,customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,invoice.paid,invoice.payment_failed
```

Copiar o whsec do listener para o backend local, iniciar servidor e worker, criar
usuário Free, clicar Assinar Feitoo Pro e pagar com cartão de teste
4242 4242 4242 4242 / data futura / CVC qualquer válido. Confirmar webhook, Pro
ativo e uso preservado. Abrir Portal, cancelar ao fim do período, voltar à conta
e confirmar data. Reenviar um evento com Stripe CLI e verificar ausência de
duplicação. Test clocks da Stripe permitem avançar o período para verificar término.
Fixtures genéricas do `stripe trigger` não possuem Customer/metadata locais e
não substituem esse teste vinculado a um Checkout real.

## Limitações desta validação

Validação local: migration aplicada, rotas conferidas, 211 testes backend e
104 testes frontend aprovados, Pint aprovado e build de produção concluído.
Após o último ajuste de leitura com lock no entitlement, os 22 testes de
monetização/assinatura passaram novamente. O teste de preview com duas NF-es
distintas também passou depois da extensão das fixtures.
O smoke test de navegador passou em 320–1440 px, sem overflow da página nem
erros de runtime. Captura: feitoo-web/dist/validation/mobile-result.png.
Esses artefatos são locais e usam respostas API sintéticas, sem pagamento real.

As chaves de teste e webhook estavam presentes, mas STRIPE_PRO_PRICE_ID estava
vazio. CLI 1.53.1 disponível. Não foi realizado pagamento nem cancelamento reais
em TEST MODE nesta execução; dependem do Product/Price e Portal configurados.
Os testes automáticos usam objetos Stripe simulados. Não foi ativado live mode.
