# WordPress Modules

Depot central des plugins et outils WordPress Parresia / Global Digital.

## Structure

- `plugins/` contient les versions propres et retenues des plugins WordPress.
- `variants/` conserve les anciennes variantes distinctes, utiles pour historique ou comparaison.
- `tools/` contient les outils compagnons qui ne sont pas des plugins WordPress activables.

## Plugins inclus

| Dossier | Plugin | Version | Note |
| --- | --- | --- | --- |
| `plugins/auto-menu-updater` | Auto Menu Updater | 1.0 | Plugin mono-fichier de navigation automatique. |
| `plugins/campaigns-redirector` | Campaigns Redirector | 1.6.8 | Version courante propre. |
| `plugins/geo-ai-optimizer` | GEO AI Optimizer - Alpha | 0.1.0-alpha | Optimisation GEO, llms.txt, miroirs Markdown et briefs LLM. |
| `plugins/global-digital-wp-sync` | Global Digital WP Sync | 0.2.0 | Synchronisation des indicateurs WordPress et stats Advanced Ads vers APIs Global Digital/Django. |
| `plugins/media-slider-blocks` | Media Slider Blocks | 1.2.4 | Blocs Gutenberg medias et slider. |
| `plugins/melody2wp` | Melody2WP | 1.0 | Envoi des articles Melody vers WordPress. |
| `plugins/omr-word-importer` | OMR Word Importer | 0.5.1 | Import `.docx` vers blocs Gutenberg. |
| `plugins/parresia-annonces` | Parresia Annonces | 1.8.1 | Module petites annonces WordPress. |
| `plugins/parresia-blocks` | Parresia Blocks | 0.1.0 | Blocs Gutenberg et widgets editoriaux. |
| `plugins/parresia-user-switcher` | Parresia User Switcher | 1.0.0 | Bascule admin vers compte utilisateur. |
| `plugins/redirection-pot-de-miel` | Redirection Pot de Miel | 1.0 | Redirection avec pot de miel. |
| `plugins/seopress-seo-ai-alpha` | Multilingual SEO AI for SEOPress - Alpha | 0.1.2-alpha | Generation de metas SEO IA pour SEOPress. |
| `plugins/yoast-seo-ai-alpha` | Multilingual SEO AI for Yoast - Alpha | 0.3.1-alpha | Generation de metas SEO IA pour Yoast. |

## Variantes conservees

| Dossier | Plugin | Version | Note |
| --- | --- | --- | --- |
| `variants/campaigns-redirector-1.6.7` | Campaigns Redirector | 1.6.7 | Ancienne version complete. |
| `variants/campaigns-redirector-https-only-1.2.2` | Campaigns Redirector (HTTPS only) | 1.2.2 | Variante historique HTTPS strict. |
| `variants/campaigns-redirector-matomo-safe-1.3.1` | Campaigns Redirector (Matomo-safe) | 1.3.1 | Variante historique Matomo-safe. |

## Outils compagnons

| Dossier | Outil | Note |
| --- | --- | --- |
| `tools/seo-meta-agent` | SEO Meta Agent | Outil Python de crawl et generation CSV pour metas SEO. |

## Notes

- Les doublons exacts detectes localement n'ont pas ete copies deux fois.
- Les dossiers generes (`__pycache__`, `node_modules`, `__MACOSX`) sont exclus.
- Les plugins peuvent etre installes dans WordPress en copiant le dossier voulu depuis `plugins/` vers `wp-content/plugins/`.
