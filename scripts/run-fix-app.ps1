# Run fix-app.php on smm-turk.com (syncs critical files + updates deploy-smm.sh)
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

Write-Host "GET fix-app.php ..."
$response = Invoke-WebRequest -Uri "https://smm-turk.com/fix-app.php?key=$secret" -UseBasicParsing
Write-Host "Status:" $response.StatusCode
Write-Host $response.Content
