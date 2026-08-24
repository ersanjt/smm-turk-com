# Auto-deploy setup: local -> GitHub -> smm-turk.com
# Run: powershell -ExecutionPolicy Bypass -File scripts/setup-auto-deploy.ps1

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $RepoRoot

$WebhookUrl = "https://smm-turk.com/deploy-webhook.php"
$DeployScriptPath = "/home/smmturk/deploy-smm.sh"

Write-Host "=== SMM Turk Auto Deploy Setup ===" -ForegroundColor Cyan

git config --global --add safe.directory F:/smm-turk 2>$null

$ghOk = $false
try {
    gh auth status 2>$null | Out-Null
    $ghOk = $true
} catch {
    Write-Host "gh not logged in. Create webhook manually on GitHub." -ForegroundColor Yellow
}

$bytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$secret = [BitConverter]::ToString($bytes).Replace("-", "").ToLower()

$secretFile = Join-Path $RepoRoot "deploy-secret.txt"
$lines = @(
    "# Upload to server: /home/smmturk/deploy-secret.txt"
    "WEBHOOK_SECRET=$secret"
    "DEPLOY_SCRIPT=$DeployScriptPath"
)
[System.IO.File]::WriteAllLines($secretFile, $lines)

Write-Host "Created deploy-secret.txt (local only, gitignored)" -ForegroundColor Green

if ($ghOk) {
    $hooksJson = gh api repos/ersanjt/smm-turk-panel/hooks 2>$null
    $existingId = $null
    if ($hooksJson) {
        $hooks = $hooksJson | ConvertFrom-Json
        foreach ($h in $hooks) {
            if ($h.config.url -eq $WebhookUrl) {
                $existingId = $h.id
                break
            }
        }
    }

    $payload = @{
        name   = "web"
        active = $true
        events = @("push")
        config = @{
            url          = $WebhookUrl
            content_type = "json"
            secret       = $secret
            insecure_ssl = "0"
        }
    }
    $json = $payload | ConvertTo-Json -Depth 5 -Compress

    if ($existingId) {
        Write-Host "Updating existing webhook id $existingId ..." -ForegroundColor Yellow
        $json | gh api --method PATCH "repos/ersanjt/smm-turk-panel/hooks/$existingId" --input - | Out-Null
    } else {
        $json | gh api --method POST repos/ersanjt/smm-turk-panel/hooks --input - | Out-Null
        Write-Host "GitHub webhook created." -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "=== One-time cPanel steps ===" -ForegroundColor Cyan
Write-Host "1) File Manager -> /home/smmturk/"
Write-Host "   Upload deploy-secret.txt from project root"
Write-Host ""
Write-Host "2) Copy scripts/deploy-cpanel.sh to /home/smmturk/deploy-smm.sh"
Write-Host "   Set permissions to 755"
Write-Host ""
Write-Host "3) Test: powershell -File scripts/push.ps1 ""test auto deploy"""
Write-Host "   Check: GitHub -> Settings -> Webhooks -> Recent Deliveries (200)"
Write-Host ""
