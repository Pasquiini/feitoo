param(
    [ValidateSet('check', 'tests', 'versions')]
    [string] $Mode = 'check',
    [string] $Filter = ''
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runtimeEnvironment = if ($Mode -eq 'tests') { 'testing' } else { 'production' }
$syntheticKey = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes('0123456789abcdef0123456789abcdef'))
# Bind only code/dependencies. Never expose .env, cached configuration, storage or .git.
$dockerArgs = @('run', '--rm', '--network', 'none', '--security-opt', 'seccomp=unconfined',
    '--tmpfs', '/app/storage:rw,mode=1777', '--tmpfs', '/app/bootstrap/cache:rw,mode=1777',
    '-e', 'QUOTATION_GENERATION_ENABLED=false', '-e', "APP_ENV=$runtimeEnvironment",
    '-e', 'APP_DEBUG=false',
    '-e', "APP_KEY=base64:$syntheticKey",
    '-e', 'LOG_CHANNEL=null', '-e', 'CACHE_STORE=array', '-e', 'SESSION_DRIVER=array')
if ($Mode -ne 'tests') {
    $dockerArgs += @('-e', 'APP_URL=https://api.example.test', '-e', 'FRONTEND_URL=https://example.test',
        '-e', 'CORS_ALLOWED_ORIGINS=https://example.test')
}
foreach ($part in @('app', 'config', 'resources', 'routes', 'scripts', 'tests', 'vendor',
    'database/migrations', 'database/factories', 'database/seeders',
    'bootstrap/app.php', 'bootstrap/providers.php', 'composer.json', 'phpunit.xml', 'artisan')) {
    $source = Join-Path $project $part
    $dockerArgs += @('--mount', "type=bind,source=$source,target=/app/$part,readonly")
}
$dockerArgs += 'feitoo-quotation-sandbox-test'
# Empty tmpfs mounts need Laravel's usual writable directory scaffolding.
$dockerArgs += @('sh', '-c', 'mkdir -p /app/storage/framework/views /app/storage/framework/sessions /app/storage/framework/cache/data /app/storage/logs; exec "$@"', 'quotation-sandbox-lab')
switch ($Mode) {
    'check' { $dockerArgs += @('php', 'artisan', 'feitoo:quotation-sandbox-check') }
    'tests' {
        $dockerArgs += @('php', 'vendor/phpunit/phpunit/phpunit', '--do-not-cache-result')
        if ($Filter) { $dockerArgs += @('--filter', $Filter) }
    }
    'versions' { $dockerArgs += @('sh', '-c', 'php -v; bwrap --version; prlimit --version; id') }
}
# Docker's default seccomp blocks nested namespaces. This exception applies only
# to this disposable test container; Bubblewrap/prlimit restrictions stay intact.
& docker @dockerArgs
exit $LASTEXITCODE
