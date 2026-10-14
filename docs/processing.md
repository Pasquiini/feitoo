# Etapa 02 — processamento e arquivos privados

> Registro histórico da Etapa 02. A autenticação anônima e os exemplos com
> anonymous token foram substituídos por sessões Sanctum e propriedade do usuário.
> Para o contrato e os comandos atuais, consulte [authentication.md](authentication.md).

Esta etapa registra execuções e armazena XMLs plausíveis. Não interpreta dados
fiscais, não gera Excel, não dispara jobs e não cria interface final de upload.

## Auditoria e banco

Laravel 12.69.3 / PHP 8.2.12, Angular standalone 21.2.25 / TypeScript 5.9.3.
Sanctum, migrations de users/cache/jobs/tokens, health e CORS já existiam.
Queues continuam no padrão database, sem alterações ou workers desta etapa.
Logs existentes usam stack/single; erros das novas rotas são tratados com
mensagens genéricas e logs de UUID/tipo de exceção, sem conteúdo, headers,
tokens, nomes originais, SQL bindings ou paths completos.

O banco configurado no `.env` não existia. Foi criado somente o schema vazio
`feitoo` e executado `php artisan migrate`, sem modificar credenciais ou dados
anteriores. O servidor local é MariaDB 10.4.32, usando a conexão Laravel `mysql`.
As migrations também são testadas em SQLite em memória. Nenhuma migration antiga
foi editada. Para um checkout novo, configure um banco MySQL vazio existente
antes de executar `php artisan migrate`; o comando não cria o schema MySQL.

## Arquitetura

- `Processing`: ID interno bigint; UUID público v4 aleatório; ferramenta/status
  tipados por enums; counters de uploads; datas; metadata interna; hash de token.
- `ProcessingFile`: pertence ao processamento; UUID v4; kind input/output;
  nome original apenas como metadado; disk/path internos; tamanho; SHA-256; TTL.
- `user_id` é nullable com FK para users e relação belongsTo, sem autenticação
  nova. A API desta etapa cria exclusivamente processamentos anônimos.
- `removed_at` registra remoção física sem apagar registros. Na remoção, o nome
  original é descartado. Counters preservam a quantidade e bytes históricos de
  uploads aceitos, não a quantidade atual de arquivos físicos.
- `StoreProcessingRequest` e `UploadProcessingFilesRequest` validam entrada.
  `ProcessingResource` retorna apenas uma allowlist pública.
- `RequireAnonymousProcessingToken` valida a capacidade antes de validar XML.
  `ProcessingController` coordena requests/resource; `ProcessingService` contém
  operações de storage e transações. `SafeXml` faz apenas validação estrutural.
- Upload termina em `pending`; não existe etapa fiscal nem fila disparada.

## Contrato HTTP

| Método | Rota | Credencial | Resultado |
| --- | --- | --- | --- |
| POST | `/api/processings` | Não | HTTP 201, `data` e `anonymous_token` |
| GET | `/api/processings/{uuid}` | Bearer | HTTP 200, `data` seguro |
| POST | `/api/processings/{uuid}/files` | Bearer | Multipart `files[]`; HTTP 200, `data` atualizado |
| DELETE | `/api/processings/{uuid}/files` | Bearer | HTTP 200, `data.status=expired` |

Criação aceita somente `{"tool":"xml-to-excel"}`. O token tem 32 bytes
criptograficamente aleatórios, codificados como 64 caracteres hexadecimais.
Somente SHA-256 é salvo; um token aleatório com 256 bits não precisa de hashing
lento de senha. Comparação usa `hash_equals`. O token é retornado uma única vez,
no topo da resposta de criação, e nunca em consultas. Não há recuperação de
token. UUID sozinho, token em query string e token de outro processamento não
autorizam. Respostas usam `Cache-Control: no-store, private`.

Falta/erro de token retorna 401, UUID desconhecido/interno 404, estado incompatível
409, upload após expiração 410, corpo acima do limite PHP 413, validação 422,
rate limit 429. Erros inesperados retornam 500 genérico, mesmo com APP_DEBUG local.
Consulta e exclusão continuam disponíveis com token correto após expiração.
Exclusão é idempotente, encerra o processamento e impede novos uploads.
Nenhum endpoint retorna storage paths nem fornece download nesta etapa.

## Storage, integridade e falhas

Disk `processing`, raiz `storage/app/private/processing`, `serve=false`,
`visibility=private`, `throw=true`. Chaves físicas:

```text
processings/{processing_uuid}/input/{file_uuid}.xml
```

O nome original nunca compõe a chave. `output` está representado no enum para
uso futuro, sem endpoint de saída. Sem symlink ou diretório público.
No Windows, bits POSIX de chmod não são confiáveis: a privacidade web vem da
raiz fora de public e da ausência de rotas de entrega. Os testes verificam também
que as operações enviam explicitamente a opção de visibilidade privada.

As operações usam `Storage` e o disk de `config/feitoo.php`; cada arquivo mantém
o disk em que foi salvo. Para S3/R2, configure futuramente um disk privado e seu
adapter/credenciais no servidor. Não altere regras de negócio nem use URL pública.
Antes de trocar o disk, preserve acesso ao antigo e limpe possíveis uploads
interrompidos no prefixo antigo; não abandone arquivos entre migrações de storage.

Upload e remoção adquirem `lockForUpdate` sobre o mesmo Processing dentro de
transação. Falhas de upload revertem registros/counters/status e tentam remover
cada chave escrita. Banco e storage não possuem transação distribuída: se essa
compensação falhar ou o processo for interrompido, o purge remove todo o prefixo
gerado, inclusive órfãos, ao expirar o processamento. Falha na remoção não marca
o processamento como limpo; a próxima execução pode tentar novamente.

## Validação de XML

Exige upload válido, extensão `.xml`, tamanho permitido e MIME detectado no
servidor (`application/xml`, `text/xml` ou `text/plain`, comum em XML sem prólogo).
O MIME do navegador não determina aceitação. Todos os arquivos passam pela
validação antes de qualquer escrita: um arquivo inválido rejeita o lote inteiro.

`XMLReader` verifica well-formed XML sem ler campos fiscais. Usa `LIBXML_NONET`,
loader de entidades externas bloqueado, DTD/validação/substituição de entidades
desabilitados, sem `LIBXML_NOENT`, `LIBXML_DTDLOAD`, `LIBXML_PARSEHUGE` ou XInclude.
DTD e declarações de entidades são rejeitadas, incluindo codificações com bytes
nulos. Há limite de profundidade (64), nós (100.000), tamanho e verificação de
tempo (2 segundos entre passos do reader). Isso é validação limitada, não um
sandbox de CPU com interrupção de uma chamada nativa em andamento. Erros libxml
não são retornados nem registrados. Estado do loader/libxml é restaurado.

## Limites, expiração e limpeza

`config/feitoo.php` e `.env.example` centralizam:

| Configuração | Padrão |
| --- | --- |
| FEITOO_PROCESSING_DISK | processing |
| FEITOO_ANONYMOUS_TTL_MINUTES | 120 |
| FEITOO_MAX_FILES | 20 por request e por processamento |
| FEITOO_MAX_FILE_SIZE_KB | 10240 (10 MiB por arquivo) |
| FEITOO_CREATE_PER_MINUTE | 10 por IP |
| FEITOO_UPLOAD_PER_MINUTE | 10 por IP |
| FEITOO_ACCESS_PER_MINUTE | 60 por IP para rotas protegidas |

TTL nasce na criação e não é renovado por uploads. Arquivos herdam o prazo do
Processing. Purge horário remove arquivos e mantém registros/status `expired`,
sem apagar linhas. Continua após falha de um item, informa UUID seguro e sai com
código de falha quando houver itens pendentes. Scheduler usa `withoutOverlapping`.
Bloqueio lógico do upload ocorre imediatamente no prazo; a remoção física ocorre
na próxima execução do scheduler (normalmente até uma hora depois).

O PHP local tem `upload_max_filesize=40M`, `post_max_size=40M`,
`max_file_uploads=20`, libxml 2.10.3 e extensões XMLReader/fileinfo/mbstring.
Logo 20 arquivos de 10 MiB não cabem em uma única request local; envie lotes
menores ou configure PHP/proxy para um corpo superior a 200 MiB mais overhead.
PHP descarta excesso de arquivos acima de `max_file_uploads`; configure esse
valor maior que o limite da aplicação para que ela rejeite explicitamente lotes
maiores, em vez de haver truncamento anterior à validação. Nenhum php.ini foi alterado.
Em produção, use HTTPS e não configure access logs/proxy/APM para capturar
Authorization, corpo de upload ou resposta de criação. Rate limiting usa o
cache Laravel existente; configure trusted proxies corretamente se houver proxy.

Localmente, em um terminal do backend:

```powershell
php artisan schedule:work
```

Em produção, configure o cron/Agendador de Tarefas para chamar
`php artisan schedule:run` a cada minuto. Para executar a limpeza diretamente:

```powershell
php artisan feitoo:purge-expired-processings
php artisan schedule:list
```

## Angular

Models e service ficam em `src/app/features/processing/`. Métodos `create`,
`upload`, `get`, `deleteFiles` e `forget` usam a URL central dos environments.
O token fica em Map privada na memória do service; não há localStorage,
sessionStorage, cookies, URL ou interceptor que o envie a outros endpoints.
Recarregar a página perde o acesso do cliente, e o TTL/purge continua válido.
Essa escolha serve à fase de teste sem implementar persistência ou autenticação.
Não foi adicionada interface de upload nem dependência nova.

## Testar manualmente em PowerShell

Inicie a API:

```powershell
cd feitoo-api
php artisan serve
```

Se o processo PHP não puder escrever no `upload_tmp_dir` configurado da máquina
(ocorreu no sandbox desta validação), use temporariamente o diretório permitido
ao usuário. O comando abaixo também impede que avisos de startup contaminem JSON,
sem alterar o php.ini:

```powershell
cd feitoo-api/public
php -d "upload_tmp_dir=$env:TEMP" -d display_errors=0 -d display_startup_errors=0 `
  -S localhost:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

Em outro terminal, o script abaixo guarda o token apenas em variáveis e usa um
XML sintético no diretório temporário. Não imprime o token. `curl.exe` recebe
Authorization por stdin para que ele não conste nos argumentos do processo.

```powershell
$feitooApi = 'http://localhost:8000/api'
$created = Invoke-RestMethod -Method Post -Uri "$feitooApi/processings" `
  -ContentType 'application/json' -Body '{"tool":"xml-to-excel"}'
$processingUuid = $created.data.id
$processingToken = $created.anonymous_token
$processingHeaders = @{ Authorization = "Bearer $processingToken" }
$created.data

$sampleXmlPath = Join-Path $env:TEMP 'feitoo-sample.xml'
[System.IO.File]::WriteAllText($sampleXmlPath, '<document><item>teste</item></document>')
'header = "Authorization: Bearer ' + $processingToken + '"' |
  curl.exe --config - --silent --show-error --fail-with-body `
    -H 'Accept: application/json' -F "files[]=@$sampleXmlPath;type=application/xml" `
    "$feitooApi/processings/$processingUuid/files"

Invoke-RestMethod -Method Get -Uri "$feitooApi/processings/$processingUuid" `
  -Headers $processingHeaders

Invoke-RestMethod -Method Delete -Uri "$feitooApi/processings/$processingUuid/files" `
  -Headers $processingHeaders

Remove-Item -LiteralPath $sampleXmlPath
Remove-Variable created, processingToken, processingHeaders
```

Para múltiplos arquivos, adicione outras opções `-F "files[]=@..."`. No
PowerShell 7 também é possível upload com Invoke-RestMethod e `-Form`, mas a
sequência acima funciona no Windows PowerShell 5.1 existente.

## Verificação automatizada

```powershell
cd feitoo-api
php artisan migrate
php artisan test
php artisan route:list
php artisan schedule:list
vendor/bin/pint.bat --test
cd ../feitoo-web
npm.cmd run build
npm.cmd test -- --watch=false
npx.cmd prettier --check src/app/features/processing
```

Backend inclui regressões de UUID/token, contrato, acesso cruzado, XML válido,
extensão/tamanho/quantidade, conteúdo falso/malformado, XXE local/remoto/UTF-16,
entity bomb, profundidade/nós, armazenamento privado, checksum, TTL, purge,
arquivos ausentes/órfãos, retry por item, exclusão idempotente, rollback e logs
seguros, rate limiting. Frontend testa contratos HTTP, multipart, Bearer e falta
de credencial em memória. Testes backend usam fake storage e SQLite em memória,
sem modificar o banco local com fixtures.

Resultado final da validação: 32 testes backend (238 assertions) e 7 testes
frontend passaram; Pint, Prettier e verificações de whitespace passaram. Build
Angular concluído com o mesmo aviso de 524,53 kB da Etapa 01. Fluxo HTTP real com
multipart foi executado no banco local usando XML sintético; seus arquivos foram
removidos e os registros técnicos permanecem expirados.
