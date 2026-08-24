# Push changes to GitHub (triggers auto-deploy on smm-turk.com)
# Usage: powershell -File scripts/push.ps1 "commit message"

param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string]$Message
)

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $RepoRoot

git -c safe.directory=F:/smm-turk add -A
$status = git -c safe.directory=F:/smm-turk status --porcelain
if (-not $status) {
    Write-Host "Nothing to commit." -ForegroundColor Yellow
    exit 0
}

git -c safe.directory=F:/smm-turk commit -m $Message
git -c safe.directory=F:/smm-turk push origin main

Write-Host ""
Write-Host "Pushed. Auto-deploy should update https://smm-turk.com within seconds." -ForegroundColor Green
Write-Host "Webhook status: https://github.com/ersanjt/smm-turk-panel/settings/hooks" -ForegroundColor Cyan
