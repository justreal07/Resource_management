param(
    [Parameter(Mandatory = $true)][string]$Destination,
    [string]$Database = 'lgu_assistance_system',
    [string]$MySqlDump = '',
    [string]$MySqlOptionsFile = '',
    [string]$MySqlUser = ''
)

$ErrorActionPreference = 'Stop'
$root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$mediaFolders = @('documents', 'avatars', 'staff_avatars', 'announcement_images')
$destinationPath = [System.IO.Path]::GetFullPath($Destination)

# Prefer the XAMPP installation containing this project, regardless of its drive or folder name.
if (-not $MySqlDump) {
    $ancestor = [System.IO.DirectoryInfo]$root
    while ($ancestor -and -not $MySqlDump) {
        $candidate = Join-Path $ancestor.FullName 'mysql\bin\mysqldump.exe'
        if (Test-Path -LiteralPath $candidate -PathType Leaf) { $MySqlDump = $candidate }
        $ancestor = $ancestor.Parent
    }
    if (-not $MySqlDump) {
        $command = Get-Command mysqldump.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($command) { $MySqlDump = $command.Source }
    }
}
if (-not $MySqlDump) { throw 'mysqldump was not found. Supply -MySqlDump with its full path.' }
if (-not (Test-Path -LiteralPath $MySqlDump -PathType Leaf)) { throw "mysqldump not found: $MySqlDump" }
if ($Database -notmatch '^[A-Za-z0-9_]+$') { throw 'The database name contains unsupported characters.' }
if ($MySqlOptionsFile -and -not (Test-Path -LiteralPath $MySqlOptionsFile -PathType Leaf)) { throw 'MySQL options file not found.' }

# Never place a dump of private records in this project or its known web root.
$webRoot = $root
$ancestor = [System.IO.DirectoryInfo]$root
while ($ancestor) {
    if ($ancestor.Name -ieq 'htdocs' -or $ancestor.Name -ieq 'wwwroot') { $webRoot = $ancestor.FullName; break }
    $ancestor = $ancestor.Parent
}
$webPrefix = $webRoot.TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
if ($destinationPath -ieq $webRoot -or $destinationPath.StartsWith($webPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Choose a backup destination outside the web folder.'
}
# Junctions could redirect the destination or copied folders outside the checked paths.
$ancestor = [System.IO.DirectoryInfo]$destinationPath
while ($ancestor) {
    if ($ancestor.Exists -and ($ancestor.Attributes -band [System.IO.FileAttributes]::ReparsePoint)) { throw 'The destination must not use junctions or symbolic links.' }
    $ancestor = $ancestor.Parent
}
function Get-MediaInventory([string]$base) {
    foreach ($folder in $mediaFolders) {
        $source = Join-Path $base $folder
        if (-not (Test-Path -LiteralPath $source -PathType Container)) { throw "Uploads folder not found: $source" }
        $items = @((Get-Item -LiteralPath $source -Force)) + @(Get-ChildItem -LiteralPath $source -Force -Recurse)
        foreach ($item in $items) {
            if ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) { throw "Uploads must not use junctions or symbolic links: $($item.FullName)" }
            if (-not $item.PSIsContainer) {
                [pscustomobject]@{
                    path = $item.FullName.Substring($base.TrimEnd('\', '/').Length + 1).Replace('\', '/')
                    bytes = $item.Length
                    sha256 = (Get-FileHash -LiteralPath $item.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
                }
            }
        }
    }
}
$before = @(Get-MediaInventory $root | Sort-Object path)
New-Item -ItemType Directory -Path $destinationPath -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$suffix = $stamp + '_' + [guid]::NewGuid().ToString('N').Substring(0, 8)
$stage = [System.IO.Path]::GetFullPath((Join-Path $destinationPath ('.lgu_backup_' + $suffix)))
$prefix = $destinationPath.TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
if (-not $stage.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) { throw 'Backup staging path is outside the destination.' }
$archive = Join-Path $destinationPath ("lgu_assistance_$suffix.zip")
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
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $dump -PathType Leaf) -or (Get-Item -LiteralPath $dump).Length -eq 0) { throw 'Database dump failed; no backup was published.' }
    foreach ($folder in $mediaFolders) {
        Copy-Item -LiteralPath (Join-Path $root $folder) -Destination (Join-Path $stage $folder) -Recurse -Force
    }
    $copied = @(Get-MediaInventory $stage | Sort-Object path)
    $after = @(Get-MediaInventory $root | Sort-Object path)
    $beforeJson = ConvertTo-Json -InputObject $before -Compress
    if ($beforeJson -cne (ConvertTo-Json -InputObject $copied -Compress) -or $beforeJson -cne (ConvertTo-Json -InputObject $after -Compress)) {
        throw 'Uploads changed during backup. Pause submissions and document changes, then run it again.'
    }
    $files = @([pscustomobject]@{
        path = 'database.sql'; bytes = (Get-Item -LiteralPath $dump).Length
        sha256 = (Get-FileHash -LiteralPath $dump -Algorithm SHA256).Hash.ToLowerInvariant()
    }) + $copied
    $manifest = [ordered]@{
        format_version = 1; created_at_utc = [DateTime]::UtcNow.ToString('o'); database = $Database
        media_folders = $mediaFolders; files = $files
        note = 'Contains files present at backup time. Previously missing uploads cannot be recovered from this archive. Pause application writes while backing up.'
    }
    $manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $stage 'manifest.json') -Encoding UTF8
    # Add files explicitly: keep hidden .htaccess files and portable '/' entry names on older Windows .NET too.
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::Open($partial, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($folder in $mediaFolders) { $zip.CreateEntry($folder + '/') | Out-Null }
        foreach ($file in $files) {
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, (Join-Path $stage $file.path), $file.path) | Out-Null
        }
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, (Join-Path $stage 'manifest.json'), 'manifest.json') | Out-Null
    } finally { $zip.Dispose() }
    $zip = [System.IO.Compression.ZipFile]::OpenRead($partial)
    try {
        foreach ($file in $files) {
            $entry = $zip.GetEntry($file.path)
            if (-not $entry -or $entry.Length -ne $file.bytes) { throw "Backup entry is missing or incomplete: $($file.path)" }
            $stream = $entry.Open()
            $sha = [System.Security.Cryptography.SHA256]::Create()
            try { $hash = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '').ToLowerInvariant() }
            finally { $sha.Dispose(); $stream.Dispose() }
            if ($hash -cne $file.sha256) { throw "Backup verification failed: $($file.path)" }
        }
        if (-not $zip.GetEntry('manifest.json')) { throw 'Backup manifest is missing.' }
    } finally { $zip.Dispose() }
    Move-Item -LiteralPath $partial -Destination $archive
    Write-Output "Backup saved: $archive"
    Write-Output "Verified database and $($copied.Count) media files (including folder protection files)."
} finally {
    if (Test-Path -LiteralPath $stage) {
        $resolvedStage = (Resolve-Path -LiteralPath $stage).ProviderPath
        if (-not $resolvedStage.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase) -or ((Get-Item -LiteralPath $stage -Force).Attributes -band [System.IO.FileAttributes]::ReparsePoint)) {
            throw 'Refusing to clean up a staging folder outside the backup destination.'
        }
        Remove-Item -LiteralPath $stage -Recurse -Force
    }
    if (Test-Path -LiteralPath $partial) { Remove-Item -LiteralPath $partial -Force }
}
