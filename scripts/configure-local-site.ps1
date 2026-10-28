param(
    [string]$ManagementApiUrl = 'http://127.0.0.1:8765/api/site/v1',
    [string]$PublicSiteUrl = 'http://127.0.0.1:8080'
)
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $taskRoot '.env'
$contents = [IO.File]::ReadAllText($envPath)
if ($contents -notmatch '(?m)^APP_ENV=local\s*$' -or $contents -notmatch '(?m)^APP_DEMO=true\s*$') {
    throw 'Este configurador atende somente ao ambiente local com APP_DEMO=true. Use a documentação para produção.'
}
foreach ($value in @($ManagementApiUrl, $PublicSiteUrl)) {
    $uri = [Uri]$value
    if ($uri.Host -notin @('127.0.0.1', 'localhost') -or $uri.Scheme -ne 'http') { throw 'Use somente endereços HTTP locais neste configurador.' }
}
$privateDirectory = Join-Path $taskRoot 'institucional/configuracao_privada'
$portalPath = Join-Path $privateDirectory 'portal.php'
if (Test-Path -LiteralPath $portalPath) { throw 'A configuração privada do portal já existe. Preserve a chave e ajuste os endereços seguindo a documentação.' }
$backupDirectory = Join-Path $taskRoot 'storage/app/private/portal-setup'
New-Item -ItemType Directory -Force -Path $privateDirectory, $backupDirectory | Out-Null
Copy-Item -LiteralPath $envPath -Destination (Join-Path $backupDirectory ('env-before-site-' + [Guid]::NewGuid().ToString('N') + '.backup'))
function New-PortalSecret {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
}
$portalKey = New-PortalSecret
$settings = [ordered]@{ SITE_API_ENABLED = 'true'; SITE_API_KEY = $portalKey; SITE_PUBLIC_URL = $PublicSiteUrl; SITE_TOKEN_HOURS = '24' }
foreach ($setting in $settings.GetEnumerator()) {
    $pattern = '(?m)^' + [regex]::Escape($setting.Key) + '=.*$'
    $line = $setting.Key + '=' + $setting.Value
    if ([regex]::IsMatch($contents, $pattern)) { $contents = [regex]::Replace($contents, $pattern, $line) }
    else { $contents = $contents.TrimEnd() + [Environment]::NewLine + $line + [Environment]::NewLine }
}
$utf8 = New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText($envPath, $contents, $utf8)
$portalPhp = "<?php`nreturn ['url' => '$ManagementApiUrl', 'key' => '$portalKey'];`n"
[IO.File]::WriteAllText($portalPath, $portalPhp, $utf8)
$appPath = Join-Path $privateDirectory 'app.php'
if (-not (Test-Path -LiteralPath $appPath)) {
    $sessionSecret = New-PortalSecret
    [IO.File]::WriteAllText($appPath, "<?php`nreturn ['secret' => '$sessionSecret'];`n", $utf8)
}
Write-Output 'Conexão local configurada. A chave foi gravada somente nos arquivos privados dos dois projetos.'
Write-Output 'As configurações de pagamento real e os cadastros existentes foram preservados.'
