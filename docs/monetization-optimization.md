# Otimização da monetização

## 1. Diagnóstico da implementação anterior

O backend já centralizava a concessão de acesso no `EntitlementService`. O plano Pro é reconhecido pela assinatura ativa; na ausência dela, o usuário usa o Free. Uma compra avulsa paga e ainda não consumida prevalece sobre Free e Pro apenas no `Processing` associado.

O uso diário é registrado no próprio processamento por ferramenta, data local, origem da concessão e quantidade reservada. A aceitação ocorre dentro de transação, com bloqueio do usuário e do processamento. O reset usa `America/Sao_Paulo`.

O upload cria e preenche um `Processing` privado antes do checkout. A compra guarda o processamento, quantidade, pacote, preço e validade. O retorno visual da Stripe apenas inicia uma consulta ao backend: somente um webhook assinado que resulte em compra `paid` permite o `/start`. Eventos Stripe são deduplicados por ID e a compra é consumida uma vez.

A seleção Angular já permanecia em memória na autenticação por e-mail. O redirecionamento externo do Google exigia nova seleção. A alternativa Free escolhia silenciosamente os primeiros arquivos, embora o restante do lote privado continuasse isolado até exclusão ou expiração.

O GA4 já respeitava consentimento, reconstruía propriedades a partir de uma allowlist e usava identificadores somente em armazenamento local para deduplicação. Os eventos de compra concluída, assinatura concluída e processamento já existiam.

## 2. Limites alterados

Somente `xml-to-excel` e `products-from-xml` receberam os limites Free de 3 XMLs por processamento e 6 XMLs por dia. Eles ficam em `monetization.xml_demo`, com defaults e variáveis opcionais `XML_DEMO_MAX_FILES_PER_PROCESSING` e `XML_DEMO_DAILY_FILES`.

Os limites anteriores permanecem para XML para PDF, Organizador e Analisador. DOCX continua usando sua configuração própria. Pro permanece em 250 arquivos por processamento e 500 por dia. Os pacotes permanecem em 100 XMLs por R$ 4,90 e 250 XMLs por R$ 6,90; pacotes acima do teto técnico não são expostos. O Pro permanece R$ 29,90/mês.

## 3. Arquivos modificados

Backend: `.env.example`, `config/monetization.php`, `EntitlementService.php`, `ProcessingStartService.php`, `ProcessingController.php`, `routes/api.php`, o teste de concorrência e testes de monetização/produtos.

Frontend: analytics, autenticação Google, armazenamento da seleção, serviço de processamento, workspace compartilhado de XML, conta/checkout Pro, cards de planos e ferramentas, FAQ, testes e scripts de navegador.

Não foi criada migration. Nenhuma credencial, produto Stripe, worker, Supervisor ou configuração de VPS foi alterada.

## 4. Fluxo de seleção e compra

Ao selecionar mais que o Free permite, o workspace mostra a quantidade escolhida, limite Free, pacote elegível, natureza avulsa da compra e opção Pro. A alternativa Free abre uma lista com checkboxes e exige escolha explícita; nada é selecionado ou descartado automaticamente.

Para compra avulsa, todos os arquivos selecionados são enviados ao mesmo `Processing` privado. O checkout é criado somente depois que o backend confirma que o lote inteiro chegou. O processamento e seus arquivos permanecem associados à compra durante retorno pendente, confirmado, falho ou expirado.

Quando a escolha Free ocorre antes do upload, somente os arquivos marcados são enviados e os demais ficam disponíveis localmente. Quando o lote já está no backend, o cliente envia os UUIDs escolhidos; o backend verifica ownership, existência, unicidade e limites antes de marcar os demais como removidos. Qualquer falha reverte a transação.

A seleção anterior à autenticação por Google é armazenada temporariamente no IndexedDB, com marcador em `sessionStorage` e validade de 15 minutos. Ela é restaurada na volta ao workspace e removida depois do processamento, limpeza ou expiração da transferência. O mesmo mecanismo preserva o lote ao navegar para conhecer o Pro.

## 5. Integração com Stripe

O checkout existente foi mantido. Preço, moeda e pacote continuam determinados pelo backend; o cliente não pode fornecê-los. A compra permanece única por processamento, usa a idempotência existente, expira conforme as regras atuais e não concede acesso pelo parâmetro de retorno.

O processamento avulso inicia apenas após `PurchaseStatus::Paid`, definido pela confirmação válida no backend. Pending, failed e expired não liberam o lote. Repetição de webhook não duplica pagamento nem consumo. Não houve acesso ou modificação do Stripe Live.

## 6. Proteções de segurança

Os limites são aplicados novamente no `/start`, portanto chamadas diretas à API não os contornam. A cota é separada por ferramenta e contabilizada sob bloqueio transacional. A seleção explícita aceita somente UUIDs de arquivos ativos do processamento autenticado.

Arquivos e resultados continuam privados, sujeitos a ownership e retenção. Nomes, conteúdo fiscal, e-mail, CPF, CNPJ e UUID não entram nos payloads GA4. Nenhum dado de cartão passa pela aplicação.

## 7. Eventos de analytics

Foram habilitados `upgrade_offer_viewed`, `upgrade_offer_selected`, `checkout_started` e `checkout_failed` na allowlist do `AnalyticsService`. Eles distinguem visualização, escolha, tentativa de checkout e falha. `checkout_started` é deduplicado para a compra avulsa; falhas terminais são deduplicadas pelo ID local da compra.

Continuam preservados `single_purchase_completed`, `subscription_completed`, `processing_started` e `processing_completed`. Compra iniciada e compra confirmada permanecem eventos diferentes. Todos dependem de consentimento e recebem somente propriedades permitidas.

## 8. Testes executados

Reexecutados na retomada em 20/10/2026, após conferir `git status`, o diff do repositório principal e o diff interno de `feitoo-web`, preservando as alterações anteriores:

- Backend completo (`php artisan test --compact`): 294 testes aprovados, 2.949 assertions; 1 teste opcional de integração real com LibreOffice ignorado. A primeira execução, antes dos seis novos cenários de preservação, passou com 288 testes e 2.777 assertions.
- Monetização isolada (`php artisan test --filter=MonetizationTest`): 21 testes aprovados, 388 assertions, incluindo os seis novos cenários de preservação de arquivos.
- Frontend completo: 20 arquivos e 211 testes aprovados.
- Assets públicos: 5 testes aprovados.
- Build Angular de produção: aprovado, com 14 rotas prerenderizadas.
- Laravel Pint (`--dirty`, `--test --dirty` e `--test` completo): aprovado. A correção anterior do script de concorrência foi preservada; o primeiro check desta retomada já retornou limpo. A formatação foi reaplicada e validada depois da inclusão dos novos testes.
- Prettier nos arquivos alterados: aprovado (`All matched files use Prettier code style`). Uma verificação ampliada de todo `src/app` encontrou cinco arquivos preexistentes, fora deste diff, ainda fora do padrão: `analysis.ts`, `analysis-pager.ts`, `xml-analyzer-dashboard.html`, `xml-analyzer-dashboard.ts` e `xml-analyzer.spec.ts`. Eles não foram reformatados para evitar ampliar esta mudança comercial.
- Smoke geral de navegador: aprovado, sem erros de runtime; cobriu workspace, conta, autenticação, pagamento e larguras de 320, 375, 390, 768, 1024 e 1440 px.
- Smoke dedicado (`node scripts/check-monetization-browser.mjs`): aprovado no Edge, com duas ferramentas, seleções de 1/3/4/40/100/250, 12 verificações de layout sem overflow e nenhum erro de runtime. Validou restauração da seleção via IndexedDB, retorno cancelado, retorno success ainda pendente, confirmação paga e estados failed/expired.
- `git diff --check`: aprovado.
- Concorrência MySQL (`php scripts/check-usage-concurrency.php`): falhou por infraestrutura. A conexão a `127.0.0.1:3306` foi recusada (`SQLSTATE[HY000] [2002]`), inclusive fora do sandbox, antes da criação das fixtures. O comando retornou código zero apesar da exceção; foi contabilizado como falha, não como aprovação. Nenhum serviço ou configuração de banco foi alterado.

Os testes cobrem Free 3/6 nas duas ferramentas, bloqueio de 4 no lote, bloqueio acima de 6 no dia, preservação de Pro e das outras ferramentas, seleção de 1/3/4/40/100/250, menor pacote elegível, lote completo no checkout, pagamento pendente/confirmado/falho/expirado, replay de webhook e proteção contra bypass da API.

Na validação dedicada da compra avulsa, lotes de 4/40/100/250 foram enviados integralmente nas duas ferramentas. O navegador descarregou a aplicação com navegação para `about:blank` e retornou ao mesmo processamento: os nomes e a quantidade permaneceram iguais, sem uploads adicionais. Cancelamento e success pendente não iniciaram o lote; a confirmação paga iniciou uma única vez. Failed e expired também preservaram os 40 inputs enviados sem iniciar trabalho. O checkout e as respostas de pagamento foram sintéticos; não houve acesso ao checkout remoto da Stripe.

No backend, os seis cenários novos cobrem 40/100/250 arquivos em cada ferramenta usando armazenamento privado de teste, gateway mockado e webhooks assinados sintéticos. Comparam UUIDs, nomes originais, caminhos, marcação de remoção e SHA-256 do conteúdo antes do checkout, depois de tentativas bloqueadas de start/seleção Free e depois da confirmação paga. Todos os arquivos permaneceram idênticos; replay do webhook e do start resultou em uma compra, um evento e um job, sem consumo Free.

O timeout anterior de `Runtime.enable` reapareceu dentro do sandbox. Fora dele, a execução revelou uma espera incorreta do smoke: no retorno do pagamento ele exigia o campo de upload, oculto quando o processamento já foi recuperado. A espera foi corrigida para a área de limites do workspace nesse retorno. A execução completa posterior passou e encerrou essa pendência. Evidência local: `feitoo-web/dist/validation/monetization-optimization/report.json` e screenshots na mesma pasta.

O build gerou temporariamente a origem pública em `src/environments/launch.generated.ts`; esse efeito foi revertido ao valor anterior, sem diff nesse arquivo. Não houve deploy, push, acesso ao VPS ou alteração de ambiente/credenciais Stripe. O smoke geral foi executado com analytics desabilitado (`consentAndSafeEvents: false`); os testes unitários de analytics passaram na suíte frontend, mas esse smoke não comprova envio ao GA4.

## 9. Comportamento mobile

A oferta usa o card do workspace, quebra textos longos, limita a altura da lista de seleção e torna os botões de largura total abaixo de 480 px. O smoke geral confirmou ausência de overflow horizontal nas larguras testadas. Screenshots sintéticos permanecem em `feitoo-web/dist/validation/` e não devem ser publicados.

## 10. Riscos e pendências

- Reexecutar o teste de concorrência com MySQL local disponível. A suíte backend usa SQLite em memória e não substitui a validação de locks entre duas conexões MySQL reais.
- O check global do Prettier ainda falha nos cinco arquivos preexistentes listados na seção 8; o conjunto de arquivos desta implementação está aprovado.
- O build mantém avisos preexistentes de orçamento CSS em `app.scss`, `home.scss` e `tool-experience.scss`; não bloqueiam o build.
- O teste real opcional com LibreOffice segue ignorado por depender da instalação externa.
- A preservação por IndexedDB depende do navegador permitir armazenamento local. Quando indisponível, o usuário recebe mensagem e a navegação externa não prossegue para evitar perda silenciosa da seleção.
- Não foi realizado pagamento remoto, teste com credenciais Stripe nem homologação em produção.
- Não foi executado GA4 DebugView remoto; a cobertura de analytics desta retomada é unitária.

## 11. Procedimento de homologação

1. Em ambiente de teste, confirmar no config e em `/account/usage` os limites 3/6 para as duas ferramentas e os limites anteriores para as demais.
2. Testar seleções de 1, 3, 4, 40, 100 e 250 XMLs em desktop e mobile.
3. Em lote acima de 3, escolher arquivos não consecutivos para Free e confirmar que somente eles processam.
4. Em lote de 40, iniciar a compra de R$ 4,90 e confirmar que os 40 arquivos permanecem no mesmo processamento.
5. Repetir com 100 e 250, conferindo respectivamente R$ 4,90 e R$ 6,90.
6. Testar login por e-mail e Google depois da seleção; conferir restauração sem novo upload.
7. No Stripe Test, validar cancelamento, recusa, expiração e pagamento aprovado. Confirmar que apenas webhook assinado libera o `/start`.
8. Reenviar o mesmo webhook e confirmar uma única liberação.
9. Conferir GA4 DebugView com consentimento concedido e negado, sem nomes, documentos, e-mail ou UUID.
10. Reexecutar testes, build, Pint, Prettier, smoke tests e `git diff --check` antes de qualquer deploy.

## 12. Plano de reversão dos limites

A reversão comercial não exige migration. Ajustar `XML_DEMO_MAX_FILES_PER_PROCESSING` e `XML_DEMO_DAILY_FILES` para os valores aprovados, limpar/recriar o cache de configuração e revalidar config, uso e `/start`. Se as variáveis não existirem, os defaults permanecem 3 e 6.

Para reverter todo o código desta etapa, restaurar os arquivos listados na seção 3 por commit/revert após backup e revisão. Compras, processamentos e uso existentes não devem ser removidos. A reversão não exige alterar produtos, preços, webhooks ou credenciais Stripe.
