# Feitoo — lançamento completo do Gerador de Orçamentos

**28/10/2026. Versão completa preparada no workspace para um lançamento controlado, sem publicação executada.** Geração, Free, Pro, créditos, compra Live, saldo, histórico e descoberta usam a implementação existente. Os bloqueios de código identificados foram corrigidos. Ainda é necessário configurar/confirmar os Prices reais, executar o aceite da instalação e autorizar a publicação e a transação Live descritas abaixo. Não há proposta de outra fase de implementação para liberar essas funções.

Foram preservadas as alterações locais da revisão da Fase 4 e seus relatórios. Lidas a auditoria e as Fases 1–4, especialmente a revisão de 28/10; inspecionados também os relatórios e código das Fases 5–6, publicação anterior, configuração, schema, testes e interfaces. A raiz/API e o frontend têm históricos Git distintos; a árvore permanece sem commit/push. Os hashes da release deverão ser os das revisões efetivamente aprovadas, não os hashes antigos da base.

## 1. O que já estava pronto

| Função                         | Implementação reaproveitada                                                                                                                                                     |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Editor, prévia, modelos e logo | Angular standalone, Reactive Forms, BigInt e Bootstrap 5.3.8 sob `.quotation-app`; sem cadastro de produtos/clientes ou ERP                                                     |
| Login e rascunho               | Sanctum/CSRF, retorno e-mail/Google, IndexedDB temporário, `QuotationDraft`, revisão, payload criptografado e PNG normalizado                                                   |
| PDF real                       | DTO estrito da Fase 1, BigDecimal/HALF_UP, Blade/Dompdf, Clássico e Moderno, fontes locais e limites de composição                                                              |
| Processamento                  | `QuotationGenerationService`, job `GenerateQuotationPdfJob`, fila database `quotations`, snapshot imutável, token/lease, subprocesso, Bubblewrap/prlimit, reconciliação e purge |
| Free                           | Reserva atômica por usuário/data em São Paulo, confirmação no sucesso, liberação terminal e preservação do consumo após apagar arquivos                                         |
| Pro                            | Assinatura existente e até 100 gerações por período efetivo, com bucket próprio; sem nova assinatura ou novo preço recorrente                                                   |
| Créditos                       | `QuotationCreditOrder`, grants, reservas/consumos/revogações, pacote `quotation_5`, 5 créditos por 990 centavos/BRL, uso explícito                                              |
| Conta e navbar                 | Saldo, Free/Pro, compras paginadas, histórico único, download autenticado e TTL; crédito de orçamentos separado das compras fiscais                                             |
| Descoberta                     | Home, categoria Negócios, menu/rodapé, FAQ, canonical, schema e sitemap já condicionados à flag pública                                                                         |

Não foram recriados ledger, tabelas comerciais, controllers, jobs, renderer, assinatura, checkout fiscal ou serviços de geração. Não foram alterados limites, pacotes ou regras financeiras das seis ferramentas anteriores.

## 2. Alterações desta entrega

### Stripe TEST e LIVE

Removidos somente os bloqueios `mode !== test` do gateway e do handler de créditos. Permanecem assinatura do webhook, conferência de `livemode`, prefixo da Session, correspondência entre chave e modo, domínio HTTPS do Checkout, usuário autenticado, CSRF, idempotência, valor/moeda e vínculos técnicos. O Stripe global/fiscal existente não foi reconfigurado.

`config/quotations.php` seleciona exclusivamente `STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID` ou `STRIPE_QUOTATION_CREDITS_LIVE_PRICE_ID` conforme `STRIPE_MODE`. Live não usa o Price TEST como fallback. O gateway busca Price com produto expandido e exige ambos ativos, ambiente correto, `one_time`, BRL/990 e produto identificado por metadata `code=quotation_5`, `credits=5`. A quantidade de Checkout é um pacote. Promo codes, Adaptive Pricing e imposto automático ficam desligados nessa Session para conservar o total contratado; não são parâmetros do navegador.

### Separação de dados e migration aditiva necessária

Nova migration: `feitoo-api/database/migrations/2026_10_28_000001_add_stripe_mode_to_quotation_credit_orders.php`.

Ela acrescenta `stripe_mode` e índice por proprietário/modo/data aos pedidos existentes. O default dos registros anteriores é TEST, pois o gateway anterior era TEST-only; Sessions históricas com prefixo `cs_live_` são classificadas como LIVE. Não altera quantidade, valor, grants, reservas, consumo, revogação, assinatura ou histórico. Pedidos antigos sem prova de Session Live não são promovidos a Live automaticamente.

Saldo, seleção de grant, listagem e consulta de pedido usam o ambiente atual. Crédito TEST permanece registrado e consultável quando o ambiente é TEST, mas não vira saldo Live. O teste da migration reconstrói o schema anterior em SQLite em memória e prova que os contadores e registros financeiros permanecem idênticos. As três migrations comerciais anteriores são reaproveitadas; não precisam ser recriadas ou reaplicadas na VPS se já constarem como executadas.

**Esta migration nova ainda não foi aplicada na VPS.** O código novo exige essa coluna; aplicá-la antes de carregar a versão nova. Não executar seu `down` como rollback funcional de lançamento.

### Pagamento, recuperação e estorno

Um webhook assinado e validado pode associar uma Session ao pedido quando a resposta de criação se perdeu antes da persistência local. A validação integral ocorre antes da associação e da concessão. Unicidade do grant por pedido e dos eventos continua impedindo crédito duplicado. Retry ambíguo conserva a chave e o vencimento; fora da janela segura, a recuperação faz somente GET de Sessions no intervalo original e confere a identidade técnica, sem criar outra Session. Pagamento encontrado é conciliado; Session aberta pode ser retomada, e ausência comprovada após o vencimento cancela a tentativa local. Antes do vencimento, ausência inconclusiva retorna `checkout_expired`. A leitura é limitada a 200 Sessions; se exceder o limite ou falhar, preserva o pedido e exige nova consulta/atendimento, sem presumir cancelamento ou cobrar outra vez.

Falha de PaymentIntent também verifica operação, pedido e vínculo existente. Reembolso parcial ou integral conserva consumo e bloqueia o saldo restante do pacote. Disputas consultam o estado atual no Stripe fora dos locks financeiros, evitando que um evento antigo reverta uma decisão mais nova. Uma disputa ganha restaura apenas saldo não utilizado quando não há reembolso; não cria outro grant ou apaga consumo. Uma confirmação de pagamento tardia não reabre pacote reembolsado. A deduplicação permanece transacional e falhas de validação não marcam o evento como concluído.

A política conservadora de bloqueio do pacote em reembolso parcial foi preservada. A restituição de saldo em disputa ganha completa o fluxo anterior; essa regra está explícita no painel de compra, FAQ e termos para revisão do lançamento. O reembolso remoto é feito pelo operador no Dashboard Stripe; não foi criada uma interface administrativa ou endpoint público para estornar dinheiro.

### Frontend e SEO

O fluxo existente de confirmação informa Free, Pro ou uso de um crédito antes da geração. O checkout continua preservando rascunho/logo, exige confirmação do backend no retorno e não emite automaticamente. Acrescentado feedback específico para tentativa de compra vencida, conservando a chave para evitar outra cobrança automática. Política de créditos/estorno e tratamento dos rascunhos foram esclarecidos em textos da interface e páginas informativas.

Removido o bloqueio HTTP incondicional da rota pública no `vercel.json`. Há regras `has` por query para `quotation_order`, `payment`, `draft` e `generation`, que continuam recebendo `X-Robots-Tag: noindex, follow`. O Angular aplica noindex aos mesmos contextos e mantém canonical limpo. Conta, login, cadastro e 404 preservam noindex; as APIs mantêm respostas privadas. A rota limpa só recebe `index,follow`, card e sitemap no build explicitamente habilitado. O build padrão continua com a ferramenta em prévia e meta noindex.

Criados testes do build de lançamento e ampliado o helper `build-quotation-generation-test.mjs --release`: compila um artefato separado em `dist/quotation-release`, testa descoberta real e restaura os arquivos de launch em `finally`. O arquivo gerado do workspace termina com `quotationEnabled=false`; nenhum ambiente de publicação foi ativado.

### Checagem operacional segura

Novo `feitoo-api/scripts/check-quotation-launch.php`, executável pela CLI, imprime JSON sanitizado com PHP/extensões, flags, configuração da fila, versão real do banco, SQL mode/isolation, motores das tabelas, presença de schema/coluna e permissões de escrita dos discos privados. Aceita MySQL/MariaDB pelo driver mysql e exige tabelas transacionais InnoDB; não exige instalar MySQL 8 em um ambiente MariaDB compatível.

Com `--stripe`, faz somente GET para conferir o Price/produto no ambiente já configurado, reaproveitando a validação do gateway. Não cria produto, Price, Session, pagamento, usuário, Processing ou consumo; não roda migrations e não imprime chaves, senhas, payloads ou mensagens de exceções. Pode ser usado com geração/compras desligadas. Não substitui prova do worker, scheduler, escrita real/limpeza ou o diagnóstico Bubblewrap.

## 3. Regras finais de consumo

| Situação                              | Comportamento                                                                                                       |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Sem Pro vigente                       | Free é a seleção padrão; uma unidade por usuário/data de reserva em `America/Sao_Paulo`                             |
| Pro vigente com saldo                 | Usa primeiro até 100 gerações no período real da assinatura; não cria outra assinatura                              |
| Pro vigente esgotado                  | Não usa Free como fallback; crédito pago requer escolha explícita                                                   |
| Crédito escolhido sem Pro prioritário | Reserva uma unidade de grant elegível do ambiente atual, em ordem de concessão                                      |
| Sucesso                               | Converte a reserva da origem em um consumo                                                                          |
| Falha definitiva                      | Libera uma vez; não consome                                                                                         |
| Retry automático                      | Conserva reserva/origem/emissão; não duplica consumo                                                                |
| Retry manual                          | Revalida direito e tentativa, preserva grant/período original; não transfere para outro dia/período silenciosamente |
| Replay ou download repetido           | Retorna a emissão/PDF existente, sem outro débito                                                                   |
| Expiração/exclusão após sucesso       | Remove conteúdo, conserva consumo e histórico financeiro                                                            |

Uma reserva de 23:59 que conclui após meia-noite consome a data original. A nova data recebe outra franquia; não há débito nos dois dias. Rascunho: 24 horas após a última edição. PDF: duas horas após concluir. Prazo pendente: 30 minutos. Crédito não tem expiração comercial; isso não amplia o TTL do PDF.

As escritas financeiras continuam serializadas pelo proprietário, com transações/locks e constraints existentes. Uma geração ativa por conta, token por tentativa e publicação condicionada impedem duas saídas/consumos em entregas duplicadas. Render e chamadas Stripe ocorrem fora dos locks longos. Não há fallback para XML/fiscal.

## 4. Testes executados

| Ensaio                                          | Resultado                                                                                                                                                                                                                                                  |
| ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend completo Windows/PHP 8.2.12, final      | **527 casos, 5.000 assertions; 520 aprovados, 7 skips**, sem falhas/erros                                                                                                                                                                                  |
| Backend completo Linux/PHP **8.5.4**            | **524 casos, 5.042 assertions; 523 aprovados, 1 skip**. Rodou antes dos três últimos casos de migration/recuperação; esses casos passaram na suíte final Windows. O ensaio complementar Linux não iniciou por falha do runtime local                       |
| Skips                                           | Linux: somente LibreOffice real opcional. Windows: o mesmo caso e seis verificações dependentes de symlink/sinais/POSIX/Linux. Os controles Linux passaram no laboratório real                                                                             |
| Bubblewrap/prlimit real                         | Exit 0; Clássico 1 página/26.053 bytes; Moderno 4 páginas/32.450 bytes com logo. Checksum/páginas, rede negada, `.env`/storage alheio/symlink inacessíveis e limpeza aprovados                                                                             |
| Angular completo                                | **348 testes aprovados em 30 arquivos**                                                                                                                                                                                                                    |
| Builds                                          | Produção padrão e artefato de lançamento completo passaram; 15 rotas prerenderizadas. Defaults restaurados após cada build                                                                                                                                 |
| SEO/assets                                      | **17 testes aprovados**, incluindo sete cards no lançamento, menu/rodapé, sitemap, modelos/FAQ, canonical, indexação pública e noindex privado                                                                                                             |
| E2E de geração no build de lançamento           | Laravel/Sanctum/CSRF, SQLite exclusivo, job database e subprocesso PDF reais; login preservado, snapshot/logo imutáveis, confirmação, refresh/clique duplicado, downloads iguais, Free esgotado, 419/410 e quatro larguras                                 |
| Checkout no navegador                           | TEST contratual simulado: crédito explícito, falha/indisponibilidade, retorno cancelado/pendente/pago, logo/IndexedDB e geração manual; sem chamada remota Stripe                                                                                          |
| Gateway/webhooks                                | TEST e LIVE com transporte HTTP simulado: produto/Price/valor/moeda/ambiente, chave estável, assinatura, eventos repetidos/distintos, saldo isolado, recuperação de Session, falha, refund e disputa ganha/evento antigo                                   |
| Concorrência MariaDB 10.4.32/InnoDB descartável | **5 pares Free/idempotência/claim/reconciliação + 13 pares comerciais**, conexões/processos independentes. Último crédito, refund versus reserva/finalização, Checkout repetido, webhooks duplicados, Pro/renovação/cancelamento e origens mistas passaram |
| Editor/rascunhos                                | **19 verificações responsivas + 24 cenários** com IndexedDB real e API simulada; e-mail/cadastro, Google simulado, conflitos, sessão, armazenamento, modelos, logo e 100 itens                                                                             |
| Seis ferramentas anteriores                     | **96 layouts e 8 downloads simulados**, sem erro de runtime; backend/Angular/SEO também passaram                                                                                                                                                           |
| Monetização fiscal                              | Duas ferramentas, 12 layouts, Stripe simulado; sem erro de runtime                                                                                                                                                                                         |
| PDFs independentes                              | **28 PDFs/64 páginas**, 14 cenários × dois modelos, PyMuPDF: integridade, hashes, texto integral, A4, imagens, paginação e totais aprovados                                                                                                                |
| Formatação                                      | Pint/Prettier e diff dos dois repositórios conferidos                                                                                                                                                                                                      |

O Docker Desktop local foi iniciado somente para o laboratório Linux já existente e encerrado ao terminar. Código foi montado como leitura; `.env`, storage operacional e banco de usuários não foram montados. Uma tentativa de build simultânea ao Docker encontrou falta de memória/paginação do Windows; a repetição após encerrá-lo passou. Não foram aumentados budgets nem limites de produção. Permanecem avisos anteriores de SCSS/budget e depreciação Sass do Bootstrap.

A tentativa de reexecutar somente os últimos casos comerciais/migration no Linux não iniciou o PHPUnit: o daemon local, ao ser reiniciado, retornou HTTP 500 em `_ping`/`version`. O Docker foi encerrado novamente. Esse ensaio complementar não foi contado como aprovado; a evidência Linux é a suíte completa e o sandbox executados antes, e a evidência final de todas as alterações é a suíte Windows de 527 casos. A recuperação adicionada por último deve integrar também o aceite TEST externo da publicação.

Os daemons MariaDB de teste, em portas 33077/33078 e datadirs privados já existentes, foram encerrados; os scripts validaram raiz/nome do banco e criaram/removeram somente seus bancos aleatórios. Nenhum banco foi instalado ou alterado na VPS. O teste de migração de classificação histórica é isolado em SQLite; os cenários concorrentes aplicaram o conjunto de migrations no MariaDB descartável.

Evidências privadas, ignoradas pelo Git: `feitoo-api/storage/app/private/quotation-validation/launch-20261028-junit.xml`; PDFs/manifesto/extração/previews em `runs/20261028-132407-3dccce5b/`; relatórios atuais nos datadirs `mysql-phase4-20261022` e `mysql-phase5-20261024`; `feitoo-web/dist/validation/{quotation-generation,quotation-commerce,quotation-drafts,quotations,tools-experience,monetization-optimization}`; build padrão em `launch-default-build.log`. `dist/quotation-release` é validação local, não publicação.

**Não confundir contratos simulados com pagamento externo:** não foi criado Checkout remoto nesta entrega. A checagem sanitizada local encontrou credencial/webhook TEST configurados, mas **nenhum Price de créditos configurado**; por isso não houve jornada externa TEST. Google real, Safari/dispositivos físicos e LibreOffice real também não foram novamente exercitados. Nenhuma transação Live ocorreu.

## 5. Configuração necessária no Stripe

Usar a mesma conta e ambiente Stripe já utilizados pela instalação, preservando compras fiscais, assinatura e endpoint existentes. Não trocar o Stripe global operacional para TEST para homologar orçamentos; TEST deve usar instância/banco/sessões/storage separados.

Para **cada ambiente**, localizar um produto apropriado antes de criar outro. Configuração necessária para criação/ajuste manual autorizado:

| Campo                                      | Valor requerido                                                                                    |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| Produto                                    | Feitoo — 5 créditos de orçamentos; ativo e exclusivo desse pacote                                  |
| Metadata do produto                        | `code=quotation_5`, `credits=5`                                                                    |
| Price                                      | Ativo, pagamento único (`one_time`), moeda `brl`, valor inteiro **990**                            |
| Quantidade na Session                      | **1 pacote = 5 créditos**                                                                          |
| Recorrência/quantidade ajustável/descontos | Sem recorrência, sem quantidade ajustável e sem promoção no fluxo                                  |
| Ambiente                                   | Product/Price, secret key e secret de assinatura do webhook devem corresponder ao modo configurado |

Copiar os IDs reais obtidos para as variáveis do ambiente correspondente. **Nenhum Product/Price ID real foi informado ou inventado neste relatório. Nenhum produto, Price ou endpoint Live foi criado/modificado.** `php scripts/check-quotation-launch.php --stripe` deve conferir o contrato antes de habilitar compras; o gateway repete a validação antes da Session.

O endpoint é `https://api.feitoo.com.br/api/stripe/webhook`. Preservar o endpoint assinado compartilhado e sua versão compatível com o SDK do lock; a aplicação espera eventos **snapshot**, não thin. Conferir a união dos eventos, sem remover eventos fiscais/Pro:

```text
checkout.session.completed
checkout.session.expired
checkout.session.async_payment_succeeded
checkout.session.async_payment_failed
payment_intent.payment_failed
charge.refunded
charge.dispute.created
charge.dispute.closed
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
invoice.paid
invoice.payment_failed
```

Não substituir o secret de um endpoint operacional pelo de `stripe listen` ou de outro ambiente. O retorno de URL não confirma pagamento. Crédito só é concedido por sessão validada com `payment_status=paid`, independentemente de o usuário voltar ao editor. Webhooks e consultas de pedidos existentes continuam funcionando com novas compras desligadas.

Fontes técnicas consultadas: [webhooks Stripe, assinatura e entrega](https://docs.stripe.com/webhooks), [Checkout Session](https://docs.stripe.com/api/checkout/sessions/create), [objeto de disputa](https://docs.stripe.com/api/disputes/object) e [schema oficial da Vercel para regras de rota](https://openapi.vercel.sh/vercel.json). A política de créditos descrita aqui é da implementação Feitoo; não é uma regra atribuída ao Stripe.

## 6. Variáveis e parâmetros de lançamento

Nenhuma das variáveis abaixo foi ativada em produção por esta entrega. A tabela descreve o estado **a configurar pelo operador na publicação autorizada**.

| Variável/configuração                                | API de produção / publicação                                                                                                                                                             |
| ---------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `QUOTATION_GENERATION_ENABLED`                       | `true` no aceite; manter false na preparação e usar false para contenção                                                                                                                 |
| `QUOTATION_PURCHASES_ENABLED`                        | `true` somente com Price/endpoint Live verificados e autorização de vendas; default false                                                                                                |
| `STRIPE_MODE`                                        | `live` na instalação Live existente; não trocar modo global das ferramentas fiscais                                                                                                      |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`         | Secrets atuais do modo Live, armazenados somente no mecanismo privado de configuração                                                                                                    |
| `STRIPE_QUOTATION_CREDITS_LIVE_PRICE_ID`             | ID real do Price aprovado; sem fallback TEST                                                                                                                                             |
| `STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID`             | ID TEST somente na instalação de homologação TEST; não é o Price Live                                                                                                                    |
| `QUOTATION_SANDBOX`                                  | `bubblewrap`                                                                                                                                                                             |
| `QUOTATION_PHP_BINARY`                               | PHP CLI 8.5.4 validado; normalmente `/usr/bin/php`, conferir caminho real                                                                                                                |
| `QUOTATION_BWRAP_BINARY`, `QUOTATION_PRLIMIT_BINARY` | Binários instalados; normalmente `/usr/bin/bwrap`, `/usr/bin/prlimit`, conferir no host                                                                                                  |
| `FEITOO_QUOTATIONS_ENABLED`                          | `true` **no build Production da Vercel aprovado**; default false no código                                                                                                               |
| `FEITOO_PUBLIC_ORIGIN`                               | `https://feitoo.com.br`; previews sem origem indexável                                                                                                                                   |
| URLs/auth                                            | Preservar `APP_URL=https://api.feitoo.com.br`, `FRONTEND_URL=https://feitoo.com.br`, origens CORS exatas, `SANCTUM_STATEFUL_DOMAINS`, cookies seguros e domínio de sessão já homologados |
| Chave/storage                                        | Preservar APP_KEY e volumes privados atuais. Não gerar outra chave                                                                                                                       |

Não há chave Stripe no Angular. `FEITOO_PUBLIC_ORIGIN` só controla origem pública/SEO, não muda o endereço da API. Preview Vercel com a API operacional **não constitui staging isolado**.

Conexão `quotations`: database, tabela `jobs`, mesma conexão SQL transacional da API, fila `quotations`, `after_commit=false`, `retry_after=180`. Job 60 s, lease 90 s, render 30 s + margem externa, PHP filho 192 MiB. Preservar as conexões `database` e `documents` existentes. O slot atual é por host/volume; não ampliar concorrência/hosts sem medir capacidade.

## 7. VPS — comandos para uma atualização autorizada

**Não executados nesta entrega.** O usuário informou PHP 8.5.4, banco operacional, cron, três workers e migrations anteriores já aplicadas. Nenhum alias SSH/caminho remoto foi confirmado nesta sessão; por isso não se afirma que a VPS foi inspecionada. O caminho abaixo é o documentado no repositório e deve ser conferido antes de executar. Se for diferente, ajustar apenas a variável. Não instalar outro banco nem recriar workers/cron.

Antes da janela: registrar revisão aprovada da API e do frontend, backup consistente pelo processo já utilizado, revisão anterior para retorno, tabela de migrations atual e nome **real** do programa Supervisor exclusivo de orçamentos. Entregar a release pelo mecanismo existente; não executar comandos de descarte Git ou restaurar stashes sobre o servidor.

**Ordem de compatibilidade:** preparar a nova release sem ativá-la, com acesso ao `.env`/storage existentes pelo mecanismo de releases da instalação. Rodar a migration aditiva nessa preparação enquanto a API pública ainda usa a versão anterior. Só depois trocar a release ativa e recarregar os processos. Os comandos abaixo usam `FEITOO_API_DIR` para essa pasta preparada; ajustar ao caminho real da release. Em publicação no mesmo diretório, disponibilizar/aplicar apenas o arquivo de migration antes de substituir os controllers/serviços novos. Não servir código que consulta `stripe_mode` antes da criação da coluna.

### Preparação com novas emissões e compras desligadas

```bash
export FEITOO_API_DIR=/var/www/feitoo/feitoo-api
cd "$FEITOO_API_DIR"
test -f artisan && test -f composer.lock
/usr/bin/php -v
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
/usr/bin/php artisan migrate:status
/usr/bin/php artisan route:list --path=quotations
/usr/bin/php artisan schedule:list
sudo supervisorctl status
sudo systemctl status cron --no-pager
```

No mecanismo privado de configuração, manter inicialmente geração e compras false, conservar todos os secrets/APP_KEY e definir o Price Live real. Não copiar `.env.example` por cima do `.env` atual. Conferir que somente a migration nova está pendente no escopo de orçamentos e revisar seu plano:

```bash
/usr/bin/php artisan migrate --pretend --path=database/migrations/2026_10_28_000001_add_stripe_mode_to_quotation_credit_orders.php
# Somente depois de revisão/backup/autorização da janela:
/usr/bin/php artisan migrate --force --path=database/migrations/2026_10_28_000001_add_stripe_mode_to_quotation_credit_orders.php
/usr/bin/php artisan config:cache
/usr/bin/php artisan view:cache
sudo -u www-data /usr/bin/php scripts/check-quotation-launch.php --stripe
sudo -u www-data /usr/bin/php artisan feitoo:quotation-sandbox-check
```

`www-data` deve ser substituído pelo usuário real do worker se a instalação usar outro. O preflight deve retornar `ok=true` com o banco realmente utilizado, InnoDB e configuração/Price coerentes; o sandbox check deve retornar zero. Flags false não fazem o preflight falhar. Não colar `.env`, cookies ou payloads financeiros em chamados/logs.

Conferir a configuração **existente** de Supervisor/cron: worker comercial usa conexão/fila `quotations`, timeout 60 e tries 0; reconciliação a cada minuto e purges por hora. Validar logs/horário da última execução, não só a listagem do schedule. Nome/path de programa real não é inventado neste documento. Não interromper workers operacionais para simular falhas; os ensaios destrutivos pertencem ao laboratório.

### Habilitação e recarga coordenadas, após autorização

Quando preflight, Stripe e janela estiverem aprovados, configurar no mecanismo privado `QUOTATION_GENERATION_ENABLED=true` e `QUOTATION_PURCHASES_ENABLED=true`, sem alterar modo/chaves/preços fiscais. Então:

```bash
cd "$FEITOO_API_DIR"
/usr/bin/php artisan config:cache
/usr/bin/php artisan view:cache
sudo supervisorctl status
# Definir com o nome EXATO do programa/grupo comercial confirmado acima:
read -r -p 'Programa Supervisor exclusivo de quotations: ' QUOTATIONS_PROGRAM
test -n "$QUOTATIONS_PROGRAM" || exit 1
sudo supervisorctl status "$QUOTATIONS_PROGRAM"
sudo supervisorctl restart "$QUOTATIONS_PROGRAM"
sudo supervisorctl status "$QUOTATIONS_PROGRAM"
# Se o processo de release exigir recarregar OPcache/FPM, conferir primeiro o serviço instalado:
sudo systemctl status php8.5-fpm --no-pager
sudo systemctl reload php8.5-fpm
sudo -u www-data /usr/bin/php scripts/check-quotation-launch.php --stripe
```

Confirmar o nome do serviço FPM antes de usar esse exemplo; não assumir outro serviço/socket no host. Não usar `supervisorctl restart all`, `queue:restart` global, `queue:clear`, retry global, alterações no cron ou reinicialização de banco. O objetivo é atualizar o worker comercial existente sem mudar as seis ferramentas.

## 8. Frontend — publicação controlada na Vercel

Publicar a revisão aprovada de **`feitoo-web`**, que tem Git próprio. Não publicar `dist/quotation-release` como um atalho nem confundir o teste local com o deploy. Na Vercel existente, manter Root Directory/projeto correto, `buildCommand=npm run build` e `outputDirectory=dist/feitoo-web/browser`.

Definir `FEITOO_QUOTATIONS_ENABLED=true` somente no escopo **Production** da publicação aprovada, com `FEITOO_PUBLIC_ORIGIN=https://feitoo.com.br`. Preservar contato/responsável/analytics já aprovados. Previews continuam bloqueados para indexação e não devem acionar produção para testes de pagamento. O `vercel.json` desta release permite a rota limpa e protege os contextos privados por query; não restaurar o header incondicional antigo no deploy.

Comandos de reprodução local antes da publicação:

```powershell
# Em feitoo-web, defaults de revisão e regressão:
npm.cmd ci
npm.cmd test -- --watch=false
# Validação completa de lançamento, sem alterar defaults ou ambiente Vercel:
node scripts/build-quotation-generation-test.mjs --release
node --test scripts/quotations-release.test.mjs
$env:FEITOO_TEST_BROWSER_ROOT='dist/quotation-release/browser'
node scripts/check-quotation-commerce-browser.mjs
node scripts/check-quotation-generation-browser.mjs
```

Usar o fluxo de publicação Git/Vercel já existente após aprovação; não há comando CLI/projeto remoto confirmado para inventar nesta entrega. Em produção `VERCEL_ENV=production` e a flag Production geram o bundle público com home/menu/rodapé, sete ferramentas, canonical e sitemap de 14 páginas indexáveis. O HTML privado continua CSR/noindex. Validar o resultado servido, inclusive headers, depois do deploy.

## 9. Smoke após deploy e autorização específica para Live

### Aceite sem cobrança

- [ ] Revisões da API/frontend, migration nova e backup registrados; preflight da instalação e sandbox aprovados sob usuário real.
- [ ] Home, busca, menu mobile e rodapé abrem o gerador; `/gerador-de-orcamentos` tem canonical limpo, meta index e não tem header HTTP noindex; sitemap inclui a rota.
- [ ] Conta/login/cadastro e URLs de pedido/rascunho/geração por query preservam noindex. Nenhum dado pessoal aparece no HTML prerender, metadados ou sitemap.
- [ ] Visitante preenche, vê a prévia, escolhe modelo/logo, autentica-se por e-mail/Google e recupera o rascunho; nenhum PDF é emitido no retorno automaticamente.
- [ ] Conta Free controlada confirma a origem e gera um PDF. Uma segunda revisão no mesmo dia é recusada; re-download não consome. Outro usuário não lê draft/logo/status/PDF.
- [ ] Outra conta controlada verifica o segundo modelo. Pro válido consulta a assinatura existente e o bucket do período, sem abrir outra assinatura ou gastar crédito pago silenciosamente.
- [ ] Navbar e Minha Conta apresentam saldo, origem, compras e histórico consistentes; retorno de sessão expirada preserva o rascunho.
- [ ] Download autenticado retorna PDF com `no-store, private`/`nosniff`; após TTL, fica indisponível. Não adiantar relógio nem rodar purge destrutivo para acelerar o teste operacional.
- [ ] Scheduler/worker dão continuidade normal à fila; alertas cobrem pendência >30 min, lease abandonada, falha de webhook e limpeza atrasada.
- [ ] Smoke das seis ferramentas, assinatura e compras fiscais existentes passa sem mudar seus limites/preços.

Para conferência de headers públicos pelo operador:

```bash
curl -I https://feitoo.com.br/gerador-de-orcamentos
curl -I 'https://feitoo.com.br/gerador-de-orcamentos?quotation_order=smoke-context'
curl -I https://feitoo.com.br/conta
curl --fail --silent https://feitoo.com.br/sitemap.xml
```

O parâmetro de smoke acima é somente contexto inválido para conferir noindex; não representa um pedido nem autoriza acesso.

### Checkout TEST externo

Antes de vendas, na instância TEST isolada e com produto/Price TEST reais configurados: concluir Checkout com meios oficiais de teste, conferir pedido/grant de cinco unidades após webhook, retorno sem geração automática, reenvio do mesmo evento e confirmação distinta sem outro grant. Cobrir cancelamento/recusa/expiração, perda de resposta, refund e disputa criada/ganha/perdida. Não alterar o modo do Stripe fiscal de produção para esse ensaio. A ausência de Price configurado impediu essa jornada externa nesta sessão; a cobertura automatizada do SDK/handler é simulada e está separada na seção 4.

### Smoke Live com R$ 9,90 — somente mediante autorização específica

Não executado e não autorizado por este documento. Quando a transação e o reembolso forem aprovados:

1. Usar conta de teste controlada do Feitoo, saldo inicial conhecido, sem Pro prioritário para exercitar crédito. Registrar somente UUID do pedido, Session/PaymentIntent/event IDs e contadores; não registrar cartão, secrets ou payloads pessoais.
2. Comprar **um pacote de cinco créditos por R$ 9,90**, usando o Checkout normal e um pagamento real autorizado. Não alterar Price/amount para R$ 1,00 nem criar desconto para contornar o contrato de 990 centavos.
3. Antes da confirmação, saldo não aumenta. Após webhook validado, conferir exatamente um pedido paid e um grant de cinco. Parâmetro `payment=success` sozinho não muda saldo.
4. Reenviar o evento real pelo Dashboard autorizado e consultar o pedido novamente: um grant, mesmas cinco unidades, sem geração automática. Conferir os fluxos fiscais existentes nesse mesmo endpoint.
5. Selecionar explicitamente um crédito e concluir um PDF real. Grant deve ter `consumed=1`, `reserved=0` e quatro unidades livres. Re-download não altera esse estado.
6. Fazer reembolso integral de R$ 9,90 no Dashboard somente com autorização. Esperar `charge.refunded`; conferir `consumed=1`, `reserved=0`, `revoked=4`, saldo disponível zero e pedido refunded. Consumo/histórico não somem e o saldo não fica negativo. Reenvio do webhook não revoga duas vezes.
7. Conferir navbar/conta, isolamento entre usuários e logs sanitizados. Registrar aceite ou conter novas compras pela flag. Taxas/custos do provedor e uso do serviço devem fazer parte da autorização do smoke; não simular disputa com emissor de cartão real.

## 10. Rollback funcional e contenção

Primeira contenção: no mecanismo privado da API, `QUOTATION_PURCHASES_ENABLED=false` bloqueia novas Sessions sem desligar confirmação/reembolso de pedidos existentes. Se geração também estiver afetada, definir `QUOTATION_GENERATION_ENABLED=false`. Atualizar cache de configuração e recarregar somente o programa comercial confirmado na seção 7. Não remover chaves/webhook para conter novas compras: pagamentos aceitos ainda precisam ser conciliados.

No frontend, retornar `FEITOO_QUOTATIONS_ENABLED=false` no build Production aprovado e publicar pelo fluxo existente; a rota volta à prévia/meta noindex e sai da descoberta. Se houver necessidade de desindexação imediata enquanto o build está pendente, aplicar temporariamente o header incondicional noindex da rota **em uma reversão de configuração autorizada**, mantendo as regras privadas. Não apagar arquivos dos usuários como contenção.

Manter jobs aceitos, reconciliador, purge, status/downloads vigentes, pedidos e ledger. A flag não cancela reservas já aceitas nem Sessions já abertas. Não usar `migrate:rollback`, apagar `stripe_mode`, truncar tabelas, limpar fila, zerar grants/quota ou trocar APP_KEY. Não reverter para um gateway TEST-only enquanto existirem pedidos Live para confirmar/reembolsar. Usar este backend compatível com flags false ou corrigir para frente.

Uma reversão de release deve continuar entendendo as origens Free/Pro/crédito, a coluna aditiva e o ambiente dos pedidos. Backup antigo não pode apagar pagamentos/consumos posteriores; restauração de desastre é um procedimento distinto. Conferir novamente saldo, reservas, eventos recentes, downloads e as seis ferramentas após a contenção.

## 11. Pendências externas e decisão de lançamento

Os bloqueios de implementação encontrados foram tratados. Restam etapas de configuração e execução, **não novas fases de desenvolvimento**:

1. Confirmar/criar manualmente o produto e os Prices TEST/Live corretos, configurar IDs reais e conferir endpoint/eventos. Nenhum ID foi fornecido; o Price TEST local está ausente. Checkout externo TEST e smoke Live ainda não são evidências desta entrega.
2. Executar o preflight e o diagnóstico na VPS real, conferir usuário/path/serviços, versão/motor/SQL mode do banco existente, cron e worker. O contexto operacional foi informado pelo usuário; não foi fornecido acesso SSH nesta sessão e nenhuma inspeção remota autenticada foi feita. Não há exigência de instalar outro banco.
3. Aplicar a **única migration aditiva nova** na janela aprovada, depois de backup/revisão. Confirmar a classificação de qualquer pedido histórico especial antes de abrir vendas.
4. Aprovar o texto/política de reembolso parcial e disputa explicitados na compra e registrar revisões de API/frontend. Autorizar publicação e, separadamente, a transação/reembolso Live de R$ 9,90.

**Nenhum deploy, push, commit, alteração Stripe Live ou ativação automática de flags foi executado.** Esta entrega deixa uma versão integrada para a janela de lançamento, com comandos e critérios de parada explícitos. O lançamento só deve ser anunciado depois do aceite operacional e comercial registrado.
