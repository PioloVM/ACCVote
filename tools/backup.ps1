<#
.SYNOPSIS
    pg_dump backup for the voting-app Postgres (Neon) database.

.DESCRIPTION
    Dumps the database referenced by the DATABASE_URL environment variable
    (or -ConnectionString) into backups/neon-<timestamp>.dump in the
    pg_dump custom format (-Fc), then rotates the folder down to the
    newest -Keep dumps. The connection string is NEVER echoed - use
    -ShowCommand to see the exact pg_dump invocation with the password
    redacted.

    Locally:   $env:DATABASE_URL = '<neon connection string>'
               powershell -NoProfile -File tools\backup.ps1
    CI:        .github/workflows/db-backup.yml runs this weekly and
               uploads the dump as an artifact (30-day retention). The
               workflow is skipped entirely when the DATABASE_URL secret
               is not configured.

.PARAMETER ShowCommand
    Print the pg_dump command that WOULD run (credentials redacted) and
    exit 0 without dumping anything. Safe to run anywhere.
#>
[CmdletBinding()]
param(
    [string]$ConnectionString = $env:DATABASE_URL,
    # Resolved at runtime: $PSScriptRoot is empty inside param defaults
    # when the script is launched with `powershell -File`.
    [string]$OutDir = '',
    [int]$Keep = 7,
    [string]$PgDumpPath = $env:PG_DUMP_PATH,
    [switch]$ShowCommand
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($OutDir)) {
    $repoRoot = if ($PSScriptRoot) { Split-Path -Parent $PSScriptRoot } else { (Get-Location).Path }
    $OutDir = Join-Path $repoRoot 'backups'
}

if ([string]::IsNullOrWhiteSpace($ConnectionString)) {
    # Write-Output (not Write-Error): callers and the smoke suite capture
    # stdout; stderr from a native child process would trip $ErrorAction-
    # Preference=Stop in the parent suite.
    Write-Output 'DATABASE_URL is not set. Export it (or pass -ConnectionString) before running a backup.'
    Write-Output 'In GitHub Actions this is the DATABASE_URL repository secret; locally it is the Neon connection string.'
    exit 1
}

# Redact user:password from a connection string for display only.
function Protect-ConnectionString([string]$value) {
    return $value -replace '://([^:/@]+):[^@]*@', '://$1:***@'
}

$outFile = Join-Path $OutDir ('neon-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.dump')

if ($ShowCommand) {
    Write-Output ('Would run: pg_dump "{0}" -Fc -f "{1}"' -f (Protect-ConnectionString $ConnectionString), $outFile)
    Write-Output ('Then keep the newest {0} dump(s) in {1}' -f $Keep, $OutDir)
    exit 0
}

$pgDump = if ($PgDumpPath) { $PgDumpPath } else { 'pg_dump' }
if (-not (Get-Command $pgDump -ErrorAction SilentlyContinue)) {
    Write-Output "pg_dump not found ('$pgDump'). Install the PostgreSQL client tools or set PG_DUMP_PATH."
    exit 1
}

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

& $pgDump $ConnectionString -Fc -f $outFile
if ($LASTEXITCODE -ne 0) {
    Write-Output "pg_dump failed with exit code $LASTEXITCODE"
    exit 1
}

# Rotation: keep only the newest -Keep dumps (weekly cadence -> one quarter
# of history, roughly). Only our own neon-*.dump files are touched.
$dumps = @(Get-ChildItem -Path $OutDir -Filter 'neon-*.dump' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending)
if ($dumps.Count -gt $Keep) {
    $dumps | Select-Object -Skip $Keep | Remove-Item -Force
}

$sizeMb = [math]::Round((Get-Item $outFile).Length / 1MB, 2)
Write-Output "Backup written: $outFile ($sizeMb MB)"
exit 0
