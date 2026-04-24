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
Examples: @('--testdox'), @('--filter', 'HSPSC_Page_Test')

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

$npmCommand = Get-Command npm.cmd -ErrorAction SilentlyContinue
if (-not $npmCommand) {
    $npmCommand = Get-Command npm -ErrorAction Stop
}

$npxCommand = Get-Command npx.cmd -ErrorAction SilentlyContinue
if (-not $npxCommand) {
    $npxCommand = Get-Command npx -ErrorAction Stop
}

function Invoke-CheckedCommand {
    param(
        [Parameter(Mandatory = $true)]
        [string]$FilePath,

        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$Arguments
    )

    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed with exit code ${LASTEXITCODE}: $FilePath $($Arguments -join ' ')"
    }
}

# Start wp-env unless explicitly skipped.
if (-not $SkipStart) {
    Write-Host 'Starting wp-env...'
    Invoke-CheckedCommand $npmCommand.Source run wp-env:start
}

Write-Host 'Installing Composer test dependencies...'
Invoke-CheckedCommand $npxCommand.Source wp-env run tests-cli --env-cwd=wp-content/plugins/hsp-smart-cache composer install --no-interaction --prefer-dist

# Build the wp-env command for tests-cli using plugin-relative cwd.
$baseCommand = @(
    'wp-env',
    'run',
    'tests-cli',
    '--env-cwd=wp-content/plugins/hsp-smart-cache',
    'vendor/bin/phpunit',
    '--configuration=phpunit.xml.dist'
)

# Append custom PHPUnit arguments, if provided.
if ($PhpUnitArgs -and $PhpUnitArgs.Count -gt 0) {
    $baseCommand += $PhpUnitArgs
}

Write-Host 'Running PHPUnit in wp-env...'
Invoke-CheckedCommand $npxCommand.Source @baseCommand
