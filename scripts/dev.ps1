param([ValidateSet('serve', 'worker', 'scheduler', 'test', 'migrate')][string]$Action = 'serve')
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)
switch ($Action) {
    'serve' { & php artisan serve --host=127.0.0.1 --port=8018 }
    'worker' { & php artisan queue:work database --queue=default --sleep=2 --tries=1 --timeout=960 }
    'scheduler' { & php artisan schedule:work }
    'test' { & php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit }
    'migrate' { & php artisan migrate }
}
exit $LASTEXITCODE
