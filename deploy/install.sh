#!/usr/bin/env bash
#
# Première installation de cyllos-pay sur le serveur de Cyllos (Debian 13, nginx,
# PHP 8.4-FPM, MariaDB). À lancer en root, depuis une copie du dépôt :
#
#   sudo DOMAIN=pay.exemple.fr \
#        CYLLOS_URL=https://cyllos.exemple.fr \
#        PAY_INTERNAL_SECRET=<même valeur que dans /var/www/cyllos/.env.local> \
#        bash deploy/install.sh
#
# Variables optionnelles : REPO, DB_NAME (cyllos), DB_USER (cyllos_pay),
# DB_PASSWORD (généré si absent), MARIADB_VERSION (10.11.2-MariaDB), WITH_CERTBOT (1).
#
# Rejouable : ce qui existe déjà est conservé (utilisateur, .env.local, clone).
# Les secrets ne sont jamais affichés.

set -euo pipefail

: "${DOMAIN:?DOMAIN manquant (ex. pay.exemple.fr)}"
: "${CYLLOS_URL:?CYLLOS_URL manquant (ex. https://cyllos.exemple.fr)}"
: "${PAY_INTERNAL_SECRET:?PAY_INTERNAL_SECRET manquant (même valeur que côté Cyllos)}"

REPO="${REPO:-https://github.com/CylaosICT/Cyllos-Pay.git}"
APP_USER="cyllospay"
APP_DIR="/var/www/cyllos-pay"
DB_NAME="${DB_NAME:-cyllos}"
DB_USER="${DB_USER:-cyllos_pay}"
MARIADB_VERSION="${MARIADB_VERSION:-10.11.2-MariaDB}"
WITH_CERTBOT="${WITH_CERTBOT:-1}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ $EUID -ne 0 ]]; then
    echo "Ce script doit être lancé en root (sudo)." >&2
    exit 1
fi
if [[ ${#PAY_INTERNAL_SECRET} -lt 32 ]]; then
    echo "PAY_INTERNAL_SECRET est trop court (32 caractères minimum)." >&2
    exit 1
fi
# Le mot de passe et le nom de base finissent dans du SQL et une URL : jeu de caractères restreint.
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "DB_NAME invalide." >&2; exit 1; }
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo "DB_USER invalide." >&2; exit 1; }

step() { printf '\n==> %s\n' "$*"; }

step "Utilisateur système $APP_USER"
if ! id "$APP_USER" >/dev/null 2>&1; then
    adduser --system --group --home "$APP_DIR" --shell /bin/bash "$APP_USER"
fi

step "Code source dans $APP_DIR"
if [[ -d "$APP_DIR/.git" ]]; then
    sudo -u "$APP_USER" git -C "$APP_DIR" pull --ff-only
else
    sudo -u "$APP_USER" git clone "$REPO" "$APP_DIR"
fi

step "Dépendances (composer, sans les outils de dev)"
sudo -u "$APP_USER" composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$APP_DIR"

step "Utilisateur MariaDB $DB_USER (lecture seule sur les 2 vues)"
if [[ -z "${DB_PASSWORD:-}" ]]; then
    # Réutilise le mot de passe déjà installé s'il existe, sinon en génère un.
    if [[ -f "$APP_DIR/.env.local" ]] && grep -q '^DATABASE_URL=' "$APP_DIR/.env.local"; then
        DB_PASSWORD="$(sed -n 's|^DATABASE_URL="mysql://[^:]*:\([^@]*\)@.*|\1|p' "$APP_DIR/.env.local")"
    fi
    DB_PASSWORD="${DB_PASSWORD:-$(openssl rand -hex 24)}"
fi
[[ "$DB_PASSWORD" =~ ^[A-Za-z0-9]+$ ]] || { echo "DB_PASSWORD : lettres et chiffres uniquement." >&2; exit 1; }

# Les vues viennent des migrations de Cyllos : elles doivent exister avant le GRANT.
for view in pay_client_view pay_order_view; do
    if ! mariadb -N -e "SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='$view'" | grep -q 1; then
        echo "La vue $DB_NAME.$view n'existe pas : lancez d'abord les migrations de Cyllos" >&2
        echo "(php bin/console doctrine:migrations:migrate dans /var/www/cyllos)." >&2
        exit 1
    fi
done
mariadb <<SQL
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$DB_USER'@'localhost';
GRANT SELECT ON \`$DB_NAME\`.pay_client_view TO '$DB_USER'@'localhost';
GRANT SELECT ON \`$DB_NAME\`.pay_order_view TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

step "Configuration (.env.local)"
if [[ -f "$APP_DIR/.env.local" ]]; then
    echo ".env.local existe déjà : conservé tel quel (supprimez-le pour le régénérer)."
else
    APP_SECRET="$(openssl rand -hex 32)"
    umask 077
    cat > "$APP_DIR/.env.local" <<ENV
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$APP_SECRET
CYLLOS_BASE_URL=${CYLLOS_URL%/}
PAY_INTERNAL_SECRET=$PAY_INTERNAL_SECRET
DATABASE_URL="mysql://$DB_USER:$DB_PASSWORD@127.0.0.1:3306/$DB_NAME?serverVersion=$MARIADB_VERSION&charset=utf8mb4"
ENV
    chown "$APP_USER:$APP_USER" "$APP_DIR/.env.local"
    chmod 600 "$APP_DIR/.env.local"
fi

step "Cache de production"
sudo -u "$APP_USER" php "$APP_DIR/bin/console" cache:clear --env=prod

step "Pool PHP-FPM"
install -m 644 "$SCRIPT_DIR/php-fpm-pool.conf" /etc/php/8.4/fpm/pool.d/cyllos-pay.conf
php-fpm8.4 -t
systemctl restart php8.4-fpm

step "Vhost nginx pour $DOMAIN"
sed "s/__DOMAIN__/$DOMAIN/g" "$SCRIPT_DIR/nginx-cyllos-pay.conf" > /etc/nginx/sites-available/cyllos-pay
ln -sf /etc/nginx/sites-available/cyllos-pay /etc/nginx/sites-enabled/cyllos-pay
nginx -t
systemctl reload nginx

if [[ "$WITH_CERTBOT" == "1" ]]; then
    step "Certificat HTTPS (Let's Encrypt)"
    certbot --nginx -d "$DOMAIN" --redirect
fi

step "Terminé"
echo "Site : https://$DOMAIN/<slug-d-un-client-stripe>"
echo "Pensez à vérifier côté Cyllos : PAY_SITE_BASE_URL=https://$DOMAIN et PAY_INTERNAL_SECRET identique."
