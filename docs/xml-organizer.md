# Organizador de XMLs

## Arquitetura e diagnóstico

Ferramenta `xml-organizer`, página pública `/organizar-xml`. Backend Laravel 12/PHP 8.2+ e frontend Angular 21 com prerender. A implementação estende a infraestrutura das três ferramentas existentes; não cria tabelas, assinaturas, filas ou mecanismos paralelos de cobrança.

Reutilizados:

- `ProcessingController`, `StoreProcessingRequest`, `UploadProcessingFilesRequest` e `ProcessingService`: criação, upload multipart em blocos, limites e armazenamento privado.
- `FiscalProcessingService` e `ProcessFiscalFilesJob`: start explícito, locks, transações por arquivo, progresso, três tentativas, timeout e proteção contra execução simultânea do mesmo lote. Fila configurada em `feitoo.processing.queue_connection`, normalmente `database`; execução pesada em `sync` continua bloqueada.
- Sanctum, login e-mail/Google e `RequireProcessingOwner`: autenticação e isolamento por proprietário em todos os endpoints privados.
- `EntitlementService`, `SubscriptionService`, `usage_files_count`, `usage_date`: limites Free/Pro e saldo diário separado por ferramenta.
- `PrivateProcessingFileReader`, `SecureXml`, `FiscalXmlFields`: leitura limitada com SHA-256, parser sem entidades externas e navegação por filhos diretos/namespace.
- Histórico de `Processing` e seus recursos: nome da ferramenta, contagens, estado, download e exclusão.
- `PurgeExpiredProcessings`, agendado a cada hora: exclusão dos originais, ZIP, intermediários e metadados fiscais ao expirar. Downloads são bloqueados imediatamente no vencimento.
- Página Angular compartilhada `XmlToExcelPage`: seleção, drag and drop, remoção, paginação, upload, autenticação, Free/Pro, progresso, feedback, exclusão e download. Dois componentes pequenos isolam os controles e o resultado do organizador.

O parser `NfeParser` continua exigindo modelo 55 para Excel/produtos/DANFE. `OrganizerXmlReader` é um leitor de metadados para modelos 55/65; não relaxa essas regras anteriores e não inventa campos obrigatórios para preencher o banco.

## Fluxo completo

1. Usuário escolhe os arquivos e as opções. Seleção e opções ficam em memória durante login por e-mail. Google provoca navegação externa; arquivos precisam ser selecionados novamente, conforme o comportamento existente.
2. Criação de `Processing` com opções validadas e imutáveis em `metadata.organizer_options`.
3. Upload pelo fluxo privado existente. São aceitos arquivos XML de texto em UTF-8, dentro dos limites. XML malformado pode ser preservado; arquivos binários, ZIPs, DTD/entidades e excessos de tamanho/profundidade/nós/tempo são rejeitados antes de armazenar.
4. Start valida o limite do plano e reserva o volume, sob os locks de lote/usuário. O job existente é despachado após commit.
5. Cada arquivo é lido com limite e checksum. O leitor identifica o envelope e modelo, extrai metadados disponíveis e registra avisos. Resultados limitados por arquivo são salvos em `metadata.organizer_files`, indexados pelo UUID do input, na mesma transação do status. Retries pulam arquivos já concluídos/falhos.
6. O organizador define caminhos únicos, detecta repetições/conflitos e prepara o resumo. Os XMLs não são reserializados nem modificados.
7. ZIP é gerado em diretório temporário privado. Um original por vez é copiado para staging, verificado novamente por SHA-256 e adicionado com `ZipArchive::addFile`. Não se acumulam todos os XMLs como strings em memória. Relatório Excel opcional usa PhpSpreadsheet e o trait existente `WritesFiscalWorkbook`.
8. O ZIP final é enviado por stream ao disco privado e registrado como output existente. O lote só fica concluído e disponível após sua persistência. Intermediários são removidos em `finally`, e também no purge em caso de interrupção abrupta.
9. Consumo é ajustado aos inputs fiscais organizados com sucesso. A página consulta os resultados pelo mesmo fluxo, exibe resumo, caminhos e observações e permite baixar `XMLs_Organizados.zip`.

## Endpoints

| Método e rota | Função |
| --- | --- |
| `GET /api/tools/xml-organizer/config` | Limites públicos existentes, sem pacotes de checkout antecipado |
| `GET /api/account/usage?tool=xml-organizer` | Saldo Free/Pro desta ferramenta, autenticado |
| `POST /api/processings` | Criar com `tool: "xml-organizer"` e objeto `organizer` opcional |
| `POST /api/processings/{uuid}/files` | Upload multipart `files[]`, autenticado e limitado |
| `POST /api/processings/{uuid}/start` | Reservar limite e enfileirar; aceita a seleção explícita dos primeiros N pelo fluxo Free existente |
| `GET /api/processings/{uuid}` | Progresso, opções e resumo quando disponível |
| `GET /api/processings/{uuid}/files` | Status individuais e campo `organization` com caminhos/observações |
| `GET /api/processings/{uuid}/result` | Download autorizado do ZIP |
| `DELETE /api/processings/{uuid}/files` | Excluir originais, output, staging e metadados fiscais |
| `GET /api/processings` | Histórico da conta, incluindo o organizador |

Todos os endpoints de lote reutilizam proprietário, TTL e rate limits existentes. Opções só são aceitas ao criar o lote; não há endpoint para alterá-las depois do upload/start.

Exemplo do corpo de criação:

```json
{
  "tool": "xml-organizer",
  "organizer": {
    "criterion": "year_month_supplier",
    "detect_duplicates": true,
    "duplicate_action": "separate",
    "report": true,
    "standardize_names": true
  }
}
```

Defaults: `criterion=year_month`, detecção/relatório/padronização habilitados e duplicados separados. Campos desconhecidos e valores inválidos são rejeitados. As opções não são inferidas do nome original.

## Critérios e nomes

| Opção | Exemplo de pasta |
| --- | --- |
| `year_month` | `2026/10/` |
| `supplier` | `Fornecedor_A_12345678000199/` |
| `issuer_document` | `12345678000199/` (ou CPF de 11 dígitos) |
| `year_month_supplier` | `2026/10/Fornecedor_A_12345678000199/` |
| `model` | `NFe/`, `NFCe/`, `Outros/` |

Emitente é a referência para fornecedor. O documento é acrescentado quando disponível para não misturar emitentes diferentes com nomes iguais. Datas usam o fuso do valor original, sem converter o mês para UTC. Ausências usam rótulos técnicos como `Sem_Data`, `Sem_Fornecedor` e `Sem_CNPJ_CPF`; esses rótulos não são dados fiscais extraídos.

Padronização usa `NFe_{chave}.xml` ou `NFCe_{chave}.xml` somente quando a chave passa por formato, modelo, DV, UF/mês e consistência com os campos disponíveis do XML. A chave não é procurada no nome original. Sem chave confiável, ou com a opção desligada, usa um nome seguro derivado do original.

`OrganizerNames` translitera acentos, restringe segmentos a letras ASCII, números, `_` e `-`, limita comprimentos e bloqueia nomes reservados do Windows. Não há caminhos absolutos, separadores vindos do usuário, pontos de traversal ou caracteres de controle. Colisões são verificadas sem distinguir maiúsculas/minúsculas e recebem `__2`, `__3` etc. Nenhum input é sobrescrito por outro.

## Metadados e limitações fiscais

Leitura inicial de `NFe` e `nfeProc` no namespace NF-e, modelos 55 e 65. Destinatário é opcional, especialmente em NFC-e. Extrai, quando presentes/confiáveis: chave, número, série, data, emitente, CNPJ/CPF de ambas as partes, modelo e total. Valores monetários, documentos e chaves são preservados como texto no relatório.

Dados opcionais ausentes ficam nulos com observações. Datas/campos numéricos inválidos ou estrutura ambígua são problemas, sem consumo. Documentos desconhecidos/incompatíveis são preservados sem informações fiscais fictícias: em `Outros/` ao organizar por modelo, e em `Arquivos_Com_Problemas/Outros/` nos demais critérios. XML malformado seguro vai para `Arquivos_Com_Problemas/`. Arquivo indisponível ou fora dos limites de segurança não é incluído; a exclusão é explicitada no relatório/interface.

O MVP não lê fiscalmente CF-e, CT-e, MDF-e ou eventos avulsos. Não valida integralmente por XSD, não verifica assinatura digital e não consulta a SEFAZ. Presença de protocolo não é usada para afirmar situação atual. Página, relatório e `LEIA-ME.txt` distinguem os dados extraídos da situação não verificada:

> Dados extraídos do XML. Status atual na SEFAZ não consultado; assinatura digital e autorização não verificadas.

UTF-16, arquivos compactados e uploads binários não são aceitos no organizador. A recuperação de malformados é intencionalmente limitada a texto UTF-8, sem ampliar essa permissão nas outras ferramentas.

## Duplicidade

- Hash SHA-256 igual: conteúdo idêntico.
- Chave confiável igual e hash diferente: conflito de chave. Todos os participantes conhecidos do conflito são marcados no relatório, inclusive o primeiro.
- `duplicate_action=keep`: cópias ficam em suas pastas de organização, com nomes únicos.
- `duplicate_action=separate`: repetições fiscais ficam em `Duplicados/Conteudo_Identico/...` ou `Duplicados/Mesma_Chave/...`. Arquivos inválidos continuam na pasta de problemas.
- Nada é descartado automaticamente. Se a detecção for desligada, colisões continuam sendo resolvidas e o resumo informa que duplicidade/conflitos não foram verificados.
- `duplicates_count` conta ocorrências após o primeiro arquivo do grupo; `conflicting_keys_count` conta chaves com conteúdo divergente. Não se afirma que duas notas com mesma chave têm bytes iguais.

## ZIP e relatório

O ZIP contém originais com os mesmos bytes, pastas escolhidas, pastas de duplicados/problemas quando aplicáveis, `LEIA-ME.txt` e, opcionalmente, `Relatorios/relatorio_organizacao.xlsx`.

Planilha `Organização`: nome original/final, pasta, chave, número/série, modelo, data, emitente, CNPJ/CPF de ambas as partes, valor extraído, status, duplicidade, conflito, observações e situação SEFAZ não verificada.

Planilha `Resumo`: enviados, organizados, repetições, inválidos/incompatíveis/falhas, fornecedores, originais preservados, chaves conflitantes e documentos por mês. O mesmo resumo aparece no frontend. Escrita explícita como texto bloqueia fórmulas de nomes/valores do XML, incluindo `=`, `+`, `-` e `@`.

Um lote com apenas XMLs problemáticos recuperáveis pode concluir como recuperação, com zero organizados e zero consumo; a página explica essa situação. Sem nenhum original recuperável, o lote falha e não disponibiliza resultado.

## Planos e idempotência

Unidade: **um input fiscal organizado com sucesso e incluído no ZIP**. Cópias mantidas/separadas contam porque são inputs devolvidos conforme a escolha explícita do usuário. Inputs inválidos, desconhecidos ou indisponíveis não contam.

O volume total é validado/reservado antes do start, considerando saldo diário e limite por processamento Free/Pro. Ao concluir o ZIP, o uso é reduzido ao total bem-sucedido. Tentativas do mesmo job preservam status/metadata por arquivo e não incrementam consumo. Novo start do mesmo lote é bloqueado; reenviar intencionalmente em novo lote é nova utilização.

Falha global mantém a reserva enquanto o job ainda pode tentar novamente e libera o consumo ao esgotar tentativas. Falha no despacho, exclusão ou expiração de lote ativo também libera. Após conclusão, exclusão não devolve uso já realizado. O histórico conserva os contadores técnicos após a exclusão.

Assim como no DANFE, o checkout avulso antecipado não é oferecido para o organizador: o checkout existente cobra pelo volume enviado antes da validação. Free/Pro existentes atendem ao requisito de consumo por sucesso sem criar cobrança nova. As ferramentas anteriores preservam suas regras.

## Segurança e capacidade

- Uploads mantêm limites existentes de requisição, arquivo, volume e quantidade (padrões: 20 arquivos por requisição, 250 por lote, 10 MiB por XML, 40 MiB por lote), além dos limites menores do plano.
- Sem extração de ZIP recebido: arquivos compactados são rejeitados. O volume descompactado do ZIP de saída é limitado pelo volume de entrada mais o relatório de até 250 linhas.
- `SecureXml` continua estrito por padrão; o modo explícito de recuperação tolera apenas erros de sintaxe, mantendo bloqueio de DTD/entidades e limites de profundidade/nós/tempo. É usado somente no arquivamento do organizador.
- Não se seguem URLs de schemas, logos, estilos ou entidades. Parsing usa `LIBXML_NONET` e carregador externo bloqueado.
- Nomes/path traversal e colisões são tratados antes de montar entradas. O texto original não é servido como HTML.
- Checksum é verificado na leitura e novamente antes de incluir no ZIP.
- Staging em `organizer-temporary/{uuid}` no disco local privado, output em `processings/{uuid}/output/organized.zip` no disco configurado. Locks impedem corrida com purge/exclusão.
- ZIP final é transmitido ao armazenamento privado por stream; não é lido inteiro em string. Relatório e staging são removidos em `finally`. Purge também remove intermediários órfãos.
- Respostas respeitam autenticação/proprietário/expiração, com `no-store, private` e `nosniff` no download. Metadados fiscais são removidos ao excluir/expirar; nenhum caminho interno de armazenamento é exposto.

## Testes e verificação

Resultado final: **263 testes backend (2.368 asserções), 173 testes Angular e 3 testes de arquivos públicos aprovados**. Build de produção aprovado com 12 rotas prerenderizadas. Smoke no Edge aprovado em desktop 1440 px e mobile 390 px, com opções, upload, resultado parcial e download ZIP; capturas inspecionadas visualmente. Pint e verificação de whitespace aprovados.

`tests/Feature/XmlOrganizerTest.php` cobre geração real e inspeção do ZIP/XLSX, todos os critérios, NF-e/NFC-e, destinatário ausente, campos ausentes, nomes repetidos, chave repetida com bytes diferentes, conteúdo idêntico, detecção desligada, relatório desligado, datas inválidas, acentos, nomes reservados, traversal, fórmulas no relatório, malformados, desconhecidos, XXE, binários, profundidade, tamanho/quantidade, limites Free/Pro, uso por sucesso, retry, falha na geração do relatório/ZIP, recuperação, histórico, download autorizado, isolamento, expiração e limpeza de staging.

`xml-organizer.spec.ts` cobre formulário/opções, seleção/drop/remoção, preservação em memória durante login, envio das opções, progresso, conclusão parcial, conflitos, caminhos, download ZIP, exclusão e limite de plano. Suítes anteriores também são executadas.

Comandos:

```text
feitoo-api: php -d extension=soap artisan test --compact
feitoo-api: php vendor/bin/pint --dirty
feitoo-web: npm test -- --watch=false
feitoo-web: npm run test:public
feitoo-web: FEITOO_BUILD_ENV=production npm run build
feitoo-web: node scripts/check-organizer-browser.mjs
```

No PowerShell, definir `$env:FEITOO_BUILD_ENV='production'` antes do build. O smoke do navegador usa Edge headless e apenas respostas de API sintéticas locais; a integridade do ZIP e o consumo são testados no backend real com fixtures sintéticas. Capturas em `feitoo-web/dist/validation/organizer-{desktop,mobile}{,-result}.png`. Testa desktop 1440 px e mobile 390 px sem overflow horizontal, opções, upload, resultado parcial e download.

SEO inclui title/description específicos, canonical, Open Graph, WebApplication, BreadcrumbList, FAQPage consistente com FAQs visíveis, prerender, sitemap, redirect de barra final e links na home/rodapé. O build público valida os dados estruturados e conteúdo indexável.

## Implantação

Nenhuma dependência nova ou migration necessária. Reutilizados PhpSpreadsheet e ZipArchive já existentes. Requisitos PHP/extensões permanecem os do projeto; SOAP exigido pela dependência do DANFE continua necessário no Composer/ambiente, conforme `docs/xml-to-pdf.md`.

Após deploy, reiniciar workers existentes; manter worker database e scheduler/purge em execução. Garantir espaço e permissão de escrita no disco local privado para staging e no disco de processamento. Preservar os limites técnicos até medir volumes maiores. Não há consulta externa fiscal ou credenciais novas para configurar.

O build mantém o aviso já existente de orçamento de CSS da página compartilhada (`5,56 kB` para o aviso de `4 kB`), sem impedir compilação ou prerender. Nenhuma publicação foi realizada. A configuração pública gerada localmente foi restaurada ao padrão de desenvolvimento após validar o build de produção.

## Arquivos desta implementação

Criados:

- `feitoo-api/app/Rules/SafeOrganizerXml.php`
- `feitoo-api/app/Services/OrganizerXmlReader.php`
- `feitoo-api/app/Services/OrganizerNames.php`
- `feitoo-api/app/Services/OrganizerReport.php`
- `feitoo-api/app/Services/XmlOrganizerService.php`
- `feitoo-api/tests/Feature/XmlOrganizerTest.php`
- `feitoo-web/src/app/features/processing/pages/organizer-options.ts`
- `feitoo-web/src/app/features/processing/pages/organizer-result.ts`
- `feitoo-web/src/app/features/processing/pages/xml-organizer.spec.ts`
- `feitoo-web/scripts/check-organizer-browser.mjs`
- `docs/xml-organizer.md`

Alterados no backend:

- `app/Enums/ProcessingTool.php`
- `app/Http/Requests/StoreProcessingRequest.php`, `UploadProcessingFilesRequest.php`
- `app/Http/Controllers/ProcessingController.php`, `GoogleAuthController.php`
- `app/Http/Resources/ProcessingResource.php`, `ProcessingFileResource.php`
- `app/Services/FiscalProcessingService.php`, `ProcessingService.php`, `PurchaseService.php`
- `app/Support/Xml/SecureXml.php`, `routes/api.php`

Alterados no frontend:

- `src/app/features/processing/tools.ts`, `models/processing.ts`
- `src/app/features/processing/services/processing.service.ts`, `xml-selection.service.ts`
- `src/app/features/processing/pages/xml-to-excel.ts`, `xml-to-excel.html`
- `src/app/features/auth/services/auth.service.ts`, `pages/auth-page.ts`
- `src/app/features/public/home-tools.ts`, `home.html`, `home.spec.ts`
- `src/app/app.routes.ts`, `app.routes.server.ts`, `app.ts`, `app.html`
- `src/app/core/public-pages.ts`, `seo.service.ts`, `tool-faqs.ts`, `analytics.service.ts`, `public-launch.spec.ts`
- `scripts/generate-public-assets.mjs`, `public-assets.test.mjs`, `vercel.json`

As alterações pré-existentes da implementação DANFE e outros arquivos do usuário foram preservados. Não houve publicação, alteração de infraestrutura nem instalação de pacotes nesta tarefa.
