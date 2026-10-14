# XML para Excel — implementação e refinamento

A apresentação pública e a medição opcional da Etapa 08 estão documentadas em
[public-launch.md](public-launch.md). Essa etapa substitui as referências
históricas abaixo à ausência de analytics, sem alterar o motor de conversão.

## Etapa 07 — UX atual e validação

O fluxo existente foi refinado sem modificar parser, generator, Processing,
autenticação, storage ou queue. Nenhuma dependência foi adicionada nesta etapa.
As seções da Etapa 06 abaixo explicam a arquitetura; os resultados atuais estão
nesta seção, que substitui os resultados históricos ao final do documento.

### Auditoria e UX

Foram corrigidos: falta de limites/privacidade/validade na tela, seletor com
label sem semântica de botão, lista sem paginação, falta de limpar seleção,
ausência de detalhes das falhas parciais e exclusão manual, mensagens de fases
pouco claras, consulta HTTP sem timeout e CSS de ícones global não utilizado.

Título “XML para Excel” e descrição “Transforme seus XMLs de NF-e em uma
planilha organizada.” ficam antes da área de upload. O shell possui menos
espaçamento vertical. O seletor aparece acima da dobra nos tamanhos verificados.

Seleção aceita visualmente .xml e permite remover/limpar antes do envio, com
quantidade, tamanho em KB/MB formatado pt-BR e no máximo 10 linhas renderizadas
por página. Nomes longos quebram linha. Os limites vêm de
GET `/api/tools/xml-to-excel/config`: apenas max_files, max_file_size_kb e
ttl_minutes, públicos e limitados a 60 consultas/minuto. Não são dados de conta
ou caminhos de storage. O backend continua validando cada upload.

A ferramenta permanece visível a visitantes. Após seleção, “Entre para continuar”
oferece Google, e-mail e cadastro. Arquivos permanecem somente em memória durante
navegação interna. O retorno aguarda carregar os limites antes de retomar envio.
Google/reload perde os arquivos e essa limitação é informada. Ao confirmar início,
a UI libera as referências File; o backend já recebeu os arquivos privados.

Mensagens: Preparando seus arquivos, Preparando o processamento, Processando
XMLs e Gerando seu Excel. Counters reais diferenciam parsing e geração. Polling
mantém intervalo de 2s, limite de 300 tentativas e parada terminal/destruição;
cada consulta HTTP possui timeout de 15s. Não há porcentagem simulada.

Conclusão sem falhas mostra somente sucesso e download. Falha parcial mantém o
Excel disponível e permite consultar nomes e mensagens seguras, paginados em
grupos de 10. Zero válidos mostra “Não encontramos NF-e válidas nesses arquivos.”
e permite selecionar outros arquivos. A consulta de detalhes usa a API existente.

Resultado permanece na página após download. A UI mostra expires_at em horário
local e desabilita download ao atingir esse prazo; o backend é a autoridade.
Exclusão manual usa a API existente, removendo inputs/output/resumos. A UI mostra
confirmação e impede novos downloads após exclusão. Falha na exclusão mantém
o resultado disponível. “Selecionar outros arquivos” apenas reinicia a tela;
os arquivos do lote anterior continuam sujeitos ao TTL, salvo exclusão explícita.

### Privacidade e retenção

Texto curto: “Seus arquivos são privados”, usados somente para o processamento
solicitado e excluídos automaticamente após o prazo informado. Não se afirmam
certificações ou criptografia ponta a ponta. TTL padrão é 120 minutos a partir
da criação do lote, imediatamente antes do upload. Acesso é negado ao expirar;
a remoção física automática ocorre no purge agendado de hora em hora e pode
acontecer depois do vencimento, ou ser atrasada se o scheduler estiver parado.
A exclusão manual elimina também o Excel.

### Responsividade e acessibilidade

Verificação real no Edge headless com build de produção e API sintética local:

| Tela | Viewport | Rolagem horizontal | Seletor acima da dobra | Foco visível |
| --- | --- | --- | --- | --- |
| Desktop | 1440 × 900 | Não | Sim | Sim |
| Tablet | 768 × 1024 | Não | Sim | Sim |
| Mobile | 390 × 844 | Não | Sim | Sim |

Mobile oferece seletor nativo, sem depender de drag/drop. Controles de login
ocupam largura disponível em telas estreitas; navegação e ações podem quebrar
linha. Botão real aciona input de arquivos, labels e nomes acessíveis identificam
ações, outline visível acompanha foco por teclado. Mensagens de andamento usam
role=status e erros role=alert. Falhas detalhadas são texto Angular escapado,
sem HTML interpretado. Estilo mantém contraste do Bootstrap e tons sóbrios;
não houve auditoria WCAG completa ou teste com leitor de tela/dispositivo físico.

O smoke no navegador confirmou seleção → login por e-mail → retorno com seleção
→ processamento → resultado parcial → detalhes → exclusão, sem exceções JS.
Essa execução usa API simulada; os testes backend verificam segurança real.

Reprodução após build (Edge instalado no caminho usado pelo script):

```powershell
cd feitoo-web
npm.cmd run build
node scripts/check-tool-browser.mjs
```

Screenshots/report ficam em `feitoo-web/dist/validation/`, ignorado pelo Git.
O script usa somente arquivos/credenciais sintéticos e servidor em loopback.

### Bundle

Rota da ferramenta já era lazy e continua lazy. Não havia uso de Bootstrap Icons
em nenhum template. Foi removido somente seu CSS da lista global de estilos;
Bootstrap e a estrutura existente permanecem. A dependência instalada não foi
removida nesta etapa. Budget continuou 500 kB de aviso/1 MB de erro.

| Build de produção | Bundle inicial | CSS global | Resultado |
| --- | --- | --- | --- |
| Antes | 572,02 kB | 315,62 kB | Aviso de budget |
| Depois | 491,84 kB | 230,85 kB | Sem aviso |

Redução inicial de 80,18 kB (~14%). A página da ferramenta, com mais controles,
fica em chunk lazy de aproximadamente 20 kB. Não houve aumento de budgets.

### Benchmark sintético

Comando disponível somente em local/testing. Usa uma fixture sintética confiável,
replica itens em memória programaticamente e varia identificação da nota. Não
lê arquivos de usuários nem grava conteúdo fiscal em logs. Totais são fictícios;
não representa validação fiscal/SEFAZ. Nenhuma centena de XMLs foi adicionada.

Cada execução mede primeira passagem de parsing, reparsing durante exportação,
tempo de geração com reparsing, tempo XLSX descontando reparsing, pico de memória
PHP alocada e tamanho do XLSX. Pico não é RSS do processo: pode omitir alocações
nativas externas ao allocator PHP. Tempo estimado das duas passagens não inclui
HTTP, banco, fila, Storage nem locks. O tempo XLSX descontado ainda inclui a
montagem leve das variações de fixture e overhead de iteração.

Ambiente: Windows, PHP 8.2.12 x64, CLI memory_limit=512M, PhpSpreadsheet 5.10.0.
Medições aproximadas locais, sem rigor estatístico, em novos processos por cenário.
Os cenários maiores foram repetidos em sequência para reduzir disputa de CPU.

| XMLs | Itens por XML | Itens totais | Parsing inicial | Reparsing | XLSX sem reparsing | Duas passagens | Pico PHP | XLSX |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 10 | 20 | 200 | 0,185s | 0,186s | 0,142s | 0,513s | 56 MB | 26.106 bytes |
| 50 | 20 | 1.000 | 0,868s | 0,879s | 0,480s | 2,226s | 70 MB | 92.452 bytes |
| 200 | 20 | 4.000 | 3,445s | 3,538s | 2,137s | 9,121s | 130 MB | 335.930 bytes |
| 500 | 20 | 10.000 | 8,595s | 8,692s | 6,415s | 23,702s | 246 MB | 847.841 bytes |
| 20 | 200 | 4.000 | 3,246s | 3,253s | 2,021s | 8,521s | 130 MB | 329.704 bytes |

O cenário de 990 itens densos por XML foi rejeitado: 162.649 nós XML por arquivo,
acima do limite atual de 100.000, embora o XML sintético tenha ~2,12 MB. Isso
confirma que tamanho/quantidade não são os únicos limites. Nenhuma proteção
SecureXml foi relaxada para fazer benchmark passar.

```powershell
cd feitoo-api
foreach ($count in @(10, 50, 200, 500)) {
    php artisan feitoo:benchmark-xml-to-excel --documents=$count --items=20
}
php artisan feitoo:benchmark-xml-to-excel --documents=20 --items=200
# Guarda apenas um XLSX sintético para abrir em Excel:
php artisan feitoo:benchmark-xml-to-excel --documents=10 --items=20 --keep
```

`--keep` grava em benchmarks/ no disk privado e imprime o identificador do artefato
sintético. Esses arquivos não são Processings e não possuem purge automático:
remova o artefato de teste após usá-lo. Sem --keep, temporários são apagados.
Falhas PHP fatais de memória não são recuperáveis pelo comando; preserve margem
e use cenários controlados. Não execute cargas arbitrárias no servidor de produção.

### PhpSpreadsheet e limites técnicos

Biblioteca mantida: para as cargas medidas, o crescimento de memória acompanha
principalmente a quantidade de células, não apenas número de XMLs. 10.000 itens
consumiram 246 MB de pico PHP neste ambiente. Não é promessa de capacidade para
outras distribuições de itens, quantidade de strings distintas ou usuários concorrentes.

O envelope moderado de **20 XMLs × 200 itens** foi medido a 130 MB/8,5s e tem
margem neste ambiente de 512 MB/timeout 60s. Não garante o pior caso permitido
por tamanho/nós. Mantidos: 20 arquivos por lote, 10.240 KiB por arquivo, profundidade
64, 100.000 nós, 2s de validação streaming e timeout/lock/retry_after de 60/75/90s.
Sem aumento arbitrário de limite, plano ou regra comercial.

Config PHP local observada: max_file_uploads=20, upload_max_filesize=40M,
post_max_size=40M e memory_limit=512M. O POST total fica limitado a 40 MiB incluindo
overhead multipart; 20 arquivos de 10 MiB não cabem numa só request. A UI informa
limites da aplicação; erro HTTP 413 orienta reduzir o lote. Servidor web/proxy/FPM
pode impor limites diferentes. Confirme esses valores no ambiente HTTP real.

**Limite técnico** é esse conjunto de restrições de aplicação, PHP, XML, timeout
e memória. **Limite comercial futuro** ainda não existe e não foi definido.

Para expansão, alternativas documentadas são cache de células PSR-16 com retenção
privada/limpeza adequada ([PhpSpreadsheet](https://phpspreadsheet.readthedocs.io/en/latest/topics/memory_saving/))
ou avaliar writer streaming como [OpenSpout](https://github.com/openspout/openspout).
Não foram instaladas. OpenSpout 5.x requer PHP mais recente que o PHP 8.2 atual,
conforme seu [composer.json](https://github.com/openspout/openspout/blob/5.x/composer.json);
uma migração precisaria validar compatibilidade, tipos/datas/precisão e segurança.

### Excel real e limitações

Microsoft Excel **16.0** abriu normalmente, somente leitura, o XLSX sintético
de 10 notas/200 itens. Confirmou duas abas, chave/CNPJ como texto, data numérica
e ausência de fórmulas. Não foi acionado modo de reparação. O script desabilita
links externos/macros e fecha somente a instância que criou.

```powershell
cd feitoo-api
# Use o caminho do artefato sintético obtido com --keep, no disk local padrão:
.\scripts\check-excel.ps1 -WorkbookPath '.\storage\app\private\processing\benchmarks\synthetic-UUID.xlsx'
```

LibreOffice não foi encontrado nos caminhos consultados e não foi instalado.
Compatibilidade nesse aplicativo permanece não verificada. Excel foi validado
com amostra sintética, não com todos os workbooks possíveis. PhpSpreadsheet e
os testes de leitura XLSX continuam sendo a validação estrutural automatizada.

Reload perde seleção e acompanhamento atual, e OAuth externo exige reseleção.
Não há histórico de lotes nesta etapa. Não foi criado novo produto ou analytics.

### Recomendação para produção

Preservar limites atuais; repetir carga representativa no servidor real, incluindo
fila/banco/storage e concorrência. Dimensionar memória por worker com margem,
supervisionar worker/scheduler e verificar purge/TTL. Validar mobile físico e
leitor de tela, além de LibreOffice, antes de ampliar capacidade. Não extrapolar
os 500 XMLs sintéticos medidos para um limite liberado ao usuário.

### Validação final da Etapa 07 — 2026-10-08

- `php artisan test`: **171 testes passaram, 1252 assertions**;
- `vendor\bin\pint --test`: passou;
- `npm.cmd test -- --watch=false`: **40 testes passaram**;
- `npm.cmd run build`: passou, sem aviso de budget;
- Prettier check e `git diff --check`: passaram;
- Edge real: desktop/tablet/mobile e fluxo com API sintética passaram;
- Excel 16.0 real: abertura normal e tipos/abas/linhas passaram;
- benchmarks sintéticos de 10/50/200/500 XMLs executados; limite denso rejeitado
  pelas proteções XML, sem relaxamento de validação.

Arquivos novos: BenchmarkXmlToExcel.php, XmlToExcelRefinementTest.php,
scripts/check-excel.ps1, scripts/check-tool-browser.mjs e xml-to-excel.scss.
Modificados: rotas API (config pública), página/template/testes da ferramenta,
contratos/serviço/testes de Processing Angular, app.html, angular.json e este documento.
Nenhuma migration ou biblioteca nova; os componentes do motor permaneceram intactos.

## Auditoria e decisão

O workspace real tinha ProcessFiscalFilesJob com UUID, fila database, serviço
fiscal transacional, sete estados de Processing, cinco estados de arquivo,
resumos fiscais sem itens, storage privado e purge do diretório inteiro do lote.
Não havia biblioteca XLSX. Os DTOs já forneciam todos os campos básicos pedidos.
Os tributos de itens são árvores tipadas, sem projeção tabular por variante.
O Angular tinha autenticação e serviço de Processing, mas nenhuma página da ferramenta.

Foi instalado **PhpSpreadsheet 5.10.0**, diretamente, compatível com PHP 8.2
do projeto; Laravel Excel não foi necessário. Composer adicionou também
composer/pcre, maennchen/zipstream-php, markbaker/complex e markbaker/matrix.
Não há migration nova: ProcessingFile já oferece kind, nome, disk, path,
checksum, size, estado e TTL suficientes para output.

## Pipeline e responsabilidades

```text
upload privado → start → queued → ProcessFiscalFilesJob → parsing por arquivo
→ reabrir inputs completed → NfeParser → XmlToExcelGenerator → persistir output
→ completed → GET autenticado /result → download
```

O job continua curto. FiscalProcessingService orquestra o parsing e chama
XmlToExcelResultService na finalização. Este serviço reabre os inputs válidos
em páginas de 100 registros, um XML/DTO por vez, usando PrivateProcessingFileReader
(limite, tamanho e SHA-256), e entrega um iterable ao generator. NfeParser não mudou.

XmlToExcelGenerator recebe apenas dados normalizados e um destino temporário;
não acessa banco, controller ou storage da aplicação. O arquivo temporário é
apagado em finally e o workbook libera suas worksheets. Não duplicamos itens
no banco nem XML em JSON. Parsing e exportação acontecem no worker, nunca na request.

O mesmo job foi mantido para evitar um novo despacho entre duas fases. A geração
usa o lock transacional do Processing, coordenando retries, duplicação, exclusão
e purge. Esse lock permanece durante a geração; lotes maiores aumentam sua duração.
Inputs terminados não são parseados novamente pela primeira fase em retries;
a exportação relê os inputs completed. Completed só é gravado depois do output.

Erros parciais mantêm os contadores e geram XLSX com os válidos. Sem documentos
válidos, termina failed com “Não houve documentos válidos para exportação.”,
sem workbook vazio. Falhas globais de geração são sanitizadas pelo job e usam
os retries existentes; após esgotamento, failed(). Não há retry manual sofisticado.

## Workbook

Duas abas: **Notas** e **Itens**. Cabeçalhos sóbrios em negrito, autofilter,
primeira linha congelada, larguras fixas e formatos aplicados por intervalo.
Não há fórmulas, hyperlinks ativos, totais calculados, autosize ou dashboard.

### Notas — uma linha por NF-e válida

Chave de acesso; Número; Série; Data de emissão (UTC); Natureza da operação;
CNPJ/CPF emitente; Razão social emitente; Nome fantasia emitente; IE emitente;
Município emitente; UF emitente; CNPJ/CPF destinatário;
Nome/Razão social destinatário; IE destinatário; Município destinatário;
UF destinatário; Quantidade de itens; Valor dos produtos; Frete; Seguro;
Desconto; Outras despesas; IPI; ICMS; PIS; COFINS; Valor total da NF-e;
Número do protocolo; Status protocolo; Motivo protocolo; Offset original.

### Itens — uma linha por item

Chave da NF-e; Número da NF-e; Série; Data de emissão (UTC); CNPJ/CPF emitente;
Fornecedor; UF emitente; Número do item; Código do produto; Descrição do produto;
EAN/GTIN; NCM; CEST; CFOP; Unidade comercial; Quantidade; Valor unitário;
Valor total; EAN tributável; Unidade tributável; Quantidade tributável;
Valor unitário tributável; Número do pedido; Item do pedido; Offset original.

Campos ausentes ficam vazios; nunca viram zero inventado. Tributos por item
ficaram para evolução: é necessário mapear variantes de CST/CSOSN e bases
explicitamente, sem misturar ST, quantidade, alíquota e base monetária ou
despejar árvores JSON. Os totais oficiais da NF-e já são exportados em Notas.

## Precisão, datas e segurança

Identificadores e todo texto são escritos com TYPE_STRING, incluindo chave de
44 dígitos, CNPJ/CPF, série, códigos, GTIN, NCM, CFOP e pedido. Zeros à esquerda
permanecem. Textos iniciados por =, +, -, @ permanecem texto, sem formula injection.
O writer não pré-calcula fórmulas; o arquivo não contém células TYPE_FORMULA.

Decimais válidos com até 15 dígitos significativos são entregues como strings
à API TYPE_NUMERIC do PhpSpreadsheet, que realiza a conversão numérica. Não há
float indiscriminado na aplicação. O Excel usa ponto flutuante: decimais usuais
são reconhecidos numericamente, sem prometer aritmética decimal exata. Acima
de 15 dígitos significativos, preservamos o valor inteiro como TYPE_STRING,
evitando arredondamento silencioso. Formato `#,##0.00########` mostra valores
monetários e quantidades com casas adicionais. O XML privado é a fonte original.

Datas são seriais Excel reais. DhEmi é convertido explicitamente para UTC antes
de serializar e usa `dd/mm/yyyy hh:mm`; Offset original permite recuperar o
fuso da origem, pois Excel não armazena timezone na célula. Fontes somente com
dEmi mantêm a data sem conversão de fuso, exibindo 00:00 e offset vazio.

Referências da biblioteca: [tipagem explícita e datas](https://phpspreadsheet.readthedocs.io/en/latest/topics/recipes/)
e [acesso às células](https://phpspreadsheet.readthedocs.io/en/stable/topics/accessing-cells/).

## Output e download

ProcessingFile output usa UUID.xlsx físico em
`processings/{processing_uuid}/output/{file_uuid}.xlsx`, no disk privado
configurado. Nome amigável: `feitoo-xml-para-excel-YYYY-MM-DD.xlsx`.
Herda expires_at. Output não incrementa files_count/total_size de inputs.
O tamanho de saída aparece em result.size.

GET `/api/processings/{uuid}` inclui result.available, file_name, size e
error_message segura. Não expõe paths, checksum ou XML. GET files lista inputs.

GET `/api/processings/{uuid}/result` exige sessão Sanctum, propriedade,
completed, output não removido e TTL válido. Abre um stream via Storage sob lock
e entrega Content-Type XLSX, Content-Disposition amigável, no-store/private
e nosniff. Outro usuário recebe 404; expirado 410; ainda não concluído 409;
output ausente 404. Content-Disposition é exposto pelo CORS à origem autorizada.

O Angular recebe Blob com sessão, obtém o nome do backend e revoga a object URL.
Não conhece paths ou URLs públicas; futuramente o contrato pode fornecer uma
URL temporária de S3/R2. A disponibilidade da API reflete registro e TTL;
se houver perda externa do objeto, o download retorna erro seguro.

Purge/exclusão removem inputs, outputs e resumos fiscais. Reexecução de lote
completed não duplica output. Falhas após escrita e antes de confirmar metadados
têm compensação; interrupções abruptas/rollback tardio podem deixar um órfão
privado no prefixo, posteriormente removido pelo purge.

## Frontend

`/xml-para-excel`: drag/drop, seleção múltipla .xml, nomes, remoção antes do envio,
quantidade e tamanho. Backend continua sendo autoridade. Estados idle,
files-selected, uploading, queued, processing, completed e error. Progresso
mostra contadores reais, sem percentual fictício; conclusão mostra erros parciais.

Polling a cada 2s, no máximo 300 consultas (~10 minutos), sem requests sobrepostas,
com parada terminal/destruição. Após timeout existe Consultar novamente.
A seleção fica apenas em um serviço em memória enquanto navega para login/cadastro;
retorno permitido apenas para a rota fixa da ferramenta, sem redirect arbitrário.
Após autenticação, continua automaticamente. Redirect OAuth/reload perde seleção;
é necessário selecionar novamente. Não há arquivos em localStorage/IndexedDB.

Métodos start/update/download deixam os pontos de ação identificáveis para
analytics futuros, sem integrar GA4 nem emitir eventos nesta etapa.

## Validação manual no navegador

1. Inicie API, worker e Angular com os comandos abaixo.
2. Abra `http://localhost:4200/xml-para-excel`.
3. Selecione `feitoo-api/tests/Fixtures/Fiscal/nfe-proc.xml` e um XML genérico
   bem formado, como `<documento/>`, para testar falha parcial.
4. Clique Entrar e continuar; faça login/cadastro. A seleção deve continuar
   enquanto a navegação permanecer na mesma aplicação, sem redirect OAuth.
5. Aguarde fila/processamento e confira sucessos/falhas.
6. Baixe XLSX e abra no Excel/LibreOffice: Notas e Itens, chave/CNPJ como texto,
   valores numéricos, data UTC, offset e acentos.
7. Em outra conta, tentar o endpoint de resultado do primeiro lote deve dar 404.

A Etapa 06 verificou o XLSX com PhpSpreadsheet e a página em testes Angular.
A Etapa 07 acrescentou Edge real com API sintética e abertura normal no Excel;
veja as medições e limites dessa validação acima.

## Comandos PowerShell/API

```powershell
cd D:\backup\Projetos\feitoo\feitoo-api
php artisan migrate
php artisan serve
# Outro terminal na mesma pasta:
php artisan queue:work database --sleep=1 --tries=3 --timeout=60
# Scheduler em outro terminal:
php artisan schedule:work
```

```powershell
cd D:\backup\Projetos\feitoo\feitoo-web
npm.cmd start
```

Para sessão, CSRF, cadastro/login e upload multipart, use o exemplo completo de
[authentication.md](authentication.md), substituindo exemplo.xml pela fixture
sintética e **sem executar a exclusão/logout ao final antes do download**.
Depois do upload, mantendo `$base`, `$session`, `$origin`, `$id` e Get-FeitooHeaders:

```powershell
$state = (Invoke-RestMethod "$base/api/processings/$id/start" -Method Post -WebSession $session -Headers (Get-FeitooHeaders) -ContentType application/json -Body '{}').data
for ($attempt = 0; $attempt -lt 300; $attempt++) {
    if ($state.status -notin @('queued', 'processing')) { break }
    Start-Sleep -Seconds 2
    $state = (Invoke-RestMethod "$base/api/processings/$id" -WebSession $session -Headers $origin).data
}
$state
if ($state.result.available) {
    Invoke-WebRequest "$base/api/processings/$id/result" -WebSession $session -Headers $origin -OutFile '.\feitoo-sintetico.xlsx'
}
```

## Limites operacionais

Os limites de upload existentes permanecem (padrão 20 arquivos), configuráveis;
também valem limites multipart do PHP. Iteração evita cópias simultâneas de XMLs
e DTOs, mas PhpSpreadsheet mantém células do workbook em memória. Milhares de
notas com muitos itens exigem dimensionamento de memória/timeout; a Etapa 07
adicionou benchmark sintético, sem garantia para milhões de linhas. Itens respeita o
limite XLSX de 1.048.576 linhas. Larguras são fixas, sem autosize custoso.

O job mantém timeout 60s, lock 75s e retry_after 90s da etapa anterior; grandes
lotes exigirão dimensionar juntos esses valores e considerar writer streaming.
No Windows sem pcntl, timeout não é rígido. A janela entre commit e despacho
da etapa anterior permanece. Não foi introduzido Redis obrigatório ou outbox.

## Testes automatizados

Fixtures são sintéticas. Testes abrem o workbook, verificam abas, linhas,
identificadores, zeros, tipos, valores, data UTC, offset, acentos, filtros,
freeze, fallback de alta precisão e ausência de fórmulas. Cobrem lote misto,
output privado/TTL, idempotência, falha de geração, zero válidos, download,
isolamento, expiração/remoção e resposta sem path.
Frontend cobre seleção, rejeição visual, remoção, upload/start, polling/status,
conclusão parcial, falhas, memória durante login e download Blob.

Próxima evolução sugerida: validação com usuários de colunas/larguras e
dimensionamento de lotes, antes de expandir tributos por item.

## Resultado histórico da Etapa 06 — 2026-10-08

- `php artisan migrate`: nenhuma migration pendente;
- `php artisan test`: 166 testes passaram, 1237 assertions;
- `vendor\bin\pint --test`: passou;
- `php artisan route:list --path=api`: 15 rotas, incluindo GET result;
- `php artisan queue:work database --once`: código zero; o teste da fila real
  também consome um lote sintético e confirma sua conclusão com output;
- `npm.cmd test -- --watch=false`: 30 testes passaram;
- `npm.cmd run build`: passou; aviso de orçamento inicial de 572,02 kB
  para 500 kB (limite de erro existente: 1 MB);
- Pint, Prettier e `git diff --check`: executados com sucesso.

Os testes anteriores foram preservados, com atualização dos contratos que esta
etapa muda intencionalmente: result na resposta, segunda leitura do XML válido
para exportação e falha de resultado quando não há documentos válidos.
