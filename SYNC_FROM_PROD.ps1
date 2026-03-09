$user = "u670530239"
$pass = "u?j?4F9wX/#r45R@"
$baseUrl = "ftp://145.223.86.39/domains/yellowgreen-quail-410393.hostingersite.com/public_html"

$webclient = New-Object System.Net.WebClient
$webclient.Credentials = New-Object System.Net.NetworkCredential($user, $pass)

function Download($remotePath, $localPath) {
    Write-Host "Downloading $remotePath..." -ForegroundColor Cyan
    try {
        $fullLocal = (Resolve-Path -Path ".\" -ErrorAction SilentlyContinue).ProviderPath + "\" + $localPath
        
        # Create directory if it doesn't exist
        $dir = Split-Path $fullLocal
        if (!(Test-Path $dir)) {
            New-Item -ItemType Directory -Force -Path $dir | Out-Null
        }
        
        $webclient.DownloadFile("$baseUrl/$remotePath", $fullLocal)
        Write-Host "OK" -ForegroundColor Green
    }
    catch {
        Write-Host "FAILED: $_" -ForegroundColor Red
    }
}

Write-Host "=== PULL LIVE DATA LITE ===" -ForegroundColor Yellow

# Backup existing local data just in case
if (Test-Path ".\admin\data\data.json") { Copy-Item ".\admin\data\data.json" ".\admin\data\data_bck.json" -Force }
if (Test-Path ".\admin\data\members.json") { Copy-Item ".\admin\data\members.json" ".\admin\data\members_bck.json" -Force }

Download "admin/data/data.json" "admin\data\data.json"
Download "admin/data/members.json" "admin\data\members.json"
Download "database/drb.sqlite" "database\drb.sqlite"

Write-Host "DATA SYNC DONE." -ForegroundColor Yellow
