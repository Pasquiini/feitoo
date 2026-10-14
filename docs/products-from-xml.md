# Produtos dos XMLs — etapa 14

## Auditoria

O parser existente já expõe código, descrição, NCM, CFOP, unidade, quantidade, unitário e total em DTOs. Processing possui tool como enum. Upload em lotes, fila, ownership, autenticação, TTL, exclusão, download, Free/Pro e compras são reutilizáveis. Não foi encontrado um UsageLimitService separado: o consumo e seus limites estão centralizados no EntitlementService.

A persistência anterior guarda resumo da NF-e e apenas uma amostra de itens. A exportação anterior relê XMLs no worker. A nova ferramenta precisava dos itens completos enquanto o lote existe, consolidação própria, duas abas próprias, resumo e preview específicos. Também havia destinos fixos de autenticação, compra, histórico e analytics que precisavam distinguir ferramentas.

## Arquitetura reutilizada

O mesmo Processing e os mesmos endpoints de criação, upload, start, consulta, arquivos, exclusão e result atendem às duas ferramentas. O mesmo ProcessFiscalFilesJob executa FiscalProcessingService. O writer de storage privado em XmlToExcelResultService despacha para o gerador adequado pelo enum; não há pipeline paralelo. WritesFiscalWorkbook concentra os helpers anteriores de tipagem, proteção contra fórmulas e formatação, usados pelos dois geradores.

## Nova ferramenta

Rota pública: /produtos-dos-xmls. Identificador canônico: products-from-xml / ProcessingTool::ProductsFromXml. Título: Produtos dos XMLs. Usuários podem selecionar arquivos e ver limites antes de entrar; processamento e download exigem autenticação.

O Angular reutiliza a mesma página e seus fluxos, parametrizados pela definição central processing/tools.ts. Upload, lista de arquivos, seleção parcial, paywall, polling, falhas e ações não foram duplicados. Seleções permanecem em memória separadamente por ferramenta. E-mail retorna ao contexto correto; Google exige nova seleção após o redirect externo, como antes.

## Regra de agrupamento

Chave: documento do emitente + código + NCM + unidade. Não há consolidação entre fornecedores diferentes. Código é texto: 001234 e 1234 permanecem distintos. Quando código está ausente/vazio, usa emitente + descrição normalizada + NCM + unidade, em namespace separado do código. Sem identidade segura, os itens ficam separados.

Normalização somente para comparação: trim, espaços repetidos e case Unicode. Acentos não são removidos. Código, descrição e demais campos exibidos/exportados mantêm o valor original do DTO do parser. A primeira descrição válida na ordem dos resultados persistidos é a escolhida. GTIN, CFOP predominante e correspondência entre códigos diferentes não entram na primeira versão.

O parser mantém sua validação anterior para XML para Excel. Somente a opção productAnalysis aceita cProd e vUnCom ausentes. Descrição, NCM, CFOP, unidade, quantidade, total do item e identificação continuam necessários; somente NF-e modelo 55 é aceita.

## Modo consolidado

ConsolidatedProduct é DTO readonly com código, descrição, emitente, NCM, unidade, quantidade total, frequência, menor/maior unitário, médio e total. Somas e comparações usam Brick Math BigDecimal, sem float. Total é a soma de vProd dos itens, não vNF das notas. Unitários ausentes são ignorados em mínimo/máximo; zero real é válido. Média ponderada = total / quantidade, com 10 casas decimais e HALF_UP explícito. Quantidade total zero produz média null.

Frequência conta chaves de NF-e distintas por grupo, não linhas. Produtos são ordenados por total decrescente; empates preservam a ordem de entrada. Essa ordem vale para preview e Excel.

## Modo detalhado

Uma linha por item, com NF-e, série, emissão, emitente, CNPJ/CPF, código, descrição, NCM, CFOP, unidade, quantidade, unitário e total. O DTO ProductItem é a representação persistida e a fonte da consolidação/exportação.

## Preview

Resumo persistido nos metadados: NF-es válidas, falhas, itens, produtos distintos e valor em produtos. Cada modo tem até 10 linhas; a API não abre XML nem carrega os itens completos ao consultar o resultado. Descrições, nomes, códigos e unidades longos são abreviados somente na amostra, com marcador; o Excel preserva os campos completos. A chave de acesso não é enviada no preview.

Consolidado e Detalhado usam botões nativos com aria-pressed e indicação visual do modo ativo. Tabelas têm captions, headings e região de rolagem acessível ao teclado; não há datagrid ou biblioteca de tabs. Valores são formatados como strings, sem conversão para Number; zeros decimais finais excedentes são removidos da exibição monetária de produtos sem alterar precisão persistida.

## Excel

Um XLSX com abas Produtos e Itens. Produtos inclui as dez colunas comerciais sugeridas mais CNPJ/CPF e nome do emitente, necessários para distinguir grupos com códigos iguais de fornecedores diferentes. Itens inclui as treze colunas detalhadas.

Chaves/códigos/documentos são texto explícito, inclusive zeros à esquerda. Strings começadas por =, +, -, @ não viram fórmulas. Decimais seguros para Excel são numéricos; valores além de 15 dígitos significativos ficam como texto para não perder precisão. Datas são numéricas com formatação; timestamps são exportados em UTC, como na ferramenta anterior, enquanto datas sem hora preservam o dia informado. Médias não dependem de fórmulas Excel. AutoFilter e freeze pane são mantidos.

## Histórico

A conta identifica a ferramenta em cada processamento e compra. Produtos dos XMLs mostra contagem de itens, produtos e valor em produtos quando disponível. Downloads reutilizam o endpoint autenticado; links de retomada apontam à ferramenta correta. A seção de uso da conta identifica explicitamente XML para Excel; cada página de ferramenta mostra seu próprio saldo.

## Free

20 XMLs por processamento e 50 por dia. A auditoria encontrou filtro por ferramenta no uso existente; foi preservada essa semântica, agora parametrizada por enum. Portanto, o diário é por usuário e ferramenta. /api/account/usage mantém XML para Excel como padrão; ?tool=products-from-xml consulta a nova ferramenta. O servidor valida o enum e aplica as mesmas regras sob os locks existentes.

## Compra avulsa

Mesmos pacotes, preços, consumo e regras. Compra continua ligada a um Processing e não consome o saldo grátis. Checkout muda apenas o nome da ferramenta e os destinos de sucesso/cancelamento, derivados do enum. Webhooks e validação de pagamento não foram alterados. Retomar processamento pago usa a rota correta.

## Pro

250 XMLs por processamento, 500 por dia por ferramenta e 24 horas de retenção, resolvidos pelo EntitlementService existente. O mesmo Pro habilita ambas. Portal, assinatura, preço mensal e webhooks permanecem iguais. Não foi criado TTL ou preço específico.

## SEO

Metadata específica: “Extrair produtos de XML NF-e para Excel | Feitoo”. Description específica, WebApplication sem reviews/rating, nova rota prerenderizada e sitemap com seis páginas públicas. Sem domínio configurado, noindex/robots bloqueado permanece. Build prerenderiza sete rotas, incluindo 404. A home tem dois cards; Ferramentas aponta à seção correspondente, com navegação por âncora habilitada.

## Analytics

Eventos compartilhados usam tool=products-from-xml. Allowlist aceita apenas os dois IDs conhecidos. São mantidos tool_view, upload_started/completed, processing_started/completed/failed, result_viewed, download_clicked e eventos comerciais existentes. Nenhum produto, descrição, quantidade fiscal, valor fiscal, documento, chave ou nome de arquivo é enviado. Medição continua sujeita ao consentimento existente.

## Performance

Benchmark local: php artisan feitoo:benchmark-products-from-xml --documents=N --items=16. Mede parsing seguro, roundtrip JSON dos DTOs, consolidação e XLSX; exclui SQL, upload, storage remoto e espera de fila. Dados exclusivamente sintéticos. Não altera banco e remove o arquivo temporário ao terminar. --unique gera códigos diferentes por NF-e para o pior caso de quantidade de grupos.

| XMLs | Itens | Produtos | Tempo total | Pico de memória |
| ---: | ---: | ---: | ---: | ---: |
| 20 | 320 | 16 | 0,4215 s | 56 MiB |
| 100 | 1.600 | 16 | 1,9060 s | 70 MiB |
| 250 | 4.000 | 16 | 4,7071 s | 96 MiB |
| 250 (--unique) | 4.000 | 4.000 | 5,8160 s | 134 MiB |

O pior caso fica próximo dos 132 MiB registrados anteriormente para XML para Excel, embora execuções e ambientes não sejam uma comparação controlada. Limites técnicos mantidos: 250 XMLs, 4.000 itens, 40 MiB por lote, limites de request/arquivo e guardas XML existentes. PhpSpreadsheet permanece; não houve troca de biblioteca. Resultados não são garantia universal para XMLs reais.

## Segurança

Storage privado, checksums, parser seguro, autorização por ownership, UUIDs públicos, fila e locks existentes. Resultados não expõem paths. A migration adiciona JSON nullable em ProcessingFileResult, sem catálogo permanente. Ao excluir/purgar, os registros de itens e os metadados de resumo/preview de produtos são removidos; o histórico técnico mínimo permanece. Resultado expirado é bloqueado mesmo antes da limpeza física. Falhas parciais geram Excel dos válidos; nenhum válido gera mensagem segura e nenhum Excel vazio.

## Testes backend

Suíte completa: 232 testes. Casos novos cobrem uma/múltiplas NF-es, repetição em dez notas, fornecedores/códigos/NCM/unidades, descrição variável, código ausente, quantidade decimal, ponderação, mínimo/máximo, soma e grandes decimais, zeros reais, falhas parciais, nenhum válido, NFC-e rejeitada, Free diário e por lote, avulso, Pro 250/500, ownership, download, expiração/exclusão, workbook e formula injection. A regeneração a partir dos resumos usa mock impedindo releitura de XML. Helpers compartilhados mantêm os testes anteriores de exportação. Benchmark rejeita volumes fora da capacidade e ambiente de produção. Pint aprovado.

## Testes frontend

148 testes frontend aprovados e dois testes de assets públicos. A nova suíte cobre página pública, seleção, isolamento da seleção, retorno por e-mail, upload/polling/analytics, modos de preview, paywall Free, Pro, Checkout, falhas, download e erro de upload. Build sem warnings, bundle inicial de 331,81 kB. Prettier aprovado.

Validação Edge usa API sintética, sem conta fiscal ou cobrança real: página pública, seletor acima da dobra, retorno após login com seleção, processamento, dois previews, falha parcial e download. Quinze capturas em 320/375/768/1024/1440 px ficam em feitoo-web/dist/validation/products-*.png; products-browser-report.json registra as larguras. As capturas desktop/mobile foram inspecionadas visualmente.

Comparação campo a campo do XLSX com a fixture XML original inclui código, produto, NCM, CFOP, quantidade, unitário e total. Não foram localizados XMLs fiscais reais disponíveis no workspace/storage consultado; sua localização foi solicitada e essa validação real permanece pendente. Nenhum documento fiscal real foi adicionado ao Git.

## Arquivos criados

- feitoo-api/app/Domain/Tools/ProductsFromXml/ProductItem.php
- feitoo-api/app/Domain/Tools/ProductsFromXml/ConsolidatedProduct.php
- feitoo-api/app/Domain/Tools/ProductsFromXml/ProductConsolidationService.php
- feitoo-api/app/Domain/Tools/ProductsFromXml/ProductsExcelGenerator.php
- feitoo-api/app/Domain/Tools/WritesFiscalWorkbook.php
- feitoo-api/app/Services/ProductsFromXmlResultService.php
- feitoo-api/app/Console/Commands/BenchmarkProductsFromXml.php
- feitoo-api/database/migrations/2026_10_13_200000_add_products_items_to_processing_file_results.php
- feitoo-api/tests/Unit/Fiscal/ProductConsolidationTest.php
- feitoo-api/tests/Feature/ProductsFromXmlTest.php
- feitoo-web/src/app/features/processing/tools.ts
- feitoo-web/src/app/features/processing/pages/products-from-xml.spec.ts
- docs/products-from-xml.md

## Arquivos modificados

- feitoo-api/app/Enums/ProcessingTool.php
- feitoo-api/app/Domain/Fiscal/DTO/ParsedFiscalItem.php
- feitoo-api/app/Domain/Fiscal/Nfe/NfeParser.php
- feitoo-api/app/Domain/Tools/XmlToExcel/XmlToExcelGenerator.php
- feitoo-api/app/Console/Commands/BenchmarkXmlToExcel.php
- feitoo-api/app/Models/ProcessingFileResult.php
- feitoo-api/app/Services/FiscalProcessingService.php
- feitoo-api/app/Services/XmlToExcelResultService.php
- feitoo-api/app/Services/EntitlementService.php
- feitoo-api/app/Services/ProcessingService.php
- feitoo-api/app/Services/StripeCheckoutGateway.php
- feitoo-api/app/Http/Controllers/BillingController.php
- feitoo-api/app/Http/Controllers/GoogleAuthController.php
- feitoo-api/app/Http/Resources/ProcessingResource.php
- feitoo-api/app/Http/Resources/ProcessingHistoryResource.php
- feitoo-api/app/Http/Resources/PurchaseResource.php
- feitoo-api/routes/api.php
- feitoo-web/src/app/app.config.ts
- feitoo-web/src/app/app.html
- feitoo-web/src/app/app.ts
- feitoo-web/src/app/app.routes.ts
- feitoo-web/src/app/app.routes.server.ts
- feitoo-web/src/app/core/analytics.service.ts
- feitoo-web/src/app/core/public-pages.ts
- feitoo-web/src/app/core/seo.service.ts
- feitoo-web/src/app/core/public-launch.spec.ts
- feitoo-web/src/app/features/account/models/account.ts
- feitoo-web/src/app/features/account/pages/account-page.ts
- feitoo-web/src/app/features/account/pages/account-page.html
- feitoo-web/src/app/features/auth/pages/auth-page.ts
- feitoo-web/src/app/features/auth/services/auth.service.ts
- feitoo-web/src/app/features/processing/models/processing.ts
- feitoo-web/src/app/features/processing/pages/xml-to-excel.ts
- feitoo-web/src/app/features/processing/pages/xml-to-excel.html
- feitoo-web/src/app/features/processing/services/processing.service.ts
- feitoo-web/src/app/features/processing/services/billing.service.ts
- feitoo-web/src/app/features/processing/services/xml-selection.service.ts
- feitoo-web/src/app/features/public/home.ts
- feitoo-web/scripts/generate-public-assets.mjs
- feitoo-web/scripts/public-assets.test.mjs
- feitoo-web/scripts/check-tool-browser.mjs

## Migrations

2026_10_13_200000_add_products_items_to_processing_file_results adiciona products_items JSON nullable. Aplicada no banco local com php artisan migrate --no-interaction. Dados anteriores permanecem null e XML para Excel não preenche a coluna. Ambientes externos precisam aplicar a migration antes de usar o código atualizado; não houve deploy.

## Limitações

NF-e modelo 55 somente. Sem catálogo, estoque, ERP, curva ABC, classificação, CSV/PDF ou IA. GTIN e CFOP predominante não foram incluídos. Produtos que representam o mesmo item comercial com códigos diferentes não são aproximados automaticamente. Descrições divergentes em um mesmo grupo usam a primeira. Não há deduplicação adicional de XMLs: as somas representam itens enviados, enquanto a frequência conta chaves distintas.

A média com divisão não exata é arredondada a dez casas; isso está explícito e não afeta as somas originais. Não há benchmark de rede/SQL/queue real nem validação Safari/iOS físico. Google externo e cobrança real Stripe não foram exercitados. A conferência com XMLs fiscais reais está pendente da localização dos arquivos. Metadados e itens fiscais deixam de estar disponíveis após exclusão/purge, conforme a política existente.
