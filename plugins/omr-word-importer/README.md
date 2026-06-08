# OMR Word Importer

Plugin WordPress V1 pour importer manuellement un fichier `.docx` et creer un brouillon d article en blocs Gutenberg editables.

## Fonctionnalites V1

- Upload `.docx` depuis `Outils > OMR Word Importer`.
- Creation d un article WordPress en brouillon ou en attente de relecture.
- Conversion des styles Word `Titre 1` a `Titre 6` en blocs Gutenberg Heading.
- Conversion basique des paragraphes, listes et tableaux en blocs Gutenberg natifs.
- Detection des styles Word `Terminal`, `Code`, `Console`, `Bash`, `Shell`, `PowerShell`.
- Conversion des blocs terminal en blocs Gutenberg Code editables avec style terminal.
- Detection d une table des matieres Word et generation d un sommaire web cliquable.
- Option pour placer le sommaire dans le contenu ou dans une sidebar Gutenberg en colonnes.
- En mode sidebar, le sommaire est genere en accordeons Gutenberg `Details`, regroupes par `Titre 2`, avec des listes ordonnees.
- Ajout d ancres HTML sur les titres importes.
- Extraction des images integrees au `.docx` et conversion en blocs Gutenberg Image.
- Import des images dans la bibliotheque de medias WordPress.
- Lecture des `.docx` via `ZipArchive` si disponible, sinon fallback WordPress `PclZip`.

## Installation

1. Copier le dossier `omr-word-importer` dans `wp-content/plugins/`.
2. Activer le plugin dans l administration WordPress.
3. Aller dans `Outils > OMR Word Importer`.
4. Importer un fichier `.docx`.

## Convention recommandee dans Word

- Utiliser les styles Word natifs `Titre 1`, `Titre 2`, `Titre 3`.
- Creer un style de paragraphe nomme `Terminal` ou `Code` pour les commandes.
- Inserer les images directement dans le document Word.
- Eviter les mises en page complexes Word si l objectif est un article web propre.
- Si une table des matieres Word est presente, elle sera remplacee par une table des matieres web sans numeros de page.

## Limites connues

- Les listes numerotees sont converties en listes simples pour cette V1.
- Les tableaux complexes sont aplatis en HTML simple.
- Les polices et tailles Word ne sont pas conservees volontairement.
- Les images liees mais non integrees dans le `.docx` ne sont pas importees.
- La V1 ne surveille pas encore Google Drive.
