param(
    [Parameter(Mandatory = $true)][string]$Database,
    [Parameter(Mandatory = $true)][string]$MySqlDump
)
$ErrorActionPreference = 'Stop'
if ($Database -notmatch '^lgu_logic_test_[a-z0-9_]+$') { throw 'Use a disposable lgu_logic_test_* database.' }
$testRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('lgu_backup_test_' + [guid]::NewGuid().ToString('N'))
$project = Join-Path $testRoot 'htdocs\fixture'
$destination = Join-Path $testRoot 'archives'
$script = Join-Path $project 'scripts\backup_data.ps1'
$folders = @('documents', 'avatars', 'staff_avatars', 'announcement_images')
$checks = 0
function Assert([bool]$ok, [string]$reason) {
    if (-not $ok) { throw $reason }
    $script:checks++
}
function Expect-Failure([scriptblock]$work, [string]$reason) {
    $failed = $false
    try { & $work | Out-Null } catch { $failed = $true }
    Assert $failed $reason
}
try {
    New-Item -ItemType Directory -Path (Split-Path $script) -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot '..\scripts\backup_data.ps1') -Destination $script
    foreach ($folder in $folders) {
        $dir = Join-Path $project $folder
        New-Item -ItemType Directory -Path $dir | Out-Null
        Set-Content -LiteralPath (Join-Path $dir '.htaccess') -Value 'Require all denied' -Encoding ASCII
        (Get-Item -LiteralPath (Join-Path $dir '.htaccess') -Force).Attributes = [System.IO.FileAttributes]::Hidden
        [System.IO.File]::WriteAllBytes((Join-Path $dir 'sample.bin'), [byte[]](0, 1, 2, 127, 255))
    }
    & $script -Destination $destination -Database $Database -MySqlDump $MySqlDump | Out-Null
    $archives = @(Get-ChildItem -LiteralPath $destination -Filter '*.zip')
    Assert ($archives.Count -eq 1) 'A successful run did not publish exactly one archive.'
    $zip = [System.IO.Compression.ZipFile]::OpenRead($archives[0].FullName)
    try {
        $reader = New-Object System.IO.StreamReader($zip.GetEntry('manifest.json').Open())
        try { $manifest = $reader.ReadToEnd() | ConvertFrom-Json } finally { $reader.Dispose() }
        Assert ($manifest.files.Count -eq 9) 'The manifest did not include SQL and all eight fixture files.'
        foreach ($folder in $folders) {
            Assert ($null -ne $zip.GetEntry($folder + '/.htaccess')) "Hidden protection file omitted: $folder"
            Assert ($zip.GetEntry($folder + '/sample.bin').Length -eq 5) "Media file omitted: $folder"
        }
        Assert ($zip.GetEntry('database.sql').Length -gt 0) 'SQL dump is empty.'
    } finally { $zip.Dispose() }
    Expect-Failure { & $script -Destination (Join-Path $project 'public-backups') -Database $Database -MySqlDump $MySqlDump } 'A web-accessible backup destination was accepted.'
    Expect-Failure { & $script -Destination (Join-Path $testRoot 'htdocs\other-project') -Database $Database -MySqlDump $MySqlDump } 'Another folder inside htdocs was accepted.'
    Expect-Failure { & $script -Destination $destination -Database 'lgu_logic_test_database_must_not_exist_98fa' -MySqlDump $MySqlDump 2>$null } 'A failed database export was published.'
    $mediaToMove = Join-Path $project 'avatars'
    $expectedPrefix = [System.IO.Path]::GetFullPath($testRoot).TrimEnd('\') + '\'
    Assert ([System.IO.Path]::GetFullPath($mediaToMove).StartsWith($expectedPrefix, [StringComparison]::OrdinalIgnoreCase)) 'Fixture path escaped the test directory.'
    Move-Item -LiteralPath $mediaToMove -Destination (Join-Path $testRoot 'removed-avatars')
    Expect-Failure { & $script -Destination $destination -Database $Database -MySqlDump $MySqlDump } 'A missing media folder was silently omitted.'
    Assert (@(Get-ChildItem -LiteralPath $destination -Force).Count -eq 1) 'Failure left a partial archive or staging directory.'
    Write-Output "PASS: $checks backup checks (all media, hidden files, invalid destinations and failure cleanup)."
} finally {
    if (Test-Path -LiteralPath $testRoot) {
        $resolved = (Resolve-Path -LiteralPath $testRoot).ProviderPath
        $tempPrefix = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\') + '\'
        if (-not $resolved.StartsWith($tempPrefix, [StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolved -Leaf) -notlike 'lgu_backup_test_*') { throw 'Unsafe fixture cleanup path.' }
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}
