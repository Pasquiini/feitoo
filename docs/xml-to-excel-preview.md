# XML para Excel — preview do resultado

O detalhe existente `GET /api/processings/{uuid}` inclui `data.preview`.
O campo é `null` até existir um Excel concluído e disponível, e também após
expiração, exclusão ou falha sem documentos válidos. Autenticação, autorização
por proprietário e `Cache-Control: no-store, private` seguem o contrato existente.

## Contrato

- `summary.successful_documents_count`: documentos válidos do lote.
- `summary.failed_documents_count`: arquivos com falha.
- `summary.items_count`: itens de todos os documentos válidos.
- `summary.total_value`: soma decimal exata dos valores totais informados,
  enviada como string. Não converte valores fiscais para ponto flutuante.
- `summary.documents_without_total_count`: documentos sem total informado,
  identificados na interface para não apresentar a soma como completa.
- `notes_preview`: até 5 notas em ordem de processamento; número, série,
  emissão, nomes de emitente/destinatário, quantidade de itens e total.
- `items_preview`: até 10 itens, com `note_number`, `series`, `issuer_name`,
  `note_reference` opaca e estável, código, produto,
  quantidade, valor unitário e total. Valores decimais seguem como strings.

Nenhum XML bruto, documento de pessoa, chave de acesso, path, checksum ou ID
interno é retornado no preview. Falhas continuam disponíveis pelo endpoint
existente de arquivos, com mensagens seguras. As duas tabelas são amostras,
sem paginação; o Excel continua sendo o resultado completo.

## Etapa 12B: agrupamento por NF-e

A origem já estava persistida no ProcessingFileResult, junto da amostra JSON de
itens. Não foi necessária migration de preview nem releitura/backfill de XML.
O endpoint acrescenta `note_reference` (HMAC do identificador persistido, sem
expor o ID ou a chave fiscal) e `issuer_name`. Mantém `note_number` e `series`.
Essa referência evita unir documentos de emitentes diferentes com número/série
iguais; o frontend conserva fallback para respostas anteriores sem referência.

A amostragem usa round robin entre até cinco documentos com amostra persistida,
um item de cada por rodada, até dez no total. Uma nota com dez itens não esconde
as demais. Os contadores globais continuam somando todas as notas e itens.
A UI agrupa por referência e mostra NF-e, série e emitente acima de cada tabela,
com quantidade de itens **na amostra**. Número/série permanecem também nas linhas.
O contador global diz “Mostrando X de Y itens. Esta é uma amostra.”

No mobile, o cabeçalho fica fora da área de scroll horizontal e permanece
visível; somente a tabela rola se exceder o espaço disponível. O script de
navegador valida duas notas sintéticas (100 e 101) em 320/375/390/768/1440 px.
O teste backend processa duas notas, verifica origem/numeração/série persistidas,
limite, compatibilidade sem amostra e proíbe leitura pelo leitor privado na consulta.
Dados antigos sem JSON de itens continuam orientando consulta no Excel.

## Persistência e implantação

A migration `2026_10_08_100000_add_items_preview_to_processing_file_results`
adiciona uma coluna JSON nullable ao resumo fiscal existente. Durante a extração
já executada são guardados até 10 itens por documento, com código e descrição
limitados a 255 caracteres. Não há releitura de XML durante consultas HTTP.
O serviço de preview percorre resumos em blocos de 100 e limita a resposta a
5 notas e 10 itens. Usa Brick Math, já disponível na árvore de dependências do
Laravel, para somar os valores com precisão.

Aplicar `php artisan migrate` antes de ativar o código atualizado e reiniciar
os processos de fila no procedimento habitual de implantação. Esta etapa
executou a migration na base local após confirmar o ambiente e as migrations
pendentes; os testes usam base isolada.
Resultados gerados antes da migration têm resumo/notas, mas não amostra de
itens; a interface orienta consultar esses itens no Excel. Não há backfill nem
reprocessamento automático.

O purge/exclusão existente remove a linha inteira do resumo e, portanto, também
o novo JSON. Parser, geração do Excel, autenticação, storage e fila preservam
seu comportamento fiscal e operacional.

## Interface e validação

Upload próximo ao título, etapas compactas, feedback de arraste/erro/seleção,
upload desabilitado durante envio, progresso sem percentual artificial para
transferência ou geração, resumo com quatro indicadores e download em destaque.
Tabelas semânticas têm legendas, cabeçalhos, regiões com foco e scroll horizontal
local. O resumo passa para duas colunas no mobile.

Validação: testes Angular, build, testes Laravel, Pint e smoke test Edge com
respostas sintéticas. O smoke test verifica upload acima da dobra e foco em
320, 375, 390, 768, 1024 e 1440 pixels; verifica preview sem overflow da página
em 320, 375, 390, 768 e 1440 pixels. Evidências são geradas em
`feitoo-web/dist/validation/` pelo script `scripts/check-tool-browser.mjs`.
