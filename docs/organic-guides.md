# Guias de aquisição orgânica do Feitoo

Implementação de duas páginas editoriais públicas, sem CMS, novas ferramentas ou dependências. Conteúdo baseado nos geradores de Excel e nas regras de consolidação do backend existente.

## Guia: conversão de XML de NF-e

- Rota: `/como-converter-xml-nfe-para-excel`
- Title: Como Converter XML NF-e para Excel Online | Feitoo
- Description: Veja como converter arquivos XML de NF-e para Excel e transformar notas fiscais em uma planilha organizada de forma rápida e online.
- H1: Como converter XML NF-e para Excel
- Canonical de produção: `https://feitoo.com.br/como-converter-xml-nfe-para-excel`

Introdução sobre a estrutura do XML e a consulta em colunas. Seções:

1. Por que converter XML de NF-e para Excel?
2. Como converter XML para Excel passo a passo
3. Quais informações podem ser extraídas?
4. Posso converter vários XMLs de uma vez?
5. É seguro enviar XMLs de NF-e?
6. XML para Excel funciona com qualquer XML?
7. Quando usar XML para Excel?
8. Converter XML para Excel online ou usar programa instalado?
9. Experimente converter seus XMLs

O guia descreve as abas Notas e Itens, campos confirmados no código, datas em UTC e offset original. Explica NF-e modelo 55, falhas parciais, limites variáveis, retenção e os cuidados ao interpretar resultados. Não promete conversão de NFC-e, CT-e ou qualquer XML.

## Guia: extração de produtos

- Rota: `/como-extrair-produtos-de-xml-nfe`
- Title: Como Extrair Produtos de XML NF-e para Excel | Feitoo
- Description: Aprenda a extrair produtos de vários XMLs de NF-e, consolidar itens, somar quantidades e gerar uma planilha organizada.
- H1: Como extrair produtos de XMLs NF-e
- Canonical de produção: `https://feitoo.com.br/como-extrair-produtos-de-xml-nfe`

Introdução sobre produtos distribuídos entre notas. Seções:

1. Por que extrair produtos dos XMLs?
2. Como extrair produtos dos XMLs passo a passo
3. Como funciona a consolidação dos produtos?
4. O que aparece na planilha?
5. É possível somar quantidades de vários XMLs?
6. Posso exportar os resultados?
7. Quando essa ferramenta é útil?
8. Privacidade dos XMLs
9. Extraia os produtos dos seus XMLs

O texto descreve agrupamento por emitente, código ou descrição, NCM e unidade; normalização de espaços e maiúsculas; soma das quantidades e valores; cálculo da média e ausência de conversão de unidades. Descreve as colunas das abas Produtos e Itens e distingue Excel completo de PDF com amostra. O exemplo de soma é ilustrativo, não uma estatística sobre usuários.

## UX e links

- Componente standalone carregado sob demanda; layout de leitura com largura máxima de 48rem, estilos do produto e ajuste para telas pequenas.
- Um H1 e nove H2 por guia; índice curto com navegação SPA e fragmentos que permanecem na rota correta.
- Breadcrumb visível: Feitoo → ferramenta → guia.
- Ambos os guias apontam para XML para Excel, Produtos dos XMLs, segurança e planos. CTAs de início e fim levam à ferramenta correspondente.
- XML para Excel aponta para o guia com “Veja como converter XML NF-e para Excel”.
- Produtos dos XMLs aponta para o guia com “Veja como extrair produtos de XMLs NF-e”.
- Links editoriais ficam abaixo da interface de processamento.

## SEO e dados estruturados

SeoService existente aplica title, description, canonical, robots e tags sociais.

- Article: headline, description, mainEntityOfPage, url, image, idioma pt-BR e publisher Organization Feitoo.
- BreadcrumbList: três níveis coerentes com a navegação visível.
- Sem FAQPage nos guias, autor pessoal, datas fictícias, avaliações ou estatísticas inventadas.
- `og:type=article`; `og:image` e `twitter:image` usam `https://feitoo.com.br/og/feitoo-og.png` em produção; Twitter Card permanece summary_large_image.
- Canonicals sem barra final. As duas rotas entram na regra 308 que normaliza a barra final.
- Sitemap gerado com nove páginas públicas; prerender com dez rotas, incluindo 404.
- Produção exige `VERCEL_ENV=production` ou `FEITOO_BUILD_ENV=production`, além da origem pública configurada. Fora do ambiente público, noindex e robots bloqueado continuam preservados.

## Validação realizada

- 169 testes frontend passaram em 15 arquivos.
- 3 testes públicos passaram: sitemap, bloqueio local e redirects/404.
- Build production com `FEITOO_BUILD_ENV=production` e `FEITOO_PUBLIC_ORIGIN=https://feitoo.com.br`: aprovado.
- Build local sem ambiente público: aprovado, mantendo noindex, sem canonical/schema públicos e robots Disallow: /.
- HTML prerenderizado validado para H1 único, title/description, canonical, indexação, JSON-LD, imagem OG/Twitter, breadcrumbs, links internos e índice.
- Testes de navegação entre os guias confirmam conteúdo, metadados e substituição do schema na SPA.
- Prettier e git diff --check: aprovados.

## Arquivos criados

- `feitoo-web/src/app/core/guide-pages.ts`
- `feitoo-web/src/app/features/public/guide-page.ts`
- `feitoo-web/src/app/features/public/guide-page.html`
- `feitoo-web/src/app/features/public/guide-page.scss`
- `feitoo-web/src/app/features/public/guide-page.spec.ts`
- `docs/organic-guides.md`

## Arquivos alterados

- `feitoo-web/src/app/app.routes.ts`
- `feitoo-web/src/app/app.routes.server.ts`
- `feitoo-web/src/app/core/public-pages.ts`
- `feitoo-web/src/app/core/seo.service.ts`
- `feitoo-web/src/app/features/processing/pages/xml-to-excel.html`
- `feitoo-web/scripts/generate-public-assets.mjs`
- `feitoo-web/scripts/public-assets.test.mjs`
- `feitoo-web/vercel.json`

## Pendências

- Publicar as alterações e confirmar HTTP 200, redirects 308 e sitemap atualizado no ambiente publicado.
- Após publicação, inspecionar as duas URLs no Search Console e acompanhar indexação e consultas. Esta sessão não acessou o Search Console nem solicitou indexação.
- Permanece o aviso anterior de orçamento de CSS em xml-to-excel.scss: 5,40 kB, acima do aviso de 4 kB; não bloqueia o build.
- A imagem OG existente foi reutilizada e suas dimensões atuais foram confirmadas em 1200×630.
