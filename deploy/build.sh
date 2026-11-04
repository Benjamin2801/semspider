#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY="$ROOT/deploy"
OUT="$ROOT/build"
APP="$OUT/app"

echo "==> Vérification des fichiers"

for file in \
    "$DEPLOY/prod.env" \
    "$DEPLOY/index.php" \
    "$DEPLOY/app.htaccess" \
    "$DEPLOY/semspider.htaccess" \
    "$ROOT/composer.json" \
    "$ROOT/composer.lock" \
    "$ROOT/.env"
do
    if [[ ! -f "$file" ]]; then
        echo "ERREUR : fichier manquant : $file"
        exit 1
    fi
done

for dir in config src templates translations public; do
    if [[ ! -d "$ROOT/$dir" ]]; then
        echo "ERREUR : dossier manquant : $ROOT/$dir"
        exit 1
    fi
done

command -v composer >/dev/null || {
    echo "ERREUR : Composer est introuvable."
    exit 1
}

echo "==> Préparation du build"

rm -rf "$OUT"
mkdir -p "$APP"

for dir in config src templates translations; do
    cp -a "$ROOT/$dir" "$APP/"
done

cp "$ROOT/composer.json" "$APP/"
cp "$ROOT/composer.lock" "$APP/"
cp "$ROOT/.env" "$APP/"
if [[ -f "$ROOT/symfony.lock" ]]; then
    cp "$ROOT/symfony.lock" "$APP/"
fi

cp "$DEPLOY/prod.env" "$APP/.env.prod.local"

BUILD_ID="$(date -u +%Y%m%d%H%M%S)"
printf '\nAPP_BUILD_ID=%s\n' "$BUILD_ID" >> "$APP/.env.prod.local"

echo "==> Installation des dépendances de production"

composer install \
    --working-dir="$APP" \
    --no-dev \
    --optimize-autoloader \
    --classmap-authoritative \
    --no-scripts \
    --no-interaction \
    --no-progress

echo "==> Compilation de l'environnement Symfony"

composer dump-env prod \
    --working-dir="$APP" \
    --no-interaction

rm -f "$APP/.env.prod.local"
rm -f "$APP/.env" "$APP/composer.lock" "$APP/symfony.lock"
rm -f "$APP/config/reference.php"

echo "==> Préparation des fichiers publics"

cp -a "$ROOT/public/." "$OUT/"
rm -f "$OUT/index.php"
rm -rf "$OUT/bundles"

cp "$DEPLOY/index.php" "$OUT/index.php"
cp "$DEPLOY/semspider.htaccess" "$OUT/.htaccess"
cp "$DEPLOY/app.htaccess" "$APP/.htaccess"

echo "==> Application des permissions PHP-FPM"

chgrp -R www-data "$OUT"
find "$OUT" -type d -exec chmod 2750 {} \;
find "$OUT" -type f -exec chmod 640 {} \;

echo
echo "BUILD TERMINÉ"
echo "Identifiant : $BUILD_ID"
echo "Dossier : $OUT"
echo "Vérifie notamment la présence de build/app/.env.local.php"
