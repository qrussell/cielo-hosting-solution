<#
.SYNOPSIS
Build script for the Cielo Hosting Solution plugin on Windows.
#>

$PluginName = "cielo-hosting-solution"
$OutputZip = "$PluginName.zip"

Write-Host "Packaging $PluginName..." -ForegroundColor Cyan

# 1. Remove old zip if it exists in the current directory
if (Test-Path $OutputZip) {
    Write-Host "Removing older build..." -ForegroundColor Yellow
    Remove-Item $OutputZip -Force
}

# 2. Create a temporary staging directory in the Windows Temp folder
$TempDir = Join-Path $env:TEMP $PluginName
if (Test-Path $TempDir) { Remove-Item $TempDir -Recurse -Force }
New-Item -ItemType Directory -Path $TempDir | Out-Null

# 3. Define the folders and files to exclude
$ExcludedDirs = @(".git", ".github", "node_modules", "tests")
$ExcludedFiles = @(".DS_Store", "phpunit.xml", "phpcs.xml", "composer.json", "composer.lock", "package.json", "package-lock.json", "gulpfile.js", "webpack.config.js", "*.zip", "build.ps1", "build.sh")

# 4. Use Robocopy to copy files to the staging folder (excluding the lists above)
Write-Host "Applying exclusions and staging files..." 
$RobocopyArgs = @(".", $TempDir, "/E", "/NFL", "/NDL", "/NJH", "/NJS", "/nc", "/ns", "/np")
$RobocopyArgs += "/XD"
$RobocopyArgs += $ExcludedDirs
$RobocopyArgs += "/XF"
$RobocopyArgs += $ExcludedFiles

# Run robocopy (We pipe to Out-Null to hide its verbose console output)
& robocopy $RobocopyArgs | Out-Null

# 5. Zip the staging directory
Write-Host "Compressing files into $OutputZip..."
Compress-Archive -Path "$TempDir\*" -DestinationPath ".\$OutputZip"

# 6. Clean up temporary files
Remove-Item $TempDir -Recurse -Force

Write-Host "✅ Success! Plugin packaged as $OutputZip" -ForegroundColor Green