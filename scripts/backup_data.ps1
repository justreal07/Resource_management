param(
    [Parameter(Mandatory = $true)][string]$Destination,
    [string]$Database = 'lgu_assistance_system',
    [string]$MySqlDump = 'C:\xampp\mysql\bin\mysqldump.exe',
    [string]$MySqlOptionsFile = '',
    [string]$MySqlUser = ''
)

$ErrorActionPreference = 'Stop'
$root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$uploads = Join-Path $root 'documents'
$destinationPath = [System.IO.Path]::GetFullPath($Destination)
if (-not (Test-Path -LiteralPath $MySqlDump -PathType Leaf)) { throw "mysqldump not found: $MySqlDump" }
if (-not (Test-Path -LiteralPath $uploads -PathType Container)) { throw "Uploads folder not found: $uploads" }
if ($Database -notmatch '^[A-Za-z0-9_]+$') { throw 'The database name contains unsupported characters.' }
if ($MySqlOptionsFile -and -not (Test-Path -LiteralPath $MySqlOptionsFile -PathType Leaf)) { throw 'MySQL options file not found.' }

New-Item -ItemType Directory -Path $destinationPath -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$stage = [System.IO.Path]::GetFullPath((Join-Path $destinationPath ('.lgu_backup_' + $stamp + '_' + [guid]::NewGuid().ToString('N'))))
$prefix = $destinationPath.TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
if (-not $stage.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) { throw 'Backup staging path is outside the destination.' }
$archive = Join-Path $destinationPath ("lgu_assistance_$stamp.zip")
$partial = "$archive.partial.zip"

try {
    New-Item -ItemType Directory -Path $stage | Out-Null
    $dump = Join-Path $stage 'database.sql'
    $arguments = @()
    if ($MySqlOptionsFile) { $arguments += ('--defaults-extra-file=' + [System.IO.Path]::GetFullPath($MySqlOptionsFile)) }
    if ($MySqlUser) { $arguments += "--user=$MySqlUser" }
    elseif (-not $MySqlOptionsFile) { $arguments += '--user=root' }
    $arguments += @('--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob', "--result-file=$dump", $Database)
    & $MySqlDump @arguments
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $dump -PathType Leaf)) { throw 'Database dump failed; no backup was published.' }
    Copy-Item -LiteralPath $uploads -Destination (Join-Path $stage 'documents') -Recurse
    Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $partial -CompressionLevel Optimal
    Move-Item -LiteralPath $partial -Destination $archive
    Write-Output "Backup saved: $archive"
} finally {
    if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
    if (Test-Path -LiteralPath $partial) { Remove-Item -LiteralPath $partial -Force }
}
