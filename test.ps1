$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$phpOptions = @()
$modules = & php -m
if ($modules -notcontains 'pdo_sqlite') { $phpOptions += @('-d', 'extension=pdo_sqlite') }
if ($modules -notcontains 'sqlite3') { $phpOptions += @('-d', 'extension=sqlite3') }
& php @phpOptions vendor/bin/phpunit @args
exit $LASTEXITCODE
