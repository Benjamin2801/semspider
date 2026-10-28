# Prépare build/, la version de production à copier sur le serveur.
#
#   build/
#     index.php, .htaccess, css/, favicon.svg   <- fichiers publics
#     app/                                       <- code, vendor, secrets (accès web refusé)
#
# Usage : powershell -ExecutionPolicy Bypass -File deploy/build.ps1

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$prodEnv = Join-Path $PSScriptRoot 'prod.env'
$out = Join-Path $root 'build'
$app = Join-Path $out 'app'

if (-not (Test-Path $prodEnv)) {
    throw "deploy/prod.env est introuvable : copiez deploy/prod.env.dist en deploy/prod.env et remplissez-le."
}

if (Test-Path (Join-Path $root 'build')) {
    Remove-Item -Recurse -Force (Join-Path $root 'build')
}
New-Item -ItemType Directory -Force $app | Out-Null

Write-Host '> Copie du code'
foreach ($item in 'config', 'src', 'templates', 'translations', 'composer.json', 'composer.lock', 'symfony.lock', '.env') {
    Copy-Item -Recurse (Join-Path $root $item) $app
}
Copy-Item $prodEnv (Join-Path $app '.env.prod.local')
# Identifiant de build : chaque version a son propre dossier de cache (voir src/Kernel.php).
$buildId = Get-Date -Format 'yyyyMMddHHmmss'
Add-Content -Path (Join-Path $app '.env.prod.local') -Value "`nAPP_BUILD_ID=$buildId" -Encoding utf8

Write-Host '> Dépendances de production'
composer install --working-dir="$app" --no-dev --optimize-autoloader --classmap-authoritative --no-scripts --no-interaction --no-progress
if ($LASTEXITCODE -ne 0) { throw 'composer install a échoué.' }

Write-Host '> Compilation des variables d''environnement'
composer dump-env prod --working-dir="$app" --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'composer dump-env a échoué.' }
Remove-Item (Join-Path $app '.env.prod.local')

Write-Host '> Nettoyage'
# Inutiles à l'exécution : .env est remplacé par .env.local.php, les locks ne servent qu'à composer install,
# reference.php n'existe que pour l'autocomplétion de l'éditeur.
foreach ($item in '.env', 'composer.lock', 'symfony.lock', 'config\reference.php') {
    Remove-Item (Join-Path $app $item)
}
# Tests, documentation et licences des paquets : jamais chargés en production.
# Seulement à la racine de chaque paquet (vendor/x/y/tests) : plus bas, un dossier « Test » peut
# contenir du vrai code (ex. les tests Twig « is true »).
$vendor = Join-Path $app 'vendor'
Get-ChildItem $vendor -Directory | Get-ChildItem -Directory | Get-ChildItem -Directory |
    Where-Object { $_.Name -in 'Tests', 'tests', 'doc', 'docs' } | Remove-Item -Recurse -Force
Get-ChildItem $vendor -Recurse -File -Include '*.md', '*.rst', 'LICENSE*', 'CHANGELOG*', 'phpunit.xml.dist', '.gitattributes', '.gitignore' |
    Remove-Item -Force

Write-Host '> Fichiers publics'
Get-ChildItem (Join-Path $root 'public') -Force | Where-Object { $_.Name -notin 'index.php', 'bundles' } | ForEach-Object {
    Copy-Item -Recurse $_.FullName $out
}
Copy-Item (Join-Path $PSScriptRoot 'index.php') (Join-Path $out 'index.php')
Copy-Item (Join-Path $PSScriptRoot 'semspider.htaccess') (Join-Path $out '.htaccess')
Copy-Item (Join-Path $PSScriptRoot 'app.htaccess') (Join-Path $app '.htaccess')

Write-Host ''
Write-Host "Build prêt : $out (identifiant $buildId)"
Write-Host 'app/.env.local.php change à chaque build : il fait toujours partie de la copie.'
