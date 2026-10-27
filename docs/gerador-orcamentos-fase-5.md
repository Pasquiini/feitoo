# Gerador de Orçamentos — Fase 5: créditos, Stripe TEST e Pro

**Data:** 24/10/2026. Implementação local para revisão. Não houve deploy, migration no banco operacional, acesso à VPS/Supervisor, mudança no Stripe Live, compra real, commit ou push. As alterações locais anteriores foram preservadas. A flag de geração em produção continua desabilitada por padrão; a ferramenta continua fora do catálogo e do sitemap.

Foram lidos a auditoria e os relatórios das Fases 1–4, as migrations versionadas, os serviços de geração/assinatura/cobrança e os contratos Angular existentes. Não foi encontrado AGENTS.md na árvore pesquisada. Os limites fiscais, pacotes XML, DOCX, Dompdf e sua execução isolada mantêm seus contratos.

## 1. Arquitetura comercial

`QuotationCommerce` resolve elegibilidade e contabiliza as origens; `QuotationGenerationService` mantém o snapshot, fila transacional, tentativas, fencing e finalização da Fase 4. `QuotationOrderService` representa compras independentes de Processing, confirma pagamento e concede um grant por pedido. `QuotationStripeGateway` reutiliza SDK, configuração, timeouts e validação de ambiente da infraestrutura Stripe, com bloqueio adicional de qualquer operação comercial em modo Live.

Preço e quantidade são fixos no servidor: **`quotation_5`, cinco créditos, 990 centavos, BRL**. Não existe preço ou quantidade de crédito enviados pelo frontend. Os registros financeiros possuem apenas identificadores técnicos, valores, contadores e datas; não recebem conteúdo do orçamento.

## 2. Estruturas de banco

Migration nova: `feitoo-api/database/migrations/2026_10_24_000001_create_quotation_commerce_tables.php`. Nenhuma migration anterior foi editada.

| Estrutura                 | Conteúdo e garantias                                                                                                                                                                                                                        |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `quotation_credit_orders` | Conta, UUID único, chave idempotente única por conta, pacote/valor/moeda/quantidade/Price congelados, estado, sessão e PaymentIntent únicos, URL temporária, vencimento do Checkout, pagamento e valor reembolsado. Sem FK para Processing. |
| `quotation_credit_grants` | Um grant único por pedido; concedidos, reservados, consumidos, revogados e bloqueio. FK de conta/pedido e índice para seleção FIFO por conta.                                                                                               |
| `quotation_pro_buckets`   | Conta, assinatura existente, início/fim efetivos, limite congelado, reserva e consumo. Unicidade por assinatura e par de datas.                                                                                                             |
| `quotation_generations`   | Campos aditivos `source`, `credit_grant_id`, `pro_bucket_id`. A geração continua sendo o registro de consumo de uma unidade e mantém seu estado, tentativa e vínculo único com Processing.                                                  |

As FKs financeiras usam restrição de exclusão: expirar arquivos não apaga compras ou consumo. Não foi transformada a tabela fiscal `purchases` em carteira. O bucket diário obrigatório da Fase 4 continua referenciado também nas emissões pagas/Pro, como compatibilidade estrutural; seus contadores **só são alterados por Free**.

MySQL recebe CHECKs para `reserved + consumed + revoked <= granted`, `reserved + consumed <= quota_limit` e associação exclusiva de cada origem ao grant/bucket correto. Contadores são unsigned. A aplicação verifica saldo sob locks e transações, independentemente do suporte do banco a CHECK. SQLite não comprova essas garantias concorrentes.

## 3. Política de consumo

- Sem Pro vigente, a seleção padrão é Free: um sucesso por dia em `America/Sao_Paulo`. Reservas ativas ocupam a franquia. Créditos exigem seleção explícita.
- Pro vigente utiliza primeiro sua quota de **100 orçamentos por período efetivo da assinatura**, exclusiva desta ferramenta. Não utiliza Free enquanto Pro estiver ativo. Esgotada a quota, só aceita crédito mediante seleção explícita; o backend rejeita fallback silencioso.
- Uma emissão concluída consome uma unidade da origem reservada. Retry automático mantém a mesma reserva. Falha terminal, prazo pendente ou exclusão antes da conclusão liberam exatamente essa origem.
- Retry manual de crédito reutiliza o grant original, se ainda elegível. Retry manual Pro exige que o período original ainda esteja vigente; não transfere reserva liberada para uma quota nova.
- Reserva Pro aceita antes da renovação/cancelamento pode concluir dentro do prazo técnico original e consome somente o bucket original. Nova emissão revalida o plano atual.
- Downloads, replay da revisão e exclusão/expiração de PDF concluído não alteram consumo. Os créditos não têm expiração comercial. O PDF continua disponível por duas horas após concluir; rascunhos mantêm o prazo próprio de 24 horas.

## 4. Fluxo de compra e API

Todas as rotas abaixo usam Sanctum, sessão, contexto de conta, ownership e respostas `no-store, private`. Mutações usam CSRF, inclusive sem Origin/Referer. Checkout e consultas de pedido possuem throttle; o histórico é paginado em 20 registros.

| Método e rota                                    | Contrato                                                                                                                                                                  |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /api/quotations/entitlements`               | Mantém Free/data de renovação/emissão ativa e acrescenta `credits`, `pro` e `offer`; política `explicit_source`.                                                          |
| `POST /api/quotations/credit-orders`             | Body vazio e `Idempotency-Key` UUID. Retorna pedido e URL real do provedor quando disponível. Campos extras são rejeitados.                                               |
| `GET /api/quotations/credit-orders/{uuid}`       | Somente proprietário. Consulta a sessão no backend quando pendente e com sessão registrada; pode confirmar/conceder atomicamente, independentemente de parâmetros da URL. |
| `GET /api/quotations/credit-orders?page=N`       | Compras comerciais do proprietário e paginação. Não consulta nem modifica as compras fiscais.                                                                             |
| `POST /api/quotations/drafts/{uuid}/generations` | Acrescenta `source: free                                                                                                                                                  | pro | credit` à revisão e chave idempotente da Fase 4. Omissão mantém compatibilidade, resolvendo Pro ou Free e nunca crédito. |

O pedido é persistido antes da chamada Stripe. A chamada externa ocorre fora dos locks de usuário/pedido; a sessão é associada em uma segunda transação curta. Falha ambígua mantém o pedido e repete a chave Stripe `quotation-order-{uuid}`, com payload e vencimento estáveis. Duas solicitações com a mesma chave local retornam o mesmo pedido. Pedido encerrado não é reaberto para cobrar novamente.

O vencimento de Checkout é independente dos créditos. Voltar por cancelamento não prova cancelamento do pagamento: o pedido permanece pendente até confirmação do provedor, falha ou expiração da sessão. Sessão expirada aparece como `cancelled`. Estados comerciais: pending, paid, failed, cancelled, refunded e disputed.

Erros próprios incluem `pro_first`, `pro_quota_exhausted`, `pro_period_invalid`, `credits_exhausted`, `source_invalid`, `checkout_unavailable`, `payment_mismatch`, `order_not_ready`, além dos códigos de geração anteriores. Auth, ownership, CSRF e throttle mantêm 401/404/419/429 e mensagens comerciais próprias.

## 5. Integração Stripe

O gateway comercial aceita exclusivamente `STRIPE_MODE=test`, chave TEST e webhook configurados. Exige um Price existente, ativo, pagamento único, BRL e valor exato de 990 centavos. Não cria produtos ou preços. Checkout usa cartão, uma unidade do Price e metadata somente `operation=quotation_credits` e `quotation_order={uuid}`, tanto na sessão quanto no PaymentIntent. Não envia nome, CPF/CNPJ, itens, logo, snapshot ou conteúdo pessoal.

O roteamento comercial acontece **antes** de assinatura/fiscal registrarem a deduplicação final. Eventos são identificados por metadata ou PaymentIntent local. Para reembolso/disputa anterior à confirmação, o backend pode recuperar a metadata técnica do PaymentIntent, sem registrar o evento como fiscal antes de resolver a origem. Compras fiscais conhecidas continuam no handler fiscal.

Eventos comerciais suportados: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `payment_intent.payment_failed`, `charge.refunded`, `charge.dispute.created` e `charge.dispute.closed`. Somente sessão com `payment_status=paid` concede créditos. A validação inclui modo TEST, sessão registrada, modo payment, referência do pedido, metadata, valor, moeda e PaymentIntent consistente.

O webhook existente continua verificando assinatura e ambiente. Deduplicação, atualização e concessão pertencem à mesma transação. A unicidade de grant por pedido protege também eventos distintos da mesma sessão. Inconsistência ou sessão ainda não persistida provocam rollback da deduplicação, permitindo nova entrega. Não há geração automática por webhook ou retorno.

Referências oficiais consultadas: [fulfillment e confirmação pelo servidor](https://docs.stripe.com/checkout/fulfillment), [tipos de eventos](https://docs.stripe.com/api/events/types) e [idempotência](https://docs.stripe.com/api/idempotent_requests).

## 6. Benefícios Pro

A assinatura continua sendo a existente em `subscriptions`, atualizada pelo serviço e webhook atuais. Não há novo Checkout recorrente, Price de assinatura ou alteração de limites XML/DOCX. Para conceder quota de orçamentos, exige status ativo, início não futuro, início anterior ao fim e fim futuro. Assinatura com período incompleto não concede o benefício comercial.

Renovar cria um bucket novo; não zera o anterior. Cancelamento agendado preserva elegibilidade até o fim efetivo enquanto o status estiver ativo. Cancelamento efetivo, expiração e status de inadimplência impedem novas reservas Pro. Consultas e a reserva novamente verificam esses dados. A interface informa o limite específico e a data final do período.

## 7. Experiência Angular

O editor consulta direitos antes da oferta. Free disponível informa a emissão gratuita; sem Free mostra o aviso contextual, prazo de renovação e alternativas. Reservas Free possuem aviso próprio. O rádio de crédito indica uso de uma unidade paga, e o botão de geração também explicita esse consumo quando selecionado. Pro com quota possui prioridade e não recebe oferta de nova assinatura.

`QuotationCommercePanel` apresenta o contexto comercial e coordena retorno/consulta do pedido. Antes de abrir Checkout, aguarda `draft.flush(false)`, preservando IndexedDB, conteúdo incompleto e logo. A chave de compra fica em sessionStorage, separada por conta, e é repetida após falha ambígua. A chave de geração e sua origem escolhida são persistidas junto à revisão no IndexedDB.

O retorno admite somente UUID técnico. `payment=success`, `confirming` ou `cancelled` não são prova de pagamento. Consulta automática limita-se a oito tentativas, espaçadas em cinco segundos; a consulta em aba oculta é interrompida e permanece disponível manualmente. Falhas de rede/429 deixam o estado consultável e 429 respeita espera limitada pelo Retry-After. Troca de conta e destruição do componente invalidam respostas pendentes.

Pagamento confirmado atualiza saldo e exige nova confirmação manual de emissão. Cancelamento, pendência ou falha mantêm o rascunho. Não há Checkout fictício na aplicação. Os testes de navegador usam somente um provedor contratual local para os cenários de retorno; o SDK comercial real é validado separadamente com transporte simulado.

## 8. Histórico e conta

A conta ganhou uma seção própria de orçamentos: saldo disponível, reservado e utilizado; benefício Pro vigente; compras comerciais paginadas com data, quantidade, preço e estado. Atualização manual consulta o backend. O histórico fiscal, seus contratos e seus pedidos permanecem separados.

Dados de clientes/itens não aparecem nas compras. Grants e geração mantêm a associação técnica do uso, mesmo após purge. A interface não oferece recuperação de conteúdo já expirado com base no saldo pago.

## 9. Concorrência e ordem de locks

Todas as escritas comerciais serializam primeiro por **User**. A cadeia existente de finalização continua **User → Processing → Generation → bucket/grant**. Retry pode consultar assinatura e bucket Pro depois da Generation. A criação pode bloquear Draft e inserir registros ainda não publicados; não toma um Processing existente depois de bloquear um saldo.

O ramo financeiro usa **User → Order → evento → Grant**. O ramo de assinatura existente usa **User → evento → Subscription**. Consultas Pro usam User → Subscription → bucket Pro. Webhooks não adquirem Processing/Generation; finalizadores não adquirem Order/evento. Não existe caminho de retorno dessas folhas para User ou Processing. A linha do usuário serializa os ramos da mesma conta; contas distintas não compartilham seus saldos.

Renderização e chamadas Stripe ocorrem fora dessas transações. Reserva/fila database seguem na mesma transação MySQL da Fase 4, com até cinco tentativas de deadlock. Lease, token de tentativa e deadline continuam impedindo publicação por worker antigo. Apenas uma emissão comercial ativa por conta, independentemente da origem, permanece permitida.

## 10. Idempotência e reembolsos

Mantêm-se os aliases e a unicidade de revisão da Fase 4. O hash inclui a origem explícita; a forma antiga sem origem conserva o hash anterior. Replay retorna a emissão existente, sem reservar novamente. Grant tem uma única criação por pedido, além da unicidade de evento Stripe e de sessão/PaymentIntent.

Política conservadora implementada: **qualquer reembolso, inclusive parcial, ou disputa bloqueia o restante do pacote afetado**. O valor reembolsado permanece registrado. Saldo livre torna-se revogado; uma reserva em andamento fica bloqueada e, ao finalizar/falhar/reconciliar, é liberada para revogado, sem publicação paga após o bloqueio. Se a publicação ganhou a corrida, seu consumo permanece registrado. Não se debita outro pacote e não se produz saldo negativo.

Evento de confirmação tardio não reabre pedido reembolsado/disputado. A decisão de disputa encerrada, inclusive ganha, **não restaura automaticamente** o pacote neste MVP: permanece bloqueado para reconciliação operacional explícita. Não há rotina administrativa de recomposição ou reembolso remoto nesta entrega. Aprovar a política parcial/disputa e seu procedimento de atendimento antes de vender é uma pendência comercial de homologação; não usar edição SQL ad hoc de contadores como procedimento de recomposição.

## 11. Testes e evidências

Todos os dados são fictícios. A suíte PHP usa SQLite `:memory:` isolado; os testes novos confirmam isso e bloqueiam transporte externo acidental. A concorrência usa banco aleatório em servidor descartável, com verificações de datadir/nome antes de qualquer DDL e exclusão somente desse banco ao terminar.

| Verificação                 | Resultado                                                                                                                                                                                                                                                                                                                                                        |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend completo            | **430 passaram, um skip opcional anterior de LibreOffice; 4.071 assertions.** Inclui conta, assinatura, seis ferramentas, autorização, CSRF, geração/PDF e os 16 testes novos comerciais/gateway.                                                                                                                                                                |
| Angular completo            | **330 testes passaram, 28 arquivos**, incluindo painel comercial, transporte, origem explícita, retorno e preservação da chave/origem.                                                                                                                                                                                                                           |
| Concorrência real           | **13 pares de processos/conexões independentes em MariaDB 10.4.32/InnoDB**: último crédito; reembolso contra reserva e finalização; mesma chave de Checkout; mesmo evento; eventos distintos da sessão; renovação/cancelamento contra reserva; Free/crédito, Pro/crédito e Free/Pro simultâneos; replay pago e claim duplicado. Falha terminal também conferida. |
| Navegador com API/PDF reais | Sanctum/CSRF, IndexedDB/logo, snapshot imutável, fila database, subprocesso, download repetido, Free, expiração e quatro larguras passaram.                                                                                                                                                                                                                      |
| Navegador comercial         | Seleção explícita; preparação e falha do Checkout; chave repetida; retorno cancelado/success pendente e posteriormente pago; rascunho parcial/logo preservados; emissão só manual; três larguras. Sem acesso remoto Stripe.                                                                                                                                      |
| Rascunhos anteriores        | 24 verificações passaram, incluindo refresh, logo, login, OAuth simulado, conflitos, rede, sessão e storage indisponível.                                                                                                                                                                                                                                        |
| Seis ferramentas existentes | **96 layouts e oito downloads**, sem erro de runtime. Fluxos fiscais de monetização: duas ferramentas, 12 layouts, sem erro de runtime.                                                                                                                                                                                                                          |
| Assets/SEO                  | **15 testes passaram**; catálogo com seis ferramentas e gerador fora do sitemap.                                                                                                                                                                                                                                                                                 |
| Build                       | Build de produção passou, 15 rotas prerenderizadas. Avisos anteriores de budget em app/home/tool-experience.                                                                                                                                                                                                                                                     |
| Pint, Prettier e diff       | Aplicados/verificados nos arquivos da fase; `git diff --check` na raiz e no frontend.                                                                                                                                                                                                                                                                            |

Comandos, nos respectivos projetos: `php -d extension=soap artisan test --compact`, `php -d extension=soap scripts/check-quotation-commerce-concurrency.php`, `npm.cmd test -- --watch=false`, build com `FEITOO_BUILD_ENV=production`, checks de geração/comércio/rascunhos/ferramentas/monetização e testes públicos.

O script de concorrência tem raiz privada fixa `storage/app/private/quotation-validation/mysql-phase5-20261024`, porta padrão 33078 e nome de banco `feitoo_quotation_test_*`. Inicialização/encerramento afetaram exclusivamente o daemon criado para esta validação, já encerrado. Não houve migration no banco operacional. O relatório JSON identifica expressamente `mysql8=false`: **MariaDB não é comprovação em MySQL 8**. Docker não tinha daemon acessível e não foi encontrado servidor MySQL 8 utilizável.

Artefatos ignorados pelo Git: `feitoo-web/dist/validation/quotation-commerce/report.json`, relatórios dos checks anteriores e `feitoo-api/storage/app/private/quotation-validation/mysql-phase5-20261024/commerce-concurrency-report.json`. O teste de navegador novo precisou de autorização para CDP fora do sandbox; não acessou Stripe remoto.

## 12. Pendências de homologação externa

1. **Price TEST ausente:** nenhuma compra externa foi executada. A inspeção local verificou somente presença/tipo de configuração, sem expor credenciais. Existem chave TEST e webhook local; `STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID` não está definido. Criar/selecionar o Price TEST correto e validar Checkout real, cartão TEST, retorno, assinatura, reentrega, reembolso e disputa em staging. Não criar credenciais fictícias nem tocar Live.
2. Repetir migrations e os cenários concorrentes em **MySQL 8 descartável**, com InnoDB/SQL mode e schema equivalentes ao destino. O script recusa datadir externo à sua raiz: adaptar esse caminho de teste mantendo as travas se o MySQL 8 estiver em container/host diferente.
3. Revalidar o isolamento Linux/bubblewrap/prlimit, capacidade, workers/scheduler, retenção e cookies Sanctum entre domínios reais, conforme a Fase 4. Nada dessa infraestrutura foi alterado.
4. Validar/aceitar formalmente a política de bloqueio integral do saldo restante em reembolso parcial/disputa e definir uma recomposição auditável antes da venda. Validar o custo da quota inicial Pro de 100.
5. Falha de rede após criação remota e antes de registrar Session exige repetir o mesmo Checkout para associá-la e permitir o webhook. Se o usuário não retornar, reconciliar o pedido/sessão em atendimento; não há varredura automática de pedidos sem Session nesta entrega. Um webhook precoce retorna erro recuperável, sem deduplicação definitiva.

## 13. Variáveis e configurações

| Configuração                             | Uso                                                                                                                                                             |
| ---------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `STRIPE_MODE=test`                       | Obrigatório no gateway comercial; Live é recusado. Não mudar o modo do Stripe fiscal de um ambiente operacional para homologar esta fase: usar staging isolado. |
| `STRIPE_SECRET_KEY`                      | Chave TEST já utilizada pela infraestrutura; nunca exposta no frontend/logs.                                                                                    |
| `STRIPE_WEBHOOK_SECRET`                  | Secret assinado do endpoint do ambiente TEST.                                                                                                                   |
| `STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID` | Nova variável, vazia em `.env.example`: Price TEST one_time, BRL, 990 centavos. Não existe valor inventado na configuração real.                                |
| `quotations.pro_limit=100`               | Limite específico da ferramenta; congelado por bucket. Não modifica limites fiscais.                                                                            |
| `QUOTATION_GENERATION_ENABLED`           | Continua false por padrão em produção. Demais variáveis de renderer/sandbox e fila seguem a Fase 4.                                                             |
| Frontend URL e Sanctum                   | Origem existente validada de retorno, cookies, CORS e CSRF. Não aceita destino externo fornecido no pedido.                                                     |

## 14. Publicação e reversão

**Somente após nova autorização operacional:** preparar staging isolado; revisar backup/schema; aplicar migrations novas com geração desligada; configurar Price/endpoint TEST; validar MySQL 8, Checkout e cenários externos; revisar atendimento de reembolso/disputa, sandbox e filas. A habilitação comercial Live exige uma etapa futura explícita: este gateway deliberadamente a recusa.

Para reversão funcional, impedir novas compras comerciais removendo a configuração do Price e desabilitar novas emissões/retries pela flag. Manter processamento/reconciliação de reservas aceitas, consultas, downloads privados vigentes, webhook e registros financeiros. Não apagar grants, buckets, pedidos ou eventos; não executar rollback destrutivo das migrations com registros financeiros. A reversão de código deve continuar entendendo `source` e reconciliando as três origens até drenar reservas. A falta de Price não impede consulta de sessão registrada nem roteamento de reembolso por PaymentIntent TEST.

**A fase operacional permanece pendente de autorização.**
