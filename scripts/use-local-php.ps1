$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path -Parent $PSScriptRoot
$phpModules = & php -m
if (($phpModules -notcontains 'pdo_sqlite') -or ($phpModules -notcontains 'sqlite3')) {
    $configInformation = & php --ini
    $loadedLine = $configInformation | Where-Object { $_ -match '^Loaded Configuration File:' }
    $loadedConfig = ($loadedLine -replace '^Loaded Configuration File:\s*','').Trim()
    $iniContent = if (Test-Path -LiteralPath $loadedConfig -PathType Leaf) { Get-Content -LiteralPath $loadedConfig -Raw } else { '' }
    if ($phpModules -notcontains 'pdo_sqlite') { $iniContent += "`nextension=pdo_sqlite`n" }
    if ($phpModules -notcontains 'sqlite3') { $iniContent += "`nextension=sqlite3`n" }
    $runtimeDirectory = Join-Path $projectDirectory '.codex'
    New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
    $runtimeConfig = Join-Path $runtimeDirectory 'php-local.ini'
    [System.IO.File]::WriteAllText($runtimeConfig,$iniContent,[System.Text.UTF8Encoding]::new($false))
    $env:PHPRC = $runtimeConfig
}
