# Refinamento visual — etapa 09

## Auditoria e mudanças

Login e cadastro tinham Google após o CTA, formulário sem mostrar senha, feedback genérico e loading sem contexto. A ferramenta não distinguia drag-over e seleção; resultado e exclusão tinham pouca hierarquia. Cores e medidas eram repetidas e o header precisava se acomodar a 320 px.

Mantivemos a identidade verde, fontes do sistema e o fluxo existente. Tokens globais pequenos organizam cores, spacing, radius, sombras e transições. Botões têm estilos primary, secondary, ghost e danger; inputs e feedback seguem o mesmo padrão. Não há nova biblioteca, fonte remota ou ícone Google improvisado.

Login/cadastro apresentam Google primeiro, divisor, labels, placeholders, mostrar/ocultar senha, erros por campo e loading contextual. No desktop, uma área de valor acompanha o formulário. Abaixo de 768 px, essa área sai e o formulário usa uma coluna. Confirmação e mínimo de 12 caracteres continuam seguindo os requisitos existentes. O formulário fica desabilitado durante envio. Cadastro mantém somente os quatro campos existentes.

O contexto `/xml-para-excel` é o único destino aceito para retorno: não leva dados de arquivos ou credenciais na URL. CTA da ferramenta e link Entrar do header preservam esse destino. Seleção permanece em memória. Google continua exigindo nova seleção após o redirect externo.

Upload mostra vazio, hover/foco, drag-over, seleção e erro. Durante envio/processamento, a área interativa dá lugar ao feedback de andamento e os controles de seleção ficam desabilitados. Não inventamos percentual: os contadores existentes informam o progresso. O resultado usa card de sucesso com download destacado; falhas parciais permanecem visíveis e não bloqueiam download. Exclusão é secundária, identificada como ação destrutiva pelo texto e estilo.

## Validação

```powershell
Set-Location D:\backup\Projetos\feitoo\feitoo-web
npm.cmd test -- --watch=false
npm.cmd run build
npx.cmd prettier --check src/app src/styles.scss scripts/check-tool-browser.mjs
node scripts/check-tool-browser.mjs
```

O script usa API e conta sintéticas e bloqueia destinos de analytics. Verifica home, login contextual, cadastro, páginas públicas e 404 em 320, 375, 768, 1024 e 1440 px. A ferramenta também é verificada nesses tamanhos e em 390 px, com seletor acima da dobra, sem overflow e foco visível.

Os cenários incluem validação local, erro de credenciais, loading com formulário desabilitado, mostrar/ocultar senha, retorno com seleção em memória, processamento, resultado parcial e exclusão. Capturas de home/login/cadastro por largura, login-loading, login-error e resultado mobile ficam em `feitoo-web/dist/validation`, fora do Git. Capturas foram revisadas visualmente.

Foco visível, associação de erros aos inputs, labels, autocomplete, botões reais e mensagens textuais foram preservados. Transições respeitam `prefers-reduced-motion`. Cadastro pode precisar de rolagem em telas pequenas; nada é cortado por altura fixa. A validação mobile usa emulação no Edge; teclado virtual físico, leitores de tela e Safari/iOS ainda precisam de avaliação em aparelhos reais. O OAuth externo não foi exercitado com conta real nesta etapa.

Backend, parser, queue, storage, processamento, analytics e estrutura SEO não foram modificados. Não houve deploy.

Resultado final: 60 testes frontend passando, build com seis páginas prerenderizadas e bundle inicial de 318,31 kB, abaixo do budget existente de 500 kB, sem warnings. Prettier aprovado. Nenhuma dependência foi adicionada.
