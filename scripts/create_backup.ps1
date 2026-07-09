param(
    [string]$BackupFile = "backups\source_backup.zip"
)

$ErrorActionPreference = "Stop"

$root = (Get-Location).ProviderPath
if ([System.IO.Path]::IsPathRooted($BackupFile)) {
    $backupPath = $BackupFile
} else {
    $backupPath = Join-Path $root $BackupFile
}

$backupDir = Split-Path -Parent $backupPath
if (-not (Test-Path -LiteralPath $backupDir)) {
    New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
}

if (Test-Path -LiteralPath $backupPath) {
    Remove-Item -LiteralPath $backupPath -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$excludedDirs = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
@('.git', 'backups', 'node_modules', 'vendor', 'sessions', 'cache', 'tmp', 'temp') | ForEach-Object {
    [void]$excludedDirs.Add($_)
}

$zip = [System.IO.Compression.ZipFile]::Open($backupPath, [System.IO.Compression.ZipArchiveMode]::Create)
$fileCount = 0

try {
    Get-ChildItem -LiteralPath $root -Force -Recurse -File | ForEach-Object {
        $relativePath = $_.FullName.Substring($root.Length).TrimStart('\', '/')
        $parts = $relativePath -split '[\\/]'

        foreach ($part in $parts) {
            if ($excludedDirs.Contains($part)) {
                return
            }
        }

        $entryName = $relativePath -replace '\\', '/'
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip,
            $_.FullName,
            $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
        $script:fileCount++
    }
} finally {
    $zip.Dispose()
}

if ($fileCount -eq 0) {
    throw "No files were added to the backup."
}

$backup = Get-Item -LiteralPath $backupPath
if ($backup.Length -le 0) {
    throw "Backup file is empty."
}

Write-Host "Backup created: $($backup.FullName) ($($backup.Length) bytes, $fileCount files)"
