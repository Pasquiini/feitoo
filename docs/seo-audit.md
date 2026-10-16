# Auditoria SEO do Feitoo — 16/10/2026

## Escopo e diagnóstico

Frontend Angular 21 e entrega estática na Vercel. Auditoria HTTP real das 12 páginas públicas, três rotas de conta, sitemap, robots, URL inexistente e variantes de URL. Evidências em `feitoo-web/dist/seo-audit/` (arquivos locais ignorados pelo Git). Nenhum deploy realizado. Backend, pagamentos e regras de plano preservados.

Já estavam corretos: prerender das 12 páginas públicas e 404, rotas lazy, canonical sem query, configuração de indexação por ambiente, H1 único, conteúdo explicativo e FAQs estáticas, breadcrumbs e dois guias educativos. Essas funcionalidades foram preservadas.

## Produção antes das correções

- As 12 páginas públicas retornaram HTTP 200 diretamente, com conteúdo sem JavaScript, H1 único, title, description, canonical próprio e index,follow. Sitemap lista as mesmas 12 URLs; robots permite essas páginas.
- `/seo-inexistente`: 404 real, noindex,follow, sem canonical.
- `/xml-para-excel/`: 308 para a versão sem barra, regra anterior ao filesystem já correta.
- `/index.html` e `/xml-para-excel/index.html`: 200, duplicatas com canonical para a URL preferida. Adicionadas regras 308 para home e todas as páginas públicas.
- **Problema prioritário:** `/login`, `/cadastro` e `/conta` entregam o HTML prerenderizado da home com index,follow e canonical da raiz. O JavaScript troca depois, mas o HTML inicial é incorreto. Corrigido rewrite para `index.csr.html`, shell existente com noindex,follow, sem canonical/schema e sem conteúdo da home. Adicionado X-Robots-Tag: noindex, follow.
- Robots bloqueava conta/login/cadastro/404, impedindo leitura do noindex. Removidos esses bloqueios; mantidos `/api/` e `/sanctum/`. Permitir rastreamento para ler noindex não substitui autenticação: guards e API continuam protegendo dados.

O filesystem da Vercel resolve os diretórios públicos para seu index.html. A produção identifica Server: Vercel e entrega conteúdo específico por rota. O fallback desconhecido permanece 404. As novas regras precisam de confirmação após deploy autorizado.

## Diagnóstico de cada página e palavras-chave

Intenções baseadas na funcionalidade; sem estimativas de volume de busca ou dificuldade.

| Rota | Palavra-chave principal | Secundárias / intenção | Diagnóstico e decisão |
| --- | --- | --- | --- |
| / | ferramentas para XML NF-e | ferramentas fiscais online, Excel, DANFE | Catálogo de cinco ferramentas preservado; corrigidos anúncio de duas e introdução limitada a Excel/produtos. |
| /xml-para-excel | XML para Excel | converter NF-e em planilha, notas e itens | Metadados e explicação adequados preservados; novo link contextual para análise. |
| /produtos-dos-xmls | extrair produtos de XML NF-e | consolidar produtos, NCM, quantidades | Preservadas regras de agrupamento e unidades; novo link para análise. |
| /xml-para-pdf | XML para PDF | gerar DANFE online, NF-e modelo 55 | Title explicita NF-e; protocolo e limites de verificação preservados; link para Excel existente. |
| /organizar-xml | organizador de XML | separar por CNPJ, fornecedor, mês, ZIP | Title mais direto; link contextual para análise; organização não equivale a validação fiscal. |
| /analisar-xml | analisador de XML NF-e | dashboard, valores, emitentes, tributos, Excel | Title explicita dashboard; description usa emitentes em vez de pressupor fornecedores. Corrigido relat?rio no catálogo. |
| /planos | planos Feitoo | limites de processamento | Indexável; comparação e regras preservadas. |
| /seguranca | segurança dos arquivos Feitoo | armazenamento privado, retenção, exclusão | Conteúdo de confiança preservado. |
| /privacidade | privacidade Feitoo | dados pessoais, cookies | Indexável; conferir responsável configurável. |
| /termos | termos de uso Feitoo | responsabilidades, processamento | Indexável; conteúdo preservado. |
| /como-converter-xml-nfe-para-excel | como converter XML NF-e para Excel | abas Notas e Itens, formatos | Guia educativo existente preservado, conectado à ferramenta. |
| /como-extrair-produtos-de-xml-nfe | como extrair produtos de XML NF-e | agrupamento, unidade, preços | Guia existente explica consolidação e limitações. |
| /login, /cadastro, /conta | não se aplica | autenticação e conta | Fora do sitemap; corrigidos shell e cabeçalho noindex. |
| /404 e inexistentes | não se aplica | recuperação de navegação | Noindex, sem canonical; fallback inexistente retorna 404. |

## Conteúdo e links internos

Ferramentas têm H1 único e H2 para modo de uso, dados extraídos, limites funcionais, segurança e perguntas frequentes. H3 são usados onde há subdivisões reais; não foram adicionados só para palavras-chave. Conteúdo e FAQs estão no HTML estático. Nenhuma promessa de processamento ilimitado ou validação oficial foi criada.

Links contextuais no corpo: Excel → Produtos e Analisador; PDF → Excel; Organizador → Analisador; Analisador → Produtos; Produtos → Excel e Analisador. Rodapé conecta as cinco ferramentas. Guias existentes conectam explicação e ação. Priorizar dúvidas reais do suporte/Search Console para atualizar esses guias antes de criar novos conteúdos. Não foram criadas páginas redundantes.

## Dados estruturados e compartilhamento

- Home: WebSite e Organization, apenas nome da marca e URL reais, sem identidade legal presumida.
- Cinco ferramentas: WebApplication, BreadcrumbList e FAQPage.
- Guias: Article e BreadcrumbList; institucionais: WebPage.
- FAQs visíveis/schema derivam de tool-faqs.ts e têm igualdade verificada no build. Schema é substituído na navegação e removido em páginas privadas/404.
- Open Graph/Twitter existentes preservados, com PNG 1200 × 630, title, description e URL próprios. A informação anterior de imagem ausente estava desatualizada.

Nenhuma avaliação, classificação, estatística ou oferta fictícia incluída. Schema não garante rich results; FAQ não deve ser tratado como promessa de destaque.

## Desempenho e responsividade

Fontes do sistema, sem downloads externos; ícones SVG inline; imagem OG é metadado e não LCP no corpo. Viewport, layouts responsivos e carregamento lazy das rotas preservados. Não foi adicionado lazy loading a conteúdo crítico.

Build inicial: 363,11 kB, transferência estimada pelo Angular 102,94 kB; chunk da ferramenta 110,71 kB; CSS inicial 8,60 kB. Aviso preexistente: SCSS da ferramenta 5,56 kB excede orçamento de aviso de 4 kB, sem erro de build.

Não foram medidos LCP, CLS e INP reais nem executado Lighthouse. Bundle e prerender não comprovam aprovação em Core Web Vitals. Acompanhar dados de campo por dispositivo no Search Console/PageSpeed antes de alterar funcionamento. Testes de navegador usam respostas sintéticas, sem credenciais ou documentos fiscais reais.

## Validação

- npm.cmd test -- --watch=false: **177 testes passaram em 18 arquivos**, incluindo autenticação, guards, conta e as cinco ferramentas.
- npm.cmd run test:public: **5 testes passaram**, cobrindo sitemap, staging, redirects, shell privado e cabeçalhos noindex.
- Build com FEITOO_BUILD_ENV=production: aprovado, **13 rotas prerenderizadas** (12 públicas + 404).
- Gerador valida HTML útil em main; H1/title/description/robots únicos; canonical exato; OG/Twitter; schema; igualdade FAQ visível; links contextuais; shell privado noindex sem canonical/schema/H1.
- Sem origem pública: comportamento noindex, sitemap vazio e robots bloqueado preservado e coberto por teste existente.
- Smoke Chrome: resultado complementar abaixo. Primeiras tentativas encontraram timeout CDP e expectativas antigas de duas ferramentas; atualizadas para as cinco atuais e o H1 existente.

## Pendências de produção e Google Search Console

1. Após deploy autorizado, conferir shell e X-Robots-Tag de conta sem JavaScript: sem canonical/schema da home. Validar login/cadastro e retorno à ferramenta.
2. Conferir 308 para index.html e cada ferramenta/index.html, sem ciclos; 200 das canônicas; 404 desconhecido; sitemap e robots atualizados.
3. Confirmar VERCEL_ENV=production e origem correta. Previews devem manter noindex. Host www e HTTP → HTTPS não foram consultados nesta rodada.
4. Conferir responsável de privacidade/termos na configuração pública; nenhuma identidade foi inventada.
5. Sem acesso ao Search Console: verificar propriedade, enviar sitemap, inspecionar cinco ferramentas com teste ao vivo, comparar canonical declarado/selecionado e HTML renderizado.
6. Monitorar motivos de exclusão, duplicatas/soft 404, consultas, impressões, cliques, CTR e Core Web Vitals por dispositivo. Solicitar indexação após validar deploy, excluindo conta/login.

Sitemap e HTTP 200 não demonstram indexação nem garantem posição no Google.

## Arquivos alterados

Em feitoo-web: vercel.json; src/app/core/public-pages.ts, seo.service.ts, public-launch.spec.ts; src/app/features/public/home.html, home-tools.ts; src/app/features/processing/pages/xml-to-excel.html; scripts/generate-public-assets.mjs, public-assets.test.mjs, check-tool-browser.mjs. Também este relatório. A alteração anterior em public/og/modal-clonagem-escalas.component.html foi preservada.

## Metadados finais

Extraídos do build de produção. Canonical = https://feitoo.com.br + rota, sem query ou barra final, exceto raiz.
| Rota | Title final | H1 | Meta description |
| --- | --- | --- | --- |
| / | Feitoo &#124; Ferramentas online para XML, planilhas e arquivos | Ferramentas online para XML, planilhas e arquivos. | Ferramentas online rápidas para transformar XMLs, gerar planilhas e resolver tarefas com arquivos. Use direto no navegador com o Feitoo. |
| /xml-para-excel | XML para Excel Online &#124; Converter NF-e em Planilha &#124; Feitoo | Converter XML para Excel | Converta arquivos XML de NF-e para Excel online. Extraia dados das notas e gere uma planilha organizada em poucos passos. |
| /xml-para-pdf | XML para PDF Online &#124; Gerar DANFE de NF-e &#124; Feitoo | Converter XML em PDF | Converta XML de nota fiscal em DANFE PDF online. Envie seu arquivo NF-e, visualize o documento e baixe o PDF. |
| /organizar-xml | Organizador de XML Online &#124; Notas por CNPJ e Mês &#124; Feitoo | Organizador de XMLs Online | Organize arquivos XML de notas fiscais automaticamente por fornecedor, CNPJ, mês ou ano. Envie seus XMLs e baixe tudo organizado em ZIP. |
| /analisar-xml | Analisador de XML NF-e Online &#124; Dashboard e Excel &#124; Feitoo | Analisador de XMLs Online | Analise vários XMLs de notas fiscais online. Visualize valores, emitentes, produtos e tributos em um dashboard e exporte relatórios para Excel. |
| /produtos-dos-xmls | Extrair Produtos de XML NF-e para Excel &#124; Feitoo | Extrair produtos de XMLs NF-e | Extraia e consolide produtos de vários XMLs de NF-e em uma planilha. Some quantidades e organize os itens automaticamente. |
| /planos | Planos Free, Avulso e Pro &#124; Feitoo | Planos Feitoo | Compare os planos Feitoo para processar seus XMLs. |
| /seguranca | Segurança dos seus arquivos &#124; Feitoo | Segurança sem promessas vazias | Entenda o armazenamento privado, o acesso autenticado e a exclusão dos arquivos enviados ao Feitoo. |
| /privacidade | Privacidade e cookies &#124; Feitoo | Privacidade e cookies | Saiba como o Feitoo utiliza dados da conta, arquivos enviados e cookies opcionais de medição. |
| /termos | Termos de uso &#124; Feitoo | Termos de uso | Condições de uso das ferramentas do Feitoo, processamento automatizado e responsabilidades sobre arquivos. |
| /como-converter-xml-nfe-para-excel | Como Converter XML NF-e para Excel Online &#124; Feitoo | Como converter XML NF-e para Excel | Veja como converter arquivos XML de NF-e para Excel e transformar notas fiscais em uma planilha organizada de forma rápida e online. |
| /como-extrair-produtos-de-xml-nfe | Como Extrair Produtos de XML NF-e para Excel &#124; Feitoo | Como extrair produtos de XMLs NF-e | Aprenda a extrair produtos de vários XMLs de NF-e, consolidar itens, somar quantidades e gerar uma planilha organizada. |


## Arquivos temporários no diretório público

Foram encontrados templates HTML, PDFs e ZIP não relacionados às ferramentas em public/og. A configuração de assets agora exclui og/**/*.html, og/**/*.pdf e og/**/*.zip do build, preservando os arquivos de origem e a imagem social PNG. Isso evita publicar e permitir indexação de artefatos temporários. angular.json e o teste de exclusão de assets também foram alterados. Após deploy, conferir que essas URLs não entregam os documentos antigos; se já conhecidas pelo Google, acompanhar remoção após 404.


## Resultado final dos smokes de navegador

- Smoke geral aprovado no Edge: login, erros de autenticação, retorno à ferramenta, processamento e resultado parcial, download, exclusão, perfil/senha, paginação/histórico, logout e Produtos dos XMLs. Sem erros de runtime. Páginas e estados testados entre 320 e 1440 px sem overflow horizontal do documento; tabelas mantêm rolagem interna.
- Smoke específico do Organizador aprovado: desktop 1440 px e mobile 390 px, opções, upload, resultado parcial e download ZIP.
- Relatórios e capturas em feitoo-web/dist/validation/: browser-report.json, account-browser-report.json, products-browser-report.json e artefatos do Organizador. Usam API sintética; não comprovam integração com backend ou OAuth reais.
- git diff --check aprovado após revisão. Build final confirma somente feitoo-og.png em dist/feitoo-web/browser/og; gerador também valida ausência dos formatos temporários.
