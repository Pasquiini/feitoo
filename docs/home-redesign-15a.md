# Feitoo — etapa 15A: redesign da home

## Auditoria

Auditoria apresentada antes das edições. A home anterior tinha hero apenas textual, uma mensagem pouco específica, dois cards empilhados no desktop e instruções centradas no Excel. Produtos dos XMLs tinha menos destaque. Faltavam benefícios e apresentação de Free/Avulso/Pro. A orientação de login também aparecia para usuários autenticados.

Header e footer eram compactos, mas faltavam Planos e navegação mobile dedicada. Os destinos das ferramentas já estavam corretos. Os tokens existentes fornecem verde principal, verde de apoio, superfícies claras, bordas, radius e sombras leves; foram preservados. Os componentes existentes de processamento, autenticação, conta e cookies foram revisados e mantidos.

Proposta aplicada: hero com visual ilustrativo → duas ferramentas → três passos → quatro benefícios → segurança → três caminhos comerciais → CTA final. Sem aparência de ERP, dashboard ou landing page de IA.

## Hero

Headline: “Resolva tarefas com XML em segundos.” Subheadline informa conversão de NF-es, consolidação e download no navegador. CTAs: Usar XML para Excel e Ver ferramentas. Um único selo: Grátis para começar.

Mini preview construído com HTML/CSS/SVG. Inclui fluxo de entrada/resultado, métricas, três linhas ilustrativas e indicações Excel/PDF. A legenda deixa claro que é um exemplo, sem sugerir dados reais da conta. Não carrega imagens, fontes, bibliotecas ou serviços externos.

O conteúdo principal é igual para ambos os estados de sessão. Apenas a nota de apoio e o rótulo do CTA Pro se adaptam; usuários autenticados não recebem convite irrelevante para entrar/cadastrar.

## Ferramentas

XML para Excel e Produtos dos XMLs recebem o mesmo destaque em dois cards. Cada card contém SVG, título, descrição curta, três chips e link com nome acessível específico. PDF aparece somente em Produtos dos XMLs. Cards empilham no mobile.

Hover/foco alteram borda e sombra, com elevação de dois pixels. Movimento reduzido elimina transição e deslocamento. Links continuam sendo links nativos com RouterLink, funcionando com teclado e abertura em outra aba.

## Como funciona

Três passos com ícones: Envie seus arquivos; O Feitoo processa; Baixe o resultado. Textos de uma frase e saída condicionada à disponibilidade da ferramenta. No mobile, ícones e textos formam uma sequência vertical.

## Benefícios

Rápido, Privado, Sem instalação e Resultado pronto. O texto de velocidade reconhece que o tempo varia conforme o lote. Não há garantia de tempo, armazenamento permanente ou resultado fiscal validado.

## Planos

Três cards, sem tabela de preços ou checkout:

- Free: 20 XMLs/processamento, 50/dia, sem cartão para começar.
- Avulso: pagamento único, sem assinatura, escolhido no fluxo de envio.
- Pro: 250 XMLs/processamento, 500/dia, retenção de 24 horas.

Limites exibidos são os existentes e os pedidos para esta etapa. Nota esclarece que o limite diário é separado por ferramenta e orienta conferir valores antes do pagamento. Free aponta às ferramentas; Avulso ao envio; Pro à conta. O guard já existente encaminha visitantes ao login com retorno para `/conta`. A home não consulta Stripe, entitlements ou preços e não inicia pagamento.

## Segurança

Bloco “Seus arquivos são seus.” explica uso apenas no processamento solicitado e exclusão automática conforme o prazo da conta. Um link leva à página de segurança. Não reproduz texto jurídico.

## Header

Logo, Ferramentas, Segurança, Planos e Entrar/Minha conta. Menu mobile com três links e botão de 44 px. Usa aria-expanded e aria-controls; fecha na seleção de um link e com Escape, devolvendo o foco ao botão. Acesso à conta permanece visível. O contexto de retorno do login em páginas de ferramentas foi preservado.

## Footer

Identidade Feitoo, frase curta, copyright e links para Ferramentas, Segurança, Privacidade, Termos e Minha conta. Preferências de cookies e contato configurado continuam disponíveis. Organização compacta, com alvos de toque de pelo menos 44 px nos links/controle.

## Responsividade

Validação em 320, 375, 768, 1024 e 1440 px, para visitantes e usuários autenticados. Sem overflow horizontal da página. Hero em duas colunas a partir de 768 e empilhado abaixo; ferramentas e planos empilham abaixo de 768. Benefícios usam duas colunas no mobile.

Capturas completas incluem hero, cards, planos, segurança, CTA final e footer. Há capturas adicionais do menu aberto em 320 e 375. Inspeção visual realizada em mobile, tablet e desktop. Uma regra antiga que fixava a largura da navegação em 320 foi sobrescrita apenas no menu novo para respeitar suas margens.

Evidências em `feitoo-web/dist/validation/`:

- `home-320.png`, `home-375.png`, `home-768.png`, `home-1024.png`, `home-1440.png`.
- `home-authenticated-320.png`, `home-authenticated-375.png`, `home-authenticated-768.png`, `home-authenticated-1024.png`, `home-authenticated-1440.png`.
- `home-menu-320.png`, `home-menu-375.png`.
- `home-browser-report.json`.

## SEO

Title: “Feitoo — Ferramentas online para XML e arquivos”. Description: “Converta XML de NF-e para Excel, consolide produtos e resolva tarefas com arquivos direto no navegador.”

SeoService, canonical, política de indexação, origem configurada, sitemap e prerender continuam com a mesma lógica. O ambiente local sem domínio mantém noindex/robots bloqueado. O schema WebSite foi preservado: a home já listava as duas ferramentas antes; não foram introduzidos inventário novo, avaliações, preços ou ratings no structured data.

## Analytics

Mantidos os eventos existentes, consentimento e payload permitido. Nenhum evento novo foi necessário. Links usam a navegação existente e page_view segue a política atual. Nenhum dado pessoal é enviado pela apresentação da home.

## Testes

- `npm test -- --watch=false`: 156 testes, 13 arquivos, todos passaram.
- `npm run test:public`: 2 testes passaram.
- `npm run build`: passou; sete rotas prerenderizadas. Home carregada sob demanda, aproximadamente 20,1 kB brutos / 4,85 kB de transferência estimada. Sem dependências novas e sem aviso de orçamento nos novos componentes.
- `node scripts/check-tool-browser.mjs`: Edge headless, build de produção com API sintética. Cobre as cinco larguras/ambos os estados, ferramentas e planos, login com retorno, âncora de ferramentas, conta, menu, Escape com foco e prefers-reduced-motion. Também conserva a regressão existente de conta, upload, previews e downloads Excel/PDF.
- Testes novos da home cobrem hero, ferramentas, chips/saídas, destinos, segurança, limites comerciais, estados de sessão e CTA principal.
- Testes do App cobrem header/footer, menu, fechamento e apresentação autenticada. Teste de lançamento público ajustado à nova headline e aos cards.

## Arquivos modificados

- `feitoo-web/src/app/features/public/home.ts`.
- `feitoo-web/src/app/app.html`, `app.ts`, `app.scss`, `app.spec.ts`.
- `feitoo-web/src/app/core/public-pages.ts`, `public-launch.spec.ts`.
- `feitoo-web/scripts/check-tool-browser.mjs`.

Criados:

- `feitoo-web/src/app/features/public/home.html`, `home.scss`, `home.spec.ts`, `home-icon.ts`.
- `feitoo-web/src/app/features/public/home-preview.ts`, `home-preview.html`, `home-preview.scss`.
- `feitoo-web/src/app/features/public/home-plans.ts`, `home-plans.html`, `home-plans.scss`.
- `docs/home-redesign-15a.md`.

SVGs: XML, planilha, produto, PDF, escudo, cronômetro, navegador, upload, download, check e seta. Traço/grade seguem a ferramenta Produtos dos XMLs. São decorativos e ocultos de leitores de tela; os rótulos visíveis comunicam as ações.

## Limitações

- Capturas e fluxo de navegador usam dados sintéticos; não exercitam OAuth/Stripe externos nem contas reais.
- A amostra visual é ilustrativa. Não consulta dados fiscais ou histórico do visitante.
- A descrição comercial apresenta os limites especificados para esta etapa; a validação efetiva continua nos fluxos e serviços existentes.
- O build mantém o aviso anterior de CSS de `xml-to-excel.scss` (5,4 kB frente ao aviso de 4 kB). Essa ferramenta e seu orçamento não foram alterados nesta etapa.
- Não houve publicação/deploy. Backend, ferramentas, auth, Stripe, storage, queue, preços e entitlements ficaram fora das alterações.
