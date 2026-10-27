# Gerador de Orçamentos — Fase 6: homologação e prontidão operacional

**Data: 26/10/2026. Resultado: homologação incompleta; publicação e vendas não autorizadas.** A regressão local passou. MySQL 8 e sandbox Linux estão bloqueados por indisponibilidade de ambiente; a jornada externa Stripe TEST está bloqueada pela ausência de Price adequado e de staging homologado. A política de reembolso exige decisão e implementação antes de vender.

Foi corrigido um problema comprovado: `Retry-After` não estava exposto por CORS, impedindo sua leitura pelo Angular entre domínios. Nenhum deploy, migration operacional, acesso a VPS/Supervisor, alteração Stripe Live, compra real, commit ou push foi realizado. A única chamada remota de negócio foi uma listagem **somente leitura de Prices TEST**. Não foram criados produtos, preços, sessões, cobranças ou webhooks remotos.

## Escopo e critério de aprovação

Foram lidos `auditoria-gerador-orcamentos.md` e os relatórios das Fases 1–5, e inspecionados código, migrations, configuração, scripts e Git dos dois repositórios. Referências: raiz `2a158a7`, frontend `5fc68e0`, ambos com numerosas alterações locais anteriores, preservadas. Não foi encontrado `AGENTS.md` na árvore pesquisada. Os resultados descrevem o checkout local, não o estado publicado.

**Aprovado** significa verificação executada com evidência, limitada ao ambiente indicado. **Pendente** significa decisão ou validação ainda necessária. **Bloqueado** significa ensaio obrigatório não executado por falta de uma dependência identificada. Código revisado e testes com mocks não substituem MySQL 8, Linux ou Stripe reais.

| Requisito geral                      | Classificação | Evidência / limite                                                                         |
| ------------------------------------ | ------------- | ------------------------------------------------------------------------------------------ |
| MySQL 8 descartável                  | **Bloqueado** | Docker sem daemon; nenhum servidor MySQL 8 local identificado. Detalhes na seção 1.        |
| Sandbox Linux real                   | **Bloqueado** | Única distribuição WSL: `docker-desktop`, parada; nenhum Linux de homologação fornecido.   |
| Contrato comercial do pacote         | **Aprovado**  | Código/gateway/testes: `quotation_5`, 5 créditos, 990 centavos, BRL, `mode=payment`.       |
| Jornada externa Stripe TEST          | **Bloqueado** | Inventário remoto TEST não encontrou Price adequado. Nenhuma transação executada.          |
| Política de reembolso/disputa        | **Pendente**  | Proposta da seção 4 ainda depende de aprovação e mecanismo auditável. **Bloqueia vendas.** |
| Regressão local                      | **Aprovado**  | Backend, Angular, build, assets e seis checks de navegador; seção 6.                       |
| Infraestrutura e domínios de staging | **Pendente**  | Sem aferição de ACL Linux, capacidade, scheduler ou cookies nos domínios reais.            |
| Publicação e rollback documentados   | **Aprovado**  | [Procedimento separado](gerador-orcamentos-publicacao.md), não executado.                  |
| Habilitação Live                     | **Bloqueado** | TEST-only preservado; alterações e autorização futura necessárias, seção 11.               |

## 1. Resultado MySQL 8

**Bloqueado: não executado.** `docker version`, repetido fora do sandbox para eliminar a limitação de permissão, encontrou cliente 29.7.2, contexto `desktop-linux`, mas falhou ao acessar `dockerDesktopLinuxEngine`: pipe inexistente/daemon indisponível. `wsl --list --verbose` mostrou somente `docker-desktop`, `Stopped`, versão 2. A inspeção dos locais usuais encontrou MySQL Workbench 8.0 CE e o executável MariaDB do XAMPP; Workbench não é servidor MySQL 8. Nenhum banco operacional foi consultado. Não foi iniciado MariaDB para substituir esta homologação.

| Ensaio em MySQL 8                                       | Classificação | Evidência / critério ainda exigido                                                                                  |
| ------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------------------------------- |
| Todas as migrations novas                               | **Bloqueado** | Revisadas as três migrations de 22 e 24/10; aplicação real em MySQL 8 não ocorreu.                                  |
| Constraints, unsigned, FKs e exclusão restrita          | **Bloqueado** | DDL contém CHECKs de saldo/origem e FKs; falta `SHOW CREATE TABLE` e tentativas inválidas rejeitadas pelo servidor. |
| Unicidade de pedidos, sessões, PaymentIntents e grants  | **Bloqueado** | Índices presentes no código; falta violação controlada em MySQL 8.                                                  |
| Revisão, aliases, reservas e bucket diário/período      | **Bloqueado** | Uniques no schema e locks nos serviços; falta corrida com conexões independentes.                                   |
| Concorrência Free, Pro, último crédito e origens mistas | **Bloqueado** | Scripts anteriores disponíveis; não reexecutados com outro motor como substituição.                                 |
| Idempotência de compra e webhooks duplicados/distintos  | **Bloqueado** | Regressão contratual local aprovada; transações/locks MySQL 8 ausentes.                                             |
| Reembolso contra reserva/finalização                    | **Bloqueado** | Falta corrida real e conservação do grant no MySQL 8.                                                               |
| Expiração, renovação e cancelamento Pro                 | **Bloqueado** | Falta testar fronteiras temporais e corrida da assinatura no motor exigido.                                         |

As migrations são `2026_10_22_000001_create_quotation_drafts_table.php`, `2026_10_22_000002_create_quotation_generation_tables.php` e `2026_10_24_000001_create_quotation_commerce_tables.php`. Há LONGTEXT criptografado, unicidade de revisão/importação/chaves, grant único por pedido e bucket Pro por assinatura/período. As constraints MySQL comerciais impõem `reserved + consumed + revoked <= granted`, `reserved + consumed <= quota_limit` e associação exclusiva de origem. O limite Free de 1 é imposto pelo serviço sob lock; sua tabela não tem CHECK de soma <= 1.

Para liberar este item: usar servidor exclusivo MySQL **8 com CHECK efetivamente aplicado** (8.0.16+), InnoDB, schema sintético vazio, SQL mode/isolation/collation registrados e compatíveis com o destino. O [manual MySQL](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html) diferencia versões com enforcement de CHECK. Aplicar o conjunto completo de migrations nesse banco, repetir aplicação sem alterações pendentes e inspecionar `information_schema`/`SHOW CREATE TABLE`.

Executar os cenários de `scripts/check-quotation-concurrency.php` e `scripts/check-quotation-commerce-concurrency.php` em processos distintos, ampliando os ensaios para expiração e violações diretas das constraints. Os scripts atuais exigem porta 33070–33100, banco `feitoo_quotation_test_<12 hex>` e **datadir exato** nas raízes privadas das Fases 4/5. Não apontar esses scripts para um servidor existente nem remover suas travas para aceitar container: preparar o mapeamento equivalente ou adaptar o harness isolado, com revisão, antes de executar. Exigir relatório `mysql8=true`, IDs de conexão distintos, zero grants/jobs/reservas duplicados e saldo conservado. O relatório MariaDB da Fase 5 continua sendo evidência histórica, sem aprovação MySQL 8.

## 2. Resultado PDF e sandbox Linux

**Bloqueado: nenhum ensaio Linux foi executado.** O PHP disponível é CLI Windows 8.2.12 x64, com GD, DOM, mbstring, fileinfo, PDO MySQL/SQLite, XML, ZIP e OpenSSL. SOAP foi habilitado apenas no comando de testes para satisfazer dependência fiscal existente. Isso não comprova extensões, patches ou fontes do Linux futuro.

| Requisito no Linux                           | Classificação | Evidência disponível / falta                                                                                   |
| -------------------------------------------- | ------------- | -------------------------------------------------------------------------------------------------------------- |
| PHP CLI/extensões, Dompdf e GD               | **Bloqueado** | Dependências e execução Windows verificadas; inventário Linux ausente.                                         |
| Fontes e paginação                           | **Bloqueado** | Templates usam fontes locais; falta extração/inspeção Linux dos dois modelos e 100 itens.                      |
| bubblewrap/prlimit e namespaces              | **Bloqueado** | Comando revisado; binários/kernel não exercitados.                                                             |
| Isolamento e ausência de rede                | **Bloqueado** | `--unshare-all`, `--cap-drop ALL`, `--die-with-parent`; falta tentativa real de rede e inspeção de namespaces. |
| Restrições de filesystem/credenciais         | **Bloqueado** | Binds de código somente leitura e chave efêmera; falta prova negativa de leitura/escrita no Linux.             |
| Timeout, memória e CPU                       | **Bloqueado** | Limites configurados; timeout real Windows passou, sem medir RSS/CPU Linux.                                    |
| PDF multipágina e logotipo                   | **Bloqueado** | Motor real local passou; falta reprodução com o comando bubblewrap de produção.                                |
| Interrupção forçada do renderer/worker       | **Bloqueado** | Fencing/recovery testados logicamente; SIGKILL real Linux não executado.                                       |
| Recuperação de jobs e limpeza de temporários | **Bloqueado** | Reconciliador/purge exercitados localmente; falta falha abrupta Linux.                                         |

`QuotationExecutionRunner` recusa modo `php` fora de `local/testing`. O sandbox Linux monta `/usr`, bibliotecas e `/etc/php` como somente leitura, além dos diretórios de código necessários; usa `/proc`, `/dev`, `/tmp` efêmero e workspace exclusivo gravável. Não monta `.env`, `bootstrap/cache`, storage fiscal/DOCX ou diretórios pessoais. A cópia do snapshot usa chave efêmera; o filho não recebe credenciais DB/Stripe/OAuth nem APP_KEY principal. Auditar também o conteúdo efetivo de `/etc/php` e de todos os diretórios montados: não devem conter secrets ou symlinks externos inesperados.

Limites configurados, **sem declaração de capacidade medida**: PHP 192 MiB/30 s; parede externa 35 s; `prlimit` AS 320 MiB, CPU 32 s e arquivo 12 MiB; saída até 20 páginas/10 MiB; job 60 s, lease 90 s, retry_after 180 s, prazo pendente 30 min. AS não equivale a RSS, e `queue:work --memory` controla reciclagem do pai, não limita sozinho o pico do subprocesso.

Roteiro de aceite no Linux isolado, com o mesmo usuário sem privilégios do worker e fixtures fictícias:

1. Inventariar versões/extensões/fontes; executar o **runner real da aplicação** com bubblewrap/prlimit. A mera existência dos binários não basta. Falha de user namespaces deve permanecer falha fechada, sem liberar sandbox.
2. Em workspace sintético, verificar que `.env`, cache externo, outros arquivos privados e sentinelas fora dos mounts não podem ser lidos; código não pode ser escrito. Tentar DNS e TCP a um servidor sentinela controlado, incluindo loopback do host; exigir ausência de conexão. Registrar os testes negativos sem imprimir credenciais.
3. Renderizar classic/modern, logo alpha/JPEG e 100 itens; conferir texto, totais, número de páginas, integridade e fontes com parser independente. Observar CPU/RSS e latência por fixture, com fila e ferramentas atuais sob carga representativa.
4. Provocar estouro de tempo/memória e falha de storage somente no volume descartável; exigir ausência de publicação/consumo e liberação ou retry da reserva original.
5. Matar renderer e depois worker isolado. Após lease/retry, exigir token antigo inválido, no máximo um PDF/consumo, três tentativas automáticas limitadas e liberação terminal. Verificar zero processos órfãos.
6. Executar reconciliação/purge no ambiente descartável: workspaces inativos com arquivos antigos (>5 min), snapshots órfãos (>1 h), resultados expirados e rascunhos. Preservar assets ativos e sentinelas XML/DOCX. Exclusão física depende do scheduler; não prometer remoção instantânea.

## 3. Resultado Stripe TEST

**Contrato local aprovado; jornada externa bloqueada.** A inspeção privada de configuração em 26/10 encontrou `STRIPE_MODE=test`, chave com prefixo TEST e webhook secret presentes, sem imprimir valores. `STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID` está ausente e não existe cache local de configuração. A consulta GET de Prices ativos ao Stripe TEST completou: **um Price inspecionado, zero Prices BRL/990/one_time elegíveis**. Não se inventou ID e nenhum `.env` foi alterado.

Evidência privada ignorada pelo Git: `feitoo-api/storage/app/private/quotation-validation/phase6-20261026/stripe-readonly-report.json`. A sonda `stripe-readonly.php` usa o SDK, lê `.env` sem inicializar Laravel/banco e só permite a listagem remota com modo/chave TEST. A primeira tentativa teve `ApiConnectionException` no sandbox; a repetição autorizada fora dele completou. O relatório contém somente presença/tipo de configuração e resultado, sem secrets.

| Requisito Stripe                                         | Classificação | Evidência / limite                                                                                                 |
| -------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------------------------------ |
| `quotation_5`, 5 créditos, R$ 9,90, BRL, pagamento único | **Aprovado**  | `QuotationOrderService`, `QuotationCommerce`, gateway SDK e testes. Quantidade Stripe 1 representa um pacote de 5. |
| Price TEST adequado/configurado                          | **Bloqueado** | Inventário TEST real sem candidato.                                                                                |
| Checkout/cartão TEST/retorno real ao editor              | **Bloqueado** | Navegador validou contrato simulado; nenhuma Session remota criada.                                                |
| Webhook assinado real                                    | **Bloqueado** | Assinatura HMAC real com secret de fixture passou localmente; entrega Stripe externa não executada.                |
| Grant único/reentrega real                               | **Bloqueado** | Testes locais de mesmo evento e eventos distintos passaram; falta evidência remota/MySQL 8.                        |
| Cancelamento e falha reais                               | **Bloqueado** | Retornos/estados simulados passaram.                                                                               |
| Reembolso e disputa reais TEST                           | **Bloqueado** | Regressão local passou; nenhuma ação remota executada.                                                             |

Procedimento posterior, **não executado**:

1. Em sandbox/modo TEST do Dashboard correto, criar produto para créditos de orçamento. Identificá-lo operacionalmente com código `quotation_5` e descrição de **5 gerações**; opcionalmente metadata `code=quotation_5`/lookup key para rastreabilidade. O código local já é fixo; o gateway não depende dessa metadata para contabilizar.
2. Criar Price **ativo, one_time, currency=brl, unit_amount=990**, sem recorrência, desconto ou ajuste automático de preço. Copiar o ID real obtido; verificar `livemode=false` e produto ativo. Não reaproveitar Price de assinatura/XML ou Price Live.
3. Configurar esse ID somente no secret/env de staging em `STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID`, junto de chave e secret do webhook TEST do mesmo ambiente. Recarregar cache/processos apenas de staging. Não alterar o modo global do ambiente fiscal operacional.
4. Configurar `/api/stripe/webhook` no staging HTTPS com payload **snapshot**, versão de API compatível com o SDK instalado e eventos comerciais suportados; preservar também os eventos da assinatura/fiscal se o endpoint de staging for compartilhado. Secret de `stripe listen` é distinto do secret do endpoint Dashboard.
5. Comprar somente em TEST: cartão de sucesso `4242 4242 4242 4242`, validade futura e CVC de teste; usar cartões de recusa/disputa documentados. Confirmar retorno sem emissão automática e saldo 5 somente após backend confirmar pagamento. Registrar UUID local, Session, PaymentIntent e event IDs técnicos, sem dados de cartão. Ver [testes oficiais Stripe](https://docs.stripe.com/testing).
6. Reenviar o mesmo evento pelo Dashboard/CLI e entregar confirmação distinta da mesma sessão: um pedido, um grant e cinco créditos ao todo. Exigir 400 em assinatura inválida e rejeição de valor/moeda/ambiente divergentes; evento precoce deve ser novamente entregável.
7. Cobrir cancelar/abandonar, recusa, pagamento antes/depois do retorno, reembolso integral/parcial concorrente com geração e disputa criada/encerrada ganha/perdida. A URL `payment=...` não prova pagamento. O [guia de webhooks Stripe](https://docs.stripe.com/webhooks) orienta assinatura e reentrega; o handler atual exige eventos snapshot, não thin.

Há uma limitação operacional adicional: Session criada remotamente com falha antes de associar o ID ao pedido depende do retry da mesma chave/payload ou reconciliação de atendimento. Não existe varredura automática de pedidos sem Session. A [idempotência Stripe](https://docs.stripe.com/api/idempotent_requests) não substitui o ledger local durável; não repetir cegamente um pedido antigo, com vencimento já ultrapassado ou chave fora da janela de retenção do provedor. Confirmar a Session/PaymentIntent original antes de qualquer nova cobrança. Esta recuperação deve ter responsável, alerta e procedimento antes de vender.

## 4. Política de reembolso recomendada

**Pendente de aprovação comercial e implementação auditável; não habilitar vendas.** Esta é uma proposta operacional, não uma mudança silenciosa do contrato atual.

Hoje `QuotationOrderService::handle` trata qualquer `charge.refunded` positivo e `charge.dispute.created/closed` como bloqueio de todo o pacote. O saldo livre vai a `revoked`; reservas são impedidas de publicar e, ao liberar, também viram revogadas. Consumo já publicado é conservado. Disputa ganha continua `disputed`, sem restauração. O código evita saldo negativo, mas mistura **retenção provisória e perda definitiva** e não tem ferramenta de recomposição com trilha de auditoria. O valor reembolsado é cumulativo por máximo, sem ledger de cada ajuste.

Recomendação para a próxima implementação aprovada:

- Manter consumo histórico imutável e contabilizar unidades separadamente de dinheiro. Por grant: `G = A + R + C + H + V`, com concedido, disponível, reservado, consumido, bloqueado temporariamente e revogado definitivo; todos inteiros não negativos. Hoje não há campo/ledger próprio de `H`.
- Introduzir movimentos imutáveis com pedido/grant, causa, provedor/evento/ajuste único, montante, unidades, operador, motivo, data e referência de resolução. Compensar movimentos com novos registros; nunca editar SQL/contadores ad hoc. Transações seguem User → Order → evento/ajuste → Grant e compartilham a serialização com reservas/finalizadores.
- Na solicitação de reembolso ou abertura de disputa, bloquear temporariamente **somente o pacote afetado** e impedir novas reservas/finalizações dele. Fazer fencing das reservas sob o lock comum. Reserva ainda ativa continua contabilizada em R até ser liberada uma vez para H; não somar a mesma unidade em dois compartimentos.
- Conclusão que venceu a corrida permanece C. Bloqueio que venceu a corrida impede publicar; o trabalho é encerrado sem cobrar. Download de documento já concluído não consome de novo; sua retenção segue o contrato de arquivos.
- Reembolso confirmado ou disputa perdida converte apenas as unidades devidas de H/A para V. Reembolso pendente/falhado/cancelado mantém bloqueio ou o desfaz conforme consulta canônica do provedor. Disputa ganha libera H, **preservando V de reembolsos já efetivados**. Restaurar elegibilidade não gera PDF automaticamente.
- Reconciliar com o estado atual do provedor antes de liberar saldo; eventos podem chegar repetidos ou fora de ordem. Não somar reembolso e disputa sobre o mesmo dinheiro duas vezes; usar IDs dos ajustes e exposição líquida confirmada por PaymentIntent, limitada ao total pago. Reconciliar eventos órfãos/atrasados e não inferir que a mera ausência de webhook significa resolução.

Proposta de tratamento econômico de créditos usados: **assumir como perda operacional o valor de unidades já utilizadas atingidas por uma reversão obrigatória**, preservando C e registrando o ajuste monetário/perda vinculado ao pedido. Não criar dívida em créditos, não debitar outro pacote, Free ou Pro, e não recuperar saldo por apagar PDF. A decisão deve ser aceita expressamente pela operação antes de vendas; pedidos suspeitos podem ter revisão de risco própria, sem reescrever consumo histórico.

Para reembolsos voluntários parciais, trabalhar preferencialmente em unidades inteiras de **R$ 1,98** (990/5), após liberar/finalizar reservas, e até o saldo não utilizado. Estorno integral continua possível conforme decisão operacional; unidades consumidas tornam-se perda registrada, não saldo negativo. Exceções de valor e reversões impostas pelo provedor precisam de análise, mesmo se excederem o saldo disponível.

Se houver valor não múltiplo de 198 centavos, manter o pacote temporariamente bloqueado para revisão. Uma regra candidata para aprovação é `K = ceil(5 × valor_revertido_liquido / 990)`, limitada a 5; revogar `min(K, 5 - C)` unidades, registrar separadamente a parcela já usada e documentar o arredondamento ao cliente. Não aplicar essa regra automaticamente antes de aprovar seus efeitos comerciais. O valor financeiro auditado continua sendo o valor efetivo do provedor, não K × 198.

| Exemplo de decisão proposta                   | Resultado consistente                                                                                                      |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Pacote 5, C=0, refund integral confirmado     | V=5, A=R=H=0; montante 990.                                                                                                |
| Pacote 5, C=3, refund integral confirmado     | C=3, V=2; registrar 594 centavos de serviço já usado como perda, sem débito futuro.                                        |
| Pacote 5, C=2, refund voluntário de 198       | V=1, A=2, C=2 após drenar reservas; não revogar os três restantes.                                                         |
| Disputa aberta, C=2, sem reservas             | H=3 e C=2; nenhuma revogação definitiva apenas pela abertura.                                                              |
| Mesma disputa ganha, sem refund paralelo      | H→A=3; C=2 permanece; resolução única auditada.                                                                            |
| Disputa perdida ou ganha após refund paralelo | Revogar/liberar segundo exposição líquida e ajustes já confirmados; sem restauração de unidades definitivamente revogadas. |

O [guia Stripe de reembolsos](https://docs.stripe.com/refunds) distingue reembolso de cancelamento e estados pendentes/falhos. A proposta requer ampliar estados, ledger, processamento de eventos e testes concorrentes; **não está implementada nesta fase**. A política atual conservadora continua no código TEST-only, sem autorização para vendas.

## 5. Correções realizadas

**Uma correção de produção no código, ainda não publicada:** `feitoo-api/config/cors.php` passa a expor `Retry-After` além de `Content-Disposition`. Sem isso, o browser filtra o header em respostas entre origens, apesar de a API enviá-lo; o polling de geração/pagamento não consegue ler o prazo do 429. A regra é definida pelo [Fetch Standard/CORS](https://fetch.spec.whatwg.org/#http-access-control-expose-headers).

Foi ampliado o teste existente `QuotationGenerationTest::test_limiter_and_fail_closed_configuration_do_not_charge`: requisição com Origin distinto e permitido, flag desligada, limite real de requisições, resposta 429 com credenciais, exposição dos dois headers e nenhuma reserva. **Antes da correção falhou**: header exposto era somente `Content-Disposition`. **Após a correção passou**, 21 assertions. Não houve ampliação de origens, remoção de CSRF ou alteração de preços, contratos fiscais, assinatura ou checkout.

Também foram criados este relatório e o procedimento separado de publicação. Sonda/logs privados ficaram em diretórios já ignorados. Nenhuma implementação de reembolso, mudança de schema ou alteração do sandbox foi feita sem validação do ambiente.

## 6. Testes executados

Todos executados nesta fase, em dados fictícios e recursos locais descartáveis. SQLite foi usado **somente para regressão**, não como resultado de homologação MySQL 8. Nenhum teste foi apontado a banco operacional.

| Verificação                        | Classificação | Resultado / evidência                                                                                                                                                            |
| ---------------------------------- | ------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend completo antes da correção | **Aprovado**  | 430 passaram, 1 skip opcional LibreOffice; 4.071 assertions.                                                                                                                     |
| Reprodução e correção CORS/429     | **Aprovado**  | Falha esperada antes; passou depois com 21 assertions.                                                                                                                           |
| Backend completo final             | **Aprovado**  | Resultado final registrado em `phase6-20261026/backend-final.log`; 430 passaram, 1 skip opcional LibreOffice; **4.077 assertions**.                                              |
| Angular completo                   | **Aprovado**  | 330 testes, 28 arquivos, zero falhas.                                                                                                                                            |
| Build produção local               | **Aprovado**  | 15 rotas prerenderizadas; log `feitoo-web/dist/validation/phase6-build.log`.                                                                                                     |
| Assets/SEO                         | **Aprovado**  | 15 testes; seis ferramentas no catálogo, gerador fora da descoberta pública e sitemap.                                                                                           |
| Navegador geração                  | **Aprovado**  | Laravel/Sanctum/CSRF reais locais, SQLite exclusivo, job database, subprocesso PHP Windows, logo/snapshot, PDF 22.735 bytes, downloads idênticos, expiração 7.200 s e 4 layouts. |
| Navegador comércio                 | **Aprovado**  | API contratual simulada, chave estável, falha/cancelamento/pending/paid, saldo explícito, preservação do rascunho/logo, emissão manual e 3 larguras. Não é Stripe remoto.        |
| Navegador rascunhos                | **Aprovado**  | 24 verificações com IndexedDB real e API simulada: sessão, refresh, conflitos, logout, logo e storage.                                                                           |
| Navegador editor                   | **Aprovado**  | 17 verificações responsivas, cálculo, modelos, PNG/JPEG, 100 itens e privacidade.                                                                                                |
| Seis ferramentas atuais            | **Aprovado**  | 96 layouts, 8 downloads simulados, zero erros de runtime.                                                                                                                        |
| Monetização fiscal no navegador    | **Aprovado**  | 2 ferramentas, 12 layouts, zero erros de runtime.                                                                                                                                |
| Pint e diff                        | **Aprovado**  | Pint nos dois PHP alterados; `git diff --check` na raiz e frontend, apenas avisos de normalização LF/CRLF.                                                                       |

Comandos executados nas respectivas pastas:

```powershell
# feitoo-api: phpunit.xml define testing, sqlite :memory:, DB_URL vazio, cache/sessão array
php -d extension=soap artisan test --compact
php -d extension=soap artisan test --compact --filter=test_limiter_and_fail_closed_configuration_do_not_charge
php vendor/bin/pint --test config/cors.php tests/Feature/QuotationGenerationTest.php

# feitoo-web
npm.cmd test -- --watch=false
$env:FEITOO_BUILD_ENV='production'
npm.cmd run build
node --test scripts/public-assets.test.mjs scripts/home-docx-seo.test.mjs scripts/tools-experience-seo.test.mjs scripts/quotations-public.test.mjs
node scripts/check-quotation-generation-browser.mjs
node scripts/check-quotation-commerce-browser.mjs
node scripts/check-quotation-drafts-browser.mjs
node scripts/check-quotation-browser.mjs
node scripts/check-tools-experience-browser.mjs
node scripts/check-monetization-browser.mjs
```

O build manteve warnings anteriores de CSS: app 4,14 kB, home 6,76 kB e tool-experience 7,47 kB acima do warning de 4 kB, sem estourar o máximo de erro. O arquivo `launch.generated.ts` foi regenerado no modo local ao terminar, retornando ao estado inicial; o build avaliado permanece em `dist`.

Artefatos novos/atualizados, ignorados pelo Git: `feitoo-web/dist/validation/{quotation-generation,quotation-commerce,quotation-drafts,quotations,tools-experience,monetization-optimization}/report.json`, log de build e `feitoo-api/storage/app/private/quotation-validation/phase6-20261026/`. Uma cópia identificada dos seis relatórios e do log de build foi arquivada em `feitoo-web/dist/validation/phase6-20261026/`, pois os caminhos padronizados são substituídos em reexecuções. O skip de LibreOffice significa que conversão DOCX real com seu binário não foi novamente homologada.

## 7. Pendências e prontidão para produção

| Requisito                                           | Classificação                             | Evidência / ação necessária                                                                                                                                                                                                                                     |
| --------------------------------------------------- | ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Flag desabilitada por padrão em produção            | **Aprovado**                              | `config/quotations.php`: default false quando `APP_ENV=production`; `.env.example` sem habilitação comercial. Fixar explicitamente false também em staging até os ensaios.                                                                                      |
| Bloqueio independente de novas vendas               | **Pendente**                              | A flag de geração **não é kill switch de Checkout**. Oferta/compra dependem de TEST + Price. Na contenção atual, remover Price do ambiente impede novos pedidos; manter chave/webhook para processar pedidos aceitos. Futuro Live exige flag comercial própria. |
| Worker comercial/configuração transacional          | **Aprovado no código; pendente no host**  | Conexão `quotations`, database/jobs/fila quotations, mesmo DB, `after_commit=false`, retry_after=180. Um worker/slot por host inicialmente.                                                                                                                     |
| Scheduler                                           | **Pendente no host**                      | Código agenda reconcile a cada minuto, purge de rascunhos/processings por hora, `withoutOverlapping`; crontab, cache de lock e heartbeat reais não inspecionados.                                                                                               |
| Storage privado                                     | **Aprovado no código; pendente no Linux** | Discos comerciais local/private, serve=false; falta usuário/grupo, ACL, umask, espaço, proibição de symlinks públicos e teste real write/read/delete.                                                                                                           |
| Retenção/purge                                      | **Aprovado local; pendente operacional**  | Rascunho 24 h, PDF 2 h após conclusão, pendência 30 min; órfãos/leases com grace. Falta política de backups, alertas e aferição dos horários reais.                                                                                                             |
| Logs seguros/monitoramento                          | **Pendente operacional**                  | Exceções HTTP sanitizadas, renderer sem stdout/stderr público e job com UUID. Faltam métricas/alertas de fila, reserva antiga, webhook falho e purge. Auditar logs de proxy/APM/failed_jobs.                                                                    |
| CPU/memória/capacidade                              | **Bloqueado**                             | Limites configurados; falta benchmark Linux, p50/p95/RSS, uso de disco/DB e impacto nas seis ferramentas. Quota Pro 100/período ainda precisa de aceite de custo.                                                                                               |
| Recuperação após falhas                             | **Aprovado local; pendente Linux**        | Lease/token/reconcile e timeout local aprovados; SIGKILL, disco cheio e reinício do host não exercitados.                                                                                                                                                       |
| Rotas Angular/prerender/Vercel                      | **Aprovado local**                        | Build/SEO/browser; rota limpa, retornos opacos, noindex e fora do sitemap. Não comprova uma publicação.                                                                                                                                                         |
| Separação frontend de staging                       | **Pendente, bloqueia E2E externo**        | `src/environments/environment.ts` fixa `https://api.feitoo.com.br`; `FEITOO_PUBLIC_ORIGIN` altera SEO, **não a API**. Preparar build isolado com replacement de API/CSRF para staging; impedir tráfego de homologação ao host operacional.                      |
| Autenticação/CSRF entre domínios                    | **Pendente em staging**                   | Interceptor restringe cookies/XSRF à API, contexto de dono e CSRF web testados. Falta provar preflight/credentials/cookies e OAuth em par real HTTPS isolado. Retry-After corrigido.                                                                            |
| Compatibilidade fiscal, DOCX, assinatura e checkout | **Aprovado na regressão local**           | Suítes e browser passaram; contratos e workers preservados. Aferição com binários/infra do destino continua pendente.                                                                                                                                           |

Na homologação entre domínios: usar frontend/API sob domínio de staging próprio, cookies de nome exclusivo e domínio restrito a esse staging, sem compartilhar sessão/APP_KEY/DB com produção. Conferir `FRONTEND_URL`, `APP_URL`, `CORS_ALLOWED_ORIGINS` exato, `SANCTUM_STATEFUL_DOMAINS` com portas quando aplicáveis, `SESSION_DOMAIN`, Secure, HttpOnly da sessão e SameSite. Não usar wildcard de origem com credenciais. Ver [Sanctum SPA](https://laravel.com/docs/12.x/sanctum#spa-authentication). Capturar login, rascunho, geração, 419 sem CSRF, 404 de terceiros, 429 com Retry-After legível, logout/troca de conta e callback Google. Não afrouxar CSRF nem usar produção para validar.

## 8. Requisitos de infraestrutura

**Pendentes:** Linux com namespaces autorizados para usuário do worker, bubblewrap/prlimit, PHP CLI suportado e atualizado com extensões exigidas pelo lock (incluindo GD/fontes e pcntl para timeout do worker), storage local privado compartilhado entre API e worker, MySQL 8/InnoDB isolado, scheduler monitorado, TLS/DNS de staging e endpoint Stripe TEST.

Separar banco, APP_KEY, sessão, cache/locks, storage, fila, OAuth, Stripe e observabilidade de staging. Não apenas trocar `APP_ENV` em uma instância operacional. Se houver múltiplos hosts, `flock` local não limita concorrência global; revisar número de slots, localidade de arquivos e custos antes de escalar. Manter fila comercial separada de `default` e `documents`.

Definir orçamento de recursos após benchmark: pico simultâneo pai+filho, margem para API/XML/DOCX, swap/OOM, disco de outputs+logos+snapshots+temporários e retenção de logs/backups. Alertar quando geração exceder prazo técnico de 30 min, lease de 90 s ficar sem reconciliação, job mais antigo crescer, webhook ficar em erro/retry, purge atrasar mais de seu ciclo ou ocorrer `quota_inconsistent`. A prontidão não autoriza escolher capacidade com base somente no limite PHP.

## 9. Plano de publicação

O roteiro com comandos, configuração de worker, scheduler e pontos de parada está em [gerador-orcamentos-publicacao.md](gerador-orcamentos-publicacao.md). **Nenhuma etapa de deploy desse procedimento foi executada nesta fase.** Comandos de regressão também citados no roteiro foram executados somente no workspace local isolado.

Ordem proposta: provisionar staging isolado → homologar MySQL 8/Linux/Stripe TEST/domínios → aprovar e implementar reembolso/reconciliação → repetir regressão e capacidade → solicitar autorização operacional para uma versão identificada → publicar backend compatível com geração/vendas desligadas → conferir worker/storage/scheduler → publicar frontend correspondente → realizar verificações autorizadas → habilitar gradualmente somente após aceite. Live é uma autorização separada e requer mudanças de código; não basta mudar uma variável.

## 10. Plano de rollback

**Plano documentado, execução pendente de autorização.** Conter novas emissões com flag false e novas compras comerciais removendo o Price específico no mecanismo atual; manter API de consulta/download válido, webhooks, credenciais necessárias à reconciliação e ledger financeiro. Configuração cacheada e worker residente precisam ser recarregados de forma controlada; retirar Price não invalida Sessions já abertas. Monitorar e atender pagamentos tardios.

Drenar jobs já aceitos com código compatível ou deixar expirar/reconciliar reservas de forma idempotente. Não executar `queue:clear`, retry global, `migrate:rollback`, truncate ou exclusão financeira. Não voltar a uma versão anterior à Fase 5 que desconheça origens Pro/crédito. Não trocar APP_KEY: isso inutiliza snapshots/rascunhos ainda vigentes. Preservar schema aditivo e histórico; corrigir para frente se a versão anterior não puder ler os registros atuais.

No rollback verificar grants, soma de reservas/consumos/revogações, jobs comerciais pendentes, webhooks recentes, PDFs ainda baixáveis e funcionamento das seis ferramentas. Restauração de backup é procedimento de desastre separado: um backup antigo não deve apagar pagamentos/consumos aceitos posteriormente.

## 11. Checklist de autorização para Stripe Live

Auditoria: `StripeEnvironment` compartilhado já admite test/live, valida prefixo de chave, `livemode`, Session e host de redirect; fiscal e assinatura usam esse mecanismo. **O domínio de orçamentos acrescenta recusas TEST-only** em `QuotationStripeGateway::client/available`, `QuotationOrderService::validate/handle` e no fallback de roteamento de PaymentIntent. `quotations.credit_price_id` lê exclusivamente `STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID`. Nada disso foi removido.

| Requisito para autorizar Live                | Classificação atual                     | Mudança / comprovação necessária                                                                                                                                                                                                          |
| -------------------------------------------- | --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Aceite MySQL 8/Linux/Stripe TEST/domínios    | **Bloqueado**                           | Concluir ensaios obrigatórios deste relatório.                                                                                                                                                                                            |
| Política de usados, refund parcial e disputa | **Pendente**                            | Aprovar perda operacional/arredondamento e implementar ledger/resolução auditável com concorrência.                                                                                                                                       |
| Mapeamento de Price por ambiente             | **Pendente**                            | Configuração TEST e LIVE explícitas, sem fallback entre elas; IDs reais de mesmo pacote, produto ativo, one_time/990/brl, sem desconto/taxas alterando amount_total. Validação server-side mantida.                                       |
| Guard comercial Live próprio                 | **Pendente**                            | Habilitação explícita por ambiente e kill switch de novas vendas, independente de geração. Não trocar `STRIPE_MODE` global de uma instalação fiscal sem revisão.                                                                          |
| Remoção controlada TEST-only                 | **Pendente**                            | Revisar os quatro pontos acima em nova mudança; substituir por validação ambiente+habilitação, nunca remover a validação `livemode` compartilhada.                                                                                        |
| Separação durável de ambientes               | **Pendente**                            | Bancos separados; migrar dados de teste fora do banco operacional. Se coexistência for necessária, incluir provider/account/mode nos pedidos/eventos/uniques e consultas; nunca deduzir modo só de ID `price_`, `pi_` ou secret `whsec_`. |
| Webhook Live                                 | **Pendente**                            | Endpoint/secret próprios do ambiente, HTTPS, snapshot/API version compatível, eventos comerciais e existentes, assinatura de corpo bruto e limite de payload. Não usar secret TEST/CLI.                                                   |
| Idempotência/deduplicação                    | **Aprovado local; pendente Live-ready** | Preservar pedido/chave estáveis, unicidade de Session/PI/grant/evento e transação única. Testar replay, concorrência e recuperação após falha de associação da Session; não confiar só na janela Stripe.                                  |
| Testes de rejeição cruzada                   | **Pendente**                            | TEST recusa objetos/chaves/Prices Live; Live recusa TEST; assinatura incorreta/valor/moeda divergentes recusados; geração não automática; contratos fiscais/assinatura preservados. Executar com transporte controlado, sem compra real.  |
| Operação, alerta e reconciliação             | **Pendente**                            | Responsáveis, permissões mínimas de secrets, atendimento de Session órfã, reentrega, refund/disputa e contabilidade, métricas e rollback ensaiado.                                                                                        |
| Autorização comercial/operacional final      | **Pendente**                            | Aprovação explícita da versão, ambiente, Price e checklist completos. Nenhuma transação real faz parte desta fase.                                                                                                                        |

Chaves e recursos TEST/Live são separados no Stripe; webhook secret é distinto da API key. Referência: [chaves e ambientes Stripe](https://docs.stripe.com/keys). Só a validação de prefixos não comprova conta, Price ou endpoint corretos.

**Entrega para revisão operacional. A ferramenta não está homologada integralmente nem autorizada para publicação/venda. Aguardar aprovação operacional sem executar o plano.**
