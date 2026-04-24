<#
.SYNOPSIS
Runs the plugin PHPUnit suite inside wp-env.

.DESCRIPTION
This script prepares the local shell for wp-env test execution by ensuring the
Docker Desktop CLI path is available, optionally starting wp-env, and then
executing PHPUnit in the tests-cli container from the plugin directory.

.PARAMETER SkipStart
If set, skips `npm run wp-env:start` and runs tests against the current
wp-env state.

.PARAMETER PhpUnitArgs
Optional extra arguments forwarded to PHPUnit.
Examples: @('--testdox'), @('--filter', 'HSP_Smart_Cache_Page_Test')

.EXAMPLE
.\bin\run-wp-env-tests.ps1
Starts wp-env (if needed) and runs the full PHPUnit suite.

.EXAMPLE
.\bin\run-wp-env-tests.ps1 -SkipStart -PhpUnitArgs @('--testdox')
Runs PHPUnit with TestDox output without restarting wp-env.
#>

param(
    # Skip the wp-env startup step.
    [switch]$SkipStart,

    # Additional arguments passed directly to PHPUnit.
    [string[]]$PhpUnitArgs
)

# Fail fast on command errors.
$ErrorActionPreference = 'Stop'

# Ensure the Docker CLI is on PATH for this shell session.
$dockerCliPath = 'C:\Program Files\Docker\Docker\resources\bin'
if (Test-Path $dockerCliPath) {
    if (-not ($env:Path -split ';' | Where-Object { $_ -eq $dockerCliPath })) {
        $env:Path = "$dockerCliPath;$env:Path"
    }
}

# Verify docker is callable before attempting wp-env commands.
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI was not found. Install Docker Desktop or add docker.exe to PATH.'
}

# Start wp-env unless explicitly skipped.
if (-not $SkipStart) {
    Write-Host 'Starting wp-env...'
    npm run wp-env:start
}

# Build the wp-env command for tests-cli using plugin-relative cwd.
$baseCommand = @(
    'wp-env',
    'run',
    'tests-cli',
    '--env-cwd=wp-content/plugins/hsp-smart-cache',
    'phpunit',
    '--configuration=phpunit.xml.dist'
)

# Append custom PHPUnit arguments, if provided.
if ($PhpUnitArgs -and $PhpUnitArgs.Count -gt 0) {
    $baseCommand += $PhpUnitArgs
}

Write-Host 'Running PHPUnit in wp-env...'
& npx @baseCommand
