# Trigger git-based rsync deploy on smm-turk.com (deploy-webhook.php?repair=1)
# This runs `git pull` + rsync from the server-side repo, bypassing raw.githubusercontent CDN cache.
$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path -Parent $PSScriptRoot
$secretFile = Join-Path $RepoRoot "deploy-secret.txt"
if (-not (Test-Path $secretFile)) {
    Write-Error "deploy-secret.txt not found"
}
$secret = ""
Get-Content $secretFile | ForEach-Object {
    if ($_ -match '^WEBHOOK_SECRET=(.+)$') { $secret = $matches[1].Trim() }
}
if (-not $secret) { Write-Error "WEBHOOK_SECRET missing in deploy-secret.txt" }

Write-Host "GET deploy-webhook.php?repair=1 ..."
$response = Invoke-WebRequest -Uri "https://smm-turk.com/deploy-webhook.php?repair=1&key=$secret" -UseBasicParsing -TimeoutSec 120
Write-Host "Status:" $response.StatusCode
Write-Host $response.Content
