param([string]$SitePhp = '',[string]$ManagementPhp = 'php',[int]$SitePort = 8080,[int]$ManagementPort = 8765)
$ErrorActionPreference = 'Stop'
if (-not $SitePhp) {
    $candidates = @($env:PHP85_PATH, (Join-Path $PSScriptRoot 'institucional/ferramentas_locais/php85/runtime/php.exe'),
        (Join-Path ([Environment]::GetFolderPath('Desktop')) 'SÃO FRANCISCO/ferramentas_locais/php85/runtime/php.exe'))
    $SitePhp = $candidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
    if (-not $SitePhp) { $SitePhp = (Get-Command php -ErrorAction Stop).Source }
}
$siteVersion = & $SitePhp -r 'echo PHP_VERSION_ID;'
if ($LASTEXITCODE -ne 0 -or [int]$siteVersion -lt 80500) { throw 'Informe PHP 8.5 com -SitePhp ou pela variável PHP85_PATH.' }
if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'institucional/configuracao_privada/portal.php'))) {
    & (Join-Path $PSScriptRoot 'scripts/configure-local-site.ps1') -ManagementApiUrl "http://127.0.0.1:$ManagementPort/api/site/v1" -PublicSiteUrl "http://127.0.0.1:$SitePort"
}
foreach ($port in @($SitePort,$ManagementPort)) {
    if (Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue) { throw "A porta $port já está em uso. Encerre o servidor anterior ou escolha outras portas." }
}
$env:APP_ENV = 'local'
$env:APP_DEBUG = '0'
if (-not $env:DATABASE_URL -and -not (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'institucional/configuracao_privada/database.php'))) { $env:PORTAL_LOCAL_PREVIEW = '1' }
$apiProcess = $null
$siteProcess = $null
try {
    $apiArguments = @('-d','extension=pdo_sqlite','-d','extension=sqlite3','-S',"127.0.0.1:$ManagementPort",'-t',('"' + (Join-Path $PSScriptRoot 'public') + '"'),('"' + (Join-Path $PSScriptRoot 'server.php') + '"'))
    $apiProcess = Start-Process -FilePath $ManagementPhp -ArgumentList $apiArguments -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -PassThru
    $sitePublic = Join-Path $PSScriptRoot 'institucional/public_html'
    $siteArguments = @('-S',"127.0.0.1:$SitePort",'-t',('"' + $sitePublic + '"'),('"' + (Join-Path $sitePublic 'index.php') + '"'))
    $siteProcess = Start-Process -FilePath $SitePhp -ArgumentList $siteArguments -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -PassThru
    Write-Output "Site e planos: http://127.0.0.1:$SitePort/planos-saude/"
    Write-Output "Área do cliente: http://127.0.0.1:$SitePort/area-do-cliente"
    Write-Output "Gestão: http://127.0.0.1:$ManagementPort/painel"
    Write-Output 'Mantenha esta janela aberta. Ctrl+C encerra os dois servidores locais.'
    while (-not $apiProcess.HasExited -and -not $siteProcess.HasExited) { Start-Sleep -Milliseconds 500 }
} finally {
    foreach ($ownedProcess in @($apiProcess,$siteProcess)) { if ($ownedProcess -and -not $ownedProcess.HasExited) { Stop-Process -Id $ownedProcess.Id -ErrorAction SilentlyContinue } }
}
