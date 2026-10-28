param([switch]$Scheduler)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
. (Join-Path $PSScriptRoot 'scripts/use-local-php.ps1')
if ($Scheduler) { & php artisan schedule:work }
else { & php artisan queue:work --queue=vindi,simplesvet,tudoprapet,partners,default --tries=3 --timeout=120 }
exit $LASTEXITCODE
