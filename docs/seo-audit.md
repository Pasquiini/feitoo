# Auditoria SEO do Feitoo — 14/10/2026

## Escopo e resultado

Otimização das duas ferramentas existentes e da home. URLs e funcionalidades mantidas. Nenhuma nova dependência de produção, ferramenta futura, imagem ou avaliação inventada.

## Metadados finais conferidos no HTML prerenderizado

| Rota | Title | H1 único | Meta description |
| --- | --- | --- | --- |
| / | Feitoo &#124; Ferramentas online para XML, planilhas e arquivos | Ferramentas online para XML, planilhas e arquivos. | Ferramentas online rápidas para transformar XMLs, gerar planilhas e resolver tarefas com arquivos. Use direto no navegador com o Feitoo. |
| /xml-para-excel | XML para Excel Online &#124; Converter NF-e em Planilha &#124; Feitoo | Converter XML para Excel | Converta arquivos XML de NF-e para Excel online. Extraia dados das notas e gere uma planilha organizada em poucos passos. |
| /produtos-dos-xmls | Extrair Produtos de XML NF-e para Excel &#124; Feitoo | Extrair produtos de XMLs NF-e | Extraia e consolide produtos de vários XMLs de NF-e em uma planilha. Some quantidades e organize os itens automaticamente. |
| /planos | Planos Free, Avulso e Pro &#124; Feitoo | Planos Feitoo | Compare os planos Feitoo para processar seus XMLs. |
| /seguranca | Segurança dos seus arquivos &#124; Feitoo | Segurança sem promessas vazias | Entenda o armazenamento privado, o acesso autenticado e a exclusão dos arquivos enviados ao Feitoo. |
| /privacidade | Privacidade e cookies &#124; Feitoo | Privacidade e cookies | Saiba como o Feitoo utiliza dados da conta, arquivos enviados e cookies opcionais de medição. |
| /termos | Termos de uso &#124; Feitoo | Termos de uso | Condições de uso das ferramentas do Feitoo, processamento automatizado e responsabilidades sobre arquivos. |

Todas as sete páginas têm canonical absoluto em `https://feitoo.com.br`, sem query ou barra final (exceto a raiz `/`).

## Conteúdo e links

- H2 editoriais para funcionamento, extração/consolidação, resultado, uso, segurança e FAQ, abaixo da interface de processamento.
- Home mantém catálogo leve de duas ferramentas e links para ambas, com âncoras claras na chamada final.
- XML para Excel → Produtos dos XMLs: “extrair produtos de XMLs”.
- Produtos dos XMLs → XML para Excel: “converter XML para Excel”.
- Ambas → segurança: “segurança dos seus arquivos”; ambas → planos: “planos e limites de processamento”.
- Breadcrumb visível nas ferramentas com retorno à home.
- PDF descrito como resumo/amostra; o Excel é a exportação completa. Critérios de consolidação conferidos no backend existente.

## Dados estruturados e compartilhamento

- Home: WebSite, idioma pt-BR.
- Ferramentas: WebApplication, BreadcrumbList e FAQPage.
- Outras páginas públicas: WebPage.
- Perguntas e respostas visíveis em details/summary e schema usam `tool-faqs.ts`; o build verifica correspondência exata.
- Schema substituído a cada navegação SPA e removido de páginas privadas/404. Nenhum preço, review ou aggregateRating inventado.
- Open Graph: title, description, url, type, site_name e locale.
- Twitter Card: summary, title e description.
- Sem og:image/twitter:image: não existe imagem social pronta no repositório; gerar imagem ficou fora do escopo.

FAQPage é marcação semântica, sem expectativa de rich result. O Google encerrou os resultados enriquecidos de FAQ em maio de 2026: https://developers.google.com/search/updates#may-2026.

## Auditoria de produção antes do deploy

Consultas HTTP ao site publicado confirmaram:

- `/xml-para-excel`: 200, canonical correto, index,follow, Open Graph e H1 presentes.
- Uma rota inexistente: 404 real, sem fallback silencioso para home.
- `/xml-para-excel/`: 200, mesmo conteúdo da URL sem barra. Adicionado redirect 308 às seis páginas públicas com barra final, antes do filesystem; confirmar no deploy.
- robots.txt permite páginas públicas, restringe rotas privadas e aponta para sitemap do domínio correto.
- sitemap.xml contém exatamente as sete páginas públicas atuais.

O HTML publicado ainda é a versão anterior; os novos textos e redirecionamentos estão no workspace, aguardando publicação.

## Validação

- 166 testes Angular/Vitest passaram, em 14 arquivos.
- 3 testes de assets/routing públicos passaram.
- Build otimizado com `FEITOO_BUILD_ENV=production` e `FEITOO_PUBLIC_ORIGIN=https://feitoo.com.br`: aprovado, oito rotas prerenderizadas (sete públicas e 404).
- Validação automática de todas as sete páginas: um H1, description, Open Graph, Twitter Card, robots, canonical único, schema JSON, FAQ visível igual ao schema e links internos.
- Produção: index,follow; 404: noindex,follow, sem canonical.
- Build fora do ambiente público: noindex,follow, robots Disallow: /, sem canonical/schema; comportamento preservado.
- Prettier nos arquivos alterados e git diff --check: aprovados. Não há comando de lint no projeto.

## Riscos e pendências

- Confirmar os redirecionamentos 308 e o HTML atualizado após deploy; a regra segue a sintaxe de routes da Vercel: https://vercel.com/docs/project-configuration/vercel-json.
- Imagem OG 1200×630 pendente.
- Aviso de CSS já existente: xml-to-excel.scss com 5,40 kB, acima do orçamento de aviso de 4 kB; build aprovado.
- Configuração `FEITOO_PUBLIC_ORIGIN` sozinha não ativa indexação: é preciso ambiente de produção (`VERCEL_ENV=production` ou `FEITOO_BUILD_ENV=production`).
- Páginas de privacidade/termos ainda têm fallback de responsável pendente quando `FEITOO_RESPONSIBLE_NAME` não está preenchido. Conferir configuração pública; não foi inventado responsável.
- Sem acesso ao Search Console nesta sessão: não foram avaliados consultas, CTR, cobertura ou Core Web Vitals reais. Após publicar, inspecionar as três URLs prioritárias e acompanhar esses dados.
- A auditoria não promete posição no Google. FAQ e WebApplication não incluem avaliações ou valores fictícios para tentar obter destaque.

## Arquivos alterados

- feitoo-web/src/app/core/public-pages.ts
- feitoo-web/src/app/core/seo.service.ts
- feitoo-web/src/app/core/tool-faqs.ts (novo)
- feitoo-web/src/app/core/public-launch.spec.ts
- feitoo-web/src/app/features/public/home.html
- feitoo-web/src/app/features/processing/pages/xml-to-excel.ts
- feitoo-web/src/app/features/processing/pages/xml-to-excel.html
- feitoo-web/src/app/features/processing/pages/products-from-xml.spec.ts
- feitoo-web/scripts/generate-public-assets.mjs
- feitoo-web/scripts/public-assets.test.mjs
- feitoo-web/vercel.json
- docs/seo-audit.md (este relatório)
