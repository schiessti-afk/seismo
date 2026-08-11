# Windows-native Sail wrapper.
# vendor/bin/sail is a bash script — PowerShell cannot run it directly.
# This script forwards common commands to docker compose.

param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Command
)

$Service = if ($env:APP_SERVICE) { $env:APP_SERVICE } else { 'laravel.test' }

if ($Command.Count -eq 0) {
    Write-Host @"
Seismo Sail (Windows)

Usage: .\sail.ps1 <command> [args]

  up [-d]          Start containers
  down             Stop and remove containers
  stop|restart|ps  Docker Compose lifecycle
  artisan ...      Run php artisan inside the app container
  test ...         Run php artisan test
  pest ...         Run Pest
  pint ...         Run Pint
  composer ...     Run Composer
  shell|bash       Open a shell in the app container
  logs ...         Tail container logs

Example:
  .\sail.ps1 up -d
  .\sail.ps1 artisan migrate
  .\sail.ps1 test
"@
    exit 0
}

$verb = $Command[0]
$rest = @()
if ($Command.Count -gt 1) {
    $rest = $Command[1..($Command.Count - 1)]
}

switch ($verb) {
    { $_ -in @('up', 'down', 'stop', 'restart', 'ps', 'build', 'logs', 'pull') } {
        docker compose $verb @rest
        exit $LASTEXITCODE
    }
    'artisan' {
        docker compose exec $Service php artisan @rest
        exit $LASTEXITCODE
    }
    'test' {
        docker compose exec $Service php artisan test @rest
        exit $LASTEXITCODE
    }
    'pest' {
        docker compose exec $Service ./vendor/bin/pest @rest
        exit $LASTEXITCODE
    }
    'pint' {
        docker compose exec $Service ./vendor/bin/pint @rest
        exit $LASTEXITCODE
    }
    'php' {
        docker compose exec $Service php @rest
        exit $LASTEXITCODE
    }
    'composer' {
        docker compose exec $Service composer @rest
        exit $LASTEXITCODE
    }
    'npm' {
        docker compose exec $Service npm @rest
        exit $LASTEXITCODE
    }
    { $_ -in @('shell', 'bash') } {
        docker compose exec $Service bash @rest
        exit $LASTEXITCODE
    }
    default {
        docker compose @Command
        exit $LASTEXITCODE
    }
}
