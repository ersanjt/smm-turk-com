# Repair a child panel on smm-turk.com (fixes 403 when files missing)
param(
    [int]$PanelId = 2,
    [string]$Action = "full"
)

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

$uri = "https://smm-turk.com/repair-panel.php?key=$secret&panel_id=$PanelId&action=$Action"
Write-Host "GET repair-panel.php (panel_id=$PanelId, action=$Action) ..."
$response = Invoke-WebRequest -Uri $uri -UseBasicParsing
Write-Host "Status:" $response.StatusCode
Write-Host $response.Content
