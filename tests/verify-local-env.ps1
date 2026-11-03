# Task 1 / Task 12 verification: local DB serving the app.
# PASS = app page reachable (HTTP 200), local voting_app schema present,
# and the baseline timings file exists.
$ErrorActionPreference = 'Stop'
$code = (curl.exe -o NUL -s -w "%{http_code}" "http://localhost/voting-app3/public/landingpage.php")
$tables = (& "C:\xampp\mysql\bin\mysql.exe" -u root voting_app -N -e "SHOW TABLES" | Measure-Object -Line).Lines
$hasBaseline = Test-Path (Join-Path $PSScriptRoot "baseline-timings.md")
if ($code -eq "200" -and $tables -ge 15 -and $hasBaseline) {
    Write-Output "PASS: landingpage 200, voting_app tables=$tables, baseline file present"
    exit 0
}
Write-Output "FAIL: code=$code tables=$tables baseline=$hasBaseline"
exit 1
