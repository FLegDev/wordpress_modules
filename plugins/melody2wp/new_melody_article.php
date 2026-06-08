<?php
	# -*- coding: utf-8 -*-
	header( 'Content-Type: text/css;charset=utf-8' );

	if ( ! function_exists( 'post_exists' ) ) {
		require_once( ABSPATH . 'wp-admin/includes/post.php' );
	}

	require_once( ABSPATH . '/wp-admin/includes/taxonomy.php' );
	require_once( ABSPATH . 'wp-admin/includes/image.php' );

	class NewMelodyArticle {
		/** @var string URL of Melody web service. */
		var string $url;

		/** @var array XML file of Melody web service. */
		var $xml;

		function __construct() {
			$this->url = 'https://melody.parresia.demainunautrejour.com/webservice/article/' . get_query_var('article_id');
			$this->xml = null;

			$response = wp_remote_head($this->url);
			if (
				!is_wp_error($response)
				&& (wp_remote_retrieve_response_code($response) === 200)
			) {
				$this->xml = simplexml_load_file($this->url);
			}

			$this->add_new_melody_post();
		}

		function replace_external_image_urls_with_local(string $content, array $reference_to_id_map): string {
			foreach ($reference_to_id_map as $reference => $attachment_id) {
				$local_url = wp_get_attachment_url($attachment_id);
				if ($local_url) {
					// Remplace toutes les occurrences de l'URL externe contenant la référence par l'URL locale
					$pattern = '/https?:\/\/[^"]*' . preg_quote($reference, '/') . '[^"]*/i';
					$content = preg_replace($pattern, $local_url, $content);
				}
			}
			return $content;
		}

		/**
		 * Main function that migrate post from Melody to Dentaire 365
		 */
		function add_new_melody_post() {
			$post_link = 'une erreur est survenue';

			// Mode import local : fichier XML sur disque
			if (file_exists($this->url)) {
				$this->xml = simplexml_load_file($this->url);
				if (!$this->xml) {
					$post_link = 'Erreur: Impossible de charger le XML local.';
					print $post_link;
					return;
				}
			}
			// Mode distant : URL HTTP/HTTPS
			else if (filter_var($this->url, FILTER_VALIDATE_URL)) {
				$response = wp_remote_head($this->url);
				if (
					!is_wp_error($response)
					&& (wp_remote_retrieve_response_code($response) === 200)
				) {
					$this->xml = simplexml_load_file($this->url);
				} else {
					$post_link = 'Erreur: Impossible de charger les données depuis Melody.';
					print $post_link;
					return;
				}
			} else {
				$post_link = 'Erreur: Chemin/URL non valide.';
				print $post_link;
				return;
			}

			// Récupère l'ID Melody du XML avant l'insertion du post
			$melody_id = isset($this->xml->id) ? (string)$this->xml->id : '';

			// Suite de la logique d’import inchangée
			$post_title = strip_tags((string) $this->xml->title);
			$author_name = (string) $this->xml->author;

			// Champs éditoriaux avancés du XML
			$sup_title         = isset($this->xml->supTitle)         ? trim(strip_tags((string)$this->xml->supTitle))         : '';
			$sub_title         = isset($this->xml->subTitle)         ? trim(strip_tags((string)$this->xml->subTitle))         : '';
			$secondary_title   = isset($this->xml->secondaryTitle)   ? trim(strip_tags((string)$this->xml->secondaryTitle))   : '';
			$exergue           = isset($this->xml->exergue)          ? trim(strip_tags((string)$this->xml->exergue))          : '';

			$query = new WP_Query([
				'post_type'      => 'post',
				'title'          => $post_title,
				'posts_per_page' => 1,
				'post_status'    => 'any',
			]);

			$my_post_id = $query->have_posts() ? $query->post : null;

			//get post author
			$author = $this->post_author( $author_name );

			//set post param
			$my_post = [
				'ID'            => $my_post_id ? $my_post_id->ID : 0,
				'post_title'    => $post_title,
				'post_excerpt'  => (string) $this->xml->abstract,
				'post_status'   => 'publish',
				'post_category' => $this->add_post_categories(),
				'post_author'   => $author['id'],
				'post_name'     => sanitize_title($post_title), // Use sanitize_title for slug
				'post_date'     => (string) $this->xml->webPublishDate,
			];
			// the post exists, then update it

			// add pictures to media library and get the mapping
			$media_data = $this->add_pictures_to_medialibrary();
			$reference_to_id_map = $media_data['reference_to_id_map'] ?? []; // Get the map

			//get post content from xml file
			$content = (string) $this->xml->completeObjectText; // Cast to string

			// Pattern to find input tags with background-image style
			$pattern = '/<input [^>]*style="[^"]*background-image: url\\([\'"]?(.*?HOT\/(.*?)\/128)[^\'"]*[\'"]?\\);[^"]*"[^>]*>/i';

			// Replace input tags with <figure><img ... alt="..."><figcaption>...</figcaption></figure>
			$content = preg_replace_callback($pattern, function ($matches) {
				$full_url = $matches[1];
				$reference = $matches[2]; // La valeur après HOT/ et avant /128

				$photo_url = '';
				$legend_raw = '';
				$credit_raw = '';

				if (!empty($reference) && isset($this->xml->photos->photo)) {
					error_log("Reference recherchée : $reference");
					error_log("Références disponibles : ");
					foreach ($this->xml->photos->photo as $photo) {
						error_log(" - " . (string)$photo->reference);
						if (isset($photo->WEB_HARDCROP_RESSOURCE)) {
							$photo_web_url = (string) $photo->WEB_HARDCROP_RESSOURCE;
							if (strpos($photo_web_url, $reference) !== false) {
								$photo_url = $photo_web_url;
								$legend_raw = '';
								if (isset($photo->legend_raw)) {
									$legend_raw_xml = $photo->legend_raw->asXML();
									if ($legend_raw_xml) {
										// Extrait le contenu interne à la balise <legend_raw>
										$legend_raw = preg_replace('/^<legend_raw>(.*)<\/legend_raw>$/s', '$1', $legend_raw_xml);
										error_log('legend_raw (inner) = [' . $legend_raw . ']');
									} else {
										error_log('legend_raw asXML est vide');
									}
								}
								$credit_raw = isset($photo->credit_raw) ? (string)$photo->credit_raw : '';
								break;
							}
						}
					}
				}

				if (!empty($photo_url)) {
					// Alt attribute: strip tags from legend_raw, then esc_attr
					$alt_text = esc_attr(strip_tags($legend_raw));
					// Extraction des dimensions du crop depuis WEB_HARDCROP_RESSOURCE si besoin
					$crop_w = 0;
					$crop_h = 0;

					// D'abord, tente avec la balise crop du XML
					if (isset($photo->crop)) {
					    $crop_w = isset($photo->crop->crop_w) ? (int)$photo->crop->crop_w : 0;
					    $crop_h = isset($photo->crop->crop_h) ? (int)$photo->crop->crop_h : 0;
					}

					// Si c'est vide ou zéro, essaye de parser WEB_HARDCROP_RESSOURCE
					if ((!$crop_w || !$crop_h) && !empty($photo_url)) {
					    if (preg_match('/crop=({.*?})/', $photo_url, $matches)) {
					        $crop_data = json_decode(urldecode($matches[1]), true);
					        if (isset($crop_data['crop_w']) && isset($crop_data['crop_h'])) {
					            $crop_w = (int)$crop_data['crop_w'];
					            $crop_h = (int)$crop_data['crop_h'];
					        }
					    }
					}

					// Ajout width/height si présents
					$img_attrs = '';
					if ($crop_w > 0 && $crop_h > 0) {
					    $img_attrs = ' width="' . $crop_w . '" height="' . $crop_h . '"';
					}

					$img_html = '<img src="' . esc_url($photo_url) . '" alt="' . $alt_text . '"' . $img_attrs . ' />';
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


			// Replace external image URLs with local ones
			$content = $this->replace_external_image_urls_with_local($content, $reference_to_id_map);

			// Récupération du champ note_raw
			$note_raw = '';
			if (isset($this->xml->note_raw)) {
				$note_raw_xml = $this->xml->note_raw->asXML();
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
			if (!empty($this->xml->children) && isset($this->xml->children->child)) {
				$children_html = '<div class="children_post">';
				$children_html .= '<h3>Suite du dossier</h3>';
				$children_html .= '<ul>';
				foreach ($this->xml->children->child as $child) {
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
			if ($exergue)         $header_html .= '<blockquote class="article-exergue">' . esc_html($exergue) . '</blockquote>';

			//AUTEUR DE L'ARTICLE --*

			// Récupération du champ author_raw
			$author_box_html = '';
			$author_raw = '';
			if (isset($this->xml->author_raw)) {
				$author_raw_xml = $this->xml->author_raw->asXML();
				if ($author_raw_xml) {
					$author_raw = preg_replace('/^<author_raw>(.*)<\/author_raw>$/s', '$1', $author_raw_xml);
				}
			} elseif (isset($this->xml->author)) {
				// Si pas de author_raw, fallback sur author
				$author_raw_xml = $this->xml->author->asXML();
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

			$my_post['post_content'] = $header_html . $content . $author_box_html;

			if ($sub_title) {
				// Si tu veux insérer le sous-titre en tout début du contenu (tu peux le placer ailleurs)
				$my_post['post_content'] = '<div class="article-subtitle">' . esc_html($sub_title) . '</div>' . $my_post['post_content'];
			}

			// add videos, if set, to content
			if ( isset( $this->xml->videos ) ) {
				$my_post['post_content'] = $this->add_post_videos( $my_post );
			}

			// update post if exists, add it if not
			$post_id = wp_insert_post( $my_post );

			if (!empty($melody_id) && !is_wp_error($post_id) && $post_id !== 0) {
				update_post_meta($post_id, 'melody_id', $melody_id);
			}

			// Stockage des Melody ID des enfants
			$melody_children_ids = [];
			if (!empty($this->xml->children) && isset($this->xml->children->child)) {
				foreach ($this->xml->children->child as $child) {
					if (isset($child->id)) {
						$melody_children_ids[] = (string)$child->id;
					}
				}
			}
			if (!empty($melody_children_ids) && !is_wp_error($post_id) && $post_id !== 0) {
				update_post_meta($post_id, 'melody_children', $melody_children_ids);
				error_log('DEBUG melody_children for post_id ' . $post_id . ' = ' . print_r($melody_children_ids, true));
			}

			// choose if we will check the default wp author field or override field
			if ( function_exists( 'update_field' ) ) {
				// if no user found, check override the author
				if ( $author['override'] ) {
					update_field( 'overload_author_choice', 'custom', $post_id );
					update_field( 'overload_author_text', $author_name, $post_id );
				} else {
					//check the default wp author field
					update_field( 'overload_author_choice', 'default', $post_id );
				}
			}
			// if no problem encountered
			if ( ! is_wp_error( $post_id ) && $post_id !== 0 ) {

				// add post tags
				$this->add_post_tags( $post_id );

				// add post thumbnail
				// Use the $media_data which contains 'upload_ids'
				if ( isset( $this->xml->photos ) && !empty($media_data['upload_ids']) ) {
					$this->add_post_thumbnails( $post_id, $media_data );
				} else {
					delete_post_thumbnail( $post_id );
				}

				// check subscription field type
				$this->add_post_subscription( $post_id );

				$post_link = get_the_permalink( $post_id );
			} else {
				// Handle error from wp_insert_post if needed
				if (is_wp_error($post_id)) {
					$post_link = 'Erreur lors de la création/mise à jour: ' . $post_id->get_error_message();
				}
			}

			print $post_link;
		}

		/**
		 * Search for author, if exists, set as post author,
		 * if not set 'La rédaction' as author and override author field
		 *
		 * @param string $xml_author_name
		 *
		 * @return array
		 */
		function post_author( string $xml_author_name ): array {
			$args = [
				'search'        => $xml_author_name,
				'search_fields' => [ 'user_login', 'display_name' ]
			];

            // Default author if not found or empty name
			$default_author_id = get_user_by( 'login', 'redac' ) ? get_user_by( 'login', 'redac' )->ID : 1; // Fallback to admin ID 1 if 'redac' not found

			$author_id = [
				'id'       => $default_author_id,
				'override' => true
			];

            // Only search if author name is not empty/whitespace
			if ( ! empty( trim( $xml_author_name ) ) ) {
				$user_query = new WP_User_Query( $args );
				$authors    = $user_query->get_results();
				if ( ! empty( $authors ) ) {
					$author_id = [
						'id'       => reset( $authors )->ID,
						'override' => false
					];
				}
            }

			return $author_id;
		}

		/**
		 * Add post tags
		 *
		 * @param int $post_id
		 */
		function add_post_tags( int $post_id ) {
			$tags = [];
			if ( ! empty( $this->xml->categories->category ) ) {
				foreach ( $this->xml->categories->category as $tag ) {
					if ( isset($tag->thesaurus->name) && (string) $tag->thesaurus->name === 'Thématique' && isset($tag->pathNames->item[0]) ) {
						array_push( $tags, (string) $tag->pathNames->item[0] );
					}
				}
			}
			// Use wp_set_post_tags for tags
            if (!empty($tags)) {
			    wp_set_post_tags( $post_id, $tags, false ); // false to replace existing tags
            } else {
                wp_set_post_tags( $post_id, [], false ); // Remove tags if none found
            }
		}

		/**
		 * Set post category if exists, create it if not
		 * @return array
		 */
		function add_post_categories(): array {
			$categories_ids = [];
			if ( ! empty( $this->xml->categories->category ) ) {
				foreach ( $this->xml->categories->category as $cat ) {
                    // Ensure all expected elements exist before accessing them
					if ( isset($cat->thesaurus->name) && (string) $cat->thesaurus->name === 'Rubriques Web' && isset($cat->pathNames->item[0], $cat->name) ) {
                        $cat_name = (string) $cat->name;
                        $parent_slug = isset($cat->pathNames->item[1]) ? (string) $cat->pathNames->item[1] : null; // Potential parent slug
                        $term_slug = (string) $cat->pathNames->item[0]; // Current term slug/name identifier in path

                        $parent_term_id = 0;
                        // If a parent slug is indicated in the XML path
                        if ($parent_slug) {
                             $parent = get_term_by( 'slug', $parent_slug, 'category' );
                             if ($parent) {
                                 $parent_term_id = $parent->term_id;
                             } else {
                                 // Parent slug provided but term doesn't exist, maybe create it?
                                 // For simplicity, let's assume parent needs to exist or term becomes top-level
                                 // Or create the parent:
                                 $new_parent = wp_insert_term($parent_slug, 'category'); // Might need a better name than slug
                                 if (!is_wp_error($new_parent)) $parent_term_id = $new_parent['term_id'];

                             }
                        }

                        // Now handle the actual category term ($cat_name, identified by $term_slug in path)
                        $term = get_term_by('name', $cat_name, 'category'); // Check if term with this name exists

                        // If term exists and has the correct parent (or no parent if $parent_term_id is 0)
                        if ($term && $term->parent == $parent_term_id) {
                             array_push( $categories_ids, $term->term_id );
                        } else {
                             // Term doesn't exist, or wrong parent. Try to insert/create it.
                             // Check by slug first maybe? Let's use wp_insert_term which handles existence check.
                             $new_term_data = wp_insert_term(
                                 $cat_name,        // The category name
                                 'category',       // Taxonomy
                                 [
                                     'parent' => $parent_term_id,
                                     'slug'   => $term_slug // Try using the slug from path
                                 ]
                             );

                             if ( ! is_wp_error( $new_term_data ) ) {
                                 array_push( $categories_ids, $new_term_data['term_id'] );
                             } elseif (isset($new_term_data->error_data['term_exists'])) {
                                 // Term already exists (maybe with different slug/parent?), use the existing ID
                                 array_push( $categories_ids, $new_term_data->error_data['term_exists'] );
                             }
                             // Handle other potential errors from wp_insert_term if needed
                        }
					}
				}
			}

            // Remove duplicates and return
			return array_unique($categories_ids);
		}

		/**
		 * Add pictures to media library, check pictures format
		 * @return array includes 'upload_ids', 'thumbnail_ids', and 'reference_to_id_map'
		 */
		function add_pictures_to_medialibrary(): array {
			$i                    = 1;
			$thumbnails_ids       = [];
			$uploads_ids          = [];
			$reference_to_id_map  = []; // <-- Map to store [reference => attachment_id]
			$wordpress_upload_dir = wp_upload_dir();

			if ( ! empty( $this->xml->photos->photo ) ) {
				foreach ( $this->xml->photos->photo as $picture ) {
                    // Ensure reference and URL exist and are strings
                    if ( !isset($picture->reference) || !isset($picture->WEB_HARDCROP_RESSOURCE) ) continue;

					$original_reference = (string) $picture->reference;
                    if (empty($original_reference)) continue; // Skip if reference is empty

					$pictures_url = (string) $picture->WEB_HARDCROP_RESSOURCE;
                    if (empty($pictures_url)) continue; // Skip if URL is empty

					// Determine file format
					$file_format = isset($picture->fileFormat) ? strtolower((string) $picture->fileFormat) : 'jpg';
					if ( ! in_array( $file_format, [ 'jpg', 'jpeg', 'png', 'gif', 'ico' ] ) ) {
						$file_format = 'jpg'; // Default to jpg if format is invalid
					}

                    // Create filename based on reference
					$base_filename = $original_reference . '.' . $file_format;
					$new_file_path = $wordpress_upload_dir['path'] . '/' . $base_filename;

                    // Handle potential filename conflicts by prepending a counter
                    $current_filename = $base_filename;
					while ( file_exists( $new_file_path ) ) {
						$i++;
                        $current_filename = $original_reference . '_' . $i . '.' . $file_format; // Append counter before extension
						$new_file_path    = $wordpress_upload_dir['path'] . '/' . $current_filename;
					}

                    // Copy the file from the external URL
					if ( copy( $pictures_url, $new_file_path ) ) {

						$new_file_mime = mime_content_type( $new_file_path );
						$attachment_title = $original_reference; // Use reference as title

                        // Check if an attachment with this title (reference) already exists
                        // Using get_page_by_title is simple but might not be robust if titles aren't unique.
                        // Consider using a meta field in the future if needed.
                        $existing_attachment = get_page_by_title($attachment_title, OBJECT, 'attachment');
                        $upload_id = ($existing_attachment) ? $existing_attachment->ID : 0;

                        // Prepare attachment data for insert/update
						$upload_media = [
								'ID'             => $upload_id, // 0 to insert, existing ID to update
								'guid'           => $wordpress_upload_dir['url'] . '/' . $current_filename, // Correct GUID uses URL path
								'post_mime_type' => $new_file_mime,
								'post_title'     => $attachment_title, // Title based on reference
								'post_excerpt'   => isset($picture->legend) ? (string) $picture->legend : '', // Caption (Legend)
                                'post_content'   => isset($picture->credit) ? (string) $picture->credit : '', // Description (Credit)
								'post_status'    => 'inherit'
							];

                        // Insert or update the attachment post
						$attachment_id = wp_insert_attachment( $upload_media, $new_file_path ); // Provide file path for metadata generation

                        if ( ! is_wp_error( $attachment_id ) && $attachment_id !== 0 ) {
							// Generate metadata (thumbnails, etc.) Requires 'wp-admin/includes/image.php'
                            require_once(ABSPATH . 'wp-admin/includes/image.php');
							$attach_data = wp_generate_attachment_metadata( $attachment_id, $new_file_path );
							wp_update_attachment_metadata( $attachment_id, $attach_data );

                            array_push( $uploads_ids, $attachment_id );
							$reference_to_id_map[$original_reference] = $attachment_id; // <-- Store the mapping

                            // $thumbnail_id isn't really needed separately here, metadata handles it.
                            // array_push( $thumbnails_ids, $attach_data ); // Storing full metadata might be large

						} else {
                            // Handle attachment insertion error (e.g., log it)
                            // Optionally remove the copied file if insertion failed
                            @unlink($new_file_path);
                        }
					} else {
                         // Handle file copy error (e.g., log it)
                    }
				}
			}

			// Return all collected data
			return [
                'thumbnail_ids'       => $thumbnails_ids, // This might be less useful now
                'upload_ids'          => $uploads_ids,
                'reference_to_id_map' => $reference_to_id_map // <-- Return the important map
            ];
		}


		/**
		 * Set the post thumbnail image based on webForward or fallback to first image.
		 *
		 * @param int   $post_id
		 * @param array $media_data Contains 'reference_to_id_map', 'upload_ids'
		 */
		function add_post_thumbnails(int $post_id, array $media_data) {
		    // Si la structure $media_data permet de faire la correspondance entre $reference et $attachment_id
		    if (!empty($media_data['reference_to_id_map']) && isset($this->xml->photos->photo)) {
		        $attachment_id = null;

		        // 1. Cherche la photo "webForward"
		        foreach ($this->xml->photos->photo as $photo) {
		            if (isset($photo->webForward) && (string)$photo->webForward === '1') {
		                $reference = (string)$photo->reference;
		                if (isset($media_data['reference_to_id_map'][$reference])) {
		                    $attachment_id = $media_data['reference_to_id_map'][$reference];
		                    break;
		                }
		            }
		        }

		        // 2. Si pas de webForward, prend la première image ancrée (déjà dockée)
		        if (!$attachment_id) {
		            if (!empty($media_data['upload_ids'])) {
		                $attachment_id = reset($media_data['upload_ids']); // Première image
		            }
		        }

		        // Applique la thumbnail ou la supprime si aucune valide
		        if ($attachment_id && is_numeric($attachment_id) && $attachment_id > 0) {
		            set_post_thumbnail($post_id, $attachment_id);
		        } else {
		            delete_post_thumbnail($post_id);
		        }
		    } else {
		        // Cas de secours, ancienne logique (première image si rien d'autre)
		        if (!empty($media_data['upload_ids'])) {
		            $first_image_id = reset($media_data['upload_ids']);
		            if (is_numeric($first_image_id) && $first_image_id > 0) {
		                set_post_thumbnail($post_id, $first_image_id);
		            }
		        } else {
		            delete_post_thumbnail($post_id);
		        }
		    }
		}

		/**
		 * Add video to content
		 *
		 * @param array $my_post
		 *
		 * @return string
		 */
		function add_post_videos( array $my_post ): string {
            $video_html = '';
			if (isset($this->xml->videos->video)) {
                foreach ( $this->xml->videos->video as $video ) {
                    if (isset($video->url)) {
                        $video_url = esc_url((string) $video->url); // Sanitize URL
                        $video_description = isset($video->description) ? wp_kses_post((string) $video->description) : ''; // Sanitize description

                        // Basic check for YouTube/Vimeo for potential responsive embeds, otherwise use simple iframe
                        // This could be enhanced
                        $video_html .= "<figure class='wp-block-embed is-type-video'>"; // Wrap in figure for better WP styling
                        $video_html .= "<div class='wp-block-embed__wrapper'>";
                        $video_html .= "<iframe" . " width='640' height='360' src='" . $video_url
                            . "' frameborder='0' allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture' allowfullscreen></iframe>";
                        $video_html .= "</div>";
                        if (!empty($video_description)) {
                            $video_html .= "<figcaption>" . $video_description . "</figcaption>";
                        }
                        $video_html .= "</figure>";
                    }
                }
            }

            // Append video HTML to the existing content
			return $my_post['post_content'] . $video_html;
		}

		/**
		 * Add post subscription type using ACF function if available
		 *
		 * @param int $post_id
		 */
		function add_post_subscription( int $post_id ) {
			if ( function_exists( 'update_field' ) && isset($this->xml->webFree) ) {
				$field_key = 'mu_subscribe_or_not';
				$webFree = (string) $this->xml->webFree;
				$connectedUserAccessOnly = isset($this->xml->connectedUserAccessOnly) ? (string)$this->xml->connectedUserAccessOnly : '0';

				// Nouvelle logique
				if ($webFree === '0' && $connectedUserAccessOnly === '0') {
					$value = 'scd';
				} elseif (
					($webFree === '0' && $connectedUserAccessOnly === '1') ||
					($webFree === '1' && $connectedUserAccessOnly === '1')
				) {
					$value = 'registered';
				} else {
					$value = 'free';
				}

				update_field( $field_key, $value, $post_id );
			}
		}
	}

	// Instantiate only if the class exists (prevents errors if file is included incorrectly)
	if ( class_exists( 'NewMelodyArticle' ) ) {
		new NewMelodyArticle();
	}
