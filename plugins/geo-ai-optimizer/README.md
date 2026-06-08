# GEO AI Optimizer - Alpha

Plugin WordPress pour travailler le GEO (Generative Engine Optimization) :
fichiers `llms.txt`, miroirs Markdown, briefs de contenu et recommandations
IA pour rendre les pages plus faciles a comprendre, citer et resumer par les
LLMs et agents de recherche.

## Fonctionnalites

- Ajoute une page admin : `Outils > GEO AI Optimizer`
- Genere dynamiquement `/llms.txt` avec alias compatible `/llm.txt`
- Genere dynamiquement `/llms-full.txt` avec alias compatible `/llm-full.txt`
- Expose des versions Markdown propres des contenus : `/geo-md/{post_id}.md`
- Configure OpenAI ou Claude / Anthropic
- Fonctionne aussi sans cle API avec un audit heuristique local
- Analyse les pages et articles publies
- Produit un brief GEO par contenu :
  - resume IA canonique
  - reponse directe
  - description pour `llms.txt`
  - entites a clarifier
  - recommandations de structure et FAQ
  - recommandations schema.org
  - preuves manquantes
- Stocke le brief en post meta `_vgeo_geo_brief`
- Ajoute une metabox dans l'editeur WordPress

## Installation

1. Copier `geo-ai-optimizer` dans `wp-content/plugins/`
2. Activer le plugin dans `Extensions`
3. Aller dans `Outils > GEO AI Optimizer`
4. Enregistrer les reglages
5. Si `/llms.txt` retourne 404, aller dans `Reglages > Permaliens` puis enregistrer

## A propos de llms.txt

`llms.txt` est une convention emergente, pas une garantie de ranking. Le fichier
sert de carte concise en Markdown pour aider un agent ou un workflow de
retrieval a trouver les contenus importants sans crawler tout le site.

Le plugin suit la structure pratique :

```txt
# Nom du site

> Resume court

Informations de contexte

## Pages principales
- [Titre](URL): description utile

## Articles et guides
- [Titre](URL): description utile

## Optional
- [Sitemap XML](URL): inventaire complet
```

## Bonnes pratiques GEO integrees

- Dire clairement ce que la page repond
- Nommer les entites importantes
- Ajouter les preuves et sources manquantes
- Structurer les sous-questions en H2/H3
- Ajouter une FAQ quand le sujet s'y prete
- Garder des URLs canoniques et des contenus a jour
- Publier un `llms.txt` court et curate, pas un second sitemap

## Securite

- Acces admin limite a `manage_options`
- Nonces WordPress pour les actions AJAX
- Les cles API restent dans les options WordPress
- Le plugin ne modifie pas automatiquement le contenu public
- Seuls les contenus publics publies sont exposes en Markdown

## Limites de cette alpha

- Pas encore de file d'attente serveur pour analyser des centaines d'URLs
- Pas encore d'integration Search Console / Matomo
- Pas encore de monitoring des citations dans ChatGPT, Perplexity ou Google AI
- Pas encore de schema JSON-LD injecte automatiquement
- `llms.txt` reste un signal de lisibilite, pas une preuve d'indexation par les grands fournisseurs

## Prochaines ameliorations utiles

- Export CSV des briefs GEO
- Mode batch via Action Scheduler
- Generation optionnelle de blocs FAQ Gutenberg
- Injection controlee de schema FAQPage / Article / Organization
- Journal de versions des briefs
- Tableau de bord par clusters de sujets
