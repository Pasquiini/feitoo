# Minha conta — etapa 12C

## Auditoria

A revisão anterior às alterações identificou que o workspace já continha a hierarquia comercial, traduções dos estados de assinatura, senha recolhida, menu autenticado com inicial e oferta contextual na ferramenta. Os problemas restantes eram regras duplicadas no SCSS, competição visual entre plano e compras, compras em texto corrido e contagem de erros sem utilidade em processamentos totalmente concluídos. Foram preservadas as alterações existentes.

## Nova hierarquia

Minha conta → Seu plano → Uso hoje → Compras → Seus dados → Segurança e acesso → Processamentos recentes. Desktop apresenta plano e uso ao lado das compras, dados ao lado da segurança e histórico abaixo. Mobile usa uma coluna na mesma ordem de leitura. O subtítulo é “Gerencie seu plano, seus dados e seus processamentos.”

## Seu plano

O card principal tem superfície suave, nome do plano destacado, limites vindos do endpoint de usage e status textual da assinatura. Os estados existentes incluem Ativo, Cancelamento agendado, Pagamento pendente, Problema no pagamento e Cancelado. Pagamento pendente e problema de pagamento recebem destaque discreto.

## Uso

Contadores mostram XMLs usados, limite diário e quantidade restante. Progresso nativo tem nome acessível e descrição textual. Uso baixo permanece verde, próximo do limite recebe atenção e limite atingido recebe um estado próprio, sempre acompanhado de texto. Renovação informa meia-noite no horário de São Paulo.

## Pro

Free apresenta CTA destacado, preço e capacidades obtidos da oferta existente. Pro apresenta limites, retenção e gerenciamento pelo Customer Portal. Cancelamento agendado informa o fim do acesso; problema de pagamento usa “Atualizar pagamento”. Checkout, polling, preços, limites, entitlement e billing foram preservados. Não foi criada /planos: a oferta compacta na conta atende ao fluxo sem duplicar uma página comercial.

## Compras

Área secundária com ferramenta, data, pacote, quantidade enviada, valor e status em português. Mantidos paginação e link de retomada quando permitido pela API. Estado vazio ocupa pouco espaço.

## Perfil

Nome editável, e-mail somente leitura, data da conta e formas de acesso. Mantidos botão “Salvar nome”, validação e feedback “Nome atualizado.”

## Segurança

Google apresenta conexão e opção de vincular quando aplicável. Senha permanece recolhida em details, operável por teclado. Conta Google-only mostra a forma de entrada sem formulário de senha.

## Processamentos

Histórico abaixo dos dados, com ferramenta, data, XMLs, concluídos e erros somente quando existentes. Mantidos download, expiração, arquivos excluídos, paginação e confirmação de exclusão com tratamento de foco.

## Header

Revisado o header existente com inicial, primeiro nome, Minha conta e Sair. Preservado sem requisição adicional para badge Pro. A ferramenta já apresenta uso gratuito restante, compra avulsa e assinatura recorrente quando aplicável.

## Responsividade

Script de navegador gera 30 capturas: Free, Free próximo do limite, Free no limite, Pro com compra, cancelamento agendado e pagamento problemático com compra; cada cenário em 320, 375, 768, 1024 e 1440 px. Relatório e imagens ficam em feitoo-web/dist/validation, fora do Git. Verificação automatizada de overflow aprovada. Revisão visual inicial de Free desktop e pagamento mobile identificou e corrigiu espaçamento nos links de privacidade.

## Acessibilidade

Headings e ordem de leitura preservados; labels, e-mail readonly, mensagens de erro/status, progresso nativo, foco visível, controles reais e details para teclado. Status não depende exclusivamente de cor. Não houve validação com leitor de tela físico.

## Testes

131 testes frontend aprovados em 10 arquivos. Cobertura existente inclui Free, Pro, estados de assinatura, uso baixo/próximo/esgotado, senha fechada/aberta, Google-only, compras vazias/existentes, histórico, download e exclusão. Adicionados testes de ordem das seções e ausência de contagem de erros em resultado totalmente concluído. Build de produção aprovado, seis rotas prerenderizadas, bundle inicial de 327,41 kB. Script de navegador usa somente dados sintéticos, sem cobrança ou autenticação externa reais.

## Arquivos modificados

- feitoo-web/src/app/features/account/pages/account-page.html
- feitoo-web/src/app/features/account/pages/account-page.scss
- feitoo-web/src/app/features/account/pages/account-page.spec.ts
- feitoo-web/scripts/check-tool-browser.mjs
- docs/account-redesign.md

## Limitações

O endpoint do histórico não fornece total fiscal; por isso o histórico não exibe valor monetário inventado nem recebeu ampliação de contrato nesta etapa. O build mantém aviso de budget no SCSS da conta: 5,59 kB para um limite de aviso de 4 kB. O limite não foi ampliado. Testes mobile usam emulação Edge; Safari/iOS, teclado virtual e leitores de tela precisam de avaliação em aparelhos reais. Stripe Checkout e Portal não foram exercitados com pagamentos reais. Não houve deploy nem alteração de backend.
