# Correção do HTTP 500 no Checkout de créditos

Data: 29/10/2026. Escopo: API e testes locais. Nenhum acesso ao Stripe remoto, Checkout real, alteração de produção, deploy, commit ou push foi executado. Nenhuma migration foi criada ou alterada.

## Conclusão e limite da evidência

**A causa exata dos cinco erros em produção ainda não está comprovada.** Os dados fornecidos mostram um pedido local persistido e uma criação remota sem resultado registrado; não distinguem rejeição do POST pelo Stripe, resposta perdida, erro de validação ou falha ao gravar a resposta. O código/release e o schema efetivamente usados pelo PHP-FPM não foram acessados nesta investigação.

Foi reproduzida e corrigida uma falha funcional compatível com o incidente: uma exceção Stripe por timeout ou HTTP 5xx resultava em HTTP 500. Mesmo que a Session já existisse remotamente, o POST não procurava recuperá-la imediatamente. A recuperação por GET só ocorria quando faltavam menos de 30 minutos para a expiração local. Agora o mesmo mecanismo também é usado imediatamente após uma falha ambígua.

Também foi corrigida a falta de diagnóstico por etapa. Erros inesperados de créditos eram registrados como `Authentication request failed.`, apenas com a classe da exceção; exceções comerciais derivadas de `HttpException` normalmente nem entravam no callback de report do Laravel. A resposta genérica não permitia localizar a falha.

Não se deve interpretar esta correção como prova de que o Stripe retornou 5xx no incidente. Uma configuração de URL inválida, dependências/release divergentes ou uma falha de banco continuam exigindo evidência específica do host.

## Evidências

- Informado: cinco POSTs com `{"return_destination":"account"}` retornaram 500; apenas o pedido interno 1, `pending`, `live`, criado às 14:38 UTC, foi encontrado; sem `session_id` ou `checkout_url`.
- Informado: preflight Live com `ok=true` e `stripe_price_product_verified=true`. Isso confirma aquela leitura de Price/Product, não a execução de `checkout.sessions.create`, a URL de retorno nem a gravação posterior.
- Informado: log Laravel apresentado sem exceção comercial; PHP-FPM apenas com reload normal. Nenhum desses trechos comprova ausência de Session remota ou elimina erro em outro canal de log.
- Código local: a primeira transação termina antes de qualquer operação Stripe. Uma falha posterior preserva o pedido pendente. A segunda transação grava Session e URL juntas.
- Regressão antes da correção: o fluxo completo de conta com resposta válida passou; os cenários de 5xx após criação remota e timeout retornaram **500**, falhando respectivamente nas expectativas de recuperação 201 e indisponibilidade 503.
- O fluxo completo usa o SDK instalado, controller, serviço, model e banco SQLite em memória. Somente o transporte HTTP Stripe é substituído por fixtures. O sucesso de `account` foi reproduzido sem modificar sua URL, metadata, Price ou chave idempotente.

## Caminho da requisição e pontos de falha

| Etapa / marcador de diagnóstico | Operação real | Resultado/falha possível |
| --- | --- | --- |
| `request_validate` | Auth/CSRF nas rotas e validação do controller: só `return_destination` e header `Idempotency-Key` UUID | 401/419/422/429; entrada inválida não cria pedido |
| `checkout_configuration` | Flags de geração/compra, modo e disponibilidade do gateway | 503 comercial se indisponível; divergência de release/config precisa ser conferida |
| `order_persist` | Transação, lock do usuário, `firstOrCreate(user_id,idempotency_key)` | Schema ausente/incompatível, conexão ou SQL podem gerar 500 antes do Stripe |
| `order_reload` | Recarrega defaults do banco e confere `stripe_mode` | Falha de banco pode gerar 500; modo divergente gera 422 |
| Reutilização local | Pedido finalizado retorna como está; URL pendente válida é reutilizada | Nenhum novo POST Stripe nesse caminho |
| `stripe_client` | Instancia SDK, confere configuração, ajusta conexão 5s, timeout 15s e uma repetição de rede | Falhas de transporte/SDK; limite por chamada não representa o tempo total de toda a requisição |
| `stripe_price_verify` | GET do **Price gravado no pedido**, com Product expandido | API/rede, Price/Product inativos ou inconsistentes. Preflight do Price configurado hoje não substitui esta verificação do pedido |
| `stripe_session_parameters` | Calcula metadata, paths, URLs e expiração original | Configuração de frontend inválida, tipos inesperados ou incompatibilidade de release |
| `stripe_session_create` | POST `checkout.sessions.create` com chave do UUID do pedido | Rejeição de parâmetro, credencial sem permissão de escrita, rate limit, timeout, 5xx; antes chegavam como 500 genérico |
| `stripe_session_response` | Confere ambiente, prefixo da Session e URL de redirecionamento | 503 se o ambiente/URL divergir; erro inesperado de SDK/tipo permanece 500 sanitizado |
| `session_validate` | Confere modo de pagamento, referência, metadata, total e moeda | 422 `payment_mismatch`; agora também tem diagnóstico técnico |
| `session_persist` | Nova transação com locks; rejeita Session diferente já registrada; grava `session_id` e `checkout_url` | Erro de banco pode retornar 500 mesmo após criação remota; rollback mantém a ambiguidade, sem prova de ausência remota |
| `response_serialize` | Formata recurso e JSON 201 ao navegador | Datas/defaults incompatíveis, erro de serialização ou execução podem retornar 500; se a transação anterior terminou, a Session já está gravada |
| `stripe_session_recovery` | GET paginado na janela original para recuperar criação ambígua | Timeout/erro/limite de busca preservam incerteza; nunca criam outra Session |
| `session_recovery_persist` | Valida e vincula Session encontrada usando os locks e a reconciliação existentes | Retoma Session aberta ou aplica estado remoto confirmado; falha de persistência não autoriza recriar cobrança |
| `stripe_session_retrieve` / `session_reconcile` | Consulta individual de pedido com Session e reconciliação | Mesmo tratamento sanitizado nas falhas do GET e do banco |

Erros de infraestrutura anteriores ao Laravel, encerramento do processo pelo PHP-FPM e indisponibilidade do próprio logger podem impedir qualquer log da aplicação. O reload normal apresentado não permite diagnosticar esses casos.

## Payload, destino, URLs e resposta

O destino aceito é `editor` ou `account`; o padrão continua `editor`. Ele é persistido no primeiro pedido e não muda num retry com a mesma chave. O navegador não fornece uma URL arbitrária.

Para `account`, com o frontend de produção esperado:

```text
success_url = https://feitoo.com.br/conta/creditos?quotation_order=<uuid>&payment=confirming
cancel_url  = https://feitoo.com.br/conta/creditos?quotation_order=<uuid>&payment=cancelled
```

O prefixo real vem de `feitoo.auth.frontend_url` / `FRONTEND_URL`; o path do editor é `/gerador-de-orcamentos`. Esses parâmetros de retorno não confirmam pagamento nem concedem crédito.

O POST usa `mode=payment`, cartão, quantidade 1 e o `price_id` persistido. Adaptive Pricing, imposto automático e promoções permanecem desativados. O vencimento permanece o timestamp original do pedido, inicialmente uma hora após sua criação. Não foram removidos parâmetros para contornar uma rejeição ainda não comprovada. A referência oficial define esses parâmetros e a janela de expiração da [criação de Checkout Session](https://docs.stripe.com/api/checkout/sessions/create).

A metadata técnica é a mesma na Session e no PaymentIntent:

```text
quotation_order = <uuid técnico do pedido>
operation       = quotation_credits
```

Nenhum e-mail, nome ou dado de cartão é adicionado ao payload pela aplicação. O gateway valida Price ativo, BRL, 990 centavos, tipo `one_time`, Product ativo, `code=quotation_5`, `credits=5` e ambiente coerente. A resposta é um `Stripe\Checkout\Session`; `StripeEnvironment` confere `livemode`, prefixo `cs_<modo>_` e redirecionamento HTTPS em `checkout.stripe.com`. O serviço também confere referência, metadata, total e moeda antes da persistência.

O teste com total divergente confirma que uma resposta inválida não é gravada nem exposta como redirecionamento. As validações financeiras e de ambiente existentes foram mantidas.

## Correção e idempotência

Arquivos alterados:

- `feitoo-api/app/Services/Quotations/QuotationOrderService.php`: captura somente erros Stripe ambíguos de conexão/5xx e reutiliza a recuperação existente por GET; o bloco antigo de recuperação foi extraído para `recoverSession` sem mudar suas validações.
- `feitoo-api/app/Services/Quotations/QuotationStripeGateway.php`: identifica as etapas de cliente, Price, parâmetros, POST e resposta.
- `feitoo-api/app/Http/Controllers/QuotationOrderController.php`: identifica validação, consultas e serialização.
- `feitoo-api/app/Services/Quotations/QuotationOrderDiagnostics.php`: contexto técnico sanitizado por requisição e deduplicação do mesmo erro.
- `feitoo-api/bootstrap/app.php`: log específico de falhas de créditos, inclusive HttpExceptions 5xx e divergência de pagamento; erros Stripe não recuperados retornam 503 `checkout_unavailable`. Falhas inesperadas de banco/execução continuam 500.
- `feitoo-api/tests/Feature/QuotationCheckoutFailureTest.php`: regressões com SDK e HTTP simulado.

A chave externa continua exatamente `quotation-order-<uuid>`. A restrição única local continua `(user_id,idempotency_key)` e os locks existentes foram preservados. Retry com a mesma chave reutiliza UUID, Price, destino e expiração; o teste compara também os parâmetros e headers enviados após avançar o relógio. Repetir uma tentativa já persistida não executa outro POST Stripe.

Em timeout/5xx, a falha é registrada e o serviço procura a Session usando apenas GET, na mesma janela `created_at - 5s` até `expires_at`, com a paginação existente e limite de 200 registros. A identidade exige metadata e `client_reference_id` do pedido. Duplicidade, modo divergente, falha ou busca truncada não são tratadas como ausência remota.

Se encontrar Session aberta válida, grava e retorna sua URL. Se encontrar pagamento confirmado, usa a reconciliação já existente e sua concessão única; o teste confirma que repetir a operação não duplica o grant. Se não encontrar Session enquanto a tentativa continua vigente, mantém o pedido pendente, sem limpar chave ou renovar expiração. Depois da janela segura para POST, o caminho antigo continua exclusivamente por GET; não ressuscita uma chave antiga.

Isso também evita repetir indefinidamente um POST cujo 500 tenha sido memorizado pelo provedor quando há Session recuperável. Na API v1, a Stripe pode reutilizar inclusive a primeira resposta 500 para a mesma chave; a retenção não é eterna. Por isso, trocar a chave para “resolver” o erro seria inseguro. Ver [idempotência Stripe](https://docs.stripe.com/api/idempotent_requests).

O frontend existente conserva a chave em `sessionStorage`, bloqueia o botão enquanto ocupado e consulta o pedido pendente. Essa proteção depende da mesma chave: a API não deduplica intenções diferentes entre abas com chaves novas. Não foi introduzida proibição global de compras distintas. Não limpar o armazenamento de uma tentativa ambígua para forçar nova compra. Mudanças em `FRONTEND_URL` durante uma tentativa também podem mudar o payload reconstruído; manter a configuração estável até sua reconciliação.

## Log sanitizado

Evento: `Quotation credit order failed.`

Campos permitidos: etapa, ID interno do pedido quando conhecido, modo Stripe, destino enumerado, presença de Session, classe da exceção, nome do arquivo de origem e linha. Para erros Stripe: HTTP status, Request ID de formato validado, código e nome de parâmetro presentes em listas permitidas. Para erros comerciais próprios: código técnico `reason`.

Não são enviados ao logger: objeto da exceção, mensagem, stack trace, SQL, bindings, corpo de request/response, headers de autorização, chave idempotente, credenciais, e-mail, dados do cliente, valor financeiro ou URL de Checkout. Códigos e parâmetros externos fora da lista são registrados como `null`. O mesmo erro tratado e posteriormente relançado não gera log duplicado.

Exemplo fictício:

```json
{"stage":"stripe_session_create","order_id":1,"stripe_mode":"live","return_destination":"account","has_session":false,"exception_type":"Stripe\\Exception\\InvalidRequestException","source_file":"ApiRequestor.php","source_line":200,"stripe_http_status":400,"stripe_request_id":"req_example","stripe_error_code":"url_invalid","stripe_parameter":"success_url"}
```

Esse exemplo não é evidência do incidente. O log identifica onde investigar sem copiar a mensagem potencialmente sensível do Stripe. A classe e etapa também distinguem falha de conexão, rejeição de API, incompatibilidade de ambiente e erro de persistência.

## Testes

Os novos testes usam exclusivamente modo TEST, credenciais fictícias, SQLite `:memory:` e `Stripe\HttpClient\ClientInterface` mockado. Não existe comunicação com Stripe Live ou Test. As suítes antigas incluem fixtures com `livemode=true` e prefixos fictícios apenas para testar isolamento entre ambientes; também não usam o serviço Stripe Live.

Casos novos:

1. Fluxo HTTP completo de `account`, URLs exatas, metadata e chave corretas, persistência e repetição sem novo POST.
2. Stripe 500 após criação remota: recuperação imediata por GET e retorno da Session original.
3. Timeout sem correspondência remota: 503, pedido pendente e retry com chave/payload originais.
4. Timeout durante a recuperação: pedido pendente, sem segunda criação.
5. Session já paga recuperada: reconciliação normal e grant único num replay, somente em banco de teste.
6. Rejeição de parâmetro pelo provedor: diagnóstico com Request ID, código e parâmetro, sem dados sensíveis.
7. Exceção de banco injetada na gravação após sucesso remoto: rollback, log na etapa correta e retry da mesma Session.
8. Schema sem `return_destination`: falha identificada antes de qualquer chamada remota. A coluna é removida somente no banco em memória desse teste.
9. Total divergente na resposta: rejeição sem persistir Session/URL ou conceder crédito.
10. Validação de entrada comum: 422 sem ser registrada como falha técnica.

Comandos executados dentro de `feitoo-api`:

```powershell
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter QuotationCheckoutFailureTest
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter 'Quotation|StripeEnvironmentTest|AccountTest|SubscriptionTest'
php vendor/bin/pint --dirty --test
git diff --check
```

Resultados: **10 testes novos, 109 assertions, aprovados**. Validação ampliada: **280 testes, 2.693 assertions, sem falhas; 6 testes ignorados por dependências do ambiente** (recursos de symlink/POSIX/Linux e sandbox). Pint e verificação de whitespace aprovados.

O script Docker de testes não pôde iniciar porque o daemon Docker Desktop local estava desligado. Foi usado PHP CLI 8.2.12 / PHPUnit 11.5.57 no Windows. Os testes não verificam conectividade, TLS, permissões de escrita da chave Stripe, MySQL real ou PHP-FPM do host de produção.

## Compatibilidade e atualização da API

Procedimento para o operador responsável; **não executado nesta tarefa**. Esta correção não exige frontend novo, alteração de preço, saldo, ledger, APP_KEY ou fila.

1. Preparar release completa a partir do diff revisado, incluindo a nova classe de diagnóstico. Preservar `.env`, APP_KEY e storage. Conferir no host que o document root/FastCGI aponta para essa mesma release; não copiar só o controller ou só o serviço.
2. Instalar dependências do `composer.lock` da release, sem `composer update`. O checkout local usa `stripe/stripe-php` 22.0.0, com API padrão `2026-09-30.endive`, e Laravel 12. Conferir a dependência efetivamente instalada no destino e os requisitos da plataforma:

   ```sh
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   composer check-platform-reqs --no-dev
   composer show stripe/stripe-php
   php artisan migrate:status
   php artisan route:list --path=quotations/credit-orders
   ```

3. Conferir a existência das migrations já exigidas pelo fluxo: `2026_10_24_000001_create_quotation_commerce_tables`, `2026_10_28_000001_add_stripe_mode_to_quotation_credit_orders` e `2026_10_29_000001_add_account_commerce_context`. A última adiciona `return_destination` e campos de contrato de assinatura. A correção não adiciona migrations. Se já aplicadas, não executar migrate. Se alguma estiver pendente, revisar especificamente o schema e esse pré-requisito antes de atualizar a API; não executar migrations em lote sem revisão.
4. Verificar somente o schema e as restrições, sem consultar dados pessoais nem editar o pedido:

   ```sql
   SHOW COLUMNS FROM quotation_credit_orders;
   SHOW INDEX FROM quotation_credit_orders;
   ```

   Exigidos: `stripe_mode`, `return_destination`, `session_id`, `checkout_url`, `expires_at` e demais campos do model; Session única e chave única por usuário. Um pedido com `stripe_mode=live` prova a existência dessa coluna, mas não prova que `return_destination`, o restante do schema e todas as instâncias da release estejam atualizados. A migração de modo classifica histórico pelo prefixo de Session; não deve ser reaplicada ou adaptada para reclassificar manualmente pedido ambíguo sem Session.
5. Conferir configuração efetiva no processo PHP-FPM: flags, modo, seleção do Price, origem HTTPS de `FRONTEND_URL`, canal/nível do log e permissão de escrita. Não imprimir `.env` ou `config:show` com secrets. O preflight anterior não elimina diferença entre cache CLI/FPM, Price atual e Price persistido, ou permissões de leitura/escrita distintas da chave.
6. Reconstruir caches da release com `php artisan config:cache` e, se usado no procedimento do host, `php artisan route:cache`. Recarregar o serviço PHP-FPM correto para atualizar OPcache pelo procedimento habitual do host. O reload deve acontecer após a troca completa da release. Não limpar filas, não reiniciar o ledger e não gerar APP_KEY.
7. Validar saúde e listagem autenticada de pedidos sem iniciar Checkout. Identificar o canal real do Laravel: `single`, `daily`, `stderr` ou stack podem ter destinos diferentes do arquivo apresentado. Procurar pelo evento `Quotation credit order failed.` e sua etapa. O nível ERROR precisa estar habilitado.
8. Para encerrar a causa do incidente, correlacionar os registros já existentes de 29/10/2026 às 14:38 UTC com a release efetiva e os Request Logs do Dashboard Stripe, consultados pelo operador em modo de leitura. Conferir POST, status, Request ID e eventual Session técnica. Não criar Checkout de diagnóstico nem reproduzir em Live.

O pedido de produção 1 não foi consultado nem alterado por esta tarefa. Qualquer reconciliação futura deve usar o fluxo existente, sob operação autorizada, validando a identidade remota. Não preencher Session/URL manualmente, não cancelar por `session_id` vazio, não renovar expiração e não gerar nova chave para contornar a ambiguidade.
