# Autenticação e processamento autenticado

## Estado e arquitetura

Laravel 12.69.3, Sanctum 4.3.3, PHP 8.2.12 e Angular standalone 21.2.25.
A Etapa 02 já tinha storage privado, SafeXml, checksum SHA-256, TTL e purge.
Esses mecanismos permanecem. Não há parser fiscal ou conversão para Excel.

Sanctum usa sessão web em cookie HttpOnly, sem bearer token persistido pelo
Angular. O cliente consulta `/api/me` para restaurar o estado e mantém apenas
os dados públicos do usuário em memória. Antes das operações mutáveis busca
`/sanctum/csrf-cookie`; o interceptor envia cookies e `X-XSRF-TOKEN` somente
para os endereços configurados da API. O cookie XSRF pode ser lido pelo cliente;
o cookie de sessão não pode. CORS permite credenciais apenas das origens
configuradas. Usar `localhost` nos dois serviços evita misturar domínios.

Cadastro normaliza e-mail, valida nome e confirmação da senha, exige no mínimo
12 caracteres e limita a senha a 72 bytes, respeitando bcrypt. Senhas são
hashadas pelo cast Laravel. Login usa o guard nativo, com erro genérico para
credenciais incorretas; cadastro/login renovam a sessão. Logout invalida a sessão.
Não foram adicionados recuperação de senha ou verificação por e-mail.

## Rotas

| Método | Caminho | Regra |
| --- | --- | --- |
| GET | `/sanctum/csrf-cookie` | Inicializar CSRF |
| POST | `/api/auth/register` | Nome, e-mail, senha, confirmação; retorna usuário e inicia sessão |
| POST | `/api/auth/login` | E-mail/senha; inicia sessão |
| POST | `/api/auth/logout` | `auth:sanctum`, encerra sessão |
| GET | `/api/me` | `auth:sanctum`, usuário público |
| GET | `/api/user` | Alias compatível de `/me` |
| POST | `/api/auth/google/start` | CSRF; `{ "intent": "login" }` ou `"link"` |
| GET | `/api/auth/google/callback` | Callback com código e state, validado no backend |
| POST | `/api/processings` | `auth:sanctum`, proprietário derivado da sessão |
| GET | `/api/processings/{uuid}` | Apenas proprietário |
| POST | `/api/processings/{uuid}/files` | Apenas proprietário; multipart `files[]` |
| DELETE | `/api/processings/{uuid}/files` | Apenas proprietário; remove arquivos |

Outro usuário recebe 404 nas rotas de um processamento. O serviço também confere
propriedade dentro do lock transacional em upload/exclusão. Nenhum ID de usuário
enviado pelo cliente define o proprietário. UUID identifica o recurso, não autoriza acesso.
Os Resources não expõem hash de senha, google_id ou caminhos privados.
Respostas privadas recebem `Cache-Control: no-store, private`.

## Google e vinculação

Socialite 5.31 usa fluxo Authorization Code, state vinculado à sessão e PKCE.
O backend troca o código com Google e consulta a identidade no servidor;
não aceita identidade declarada pelo Angular. Exige e-mail verificado e subject
válido. O contexto OAuth expira após dez minutos e é consumido uma única vez.
Tokens Google são usados durante a requisição e não são armazenados.

`users.google_id` é o subject estável do Google, nullable e único. Um novo
usuário Google nasce com senha null. Login posterior pelo mesmo subject usa a
mesma conta, mesmo que o e-mail Google mude, sem alterar automaticamente o
e-mail canônico da conta Feitoo.

Se o e-mail já existir em uma conta por senha, o login Google **não** cria outra
conta e **não** vincula silenciosamente: orienta entrar pela senha. Depois do
login, o botão **Vincular Google** inicia `intent=link`; exige a mesma sessão,
e-mail Google verificado igual ao da conta e subject não vinculado a outra pessoa.
A senha existente é preservada. Conflitos retornam mensagem genérica na interface.

Configure localmente, sem versionar secrets:

```dotenv
FRONTEND_URL=http://localhost:4200
SANCTUM_STATEFUL_DOMAINS=localhost:4200,127.0.0.1:4200,localhost:8000,127.0.0.1:8000
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/api/auth/google/callback
FEITOO_PROCESSING_TTL_MINUTES=120
```

No Google Cloud, configure cliente OAuth do tipo aplicação web, tela de consentimento
e a URI de callback exatamente acima; adicione usuários de teste quando aplicável.
Sem credenciais, o início do fluxo responde 503. Em produção configure HTTPS,
`SESSION_SECURE_COOKIE=true`, origens e domínios da sessão adequados, e URLs reais
nos environments Angular. Esta configuração SPA pressupõe frontend e API no mesmo
domínio registrável. Não exponha GOOGLE_CLIENT_SECRET no frontend.

## Banco e arquivos

Migration nova: `2026_10_07_220000_enable_user_authentication.php`. Adiciona
google_id único, permite senha null e remove anonymous_token_hash dos processamentos.
Não modifica migrations anteriores nem atribui registros anônimos a usuários.
Registros legados continuam sem proprietário, inacessíveis pela API, mas sujeitos ao purge.
O rollback mantém senha nullable para não inventar senhas para contas Google.

Storage continua no disk privado `processing`, sem link público. TTL padrão 120
minutos; o nome antigo `FEITOO_ANONYMOUS_TTL_MINUTES` é fallback para preservar
configurações locais existentes. Mantidos limite de 20 arquivos, 10 MB por arquivo,
nome físico UUID, SHA-256, rejeição de DTD/entidades externas, limites XML e limpeza
idempotente. Purge preserva metadados, isola falhas e roda de hora em hora.

Não são registrados senha, token OAuth, cookie, XML ou caminhos privados completos.
Falhas OAuth registram apenas classe da exceção. Auth tem limite 10/minuto/IP;
login combina 20/minuto/IP e 5/minuto/e-mail hash + IP. Limites de processing da
Etapa 02 permanecem em `AppServiceProvider`.

## Executar e verificar

```powershell
cd feitoo-api
php artisan migrate
php artisan serve
# Outro terminal, mesma pasta, para limpeza agendada:
php artisan schedule:work
# Ou limpeza manual:
php artisan feitoo:purge-expired-processings
```

```powershell
cd feitoo-web
npm.cmd start
```

Frontend `http://localhost:4200`, login `/login`, cadastro `/cadastro`;
API `http://localhost:8000/api`. A interface mínima mostra entrar/cadastrar,
Continuar com Google, usuário atual, vinculação e logout; não há dashboard.

### Exemplos PowerShell reais

Execute a partir da raiz. Use uma conta de teste nova e um XML local, sem publicar
senha, cookies ou headers no console. O helper atualiza XSRF depois da renovação
da sessão. `Invoke-RestMethod -WebSession` também retém headers: para testar ausência
de CSRF, remova explicitamente `X-XSRF-TOKEN` de `$session.Headers`.

```powershell
$base = 'http://localhost:8000'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$origin = @{ Origin='http://localhost:4200'; Accept='application/json' }
function Get-FeitooHeaders {
    Invoke-RestMethod "$base/sanctum/csrf-cookie" -WebSession $session -Headers $origin | Out-Null
    $xsrf = [uri]::UnescapeDataString($session.Cookies.GetCookies($base)['XSRF-TOKEN'].Value)
    return @{ Origin='http://localhost:4200'; Accept='application/json'; 'X-XSRF-TOKEN'=$xsrf }
}
$credential = Get-Credential -Message 'E-mail e senha da conta de teste'
$password = $credential.GetNetworkCredential().Password
$body = @{ name='Pessoa Teste'; email=$credential.UserName; password=$password; password_confirmation=$password } | ConvertTo-Json
Invoke-RestMethod "$base/api/auth/register" -Method Post -WebSession $session -Headers (Get-FeitooHeaders) -ContentType application/json -Body $body
# Para login de uma conta já existente, use no lugar do cadastro:
# $body = @{ email=$credential.UserName; password=$password } | ConvertTo-Json
# Invoke-RestMethod "$base/api/auth/login" -Method Post -WebSession $session -Headers (Get-FeitooHeaders) -ContentType application/json -Body $body

Invoke-RestMethod "$base/api/me" -WebSession $session -Headers $origin
$processing = Invoke-RestMethod "$base/api/processings" -Method Post -WebSession $session -Headers (Get-FeitooHeaders) -ContentType application/json -Body '{"tool":"xml-to-excel"}'
$id = $processing.data.id

# Upload via curl.exe. Configuração pela entrada padrão evita secrets nos argumentos.
# Ajuste apenas o caminho do XML; nomes físicos continuam sendo gerados no servidor.
$headers = Get-FeitooHeaders
$cookie = $session.Cookies.GetCookieHeader([uri]$base)
$xml = (Resolve-Path '.\exemplo.xml').Path.Replace('\','/')
$curlConfig = @"
url = "$base/api/processings/$id/files"
request = "POST"
header = "Origin: http://localhost:4200"
header = "Accept: application/json"
header = "X-XSRF-TOKEN: $($headers['X-XSRF-TOKEN'])"
cookie = "$cookie"
form = "files[]=@$xml;type=application/xml"
silent
show-error
fail-with-body
"@
$curlConfig | curl.exe --config -
Invoke-RestMethod "$base/api/processings/$id" -WebSession $session -Headers $origin
Invoke-RestMethod "$base/api/processings/$id/files" -Method Delete -WebSession $session -Headers (Get-FeitooHeaders)
Invoke-RestMethod "$base/api/auth/logout" -Method Post -WebSession $session -Headers (Get-FeitooHeaders)

# Google: iniciar na tela /login ou /cadastro após configurar o cliente OAuth.
# A vinculação exige login prévio e o botão Vincular Google na tela inicial.
```

Os limites efetivos de upload também dependem de `upload_max_filesize`,
`post_max_size` e `max_file_uploads` do PHP; veja a documentação da Etapa 02.

## Validação

`php artisan test`: 51 testes, 434 assertions. Auth, me/logout, CSRF real,
isolamento entre usuários, Google com provider Socialite real e HTTP simulado,
state/PKCE, e-mail não verificado, conflitos e duplicidade; regressões de XML
hostil, storage, TTL, checksum, purge e compensação de falhas preservadas.

`npm.cmd test -- --watch=false`: 19 testes em quatro arquivos. `npm.cmd run build`
passou, com aviso de bundle inicial 555,22 kB acima do orçamento de aviso 500 kB
(limite de erro 1 MB). Pint e Prettier foram executados. Migration aplicada ao
MariaDB local, também exercitada pelos testes SQLite; rotas e scheduler conferidos.
Smoke HTTP confirmou cadastro, cookie HttpOnly, me, CSRF 419 sem header, criação
autenticada, exclusão e logout seguido de 401. Google real permanece dependente
da configuração das credenciais/consentimento, não substituída por mocks em produção.
