# SEO Meta Agent

Petit MVP pour automatiser l'optimisation des metas SEO d'un site vietnamien.

Il crawle un site, extrait les pages, puis genere un CSV de validation avec :

- SEO title suggere
- meta description suggeree
- mot-cle principal
- mots-cles secondaires
- Open Graph title / description
- score de validation
- notes de controle

Important : l'outil ne cherche pas a remplir une ancienne balise HTML `meta keywords`.
Google ne l'utilise pas pour le ranking. Les mots-cles generes servent plutot de
cibles editoriales pour guider le title, la meta description, les H1/H2 et le contenu.

## Prerequis

Python 3.10+ suffit. Le script utilise uniquement la bibliotheque standard Python.

Pour la generation LLM, il faut une cle API OpenAI dans la variable d'environnement
`OPENAI_API_KEY`.

PowerShell :

```powershell
$env:OPENAI_API_KEY="sk-..."
```

Optionnel :

```powershell
$env:OPENAI_MODEL="gpt-5-mini"
```

## Test sans API

Le mode `--dry-run` permet de tester le crawl et le CSV sans appeler OpenAI :

```powershell
python .\seo_meta_agent.py https://example.vn --dry-run --max-pages 20 --out .\metas.csv
```

## Generation avec OpenAI

```powershell
python .\seo_meta_agent.py https://example.vn --max-pages 50 --out .\metas.csv
```

Le script essaie d'abord `sitemap.xml`, puis retombe sur un crawl interne depuis
l'URL de depart si aucun sitemap exploitable n'est trouve.

## Filtrer uniquement les articles

Exemples :

```powershell
python .\seo_meta_agent.py https://example.vn --include "/tin-tuc|/blog" --max-pages 100 --out .\articles_metas.csv
```

```powershell
python .\seo_meta_agent.py https://example.vn --exclude "/tag|/category|/author" --max-pages 100 --out .\metas.csv
```

## Fournir une liste d'URLs

Creer un fichier texte avec une URL par ligne, puis :

```powershell
python .\seo_meta_agent.py https://example.vn --pages-file .\urls.txt --out .\metas.csv
```

## Colonnes importantes du CSV

- `url`
- `page_type`
- `current_title`
- `current_meta_description`
- `current_meta_keywords`
- `h1`
- `suggested_seo_title`
- `suggested_meta_description`
- `primary_keyword`
- `secondary_keywords`
- `validation_score`
- `validation_notes`
- `generator`

## Mode operationnel conseille

1. Lancer sur 20 a 50 pages en `--dry-run` pour verifier le crawl.
2. Lancer avec OpenAI pour obtenir de vraies propositions.
3. Filtrer les lignes avec `validation_score < 75`.
4. Valider manuellement la page d'accueil et les pages business.
5. Importer le CSV dans le CMS ou ajouter un connecteur CMS dans une prochaine version.

## Prochaine etape logique

Apres validation du CSV, on peut brancher un connecteur :

- WordPress REST API
- Odoo
- Shopify
- Webflow
- CMS custom

Le connecteur dependra surtout de l'endroit ou les metas SEO sont stockees dans le CMS.
