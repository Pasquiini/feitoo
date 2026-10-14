# Conta e histórico — etapa 12D

## Auditoria

O SCSS duplicava utilitários globais de flex, espaçamento, feedback e foco. Seletores descendentes repetiam tamanhos e o progresso repetia cores por navegador. O processamento persiste total_value por NF-e como string, mas não persiste um total consolidado no lote. O preview soma esses valores com Brick Math BigDecimal. O histórico não precisava de XML, preview completo, migração ou novo serviço para acessar os mesmos valores.

## SCSS antes

Aproximadamente 5,59 kB, acima do aviso de 4 kB do build Angular.

## SCSS depois

Dentro do budget de 4 kB no build de produção. Classes globais existentes substituem declarações locais de flex, gap, feedback e foco. Headings usam classes locais menos específicas. Progresso usa variável de cor em vez de repetir regras por estado/navegador. Regras equivalentes e media queries foram simplificadas; a ordem da cascata preserva coluna única abaixo de 768 px. Não foi transferido CSS específico da conta para o stylesheet global.

## Budget

Budget mantido sem alteração. npm.cmd run build concluiu sem warning de SCSS. Bundle inicial: 327,41 kB. Nenhuma dependência adicionada.

## Total fiscal no histórico

Uma consulta adicional para a página atual seleciona somente processing_id e total_value dos resumos persistidos associados a inputs concluídos e não removidos. BigDecimal soma as strings sem float, como no preview existente. Só é exposto um total quando todos os documentos bem-sucedidos possuem resultado e valor; isso evita apresentar uma soma incompleta como consolidada. XMLs inválidos não entram na soma.

## Compatibilidade com dados antigos

Sem resultados persistidos, com valores ausentes ou quantidade incompleta, total_value é null. Zero real é preservado como string decimal. Um lote expirado ainda pode mostrar total se os resumos estiverem presentes. A exclusão/purge existente apaga os resumos fiscais; após isso o campo fica null e é ocultado. Não houve backfill ou releitura de arquivos.

## API

ProcessingHistoryResource acrescenta somente total_value: string decimal ou null. A consulta usa IDs da página já filtrada pelo usuário autenticado, sem campos fiscais extras. Mantidas autenticação, isolamento, ordenação, paginação e cache privado. ProcessingPreviewService não é chamado e items_preview não é selecionado.

## Frontend

HistoryProcessing aceita total_value opcional e nullable, permitindo respostas antigas. O item mostra “Total fiscal: R$ 8.742,19” como informação secundária. A formatação trabalha com dígitos em string, preservando valores acima do limite seguro de Number e a precisão decimal existente. Ausência oculta a linha; disponibilidade de download e expiração não são condicionadas ao total.

## Testes backend

php artisan test: 214 testes aprovados, 1.806 assertions na execução completa. Após reforçar o teste de exclusão para usar o endpoint real, AccountTest foi executado novamente: 12 testes, 112 assertions aprovadas. Cobertos soma exata de grandes decimais, isolamento, valor ausente/incompleto, zero real, expirado, exclusão e mocks impedindo leitura de XML e geração de preview. Teste de consultas confirma ausência de items_preview. vendor/bin/pint --test aprovado.

## Testes frontend

npm.cmd test -- --watch=false: 138 testes aprovados em 10 arquivos. Novos casos incluem BRL, valores grandes, zero, campo ausente/null, item expirado e excluído. Prettier aprovado. O script de Edge verifica totals disponíveis e ausentes, sem overflow em 320, 375, 768, 1024 e 1440 px, e coluna única no mobile. Capturas ficam em feitoo-web/dist/validation/account-{largura}.png e nos cenários Free/Pro existentes. Todos os dados do navegador são sintéticos.

## Arquivos modificados

- feitoo-api/app/Http/Controllers/AccountController.php
- feitoo-api/app/Http/Resources/ProcessingHistoryResource.php
- feitoo-api/tests/Feature/AccountTest.php
- feitoo-web/src/app/features/account/models/account.ts
- feitoo-web/src/app/features/account/pages/account-page.ts
- feitoo-web/src/app/features/account/pages/account-page.html
- feitoo-web/src/app/features/account/pages/account-page.scss
- feitoo-web/src/app/features/account/pages/account-page.spec.ts
- feitoo-web/scripts/check-tool-browser.mjs
- docs/account-quality.md

## Limitações

Não é possível recuperar total após os resumos fiscais serem apagados sem introduzir persistência nova, fora do escopo. A soma consulta apenas resumos mínimos da página, mas acrescenta uma consulta ao histórico. Validação mobile usa emulação Edge; não substitui Safari/iOS físico ou leitor de tela. Nenhuma alteração em billing, Stripe, entitlement, preços, limites ou estrutura da conta. Não houve deploy.
