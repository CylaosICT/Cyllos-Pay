# cyllos-pay — site de paiement public (site B)

Interface publique de paiement de Cyllos pour les clients **Stripe** : la personne saisit
l'e-mail de son compte en monnaie locale (Cyclos) et un montant, puis est redirigée vers la
page de paiement hébergée par Stripe (Checkout, pleine page). Au retour, elle voit le statut
de sa commande.

Ce site est volontairement **sans pouvoir** : il n'a ni clé Stripe, ni accès à Cyclos, ni accès
aux tables de Cyllos.

```
Payeur ──> cyllos-pay ──(appel signé HMAC)──> Cyllos /internal/checkout ──> Stripe
              │                                          ▲
              └── lecture seule (2 vues SQL) ────────────┘ base MariaDB unique
```

## Parcours guidé

| Étape | URL | Ce qui se passe |
| --- | --- | --- |
| 1. Compte | `/{slug}` | La personne saisit l'e-mail de son compte. Cyllos vérifie (`/internal/account-check`) qu'un compte Cyclos existe et peut être crédité. `?annule=REF` : paiement annulé. |
| 2. Confirmation | `/{slug}/confirmer` | Affiche l'e-mail et le type de compte (particulier / professionnel) ; « Oui, continuer » ou « changer d'adresse ». |
| 3. Montant | `/{slug}/montant` | Montant en euros, borné par les min/max du client. |
| 4. Paiement | `/{slug}/recapitulatif` | Récapitulatif, puis redirection vers la page de paiement hébergée par Stripe. |
| 5. Suivi | `/{slug}/retour/{reference}` | Retour de Stripe, rafraîchi toutes les 5 s : paiement → crédit du compte → terminé (ou « crédit à traiter par l'équipe »). |

`{slug}` est le slug du client dans Cyllos. Un client inconnu, inactif ou non Stripe → 404. Une
étape n'est accessible qu'une fois la précédente faite (état gardé en session, par client).

Le crédit du compte Cyclos est fait **par Cyllos**, jamais par ce site : celui-ci ne fait
qu'afficher l'avancement (`credit_status` de la vue `pay_order_view`).
## Mise en place

1. Côté Cyllos : appliquer les migrations (elles créent les deux vues), définir
   `PAY_INTERNAL_SECRET` (long secret aléatoire) et `PAY_SITE_BASE_URL` (URL publique de ce site).
2. Créer l'utilisateur MariaDB restreint avec `docs/PAY_SITE_DB.sql` (dépôt Cyllos).
3. Ici, `.env.local` :

   ```
   APP_ENV=prod
   APP_SECRET=<aléatoire>
   CYLLOS_BASE_URL=https://cyllos.exemple.fr
   PAY_INTERNAL_SECRET=<le même secret que côté Cyllos>
   DATABASE_URL="mysql://cyllos_pay:<mot de passe>@127.0.0.1:3306/cyllos?serverVersion=11.4.0-MariaDB&charset=utf8mb4"
   ```

4. `composer install --no-dev --optimize-autoloader`, servir `public/` en HTTPS uniquement.
5. Derrière un reverse proxy, déclarer `framework.trusted_proxies` : l'IP du payeur
   (`getClientIp()`) est transmise à Cyllos pour la limitation de débit par IP.

## Sécurité

- En-têtes stricts (CSP `default-src 'none'`, `frame-ancestors 'none'`, `no-store`, etc.).
- Jeton CSRF sur le formulaire, limitation à 10 tentatives / 10 min / IP (Cyllos en applique
  d'autres, par IP et par e-mail).
- Les erreurs techniques ne sont jamais détaillées au payeur (journalisées seulement).
- Limite connue : l'appel signé n'a pas de nonce ; un appel capturé pourrait être rejoué dans
  la fenêtre de 5 minutes. Piste : Cloudflare Turnstile sur le formulaire contre l'abus automatisé.

## Développement

```bash
composer install
php bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run
```

Les tests n'ont besoin ni de base de données ni de Cyllos : le catalogue, la lecture des
commandes et le client HTTP vers Cyllos sont remplacés par des doublures.
