#!/usr/bin/env bash
#
# Mise à jour de cyllos-pay déjà installé (voir install.sh) :
#   sudo bash /var/www/cyllos-pay/deploy/update.sh
#
# Tire le code, réinstalle les dépendances, vide le cache, recharge PHP-FPM.
# S'arrête à la première erreur ; ne touche ni à .env.local ni à la base.

set -euo pipefail

APP_USER="cyllospay"
APP_DIR="/var/www/cyllos-pay"

if [[ $EUID -ne 0 ]]; then
    echo "Ce script doit être lancé en root (sudo)." >&2
    exit 1
fi

cd "$APP_DIR"

echo "==> Code"
sudo -u "$APP_USER" git pull --ff-only

echo "==> Dépendances"
sudo -u "$APP_USER" composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Cache"
sudo -u "$APP_USER" php bin/console cache:clear --env=prod

echo "==> Rechargement PHP-FPM"
systemctl reload php8.4-fpm

echo "Mise à jour terminée."
