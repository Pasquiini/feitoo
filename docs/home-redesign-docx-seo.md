# Home e SEO DOCX → PDF

Validação local em 19/10/2026. Sem deploy, acesso ao VPS ou alteração de Supervisor.

## 1. Conceito visual

A home passa a funcionar como catálogo de utilidades. Verde Feitoo, off-white, tipografia forte, formatos de arquivos e superfícies discretas substituem a apresentação predominantemente fiscal.

## 2. Estrutura da home

Header compacto → hero e busca → atalhos → catálogo por família → destaques DOCX e XML → três passos → segurança → planos → CTA final → footer.

## 3. Hero

“Arquivos entram. Trabalho pronto sai.” é o destaque visual. Existe apenas um H1: “Ferramentas online para arquivos, documentos e planilhas.” O gráfico é HTML/CSS com transformações reais XML → XLSX, DOCX → PDF e XML → ZIP; não representa um software fictício.

## 4. Busca

Atualização por signals/computed, totalmente no frontend, sem biblioteca ou chamada à API. `searchTools` normaliza caixa e acentos e combina title, description, benefit, categoria, keywords e aliases. Aceita vários termos. Limpar devolve foco ao campo; Escape também limpa. O estado vazio sugere termos e permite retornar ao catálogo. Contagem anunciada com status live.

## 5. Categorias

XML e Fiscal e Documentos são derivadas do catálogo. Novas famílias podem ser introduzidas pelos dados, sem duplicar listas na navegação.

## 6. Catálogo

`home-tools.ts` concentra as seis ferramentas reais e exporta categorias e busca reutilizável. Home, atalhos, destaques, dropdown e footer usam essa fonte. Metadados editoriais de SEO permanecem em `public-pages.ts`, pois têm finalidade e texto diferentes dos títulos curtos dos cards.

## 7. Cards

Cada microproduto mostra formato de entrada, transformação, saída, título, descrição, tags e CTA. Links reais com destinos existentes, foco visível e elevação leve no hover. Sem botões simulados por div.

## 8. Header

Dropdown nativo com `details/summary`, organizado por família. No mobile vira acordeão dentro do menu, com rolagem própria quando necessário. Escape fecha o dropdown e retorna ao summary; outro Escape fecha o menu mobile. Conta, login contextual e logout preservados.

## 9. Footer

Marca com posicionamento amplo, catálogo completo gerado pelos dados e grupos Produto, Legal e Conta. Preferências de cookies preservadas.

## 10. Segurança

Bloco compacto informa armazenamento privado, acesso restrito, retenção limitada e exclusão automática, com link para `/seguranca`. Não promete exclusão instantânea no vencimento: há bloqueio de acesso e rotina de limpeza.

## 11. Planos

Free, Avulso e Pro existentes preservados, com link adicional para comparar em `/planos`. Preços, limites operacionais e Stripe não foram alterados. DOCX não anuncia compra avulsa.

## 12. Responsividade

Home e DOCX verificadas em 320, 375, 390, 768, 1024 e 1440 px. Grade em três, duas ou uma coluna. Gráfico decorativo do hero omitido no mobile. Smokes verificam ausência de overflow e produzem screenshots em `feitoo-web/dist/validation`.

## 13. Acessibilidade

Landmarks e skip link existentes preservados. H1 único, labels, controles nativos, links navegáveis, estado live da busca e foco visível. Navegação mobile e dropdown usam teclado. Animação dos cards desativada sob `prefers-reduced-motion`. Inspeção visual desktop/mobile realizada; não foi executada auditoria externa de leitores de tela.

## 14. Performance

Sem novas dependências, fontes, vídeos ou imagens. Lazy routes mantidas. Build final: aproximadamente 371,41 kB iniciais brutos / 105,22 kB estimados de transferência; chunk lazy da home aproximadamente 22,41 kB / 5,95 kB. O header compartilha os dados pequenos do catálogo com a home. Não foi medido Lighthouse ou Core Web Vitals de produção.

## 15. SEO home

Title: “Feitoo | Ferramentas Online para XML, Word, PDF e Planilhas”. Description: “Converta XML, Word e outros arquivos online. Organize documentos, gere planilhas e PDFs e resolva tarefas em poucos passos com o Feitoo.” Canonical da raiz, index,follow, WebSite, Organization, OG e Twitter preservados.

## 16. SEO DOCX

Title e description solicitados preservados. H1 “Converter DOCX para PDF online”. Conteúdo cobre quatro passos, preservação e limitações de layout, DOC versus DOCX, ausência de necessidade do Word, lotes e retenção. Free: 3 por processamento e 6/dia; Pro: 20 e 100/dia. Prazo real é mostrado pela configuração da API e pela expiração do resultado, evitando inventar retenção específica.

## 17. FAQ

Sete perguntas cobrem conversão, Word, DOC, lotes, layout, armazenamento e ZIP. `tool-faqs.ts` é a fonte tanto da interface quanto do FAQPage. Teste do HTML prerenderizado compara integralmente perguntas e respostas visíveis com o JSON-LD. ZIP descrito corretamente como disponível com mais de um documento convertido com sucesso.

## 18. Schemas

DOCX mantém WebApplication, BreadcrumbList e FAQPage em um único script JSON-LD. Sem ratings, reviews, estatísticas ou ofertas fictícias. Home mantém WebSite e Organization. Testes verificam ausência de duplicação e limpeza ao navegar.

## 19. Sitemap

DOCX presente em `https://feitoo.com.br/docx-para-pdf`. Treze páginas públicas indexáveis; login, cadastro e conta excluídos. Artefatos temporários OG continuam excluídos do build.

## 20. Prerender

Build com `FEITOO_BUILD_ENV=production`: 14 rotas estáticas, incluindo DOCX e a cobertura anterior. O número difere do sitemap porque inclui a página de erro. Shell CSR privado sem canonical ou schema da home e com noindex.

## 21. Redirects

Configuração existente preservada. Testes públicos verificam 308 de barra final e `/index.html`, inclusive DOCX, para rota limpa. Destino canônico não corresponde novamente à regra, evitando loop. Validação local das regras; nenhuma configuração de hospedagem foi publicada.

## 22. Analytics

Sistema consent-aware mantido. Código DOCX preserva `tool = docx_to_pdf` e eventos tool_view, processing_started e processing_completed. Nenhum novo tracking de busca ou conteúdo. Sem envio de filename, texto, e-mail ou identificador do processamento como propriedade de analytics. Testes existentes cobrem consentimento e filtragem de propriedades.

## 23. Testes

- Angular: 190 testes passaram em 19 arquivos.
- `npm.cmd run test:public`: cinco testes de assets, sitemap, robots, redirects e privados.
- `node --test scripts/home-docx-seo.test.mjs`: dois testes do HTML gerado em modo produção, incluindo metadados, schemas, igualdade do FAQ e shell privado.
- Backend: `php artisan test --compact --filter=Docx`: 13 passaram, 164 assertions, uma integração real opcional pulada.
- Busca: word, docx, pdf, xml, Excel, múltiplos termos, caixa/acentos, vazio e limpeza; rotas, categorias e atalhos.
- Header: catálogo completo e Escape em teste Angular, menu mobile nos smokes existentes e navegação de teclado no smoke DOCX ampliado.

## 24. Build

`npm.cmd run build` aprovado em modo local e produção, com 14 rotas. Budgets emitem avisos de CSS: home 6,76 kB, app 4,14 kB e XML/Excel 5,56 kB, contra alerta de 4 kB. Sem aumento dos limites para esconder avisos; XML/Excel já excedia anteriormente. Prettier aplicado aos arquivos alterados e `git diff --check` executado.

## 25. Smokes

`check-tool-browser.mjs` aprovado: home visitante/autenticada, busca, seis larguras, menu, Escape, reduced motion, ferramentas, conta, autenticação e histórico; sem erros de runtime. `check-organizer-browser.mjs` aprovado: desktop/mobile, opções, envio, resultado parcial e ZIP. `check-docx-browser.mjs` ampliado e aprovado: home em seis larguras, grid com 1/2/3 colunas, dropdown com Enter/Escape, foco restaurado e ausência de overflow com o painel aberto; DOCX: envio, polling, resultado parcial, PDF, ZIP, exclusão e seis larguras, sem erros de runtime. Os testes usam APIs e documentos sintéticos; não executam conversão Linux real.

## 26. Arquivos alterados

- `feitoo-web/src/app/app.{ts,html,scss,spec.ts}`: navegação e footer.
- `feitoo-web/src/app/features/public/home{.ts,.html,.scss,.spec.ts,-tools.ts}`: catálogo, busca, composição e testes.
- `feitoo-web/src/app/features/public/tool-card.{html,scss}`: microprodutos.
- `feitoo-web/src/app/core/{public-pages.ts,tool-faqs.ts,public-launch.spec.ts}`: SEO, FAQ e expectativa do novo H1.
- `feitoo-web/src/app/features/processing/pages/docx-to-pdf.{html,spec.ts}`: conteúdo e FAQ.
- `feitoo-web/scripts/{check-tool-browser.mjs,check-docx-browser.mjs,home-docx-seo.test.mjs}`: smokes e validação de HTML.
- `feitoo-api/app/Services/Documents/LibreOfficeDocxToPdfConverter.php`: inclusão necessária de `/etc/libreoffice` na lista de binds read-only, ausente no checkout local.
- Este relatório.

## 27. Riscos conhecidos

Avisos de orçamento CSS descritos acima. A renderização real LibreOffice/Bubblewrap/prlimit depende do Linux e não é homologada por simulações no Windows. Mudança backend limitada ao bind read-only; `/lib`, `/lib64`, `/etc/fonts` e `/etc/ld.so.cache` preservados. Fila documents, jobs, downloads e histórico não tiveram arquitetura alterada. Nenhum arquivo do Supervisor foi tocado.

## 28. Pendências

Nenhuma publicação realizada, conforme solicitado. Eventual publicação e conferência HTTP no ambiente publicado ficam fora desta tarefa. Artefato pré-existente `feitoo-web/public/og/Termo de Recebimento.pdf` preservado e excluído do build pelas regras existentes. Configuração pública gerada para testes é retornada ao estado local após validar o build de produção.
