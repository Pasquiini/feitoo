$ErrorActionPreference = "Stop"

$project = "D:\backup\Projetos\feitoo\feitoo-api"
$backendUrl = "http://localhost:8000"
$webhookUrl = "$backendUrl/api/stripe/webhook"

function Write-Step($message) {
    Write-Host ""
    Write-Host "==> $message" -ForegroundColor Cyan
}

function Write-Ok($message) {
    Write-Host "[OK] $message" -ForegroundColor Green
}

function Write-Warn($message) {
    Write-Host "[AVISO] $message" -ForegroundColor Yellow
}

function Test-Command($command) {
    return $null -ne (Get-Command $command -ErrorAction SilentlyContinue)
}

function Get-EnvValue($name) {
    $envFile = Join-Path $project ".env"

    if (-not (Test-Path $envFile)) {
        return $null
    }

    $line = Get-Content $envFile |
        Where-Object { $_ -match "^$name=" } |
        Select-Object -First 1

    if (-not $line) {
        return $null
    }

    return ($line -replace "^$name=", "").Trim()
}

Write-Host ""
Write-Host "==========================================" -ForegroundColor DarkGreen
Write-Host "          FEITOO BACKEND DEV" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor DarkGreen

Set-Location $project

# --------------------------------------------------
# PHP
# --------------------------------------------------

Write-Step "Verificando PHP"

if (-not (Test-Command "php")) {
    Write-Host "PHP não encontrado no PATH." -ForegroundColor Red
    exit 1
}

php --version | Select-Object -First 1
Write-Ok "PHP encontrado"

# --------------------------------------------------
# Composer
# --------------------------------------------------

Write-Step "Verificando Composer"

if (-not (Test-Command "composer")) {
    Write-Host "Composer não encontrado no PATH." -ForegroundColor Red
    exit 1
}

composer --version
Write-Ok "Composer encontrado"

# --------------------------------------------------
# Artisan
# --------------------------------------------------

Write-Step "Verificando Laravel"

if (-not (Test-Path ".\artisan")) {
    Write-Host "Arquivo artisan não encontrado em $project" -ForegroundColor Red
    exit 1
}

Write-Ok "Projeto Laravel encontrado"

# --------------------------------------------------
# .env
# --------------------------------------------------

Write-Step "Verificando .env"

if (-not (Test-Path ".\.env")) {
    Write-Host ".env não encontrado." -ForegroundColor Red
    exit 1
}

Write-Ok ".env encontrado"

# --------------------------------------------------
# Banco
# --------------------------------------------------

Write-Step "Verificando conexão com banco"

try {
    php artisan db:show --no-interaction | Out-Null
    Write-Ok "Banco de dados acessível"
}
catch {
    Write-Warn "Não foi possível validar o banco."
    Write-Warn "Confirme se MySQL/MariaDB está rodando."
}

# --------------------------------------------------
# Migrations
# --------------------------------------------------

Write-Step "Verificando migrations"

try {
    $migrationOutput = php artisan migrate:status --no-interaction 2>&1

    if ($migrationOutput -match "Pending") {
        Write-Warn "Existem migrations pendentes."

        $answer = Read-Host "Deseja executar php artisan migrate agora? (s/n)"

        if ($answer -match "^[sS]") {
            php artisan migrate --no-interaction
            Write-Ok "Migrations executadas"
        }
    }
    else {
        Write-Ok "Migrations em dia"
    }
}
catch {
    Write-Warn "Não foi possível consultar migrations."
}

# --------------------------------------------------
# Cache
# --------------------------------------------------

Write-Step "Limpando caches"

php artisan optimize:clear

Write-Ok "Caches limpos"

# --------------------------------------------------
# Stripe
# --------------------------------------------------

Write-Step "Verificando Stripe CLI"

$stripeAvailable = Test-Command "stripe"

if ($stripeAvailable) {
    stripe --version
    Write-Ok "Stripe CLI encontrada"
}
else {
    Write-Warn "Stripe CLI não encontrada."
}

$stripeSecret = Get-EnvValue "STRIPE_SECRET_KEY"
$stripeWebhookSecret = Get-EnvValue "STRIPE_WEBHOOK_SECRET"

$stripeConfigured =
    $stripeAvailable -and
    $stripeSecret -and
    $stripeWebhookSecret

if (-not $stripeSecret) {
    Write-Warn "STRIPE_SECRET_KEY não configurada."
}

if (-not $stripeWebhookSecret) {
    Write-Warn "STRIPE_WEBHOOK_SECRET não configurado."
}

# --------------------------------------------------
# Google OAuth
# --------------------------------------------------

Write-Step "Verificando Google OAuth"

$googleClient = Get-EnvValue "GOOGLE_CLIENT_ID"
$googleSecret = Get-EnvValue "GOOGLE_CLIENT_SECRET"

if ($googleClient -and $googleSecret) {
    Write-Ok "Google OAuth configurado"
}
else {
    Write-Warn "Google OAuth ainda não está completamente configurado."
}

# --------------------------------------------------
# Abrir processos
# --------------------------------------------------

Write-Step "Iniciando Laravel"

Start-Process powershell -ArgumentList @(
    "-NoExit",
    "-Command",
    "cd '$project'; `$Host.UI.RawUI.WindowTitle='Feitoo API'; php artisan serve --host=localhost --port=8000"
)

Write-Ok "API iniciada em $backendUrl"

Start-Sleep -Seconds 1

Write-Step "Iniciando queue worker"

Start-Process powershell -ArgumentList @(
    "-NoExit",
    "-Command",
    "cd '$project'; `$Host.UI.RawUI.WindowTitle='Feitoo Queue'; php artisan queue:work database --sleep=1 --tries=3 --timeout=60"
)

Write-Ok "Queue worker iniciado"

Write-Step "Iniciando scheduler"

Start-Process powershell -ArgumentList @(
    "-NoExit",
    "-Command",
    "cd '$project'; `$Host.UI.RawUI.WindowTitle='Feitoo Scheduler'; php artisan schedule:work"
)

Write-Ok "Scheduler iniciado"

# --------------------------------------------------
# Stripe listener
# --------------------------------------------------

if ($stripeConfigured) {

    Write-Step "Iniciando Stripe listener"

    $stripeEvents = @(
        "checkout.session.completed",
        "checkout.session.expired",
        "payment_intent.succeeded",
        "payment_intent.payment_failed",
        "charge.refunded",
        "customer.subscription.created",
        "customer.subscription.updated",
        "customer.subscription.deleted",
        "invoice.paid",
        "invoice.payment_failed"
    ) -join ","

    Start-Process powershell -ArgumentList @(
        "-NoExit",
        "-Command",
        "`$Host.UI.RawUI.WindowTitle='Feitoo Stripe'; stripe listen --events $stripeEvents --forward-to $webhookUrl"
    )

    Write-Ok "Stripe listener iniciado"
}
else {
    Write-Warn "Stripe listener não iniciado."
}

# --------------------------------------------------
# Resumo
# --------------------------------------------------

Write-Host ""
Write-Host "==========================================" -ForegroundColor DarkGreen
Write-Host "          FEITOO INICIADO" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor DarkGreen
Write-Host ""
Write-Host "API        : $backendUrl"
Write-Host "Queue      : ativa"
Write-Host "Scheduler  : ativo"

if ($stripeConfigured) {
    Write-Host "Stripe     : listener ativo"
}
else {
    Write-Host "Stripe     : não iniciado"
}

if ($googleClient -and $googleSecret) {
    Write-Host "Google     : configurado"
}
else {
    Write-Host "Google     : configuração pendente"
}

Write-Host ""
Write-Host "Agora rode o frontend:" -ForegroundColor Cyan
Write-Host ""
Write-Host "cd D:\backup\Projetos\feitoo\feitoo-web"
Write-Host "npm.cmd start"
Write-Host ""