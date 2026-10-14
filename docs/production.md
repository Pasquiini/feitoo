# Feitoo — produção (etapa 16)

Preparação local, sem deploy, DNS, credenciais reais ou ativação LIVE. Os exemplos em [production/](production/) precisam ser revisados no servidor. O `.env` local existente não foi alterado.

## Arquitetura e domínios

Usuário → `https://feitoo.com.br` (Vercel, Angular estático/prerender) → `https://api.feitoo.com.br` (Hostinger VPS, Laravel) → MySQL, queue database, storage privado e scheduler. Suporte: `suporte@feitoo.com.br`. O backend não precisa de Node nem do Vite para servir a API. Não executar `composer setup` na VPS: inclui Node e migrations.

## Auditoria anterior às alterações

Produção tinha API/CSRF relativos; `.env.example` local com debug, cookies HTTP e remetente placeholder; defaults locais de CORS/Sanctum/OAuth; SEO sem domínio por padrão; ausência de Vercel, planos e imagem OG. Não foram encontrados console.log de runtime ou TODO/FIXME nos diretórios de aplicação inspecionados. Console e localhost em scripts de teste/dev são intencionais. Endereços loopback de banco/cache são corretos na VPS. `.env` é ignorado; nenhum segredo real foi encontrado na busca dos diretórios de aplicação/configuração/documentação (valores Stripe encontrados em testes são fixtures). Isso não constitui auditoria do histórico Git remoto.

Storage já privado, downloads autenticados, queue database e failed_jobs presentes. Purge já remove XML/resultados/previews e preserva registros técnicos. Webhook já valida assinatura e deduplica em transação sem sessão/CSRF. Stripe aceita TEST apenas. Analytics já depende de consentimento. Migration `2026_10_07_220000` remove `anonymous_token_hash` no `up`: revisar antes de aplicar sobre banco existente. `down` de outras migrations remove dados; rollback de schema nunca deve ser automático.

## DNS e HTTPS

Adicionar os domínios apex e www ao projeto Vercel e copiar os registros **exatos exibidos no Dashboard**; não presumir IP da Vercel. `api` recebe A para o IP da VPS; AAAA somente se IPv6 realmente configurado. Evitar registros conflitantes. Configurar redirect permanente de www para apex no Dashboard. Conferir CAA se emissão TLS falhar. DNS não foi alterado.

Vercel fornece TLS. Na VPS instalar Certbot e certificado de `api.feitoo.com.br`, habilitar renovação e executar `certbot renew --dry-run`. Para emissão inicial usar configuração HTTP temporária somente para ACME; o exemplo final referencia certificado já existente. Abrir 80/443, restringir SSH e manter 3306 privado. Nginx redireciona HTTP com 308, serve apenas `feitoo-api/public`, bloqueia dotfiles/storage e executa somente index.php. Revisar `nginx -t` antes de reload. Não confiar em headers forwarded de clientes; caso se adicione proxy externo, configurar apenas seus IPs confiáveis.

## VPS, PHP e MySQL

Base sugerida: Ubuntu 24.04 LTS, PHP 8.3 64-bit (Composer exige >=8.2), Composer 2, Nginx, MySQL 8 ou MariaDB compatível, Supervisor, cron, Git, unzip, CA certificates e Certbot. Validar com o lock, sem atualizar dependências:

```bash
sudo apt update
sudo apt install nginx mysql-server supervisor cron git unzip composer certbot python3-certbot-nginx php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-zip php8.3-gd php8.3-curl php8.3-bcmath php8.3-intl
composer check-platform-reqs --no-dev
php -m
```

Confirmar ctype, curl, dom, fileinfo, filter, gd, hash, iconv, libxml, mbstring, openssl, pdo_mysql, simplexml, tokenizer, xml, xmlreader, xmlwriter, zip, zlib e pcntl/posix no CLI. Aplicar [php.ini](production/php.ini) ao FPM e CLI e conferir configurações efetivas. CLI controla jobs; web usa timeout de 60s, processamento ocorre no worker. Testar memory_limit 256M com lotes reais antes de lançamento.

Criar banco `feitoo_production` utf8mb4/unicode_ci e usuário específico `feitoo_app`@`localhost`, senha aleatória fora do repositório, privilégios somente nesse banco (DDL necessário durante migrations). MySQL deve ouvir apenas loopback/private. Não usar root na aplicação. Configurar DB_USERNAME/DB_PASSWORD manualmente. Idealmente separar usuário de migrations e usuário runtime com privilégios mínimos. Não há necessidade de Redis.

## Environment e e-mail

Copiar `feitoo-api/.env.example` para `.env` na VPS; preencher APP_KEY com `php artisan key:generate` apenas na primeira instalação. Preservar a chave nas próximas versões e no restore. Preencher banco, Google e Stripe TEST. Os valores comerciais permanecem iguais. `.env.example` agora é de produção; para desenvolvimento sobrescrever APP_ENV=local, APP_DEBUG=true, URLs localhost, SANCTUM_STATEFUL_DOMAINS=localhost:4200, SESSION_DOMAIN=null e SESSION_SECURE_COOKIE=false. Nunca copiar o `.env` local para produção.

MAIL_MAILER=log é provisório; não comprova envio e pode registrar conteúdo de mensagens futuras. Antes de ativar e-mails configurar SMTP/provedor, host/porta/TLS, credenciais privadas, remetente suporte, SPF/DKIM/DMARC e testar entrega. Não deixar mensagens sensíveis no mailer log. Criar e testar a caixa `suporte@feitoo.com.br`. Nome/responsável legal público continua pendente (`FEITOO_RESPONSIBLE_NAME`).

## Sanctum, CORS e cookies

Revisado `config/session.php`: SESSION_DOMAIN=.feitoo.com.br permite que Angular leia XSRF-TOKEN emitido pela API. Sessão permanece HttpOnly; XSRF-TOKEN precisa ser legível por JS. Ambos Secure; SESSION_SAME_SITE=lax: os dois hosts HTTPS compartilham site, embora sejam origens distintas. Callback OAuth top-level continua compatível. SESSION_PATH=/, driver database. Produção força Secure mesmo se variável false e rejeita debug/URLs HTTP/CORS incompatível no boot.

SANCTUM_STATEFUL_DOMAINS=feitoo.com.br, sem protocolo; supports_credentials=true; CORS_ALLOWED_ORIGINS=https://feitoo.com.br, sem wildcard. Angular usa withCredentials e interceptor restrito à API para X-XSRF-TOKEN (Angular ignora URLs absolutas no handler padrão). Fluxo: GET /sanctum/csrf-cookie → POST /api/auth/login → GET /api/me → logout. Testar em navegador real nos domínios após provisionamento: cookies Domain/Secure/HttpOnly/SameSite; CSRF ausente retorna 419; origem não autorizada não recebe allow-origin. Previews vercel.app não devem autenticar na API de produção; usar backend separado sob domínio de staging se necessário. Não adicionar previews indiscriminadamente ao CORS.

## Storage, permissões e limpeza

`storage/app/private/processing` não é servido. Não executar storage:link para arquivos fiscais. Downloads seguem controllers autenticados com ownership. Usuário de deploy possui código, www-data lê código e escreve somente storage/bootstrap/cache. Exemplo, dentro do checkout correto:

```bash
sudo chown -R deploy:www-data /var/www/feitoo
sudo chmod -R u=rwX,g=rX,o= /var/www/feitoo
sudo chown -R deploy:www-data /var/www/feitoo/feitoo-api/storage /var/www/feitoo/feitoo-api/bootstrap/cache
sudo chmod -R ug=rwX,o= /var/www/feitoo/feitoo-api/storage /var/www/feitoo/feitoo-api/bootstrap/cache
sudo find /var/www/feitoo/feitoo-api/storage /var/www/feitoo/feitoo-api/bootstrap/cache -type d -exec chmod g+s {} \;
sudo chmod 640 /var/www/feitoo/feitoo-api/.env
```

Nunca chmod 777. Aplicar umask 0007 para deploy/cron/worker e garantir herança de grupo. Não distribuir vendor local, .env, caches de configuração local, logs, benchmarks ou artefatos temporários; instalar pelo lock. Não apagar arquivos de usuários durante preparação.

Free/avulsa: 120 minutos. Pro: 1440 minutos. Lote continua <=40 MiB; cada envio <=30 MiB/20 arquivos, arquivo <=10 MiB. Nginx body 32m, PHP post 32M/upload 10M/max_file_uploads 20 deixam margem de multipart sem aumentar limites comerciais. O acesso expira no prazo; purge físico ocorre na próxima execução horária, podendo levar até aproximadamente mais uma hora. Purge também elimina prefixo de arquivos órfãos associado ao processamento e previews persistidos.

## Worker, scheduler e failed jobs

Instalar [supervisor.conf](production/supervisor.conf) em /etc/supervisor/conf.d/feitoo.conf, revisar paths/socket/usuário e usar supervisorctl reread/update/status. Worker timeout=60 < retry_after=90, lease=75, backoff 10/30, tries=3; stopwaitsecs=90. Não usar queue:listen em produção. Logs do worker têm rotação no Supervisor.

Crontab de www-data (um único scheduler):

```cron
* * * * * umask 0007; cd /var/www/feitoo/feitoo-api && /usr/bin/php artisan schedule:run >> /var/www/feitoo/feitoo-api/storage/logs/scheduler.log 2>&1
```

Rotacionar scheduler.log com logrotate. `schedule:list`: purge hourly sem overlap; expire-pending-purchases everyFiveMinutes sem overlap. Não usar schedule:work. Validar retorno e monitorar falhas/disk full; cron não garante sucesso só por existir.

```bash
php artisan queue:failed
php artisan queue:retry UUID_DO_JOB
php artisan schedule:list
php artisan feitoo:purge-expired-processings
php artisan feitoo:expire-pending-purchases
```

Retries somente após investigar causa, conferir TTL e integridade. failed_jobs armazena exceção sanitizada do job; evitar dumps de payload nos logs.

## Google OAuth

Dashboard: nome Feitoo, homepage https://feitoo.com.br, suporte suporte@feitoo.com.br, domínio autorizado feitoo.com.br, links privacidade/termos. Origem https://feitoo.com.br; redirect exato https://api.feitoo.com.br/api/auth/google/callback. Manter localhost na credencial de desenvolvimento quando necessário; preferir cliente separado. GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI só no backend. Scopes atuais de identidade básica, sem adicionar scopes fiscais/Drive. Avaliar publicação da consent screen, usuários de teste e exigências de verificação no Console; isso depende do tipo de app/scopes/branding e não foi aplicado.

## Stripe TEST e futuro LIVE

Primeiro release exclusivamente TEST: sk_test_, webhook whsec_ do endpoint TEST, Price TEST real (ID price_ não informa sozinho o ambiente). Endpoint público POST https://api.feitoo.com.br/api/stripe/webhook. Criar produto/preço Pro TEST e portal no Dashboard. Eventos usados: checkout.session.completed/expired, payment_intent.payment_failed, charge.refunded, customer.subscription.created/updated/deleted, invoice.paid/payment_failed. Confirmar eventos e versão do endpoint com SDK e testes.

Verificar pagamento avulso/Pro, cancelamento, atraso, reenvio idempotente, assinatura inválida, ausência de sessão e não vazamento de payload. Não aplicar throttle de sessão ao webhook: Stripe precisa de retries; assinatura e limite de corpo 1 MiB protegem o endpoint.

**LIVE bloqueado pelo código atual**, intencionalmente preservado nesta etapa. Gateways exigem sk_test_, serviços exigem livemode=false/cs_test_. Antes de LIVE: implementação explícita de seleção de modo, testes dos dois ambientes, reconciliação de dados TEST (clientes/assinaturas/eventos), aprovação separada e Product/Price/webhook/secrets LIVE próprios. Trocar apenas .env não habilita LIVE. Nunca reutilizar Price/webhook/secret TEST e nunca pôr secrets no frontend.

## Vercel, SEO e analytics

Root Directory feitoo-web; preset Other; Node 22 >=22.12 (Angular 21), lockfile npm; install npm ci, build npm run build, output dist/feitoo-web/browser. [vercel.json](../feitoo-web/vercel.json) serve filesystem antes de fallback somente conta/login/cadastro; restante 404 real. Preserva páginas prerenderizadas. Adicionar apex/www e redirect permanente www no Dashboard.

VERCEL_ENV=production ativa domínio por padrão; preview/desenvolvimento mantém noindex/robots bloqueado mesmo recebendo FEITOO_PUBLIC_ORIGIN. Para build local de release definir FEITOO_BUILD_ENV=production. Scripts não carregam .env automaticamente: exportar variáveis no shell ou Dashboard. npm run watch não regenera launch: após gerar um release, regenerar com FEITOO_BUILD_ENV=development antes de uso local. Ambiente API de produção é absoluto e nunca tem fallback localhost; desenvolvimento usa environment.development.ts.

Variáveis públicas permitidas: FEITOO_PUBLIC_ORIGIN, CONTACT_EMAIL, RESPONSIBLE_NAME, GA4_ID, ANALYTICS_ENABLED, PUBLIC_TTL_MINUTES. API URL centralizada em environment.ts. Sem Stripe/Google secrets na Vercel. GA4_ID=G-... e ANALYTICS_ENABLED=true somente após configurar propriedade; script só é carregado após consentimento, revogação impede novos eventos. Testar aceitar/recusar/revogar e ausência de eventos antes de aceitar.

Canonicals/sitemap: /, /xml-para-excel, /produtos-dos-xmls, /planos, /seguranca, /privacidade, /termos com https://feitoo.com.br. Robots bloqueia conta/login/cadastro/404/API. Testar sitemap.xml, robots.txt, meta e status HTTP no host real. Search Console: adicionar propriedade de domínio feitoo.com.br, copiar TXT fornecido, verificar e enviar https://feitoo.com.br/sitemap.xml. Nenhuma verificação automatizada.

OG title/description/url usam domínio real. **Imagem OG 1200×630 ainda pendente**; não há asset adequado nem og:image, não afirmar conclusão. Headers básicos e CSP limitada a frame/object/base evitam bloquear Google/Stripe/Angular/GA4. Não usar CSP restritiva de script sem medir inline Angular e scripts de consentimento. Reavaliar CSP em report-only antes de endurecer.

## Healthcheck, logs e erros

GET /api/health retorna status ok e identificador público feitoo-api, sem versão/credenciais. É liveness, não comprova MySQL/worker/cron. Monitorar separadamente backlog, failed jobs, scheduler, espaço e TLS. APP_DEBUG=false; erros da API têm mensagem genérica e logs só classe/UUID, sem XML/senhas/tokens/bindings. Logs daily, 14 dias, warning. Não registrar query string no access log (OAuth codes); revisar coleta externa e error logs para eventual URL sensível. Rate limits auth/login/create/upload/access/subscription permanecem ativos. Request ID de correlação é melhoria pendente; logs atuais usam UUID de processamento quando disponível.

## Backup e restore

Banco: backup diário criptografado fora da VPS, retenção sugerida 7 diários/4 semanais/3 mensais; RPO inicial 24h, RTO definido e testado pela operação. Para RPO menor adicionar binlogs. Usar usuário de backup e arquivo de credenciais chmod 600, nunca senha no comando:

```bash
mysqldump --defaults-extra-file=/etc/feitoo/backup.cnf --single-transaction --quick --no-tablespaces feitoo_production > /caminho-seguro/feitoo.sql
```

Criptografar/enviar ao destino externo e monitorar sucesso; dump inclui dados pessoais/fiscais persistidos, proteger acesso e retenção. Backup separado e seguro de APP_KEY/.env e configs; não commitar. XML temporário não precisa backup permanente; resultados/previews fiscais no banco acompanham dump e expiram conforme retenção de backup. Restore em banco isolado: importar dump, reinstalar commit e lock, restaurar chave/config, revisar jobs antigos antes de ligar worker e executar purge de registros vencidos. Testar restore mensalmente.

## Deploy manual e rollback

Não criado script de git pull cego: migrations exigem revisão e banco existente contém passo destrutivo. Procedimento manual de release aprovado, sem executar nesta etapa:

1. Escolher commit/tag imutável, revisar diff/migrations, testar e criar backup verificável.
2. Preparar checkout limpo em /var/www/feitoo; preservar .env/storage. Frontend permanece na Vercel. Composer install --no-dev --prefer-dist --optimize-autoloader; composer check-platform-reqs --no-dev.
3. Revisar secrets/config/permissões; primeira instalação gerar APP_KEY. Colocar manutenção se necessário, coordenar worker e impedir processamento durante migrations incompatíveis.
4. Executar php artisan migrate:status e migrate --pretend, revisar SQL. Somente após revisão executar php artisan migrate --force. Nunca migrate:fresh/reset.
5. php artisan optimize; php artisan queue:restart; conferir Supervisor e cron; retirar manutenção, validar health e testes de navegador.
6. Publicar frontend pela Vercel após validar API, mantendo release anterior para rollback. Verificar 404/prerender/cookies/Google/Stripe TEST/purge no host real.

Rollback: restaurar commit/release anterior e dependências do seu lock, manter .env/APP_KEY/storage, reconstruir caches e reiniciar worker. Reverter deployment Vercel pelo Dashboard. Só voltar código se compatível com schema atual. Não executar migrate:rollback automaticamente; preferir correção forward. Restore de banco exige janela de manutenção e reconciliação de pagamentos/webhooks/alterações posteriores ao backup. Documentar ponto de recuperação e impacto antes de restaurar.

## Checklist pré-deploy (manual)

- [ ] domínio apex/www/API
- [ ] DNS e redirect www
- [ ] VPS e firewall/atualizações
- [ ] SSL e renovação
- [ ] database/usuário privado
- [ ] .env e APP_KEY/secrets
- [ ] migrations revisadas/backup
- [ ] worker e failed_jobs
- [ ] scheduler e monitoramento
- [ ] frontend Vercel/prerender/404
- [ ] CORS origem autorizada
- [ ] Sanctum/CSRF
- [ ] cookies Secure/HttpOnly/domain/SameSite
- [ ] Google/consent screen
- [ ] Stripe TEST/Price/portal
- [ ] webhook assinatura/idempotência
- [ ] e-mail e responsável legal
- [ ] analytics e consentimento
- [ ] sitemap/canonicals
- [ ] robots/noindex previews
- [ ] imagem OG
- [ ] healthcheck e monitoração
- [ ] purge/TTL/resultados/previews
- [ ] backup e restore testado
- [ ] rollback ensaiado

Referências oficiais: [Sanctum](https://laravel.com/framework/docs/12.x/sanctum), [Angular versões](https://angular.dev/reference/versions), [Vercel configuração](https://vercel.com/docs/project-configuration/vercel-json). Instalação e configurações externas precisam de validação na VPS/Dashboard; testes locais não comprovam infraestrutura.

## Valida??o local

Backend: 236 testes; frontend: 156 testes; Pint; builds de produ??o e preview; dois testes de assets p?blicos. Preview bloqueia robots mesmo com vari?vel de dom?nio oficial. Boot de produ??o v?lido e rejei??o de debug/HTTP/CORS wildcard verificados. O build passa com aviso de CSS em xml-to-excel.scss (5,40 kB vs or?amento de aviso 4 kB). Bundle p?blico sem localhost/IP de desenvolvimento/padr?es de secrets. Nginx/PHP-FPM/Supervisor n?o foram executados no Windows; validar na VPS.
