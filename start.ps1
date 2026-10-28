param([int]$Port = 8765)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
. (Join-Path $PSScriptRoot 'scripts/use-local-php.ps1')
$phpOptions = @()
$modules = & php -m
if ($modules -notcontains 'pdo_sqlite') { $phpOptions += @('-d', 'extension=pdo_sqlite') }
if ($modules -notcontains 'sqlite3') { $phpOptions += @('-d', 'extension=sqlite3') }
if (-not (Test-Path -LiteralPath 'vendor/autoload.php')) {
    & composer install --no-interaction --prefer-dist
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao instalar dependências.' }
}
if (-not (Test-Path -LiteralPath '.env')) {
    Copy-Item -LiteralPath '.env.example' -Destination '.env'
    & php @phpOptions artisan key:generate
}
if (-not (Test-Path -LiteralPath 'database/database.sqlite')) { New-Item -ItemType File -Path 'database/database.sqlite' | Out-Null }
& php @phpOptions artisan migrate --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Não foi possível preparar o banco.' }
Write-Host "São Francisco disponível em http://127.0.0.1:$Port"
Write-Host 'Mantenha esta janela aberta. Ctrl+C encerra o servidor local.'
# Cache de código do PHP (OPcache): páginas cerca de 40% mais rápidas no servidor local.
# Os timestamps continuam sendo verificados, então alterações no código aparecem na hora.
$serverOptions = $phpOptions
$extensionDirectory = & php -r "echo ini_get('extension_dir');"
if (-not [System.IO.Path]::IsPathRooted($extensionDirectory)) { $extensionDirectory = Join-Path (Split-Path -Parent (Get-Command php).Source) $extensionDirectory }
$opcacheAvailable = ($modules -contains 'Zend OPcache') -or (Test-Path -LiteralPath (Join-Path $extensionDirectory 'php_opcache.dll'))
if ($opcacheAvailable) {
    if ($modules -notcontains 'Zend OPcache') { $serverOptions += @('-d', 'zend_extension=opcache') }
    $serverOptions += @('-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=0')
}
& php @serverOptions -S "127.0.0.1:$Port" -t (Join-Path $PSScriptRoot 'public') (Join-Path $PSScriptRoot 'server.php')
