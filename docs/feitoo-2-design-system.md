# Feitoo 2.0 — design system

Proposta e consolidação incremental, 27/10/2026. Fonte de verdade executável: `feitoo-web/src/styles.scss`. Preservar verde Feitoo, tipografia de sistema, fundo claro e superfícies com borda discreta. Não criar um segundo sistema Bootstrap nem substituir os componentes de processamento.

| Elemento               | Regra e implementação                                                                                                                                                                                                 |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tipografia             | system-ui/Segoe UI; corpo 16px, line-height 1,65; apoio de instruções 14px; h1 fluido, h2 23–32px, título de seção de editor 19px. Valores monetários legíveis e com unidade                                          |
| Cores                  | primary #125d54, hover #0b473f, support #e5f7ce; text #243631, muted #57665f; surface #fff, subtle #f0f5ee, border #d7e2d9; danger #a72e2e, warning #76520c. Estado também contém texto                               |
| Espaçamento            | tokens space-1…5: 4,8,16,24,32px. Aplicar 16–24px em grupos; 32–56px entre seções públicas                                                                                                                            |
| Raios                  | sm 8px, md 12px, lg 20px; usar lg somente para workspace/painel de tarefa, não para cada linha                                                                                                                        |
| Botões                 | `.btn` com variantes primary, outline, ghost, danger; mínimo 44px, texto com verbo e objeto; disabled real durante operação; foco visível; sem ícone como única indicação                                             |
| Inputs/select/textarea | `.form-control`; label associado, aria-describedby para dica/erro, aria-invalid coerente; fonte herdada; mínimo 48px para input; maxlength e inputmode adequados. Foco explícito ampliado a select e textarea         |
| Upload                 | Reutilizar `ToolUploader`, input de arquivo nativo, botão acessível, tipos/limites reais da API, drag/drop com alternativa de teclado; logotipo segue validação existente                                             |
| Cards                  | `HomeToolCard` para descoberta, painéis somente para grupos de tarefa; sem gradientes. Usar lista/tabela para dados repetidos                                                                                         |
| Etapas/tabs            | Quatro botões com aria-current=step, destino anunciado e foco no título após navegação. Alternância mobile editor/prévia continua com aria-pressed; não anunciar role=tab sem implementar semântica e teclado de tabs |
| Badges                 | Rótulos curtos (Free/Pro/status), sem inferir sucesso pelo verde. Unidades de crédito sempre identificadas                                                                                                            |
| Confirmação/modal      | Orçamento conserva confirmação inline focada, resumo de fonte/consumo e Voltar à edição. Não atribuir role=dialog a uma seção sem focus trap; manter edição inert durante confirmação                                 |
| Toasts/notificações    | Preferir `.feedback` inline no contexto da ação, role=status para sucesso/loading e alert para erro; não ocultar erro financeiro em toast que desaparece. Não há necessidade de novo serviço de toast nesta etapa     |
| Skeleton/loading       | Usar texto de consulta/aria-busy já existente, sem exibir zero provisório ou progresso simulado. Skeleton só quando houver geometria fixa, aria-hidden e status textual; não foi introduzida animação decorativa      |
| Tabelas                | Preservar tabelas semânticas e paginação das ferramentas; scroll horizontal restrito ao contêiner quando necessário, cabeçalhos e unidades visíveis; não colapsar tabela em blocos sem contexto                       |
| Empty/error            | Estado explica ausência/causa, oferece próximo passo seguro (ver ferramentas, consultar novamente, editar); não mistura indisponibilidade de saldo com saldo zero                                                     |
| Mobile                 | Catálogo de uma coluna; editor alterna com prévia abaixo de 900px; etapas compactas sem overflow; créditos em popover limitado à viewport; conta tem navegação local com quebra de linha                              |
| Navegação              | Skip link, main único, menus details nativos com Escape e retorno de foco. Saldo carregado somente após renderização no navegador e autenticação                                                                      |

## Reutilização e limites

Componentes novos: `NavbarCredits` e `CreditBalanceStore`. Editor, prévia, seleção de modelos, itens, uploader, status, rascunhos, compra, login e histórico continuam nos componentes atuais. Nenhuma biblioteca de UI ou fonte remota foi adicionada.

A consolidação não significa que todos os valores CSS locais foram eliminados nem que toda a plataforma passou por certificação WCAG. As folhas existentes das ferramentas preservam suas regras; expandir tokens nelas exige regressão específica. Ver evidências e limitações em `feitoo-2-entrega.md`.
