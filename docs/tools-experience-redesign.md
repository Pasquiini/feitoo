# Experiência das ferramentas Feitoo

Implementação e validação local em 19/10/2026. As seis rotas foram redesenhadas. Nenhum deploy ou acesso ao VPS foi realizado.

## 1. Diagnóstico visual anterior

Cinco ferramentas XML compartilham `XmlToExcelPage`; DOCX tem componente próprio. Havia estilos repetidos de upload, status, listas e resultados. O DOCX expunha o seletor de arquivos nativo. O organizador começava com um bloco de configurações antes do envio. Estados apareciam predominantemente como textos soltos, e havia pouca separação visual entre a tarefa e o conteúdo editorial.

## 2. Direção visual escolhida

Microapps com área de trabalho dominante: breadcrumb, hero compacto, barra verde do workspace, etapas, entrada de arquivos, seleção/configuração e resultados. Verde Feitoo, fundo claro e superfícies neutras; sem imagens, gradientes, dashboard fictício ou nova identidade por ferramenta. Conteúdo editorial e FAQ permanecem abaixo da aplicação.

## 3. Componentes compartilhados

`tool-experience.ts` contém seis componentes pequenos de apresentação: `ToolHero`, `ToolWorkspace`, `ToolUploader`, `ToolStatus`, `ToolSecurityNote` e `ToolRelated`. Hero e relacionados consultam o catálogo existente, sem duplicar títulos, tags ou URLs. Hero e relacionados têm estilos próprios; o workspace carrega uma folha compartilhada, restrita a `.tool-app`, reutilizada nas seis ferramentas. Ela usa encapsulamento global deliberadamente para alcançar o conteúdo projetado; o namespace evita atingir home, conta ou login.

Não foi criado um framework de processamento. Serviços, polling, seleção, contratos da API, monetização e analytics existentes foram mantidos. Nos dois componentes que controlam os fluxos XML e DOCX, a única função nova é um formatador visual do nome PDF, com fallback para nome nulo; ele não altera upload ou download.

## 4. Uploader

Superfície com ícone de arquivo, formato, instrução, botão de seleção, indicação de arquivos já escolhidos e limites vindos da configuração existente. Hover, foco e drag-over têm estados próprios. Os handlers originais de drag/drop e seleção foram preservados. Input nativo permanece como mecanismo de seleção, acionado por botão acessível; nenhum seletor custom substitui o diálogo do sistema.

## 5. File list

Listas mostram tipo, nome, tamanho e situação, com ação de remoção. Nomes longos truncam visualmente sem remover o texto acessível; seleção e resultados DOCX também oferecem o nome completo no title. XML preserva paginação, limpeza e remoção. A seleção XML usa details nativo aberto antes do processamento. Não foi introduzida tabela para seleção de arquivos.

## 6. Estados

Status compartilhado possui ícone, rótulo, título e explicação textual para idle, seleção, envio, pending, queued, processing, partial, completed, failed/error e expired. A apresentação deriva exclusivamente dos estados e contadores existentes. Falha de rede continua distinta de falha terminal. Quando há arquivos com falha num lote concluído, o resultado informa conclusão parcial sem bloquear downloads válidos. Expiração e exclusão retiram resultados segundo a lógica já existente.

## 7. Progresso

XML mantém `processed_files_count / files_count`. DOCX mantém sua porcentagem calculada dos mesmos contadores e mostra a barra durante processing; fila e envio têm feedback textual próprio. Nenhuma porcentagem, duração ou progresso de upload é simulado na interface.

## 8. Resultados

Resultados têm superfície própria com faixa verde, resumo de status, métricas reais e downloads destacados. XML/Excel preserva resumo de notas/itens e seus previews. DOCX preserva ações individuais e ZIP, exibindo o nome `.pdf` nos resultados concluídos. Ações secundárias de novo lote e consulta permanecem acessíveis, e exclusão tem estilo de perigo.

## 9. Erros

Mensagens existentes são preservadas, com tratamento visual discreto e contexto. Erros não dependem apenas de cor. Não se adicionou exposição de códigos internos, stderr ou stack traces. Testes continuam verificando mensagens seguras, formatos inválidos, falhas individuais, falha total e expiração. Ações de consultar novamente e selecionar outros arquivos continuam usando os métodos anteriores.

## 10. XML → Excel

Hero e workspace dedicados, seleção visual, contagem, resumo numérico e download principal. Previews de Notas e Itens preservados integralmente: tabelas, agrupamento por nota, dados, limites de amostra e scroll interno. Tabelas receberam cabeçalho mais discreto e tipografia compacta; a ferramenta não foi convertida em dashboard.

## 11. Produtos dos XMLs

Mantém consolidação, quantidade, produtos diferentes, valores e modos Consolidado/Detalhado. Preview, Excel completo e PDF de resumo continuam disponíveis com os mesmos dados e avisos. O resultado comunica consolidação usando métricas e abas existentes, dentro do novo workspace.

## 12. XML → PDF

Resultado de lote com download ZIP e lista de DANFEs, preservando visualização PDF autenticada, downloads individuais, atualização de arquivos e avisos fiscais. O iframe e o fallback de download foram mantidos. Nenhum novo formato ou comportamento fiscal foi criado.

## 13. Organizador

Upload aparece antes da configuração. Os cinco critérios existentes viraram rádios nativos em opções visuais: ano/mês, fornecedor, CNPJ/CPF do emitente, ano/mês/fornecedor e modelo. A prévia textual de pastas acompanha a escolha sem inventar arquivos ou datas. Padronização, duplicados, ação para duplicados e relatório Excel foram preservados. Não foram acrescentados critérios de entrada/saída ou campos sem suporte no contrato atual. Resumo de organização, destinos e problemas continuam visíveis.

## 14. Analisador

Workspace de entrada consistente e área analítica específica. Filtros mantêm valores e handlers. Métricas têm contraste maior, três colunas no desktop, duas em telas intermediárias e uma no mobile estreito; período ocupa linha própria. Gráficos, séries, tabelas, paginação, avisos, conflitos e exportação por filtros foram preservados. Nenhum indicador foi inventado ou agregado de outra maneira.

## 15. DOCX → PDF

Novo uploader, lista de seleção com ícones, nome/tamanho/status, ação primária, feedback separado de envio/fila/conversão e resultado por documento. Nomes de resultados concluídos aparecem com `.pdf`, coerentes com o download. ZIP permanece disponível quando o fluxo anterior já o oferecia. Limites e retenção continuam vindos da API; autenticação e retorno à ferramenta foram preservados.

## 16. Mobile e evidências visuais

Smokes verificaram 320, 375, 390, 768, 1024 e 1440 px. Sem overflow horizontal nas seis ferramentas, tanto na entrada quanto nos resultados. Listas quebram ações em linhas úteis; nomes longos truncam; previews usam scroll interno. Screenshots incluem idle, seleção, processamento, resultados e falha total. Os dados das imagens são sintéticos.

| Ferramenta  | 1440 px                                                                                                                                                                               | 390 px                                                                                                                                                                              |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| XML → Excel | [Entrada](../feitoo-web/dist/validation/tools-experience/xml-to-excel-idle-1440.png), [resultado](../feitoo-web/dist/validation/tools-experience/xml-to-excel-result-1440.png)        | [Entrada](../feitoo-web/dist/validation/tools-experience/xml-to-excel-idle-390.png), [resultado](../feitoo-web/dist/validation/tools-experience/xml-to-excel-result-390.png)        |
| DOCX → PDF  | [Entrada](../feitoo-web/dist/validation/tools-experience/docx_to_pdf-idle-1440.png), [resultado](../feitoo-web/dist/validation/tools-experience/docx_to_pdf-result-1440.png)          | [Entrada](../feitoo-web/dist/validation/tools-experience/docx_to_pdf-idle-390.png), [resultado](../feitoo-web/dist/validation/tools-experience/docx_to_pdf-result-390.png)          |
| Organizador | [Configuração](../feitoo-web/dist/validation/tools-experience/xml-organizer-idle-1440.png), [resultado](../feitoo-web/dist/validation/tools-experience/xml-organizer-result-1440.png) | [Configuração](../feitoo-web/dist/validation/tools-experience/xml-organizer-idle-390.png), [resultado](../feitoo-web/dist/validation/tools-experience/xml-organizer-result-390.png) |
| Analisador  | [Entrada](../feitoo-web/dist/validation/tools-experience/xml-analyzer-idle-1440.png), [resultado](../feitoo-web/dist/validation/tools-experience/xml-analyzer-result-1440.png)        | [Entrada](../feitoo-web/dist/validation/tools-experience/xml-analyzer-idle-390.png), [resultado](../feitoo-web/dist/validation/tools-experience/xml-analyzer-result-390.png)        |

Revisão visual efetuada: hierarquia hero/workspace/resultado, alinhamento, controles, legibilidade, densidade e scroll dos previews. Capturas são artefatos locais em `dist/validation`, não assets publicados.

## 17. Acessibilidade

H1 único e textos SEO preservados. Botões reais acionam upload e ações. Rádios, checkbox, select e details nativos mantêm interação por teclado; labels e legend foram preservados. Foco visível em ações e controles. Status compartilhado usa aria-live polite e texto além dos ícones/cores. Drag/drop possui alternativa de seleção. Transições respeitam reduced-motion. Não foi executada auditoria formal com leitores de tela.

## 18. Performance

Sem dependências, fontes, imagens externas ou animações novas. Rotas continuam lazy. Build final: 372,61 kB iniciais brutos, 105,61 kB estimados de transferência; chunk compartilhado das ferramentas 16,67 kB / 4,71 kB; XML 108,58 kB / 26,61 kB; DOCX 20,52 kB / 6,33 kB. Antes desta tarefa: inicial aproximadamente 371,41 kB / 105,22 kB, XML 110,19 kB e DOCX 19,20 kB. Há custo adicional de apresentação compartilhada, sem duplicá-la por rota. Não foi medido Lighthouse ou Web Vitals em produção.

## 19. CSS

Estilos duplicados de upload, listas, status, resultados e botões saíram dos arquivos XML/DOCX para o padrão compartilhado. Hero e ferramentas relacionadas têm folhas próprias porque são componentes independentes. Estilos específicos de preview, organização e análise ficam nos componentes correspondentes. Budgets não foram alterados.

Avisos finais do build: `tool-experience.scss` 7,47 kB (3,47 kB acima do alerta de 4 kB), home 6,76 kB e shell 4,14 kB. Home/shell já tinham esses avisos antes desta tarefa. O aviso anterior de XML/Excel (5,56 kB) deixou de existir nesse arquivo após a consolidação. O compartilhado cobre seis ferramentas, estados, responsividade e conteúdo projetado; permanece abaixo do erro de 8 kB. O total de CSS aumentou para oferecer a experiência pedida; o custo está explícito, sem elevar orçamento para ocultá-lo.

## 20. Testes

`npm.cmd test -- --watch=false`: 201 testes passaram em 20 arquivos. Inclui novos testes dos estados compartilhados, hero/H1, uploader, contagem e relacionados; o teste do organizador agora interage com os rádios e verifica preservação de opções após login. DOCX verifica nome PDF e estado parcial, além dos testes anteriores de seleção, remoção, envio, polling, falha, download, expiração e retorno autenticado.

`npm.cmd run test:public`: cinco testes passaram. `node --test scripts/tools-experience-seo.test.mjs scripts/home-docx-seo.test.mjs`: oito testes passaram sobre o HTML gerado em modo produção. Verificam H1, canonical, robots, schemas, FAQ visível igual ao schema, workspace presente e links de segurança; os testes anteriores mantêm metadados, OG/Twitter e shell privado. SEOService, public-pages, FAQ, sitemap e configuração de redirects não foram alterados nesta tarefa.

## 21. Build

`npm.cmd run build` com `FEITOO_BUILD_ENV=production` aprovado. Quatorze rotas estáticas preservadas. Prettier aplicado aos arquivos desta tarefa e `git diff --check` executado. A configuração gerada é devolvida ao modo local após validar os artefatos de produção; não fica um domínio público habilitado no desenvolvimento.

## 22. Smokes

- `check-tool-browser.mjs`: aprovado para XML/Excel, Produtos, previews, mobile, autenticação, conta, histórico, erros e fluxos de download/exclusão; sem runtime errors.
- `check-organizer-browser.mjs`: aprovado com critérios visuais, opções, upload, resultado parcial, estrutura e ZIP em desktop/mobile.
- `check-docx-browser.mjs`: aprovado com drag/drop, polling, PDF individual, ZIP, exclusão, seis larguras e navegação compartilhada; sem runtime errors.
- Novo `check-tools-experience-browser.mjs`: seis ferramentas, 96 combinações de estado/largura, oito requisições de download verificadas, envio real pelo input, formato inválido, remoção, fila, processamento, conclusão parcial, exclusão/indisponibilidade, falha total recuperada e nova tentativa até conclusão integral. Validações usam a interface renderizada e APIs sintéticas, sem hook de estado no componente. Resultado em [report.json](../feitoo-web/dist/validation/tools-experience/report.json), sem runtime errors.

## 23. Arquivos alterados nesta tarefa

- `src/app/features/processing/pages/tool-experience.{ts,scss,spec.ts}`: apresentação compartilhada e testes.
- `src/app/features/processing/pages/tool-hero.scss` e `tool-related.scss`: estilos dos componentes independentes.
- `src/app/features/processing/pages/xml-to-excel.{ts,html,scss}`: integração nas cinco ferramentas XML; lógica funcional preservada, além de formatação Prettier.
- `src/app/features/processing/pages/docx-to-pdf.{ts,html,scss,spec.ts}`: integração, apresentação de nome PDF e teste.
- `src/app/features/processing/pages/organizer-options.{ts,scss}`: controles visuais e prévia de estrutura.
- `src/app/features/processing/pages/organizer-result.ts`: apresentação do resumo.
- `src/app/features/processing/pages/xml-analyzer-dashboard.scss`: métricas, controles e contraste.
- `src/app/features/processing/pages/xml-organizer.spec.ts` e `scripts/check-organizer-browser.mjs`: interação com os novos rádios.
- `scripts/check-tools-experience-browser.mjs`, `tools-experience-fixtures.mjs` e `tools-experience-seo.test.mjs`: smoke, dados sintéticos e SEO.
- Este relatório. Caminhos de código acima são relativos a `feitoo-web`.

As alterações de home/SEO e o bind `/etc/libreoffice` já estavam presentes no início desta tarefa e foram preservados. Nenhuma nova alteração de backend foi feita aqui. O PDF preexistente na pasta OG também foi preservado, sem entrar nos assets publicados.

## 24. Riscos

O CSS compartilhado usa namespace global intencional e precisa manter esse isolamento em futuras edições. Continua emitindo aviso de orçamento, conforme valores acima. Os smokes usam respostas sintéticas e não homologam LibreOffice, geração real de DANFE/Excel/ZIP ou a infraestrutura Linux; esses contratos e serviços não foram modificados. Screenshots de resultados usam exemplos sintéticos e não representam dados de usuários. Não foi realizado benchmark de desempenho ou auditoria completa de acessibilidade.

## 25. Pendências

Sem regressões conhecidas nos testes e smokes executados. Não foi identificada necessidade de alteração de backend. Publicação, VPS, filas, Supervisor, LibreOffice, Bubblewrap, Stripe, planos e migrations ficam fora desta tarefa. Nenhum deploy foi realizado.
