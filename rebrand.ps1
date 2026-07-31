# rebrand.ps1
# Run this script in the root directory of your plugin.

$folderPath = ".\"

# 1. Define exact, CASE-SENSITIVE replacements. 
# Order matters: Longest/most specific strings must be replaced first!
$replacements = @(
    @{ Find = 'skyhs-hosting-solution'; Replace = 'cielo-hosting-solution' },
    @{ Find = 'skyhshoso-'; Replace = 'cielo-' },
    @{ Find = 'skyhshoso_'; Replace = 'cielo_' },
    @{ Find = 'SkyHSHOSO_'; Replace = 'Cielo_' },
    @{ Find = 'SKYHSHOSO_'; Replace = 'CIELO_' },
    @{ Find = 'SkyHSHOSO';  Replace = 'Cielo' },
    @{ Find = 'skyhs-';     Replace = 'cielo-' },
    @{ Find = 'skyhs_';     Replace = 'cielo_' },
    @{ Find = 'SkyHS';      Replace = 'Cielo' },
    @{ Find = 'skyhs';      Replace = 'cielo' }
)

# Target file types (ignoring binaries, zips, and images)
$extensions = "*.php", "*.js", "*.css", "*.txt", "*.md", "*.json", "*.xml"
$files = Get-ChildItem -Path $folderPath -Include $extensions -Recurse -File

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "   CIELO HOSTING SOLUTION REBRANDER" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan

# ---------------------------------------------------------
# STEP 1: Replace text inside files
# ---------------------------------------------------------
Write-Host "`n[1/3] Updating file contents..." -ForegroundColor Yellow
$updatedCount = 0

foreach ($file in $files) {
    # Read using .NET to perfectly preserve file encodings and line breaks
    $originalContent = [System.IO.File]::ReadAllText($file.FullName)
    if ([string]::IsNullOrEmpty($originalContent)) { continue }
    
    $newContent = $originalContent
    $modified = $false

    foreach ($pair in $replacements) {
        # -cmatch and -creplace enforce STRICT CASE SENSITIVITY
        if ($newContent -cmatch [regex]::Escape($pair.Find)) {
            $newContent = $newContent -creplace [regex]::Escape($pair.Find), $pair.Replace
            $modified = $true
        }
    }

    if ($modified) {
        [System.IO.File]::WriteAllText($file.FullName, $newContent, [System.Text.Encoding]::UTF8)
        Write-Host "  -> Updated text in: $($file.Name)" -ForegroundColor Green
        $updatedCount++
    }
}
Write-Host "Successfully updated contents in $updatedCount files." -ForegroundColor Cyan

# ---------------------------------------------------------
# STEP 2: Rename Files
# ---------------------------------------------------------
Write-Host "`n[2/3] Renaming files..." -ForegroundColor Yellow
$renameFiles = Get-ChildItem -Path $folderPath -Recurse -File | Where-Object { $_.Name -match 'skyhs' }
$fileRenameCount = 0

foreach ($file in $renameFiles) {
    $newName = $file.Name
    foreach ($pair in $replacements) {
        $newName = $newName -creplace [regex]::Escape($pair.Find), $pair.Replace
    }
    Rename-Item -Path $file.FullName -NewName $newName
    Write-Host "  -> Renamed file: $newName" -ForegroundColor Green
    $fileRenameCount++
}
Write-Host "Successfully renamed $fileRenameCount files." -ForegroundColor Cyan

# ---------------------------------------------------------
# STEP 3: Rename Folders
# ---------------------------------------------------------
Write-Host "`n[3/3] Renaming folders..." -ForegroundColor Yellow
# We MUST sort descending by path length to rename deeply nested child folders BEFORE their parent folders
$renameDirs = Get-ChildItem -Path $folderPath -Recurse -Directory | Where-Object { $_.Name -match 'skyhs' } | Sort-Object -Property @{Expression={$_.FullName.Length}; Descending=$true}
$dirRenameCount = 0

foreach ($dir in $renameDirs) {
    $newName = $dir.Name
    foreach ($pair in $replacements) {
        $newName = $newName -creplace [regex]::Escape($pair.Find), $pair.Replace
    }
    Rename-Item -Path $dir.FullName -NewName $newName
    Write-Host "  -> Renamed folder: $newName" -ForegroundColor Green
    $dirRenameCount++
}
Write-Host "Successfully renamed $dirRenameCount folders." -ForegroundColor Cyan

Write-Host "`n==========================================" -ForegroundColor Cyan
Write-Host " REBRANDING COMPLETE! " -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Cyan