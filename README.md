# Feitoo — fundação técnica

O contrato atual de autenticação e processamento autenticado está em
[docs/authentication.md](docs/authentication.md), incluindo Google, configuração
local e exemplos PowerShell. O núcleo de processamento da Etapa 02 está documentado em
[docs/processing.md](docs/processing.md), com contratos, segurança, limites,
scheduler e exemplos PowerShell. As seções abaixo registram a fundação da Etapa 01.

`feitoo-web/` contém Angular standalone com TypeScript estrito e SCSS.
`feitoo-api/` contém a API Laravel. Nenhuma ferramenta fiscal foi implementada.

## Ambiente auditado

Node 22.14.0, npm 11.21.0, Angular 21.2.25, Angular CLI 21.2.26,
TypeScript 5.9.3, Bootstrap 5.3.8, Bootstrap Icons 1.13.1,
PHP 8.2.12, Composer 2.10.2, Laravel 12.69.3 e Sanctum 4.3.3.
As versões exatas das dependências estão nos respectivos lockfiles.

## Executar em PowerShell

Em dois terminais, a partir desta pasta:

```powershell
cd feitoo-api
php artisan serve
```

```powershell
cd feitoo-web
npm.cmd start
```

Frontend: http://localhost:4200. API: http://localhost:8000/api.
Health: http://localhost:8000/api/health retorna
`{"status":"ok","service":"feitoo-api"}` sem autenticação nem consulta ao banco.
A tela temporária verifica a API uma vez, apenas em desenvolvimento, com timeout
de cinco segundos. A falha não impede a renderização.
`npm.cmd` evita o bloqueio de `npm.ps1` pela política de scripts deste ambiente.

Em um checkout novo, execute `npm.cmd ci` no frontend e `composer install` no
backend. Crie `.env` a partir de `.env.example` somente se ele não existir e
execute `php artisan key:generate` somente na configuração inicial.
Não substitua um `.env` existente.

## Configuração

Configure localmente `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e
`DB_PASSWORD` para um banco MySQL existente. O PHP auditado tem `pdo_mysql`.
O `.env` existente foi preservado; `.env.example` usa MySQL e credenciais vazias.
Antes de usar recursos que dependem do banco, revise e execute as migrations
originais com `php artisan migrate`. Nenhuma migration foi criada nesta etapa
e nenhum dado foi alterado. Session/cache/queue mantêm os padrões existentes;
o health não depende deles. Os testes usam SQLite em memória/array conforme
`phpunit.xml`, sem acessar o MySQL local.

A URL pública da API fica em `src/environments/` no frontend. O build de
desenvolvimento substitui `environment.ts` por `environment.development.ts`.
O build de produção usa `/api`: a hospedagem deverá encaminhar esse caminho ao
Laravel ou configurar a URL pública antes do build. Nunca coloque secrets nesses
arquivos, pois são incorporados ao JavaScript entregue ao navegador.

CORS usa o middleware nativo do Laravel e permite `http://localhost:4200` por
padrão. `CORS_ALLOWED_ORIGINS` aceita origens separadas por vírgula. Configure
as origens reais em produção e use `APP_DEBUG=false`. Após alterar configurações
com cache ativo, execute `php artisan config:clear` em desenvolvimento.
Cookies de autenticação entre origens não estão habilitados nesta etapa.
Sanctum e `/api/user` já existiam e foram preservados, sem fluxo de login.

## Storage e próximas etapas

O disco padrão `local` permanece em `storage/app/private`, com visibilidade
privada e entrega/upload automático desabilitados (`serve=false`). Não use
`public/`, o disco `public` ou `storage:link` para documentos fiscais.
Nas próximas etapas, use a abstração `Storage`/discos configuráveis, identificadores
internos gerados pela aplicação e não exponha paths nem registre conteúdo fiscal.
O disco S3 já está declarado pelo Laravel, mas não foi ativado: S3/R2 exigirá
adapter, credenciais privadas e configuração apropriada na etapa correspondente.
Nenhum sistema de arquivos, jobs, workers ou modelos Processing foi criado.
O processamento futuro deve ocorrer em jobs, sem trabalho pesado nos controllers.

## Validação

Build de produção concluído; o orçamento original de 500 kB emite aviso porque
Bootstrap e Bootstrap Icons elevam o bundle inicial a 524,53 kB. O limite de erro
de 1 MB permanece preservado. Testes Angular validam GET, resposta e falha via
HttpTestingController; testes Laravel validam contrato público e CORS, incluindo
preflight e origem não permitida. HTTP real local foi verificado separadamente
nos dois servidores. Não foi executado teste ponta a ponta em navegador real.

```powershell
cd feitoo-web
npm.cmd run build
npm.cmd test -- --watch=false
cd ../feitoo-api
php artisan test
php artisan route:list
Invoke-RestMethod http://localhost:8000/api/health
```

## Pendência estrutural do Git

Preparação das páginas públicas, SEO, prerender, analytics opcional e checklist
de publicação: [docs/public-launch.md](docs/public-launch.md).

A raiz registra `feitoo-web` como gitlink (modo `160000`), e o frontend tem seu
próprio `.git`, mas não existe `.gitmodules`. Um clone da raiz não recupera
automaticamente o frontend. Os históricos foram preservados: decidir entre
monorepo ou submódulo com URL real antes de compartilhar o repositório.
As alterações do frontend devem ser inspecionadas com `git -C feitoo-web diff`.
