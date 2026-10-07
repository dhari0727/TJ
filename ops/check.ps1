<#
  JourneyAI — pre-deploy check. Run before every release:  powershell -File ops\check.ps1
  1. PHP syntax (php -l) for every .php file
  2. Python compile check for the ML service
  3. JavaScript syntax check (if node is installed)
  4. End-to-end smoke test against the running site (tests\smoke.py)
  Exit code 0 = safe to ship.
#>
param(
    [string]$Php = 'C:\xampp\php\php.exe',
    [string]$Python = '',
    [string]$BaseUrl = 'http://localhost/travel_journel',
    [switch]$SkipSmoke
)
$root = Split-Path $PSScriptRoot -Parent
Set-Location $root
if (-not $Python) { $Python = Join-Path $root 'ml\venv\Scripts\python.exe' }
$fail = 0

Write-Host '== PHP syntax'
$bad = @()
Get-ChildItem $root -Filter *.php -File | ForEach-Object {
    $o = & $Php -l $_.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { $bad += "$($_.Name): $o" }
}
if ($bad) { $bad | ForEach-Object { Write-Host "  FAIL $_" -ForegroundColor Red }; $fail++ } else { Write-Host '  all files OK' -ForegroundColor Green }

Write-Host '== Python compile'
& $Python -m compileall -q (Join-Path $root 'ml') -x 'venv' | Out-Null
if ($LASTEXITCODE -ne 0) { Write-Host '  FAIL compileall' -ForegroundColor Red; $fail++ } else { Write-Host '  OK' -ForegroundColor Green }

Write-Host '== JavaScript syntax'
if (Get-Command node -ErrorAction SilentlyContinue) {
    $jsBad = @()
    Get-ChildItem (Join-Path $root 'js') -Filter *.js -File | Where-Object { $_.Name -notlike '*.min.js' } | ForEach-Object {
        & node --check $_.FullName 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { $jsBad += $_.Name }
    }
    if ($jsBad) { Write-Host "  FAIL: $($jsBad -join ', ')" -ForegroundColor Red; $fail++ } else { Write-Host '  OK' -ForegroundColor Green }
} else { Write-Host '  node not installed, skipped' }

if (-not $SkipSmoke) {
    Write-Host "== Smoke test ($BaseUrl)"
    $env:BASE_URL = $BaseUrl
    & $Python (Join-Path $root 'tests\smoke.py')
    if ($LASTEXITCODE -ne 0) { $fail++ }
}

if ($fail) { Write-Host "`nCHECK FAILED ($fail problem area(s))" -ForegroundColor Red; exit 1 }
Write-Host "`nALL CHECKS PASSED" -ForegroundColor Green
