# Trigger deploy webhook on smm-turk.com (simulates GitHub push)
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

$body = '{"ref":"refs/heads/main","repository":{"full_name":"ersanjt/smm-turk-panel"}}'
$encoding = [Text.Encoding]::UTF8
$hmac = [System.Security.Cryptography.HMACSHA256]::new($encoding.GetBytes($secret))
$hash = $hmac.ComputeHash($encoding.GetBytes($body))
$sig = 'sha256=' + (-join ($hash | ForEach-Object { $_.ToString('x2') }))

Write-Host "POST deploy-webhook.php ..."
$response = Invoke-WebRequest -Uri "https://smm-turk.com/deploy-webhook.php" -Method POST `
    -Body $body -ContentType "application/json" `
    -Headers @{ "X-Hub-Signature-256" = $sig } -UseBasicParsing
Write-Host "Status:" $response.StatusCode
Write-Host $response.Content
