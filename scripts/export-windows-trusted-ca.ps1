param([Parameter(Mandatory=$true)][string]$OutputPath)
$ErrorActionPreference='Stop'
# Only public CA certificates already trusted by Windows; never private keys.
$certificates=@(Get-ChildItem Cert:\CurrentUser\Root; Get-ChildItem Cert:\LocalMachine\Root) | Sort-Object Thumbprint -Unique
$pem=($certificates | ForEach-Object {
    "-----BEGIN CERTIFICATE-----`n"+[Convert]::ToBase64String($_.RawData,[Base64FormattingOptions]::InsertLineBreaks)+"`n-----END CERTIFICATE-----`n"
}) -join "`n"
[IO.File]::WriteAllText([IO.Path]::GetFullPath($OutputPath),$pem,(New-Object Text.UTF8Encoding($false)))
Write-Output ('Certificados públicos de CAs confiáveis exportados: '+$certificates.Count)
