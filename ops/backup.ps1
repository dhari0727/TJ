<#
  JourneyAI — backup: database dump + uploaded photos/videos, with retention.

  Usage:   powershell -ExecutionPolicy Bypass -File ops\backup.ps1
           powershell -File ops\backup.ps1 -Destination D:\backups\journeyai -KeepDays 30
  Schedule daily with Task Scheduler (docs\DEPLOYMENT.md). Credentials come from the same env vars the app uses
  (JOURNEYAI_DB_HOST / _PORT / _USER / _PASS / _NAME); defaults match a local XAMPP install.
  Each run writes:  journeyai-db-YYYYMMDD-HHMMSS.sql.gz   and   journeyai-uploads-YYYYMMDD-HHMMSS.zip
#>
param(
    [string]$Destination = (Join-Path (Split-Path $PSScriptRoot -Parent) 'backups'),
    [int]$KeepDays = 14,
    [string]$MysqlDump = 'C:\xampp\mysql\bin\mysqldump.exe'
)
$ErrorActionPreference = 'Stop'
$root   = Split-Path $PSScriptRoot -Parent
$stamp  = Get-Date -Format 'yyyyMMdd-HHmmss'
$dbHost = if ($env:JOURNEYAI_DB_HOST) { $env:JOURNEYAI_DB_HOST } else { '127.0.0.1' }
$dbPort = if ($env:JOURNEYAI_DB_PORT) { $env:JOURNEYAI_DB_PORT } else { '3306' }
$dbUser = if ($env:JOURNEYAI_DB_USER) { $env:JOURNEYAI_DB_USER } else { 'root' }
$dbName = if ($env:JOURNEYAI_DB_NAME) { $env:JOURNEYAI_DB_NAME } else { 'project' }

if (-not (Test-Path $MysqlDump)) { throw "mysqldump not found at $MysqlDump (use -MysqlDump)" }
New-Item -ItemType Directory -Force -Path $Destination | Out-Null

# --- database: consistent snapshot, routines included, gzip-compressed ---
$sqlFile = Join-Path $Destination "journeyai-db-$stamp.sql"
$args = @("--host=$dbHost", "--port=$dbPort", "--user=$dbUser", '--single-transaction', '--routines', '--default-character-set=utf8mb4', "--result-file=$sqlFile")
if ($env:JOURNEYAI_DB_PASS) { $args += "--password=$($env:JOURNEYAI_DB_PASS)" }
& $MysqlDump @args $dbName
if ($LASTEXITCODE -ne 0) { throw "mysqldump failed with exit code $LASTEXITCODE" }
$in  = [IO.File]::OpenRead($sqlFile)
$out = [IO.File]::Create("$sqlFile.gz")
$gz  = New-Object IO.Compression.GZipStream($out, [IO.Compression.CompressionMode]::Compress)
$in.CopyTo($gz); $gz.Dispose(); $out.Dispose(); $in.Dispose()
Remove-Item $sqlFile

# --- uploads (skip when empty) ---
$uploads = Join-Path $root 'uploads'
$files = Get-ChildItem $uploads -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne '.htaccess' }
if ($files) {
    Compress-Archive -Path (Join-Path $uploads '*') -DestinationPath (Join-Path $Destination "journeyai-uploads-$stamp.zip") -CompressionLevel Fastest
}

# --- retention ---
Get-ChildItem $Destination -File | Where-Object { $_.Name -like 'journeyai-*' -and $_.LastWriteTime -lt (Get-Date).AddDays(-$KeepDays) } | Remove-Item -Force

$size = [math]::Round((Get-Item "$sqlFile.gz").Length / 1KB, 1)
Write-Host "Backup OK: $sqlFile.gz ($size KB)$(if ($files) { ' + uploads zip' })  ->  $Destination"
