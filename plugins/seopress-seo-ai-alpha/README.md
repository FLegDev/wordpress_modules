# Multilingual SEO AI for SEOPress - Alpha

Plugin WordPress experimental pour generer depuis l'administration des metas SEO et des suggestions d'optimisation article avec OpenAI ou Claude, puis les appliquer aux champs SEOPress.

## Installation

1. Dans WordPress, va dans Extensions > Ajouter > Televerser une extension.
2. Envoie `seopress-seo-ai-alpha.zip`.
3. Active l'extension.
4. Ouvre Outils > SEO Meta AI SEOPress.
5. Renseigne une cle OpenAI ou Anthropic, choisis le fournisseur, puis enregistre.

## Ce que fait l'alpha

- Detecte la langue principale du site via la locale WordPress, ou permet de forcer une langue manuellement.
- Liste la page d'accueil si elle est une page statique, puis les articles/pages/types publics selectionnes.
- Ajoute une boite "SEO Meta AI - SEOPress" dans l'ecran d'edition des articles/pages selectionnes.
- Genere pour chaque contenu un SEO title, une meta description, une requete cible, des mots-cles secondaires, un titre Open Graph et une description Open Graph.
- Genere aussi des conseils d'optimisation article : contenu a enrichir, idees de H2/H3, maillage interne, manques a combler et lisibilite.
- Propose des suggestions de poursuite de lecture a partir des contenus existants du site, avec URL, texte d'ancre, raison et emplacement conseille.
- Dans l'edition d'un article, peut proposer une version de contenu optimisee SEO.
- Le bouton "Transformer le texte dans l'editeur" injecte la proposition dans Gutenberg ou l'editeur classique, puis tu sauvegardes avec le bouton WordPress natif.
- Peut afficher les lectures recommandees en slider en bas d'article.
- Ajoute deux blocs Gutenberg : "Poursuite de lecture" et "Popup poursuite de lecture".
- Ecrit dans SEOPress seulement quand tu cliques sur "Appliquer a SEOPress".
- Enregistre un backup JSON avant chaque application dans `_vsspa_seopress_backup`.

## Champs SEOPress modifies

- `_seopress_titles_title`
- `_seopress_titles_desc`
- `_seopress_analysis_target_kw`
- `_seopress_social_fb_title`
- `_seopress_social_fb_desc`
- `_seopress_social_twitter_title`
- `_seopress_social_twitter_desc`

Les suggestions editoriales sont stockees dans `_vsspa_article_optimization_suggestions`. Les suggestions de poursuite de lecture sont stockees dans `_vsspa_reading_recommendations` et `_vsspa_reading_recommendations_json`. Les propositions de contenu optimise sont stockees dans `_vsspa_optimized_content_proposal` et `_vsspa_optimized_content_summary`. Les mots-cles secondaires restent aussi dans `_vsspa_secondary_keywords`.

## Notes importantes

- Le plugin ne reecrit pas le contenu des articles sans action humaine. Il propose un texte optimise, puis le bouton de transformation l'insere dans l'editeur pour relecture avant sauvegarde.
- La reecriture SEO utilise une version texte du contenu et produit du HTML WordPress simple. Elle ne preserve pas encore parfaitement les blocs complexes, images ou mises en page avancees.
- Le slider public apparait seulement apres generation et application des recommandations, car l'application stocke les recommandations JSON.
- Sur l'ecran d'edition, enregistre l'article avant generation pour que l'IA analyse la derniere version sauvegardee.
- Les metas sont generees dans la langue principale choisie, meme pour un site multilingue.
- L'alpha utilise `manage_options`, donc elle est reservee aux administrateurs.
- Verifie toujours les suggestions avant application, surtout sur les pages commerciales ou medicales/juridiques/financieres.
