param([Parameter(Mandatory=$true)][string]$RepositoryUrl)
$ErrorActionPreference = 'Stop'
if ($RepositoryUrl -ne 'https://github.com/smartresponsor/inspecting.git') { throw 'Unexpected remote URL' }
$existing = @(git remote)
if ($LASTEXITCODE -ne 0) { throw 'Unable to read remotes' }
if ($existing -contains 'origin') {
    $actual = (git remote get-url origin).Trim()
    if ($actual -ne $RepositoryUrl) { throw "origin already points to $actual" }
} else {
    git remote add origin $RepositoryUrl
    if ($LASTEXITCODE -ne 0) { throw 'Failed to add origin' }
}
git remote -v
