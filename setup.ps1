# One-command local setup for Seismo (Docker Desktop required).
# Usage: .\setup.ps1

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function Write-Step([string]$Message) {
    Write-Host ""
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Assert-Docker {
    try {
        docker info 1>$null 2>$null
    } catch {
        throw "Docker is not available. Install Docker Desktop and ensure it is running."
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Docker is not running. Start Docker Desktop, then re-run .\setup.ps1"
    }
}

function Wait-AppContainer {
    $service = if ($env:APP_SERVICE) { $env:APP_SERVICE } else { 'laravel.test' }
    Write-Step "Waiting for app container ($service)..."
    for ($i = 1; $i -le 90; $i++) {
        docker compose exec -T $service php -v 1>$null 2>$null
        if ($LASTEXITCODE -eq 0) {
            return
        }
        Start-Sleep -Seconds 2
    }
    throw "App container did not become ready. Check: docker compose ps && docker compose logs"
}

Assert-Docker

Write-Step "Creating .env (if missing)"
if (-not (Test-Path .env)) {
    Copy-Item .env.example .env
    Write-Host "Created .env from .env.example"
} else {
    Write-Host ".env already exists — leaving it unchanged"
}

Write-Step "Installing PHP dependencies (Composer via Docker)"
docker run --rm `
    -v "${PWD}:/var/www/html" `
    -w /var/www/html `
    laravelsail/php84-composer:latest `
    composer install --no-interaction --prefer-dist --ignore-platform-req=ext-pcntl
if ($LASTEXITCODE -ne 0) { throw "composer install failed" }

Write-Step "Starting Sail stack (first run may build the image)"
docker compose up -d
if ($LASTEXITCODE -ne 0) { throw "docker compose up failed" }

Wait-AppContainer

$service = if ($env:APP_SERVICE) { $env:APP_SERVICE } else { 'laravel.test' }

Write-Step "Generating app key (if empty)"
$envText = Get-Content .env -Raw
if ($envText -notmatch '(?m)^APP_KEY=base64:') {
    docker compose exec -T $service php artisan key:generate --force
    if ($LASTEXITCODE -ne 0) { throw "key:generate failed" }
} else {
    Write-Host "APP_KEY already set"
}

Write-Step "Running migrations"
docker compose exec -T $service php artisan migrate --force
if ($LASTEXITCODE -ne 0) { throw "migrate failed" }

Write-Step "Installing and building frontend assets"
docker compose exec -T $service npm install
if ($LASTEXITCODE -ne 0) { throw "npm install failed" }
docker compose exec -T $service npm run build
if ($LASTEXITCODE -ne 0) { throw "npm run build failed" }

Write-Host ""
Write-Host "Seismo is ready." -ForegroundColor Green
Write-Host "  App:     http://localhost"
Write-Host "  Horizon: http://localhost/horizon  (local only)"
Write-Host ""
Write-Host "First boot pulls ~30 days of USGS data in the background."
Write-Host "Markers may take a minute or two to appear."
Write-Host ""
Write-Host "Useful commands:"
Write-Host "  .\sail.ps1 ps"
Write-Host "  .\sail.ps1 logs"
Write-Host "  .\sail.ps1 down"
Write-Host ""
