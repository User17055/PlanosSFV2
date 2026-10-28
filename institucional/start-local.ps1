param([string]$PhpPath = 'php', [int]$Port = 8080)
$ErrorActionPreference = 'Stop'
$phpVersion = & $PhpPath -r 'echo PHP_VERSION_ID;'
if ($LASTEXITCODE -ne 0 -or [int]$phpVersion -lt 80500) { throw 'O institucional exige PHP 8.5. Informe o executável com -PhpPath.' }
$sitePublic = Join-Path $PSScriptRoot 'public_html'
$env:APP_ENV = 'local'
$env:APP_DEBUG = '0'
if (-not $env:DATABASE_URL -and -not (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'configuracao_privada/database.php'))) { $env:PORTAL_LOCAL_PREVIEW = '1' }
Write-Output "Área do cliente: http://127.0.0.1:$Port/area-do-cliente"
Write-Output 'A gestão/API deve estar iniciada no endereço da configuração privada do portal.'
& $PhpPath -S "127.0.0.1:$Port" -t $sitePublic (Join-Path $sitePublic 'index.php')
