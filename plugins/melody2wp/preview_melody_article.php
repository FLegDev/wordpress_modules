<?php get_header();
/**
 * Nettoie les fichiers temporaires Melody preview (dossiers de plus de 24h)
 */
function melody_preview_clean_temp($hours = 24) {
    $upload_dir = wp_upload_dir();
    $preview_root = $upload_dir['basedir'] . '/melody-preview/';
    if (!file_exists($preview_root)) return;
    foreach (glob($preview_root . 'preview_*') as $folder) {
        if (is_dir($folder)) {
            $last_mod = filemtime($folder);
            if ($last_mod && (time() - $last_mod > $hours * 3600)) {
                array_map('unlink', glob("$folder/*.*"));
                @rmdir($folder);
            }
        }
    }
}
/**
 * Télécharge temporairement une image Melody et retourne son URL locale temporaire.
 * Stocke dans /wp-content/uploads/melody-preview/preview_{id}/
 */
function melody_preview_temp_image($remote_url, $reference, $preview_id) {
    $upload_dir = wp_upload_dir();
    $preview_folder = $upload_dir['basedir'] . '/melody-preview/preview_' . intval($preview_id);
    if (!file_exists($preview_folder)) {
        wp_mkdir_p($preview_folder);
    }
    $parsed = pathinfo(parse_url($remote_url, PHP_URL_PATH));
    $extension = isset($parsed['extension']) ? $parsed['extension'] : 'jpg';
    // Ajout d’un hash du crop dans le nom pour garantir unicité en cas de crop différent
    $crop_hash = '';
    if (preg_match('/crop=({.*?})/', $remote_url, $matches_crop)) {
        $crop_hash = '_' . substr(md5($matches_crop[1]), 0, 8);
    }
    $filename = $reference . $crop_hash . '.' . $extension;
    $local_path = $preview_folder . '/' . $filename;
    // Si le fichier n’existe pas, on télécharge
    if (!file_exists($local_path)) {
        $img_data = @file_get_contents($remote_url);
        if ($img_data !== false) {
            file_put_contents($local_path, $img_data);
        }
    }
    return $upload_dir['baseurl'] . '/melody-preview/preview_' . intval($preview_id) . '/' . $filename;
}
melody_preview_clean_temp(1);
    // Empêche l'indexation des pages de preview par les moteurs de recherche
    add_action('wp_head', function() {
        if (get_query_var('preview_melody_id')) {
            echo '<meta name="robots" content="noindex, nofollow">';
        }
    });
    $article_id = get_query_var('preview_melody_id');
    $url = 'https://melody.parresia.demainunautrejour.com/webservice/article/' . intval($article_id);
    $xml = @simplexml_load_file($url);
    $preview_url = home_url('/non-classifiee/preview-melody/' . intval($article_id));

    if ( ! $xml ) {
        wp_die('Erreur lors de la récupération de l’article Melody.');
    }

	// Récupère l'ID Melody du XML avant l'insertion du post
	$melody_id = $article_id;

	// Suite de la logique d’import inchangée
	$post_title = strip_tags((string) $xml->title);
	$excerpt = strip_tags((string) $xml->abstract);
	$author_name = (string) $xml->author;

	// Champs éditoriaux avancés du XML
	$sup_title         = isset($xml->supTitle)         ? trim(strip_tags((string)$xml->supTitle))         : '';
	$sub_title         = isset($xml->subTitle)         ? trim(strip_tags((string)$xml->subTitle))         : '';
	$secondary_title   = isset($xml->secondaryTitle)   ? trim(strip_tags((string)$xml->secondaryTitle))   : '';
	$exergue           = isset($xml->exergue)          ? trim(strip_tags((string)$xml->exergue))          : '';

	$query = new WP_Query([
		'post_type'      => 'post',
		'title'          => $post_title,
		'posts_per_page' => 1,
		'post_status'    => 'any',
	]);

	$my_post_id = $query->have_posts() ? $query->post : null;


	$reference_to_id_map = $media_data['reference_to_id_map'] ?? []; // Get the map

	//get post content from xml file
	$content = (string) $xml->completeObjectText; // Cast to string

	// Pattern to find input tags with background-image style
	$pattern = '/<input [^>]*style="[^"]*background-image: url\\([\'"]?(.*?HOT\/(.*?)\/128)[^\'"]*[\'"]?\\);[^"]*"[^>]*>/i';

	// Replace input tags with <figure><img ... alt="..."><figcaption>...</figcaption></figure>
	$content = preg_replace_callback($pattern, function ($matches) use ($xml, $melody_id) {
		static $main_photo_url = null, $main_photo_legend = null, $main_photo_credit = null, $main_photo_attrs = '';

		$full_url = $matches[1];
		$reference = $matches[2]; // La valeur après HOT/ et avant /128

		$photo_url = '';
		$legend_raw = '';
		$credit_raw = '';
		$photo = null;

		if (!empty($reference) && isset($xml->photos->photo)) {
			error_log("Reference recherchée : $reference");
			error_log("Références disponibles : ");
			foreach ($xml->photos->photo as $p) {
				error_log(" - " . (string)$p->reference);
				if (isset($p->WEB_HARDCROP_RESSOURCE)) {
					$photo_web_url = (string) $p->WEB_HARDCROP_RESSOURCE;
					if (strpos($photo_web_url, $reference) !== false) {
						$photo_url = $photo_web_url;
						$photo = $p;
						$legend_raw = '';
						if (isset($p->legend_raw)) {
							$legend_raw_xml = $p->legend_raw->asXML();
							if ($legend_raw_xml) {
								// Extrait le contenu interne à la balise <legend_raw>
								$legend_raw = preg_replace('/^<legend_raw>(.*)<\/legend_raw>$/s', '$1', $legend_raw_xml);
								error_log('legend_raw (inner) = [' . $legend_raw . ']');
							} else {
								error_log('legend_raw asXML est vide');
							}
						}
						$credit_raw = isset($p->credit_raw) ? (string)$p->credit_raw : '';
						break;
					}
				}
			}
		}

		if (!empty($photo_url)) {
			// Alt attribute: strip tags from legend_raw, then esc_attr
			$alt_text = esc_attr(strip_tags($legend_raw));
			// Logique crop strictement identique à la prod (uniquement JSON Melody)
			$crop_w = 0;
			$crop_h = 0;
			if ($photo && isset($photo->crop)) {
				$crop_w = isset($photo->crop->crop_w) ? (int)$photo->crop->crop_w : 0;
				$crop_h = isset($photo->crop->crop_h) ? (int)$photo->crop->crop_h : 0;
			}
			if ((!$crop_w || !$crop_h) && !empty($photo_url)) {
				if (preg_match('/crop=({.*?})/', $photo_url, $matches_crop)) {
					$crop_data = json_decode(urldecode($matches_crop[1]), true);
					if (isset($crop_data['crop_w']) && isset($crop_data['crop_h'])) {
						$crop_w = (int)$crop_data['crop_w'];
						$crop_h = (int)$crop_data['crop_h'];
					}
				}
			}
			$img_attrs = ($crop_w > 0 && $crop_h > 0) ? ' width="' . $crop_w . '" height="' . $crop_h . '"' : '';

			// Enregistre la première image trouvée pour le thumbnail
			if ($main_photo_url === null) {
				$main_photo_url = $photo_url;
				$main_photo_legend = $legend_raw;
				$main_photo_credit = $credit_raw;
				$main_photo_attrs = $img_attrs;
			}

			$local_temp_url = melody_preview_temp_image($photo_url, $reference, $melody_id);
			$img_html = '<img src="' . esc_url($local_temp_url) . '" alt="' . $alt_text . '"' . $img_attrs . ' />';
			$figcaption = '';
			$parts = [];

			// Nettoie la légende brute
			$legend_html = trim($legend_raw);
			// Supprime les paragraphes vides au début (si le XML en met)
			$legend_html = preg_replace('/^(<p>\s*<\/p>\s*)+/', '', $legend_html);
			// Insère "Légende :" dans le premier <p> non vide
			$legend_html = preg_replace('/<p>/', '<p>Légende : ', $legend_html, 1);

			$legend_clean = trim(strip_tags($legend_html));
			if (!empty($legend_clean)) {
				$parts[] = '<span class="legend">' . wp_kses_post($legend_html) . '</span>';
			}

			// Nettoie le crédit pour ne pas afficher si vide ou balises seulement
			$credit_clean = trim(strip_tags($credit_raw));
			if (!empty($credit_clean)) {
				$parts[] = '<span class="credit">Crédit : ' . esc_html($credit_raw) . '</span>';
			}

			if (!empty($parts)) {
				$figcaption = '<figcaption>' . implode(' ', $parts) . '</figcaption>';
			}
			return '<figure>' . $img_html . $figcaption . '</figure>';
		}

		return '<!-- Image non trouvée pour référence: ' . htmlspecialchars($reference) . ' -->';
	}, $content);

	// Récupération des variables statiques de la callback pour l'affichage du thumbnail
	$main_photo_url = null;
	$main_photo_legend = null;
	$main_photo_credit = null;
	$main_photo_attrs = '';

	$first_image_data = [
		'url' => null,
		'legend' => null,
		'credit' => null,
		'attrs' => '',
	];

	// On refait la preg_replace_callback avec la variable externe

	$content = preg_replace_callback($pattern, function ($matches) use ($xml, &$first_image_data, $melody_id) {
		$full_url = $matches[1];
		$reference = $matches[2]; // La valeur après HOT/ et avant /128

		$photo_url = '';
		$legend_raw = '';
		$credit_raw = '';
		$photo = null;

		if (!empty($reference) && isset($xml->photos->photo)) {
			foreach ($xml->photos->photo as $p) {
				if (isset($p->WEB_HARDCROP_RESSOURCE)) {
					$photo_web_url = (string) $p->WEB_HARDCROP_RESSOURCE;
					if (strpos($photo_web_url, $reference) !== false) {
						$photo_url = $photo_web_url;
						$photo = $p;
						$legend_raw = '';
						if (isset($p->legend_raw)) {
							$legend_raw_xml = $p->legend_raw->asXML();
							if ($legend_raw_xml) {
								$legend_raw = preg_replace('/^<legend_raw>(.*)<\/legend_raw>$/s', '$1', $legend_raw_xml);
							}
						}
						$credit_raw = isset($p->credit_raw) ? (string)$p->credit_raw : '';
						break;
					}
				}
			}
		}

		if (!empty($photo_url)) {
			$alt_text = esc_attr(strip_tags($legend_raw));
			// Logique crop strictement identique à la prod (uniquement JSON Melody)
			$crop_w = 0;
			$crop_h = 0;
			if ($photo && isset($photo->crop)) {
				$crop_w = isset($photo->crop->crop_w) ? (int)$photo->crop->crop_w : 0;
				$crop_h = isset($photo->crop->crop_h) ? (int)$photo->crop->crop_h : 0;
			}
			if ((!$crop_w || !$crop_h) && !empty($photo_url)) {
				if (preg_match('/crop=({.*?})/', $photo_url, $matches_crop)) {
					$crop_data = json_decode(urldecode($matches_crop[1]), true);
					if (isset($crop_data['crop_w']) && isset($crop_data['crop_h'])) {
						$crop_w = (int)$crop_data['crop_w'];
						$crop_h = (int)$crop_data['crop_h'];
					}
				}
			}
			$img_attrs = ($crop_w > 0 && $crop_h > 0) ? ' width="' . $crop_w . '" height="' . $crop_h . '"' : '';

			// Enregistre la première image trouvée pour le thumbnail
			if ($first_image_data['url'] === null) {
				$first_image_data['url'] = $photo_url;
				$first_image_data['legend'] = $legend_raw;
				$first_image_data['credit'] = $credit_raw;
				$first_image_data['attrs'] = $img_attrs;
			}

			$local_temp_url = melody_preview_temp_image($photo_url, $reference, $melody_id);
			$img_html = '<img src="' . esc_url($local_temp_url) . '" alt="' . $alt_text . '"' . $img_attrs . ' />';
			$figcaption = '';
			$parts = [];

			$legend_html = trim($legend_raw);
			$legend_html = preg_replace('/^(<p>\s*<\/p>\s*)+/', '', $legend_html);
			$legend_html = preg_replace('/<p>/', '<p>Légende : ', $legend_html, 1);

			$legend_clean = trim(strip_tags($legend_html));
			if (!empty($legend_clean)) {
				$parts[] = '<span class="legend">' . wp_kses_post($legend_html) . '</span>';
			}

			$credit_clean = trim(strip_tags($credit_raw));
			if (!empty($credit_clean)) {
				$parts[] = '<span class="credit">Crédit : ' . esc_html($credit_raw) . '</span>';
			}

			if (!empty($parts)) {
				$figcaption = '<figcaption>' . implode(' ', $parts) . '</figcaption>';
			}
			return '<figure>' . $img_html . $figcaption . '</figure>';
		}

		return '<!-- Image non trouvée pour référence: ' . htmlspecialchars($reference) . ' -->';
	}, $content);


	// Ligne supprimée car méthode custom non disponible dans ce contexte
	// $content = $xml->replace_external_image_urls_with_local($content, $reference_to_id_map);

	// Récupération du champ note_raw
	$note_raw = '';
	if (isset($xml->note_raw)) {
		$note_raw_xml = $xml->note_raw->asXML();
		if ($note_raw_xml) {
			$note_raw = preg_replace('/^<note_raw>(.*)<\/note_raw>$/s', '$1', $note_raw_xml);
		}
	}

	// Nettoie la note pour ne pas afficher un bloc vide (ex : <p></p> ou espaces)
	$note_clean = trim(strip_tags($note_raw));
	if (!empty($note_clean)) {
		$note_block = '<div class="encadre note-bas-page">' . wp_kses_post($note_raw) . '</div>';
		$content .= $note_block;
	}

	// Affichage des articles liés en bas de page (children)
	$children_html = '';
	if (!empty($xml->children) && isset($xml->children->child)) {
		$children_html = '<div class="children_post">';
		$children_html .= '<h3>Suite du dossier</h3>';
		$children_html .= '<ul>';
		foreach ($xml->children->child as $child) {
			// Récupère l’ID
			$child_id = isset($child->id) ? (string)$child->id : '';
			$fallback_title = isset($child->title) && !empty((string)$child->title)
				? (string)$child->title
				: (isset($child->title_raw) ? (string)$child->title_raw : '');
			$fallback_title = trim(strip_tags(html_entity_decode($fallback_title)));

			// Recherche du post WP lié à cet ID Melody
			$post_link = '';
			$wp_title = '';
			if (!empty($child_id)) {
				$query = new WP_Query([
					'post_type'   => 'post',
					'meta_key'    => 'melody_id',
					'meta_value'  => $child_id,
					'post_status' => 'publish',
					'fields'      => 'ids',
					'posts_per_page' => 1
				]);
				if (!empty($query->posts)) {
					$child_post_id = $query->posts[0];
					$post_link = get_permalink($child_post_id);
					$wp_title = get_the_title($child_post_id);
				}
				wp_reset_postdata();
			}

			// Affichage du titre WP s’il existe, sinon fallback XML
			if (!empty($post_link) && !empty($wp_title)) {
				$children_html .= '<li class="children_item"><a href="' . esc_url($post_link) . '">' . esc_html($wp_title) . '</a></li>';
			} else {
				$children_html .= '<li class="children_item">' . esc_html($fallback_title) . '</li>';
			}
		}
		$children_html .= '</ul>';
		$children_html .= '</div>';
		// Ajoute à la fin du contenu de l'article
	}

	$header_html = '';
	if ($sup_title)       $header_html .= '<span class="article-supertitle">' . esc_html($sup_title) . '</span>';
	if ($secondary_title) $header_html .= '<h2 class="article-secondarytitle">' . esc_html($secondary_title) . '</h2>';
	if ($exergue)         $header_html .= '<blockquote class="article-exergue"><p class="mb-0">' . esc_html($exergue) . '</p></blockquote>';

	//AUTEUR DE L'ARTICLE --*

	// Récupération du champ author_raw
	$author_box_html = '';
	$author_raw = '';
	if (isset($xml->author_raw)) {
		$author_raw_xml = $xml->author_raw->asXML();
		if ($author_raw_xml) {
			$author_raw = preg_replace('/^<author_raw>(.*)<\/author_raw>$/s', '$1', $author_raw_xml);
		}
	} elseif (isset($xml->author)) {
		// Si pas de author_raw, fallback sur author
		$author_raw_xml = $xml->author->asXML();
		if ($author_raw_xml) {
			$author_raw = preg_replace('/^<author>(.*)<\/author>$/s', '$1', $author_raw_xml);
		}
	}

	// Nettoie l'auteur pour ne pas afficher un bloc vide
	$author_clean = trim(strip_tags($author_raw));
	if (!empty($author_clean)) {
		$author_box_html = '<div class="encadre author-box"><strong>Auteur :</strong> ' . wp_kses_post($author_raw) . '</div>';
	}

	//END AUTEUR DE L'ARTICLE --*

	$post_content = $header_html . $content .$author_box_html;

	if ($sub_title) {
		// Si tu veux insérer le sous-titre en tout début du contenu (tu peux le placer ailleurs)
		$post_content = '<div class="article-subtitle">' . esc_html($sub_title) . '</div>' . $post_content;
	}

	// add videos, if set, to content
	// Suppression de l'appel à méthode custom sur $xml
	// if ( isset( $xml->videos ) ) {
	// 	$post_content = $xml->add_post_videos( $post_content );
	// }
?>

    <main>
        <section class="single_post">
            <div class="container">
                <div class="row gx-md-3">
                    <div class="col-12">
                        <div class="pub__fullwidth">
                            <!-- Pub placeholder -->
                        </div>
                    </div>
                    <div class="col-12">
                        <?php if(function_exists('seopress_display_breadcrumbs')) { seopress_display_breadcrumbs(); } ?>
                    </div>

                    <article class="col-12 col-md-8 col-xl-9">
                        <div class="single_post__title">
                            <h1><?php echo $post_title; ?></h1>
	                        <div class="excerpt">
		                        <p><?php echo $excerpt; ?></p>
	                        </div>
                        </div>

	                    <?php
		                    // Récupère la photo Melody à afficher en thumbnail : priorité webForward=1, sinon première avec WEB_HARDCROP_RESSOURCE
		                    $photo_thumbnail = null;
		                    // Priorité à webForward=1
		                    if (isset($xml->photos->photo)) {
			                    foreach ($xml->photos->photo as $photo) {
				                    if (isset($photo->webForward) && intval($photo->webForward) === 1 && isset($photo->WEB_HARDCROP_RESSOURCE)) {
					                    $photo_thumbnail = $photo;
					                    break;
				                    }
			                    }
			                    // Si aucune photo webForward=1 trouvée, fallback sur la première avec WEB_HARDCROP_RESSOURCE
			                    if (!$photo_thumbnail) {
				                    foreach ($xml->photos->photo as $photo) {
					                    if (isset($photo->WEB_HARDCROP_RESSOURCE)) {
						                    $photo_thumbnail = $photo;
						                    break;
					                    }
				                    }
			                    }
		                    }
	                    ?>

	                    <!--
	                        Preview Melody : on n’utilise jamais l’image mise en avant WordPress, uniquement celle du XML Melody
	                    -->
	                    <div class="single_post__thumbnail">
	                        <?php if ($photo_thumbnail && isset($photo_thumbnail->WEB_HARDCROP_RESSOURCE)) :
	                            $photo_url = (string) $photo_thumbnail->WEB_HARDCROP_RESSOURCE;
	                            $legend = isset($photo_thumbnail->legend_raw) ? preg_replace('/^<legend_raw>(.*)<\/legend_raw>$/s', '$1', $photo_thumbnail->legend_raw->asXML()) : '';
	                            $credit = isset($photo_thumbnail->credit_raw) ? (string) $photo_thumbnail->credit_raw : '';
	                            $crop_w = 0;
	                            $crop_h = 0;
	                            if (isset($photo_thumbnail->crop)) {
	                                $crop_w = isset($photo_thumbnail->crop->crop_w) ? (int)$photo_thumbnail->crop->crop_w : 0;
	                                $crop_h = isset($photo_thumbnail->crop->crop_h) ? (int)$photo_thumbnail->crop->crop_h : 0;
	                            }
	                            if ((!$crop_w || !$crop_h) && !empty($photo_url)) {
	                                if (preg_match('/crop=({.*?})/', $photo_url, $matches)) {
	                                    $crop_data = json_decode(urldecode($matches[1]), true);
	                                    if (isset($crop_data['crop_w']) && isset($crop_data['crop_h'])) {
	                                        $crop_w = (int)$crop_data['crop_w'];
	                                        $crop_h = (int)$crop_data['crop_h'];
	                                    }
	                                }
	                            }
	                            $img_attrs = ($crop_w > 0 && $crop_h > 0) ? ' width="' . $crop_w . '" height="' . $crop_h . '"' : '';
	                            $photo_url_temp = melody_preview_temp_image($photo_url, (string) $photo_thumbnail->reference, $melody_id);
	                        ?>
	                            <figure class="mb-0">
	                                <img src="<?php echo esc_url($photo_url_temp); ?>"
	                                     alt="<?php echo esc_attr(strip_tags($legend)); ?>"
	                                     class="img-fluid w-100"
	                                     <?php echo $img_attrs; ?>>
	                                <?php if (trim(strip_tags($legend)) || trim(strip_tags($credit))) : ?>
	                                    <figcaption>
	                                        <?php if (trim(strip_tags($legend))) : ?>
	                                            <span class="legend"><?php echo wp_kses_post($legend); ?></span>
	                                        <?php endif; ?>
	                                        <?php if (trim(strip_tags($credit))) : ?>
	                                            <span class="credit">Crédit : <?php echo esc_html($credit); ?></span>
	                                        <?php endif; ?>
	                                    </figcaption>
	                                <?php endif; ?>
	                            </figure>
	                        <?php endif; ?>
	                    </div>

                        <div class="single_post__content">
                            <div class="non-paywall">
                                <?php echo $post_content; ?>
                            </div>
                        </div>
                    </article>

                    <!-- SIDEBAR -->
                    <?php get_template_part('template-parts/single/single', 'sidebar-preview'); ?>

                    <!-- SAME CATEGORY -->
                    <?php get_template_part('template-parts/single/single', 'same-category'); ?>
                </div>
            </div>
        </section>
    </main>

    <?php get_footer(); ?>