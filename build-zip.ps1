# Builds a release zip for Nexora Media (free wp.org plugin).
#
# IMPORTANT: do NOT use Compress-Archive — it writes backslash zip entry paths
# that break extraction on Linux/WordPress. Windows 10+ ships bsdtar (tar.exe)
# which produces standard forward-slash entries. This stages the runtime files
# (excluding dev source, node_modules, build configs) and zips with tar.
#
# Nexora Media is fully FREE — there is no pro build. This single zip is what
# ships to wp.org.
#
# Usage:  & "$PSScriptRoot\build-zip.ps1"

$ErrorActionPreference = 'Stop'

# Layout: <root>\products\nexora-media\  ->  release at <root>\release\nexora-media\
$src      = $PSScriptRoot
$root     = Split-Path (Split-Path $src -Parent) -Parent
$relProd  = Join-Path (Join-Path $root 'release') 'nexora-media'
$stage    = Join-Path $relProd 'nexora-media'

# Read version from the plugin header so the zip name always matches.
$verLine  = Select-String -Path (Join-Path $src 'nexora-media.php') -Pattern '^\s*\*\s*Version:\s*([\d.]+)' | Select-Object -First 1
$version  = if ($verLine) { $verLine.Matches[0].Groups[1].Value } else { '0.0.0' }
$zipName  = "nexora-media-$version.zip"
$zipPath  = Join-Path $relProd $zipName

# Fresh stage.
if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Force $stage | Out-Null

# Runtime directories only. frontend/ source, node_modules, and dev tooling are
# never shipped. assets/ ships (it holds dist/ + admin css/js).
$dirs = @('admin', 'assets', 'includes', 'languages')
foreach ($d in $dirs) {
    if (Test-Path (Join-Path $src $d)) {
        robocopy (Join-Path $src $d) (Join-Path $stage $d) /E /NFL /NDL /NJH /NJS /XD 'node_modules' | Out-Null
    }
}

# Root files.
$rootFiles = @('nexora-media.php', 'readme.txt', 'uninstall.php', 'index.php')
foreach ($f in $rootFiles) {
    if (Test-Path (Join-Path $src $f)) { Copy-Item (Join-Path $src $f) $stage -Force }
}

# Zip with bsdtar from inside the release dir so the archive root is the
# nexora-media/ folder with forward-slash entry paths.
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Push-Location $relProd
try {
    & tar.exe -a -cf $zipName 'nexora-media'
    if ($LASTEXITCODE -ne 0) { throw "tar failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
}

# Sanity checks.
$entries = & tar.exe -tf $zipPath
if ($entries -match '\\') { throw 'Zip contains backslash entry paths — do not ship this archive.' }
if (-not ($entries -contains 'nexora-media/nexora-media.php')) { throw 'nexora-media.php missing from archive root.' }
if ($entries | Select-String -SimpleMatch 'node_modules', 'frontend/', '/package.json', 'vite.config') {
    throw 'Dev cruft (node_modules/frontend/build configs) leaked into the zip.'
}

Write-Output "OK: $zipPath"
Write-Output ("Version: $version")
Write-Output ("Entries: " + ($entries | Measure-Object).Count)
