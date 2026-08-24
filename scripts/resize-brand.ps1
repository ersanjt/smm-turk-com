# Resize generated 3D brand art into optimized web assets.
$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.Drawing

$srcIcon = "C:\Users\ersan\.cursor\projects\f-smm-turk\assets\smm-turk-icon-3d.png"
$srcLogo = "C:\Users\ersan\.cursor\projects\f-smm-turk\assets\smm-turk-logo-3d.png"
$outDir  = "f:\smm-turk\assets\img"

function Save-Png($bitmap, $path) {
    $bitmap.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
}

function Resize-Square($srcPath, $size, $outPath) {
    $img = [System.Drawing.Image]::FromFile($srcPath)
    $bmp = New-Object System.Drawing.Bitmap($size, $size)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.Clear([System.Drawing.Color]::Transparent)
    $g.DrawImage($img, 0, 0, $size, $size)
    Save-Png $bmp $outPath
    $g.Dispose(); $bmp.Dispose(); $img.Dispose()
    Write-Host "Wrote $outPath ($size x $size)"
}

# Square icon variants
Resize-Square $srcIcon 256 (Join-Path $outDir "logo-icon.png")
Resize-Square $srcIcon 192 (Join-Path $outDir "logo-192.png")
Resize-Square $srcIcon 512 (Join-Path $outDir "logo-512.png")

# OG image: 1200x630, logo centered on white (logo already has white bg)
$img = [System.Drawing.Image]::FromFile($srcLogo)
$W = 1200; $H = 630
$bmp = New-Object System.Drawing.Bitmap($W, $H)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
$g.Clear([System.Drawing.Color]::White)
$scale = [Math]::Min(($W * 0.86) / $img.Width, ($H * 0.86) / $img.Height)
$dw = [int]($img.Width * $scale); $dh = [int]($img.Height * $scale)
$dx = [int](($W - $dw) / 2); $dy = [int](($H - $dh) / 2)
$g.DrawImage($img, $dx, $dy, $dw, $dh)
Save-Png $bmp (Join-Path $outDir "og-default.png")
$g.Dispose(); $bmp.Dispose(); $img.Dispose()
Write-Host "Wrote og-default.png ($W x $H)"

# Full horizontal logo, downscaled for header/marketing use (max width 640)
$img = [System.Drawing.Image]::FromFile($srcLogo)
$scale = 640.0 / $img.Width
$fw = 640; $fh = [int]($img.Height * $scale)
$bmp = New-Object System.Drawing.Bitmap($fw, $fh)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
$g.Clear([System.Drawing.Color]::White)
$g.DrawImage($img, 0, 0, $fw, $fh)
Save-Png $bmp (Join-Path $outDir "logo-full.png")
$g.Dispose(); $bmp.Dispose(); $img.Dispose()
Write-Host "Wrote logo-full.png ($fw x $fh)"

Get-ChildItem $outDir -Filter "logo-*.png" | Select-Object Name, Length | Format-Table -AutoSize
Get-Item (Join-Path $outDir "og-default.png") | Select-Object Name, Length | Format-Table -AutoSize
