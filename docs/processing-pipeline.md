# Etapa 05 — orquestração fiscal

> Registro histórico da Etapa 05. A [Etapa 06](xml-to-excel.md) evolui a conclusão:
> `xml-to-excel` só termina completed após persistir o XLSX privado. Zero inputs
> válidos agora termina failed, com mensagem segura e sem output. O contrato GET
> inclui result, há download autenticado e página Angular com polling limitado.

## Reauditoria do workspace — 2026-10-08

A inspeção do código real encontrou esta etapa já implementada: serviço fiscal,
job, enums, resultados-resumo, migrations de evolução, endpoints, contrato
Angular e 28 testes específicos. Não foi necessário criar ou modificar código
de aplicação ou migrations nesta execução; apenas registrar esta revalidação.
O workspace já continha alterações locais, que foram preservadas.

Validação executada novamente:

- `php artisan migrate`: nenhuma migration pendente;
- `php artisan test`: 156 testes passaram, com 1023 assertions;
- `vendor\bin\pint --test`: passou;
- `php artisan route:list --path=api`: 14 rotas;
- `php artisan queue:work --once`: terminou com código zero, sem saída;
- `npm.cmd test -- --watch=false`: 21 testes passaram;
- `npm.cmd run build`: passou, com aviso de bundle inicial de 555,22 kB
  para orçamento de aviso de 500 kB.

O MariaDB local estava desligado na primeira tentativa de migration. Após sua
inicialização autorizada, migration e worker passaram. A suíte usa SQLite em
memória e inclui uma execução real do worker database com um lote sintético;
o comando isolado de worker acima não demonstra consumo de um lote adicional.

As limitações de despacho após commit, timeout no Windows e ausência de tela
técnica/polling descritas abaixo permanecem válidas.

## Estado auditado e escopo

Laravel 12.69.3 / PHP 8.2.12, Sanctum com sessão HttpOnly; Angular standalone
21.2.25 / TypeScript 5.9.3. Processing já possuía os sete estados, proprietário,
UUID, contagem/tamanho, datas e TTL. ProcessingFile não tinha estado de parsing.
Storage privado, SecureXml, parser NF-e e purge já existiam. Queue/cache tinham
drivers database e suas migrations originais; não havia job fiscal ou resultados.
Os 128 testes anteriores passaram antes das alterações.

Esta etapa integra **NF-e modelo 55** ao processamento. Não gera Excel ou
qualquer saída, não consulta SEFAZ e não implementa novos parsers. Upload continua
independente: apenas o proprietário pode solicitar início depois de enviar arquivos.

## Ciclo e endpoints

```text
pending → uploading → pending → queued → processing → completed
                                            └──────→ failed (falha global)
qualquer estado → expired (TTL ou exclusão manual)
```

O worker não trata erros de arquivo como falha global. Inclusive um lote em que
todos os XMLs sejam recusados termina completed, com successful_files_count=0.

| Método | Rota | Comportamento |
| --- | --- | --- |
| POST | `/api/processings` | Cria lote pending, vinculado à sessão |
| POST | `/api/processings/{uuid}/files` | Upload seguro; não despacha job |
| POST | `/api/processings/{uuid}/start` | Início explícito; responde 202/queued |
| GET | `/api/processings/{uuid}` | Status, contadores e datas |
| GET | `/api/processings/{uuid}/files` | Metadados seguros e erro por arquivo |
| DELETE | `/api/processings/{uuid}/files` | Remove arquivos e resumos fiscais; expired |

Todas essas rotas exigem `auth:sanctum`; acesso a lote de outro usuário responde
404. Início valida propriedade novamente sob lock, presença de input não removido,
TTL e estado pending. Início duplicado responde 409; lote vazio 422; expirado 410.
O endpoint reutiliza o limite nativo de criação, além do limite geral de acesso.

Exemplo de GET após lote misto:

```json
{
  "data": {
    "id": "uuid-publico",
    "tool": "xml-to-excel",
    "status": "completed",
    "files_count": 10,
    "processed_files_count": 10,
    "successful_files_count": 8,
    "failed_files_count": 2,
    "total_size": 123456,
    "started_at": "2026-10-07T20:00:00+00:00",
    "completed_at": "2026-10-07T20:00:10+00:00",
    "expires_at": "2026-10-07T22:00:00+00:00",
    "created_at": "2026-10-07T20:00:00+00:00"
  }
}
```

GET files retorna `data` como lista, com apenas UUID público, original_name,
status, size, processed_at, error_code e error_message. Não inclui ID interno,
storage_path, stored_name, checksum, resultado fiscal, chave ou documentos.
Respostas continuam `no-store, private`. Não há endpoint de resultados nesta etapa.

## Fila, job e concorrência

`ProcessFiscalFilesJob` implementa ShouldQueue e carrega apenas o UUID do lote,
além dos metadados nativos de agendamento. Nunca serializa models, XML ou DTOs.
`FiscalProcessingService` organiza início, leitura, parser, persistência e contadores.

Configuração central:

```dotenv
QUEUE_CONNECTION=database
FEITOO_PROCESSING_QUEUE_CONNECTION=database
CACHE_STORE=database
```

O job usa a conexão `feitoo.processing.queue_connection`, database por padrão.
Essa configuração é independente de `QUEUE_CONNECTION=sync` utilizado pelos
testes. O serviço recusa drivers sync, deferred, background, null ou failover:
não executa trabalho fiscal no processo HTTP. Troca futura para Redis é possível
pela configuração da conexão, sem mudar regras fiscais; Redis não foi instalado.

O status queued é confirmado em transação, e o despacho ocorre em afterCommit.
Assim, um worker não observa o lote antes do commit. Falha no despacho marca
failed e responde 503 com mensagem genérica. Há uma pequena janela entre commit
e envio à fila: uma interrupção abrupta nesse intervalo pode deixar queued sem
job; não foi introduzido outbox/reconciliador nesta etapa. O TTL/purge continua
válido nesse caso.

O middleware nativo WithoutOverlapping usa lock pelo UUID, lease de 75 segundos
e descarta entregas simultâneas enquanto o job original trabalha. Cache precisa
ser compartilhado entre workers; database local já atende. Há também locks no
banco sobre Processing e ProcessingFile, mantidos durante leitura/parser/commit
de cada arquivo. Exclusão e purge usam o mesmo lock do lote, impedindo que uma
remoção seja desfeita por persistência tardia do worker.

Timeout do job: 60 segundos; três tentativas, com backoff de 10/30 segundos.
Mantenha retry_after/visibility timeout maior que 75 segundos; database existente
usa 90. Windows sem pcntl não oferece o mesmo timeout rígido do worker Linux;
os limites SecureXml e locks transacionais continuam ativos. Produção precisa
supervisionar workers e dimensionar esses tempos segundo o storage/lote.

## Estado por arquivo e idempotência

`ProcessingFileStatus`: pending, processing, completed, failed, removed.
Adicionados processed_at, error_code e error_message. Registros já removidos são
migrados para removed; os demais começam pending.

Cada arquivo é uma transação: o worker bloqueia o lote/arquivo, confere estado e
TTL, lê conteúdo limitado via `Storage::disk(...)->readStream(...)`, fecha o stream,
confere tamanho/SHA-256, chama o parser e salva sucesso ou erro. O parser reutiliza
SecureXml; não há caminho absoluto montado manualmente nem rede XML habilitada.
Estado processing do arquivo ocorre dentro dessa transação e pode não ser visível
em polling externo antes do estado terminal, pois leitura e parse são curtos.

Arquivos completed/failed/removed não são refeitos em novas entregas. Existe uma
FK única para o resultado de cada arquivo, e a persistência usa updateOrCreate.
Falha de banco reverte arquivo, resultado e contadores daquela transação, mantendo
sucessos anteriores. Uma reexecução do job em processing retoma os arquivos ainda
não terminados; completed/failed/expired não são reabertos.

Não existe retry manual de arquivo nesta etapa. Os retries nativos tratam falhas
globais/transientes. Após esgotá-los, `failed()` marca o lote failed e completed_at,
preservando contadores já confirmados; arquivos não processados podem continuar pending.

## Contadores e falhas

- files_count / total_size conservam os totais de upload existentes.
- successful_files_count conta inputs completed.
- failed_files_count conta inputs failed.
- processed_files_count é a soma dos dois, atualizada em cada commit.
- started_at é definido uma vez; completed_at registra término do lote.

Os contadores são recalculados por estado, sem incremento duplicado em retries.
O lote termina completed apenas quando todos os inputs registrados estão terminados.
Na exclusão/purge os contadores históricos permanecem, sem preservar o resumo fiscal.

| Código | Situação |
| --- | --- |
| invalid_xml | XML inseguro, malformado ou campos inconsistentes |
| unsupported_document | Documento/modelo não suportado pelo parser |
| missing_required_field | NF-e estruturalmente incompleta |
| parse_error | Falha inesperada na interpretação daquele arquivo |
| storage_error | Leitura indisponível, limite excedido, tamanho/checksum divergente |

Mensagens são constantes do enum ProcessingFileError: não persistem XML, detalhes
do parser, paths ou stack traces. Logs contêm somente UUIDs e classe da exceção.
Falhas globais são encapsuladas em uma exceção genérica sem previous antes de
Laravel registrar failed_jobs; não carregam a mensagem sensível original.

## Resultados e retenção

`ProcessingFileResult` possui FK única processing_file_id e os campos:
document_type, model, access_key, number, series, issued_at, issued_at_has_time,
issuer_document/name, recipient_document/name, items_count e total_value.

Não há parsed_payload, XML duplicado, tabela de itens, cálculo ou JSON arbitrário.
Datas são strings ISO com offset ou YYYY-MM-DD, acompanhadas da precisão da fonte.
Valores totais são strings de até 128 caracteres, sem float/rounding de banco.
Nomes-resumo são limitados a 255 caracteres e documentos a 32; a fonte completa
permanece no XML privado. Para gerar Excel posteriormente, os itens podem ser
reextraídos pelo parser do XML ainda dentro do TTL.

Esses resumos contêm dados fiscais sensíveis e permanecem internos. DELETE e
purge removem os resultados, os arquivos e os nomes originais, mantendo apenas
registros técnicos e contadores. Jobs atrasados não reconstroem dados expirados.

## Angular e validação manual

ProcessingService oferece create, upload, start, get, listFiles e deleteFiles.
Models/interfaces incluem contadores, datas, estados de arquivo e códigos de erro,
mantendo tipagem forte. O interceptor de sessão/CSRF permanece o existente.
Não foi criada interface técnica nem polling: os testes e exemplos API validam
o fluxo sem introduzir uma tela temporária permanente.

### Executar em PowerShell

```powershell
cd feitoo-api
php artisan migrate
php artisan serve
```

Em outro terminal:

```powershell
cd feitoo-api
php artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=60
# Consumir no máximo um job:
php artisan queue:work --once --sleep=0
# Scheduler local, em outro terminal:
php artisan schedule:work
```

Não é necessária nova migration de jobs/cache; as originais já existiam.
Se DB_QUEUE usar nome diferente de default, ajuste --queue. Se a conexão fiscal
mudar, use o nome correspondente no worker. Após mudanças de código/configuração,
reinicie o worker ou execute `php artisan queue:restart`.

```powershell
cd feitoo-web
npm.cmd start
```

Para autenticar, criar lote e fazer upload, use os exemplos com sessão HttpOnly
e CSRF de [authentication.md](authentication.md). Depois, na mesma sessão:

```powershell
# $base, $session, $origin, $id e Get-FeitooHeaders vêm do exemplo de autenticação.
$started = Invoke-RestMethod "$base/api/processings/$id/start" -Method Post -WebSession $session -Headers (Get-FeitooHeaders) -ContentType application/json -Body '{}'
$started.data

# Consulta limitada a 60 tentativas, interrompida nos estados terminais.
$state = $started.data
for ($attempt = 0; $attempt -lt 60; $attempt++) {
    if ($state.status -notin @('queued','processing')) { break }
    Start-Sleep -Seconds 1
    $state = (Invoke-RestMethod "$base/api/processings/$id" -WebSession $session -Headers $origin).data
}
$state
Invoke-RestMethod "$base/api/processings/$id/files" -WebSession $session -Headers $origin
```

Use apenas fixtures sintéticas. Uma fixture NF-e e um XML genérico bem formado
permitem validar sucesso/falha parcial. XML malformado/XXE normalmente é rejeitado
já no upload; as proteções do worker cobrem também defesa em profundidade.

## Migrations e arquivos desta etapa

Novas migrations:

- `2026_10_07_230000_add_fiscal_processing_states.php`;
- `2026_10_07_230001_create_processing_file_results_table.php`.

Novos arquivos backend: ProcessFiscalFilesJob, FiscalProcessingService,
ProcessingFileStatus, ProcessingFileError, ProcessingFileResult,
ProcessingFileResource e `tests/Feature/FiscalProcessingTest.php`.

Modificados: Processing, ProcessingFile, ProcessingService, ProcessingController,
ProcessingResource, routes/api.php, bootstrap/app.php, config/feitoo.php,
.env.example e o contrato esperado em ProcessingTest. Angular: processing.ts,
processing.service.ts e processing.service.spec.ts. Nenhuma migration antiga,
parser fiscal, SafeXml, credencial privada ou configuração de scheduler foi alterada.

## Testes e resultados

Adicionados 28 testes backend cobrindo todos os pontos solicitados: início,
ownership, filas, vazio/duplicado/expirado, handler real, lotes mistos, resultados,
counters, storage/integridade, retries, rollback, lock, purge e sanitização.
Um teste usa a fila database real e `queue:work --once`, sem worker externo.

Validação final:

- `php artisan migrate`: migrations aplicadas no MariaDB local, nenhuma pendente;
- `php artisan test --compact`: **156 testes, 1023 assertions, todos passaram**;
- `vendor\bin\pint --test`: passou;
- `php artisan route:list --path=api`: 14 rotas, incluindo start e GET files;
- `php artisan queue:work --once --sleep=0`: executado com sucesso;
- `npm.cmd test -- --watch=false`: **21 testes passaram**;
- `npm.cmd run build`: passou, mantendo o aviso anterior de bundle 555,22 kB
  acima do orçamento de aviso de 500 kB;
- Prettier e `git diff --check`: passaram.

Próxima etapa sugerida: geração de Excel a partir dos documentos normalizados,
reutilizando o parser e o storage privado. Essa etapa não foi iniciada.
