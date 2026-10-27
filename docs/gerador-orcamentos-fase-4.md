# Gerador de Orçamentos — Fase 4: geração real, fila e Free diário

**Data:** 22/10/2026. **Resultado:** integração implementada e validada localmente, incluindo PDF real no navegador. A geração fica **desabilitada por padrão em produção** até homologação do sandbox Linux, banco e worker. Esta entrega não autoriza publicação nem início da Fase 5.

Os relatórios de auditoria e das Fases 1, 2 e 3, os status Git e os diffs dos dois repositórios foram lidos antes das alterações. Foram preservados os trabalhos locais anteriores, inclusive monetização e autenticação. Nenhuma migration operacional, implantação, alteração de VPS/Supervisor/worker, operação Stripe, commit ou push foi executada.

## 1. Arquitetura implementada

Fluxo: editor → sincronização do rascunho → confirmação explícita → validação comercial → snapshot criptografado e cópia do logo → reserva diária e publicação transacional na fila → job comercial → subprocesso Dompdf → publicação e consumo atômicos → download privado.

Principais arquivos backend:

- `QuotationGenerationService`: emissão, idempotência, reserva, tentativa, finalização, reconciliação e purge.
- `QuotationDraftDocument`: converte o schema editável da Fase 3 para o contrato estrito da Fase 1.
- `QuotationExecutionRunner` e `scripts/render-quotation.php`: execução isolada, limites e verificação do resultado.
- `GenerateQuotationPdfJob`: recebe somente o UUID técnico da emissão.
- `QuotationGenerationController`, `QuotationGeneration`, `config/quotations.php` e `ReconcileQuotationGenerations`.

O Processing existente representa uma unidade comercial e um PDF; `tool=quotation-generator`, `entitlement_source=quotation_free`, `files_count=1` e `usage_files_count=0`. O JSON criptografado é o input técnico. O logo é um asset separado, sem cobrança adicional. Não há XML, DOCX, ZIP ou dispatcher fiscal nesse fluxo.

Criação genérica, upload, start, checkout fiscal e processamento fiscal rejeitam explicitamente a ferramenta comercial. Os seis valores e limites fiscais anteriores permanecem preservados. O novo enum foi excluído do endpoint público de catálogo. A rota continua com `noindex,follow`, fora do catálogo público e do sitemap.

O histórico técnico autenticado reconhece a ferramenta e oferece download de PDF com nome genérico, sem passar pelo preview fiscal.

## 2. Migration e persistência

Migration aditiva: `feitoo-api/database/migrations/2026_10_22_000002_create_quotation_generation_tables.php`. Nenhuma migration antiga foi alterada.

Foram revisados os modelos, migrations, FKs, índices, discos e infraestrutura de fila existentes. O conjunto completo das migrations foi aplicado somente a bancos descartáveis SQLite e MariaDB/InnoDB. O schema instalado no MySQL operacional não foi consultado nem modificado; a conferência desse ambiente integra a homologação.

| Tabela                          | Finalidade e constraints                                                                                                                                                                                                                                                                             |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `quotation_daily_quotas`        | Conta, data comercial, reservadas e consumidas; unicidade `(user_id, reservation_date)` e FK de usuário.                                                                                                                                                                                             |
| `quotation_generations`         | UUID público único, Processing único, conta, UUID/revisão do rascunho, bucket e data da reserva, estado de consumo, referências privadas e checksums, versão do renderer, tentativa monotônica, token vigente, lease, prazo pendente, erro seguro e purge. Unicidade `(draft_uuid, draft_revision)`. |
| `quotation_generation_requests` | Aliases de idempotência; unicidade `(user_id, idempotency_key)`, hash da operação técnica e FK da emissão. Permite reconhecer retries e chaves diferentes para a mesma revisão sem duplicar a emissão.                                                                                               |

Não há FK para o rascunho: sua exclusão não apaga a emissão. A FK de Processing impede exclusão física que perderia o vínculo. O purge conserva os registros técnicos e o ledger diário, removendo os arquivos e as referências de snapshot/logo. Não foram criadas tabelas antecipadas de créditos, pedidos ou produtos Stripe.

## 3. Modelo da quota

Regra: **uma conclusão bem-sucedida por conta e dia em `America/Sao_Paulo`**, contando reservas ativas. Todas as contas, inclusive Pro, recebem explicitamente essa franquia comercial Free nesta fase. O editor informa que a cota comercial Pro ainda não existe e que nenhum crédito das outras ferramentas será utilizado. Não se promete emissão ilimitada nem se modifica a assinatura.

Na reserva, a data de São Paulo é gravada na emissão e no bucket. Finalização e retry consultam esse mesmo bucket, inclusive após a meia-noite. A conclusão tardia não reclassifica o documento. `resets_at` aponta para a próxima meia-noite de São Paulo.

Transições:

| Evento                                                   | Reserva           | Consumo          |
| -------------------------------------------------------- | ----------------- | ---------------- |
| Editar/pré-visualizar                                    | Sem alteração     | Sem alteração    |
| Aceitar nova emissão                                     | +1                | 0                |
| Retry técnico automático                                 | Mantida           | 0                |
| Conclusão publicada                                      | −1                | +1               |
| Falha terminal/prazo pendente/exclusão antes de concluir | −1, uma única vez | 0                |
| Repetir emissão/download                                 | Sem nova reserva  | Sem novo consumo |
| Excluir/purgar PDF concluído                             | 0                 | Mantido          |

Escritas comerciais serializam pelo usuário, com ordem de locks **User → Processing → Generation → Quota**. A criação também bloqueia o rascunho antes do novo Processing. Bucket diário usa `insertOrIgnore`, índice único e `lockForUpdate`; a soma reservada + consumida não pode ultrapassar um pelo serviço. Existe ainda uma execução ativa por conta e um slot de renderização por host. Os locks financeiros não permanecem abertos durante a renderização.

Os registros mínimos de consumo não expiram junto com os arquivos. Exclusão da própria conta é um ciclo de vida distinto, sujeito às FKs; não foi criado um fluxo novo de exclusão de conta nesta fase.

## 4. Contratos de API

Todas as respostas comerciais e de Processing, inclusive erros, usam `Cache-Control: no-store, private`. Sanctum e ownership são obrigatórios; UUID não confere acesso. Recursos de terceiros retornam 404.

| Método / rota                                    | Contrato                                                                                                                                                                                                                                                 |
| ------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /api/quotations/entitlements`               | `{data: {source, policy, daily_limit, reserved, consumed, remaining, resets_at, enabled, active_processing, latest_processing}}`. Política `one_per_day_all_accounts`.                                                                                   |
| `POST /api/quotations/drafts/{uuid}/generations` | Body estrito `{revision: inteiro}` e header obrigatório `Idempotency-Key: UUID`. Retorna 202 com Processing, inclusive replay sem nova emissão.                                                                                                          |
| `POST /api/quotations/generations/{uuid}/retry`  | Body estrito `{attempt: inteiro}` e nova chave UUID por solicitação de retry. Reabre somente falha técnica elegível e não expirada, preservando o período original.                                                                                      |
| `GET /api/processings/{uuid}`                    | Contrato comercial explícito: ID, ferramenta, estado, datas, revisão, tentativa, data/estado de reserva, erro seguro, possibilidade de retry e disponibilidade/tamanho do resultado. Não devolve snapshot, campos pessoais, paths ou token de tentativa. |
| `GET /api/processings/{uuid}/result`             | PDF privado do proprietário, nome `orcamento.pdf`; permite downloads repetidos. Expiração é verificada na requisição.                                                                                                                                    |
| `DELETE /api/processings/{uuid}/files`           | Exclusão existente adaptada explicitamente: invalida a tentativa e remove somente os arquivos comerciais, preservando consumo concluído.                                                                                                                 |

As novas mutações estão no middleware web com sessão, CSRF, `auth:sanctum` e contexto de conta, inclusive sem Origin/Referer. O Angular usa o mecanismo existente de cookie CSRF e valida a conta esperada antes e depois dessa preparação. Download/status reutilizam os endpoints autenticados de Processing com autorização existente.

Limites: emissão/retry 10 por conta/minuto e 30 por IP/minuto; entitlements e rascunhos 120 por conta/minuto. Processing mantém seu throttle existente. 429 preserva `Retry-After`.

Erros próprios incluem `free_quota_exhausted` (422), `quotation_invalid` (422), `draft_revision_conflict` (409), `generation_in_progress` (409), `idempotency_conflict` (409), conflitos de tentativa, `draft_expired`/`generation_expired`/`result_expired` (410), indisponibilidade de execução/fila (503), além de 401/419/429. Não se aceitam dono, preço, paths, template HTML ou campos arbitrários no pedido de geração.

## 5. Fila e job

Conexão **`quotations`**, driver **database**, tabela existente `jobs`, fila **`quotations`**, mesma conexão padrão da transação, `after_commit=false` e `retry_after=180`. O serviço recusa configuração incompatível. Inserção do job, Processing, ledger, reserva e alias ocorrem dentro da mesma transação. Um worker só vê o job após o commit; falha de publicação reverte a reserva e compensa arquivos ainda não comprometidos. Referência: [fila database e transações no Laravel 12](https://laravel.com/docs/12.x/queues#jobs-and-database-transactions).

O job contém apenas `generationUuid`. Não transporta nomes, documentos, valores, logo ou snapshot. Primeiro obtém um `flock` exclusivo do renderer; se ocupado, retorna à fila sem incrementar a tentativa comercial. Depois reclama a emissão em transação curta, incrementa `attempt` e recebe um novo UUID de tentativa e lease de 90 segundos.

Renderização ocorre fora da transação. A publicação usa arquivo exclusivo por finalização e, sob locks, verifica token, status, reserva, lease e prazo pendente. Somente a tentativa vigente publica um output e converte reserva em consumo. Finalizador duplicado ou worker antigo não remove nem substitui o PDF publicado.

Falhas técnicas podem repetir até três tentativas persistidas dentro do prazo de 30 minutos. Falha terminal libera a reserva. Retry manual elegível tem controle de tentativa e chave idempotente; reutiliza o snapshot e o bucket originais. O número da tentativa nunca volta a zero. Depois do limite automático, um retry manual é uma nova tentativa explícita, sem reiniciar o contador automático.

`tries=0` da fila não significa execução financeira ilimitada: tentativas efetivas, lease e prazo persistentes limitam o trabalho. Interrupção abrupta é tratada pelo reconciliador. O timeout do job é 60 s, menor que a lease de 90 s e que `retry_after=180`.

## 6. Idempotência e conflitos

A chave UUID por revisão fica no IndexedDB, junto do contexto técnico do rascunho, antes do POST. Refresh ou resposta perdida reutilizam essa chave. Nova revisão sincronizada recebe nova chave. Durante a preparação, cliques repetidos são barrados também pelo coordenador, além do botão.

No servidor, replay da mesma operação devolve o Processing existente; reutilizar a chave para outra operação retorna conflito. Uma segunda chave para o mesmo UUID/revisão também aponta para a emissão existente, sem novo job ou reserva. A consulta de replay ocorre antes da leitura do rascunho, permitindo reconhecer a emissão mesmo depois de excluí-lo.

Revisão incorreta do rascunho impede snapshot e reserva. Tentativas repetidas de consumir, liberar, reconciliar ou finalizar não alteram duas vezes o ledger. O token impede publicação por workers antigos após retry, purge ou abandono.

## 7. Snapshots e logotipo

A revisão salva é bloqueada durante a leitura. `QuotationDraftDocument` transforma quantidades e descontos no DTO estrito e verifica inclusive a consistência dos textos de máscara monetária com seus centavos. `Quotation::fromArray` valida o documento e recalcula todos os totais com a aritmética da Fase 1. Totais enviados pelo navegador não são aceitos.

O snapshot contém schema, documento canônico e totais recalculados. É criptografado com Laravel Crypt/APP_KEY em `snapshot.enc`, com checksum do ciphertext, no disco privado `quotation_generations`. Não existe API de alteração de snapshot. O runner verifica o checksum; o subprocesso descriptografa a cópia de execução com sua chave efêmera e recalcula novamente, comparando com os totais congelados.

O PNG normalizado é copiado para o diretório exclusivo da emissão enquanto a revisão do rascunho está bloqueada. O checksum é verificado ao copiar e ao preparar a execução. O subprocesso reaplica as regras da Fase 1: PNG/JPEG válidos, 2 MiB, quatro milhões de pixels, reencode PNG sem metadados. Alterar ou excluir o rascunho/logo depois da emissão não muda a cópia. Logos e PDFs são privados, sem URL pública, mas não recebem criptografia de arquivo nesta fase; o snapshot recebe criptografia da aplicação.

Armazenamento separado:

- `quotation-generations/processings/{uuid}/input/snapshot.enc`;
- `quotation-generations/processings/{uuid}/assets/logo.png`;
- `quotation-generations/processings/{uuid}/attempts/{token}/{uuid-aleatorio}.pdf`;
- `quotation-execution/{uuid}/{token}/`: workspace efêmero independente.

Todos sob `storage/app/private`, com discos não servidos e falhas de escrita propagadas. Nenhuma limpeza comercial acessa o disco de XML/DOCX.

## 8. Segurança operacional

O motor Blade + Dompdf conserva templates fixos, escape de texto, ausência de JavaScript/PHP remoto, acesso remoto desabilitado, chroot restrito, limites de caracteres, itens, páginas e bytes. O subprocesso usa argumentos de array, sem interpolação em shell. Referência: [Symfony Process](https://symfony.com/doc/current/components/process.html).

Limites atuais: PHP 192 MiB e 30 s, timeout externo de 35 s, até 20 páginas/10 MiB de PDF; no Linux, `prlimit` acrescenta 320 MiB de espaço de endereçamento, CPU de 32 s e arquivo de 12 MiB. O resultado precisa passar por assinatura/trailer PDF, tamanho, quantidade de páginas e checksum antes da publicação. O processo pai controla tempo mesmo quando o timeout PHP CLI não bastar.

Produção exige **Linux + bubblewrap + prlimit**: namespaces isolados, sem rede, sem capabilities, código necessário somente leitura e apenas workspace gravável. `.env`, `bootstrap/cache` de produção, storage de outras ferramentas e diretórios pessoais não são montados. O processo pai descriptografa o snapshot e recriptografa sua cópia de execução com uma chave aleatória exclusiva. O subprocesso não herda credenciais DB/OAuth/Stripe nem a chave principal da aplicação; recebe somente essa chave efêmera, configuração mínima e caches próprios. Funções de shell/processos e inclusão remota são desabilitadas. Logs e stdout/stderr do renderer não são publicados.

O modo `php` de desenvolvimento funciona apenas em `local`/`testing`; produção o recusa. Ausência dos executáveis exigidos causa falha fechada antes da reserva. A existência do binário não comprova suporte a namespaces: sua execução deve ser homologada no Linux de destino. Falha real do sandbox segue o tratamento técnico seguro e não produz consumo.

Erros/logs guardam códigos, tipos de exceção e identificadores técnicos. Não há novos eventos GA4, metadata pessoal de Processing, nomes em filenames, URLs pessoais ou integração Stripe. A APP_KEY deve permanecer disponível enquanto houver snapshots/rascunhos vigentes; troca/rotação precisa considerar a recuperação desses dados.

## 9. UX de geração e download

O editor e a prévia continuam editáveis sem login. O botão orienta autenticação mantendo o rascunho; login, cadastro e OAuth retornam ao editor, **sem geração automática**. A emissão exige novo clique manual. Falhas de autenticação não descartam o preenchimento.

`QuotationGenerationApi` trata contratos comerciais e download Blob. `QuotationGenerationCoordinator` coordena sincronização, envio, restauração da emissão, polling, retry, quota e download. Durante a captura/envio, o formulário fica temporariamente inerte; durante o restante do processamento é possível editar o rascunho, com aviso de que isso não muda a emissão atual. O logo restaurado recupera suas dimensões reais ao carregar a imagem.

Estados visíveis: preparação, fila/reserva, geração, conclusão, falha técnica, sessão expirada, rate limit e arquivo expirado. A seção é pequena, com botões nativos, labels, `aria-describedby`, status ao vivo e erros com `role=alert`. Os testes verificam ativação por teclado e layouts de 320 a 1440 px.

Polling começa em 2 s, cresce até 5 s e se limita a 40 requisições ou 180 s. Abas ocultas pausam requisições, preservando o limite temporal. 429 respeita Retry-After limitado a 3–60 s. Ao pausar ou perder rede, a geração continua no servidor e há consulta manual sem emitir novamente. Troca de conta invalida respostas e downloads pendentes. Entitlements permite recuperar emissão pendente/última emissão após refresh, sem repetir POST.

PDF disponível por **duas horas a partir da conclusão**, com data/hora de São Paulo apresentada. Download usa nome genérico e object URL temporária. 410 remove a ação de baixar. Se a revisão já foi emitida e seu resultado expirou, é necessário editar o rascunho para solicitar outra emissão quando houver franquia.

Mensagem de limite: **“Você já utilizou seu orçamento gratuito de hoje.”**, acompanhada da próxima meia-noite. Não há oferta de checkout/créditos inexistentes. A política Free também é explicitada para assinantes Pro.

## 10. Expiração, reconciliação e purge

O prazo pendente é de **30 minutos desde a reserva**, independente das duas horas de download após sucesso. O scheduler existente recebe `feitoo:reconcile-quotation-generations` a cada minuto, com `withoutOverlapping`.

O reconciliador invalida tentativas abandonadas, reenfileira uma substituição transacional quando elegível ou libera a reserva após limite/prazo. Dois reconciliadores concorrentes não criam duas substituições. Workspaces órfãos têm carência de cinco minutos; inputs sem emissão comprometida têm carência de uma hora; outputs de finalizadores inválidos são removidos quando antigos e não referenciados. Só examina diretórios UUID nos discos comerciais dedicados.

O purge horário existente chama o ramo comercial de `ProcessingService`: apaga snapshot, logo e resultados, marca arquivos removidos e mantém os registros mínimos de consumo. O bloqueio HTTP acontece exatamente no prazo, sem esperar o scheduler. A remoção física pode ocorrer até aproximadamente uma hora depois, conforme o scheduler; temporários interrompidos dependem do reconciliador. Backups têm política de retenção própria e precisam respeitar os dados temporários.

## 11. Testes e regressões executados

Dados inteiramente fictícios; bancos de teste isolados. Comandos executados a partir dos respectivos projetos:

| Verificação                                           | Resultado                                                                                                                                                                                                                                                                                                                            |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `php -d extension=soap artisan test`                  | **414 passaram, 1 ignorado, 3.920 assertions**. O ignorado é a integração opcional anterior com LibreOffice real.                                                                                                                                                                                                                    |
| `QuotationGenerationTest` incluído nessa suíte        | **18 testes**: job/PDF real, logo, criptografia, imutabilidade, aliases/replay, reserva, São Paulo/meia-noite, revisão/validação, rollback de fila, falha/retry, tentativa antiga/duplicada, subprocesso com timeout real, abandono, purge/órfãos, auth/ownership/CSRF/throttle e conta Pro sem consumo fiscal.                      |
| `npm.cmd test -- --watch=false`                       | **320 testes passaram em 27 arquivos**, incluindo 20 testes novos de API/coordenador de geração e dois de preparação idempotente no coordenador de rascunhos.                                                                                                                                                                        |
| Build de produção                                     | Passou; 15 rotas prerenderizadas. Chunk lazy do editor aproximadamente 80,55 kB/20,17 kB transferidos. Somente os avisos anteriores de budget em app/home/tool-experience.                                                                                                                                                           |
| Testes públicos/SEO                                   | 15 passaram na verificação combinada: 13 testes públicos anteriores e dois específicos de orçamento. Catálogo continua com seis ferramentas, rota sem indexação/sitemap. Os dois testes específicos foram repetidos após o build final.                                                                                              |
| `node scripts/check-quotation-generation-browser.mjs` | Angular + Laravel/Sanctum/CSRF reais, SQLite em arquivo isolado, fila database e subprocesso real. Login, IndexedDB, logo, clique duplicado, refresh em fila, snapshot imutável, PDF com imagem, download idêntico duas vezes, Free esgotado, 419 sem CSRF, 410 expirado e quatro larguras. Sem chamadas Stripe/upload/start fiscal. |
| `node scripts/check-quotation-drafts-browser.mjs`     | 24 verificações anteriores: email/cadastro, Google simulado/falha/cancelamento, refresh, logo, duas abas/conflito, rede, sessão, conta, expiração e IndexedDB indisponível. Sem erros de runtime.                                                                                                                                    |
| `node scripts/check-quotation-browser.mjs`            | 17 verificações do editor e responsividade, com botão habilitado após hidratação.                                                                                                                                                                                                                                                    |
| `node scripts/check-tools-experience-browser.mjs`     | Seis ferramentas existentes, 96 layouts e oito downloads; upload, remoção, fila, processamento, sucesso parcial, falha, retry e expiração. Sem erros de runtime.                                                                                                                                                                     |
| `node scripts/check-monetization-browser.mjs`         | Dois fluxos XML, 12 layouts; lote completo, seleção restaurada no Google, retorno cancelado, tamanhos de compra e início único após confirmação simulada. Sem acesso Stripe Live.                                                                                                                                                    |
| Pint / Prettier                                       | Aplicados aos arquivos envolvidos.                                                                                                                                                                                                                                                                                                   |

Os testes unitários do coordenador também exercitam timeout do polling, aba oculta, 429, rede, 401/419, troca de conta, revisão, replay, retry e download. A autenticação Google externa com credenciais reais não foi acionada; os contratos de callback/retorno foram exercitados pelos testes existentes e simuladores. A acessibilidade validada cobre semântica, labels, feedback e teclado; não houve auditoria completa com leitor de tela.

Após reforçar a chave efêmera e excluir o cache de produção do sandbox, os **18 testes de geração passaram novamente, com 226 assertions**, incluindo prova de que a chave principal não descriptografa a cópia de execução. O browser E2E real também passou novamente com ativação por teclado. Houve uma execução intermediária com timeout de inicialização CDP e outra em que o evento de Enter do teste estava incompleto; o harness recebeu o evento de texto correspondente e a verificação passou sem alteração no comportamento do botão.

**Concorrência real:** `php -d extension=soap scripts/check-quotation-concurrency.php` passou em **MariaDB 10.4.32/InnoDB**, driver PDO mysql, com cinco pares de processos/conexões independentes sincronizados por barreira:

1. Dois rascunhos disputando a última unidade: somente uma reserva/job.
2. Mesma chave concorrente: uma emissão.
3. Chaves diferentes para a mesma revisão: uma emissão/job.
4. Entrega duplicada: somente um claim vigente.
5. Reconciliação concorrente: somente uma substituição; worker antigo recusado, finalização atual aceita e repetição incapaz de apagar o PDF publicado.

Também verificou retenção do consumo após purge. Servidor privado em loopback porta 33077, datadir `feitoo-api/storage/app/private/quotation-validation/mysql-phase4-20261022`; banco aleatório `feitoo_quotation_test_*` criado e removido pelo script. O script verifica o datadir e o nome antes de aceitar qualquer operação. O daemon descartável foi encerrado após o teste; nenhum serviço MySQL existente foi alterado.

Esse resultado **não é comprovação em MySQL 8**. SQLite também não comprova locking MySQL. O MariaDB local exigiu `--explicit-defaults-for-timestamp=1` para aplicar o conjunto de migrations, devido ao TIMESTAMP obrigatório da Fase 3; a migration antiga foi preservada. O script recusa servidor sem essa configuração. MySQL 8 com seu schema/SQL mode reais permanece pendente.

Evidências privadas, ignoradas pelo Git: `feitoo-web/dist/validation/quotation-generation/report.json` e `real-quotation.pdf`, relatórios dos outros browser checks e `concurrency-report.json` no datadir descartável. Os traces do novo browser check guardam apenas método/path/status e presença de CSRF/idempotência/no-store; não gravam cookies, tokens ou corpos.

## 12. Pendências de infraestrutura e limitações

Antes de qualquer habilitação:

- Homologar Linux com bubblewrap, user namespaces, prlimit, PHP CLI/GD/pcntl, fontes e permissões mínimas. Testar negação de rede/leitura de outros arquivos, timeout/memória, interrupção abrupta e limpeza. Windows validou subprocesso/timeout/PDF reais, mas não os namespaces Linux.
- Repetir concorrência e migrations em **MySQL 8 descartável**, com conexões independentes, InnoDB, SQL mode, timezone e schema equivalentes ao destino. Não apontar scripts de teste ao banco operacional.
- Provisionar futuramente worker dedicado e scheduler; nenhum deles foi alterado nesta entrega. A fila database precisa compartilhar a conexão da aplicação e a tabela `jobs`, com tabelas transacionais.
- Garantir quota/monitoramento de disco, permissões privadas, backups e retenção apropriada. Filesystem e MySQL não formam uma transação única: compensação e limpeza de órfãos cobrem interrupções entre escrita e commit.
- Homologar capacidade com carga e conteúdo máximo antes de ampliar concorrência. O slot atual é por host/volume; múltiplos hosts exigem coordenação distribuída ou limite global equivalente antes de escalar.
- A retenção física depende de scheduler saudável. Alertas operacionais devem usar contagens/IDs/códigos, sem conteúdo do orçamento. A flag não detecta disponibilidade real do worker; fila parada termina por deadline/reconciliador.

Configuração em `config/quotations.php`; variáveis novas: `QUOTATION_GENERATION_ENABLED`, `QUOTATION_PHP_BINARY`, `QUOTATION_SANDBOX`, `QUOTATION_BWRAP_BINARY` e `QUOTATION_PRLIMIT_BINARY`. Em produção, a flag default é false e o modo permitido é bubblewrap. Não usar o modo de desenvolvimento como fallback.

Exemplo de worker **para futura homologação, não executado em produção**:

```sh
php artisan queue:work quotations --queue=quotations --sleep=2 --timeout=60 --memory=128 --tries=0
```

Começar com um processo de baixo privilégio, volume privado compartilhado com API/purge e APP_KEY correspondente. A memória do worker é diferente do limite do subprocesso. Manter `job timeout < lease < retry_after` e tolerância de encerramento suficiente. O caminho PHP CLI pode ser configurado explicitamente quando diferente do PHP-FPM. Não alterar o dispatcher nem os workers fiscais para acomodar orçamento.

## 13. Preparação para créditos avulsos na Fase 5

A origem comercial explícita, geração vinculada ao Processing, ledger separado, snapshot imutável, reserva/finalização e aliases idempotentes dão pontos de extensão para outra fonte de direito. A Fase 5 deverá definir autorização e contabilização próprias, mantendo reserva/consumo/liberação e isolamento fiscal. Não existem créditos fictícios, compras antecipadas, novos preços, checkouts, alterações de assinatura ou promessas de Pro ilimitado nesta entrega.

## 14. Homologação e rollback

Procedimento futuro, sujeito à aprovação:

1. Criar ambiente isolado Linux/MySQL 8 e armazenamento privado, com flag de geração desligada. Revisar schema instalado, configurações e backup; aplicar migrations apenas nesse ambiente descartável.
2. Executar suítes, browser E2E e concorrência, validar quota perto da meia-noite, sandbox/recursos, kill/retry, fila indisponível, troca de conta, download/expiração, purge e preservação do ledger. O script de concorrência atual aceita somente seu datadir privado; adaptação para outro ambiente deve manter essas travas.
3. Homologar um worker dedicado e scheduler, confirmar inexistência de acesso remoto pelo renderer e permissões dos diretórios. Conferir que a rota não entrou no catálogo/sitemap.
4. Somente depois de aprovação de publicação, planejar migration operacional e habilitação explícita com `QUOTATION_GENERATION_ENABLED=true`, sem operações Stripe. Essa etapa não foi executada.

Rollback funcional: desabilitar novas emissões/retries pela flag e atualizar o cache de configuração pelo procedimento aprovado do ambiente. Manter status/download de resultados existentes, drenagem controlada dos jobs já reservados e reconciliador/purge para não deixar reservas presas. A flag não cancela trabalhos já aceitos. Preservar o ledger e os vínculos de emissão; não apagar consumo nem executar `migrate:rollback` em banco com emissões. Uma reversão de código precisa manter compatibilidade com o enum `quotation-generator` e com a limpeza/contabilização até encerrar os registros pendentes. Alterações de worker/deploy só ocorrerão em uma publicação futura autorizada.

**Entrega local concluída; aguardando aprovação para a próxima fase.**
