<#
.SYNOPSIS
    Keeps an app in this (internal) store and in the public store identical.

.DESCRIPTION
    This repository is the source of truth. The public store
    (public\biereco-umbrel-appstore) is a mirror that only gets pushed to
    GitHub after approval.

    1. Edit the app here.
    2. Run  .\scripts\sync-app.ps1 -App biereco-spotweb -Apply
       Commits and pushes the internal store, mirrors the app into the public
       working copy and commits it there. Nothing is pushed to GitHub.
    3. Check the result, then run
       .\scripts\sync-app.ps1 -App biereco-spotweb -Approve
       to push the public store. Without -Approve the public commit stays local.

.PARAMETER App
    App folder name, for example biereco-spotweb.

.PARAMETER Apply
    Commit/push the internal store and mirror the app into the public store
    (committed locally, not pushed).

.PARAMETER Approve
    Push the public store to GitHub, but only if its pending commits touch
    nothing outside the app.

.PARAMETER Message
    Commit message. Defaults to "Sync <app> to <version>".

.PARAMETER ExternalRoot
    Path of the public store checkout.
#>
param(
    [string]$App = 'biereco-spotweb',
    [switch]$Apply,
    [switch]$Approve,
    [string]$Message,
    [string]$ExternalRoot = 'C:\Users\jelle\Projects\umbrel\public\biereco-umbrel-appstore'
)

$ErrorActionPreference = 'Stop'

$InternalRoot = Split-Path -Parent $PSScriptRoot
$InternalApp = Join-Path $InternalRoot $App
$ExternalApp = Join-Path $ExternalRoot $App

function Invoke-Git {
    param([string]$Repo, [string[]]$GitArgs, [switch]$AllowFail)
    $output = & git -C $Repo @GitArgs 2>&1
    $code = $LASTEXITCODE
    if ($code -ne 0 -and -not $AllowFail) {
        throw "git $($GitArgs -join ' ') failed in $Repo`n$output"
    }
    return [pscustomobject]@{ Code = $code; Output = ($output -join "`n") }
}

function Get-FileMap {
    param([string]$Root)
    $map = @{}
    if (-not (Test-Path $Root)) { return $map }
    Get-ChildItem $Root -Recurse -File -Force |
        Where-Object { $_.FullName -notmatch '[\\/]\.git[\\/]' } |
        ForEach-Object {
            $rel = $_.FullName.Substring($Root.Length).TrimStart('\', '/') -replace '\\', '/'
            $map[$rel] = (Get-FileHash $_.FullName -Algorithm SHA256).Hash
        }
    return $map
}

function Compare-App {
    $internal = Get-FileMap $InternalApp
    $external = Get-FileMap $ExternalApp
    $result = @()
    foreach ($key in ($internal.Keys | Sort-Object)) {
        if (-not $external.ContainsKey($key)) {
            $result += [pscustomobject]@{ Change = 'missing in public'; Path = $key }
        } elseif ($external[$key] -ne $internal[$key]) {
            $result += [pscustomobject]@{ Change = 'differs'; Path = $key }
        }
    }
    foreach ($key in ($external.Keys | Sort-Object)) {
        if (-not $internal.ContainsKey($key)) {
            $result += [pscustomobject]@{ Change = 'extra in public'; Path = $key }
        }
    }
    return $result
}

function Get-AppVersion {
    param([string]$Manifest)
    if (-not (Test-Path $Manifest)) { return '?' }
    $line = Select-String -Path $Manifest -Pattern '^version:' | Select-Object -First 1
    if (-not $line) { return '?' }
    return ($line.Line -replace '^version:\s*', '').Trim().Trim('"')
}

function Test-RepoExists {
    param([string]$Repo, [string]$Label)
    if (-not (Test-Path (Join-Path $Repo '.git'))) {
        throw "$Label is not a git checkout: $Repo"
    }
}

# --- preconditions ---------------------------------------------------------

Test-RepoExists $InternalRoot 'Internal store'
Test-RepoExists $ExternalRoot 'Public store'
if (-not (Test-Path $InternalApp)) { throw "App not found in the internal store: $InternalApp" }

$version = Get-AppVersion (Join-Path $InternalApp 'umbrel-app.yml')
$externalHasApp = Test-Path $ExternalApp

# --- report ----------------------------------------------------------------

$internalDirty = (Invoke-Git $InternalRoot @('status', '--porcelain', '--', $App)).Output.Trim()
$externalDirty = if ($externalHasApp) { (Invoke-Git $ExternalRoot @('status', '--porcelain', '--', $App)).Output.Trim() } else { '' }
$pendingPush = (Invoke-Git $ExternalRoot @('log', '--oneline', 'origin/master..HEAD')).Output.Trim()
$differences = Compare-App

Write-Host ''
Write-Host "App:            $App ($version)"
Write-Host "Internal store: $InternalRoot"
Write-Host "Public store:   $ExternalRoot"
Write-Host ''

if (-not $externalHasApp) {
    Write-Host 'Public store: app is not present yet' -ForegroundColor Yellow
} elseif ($differences.Count -eq 0) {
    Write-Host 'Public store: files match the internal store' -ForegroundColor Green
} else {
    Write-Host "Public store: $($differences.Count) file(s) differ from the internal store" -ForegroundColor Yellow
    $differences | Format-Table -AutoSize | Out-String | Write-Host
}

if ($pendingPush) {
    Write-Host 'Public store: commits waiting for approval (not yet on GitHub):' -ForegroundColor Yellow
    $pendingPush -split "`n" | ForEach-Object { Write-Host "  $_" }
} else {
    Write-Host 'Public store: nothing waiting for approval' -ForegroundColor Green
}

if ($internalDirty) {
    Write-Host 'Internal store: uncommitted changes' -ForegroundColor Yellow
}

# --- apply -----------------------------------------------------------------

if ($Apply) {
    if (-not $Message) { $Message = "Sync $App to $version" }

    if ($internalDirty) {
        Write-Host ''
        Write-Host 'Committing and pushing the internal store...' -ForegroundColor Cyan
        [void](Invoke-Git $InternalRoot @('add', '-A', '--', $App))
        [void](Invoke-Git $InternalRoot @('commit', '-m', $Message, '--', $App))
        [void](Invoke-Git $InternalRoot @('push', 'origin', 'HEAD'))
        Write-Host 'Internal store pushed' -ForegroundColor Green
    } else {
        Write-Host ''
        Write-Host 'Internal store already committed' -ForegroundColor DarkGray
    }

    Write-Host ''
    Write-Host 'Mirroring into the public store (no push)...' -ForegroundColor Cyan
    $robocopyArgs = @($InternalApp, $ExternalApp, '/MIR', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
    & robocopy @robocopyArgs | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed with exit code $LASTEXITCODE" }

    $publicDirty = (Invoke-Git $ExternalRoot @('status', '--porcelain', '--', $App)).Output.Trim()
    if ($publicDirty) {
        [void](Invoke-Git $ExternalRoot @('add', '-A', '--', $App))
        $publicMessage = "$Message`n`nMirrored from the internal store. Awaiting approval before pushing to GitHub."
        [void](Invoke-Git $ExternalRoot @('commit', '-m', $publicMessage, '--', $App))
        Write-Host 'Public store committed locally - run with -Approve to publish' -ForegroundColor Yellow
    } else {
        Write-Host 'Public store already up to date' -ForegroundColor Green
    }
}

# --- approve ---------------------------------------------------------------

if ($Approve) {
    $remaining = Compare-App
    if ($remaining.Count -ne 0) {
        throw 'The public store still differs from the internal store. Run with -Apply first.'
    }

    $pending = (Invoke-Git $ExternalRoot @('log', '--oneline', 'origin/master..HEAD')).Output.Trim()
    if (-not $pending) {
        Write-Host ''
        Write-Host 'Nothing to publish' -ForegroundColor Green
        return
    }

    $files = (Invoke-Git $ExternalRoot @('diff', '--name-only', 'origin/master..HEAD')).Output.Trim() -split "`n" |
        Where-Object { $_ } | ForEach-Object { $_.Trim() }
    $outside = $files | Where-Object { $_ -notmatch "^$([regex]::Escape($App))/" }
    if ($outside) {
        Write-Host ''
        Write-Host 'Refusing to publish: the pending commits also touch other files:' -ForegroundColor Red
        $outside | ForEach-Object { Write-Host "  $_" }
        throw 'Publish those changes separately first.'
    }

    Write-Host ''
    Write-Host 'Publishing the public store (approved):' -ForegroundColor Cyan
    $pending -split "`n" | ForEach-Object { Write-Host "  $_" }
    [void](Invoke-Git $ExternalRoot @('push', 'origin', 'HEAD'))
    Write-Host 'Public store pushed to GitHub' -ForegroundColor Green
}

if (-not $Apply -and -not $Approve) {
    Write-Host ''
    Write-Host 'Dry run. Use -Apply to update the public store, then -Approve to publish it.' -ForegroundColor DarkGray
}
