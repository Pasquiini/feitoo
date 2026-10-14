# Produtos dos XMLs — etapa 14A

## Auditoria e layout

A página reutiliza XmlToExcelPage. O refinamento é condicionado a productsMode para preservar a outra ferramenta. Antes, havia explicações repetidas, botões de preview soltos e um resultado centrado apenas no Excel.

O hero recebeu identificação com ícone e uma frase curta. O resultado ganhou borda, fundo suave, cards de métricas com números maiores e ações Excel/PDF. Consolidado e Detalhado usam um seletor compacto com estado pressionado acessível. As tabelas permanecem em regiões com rolagem própria e foco por teclado, sem provocar overflow da página. Como funciona passou a três passos; as regras detalhadas foram recolhidas em details. O FAQ permanece fechado inicialmente.

Ícones SVG locais: XML, produto, planilha, PDF, sucesso, informação e download. Sem biblioteca, fonte de ícones ou novos pacotes. SVGs decorativos ficam ocultos de leitores de tela; os controles mantêm rótulos textuais.

## PDF

Download direto de um PDF 1.4, A4, uma página, criado no navegador a partir do preview autenticado. Inclui nome, data/hora local da geração, NF-es válidas, itens, produtos diferentes, total em produtos e tabela resumida com produto, código, emitente, quantidade/unidade e total. Exporta no máximo dez produtos, na ordem consolidada do preview. Mostra explicitamente a quantidade da amostra e orienta o uso do Excel completo.

Decimais monetários são formatados como strings, sem conversão para ponto flutuante. Texto fiscal é codificado em hexadecimal, sem interpretação como comandos PDF. As caixas de texto têm limites e ajuste horizontal para evitar sobreposição. O Blob é liberado após o download. A ação verifica resultado concluído/disponível, expiração e exclusão.

Nenhuma mudança em backend, processamento, agrupamento, preços, consumo, monetização ou limites. Nenhum novo upload, endpoint ou arquivo persistido.

## Validação

- Backend: `php artisan test` — 232 testes, 1.956 assertions.
- Frontend: `npm test -- --watch=false` — 149 testes, 12 arquivos.
- Assets públicos: `npm run test:public` — 2 testes.
- Produção: `npm run build` — passou, sete rotas prerenderizadas.
- Navegador: `node scripts/check-tool-browser.mjs`, Edge headless, build de produção e API sintética. Verifica seleção/upload, retorno do login, processamento, resultado parcial, Consolidado/Detalhado, Excel e PDF.
- Página pública e ambos os previews em 320, 375, 768, 1024 e 1440: sem overflow horizontal da página. Tabelas largas mantêm rolagem interna.
- Inspeção manual das capturas mobile e desktop para hierarquia, textos, cards, botões e FAQ.
- Teste PDF: estrutura/xref, precisão de valores grandes, acentos, limite de dez linhas e texto não confiável.

Evidências locais: `feitoo-web/dist/validation/products-browser-report.json`, `products-summary.pdf`, `products-public-*.png`, `products-consolidado-*.png` e `products-detalhado-*.png`.

## Arquivos desta etapa

Modificados: `feitoo-web/src/app/features/processing/pages/xml-to-excel.ts`, `.html`, `.scss`; `feitoo-web/scripts/check-tool-browser.mjs`.

Criados: `feitoo-web/src/app/features/processing/pages/tool-icon.ts`; `feitoo-web/src/app/features/processing/services/products-pdf.ts`; `products-pdf.spec.ts`; este relatório.

## Limitações

- PDF é amostra do preview, não exportação completa; descrições e identificadores longos podem ser abreviados. Excel preserva a versão completa.
- Fonte PDF padrão com caracteres latinos; caracteres fora desse conjunto usam substituição. PDF não possui marcação semântica para leitores de tela; a página mantém a tabela HTML acessível.
- Validação de navegador usa dados sintéticos, sem serviços externos nem credenciais fiscais reais. Backend possui testes próprios com fixtures XML.
- Build emite aviso de CSS do componente: 5,4 kB versus orçamento de aviso de 4 kB; abaixo do limite de erro de 8 kB. O orçamento não foi alterado.
