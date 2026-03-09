param (
    [string]$OutputZip = "patch_v37.zip"
)

$filesToZip = @(
    "fix_v37_ultimate.php",
    "admin/reset_championship.php",
    "admin/members.php",
    "services/MemberService.php"
)

# Remove the old zip if it exists
if (Test-Path $OutputZip) {
    Remove-Item $OutputZip -Force
}

Write-Host "Creating patch file: $OutputZip"

# Create a temporary directory structure to preserve paths
$tempDir = Join-Path $env:TEMP "patch_temp_$(Get-Random)"
New-Item -ItemType Directory -Path $tempDir | Out-Null

foreach ($file in $filesToZip) {
    if (Test-Path $file) {
        $destPath = Join-Path $tempDir $file
        $destDir = Split-Path $destPath
        
        if (!(Test-Path $destDir)) {
            New-Item -ItemType Directory -Path $destDir | Out-Null
        }
        
        Copy-Item $file -Destination $destPath
        Write-Host "Added: $file"
    } else {
        Write-Host "WARNING: File not found: $file" -ForegroundColor Yellow
    }
}

# Zip the temporary directory
Compress-Archive -Path "$tempDir\*" -DestinationPath $OutputZip
Remove-Item -Recurse -Force $tempDir

Write-Host "Success! Upload $OutputZip to the root directory of your server and extract it." -ForegroundColor Green
Write-Host "After extracting, run: https://yourdomain.com/fix_v37_ultimate.php" -ForegroundColor Cyan
