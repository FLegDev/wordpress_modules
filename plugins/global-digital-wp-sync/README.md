# Global Digital WP Sync

Plugin WordPress pour collecter les indicateurs Global Digital dont la source est WordPress, puis les pousser vers une API Global Digital. Il peut aussi envoyer les statistiques Advanced Ads locales vers une API de statistiques Django.

## Indicateurs inclus

- `wp_articles_published`: articles publies sur la periode.
- `wp_articles_datawall`: articles sous datawall sur la periode.
- `wp_articles_paywall`: articles sous paywall sur la periode.
- `wp_existing_accounts`: comptes WordPress existants.
- `wp_classified_ads_count`: nombre de petites annonces sur la periode.
- `wp_classified_ads_revenue`: chiffre d'affaires petites annonces sur la periode.

Les indicateurs Matomo, Swello, reseaux sociaux, Mailjet, Stripe, Podle et autres sources externes ne sont pas collectes.

## Statistiques Advanced Ads

Si l'option Advanced Ads est activee, le plugin collecte les statistiques locales de l'add-on Tracking:

- impressions par publicite;
- clics par publicite;
- CTR par publicite;
- totaux impressions, clics et CTR sur la periode.

Le flux Advanced Ads est envoye vers un endpoint Django separe de l'endpoint Global Digital. Le plugin tente de detecter automatiquement les tables `advads_impressions` et `advads_clicks` avec le prefixe WordPress courant. Si un site utilise un schema different, les noms de tables peuvent etre renseignes dans les reglages.

## Installation

1. Installer le ZIP depuis `Extensions > Ajouter > Televerser une extension`.
2. Activer `Global Digital WP Sync`.
3. Aller dans `Reglages > Global Digital Sync`.
4. Renseigner l'endpoint API Global Digital, le jeton et le header d'authentification.
5. Adapter les meta keys, taxonomies, slugs et types de contenus selon le WordPress cible.
6. Optionnel: activer Advanced Ads, renseigner l'endpoint Django et ajuster les tables de tracking.

## Payload envoye

Le plugin envoie un POST JSON:

```json
{
  "source": "wordpress",
  "site": {
    "name": "Nom du site",
    "url": "https://example.com/",
    "timezone": "Europe/Paris"
  },
  "period": {
    "start_date": "2026-06-01",
    "end_date": "2026-06-30"
  },
  "generated_at": "2026-06-08T09:00:00+00:00",
  "plugin": {
    "name": "global-digital-wp-sync",
    "version": "0.1.0"
  },
  "metrics": {
    "wp_articles_published": {
      "label": "Articles publies sur la periode",
      "source": "wordpress",
      "value": 42,
      "type": "integer"
    }
  }
}
```

## Reglages importants

- `Types de contenus articles`: par defaut `post`.
- `Meta keys paywall` et `Valeurs paywall`: detection par champs personnalises.
- `Taxonomies paywall` et `Slugs de termes paywall`: detection par termes WordPress.
- `Meta keys datawall` et `Slugs de termes datawall`: meme logique pour le datawall.
- `Types de contenus petites annonces`: par defaut `petite_annonce,petites_annonces,classified,annonce`.
- `Meta keys CA petites annonces`: champs numeriques a sommer.
- `Activer Advanced Ads`: active le payload Django.
- `Endpoint statistiques Django`: endpoint POST JSON pour les stats Advanced Ads.
- `Table impressions Advanced Ads` et `Table clics Advanced Ads`: laisser vide pour detection automatique.

## Payload Django Advanced Ads

Le plugin envoie un POST JSON separe vers l'API statistiques Django:

```json
{
  "source": "wordpress_advanced_ads",
  "site": {
    "name": "Nom du site",
    "url": "https://example.com/",
    "timezone": "Europe/Paris",
    "blog_id": 1
  },
  "period": {
    "start_date": "2026-06-01",
    "end_date": "2026-06-30"
  },
  "generated_at": "2026-06-08T09:00:00+00:00",
  "advanced_ads": {
    "available": true,
    "totals": {
      "impressions": 10000,
      "clicks": 120,
      "ctr": 1.2
    },
    "ads": [
      {
        "ad_id": 123,
        "title": "Banniere exemple",
        "status": "publish",
        "post_type": "advanced_ads",
        "impressions": 5000,
        "clicks": 75,
        "ctr": 1.5
      }
    ],
    "detected": {
      "impressions": {
        "table": "wp_advads_impressions",
        "ad_id_column": "ad_id",
        "date_column": "timestamp",
        "count_column": "count",
        "period_filtered": true,
        "count_mode": "sum_column"
      }
    },
    "errors": []
  }
}
```

## Hooks disponibles

Adapter le payload final:

```php
add_filter('gd_wp_sync_payload', function ($payload, $settings, $trigger) {
    $payload['tenant'] = 'dentaire365-france';
    return $payload;
}, 10, 3);
```

Adapter ou ajouter des metriques:

```php
add_filter('gd_wp_sync_metrics', function ($metrics, $start_date, $end_date, $settings) {
    $metrics['wp_custom_metric'] = array(
        'label' => 'Metrique custom WP',
        'source' => 'wordpress',
        'value' => 123,
        'type' => 'integer',
    );

    return $metrics;
}, 10, 4);
```

Modifier les headers API:

```php
add_filter('gd_wp_sync_api_headers', function ($headers, $settings) {
    $headers['X-Site-Key'] = 'dentaire365-france';
    return $headers;
}, 10, 2);
```

Modifier les headers Django:

```php
add_filter('gd_wp_sync_django_stats_headers', function ($headers, $settings) {
    $headers['X-Site-Key'] = 'dentaire365-france';
    return $headers;
}, 10, 2);
```

Adapter le payload Django:

```php
add_filter('gd_wp_sync_django_stats_payload', function ($payload, $settings, $trigger) {
    $payload['tenant'] = 'dentaire365-france';
    return $payload;
}, 10, 3);
```

## WP-CLI

Si WP-CLI est disponible:

```bash
wp global-digital-sync push --start=2026-06-01 --end=2026-06-30
```
