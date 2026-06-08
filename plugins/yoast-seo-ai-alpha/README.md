# Multilingual SEO AI for Yoast - Alpha

Plugin WordPress alpha pour generer des metas SEO multilingues avec OpenAI
ou Claude depuis l'admin WordPress, puis les enregistrer dans les champs Yoast SEO.

## Ce que fait cette alpha

- Ajoute une page admin : `Outils > SEO Meta AI`
- Configure un fournisseur : OpenAI ou Claude / Anthropic
- Configure une cle API et un modele par fournisseur
- Detecte la langue principale du site WordPress
- Utilise cette langue principale pour toutes les suggestions
- Permet de forcer une autre langue manuellement si besoin
- Liste les derniers articles/pages
- Genere une suggestion SEO avec le fournisseur choisi
- Affiche une preview editable dans l'admin
- Enregistre dans les champs Yoast uniquement apres clic manuel
- Garde un backup JSON dans le post meta `_vsmya_yoast_backup`

## Champs Yoast remplis

- `_yoast_wpseo_title`
- `_yoast_wpseo_metadesc`
- `_yoast_wpseo_focuskw`
- `_yoast_wpseo_opengraph-title`
- `_yoast_wpseo_opengraph-description`

Les mots-cles secondaires sont conserves dans `_vsmya_secondary_keywords`.
Ils servent de guide editorial, pas de balise `meta keywords`.

## Installation

Option 1, dossier plugin :

1. Copier le dossier `yoast-seo-ai-alpha` dans `wp-content/plugins/`
2. Activer le plugin dans `Extensions`
3. Aller dans `Outils > SEO Meta AI`

Option 2, ZIP :

1. Creer un zip du dossier `yoast-seo-ai-alpha`
2. Dans WordPress : `Extensions > Ajouter > Televerser une extension`
3. Installer puis activer

## Utilisation

1. Aller dans `Outils > SEO Meta AI`
2. Choisir le fournisseur : `OpenAI` ou `Claude / Anthropic`
3. Renseigner la cle API correspondante
4. Choisir le modele
5. Choisir le mode de langue
6. Choisir les types de contenus a afficher
7. Cliquer sur `Generer` pour une ligne
8. Relire/modifier la suggestion
9. Cliquer sur `Appliquer a Yoast`

## Langue principale

Modes disponibles :

- `Detecter depuis la langue principale du site` : utilise `get_locale()` WordPress
- `Forcer une langue manuelle` : force toutes les suggestions dans une langue donnee

Le plugin envoie au LLM :

- la langue principale du site
- la langue cible a utiliser pour les metas
- la source de detection, par exemple `wordpress_site_locale`
- le contenu de la page

Le prompt demande explicitement de generer toutes les metas dans cette langue
principale, avec les accents, la ponctuation et les formulations naturelles.

Si un site utilise plusieurs langues mais qu'on veut seulement suivre sa langue
principale, c'est le mode par defaut. Le plugin ne cherche pas a detecter la
langue de chaque traduction separement.

## SEO multilingue

Pour cette alpha, le comportement est simple : toutes les metas sont generees
dans la langue principale du site. Cela convient a un site mono-langue et a un
site techniquement multilingue dont on veut optimiser la langue principale.

Les bases SEO restent les memes : title clair, meta description utile, intention
de recherche, pas de bourrage de mots-cles. Ce qui change selon la langue, c'est
surtout la formulation naturelle et les mots-cles que les utilisateurs tapent
vraiment dans cette langue.

Cette alpha adapte les metas a la langue principale du site. Elle ne gere pas
les tags `hreflang`, l'architecture des URLs multilingues, ni l'optimisation
separee de chaque traduction.

## Fournisseurs supportes

### OpenAI

Le plugin utilise l'API Responses :

```txt
https://api.openai.com/v1/responses
```

Modele par defaut :

```txt
gpt-5-mini
```

### Claude / Anthropic

Le plugin utilise l'API Messages :

```txt
https://api.anthropic.com/v1/messages
```

Avec les en-tetes :

```txt
x-api-key
anthropic-version: 2023-06-01
content-type: application/json
```

Modele Claude par defaut :

```txt
claude-sonnet-4-5-20250929
```

Le champ reste editable dans l'admin pour pouvoir utiliser un autre modele
disponible sur ton compte Anthropic.

## Erreur de quota OpenAI

Si tu vois une erreur du type :

```txt
You exceeded your current quota
```

cela veut dire que le fournisseur actif est encore `OpenAI`. Pour utiliser
Claude :

1. Choisir `Claude / Anthropic` dans `Fournisseur`
2. Verifier que la cle Anthropic est enregistree
3. Cliquer sur `Enregistrer les reglages`
4. Relancer `Generer`

Le plugin affiche maintenant le fournisseur actif pres des boutons de generation.

Le bouton `Generer les suggestions visibles` lance la generation ligne par ligne.
Le bouton `Appliquer les suggestions generees` enregistre les lignes qui ont deja
une suggestion visible.

## Page d'accueil

L'alpha gere automatiquement la page d'accueil si WordPress utilise une page
statique comme accueil.

Si l'accueil affiche les derniers articles, l'outil ne modifie pas encore les
templates Yoast globaux. Dans ce cas, il faudra soit passer par une page statique,
soit ajouter une integration plus specifique aux options globales Yoast.

## Securite

- Acces limite aux administrateurs avec `manage_options`
- Nonce WordPress sur les appels AJAX
- Aucune ecriture automatique sans clic d'application
- Backup avant chaque ecriture Yoast

## Limites connues

- Pas encore de file d'attente serveur pour traiter des centaines d'articles
- Pas encore de rollback visuel dans l'admin
- Pas encore d'integration Google Search Console
- Pas encore d'import/export CSV dans le plugin
- Les cles API sont stockees dans les options WordPress, comme beaucoup de plugins alpha

## Prochaines ameliorations utiles

- Mode batch robuste via Action Scheduler
- Ecran d'historique et rollback
- Priorisation des articles sans metas ou avec faible CTR
- Integration Search Console
- Mode "auto pour nouveaux articles, validation manuelle pour pages business"
