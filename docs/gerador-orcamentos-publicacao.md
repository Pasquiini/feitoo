# Gerador de Orçamentos — procedimento futuro de publicação e rollback

> Atualização de 27/10/2026: para a release pública do editor, Home e SEO, utilizar [o roteiro atual de lançamento](gerador-orcamentos-lancamento-publico.md). Este documento preserva o planejamento histórico da Fase 6; as instruções antigas para manter o gerador fora do catálogo/sitemap não descrevem mais o frontend desta release. A homologação de infraestrutura na VPS foi informada pelo responsável e não foi repetida remotamente nesta sessão. Stripe Live continua bloqueado.

**Preparado em 26/10/2026. Não executado. Não autoriza deploy.** Este roteiro depende do aceite das pendências de [homologação da Fase 6](gerador-orcamentos-fase-6.md). Os caminhos, usuários e nomes de serviço abaixo são parâmetros a preencher com a topologia aprovada, não dados de uma VPS inspecionada.

## 1. Pontos de parada obrigatórios

1. **Staging:** banco MySQL 8, Linux/bubblewrap/prlimit, storage, domínio, sessão, OAuth e Stripe TEST separados de produção. Nenhum teste deve usar API/DB/Stripe Live operacional.
2. **Aceite técnico:** migrations, constraints/FKs/uniques, concorrência Free/Pro/créditos, reembolso concorrente, PDF Linux, SIGKILL/recovery/purge e capacidade com evidências. Não substituir MySQL 8 por MariaDB/SQLite nem bubblewrap pelo modo PHP local.
3. **Aceite comercial:** política de usados/refund/disputa aprovada e mecanismo auditável implementado/testado. Resolver recuperação de pedido com Session não associada e definir atendimento/alertas.
4. **Publicação:** autorização operacional explícita para uma versão identificada, backup validado e rollback compatível. Não publicar automaticamente ao concluir testes.
5. **Vendas Live:** autorização separada, após as mudanças TEST-only/ambiente/preço/webhook da Fase 6. O código atual recusa Live para orçamentos.

Se qualquer ponto falhar, manter geração false e Price comercial sem configuração no destino. Não tocar nos workers `default`/`documents` para tentar corrigir a fila comercial.

## 2. Preparação de staging isolado

Preparar cópia de release própria, banco vazio descartável, usuário MySQL restrito a ele, volumes privados, APP_KEY independente, cache e sessão próprios, e par de hosts HTTPS sob um domínio de homologação. Usar contas/fixtures fictícias. Não copiar dados ou secrets de clientes.

O inventário deve registrar, sem imprimir secrets:

```sh
# SOMENTE no Linux isolado, após sua preparação/autorização
php -v
php -m
composer check-platform-reqs
command -v php
command -v bwrap
command -v prlimit
bwrap --version
prlimit --version
```

Conferir PHP CLI, GD/DOM/mbstring/fileinfo/PDO MySQL/XML/ZIP/OpenSSL, extensões adicionais exigidas pelo lock e pcntl para timeout do worker. Confirmar Dompdf pelo `composer.lock`, fontes DejaVu e seus arquivos reais. A existência dos binários não aprova namespaces, rede, limites ou permissões; executar os ensaios da seção 2 da Fase 6 como usuário do worker.

Configuração de staging a provisionar pelo mecanismo de secrets, sem colar credenciais no histórico:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<api-staging>
FRONTEND_URL=https://<frontend-staging>
DB_CONNECTION=mysql
DB_HOST=<mysql8-exclusivo-staging>
DB_DATABASE=<banco-exclusivo-staging>
DB_USERNAME=<usuario-restrito-staging>
# DB_PASSWORD e APP_KEY: secrets exclusivos, DB_URL ausente/vazio
CORS_ALLOWED_ORIGINS=https://<frontend-staging>
SANCTUM_STATEFUL_DOMAINS=<frontend-staging>,<api-staging>
SESSION_COOKIE=feitoo_quotation_staging_session
SESSION_DOMAIN=.<dominio-somente-staging>
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
QUOTATION_GENERATION_ENABLED=false
QUOTATION_SANDBOX=bubblewrap
QUOTATION_PHP_BINARY=/usr/bin/php
QUOTATION_BWRAP_BINARY=/usr/bin/bwrap
QUOTATION_PRLIMIT_BINARY=/usr/bin/prlimit
STRIPE_MODE=test
# STRIPE_SECRET_KEY / STRIPE_WEBHOOK_SECRET: exclusivos TEST
STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID=
```

Usar `APP_ENV=production` em staging para exercitar as proteções de produção. A flag permanece false até o ensaio controlado. O domínio de cookie deve permitir XSRF entre os dois hosts de staging e não alcançar hosts operacionais. Isolar também cache de rate limit/locks, storage, logs e callback Google. Conferir o valor efetivo de cada configuração sem `config:show` de secrets.

**Frontend de staging:** o build atual fixa API/CSRF de produção em `src/environments/environment.ts`. `FEITOO_PUBLIC_ORIGIN` só controla SEO. Antes de abrir o frontend de homologação, preparar em cópia isolada um `environment.staging.ts` com as URLs HTTPS de staging e uma configuração Angular `staging` com `fileReplacements` desse arquivo. Essa configuração é trabalho pendente; não existe hoje no projeto e não foi criada no ambiente de produção. Revisar/testar o replacement, inclusive ausência do host operacional nos bundles e nas requisições. Não substituir URLs dinamicamente no navegador publicado como procedimento operacional.

Após criar e revisar essa configuração na cópia de homologação:

```sh
# SOMENTE na cópia de staging; comandos propostos, não executados nesta fase
npm ci
FEITOO_BUILD_ENV=staging node scripts/configure-launch.mjs
npm exec ng -- build --configuration=production,staging
node scripts/generate-public-assets.mjs
```

Manter staging com noindex e acesso controlado. Confirmar no DevTools que login/CSRF/rascunhos/checkout não fazem requests a `api.feitoo.com.br`. Não usar o artefato de build de produção desta sessão em staging sem esse replacement.

## 3. Banco e ensaios obrigatórios de staging

Antes de qualquer migration, comprovar a identidade do servidor/banco descartável por conexão própria. Um operador deve conferir host, nome, permissões, ausência de dados operacionais e versão MySQL 8. Registrar:

```sql
-- SOMENTE na conexão exclusiva de homologação já validada
SELECT DATABASE(), VERSION(), @@datadir, @@sql_mode,
       @@transaction_isolation, @@explicit_defaults_for_timestamp;
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();
```

Na release de staging, com env exclusivo e nenhuma conexão cacheada operacional:

```sh
# SOMENTE staging; não executar no banco operacional durante esta fase
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate --force
php artisan migrate:status
```

Aplicar todas as migrations a banco vazio antes dos cenários; em cópia com schema anterior, conferir também upgrade aditivo. Capturar `SHOW CREATE TABLE` de todas as tabelas quotation, `jobs` e `stripe_webhook_events`, FKs/uniques/CHECKs, e provocar rejeições inválidas controladas. Testar idempotência/reembolso/assinatura com conexões independentes. Não usar a configuração SQLite do PHPUnit como homologação MySQL.

Os scripts de concorrência existentes têm raízes/datadir/portas restritos; o mapeamento Linux/container precisa ser preparado e revisado conforme seção 1 da Fase 6. **Não há comando universal pronto que possa ser apontado ao DB de staging sem essa adaptação.** Conservar todas as travas de destino descartável e verificar MySQL 8 antes de DDL. Descartar somente os recursos identificados da validação; nunca usar `migrate:fresh` operacional.

## 4. Worker, storage e scheduler

Conferir volume persistente compartilhado pela API e worker e sem acesso HTTP: `storage/app/private/quotation-drafts`, `quotation-generations`, `quotation-execution`. Criar diretórios comerciais com usuário/grupo específicos, diretórios 0700 e arquivos 0600, ou ACL equivalente revisada. Evitar chmod/chown recursivo do storage inteiro. Verificar write/read/delete pelo usuário real, proibir symlinks públicos e definir capacidade/retensão de backups.

Modelo a revisar para um serviço **novo e dedicado**, sem editar programas XML/DOCX:

```ini
; EXEMPLO: preencher todos os parâmetros antes de submeter à operação
[program:feitoo-quotations]
directory=<caminho-absoluto-release-api>
command=/usr/bin/php <caminho-absoluto-release-api>/artisan queue:work quotations --queue=quotations --sleep=1 --timeout=60 --tries=0 --memory=256 --max-time=3600
user=<usuario-aplicacao-sem-privilegios>
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=120
umask=0077
redirect_stderr=true
stdout_logfile=<storage-persistente>/logs/quotations-worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
```

O valor de memória do pai é envelope inicial de reciclagem, a aceitar após benchmark; não mede/limita o filho. Exigir timeout job 60 < lease 90 < retry_after 180; limite externo do renderer 35 s e prazo pendente 30 min. A fila `quotations` precisa usar database/jobs e a mesma conexão DB da transação; não mudar para Redis/sync nem `after_commit=true` sem revisar atomicidade. O storage do lock define um slot de render por host.

**Somente depois de autorização futura**, instalação/recarga deve atingir exclusivamente o novo programa. Modelos de comandos operacionais (não executados):

```sh
supervisorctl reread
supervisorctl update feitoo-quotations
supervisorctl status feitoo-quotations
```

`reread` deve mostrar apenas o programa aprovado como alterado; se aparecer outro serviço, interromper. Não usar `supervisorctl update` sem nome, `restart all` ou `php artisan queue:restart`, pois podem alcançar workers existentes.

Usar o agendador Laravel **já existente**, com uma única entrada cron por instalação. Conferir crontab e usuário antes de instalar qualquer entrada nova. Exemplo, somente se ainda não houver equivalente:

```cron
* * * * * cd <caminho-absoluto-release-api> && /usr/bin/php artisan schedule:run >> <storage-persistente>/logs/scheduler.log 2>&1
```

Configuração esperada do código: reconcile quotations a cada minuto; purge quotations drafts e processings por hora; expiração de compras fiscais mantém o agendamento atual. Verificar `withoutOverlapping` e cache de locks persistente/compartilhado conforme topologia. Não duplicar o scheduler das seis ferramentas.

Comandos de verificação, a executar futuramente em staging:

```sh
php artisan schedule:list
php artisan route:list --path=quotations
php artisan route:list --path=stripe
php artisan feitoo:reconcile-quotation-generations
php artisan feitoo:purge-quotation-drafts
php artisan feitoo:purge-expired-processings
```

Os três últimos comandos alteram dados/arquivos; somente executar depois de confirmar o destino descartável e criar fixtures de expiração/sentinelas. Verificar preservação das sentinelas XML/DOCX e dos registros financeiros.

## 5. Stripe TEST e aceite entre domínios

Seguir a criação/configuração do Price descrita na Fase 6. Nunca substituir a chave de uma instalação Live por TEST. A configuração TEST pertence exclusivamente ao staging. Registrar IDs reais somente depois de obtidos; nenhum ID fictício deste documento serve para configuração.

Com frontend/API HTTPS de staging e geração temporariamente habilitada **apenas nesse ambiente**, validar login/cadastro/OAuth, CSRF, ownership, CORS credentialed e leitura de Retry-After em 429. Validar Checkout TEST, retorno preservando rascunho/logo, cinco créditos concedidos uma vez, reentrega, cancelamento, falha, refund parcial/integral e disputa ganha/perdida. Abertura da página e parâmetro de sucesso nunca devem emitir ou conceder créditos automaticamente.

Guardar relatório por versão com IDs técnicos de teste, tempos/recursos, logs sanitizados e resultado de cada requisito; nenhuma credencial/payload pessoal. Executar a regressão completa em staging/cópia isolada. Só depois solicitar aprovação operacional da release.

## 6. Publicação futura com geração e vendas desligadas

**Não executar agora.** Em janela autorizada, preparar release nova a partir do pacote revisado e backup consistente com teste de recuperação. Este workspace contém trabalho não commitado; identificar/congelar o artefato aprovado sem sobrescrever as alterações locais ou presumir que HEAD contém todas as fases.

Conferir alvo e secrets antes dos comandos. Nunca executar `composer setup`/`composer update` como atalho: podem executar migrations ou alterar dependências. Na release nova, os comandos propostos são:

```sh
# EXCLUSIVAMENTE depois da aprovação da release, destino e janela
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan migrate:status
php artisan migrate --pretend
# PAUSA: revisar exatamente quais migrations serão aplicadas e o backup
php artisan migrate --force
php artisan migrate:status
php artisan config:cache
php artisan view:cache
php artisan route:list --path=quotations
php artisan schedule:list
```

Antes da ativação da release, fixar explicitamente `QUOTATION_GENERATION_ENABLED=false` e não configurar Price comercial no mecanismo atual. Preservar integralmente chaves/modo/webhook/preços fiscais e assinatura existentes. MySQL DDL não é uma transação de aplicação que possa ser desfeita com segurança em caso de falha: em erro de migration, interromper, inspecionar schema parcial e corrigir em revisão específica, sem rollback destrutivo automático.

Ativar backend pelo mecanismo de releases já autorizado da instalação, conferir health e seis ferramentas. Recarregar processo HTTP pelo procedimento existente e somente o worker comercial pelo programa dedicado; não reiniciar XML/DOCX como parte desta fase. O hostname, comando de troca de symlink e nome do serviço PHP-FPM não foram fornecidos/inspecionados: preencher esses passos na autorização, em vez de executar comandos genéricos em uma VPS.

Frontend, em ambiente de build da release aprovada:

```sh
npm ci
npm test -- --watch=false
FEITOO_BUILD_ENV=production npm run build
node --test scripts/public-assets.test.mjs scripts/home-docx-seo.test.mjs scripts/tools-experience-seo.test.mjs scripts/quotations-public.test.mjs
```

A publicação do artefato na hospedagem também exige autorização; nenhum comando de publicação foi executado ou agendado. Conferir rota limpa, 308, 404, noindex do gerador, catálogo com seis ferramentas, sessão/CSRF e resposta de indisponibilidade de geração sem reservar quota.

**Parada antes de habilitar:** as recusas TEST-only atuais tornam vendas Live impossíveis no domínio de orçamentos. A mudança futura aprovada deve trazer flag comercial independente, Price por ambiente, webhook/ledger/recuperação e testes descritos na Fase 6. Não remover proteção durante deploy para contornar erro. A liberação pública/descoberta/SEO e a habilitação Live precisam de aceite explícito próprio.

## 7. Observabilidade e recuperação

Definir responsável e destino dos alertas antes de liberar: idade/tamanho da fila quotations, pendências acima de 30 min, leases sem reconciliação, taxa de falha/timeout, CPU/RSS/disco, `quota_inconsistent`, eventos webhook em erro/retry, pedidos sem Session e atraso do purge. Nenhum desses painéis foi provisionado nesta fase.

Logar somente UUIDs técnicos, códigos e estados. Não registrar conteúdo do orçamento/logo/HTML, secrets, URL completa de Checkout ou corpo integral do webhook. Auditar proxy, APM, `failed_jobs`, logs do worker e backups além dos logs Laravel. Medir latência p50/p95 e impacto nas filas existentes; ajustar capacidade após ensaio, sem afrouxar sandbox.

Recuperação operacional deve usar status/token/lease e reconciliador idempotente, nunca ajuste manual de saldo. Não reenfileirar globalmente jobs fiscais ou compras. Pagamento com Session não associada exige confirmar o recurso remoto original e repetir a operação estável ou usar ferramenta de reconciliação aprovada; não criar uma nova cobrança para compensar incerteza.

## 8. Rollback futuro

Gatilhos: falha de isolamento, saldo inconsistente, duplicação de grant/consumo, erro de webhook persistente, falhas de geração acima do nível aceito ou impacto nas ferramentas existentes. Contenção proposta, após autorização:

1. Desabilitar novas emissões/retries pela flag e novas compras pelo Price comercial/flag própria quando existir. Preservar modo/chave/webhook e preços fiscais/assinatura. Retirar Price não cancela Sessions já abertas: continuar confirmação/concessão e atendimento de pagamentos tardios.
2. Recarregar configuração HTTP e o programa quotations de forma controlada. Com o mecanismo atual, jobs já aceitos não são cancelados pela flag: drenar com código compatível ou manter reconciliação até liberar/expirar cada reserva. Não parar o scheduler e não matar workers existentes.
3. Manter consultas, histórico, saldo, downloads privados vigentes, webhooks e ledger. Se necessário suspender execução do novo worker, permitir reconciliação das pendências e monitorar a fila; não usar `queue:clear`, retry global ou SQL para zerar reservas.
4. Voltar somente a uma release que entenda as três origens e schema atual. Se não houver release anterior compatível, manter contenção e corrigir para frente. Frontend pode voltar a artefato compatível que preserve acesso à conta/resultados, sem anunciar vendas indisponíveis.
5. Preservar migrations aditivas e APP_KEY. Não executar `migrate:rollback`, excluir pedidos/grants/eventos ou restaurar backup antigo sobre pagamentos novos. Backup é contingência de desastre com reconciliação financeira, não botão de reversão funcional.
6. Conferir zero novos pedidos/emissões após contenção, Sessions tardias processadas uma vez, soma financeira/contadores íntegra, reservas resolvidas, downloads válidos e seis ferramentas sem regressão. Documentar horário, versão, motivo e ajustes auditados.

**Estado deste procedimento: preparado para revisão. Execução de publicação, rollback e Stripe Live aguarda autorização operacional separada.**
