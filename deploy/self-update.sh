#!/usr/bin/env bash
#
# Mise a jour declenchee depuis le back-office de Cyllos (page "Mises a jour"),
# lancee par le site lui-meme sous l'utilisateur PHP-FPM (cyllospay) : pas de root.
# Comme update.sh, sans le rechargement de PHP-FPM (OPcache revalide les fichiers
# et le cache Symfony est vide ci-dessous).
# Etat dans var/self-update.state (running | ok | failed), journal dans var/log/self-update.log.
#
# Tout est dans main() : git pull peut reecrire ce fichier pendant qu'il tourne,
# et bash lit un script au fil de l'eau.

set -uo pipefail

main() {
    cd "$(dirname "$0")/.." || exit 1
    mkdir -p var/log
    local state=var/self-update.state
    local log=var/log/self-update.log

    HOME="$(getent passwd "$(id -un)" | cut -d: -f6)"
    export HOME
    export COMPOSER_HOME="$HOME/.composer"
    export GIT_TERMINAL_PROMPT=0

    exec 9>var/self-update.lock
    if ! flock -n 9; then
        echo "Deja en cours." >>"$log"
        return 0
    fi

    : >"$log"
    echo running >"$state"

    step() {
        local label="$1"
        shift
        echo "==> $label" >>"$log"
        if ! "$@" >>"$log" 2>&1; then
            echo "ECHEC : $label" >>"$log"
            echo failed >"$state"
            return 1
        fi
    }

    step "Code" git pull --ff-only \
        && step "Dependances" composer install --no-dev --optimize-autoloader --no-interaction \
        && step "Cache" php bin/console cache:clear --env=prod \
        || return 1

    echo "Mise a jour terminee." >>"$log"
    echo ok >"$state"
}

main "$@"
exit $?
