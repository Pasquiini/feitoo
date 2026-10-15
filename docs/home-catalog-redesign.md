# Home Feitoo — central de ferramentas

Redesign implementado em 14/10/2026, somente no frontend.

## Conceito e estrutura

Catálogo como entrada do produto: hero curto, busca destacada, duas ferramentas reais em cards inteiramente clicáveis, três passos, segurança compacta, Free/Avulso/Pro, atalhos finais e footer agrupado. Tokens existentes, verde como ação, superfícies claras, SVGs locais e nenhuma dependência nova.

Busca client-side por nome, descrição, benefício, categoria e palavras-chave. Ignora caixa, acentos e espaços externos; combina termos. Busca vazia mostra tudo; resultado vazio oferece recuperação. A categoria XML Fiscal é apenas informativa, sem filtros redundantes.

Cards em duas colunas a partir de 768px; uma coluna abaixo disso. Planos seguem o mesmo breakpoint. Header mantém menu mobile, Escape, sessão e logout existentes. Footer usa quatro grupos no desktop e dois no mobile. Foco visível, anúncios da busca, links semânticos e reduced-motion preservados.

## Arquivos criados

- `feitoo-web/src/app/features/public/home-tools.ts`
- `feitoo-web/src/app/features/public/tool-card.ts`
- `feitoo-web/src/app/features/public/tool-card.html`
- `feitoo-web/src/app/features/public/tool-card.scss`
- Este relatório.

## Arquivos alterados

- `feitoo-web/src/app/features/public/home.ts`, `home.html`, `home.scss`, `home.spec.ts`
- `feitoo-web/src/app/features/public/home-icon.ts`
- `feitoo-web/src/app/features/public/home-plans.html`, `home-plans.scss` (também reutilizados em /planos)
- `feitoo-web/src/app/app.html`, `app.scss`, `app.spec.ts`
- `feitoo-web/src/app/core/public-launch.spec.ts`
- `feitoo-web/scripts/check-tool-browser.mjs`

HomePreview preservado, retirado apenas da composição da home. Rotas, AuthService e infraestrutura de SEO não foram modificados. Configuração gerada pelo build de produção restaurada ao valor original no código fonte; o artefato de produção validado mantém a origem https://feitoo.com.br.

## Validação

- `npm.cmd test -- --watch=false`: 157 testes, 13 arquivos, todos passaram.
- `npm.cmd run test:public`: 2 testes passaram.
- `FEITOO_BUILD_ENV=production npm run build`: sucesso; 8 rotas prerenderizadas; bundle inicial 338,43 kB, transferência estimada 95,28 kB; home lazy 16,77 kB.
- Verificação do HTML de 7 páginas públicas: title, description, canonical, robots index/follow, Open Graph e um h1. Home prerenderizada contém catálogo e busca; robots permite indexação; sitemap gerado pelo fluxo existente.
- `node scripts/check-tool-browser.mjs`: passou no Edge headless com respostas sintéticas locais; nenhuma chamada ao backend real. Home visitante e autenticada em 320/375/768/1024/1440px sem overflow; busca por excel/xml/produto/nota/nfe/conversão; empty state; limpar e restaurar foco; links; âncora de ferramentas; menu mobile e Escape; reduced-motion; Pro para conta e login com retorno. Fluxos existentes de ferramenta e conta também passaram, sem erros de runtime.
- Capturas e relatórios em `feitoo-web/dist/validation/`, incluindo `home-browser-report.json`, `home-1440.png` e `home-320.png`.
- `git diff --check`: passou.

Único warning de build restante: `xml-to-excel.scss` com 5,40 kB para budget de aviso de 4 kB. Arquivo preexistente, não alterado. Home e card estão dentro dos budgets; limites não foram aumentados.

## Fase 2

Adicionar filtros apenas quando houver novas categorias reais; ampliar sinônimos conforme buscas observadas; conectar o catálogo tipado aos outros atalhos compartilhados quando seu volume justificar. Nenhuma publicação, alteração no backend, Stripe, OAuth, VPS ou banco foi feita.
