# Global Digital WP Sync

Plugin WordPress pour collecter uniquement les indicateurs Global Digital dont la source est WordPress, puis les pousser vers une API Global Digital.

## Indicateurs inclus

- `wp_articles_published`: articles publies sur la periode.
- `wp_articles_datawall`: articles sous datawall sur la periode.
- `wp_articles_paywall`: articles sous paywall sur la periode.
- `wp_existing_accounts`: comptes WordPress existants.
- `wp_classified_ads_count`: nombre de petites annonces sur la periode.
- `wp_classified_ads_revenue`: chiffre d'affaires petites annonces sur la periode.

Les indicateurs Matomo, Swello, reseaux sociaux, Mailjet, Stripe, Podle et autres sources externes ne sont pas collectes.

## Installation

1. Installer le ZIP depuis `Extensions > Ajouter > Televerser une extension`.
2. Activer `Global Digital WP Sync`.
3. Aller dans `Reglages > Global Digital Sync`.
4. Renseigner l'endpoint API, le jeton et le header d'authentification.
5. Adapter les meta keys, taxonomies, slugs et types de contenus selon le WordPress cible.

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

## WP-CLI

Si WP-CLI est disponible:

```bash
wp global-digital-sync push --start=2026-06-01 --end=2026-06-30
```
