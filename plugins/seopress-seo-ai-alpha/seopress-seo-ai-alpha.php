<?php
/**
 * Plugin Name: Multilingual SEO AI for SEOPress - Alpha
 * Description: Admin tool to generate multilingual SEO metadata and article optimization suggestions with OpenAI or Claude, then apply metadata to SEOPress fields.
 * Version: 0.1.2-alpha
 * Author: Codex
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VSSPA_Plugin {
	const VERSION = '0.1.2-alpha';
	const OPTION = 'vsspa_settings';
	const NONCE = 'vsspa_admin';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_editor_meta_box' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
		add_filter( 'the_content', array( $this, 'append_reading_slider_to_content' ) );
		add_action( 'wp_ajax_vsspa_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_vsspa_apply', array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_vsspa_rewrite_content', array( $this, 'ajax_rewrite_content' ) );
	}

	public function admin_menu() {
		add_management_page(
			'SEO Meta AI SEOPress',
			'SEO Meta AI SEOPress',
			'manage_options',
			'vsspa',
			array( $this, 'render_admin_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'vsspa_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->default_settings(),
			)
		);
	}

	public function enqueue_admin_assets( $hook ) {
		$should_enqueue = 'tools_page_vsspa' === $hook;
		if ( ! $should_enqueue && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$settings = $this->settings();
			if ( $screen && ! empty( $screen->post_type ) && in_array( $screen->post_type, $settings['post_types'], true ) ) {
				$should_enqueue = true;
			}
		}

		if ( ! $should_enqueue ) {
			return;
		}

		wp_enqueue_style(
			'vsspa-admin',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'vsspa-admin',
			plugins_url( 'assets/admin.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);
		wp_localize_script(
			'vsspa-admin',
			'VSSPA',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'generating'   => 'Generation en cours...',
					'rewriting'    => 'Proposition de contenu en cours...',
					'applying'     => 'Ecriture dans SEOPress...',
					'done'         => 'Termine',
					'error'        => 'Erreur',
					'confirmBatch' => 'Appliquer toutes les suggestions visibles dans SEOPress ?',
					'confirmContent' => 'Remplacer le contenu dans l editeur par la proposition optimisee ? Tu pourras encore relire avant de cliquer sur Mettre a jour.',
				),
			)
		);
	}

	public function add_editor_meta_box( $post_type, $post ) {
		if ( ! current_user_can( 'manage_options' ) || ! $post instanceof WP_Post || 'auto-draft' === $post->post_status ) {
			return;
		}

		$settings = $this->settings();
		if ( ! in_array( $post_type, $settings['post_types'], true ) ) {
			return;
		}

		add_meta_box(
			'vsspa-editor-box',
			'SEO Meta AI - SEOPress',
			array( $this, 'render_editor_meta_box' ),
			$post_type,
			'normal',
			'high'
		);
	}

	private function default_settings() {
		return array(
			'provider'          => 'openai',
			'openai_api_key'    => '',
			'openai_model'      => 'gpt-5-mini',
			'anthropic_api_key' => '',
			'anthropic_model'   => 'claude-sonnet-4-6',
			'language_mode'     => 'site',
			'manual_language'   => '',
			'post_types'        => array( 'post', 'page' ),
			'max_posts'         => 20,
			'reading_candidates' => 12,
			'auto_append_slider' => 1,
			'max_content_chars' => 9000,
		);
	}

	private function settings() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, $this->default_settings() );
		if ( empty( $settings['openai_api_key'] ) && ! empty( $saved['api_key'] ) ) {
			$settings['openai_api_key'] = $saved['api_key'];
		}
		if ( empty( $settings['openai_model'] ) && ! empty( $saved['model'] ) ) {
			$settings['openai_model'] = $saved['model'];
		}
		if ( empty( $settings['language_mode'] ) || 'auto' === $settings['language_mode'] ) {
			$settings['language_mode'] = 'site';
		}

		return $settings;
	}

	public function sanitize_settings( $input ) {
		$current = $this->settings();
		$input   = is_array( $input ) ? $input : array();

		$public_post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types        = array();
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $post_type ) {
				$post_type = sanitize_key( $post_type );
				if ( in_array( $post_type, $public_post_types, true ) ) {
					$post_types[] = $post_type;
				}
			}
		}

		$provider = isset( $input['provider'] ) ? sanitize_key( $input['provider'] ) : 'openai';
		if ( ! in_array( $provider, array( 'openai', 'anthropic' ), true ) ) {
			$provider = 'openai';
		}

		$openai_api_key = isset( $input['openai_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $input['openai_api_key'] ) ) ) : '';
		if ( '' === $openai_api_key && ! empty( $current['openai_api_key'] ) ) {
			$openai_api_key = $current['openai_api_key'];
		}

		$anthropic_api_key = isset( $input['anthropic_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $input['anthropic_api_key'] ) ) ) : '';
		if ( '' === $anthropic_api_key && ! empty( $current['anthropic_api_key'] ) ) {
			$anthropic_api_key = $current['anthropic_api_key'];
		}

		$language_mode = isset( $input['language_mode'] ) ? sanitize_key( $input['language_mode'] ) : 'site';
		if ( 'auto' === $language_mode ) {
			$language_mode = 'site';
		}
		if ( ! in_array( $language_mode, array( 'site', 'manual' ), true ) ) {
			$language_mode = 'site';
		}

		return array(
			'provider'          => $provider,
			'openai_api_key'    => $openai_api_key,
			'openai_model'      => isset( $input['openai_model'] ) ? sanitize_text_field( wp_unslash( $input['openai_model'] ) ) : 'gpt-5-mini',
			'anthropic_api_key' => $anthropic_api_key,
			'anthropic_model'   => isset( $input['anthropic_model'] ) ? sanitize_text_field( wp_unslash( $input['anthropic_model'] ) ) : 'claude-sonnet-4-6',
			'language_mode'     => $language_mode,
			'manual_language'   => isset( $input['manual_language'] ) ? sanitize_text_field( wp_unslash( $input['manual_language'] ) ) : '',
			'post_types'        => $post_types ? array_values( array_unique( $post_types ) ) : array( 'post' ),
			'max_posts'         => isset( $input['max_posts'] ) ? max( 1, min( 100, absint( $input['max_posts'] ) ) ) : 20,
			'reading_candidates' => isset( $input['reading_candidates'] ) ? max( 3, min( 30, absint( $input['reading_candidates'] ) ) ) : 12,
			'auto_append_slider' => ! empty( $input['auto_append_slider'] ) ? 1 : 0,
			'max_content_chars' => isset( $input['max_content_chars'] ) ? max( 1000, min( 30000, absint( $input['max_content_chars'] ) ) ) : 9000,
		);
	}

	public function register_blocks() {
		wp_register_script(
			'vsspa-blocks',
			plugins_url( 'assets/blocks.js', __FILE__ ),
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor' ),
			self::VERSION,
			true
		);
		wp_register_style(
			'vsspa-frontend',
			plugins_url( 'assets/frontend.css', __FILE__ ),
			array(),
			self::VERSION
		);
		wp_register_script(
			'vsspa-frontend',
			plugins_url( 'assets/frontend.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);

		register_block_type(
			'vsspa/reading-slider',
			array(
				'editor_script'   => 'vsspa-blocks',
				'style'           => 'vsspa-frontend',
				'script'          => 'vsspa-frontend',
				'render_callback' => array( $this, 'render_reading_slider_block' ),
			)
		);
		register_block_type(
			'vsspa/reading-popup',
			array(
				'editor_script'   => 'vsspa-blocks',
				'style'           => 'vsspa-frontend',
				'script'          => 'vsspa-frontend',
				'render_callback' => array( $this, 'render_reading_popup_block' ),
				'attributes'      => array(
					'buttonText' => array(
						'type'    => 'string',
						'default' => 'Continuer la lecture',
					),
					'title'      => array(
						'type'    => 'string',
						'default' => 'A lire aussi',
					),
				),
			)
		);
	}

	public function enqueue_front_assets() {
		if ( is_singular() ) {
			wp_enqueue_style( 'vsspa-frontend' );
			wp_enqueue_script( 'vsspa-frontend' );
		}
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.' ) );
		}

		$settings          = $this->settings();
		$public_post_types = get_post_types( array( 'public' => true ), 'objects' );
		$posts             = $this->get_admin_posts( $settings );
		$front_page_id     = (int) get_option( 'page_on_front' );
		$is_static_front   = 'page' === get_option( 'show_on_front' ) && $front_page_id > 0;
		$seopress_active   = $this->is_seopress_active();
		$provider          = $this->selected_provider( $settings );
		$provider_label    = $this->provider_label( $provider );
		$site_language     = $this->site_language_context();
		?>
		<div class="wrap vsspa-wrap">
			<h1>SEO Meta AI - SEOPress Alpha</h1>

			<?php if ( ! $seopress_active ) : ?>
				<div class="notice notice-warning">
					<p>SEOPress ne semble pas actif. Le plugin peut enregistrer les champs meta, mais SEOPress doit etre actif pour les utiliser dans le rendu SEO.</p>
				</div>
			<?php endif; ?>

			<div class="vsspa-grid">
				<section class="vsspa-panel">
					<h2>Reglages</h2>
					<form method="post" action="options.php">
						<?php settings_fields( 'vsspa_settings' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="vsspa-provider">Fournisseur</label></th>
								<td>
									<select id="vsspa-provider" name="<?php echo esc_attr( self::OPTION ); ?>[provider]">
										<option value="openai" <?php selected( $settings['provider'], 'openai' ); ?>>OpenAI</option>
										<option value="anthropic" <?php selected( $settings['provider'], 'anthropic' ); ?>>Claude / Anthropic</option>
									</select>
									<p class="description">Fournisseur actif apres enregistrement : <strong><?php echo esc_html( $provider_label ); ?></strong>.</p>
								</td>
							</tr>
							<tr class="vsspa-provider-row vsspa-provider-openai-row">
								<th scope="row"><label for="vsspa-openai-api-key">Cle OpenAI</label></th>
								<td>
									<input id="vsspa-openai-api-key" type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[openai_api_key]" value="" placeholder="<?php echo esc_attr( empty( $settings['openai_api_key'] ) ? 'sk-...' : 'Cle deja enregistree' ); ?>">
									<p class="description">Laisser vide conserve la cle existante.</p>
								</td>
							</tr>
							<tr class="vsspa-provider-row vsspa-provider-openai-row">
								<th scope="row"><label for="vsspa-openai-model">Modele OpenAI</label></th>
								<td>
									<input id="vsspa-openai-model" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[openai_model]" value="<?php echo esc_attr( $settings['openai_model'] ); ?>">
								</td>
							</tr>
							<tr class="vsspa-provider-row vsspa-provider-anthropic-row">
								<th scope="row"><label for="vsspa-anthropic-api-key">Cle Anthropic</label></th>
								<td>
									<input id="vsspa-anthropic-api-key" type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[anthropic_api_key]" value="" placeholder="<?php echo esc_attr( empty( $settings['anthropic_api_key'] ) ? 'sk-ant-...' : 'Cle deja enregistree' ); ?>">
									<p class="description">Utilisee quand le fournisseur choisi est Claude / Anthropic.</p>
								</td>
							</tr>
							<tr class="vsspa-provider-row vsspa-provider-anthropic-row">
								<th scope="row"><label for="vsspa-anthropic-model">Modele Claude</label></th>
								<td>
									<input id="vsspa-anthropic-model" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[anthropic_model]" value="<?php echo esc_attr( $settings['anthropic_model'] ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="vsspa-language-mode">Langue principale des metas</label></th>
								<td>
									<select id="vsspa-language-mode" name="<?php echo esc_attr( self::OPTION ); ?>[language_mode]">
										<option value="site" <?php selected( $settings['language_mode'], 'site' ); ?>>Detecter depuis la langue principale du site</option>
										<option value="manual" <?php selected( $settings['language_mode'], 'manual' ); ?>>Forcer une langue manuelle</option>
									</select>
									<p class="description">Langue principale detectee : <strong><?php echo esc_html( $site_language['label'] ); ?></strong> (<code><?php echo esc_html( $site_language['locale'] ); ?></code>). Utilisee pour tous les contenus.</p>
								</td>
							</tr>
							<tr class="vsspa-language-manual-row">
								<th scope="row"><label for="vsspa-manual-language">Langue manuelle</label></th>
								<td>
									<input id="vsspa-manual-language" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[manual_language]" value="<?php echo esc_attr( $settings['manual_language'] ); ?>" placeholder="ex: French, Vietnamese, English, fr-FR, vi-VN">
									<p class="description">A utiliser seulement si la langue principale detectee par WordPress n'est pas celle que tu veux.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Types de contenus</th>
								<td>
									<?php foreach ( $public_post_types as $post_type => $object ) : ?>
										<label class="vsspa-check">
											<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $settings['post_types'], true ) ); ?>>
											<?php echo esc_html( $object->labels->name ); ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="vsspa-max-posts">Articles a afficher</label></th>
								<td>
									<input id="vsspa-max-posts" type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION ); ?>[max_posts]" value="<?php echo esc_attr( $settings['max_posts'] ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="vsspa-reading-candidates">Candidats poursuite de lecture</label></th>
								<td>
									<input id="vsspa-reading-candidates" type="number" min="3" max="30" name="<?php echo esc_attr( self::OPTION ); ?>[reading_candidates]" value="<?php echo esc_attr( $settings['reading_candidates'] ); ?>">
									<p class="description">Nombre de contenus existants envoyes au LLM pour choisir les lectures recommandees.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Affichage front</th>
								<td>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto_append_slider]" value="1" <?php checked( ! empty( $settings['auto_append_slider'] ) ); ?>>
										Afficher automatiquement le slider de poursuite de lecture en bas des articles
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="vsspa-max-content">Caracteres envoyes au LLM</label></th>
								<td>
									<input id="vsspa-max-content" type="number" min="1000" max="30000" step="500" name="<?php echo esc_attr( self::OPTION ); ?>[max_content_chars]" value="<?php echo esc_attr( $settings['max_content_chars'] ); ?>">
								</td>
							</tr>
						</table>
						<?php submit_button( 'Enregistrer les reglages' ); ?>
					</form>
				</section>

				<section class="vsspa-panel">
					<h2>Mode alpha</h2>
					<p>Cette version genere les suggestions dans l'admin, puis ecrit dans SEOPress seulement quand tu cliques sur appliquer.</p>
					<ul>
						<li>SEO title : <code>_seopress_titles_title</code></li>
						<li>Meta description : <code>_seopress_titles_desc</code></li>
						<li>Mots-cles cibles : <code>_seopress_analysis_target_kw</code></li>
						<li>Open Graph : <code>_seopress_social_fb_title</code> et <code>_seopress_social_fb_desc</code></li>
						<li>X/Twitter : <code>_seopress_social_twitter_title</code> et <code>_seopress_social_twitter_desc</code></li>
						<li>Conseils article : <code>_vsspa_article_optimization_suggestions</code></li>
						<li>Poursuite de lecture : <code>_vsspa_reading_recommendations</code></li>
					</ul>
				</section>
			</div>

			<div class="vsspa-actions">
				<span class="vsspa-active-provider">Generation avec : <strong><?php echo esc_html( $provider_label ); ?></strong></span>
				<button type="button" class="button button-primary" id="vsspa-batch-generate">Generer les suggestions visibles</button>
				<button type="button" class="button" id="vsspa-batch-apply">Appliquer les suggestions generees</button>
			</div>

			<?php if ( $is_static_front ) : ?>
				<h2>Page d'accueil</h2>
				<table class="widefat striped vsspa-table">
					<thead>
						<tr>
							<th>Contenu</th>
							<th>SEOPress actuel</th>
							<th>Suggestion</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php $this->render_post_row( get_post( $front_page_id ), 'Accueil', $settings ); ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="notice notice-info">
					<p>La page d'accueil WordPress n'est pas une page statique. Pour l'alpha, l'optimisation automatique de l'accueil fonctionne si l'accueil est une page WordPress.</p>
				</div>
			<?php endif; ?>

			<h2>Articles et pages</h2>
			<table class="widefat striped vsspa-table">
				<thead>
					<tr>
						<th>Contenu</th>
						<th>SEOPress actuel</th>
						<th>Suggestion</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $posts as $post ) {
						if ( $is_static_front && $front_page_id === (int) $post->ID ) {
							continue;
						}
						$this->render_post_row( $post, '', $settings );
					}
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function append_reading_slider_to_content( $content ) {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$settings = $this->settings();
		if ( empty( $settings['auto_append_slider'] ) ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $settings['post_types'], true ) ) {
			return $content;
		}

		if ( function_exists( 'has_block' ) && has_block( 'vsspa/reading-slider', $post ) ) {
			return $content;
		}

		$slider = $this->render_reading_recommendations( (int) $post->ID, 'auto' );
		return $slider ? $content . $slider : $content;
	}

	public function render_reading_slider_block( $attributes = array(), $content = '' ) {
		$post_id = get_the_ID();
		return $post_id ? $this->render_reading_recommendations( (int) $post_id, 'block' ) : '';
	}

	public function render_reading_popup_block( $attributes = array(), $content = '' ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$slider = $this->render_reading_recommendations( (int) $post_id, 'popup' );
		if ( ! $slider ) {
			return '';
		}

		$button_text = isset( $attributes['buttonText'] ) ? sanitize_text_field( $attributes['buttonText'] ) : 'Continuer la lecture';
		$title       = isset( $attributes['title'] ) ? sanitize_text_field( $attributes['title'] ) : 'A lire aussi';
		$popup_id    = 'vsspa-popup-' . (int) $post_id . '-' . wp_rand( 1000, 9999 );

		return sprintf(
			'<div class="vsspa-reading-popup"><button type="button" class="vsspa-popup-open" data-vsspa-popup-target="%1$s">%2$s</button><div id="%1$s" class="vsspa-popup" hidden><div class="vsspa-popup__overlay" data-vsspa-popup-close></div><div class="vsspa-popup__dialog" role="dialog" aria-modal="true" aria-label="%3$s"><button type="button" class="vsspa-popup__close" data-vsspa-popup-close aria-label="Fermer">x</button><h2>%3$s</h2>%4$s</div></div></div>',
			esc_attr( $popup_id ),
			esc_html( $button_text ),
			esc_html( $title ),
			$slider
		);
	}

	private function render_reading_recommendations( $post_id, $context ) {
		$json = get_post_meta( $post_id, '_vsspa_reading_recommendations_json', true );
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || ! $data ) {
			return '';
		}

		$items = $this->sanitize_reading_recommendations( $data );
		if ( ! $items ) {
			return '';
		}

		$slides = array();
		foreach ( $items as $item ) {
			$related = get_post( $item['post_id'] );
			if ( ! $related instanceof WP_Post || 'publish' !== $related->post_status ) {
				continue;
			}

			$theme = $related->post_type;
			if ( 'post' === $related->post_type ) {
				$categories = get_the_category( $related->ID );
				if ( $categories ) {
					$theme = $categories[0]->name;
				}
			}

			$excerpt = get_the_excerpt( $related );
			if ( ! $excerpt ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( $related->post_content ), 24 );
			}

			$slides[] = sprintf(
				'<article class="vsspa-reading-slide"><a href="%1$s"><span class="vsspa-reading-theme">%2$s</span><h3>%3$s</h3><p>%4$s</p><span class="vsspa-reading-anchor">%5$s</span></a></article>',
				esc_url( get_permalink( $related->ID ) ),
				esc_html( $theme ),
				esc_html( get_the_title( $related->ID ) ),
				esc_html( $item['reason'] ? $item['reason'] : $excerpt ),
				esc_html( $item['anchor_text'] ? $item['anchor_text'] : 'Lire la suite' )
			);
		}

		if ( ! $slides ) {
			return '';
		}

		$class = 'vsspa-reading-slider vsspa-reading-slider--' . sanitize_html_class( $context );
		return '<section class="' . esc_attr( $class ) . '" aria-label="Poursuivre la lecture"><div class="vsspa-reading-head"><h2>Poursuivre la lecture</h2><div class="vsspa-reading-controls"><button type="button" class="vsspa-slider-prev" aria-label="Article precedent">&lt;</button><button type="button" class="vsspa-slider-next" aria-label="Article suivant">&gt;</button></div></div><div class="vsspa-reading-track">' . implode( '', $slides ) . '</div></section>';
	}

	private function get_admin_posts( $settings ) {
		return get_posts(
			array(
				'post_type'      => $settings['post_types'],
				'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
				'posts_per_page' => (int) $settings['max_posts'],
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
	}

	public function render_editor_meta_box( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$post_id      = (int) $post->ID;
		$settings     = $this->settings();
		$seopress     = $this->get_seopress_meta( $post_id );
		$target_parts = $this->split_target_keywords( $seopress['target_keywords'] );
		$language     = $this->detect_post_language_context( $post_id, $settings );
		$has_values   = $seopress['title'] && $seopress['description'];
		?>
		<div class="vsspa-editor-box vsspa-row" data-post-id="<?php echo esc_attr( $post_id ); ?>"<?php echo $has_values ? ' data-has-suggestion="1"' : ''; ?><?php echo $seopress['optimized_content'] ? ' data-has-content-proposal="1"' : ''; ?>>
			<p class="vsspa-editor-note">Les recommandations apparaissent ici pour aider l'edition. Enregistre l'article avant generation pour analyser la derniere version sauvegardee. Le contenu de l'article n'est pas modifie automatiquement.</p>
			<div class="vsspa-meta-line">
				<span class="vsspa-badge">Langue principale : <?php echo esc_html( $language['label'] ); ?></span>
			</div>
			<div class="vsspa-editor-grid">
				<div>
					<h4>Metas SEOPress</h4>
					<label>SEO title</label>
					<input type="text" class="widefat vsspa-field vsspa-title" maxlength="90" value="<?php echo esc_attr( $seopress['title'] ); ?>">
					<label>Meta description</label>
					<textarea class="widefat vsspa-field vsspa-description" rows="3"><?php echo esc_textarea( $seopress['description'] ); ?></textarea>
					<label>Mot-cle principal</label>
					<input type="text" class="widefat vsspa-field vsspa-focuskw" value="<?php echo esc_attr( $target_parts['focus'] ); ?>">
					<label>Mots-cles secondaires</label>
					<input type="text" class="widefat vsspa-field vsspa-secondary" value="<?php echo esc_attr( $target_parts['secondary'] ); ?>" readonly>
					<label>Open Graph title</label>
					<input type="text" class="widefat vsspa-field vsspa-og-title" value="<?php echo esc_attr( $seopress['og_title'] ? $seopress['og_title'] : $seopress['title'] ); ?>">
					<label>Open Graph description</label>
					<textarea class="widefat vsspa-field vsspa-og-description" rows="2"><?php echo esc_textarea( $seopress['og_description'] ? $seopress['og_description'] : $seopress['description'] ); ?></textarea>
				</div>
				<div>
					<h4>Optimisation de l'article</h4>
					<label>Suggestions d'optimisation article</label>
					<textarea class="widefat vsspa-field vsspa-article-suggestions" rows="10" readonly><?php echo esc_textarea( $seopress['article_optimization'] ); ?></textarea>
					<label>Poursuite de lecture</label>
					<textarea class="widefat vsspa-field vsspa-reading-suggestions" rows="8" readonly><?php echo esc_textarea( $seopress['reading_recommendations'] ); ?></textarea>
					<input type="hidden" class="vsspa-reading-json" value="<?php echo esc_attr( $seopress['reading_recommendations_json'] ); ?>">
					<label>Synthese des changements proposes</label>
					<textarea class="widefat vsspa-field vsspa-content-summary" rows="4" readonly><?php echo esc_textarea( $seopress['optimized_content_summary'] ); ?></textarea>
					<label>Proposition de contenu optimise SEO</label>
					<textarea class="widefat vsspa-field vsspa-optimized-content" rows="14"><?php echo esc_textarea( $seopress['optimized_content'] ); ?></textarea>
				</div>
			</div>
			<div class="vsspa-editor-actions">
				<button type="button" class="button button-primary vsspa-generate">Generer pour cet article</button>
				<button type="button" class="button vsspa-apply"<?php disabled( ! $has_values ); ?>>Appliquer a SEOPress</button>
				<button type="button" class="button vsspa-rewrite-content">Proposer une version optimisee</button>
				<button type="button" class="button vsspa-apply-content"<?php disabled( ! $seopress['optimized_content'] ); ?>>Transformer le texte dans l'editeur</button>
				<span class="vsspa-status" aria-live="polite"></span>
				<span class="vsspa-quality"></span>
			</div>
		</div>
		<?php
	}

	private function render_post_row( $post, $badge, $settings ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$post_id    = (int) $post->ID;
		$seopress   = $this->get_seopress_meta( $post_id );
		$language   = $this->detect_post_language_context( $post_id, $settings );
		$edit_link  = get_edit_post_link( $post_id, '' );
		$permalink  = get_permalink( $post_id );
		$post_title = get_the_title( $post_id );
		?>
		<tr class="vsspa-row" data-post-id="<?php echo esc_attr( $post_id ); ?>">
			<td class="vsspa-content-cell">
				<strong>
					<?php if ( $edit_link ) : ?>
						<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $post_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $post_title ); ?>
					<?php endif; ?>
				</strong>
				<div class="vsspa-meta-line">
					#<?php echo esc_html( $post_id ); ?> · <?php echo esc_html( $post->post_type ); ?> · <?php echo esc_html( $post->post_status ); ?>
					<?php if ( $badge ) : ?>
						<span class="vsspa-badge"><?php echo esc_html( $badge ); ?></span>
					<?php endif; ?>
					<span class="vsspa-badge">Langue principale : <?php echo esc_html( $language['label'] ); ?></span>
				</div>
				<?php if ( $permalink ) : ?>
					<a class="vsspa-url" href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $permalink ); ?></a>
				<?php endif; ?>
			</td>
			<td>
				<label>Title</label>
				<div class="vsspa-current"><?php echo esc_html( $seopress['title'] ? $seopress['title'] : '(vide)' ); ?></div>
				<label>Description</label>
				<div class="vsspa-current"><?php echo esc_html( $seopress['description'] ? $seopress['description'] : '(vide)' ); ?></div>
				<label>Mots-cles cibles</label>
				<div class="vsspa-current"><?php echo esc_html( $seopress['target_keywords'] ? $seopress['target_keywords'] : '(vide)' ); ?></div>
			</td>
			<td class="vsspa-suggestion-cell">
				<label>SEO title</label>
				<input type="text" class="widefat vsspa-field vsspa-title" maxlength="90">
				<label>Meta description</label>
				<textarea class="widefat vsspa-field vsspa-description" rows="3"></textarea>
				<label>Mot-cle principal</label>
				<input type="text" class="widefat vsspa-field vsspa-focuskw">
				<label>Mots-cles secondaires</label>
				<input type="text" class="widefat vsspa-field vsspa-secondary" readonly>
				<label>Open Graph title</label>
				<input type="text" class="widefat vsspa-field vsspa-og-title">
				<label>Open Graph description</label>
				<textarea class="widefat vsspa-field vsspa-og-description" rows="2"></textarea>
				<label>Suggestions d'optimisation article</label>
				<textarea class="widefat vsspa-field vsspa-article-suggestions" rows="8" readonly></textarea>
				<label>Poursuite de lecture</label>
				<textarea class="widefat vsspa-field vsspa-reading-suggestions" rows="7" readonly></textarea>
				<input type="hidden" class="vsspa-reading-json" value="">
				<div class="vsspa-quality"></div>
			</td>
			<td class="vsspa-button-cell">
				<button type="button" class="button button-primary vsspa-generate">Generer</button>
				<button type="button" class="button vsspa-apply" disabled>Appliquer a SEOPress</button>
				<div class="vsspa-status" aria-live="polite"></div>
			</td>
		</tr>
		<?php
	}

	private function split_target_keywords( $target_keywords ) {
		$keywords = array();
		foreach ( explode( ',', (string) $target_keywords ) as $keyword ) {
			$keyword = $this->compact_spaces( $keyword );
			if ( '' !== $keyword ) {
				$keywords[] = $keyword;
			}
		}

		$focus     = $keywords ? array_shift( $keywords ) : '';
		$secondary = implode( ', ', $keywords );

		return array(
			'focus'     => $focus,
			'secondary' => $secondary,
		);
	}

	private function get_seopress_meta( $post_id ) {
		return array(
			'title'                  => (string) get_post_meta( $post_id, '_seopress_titles_title', true ),
			'description'            => (string) get_post_meta( $post_id, '_seopress_titles_desc', true ),
			'target_keywords'        => (string) get_post_meta( $post_id, '_seopress_analysis_target_kw', true ),
			'og_title'               => (string) get_post_meta( $post_id, '_seopress_social_fb_title', true ),
			'og_description'         => (string) get_post_meta( $post_id, '_seopress_social_fb_desc', true ),
			'twitter_title'          => (string) get_post_meta( $post_id, '_seopress_social_twitter_title', true ),
			'twitter_description'    => (string) get_post_meta( $post_id, '_seopress_social_twitter_desc', true ),
			'article_optimization'   => (string) get_post_meta( $post_id, '_vsspa_article_optimization_suggestions', true ),
			'reading_recommendations' => (string) get_post_meta( $post_id, '_vsspa_reading_recommendations', true ),
			'reading_recommendations_json' => (string) get_post_meta( $post_id, '_vsspa_reading_recommendations_json', true ),
			'optimized_content'      => (string) get_post_meta( $post_id, '_vsspa_optimized_content_proposal', true ),
			'optimized_content_summary' => (string) get_post_meta( $post_id, '_vsspa_optimized_content_summary', true ),
		);
	}

	public function ajax_generate() {
		$this->assert_ajax_access();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post introuvable.' ), 404 );
		}

		$settings = $this->settings();
		$provider = $this->selected_provider( $settings );
		$api_key  = $this->provider_api_key( $settings, $provider );
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'Cle API manquante pour le fournisseur selectionne.' ), 400 );
		}

		$context = $this->build_post_context( $post, $settings );
		$result  = $this->request_llm_suggestion( $context, $settings );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $this->format_llm_error_message( $result, $settings ) ), 500 );
		}

		$validation = $this->validate_suggestion( $result, $post_id );
		wp_send_json_success(
			array(
				'suggestion' => $result,
				'validation' => $validation,
			)
		);
	}

	public function ajax_apply() {
		$this->assert_ajax_access();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post introuvable.' ), 404 );
		}

		$seo_title       = isset( $_POST['seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_title'] ) ) : '';
		$description     = isset( $_POST['meta_description'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_description'] ) ) : '';
		$focuskw         = isset( $_POST['focuskw'] ) ? sanitize_text_field( wp_unslash( $_POST['focuskw'] ) ) : '';
		$og_title        = isset( $_POST['og_title'] ) ? sanitize_text_field( wp_unslash( $_POST['og_title'] ) ) : '';
		$og_description  = isset( $_POST['og_description'] ) ? sanitize_text_field( wp_unslash( $_POST['og_description'] ) ) : '';
		$secondary       = isset( $_POST['secondary_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['secondary_keywords'] ) ) : '';
		$article_suggestions = isset( $_POST['article_suggestions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['article_suggestions'] ) ) : '';
		$reading_suggestions = isset( $_POST['reading_suggestions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reading_suggestions'] ) ) : '';
		$reading_json    = isset( $_POST['reading_json'] ) ? $this->sanitize_reading_json_payload( wp_unslash( $_POST['reading_json'] ) ) : '';

		if ( '' === $seo_title || '' === $description ) {
			wp_send_json_error( array( 'message' => 'SEO title et meta description sont obligatoires.' ), 400 );
		}

		$target_keywords = $this->combine_target_keywords( $focuskw, $secondary );
		$before = $this->get_seopress_meta( $post_id );
		$after  = array(
			'title'                => $seo_title,
			'description'          => $description,
			'target_keywords'      => $target_keywords,
			'og_title'             => $og_title,
			'og_description'       => $og_description,
			'article_suggestions'  => $article_suggestions,
			'reading_suggestions'  => $reading_suggestions,
			'reading_json'         => $reading_json,
		);

		add_post_meta(
			$post_id,
			'_vsspa_seopress_backup',
			wp_json_encode(
				array(
					'at'      => current_time( 'mysql' ),
					'user_id' => get_current_user_id(),
					'before'  => $before,
					'after'   => $after,
				)
			)
		);

		update_post_meta( $post_id, '_seopress_titles_title', $seo_title );
		update_post_meta( $post_id, '_seopress_titles_desc', $description );
		update_post_meta( $post_id, '_seopress_analysis_target_kw', $target_keywords );
		update_post_meta( $post_id, '_seopress_social_fb_title', $og_title ? $og_title : $seo_title );
		update_post_meta( $post_id, '_seopress_social_fb_desc', $og_description ? $og_description : $description );
		update_post_meta( $post_id, '_seopress_social_twitter_title', $og_title ? $og_title : $seo_title );
		update_post_meta( $post_id, '_seopress_social_twitter_desc', $og_description ? $og_description : $description );
		update_post_meta( $post_id, '_vsspa_secondary_keywords', $secondary );
		update_post_meta( $post_id, '_vsspa_article_optimization_suggestions', $article_suggestions );
		update_post_meta( $post_id, '_vsspa_reading_recommendations', $reading_suggestions );
		update_post_meta( $post_id, '_vsspa_reading_recommendations_json', $reading_json );

		wp_send_json_success(
			array(
				'message' => 'Champs SEOPress mis a jour.',
				'editUrl' => get_edit_post_link( $post_id, '' ),
				'viewUrl' => get_permalink( $post_id ),
			)
		);
	}

	public function ajax_rewrite_content() {
		$this->assert_ajax_access();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post introuvable.' ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Permission refusee pour modifier ce contenu.' ), 403 );
		}

		$settings = $this->settings();
		$provider = $this->selected_provider( $settings );
		$api_key  = $this->provider_api_key( $settings, $provider );
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'Cle API manquante pour le fournisseur selectionne.' ), 400 );
		}

		$context = $this->build_content_rewrite_context( $post, $settings );
		if ( ! empty( $context['source_is_truncated'] ) ) {
			wp_send_json_error(
				array(
					'message' => 'Article trop long pour une reecriture complete avec la limite actuelle. Augmente "Caracteres envoyes au LLM", enregistre les reglages, puis relance.',
				),
				400
			);
		}

		$result = $this->request_content_rewrite( $context, $settings );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $this->format_llm_error_message( $result, $settings ) ), 500 );
		}

		$summary = $this->format_summary_list( $result['change_summary'] );
		if ( ! empty( $result['notes'] ) ) {
			$summary .= ( $summary ? "\n\n" : '' ) . 'Notes: ' . $result['notes'];
		}

		update_post_meta( $post_id, '_vsspa_optimized_content_proposal', $result['optimized_content'] );
		update_post_meta( $post_id, '_vsspa_optimized_content_summary', $summary );

		wp_send_json_success(
			array(
				'optimized_content' => $result['optimized_content'],
				'change_summary'    => $summary,
			)
		);
	}

	private function assert_ajax_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission refusee.' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private function is_seopress_active() {
		if ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) || function_exists( 'seopress_get_service' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( 'wp-seopress/seopress.php' ) || is_plugin_active( 'wp-seopress-pro/seopress-pro.php' );
		}

		return false;
	}

	private function combine_target_keywords( $focuskw, $secondary ) {
		$keywords = array();
		foreach ( array_merge( array( $focuskw ), explode( ',', (string) $secondary ) ) as $keyword ) {
			$keyword = $this->compact_spaces( $keyword );
			if ( '' !== $keyword ) {
				$keywords[ strtolower( $keyword ) ] = $keyword;
			}
		}

		return implode( ', ', array_values( $keywords ) );
	}

	private function build_post_context( WP_Post $post, $settings ) {
		$post_id       = (int) $post->ID;
		$front_page_id = (int) get_option( 'page_on_front' );
		$page_type     = $front_page_id === $post_id ? 'homepage' : $post->post_type;
		if ( 'post' === $post->post_type ) {
			$page_type = 'article';
		}

		$content = strip_shortcodes( $post->post_content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$content = $this->compact_spaces( $content );
		$content = $this->truncate_text( $content, (int) $settings['max_content_chars'] );

		$categories = array();
		if ( 'post' === $post->post_type ) {
			foreach ( get_the_category( $post_id ) as $category ) {
				$categories[] = $category->name;
			}
		}

		$seopress = $this->get_seopress_meta( $post_id );
		$site_language   = $this->site_language_context();
		$target_language = $this->detect_post_language_context( $post_id, $settings );

		return array(
			'site_name'                => get_bloginfo( 'name' ),
			'site_description'         => get_bloginfo( 'description' ),
			'locale'                   => get_locale(),
			'site_language'            => $site_language,
			'target_language'          => $target_language,
			'url'                      => get_permalink( $post_id ),
			'post_id'                  => $post_id,
			'page_type'                => $page_type,
			'post_type'                => $post->post_type,
			'post_status'              => $post->post_status,
			'post_title'               => get_the_title( $post_id ),
			'post_excerpt'             => $this->compact_spaces( wp_strip_all_tags( get_the_excerpt( $post_id ) ) ),
			'categories'               => $categories,
			'current_seopress_title'          => $seopress['title'],
			'current_seopress_description'    => $seopress['description'],
			'current_seopress_target_keywords' => $seopress['target_keywords'],
			'current_article_optimization'    => $seopress['article_optimization'],
			'current_reading_recommendations' => $seopress['reading_recommendations'],
			'related_content_candidates'      => $this->build_reading_candidates( $post_id, $settings ),
			'content_excerpt'          => $content,
		);
	}

	private function build_reading_candidates( $post_id, $settings ) {
		$post_types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );
		$limit      = isset( $settings['reading_candidates'] ) ? (int) $settings['reading_candidates'] : 12;
		$posts      = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => max( 3, min( 30, $limit ) ),
				'post__not_in'   => array( (int) $post_id ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$candidates = array();
		foreach ( $posts as $candidate ) {
			if ( ! $candidate instanceof WP_Post ) {
				continue;
			}

			$categories = array();
			if ( 'post' === $candidate->post_type ) {
				foreach ( get_the_category( $candidate->ID ) as $category ) {
					$categories[] = $category->name;
				}
			}

			$excerpt = has_excerpt( $candidate ) ? get_the_excerpt( $candidate ) : $candidate->post_content;
			$excerpt = strip_shortcodes( $excerpt );
			$excerpt = wp_strip_all_tags( $excerpt, true );
			$excerpt = $this->compact_spaces( html_entity_decode( $excerpt, ENT_QUOTES, get_bloginfo( 'charset' ) ) );

			$candidates[] = array(
				'post_id'    => (int) $candidate->ID,
				'title'      => get_the_title( $candidate->ID ),
				'post_type'  => $candidate->post_type,
				'url'        => get_permalink( $candidate->ID ),
				'categories' => $categories,
				'excerpt'    => $this->truncate_text( $excerpt, 450 ),
			);
		}

		return $candidates;
	}

	private function build_content_rewrite_context( WP_Post $post, $settings ) {
		$context = $this->build_post_context( $post, $settings );
		$content = strip_shortcodes( $post->post_content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$content = $this->compact_spaces( $content );

		$limit  = isset( $settings['max_content_chars'] ) ? (int) $settings['max_content_chars'] : 9000;
		$length = $this->text_length( $content );

		$context['source_content']        = $this->truncate_text( $content, $limit );
		$context['source_content_length'] = $length;
		$context['source_content_limit']  = $limit;
		$context['source_is_truncated']   = $length > $limit;

		return $context;
	}

	private function selected_provider( $settings ) {
		$provider = isset( $settings['provider'] ) ? $settings['provider'] : 'openai';
		return in_array( $provider, array( 'openai', 'anthropic' ), true ) ? $provider : 'openai';
	}

	private function provider_label( $provider ) {
		return 'anthropic' === $provider ? 'Claude / Anthropic' : 'OpenAI';
	}

	private function provider_api_key( $settings, $provider ) {
		if ( 'anthropic' === $provider ) {
			return isset( $settings['anthropic_api_key'] ) ? $settings['anthropic_api_key'] : '';
		}

		return isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
	}

	private function format_llm_error_message( WP_Error $error, $settings ) {
		$provider = $this->selected_provider( $settings );
		$message  = $error->get_error_message();

		if ( 'openai' === $provider && false !== stripos( $message, 'quota' ) ) {
			if ( ! empty( $settings['anthropic_api_key'] ) ) {
				return 'OpenAI est le fournisseur actif et son quota est depasse. Pour utiliser Claude, choisis "Claude / Anthropic" dans Fournisseur, clique sur "Enregistrer les reglages", puis relance la generation.';
			}

			return 'OpenAI est le fournisseur actif et son quota est depasse. Ajoute du credit OpenAI ou configure une cle Anthropic, choisis "Claude / Anthropic", puis enregistre les reglages.';
		}

		return $this->provider_label( $provider ) . ' : ' . $message;
	}

	private function site_language_context() {
		$locale = get_locale();
		if ( ! $locale ) {
			$locale = get_bloginfo( 'language' );
		}

		return $this->language_context_from_value( $locale, 'wordpress_site_locale' );
	}

	private function detect_post_language_context( $post_id, $settings ) {
		$mode = isset( $settings['language_mode'] ) ? $settings['language_mode'] : 'site';
		if ( 'manual' === $mode && ! empty( $settings['manual_language'] ) ) {
			return $this->language_context_from_value( $settings['manual_language'], 'manual_setting' );
		}

		return $this->site_language_context();
	}

	private function language_context_from_value( $value, $source ) {
		$value  = trim( (string) $value );
		$locale = $this->normalize_language_tag( $value );
		$code   = $this->language_code_from_locale( $locale );

		if ( '' === $value ) {
			$locale = 'und';
			$code   = 'und';
			$label  = 'Unknown';
		} elseif ( preg_match( '/^[a-z]{2,3}([_-][a-z0-9]{2,8})?$/i', $value ) ) {
			$label = $this->language_name_from_code( $code );
		} else {
			$label  = sanitize_text_field( $value );
			$locale = $value;
			$code   = strtolower( preg_replace( '/[^a-z]/i', '', substr( $value, 0, 3 ) ) );
		}

		return array(
			'locale' => $locale,
			'code'   => $code,
			'label'  => $label,
			'source' => $source,
		);
	}

	private function normalize_language_tag( $value ) {
		$value = trim( (string) $value );
		$value = str_replace( '_', '-', $value );
		if ( false === strpos( $value, '-' ) ) {
			return strtolower( $value );
		}

		$parts = explode( '-', $value );
		$parts[0] = strtolower( $parts[0] );
		for ( $i = 1; $i < count( $parts ); $i++ ) {
			$parts[ $i ] = strtoupper( $parts[ $i ] );
		}

		return implode( '-', $parts );
	}

	private function language_code_from_locale( $locale ) {
		$locale = strtolower( str_replace( '_', '-', (string) $locale ) );
		$parts  = explode( '-', $locale );
		return isset( $parts[0] ) && $parts[0] ? $parts[0] : 'und';
	}

	private function language_name_from_code( $code ) {
		$names = array(
			'ar'  => 'Arabic',
			'de'  => 'German',
			'en'  => 'English',
			'es'  => 'Spanish',
			'fr'  => 'French',
			'hi'  => 'Hindi',
			'id'  => 'Indonesian',
			'it'  => 'Italian',
			'ja'  => 'Japanese',
			'km'  => 'Khmer',
			'ko'  => 'Korean',
			'ms'  => 'Malay',
			'nl'  => 'Dutch',
			'pl'  => 'Polish',
			'pt'  => 'Portuguese',
			'ru'  => 'Russian',
			'th'  => 'Thai',
			'tr'  => 'Turkish',
			'und' => 'Unknown',
			'vi'  => 'Vietnamese',
			'zh'  => 'Chinese',
		);

		return isset( $names[ $code ] ) ? $names[ $code ] : strtoupper( $code );
	}

	private function suggestion_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array(
				'seo_title',
				'meta_description',
				'focus_keyphrase',
				'secondary_keywords',
				'og_title',
				'og_description',
				'article_optimization_summary',
				'content_recommendations',
				'heading_recommendations',
				'internal_linking_recommendations',
				'content_gap_recommendations',
				'readability_recommendations',
				'reading_recommendations',
				'confidence',
				'notes',
			),
			'properties'           => array(
				'seo_title'          => array( 'type' => 'string' ),
				'meta_description'   => array( 'type' => 'string' ),
				'focus_keyphrase'    => array( 'type' => 'string' ),
				'secondary_keywords' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'og_title'           => array( 'type' => 'string' ),
				'og_description'     => array( 'type' => 'string' ),
				'article_optimization_summary' => array( 'type' => 'string' ),
				'content_recommendations' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'heading_recommendations' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'internal_linking_recommendations' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'content_gap_recommendations' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'readability_recommendations' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'reading_recommendations' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'post_id', 'title', 'url', 'anchor_text', 'reason', 'placement_hint' ),
						'properties'           => array(
							'post_id'        => array( 'type' => 'integer' ),
							'title'          => array( 'type' => 'string' ),
							'url'            => array( 'type' => 'string' ),
							'anchor_text'    => array( 'type' => 'string' ),
							'reason'         => array( 'type' => 'string' ),
							'placement_hint' => array( 'type' => 'string' ),
						),
					),
				),
				'confidence'         => array( 'type' => 'number' ),
				'notes'              => array( 'type' => 'string' ),
			),
		);
	}

	private function seo_instructions() {
		return implode(
			' ',
			array(
				'You are a senior multilingual SEO editor for WordPress sites using SEOPress.',
				'Generate metadata in the target_language provided in the content JSON.',
				'Use the target language naturally, including correct accents, script, punctuation, word order, and local search phrasing.',
				'If target_language and source content disagree, prioritize the target_language unless the content is clearly in another language and mention uncertainty in notes.',
				'For homepage pages, emphasize brand, core offer, audience, and market positioning.',
				'For articles, summarize the actual article and match the search intent.',
				'Also provide practical article optimization suggestions: content gaps, heading improvements, internal link ideas, readability, and priority edits.',
				'For reading_recommendations, select zero to five highly relevant existing contents from related_content_candidates only.',
				'Use exact post_id, title, and url values from related_content_candidates, and explain why the reader should continue there.',
				'Do not rewrite the article automatically; recommendations must be concise and actionable for a human editor.',
				'Do not invent facts, prices, dates, claims, guarantees, locations, or services not supported by the content.',
				'Do not create a legacy HTML meta keywords tag; secondary keywords are editorial guidance only.',
				'Keep SEO title around 45 to 65 characters when possible.',
				'Keep meta description around 120 to 165 characters when possible.',
				'Avoid keyword stuffing. Return only JSON matching the schema.',
			)
		);
	}

	private function content_rewrite_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'optimized_content', 'change_summary', 'notes' ),
			'properties'           => array(
				'optimized_content' => array( 'type' => 'string' ),
				'change_summary'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'notes'             => array( 'type' => 'string' ),
			),
		);
	}

	private function content_rewrite_instructions() {
		return implode(
			' ',
			array(
				'You are a senior multilingual SEO content editor for WordPress.',
				'Rewrite the provided source_content into an SEO-optimized article body in the target_language.',
				'Preserve factual meaning, brand names, dates, prices, places, claims, and intent; do not invent new facts.',
				'Use clean WordPress-friendly HTML only: paragraphs, h2, h3, ul, ol, li, strong, em, and links when already supported by the provided context.',
				'Do not include h1, script, style, iframe, forms, Markdown fences, or explanations outside JSON.',
				'Improve introduction, heading hierarchy, semantic coverage, readability, transitions, and natural keyword usage.',
				'If reading recommendations are useful, mention a natural section where the human editor could insert them, but do not insert fake links.',
				'Return one complete optimized_content string that can replace the article body in the editor after human approval.',
			)
		);
	}

	private function request_content_rewrite( $context, $settings ) {
		$provider = $this->selected_provider( $settings );
		if ( 'anthropic' === $provider ) {
			return $this->request_anthropic_content_rewrite( $context, $settings );
		}

		return $this->request_openai_content_rewrite( $context, $settings );
	}

	private function request_openai_content_rewrite( $context, $settings ) {
		$schema       = $this->content_rewrite_schema();
		$instructions = $this->content_rewrite_instructions();

		$body = array(
			'model'        => $settings['openai_model'],
			'instructions' => $instructions,
			'input'        => 'Rewrite this WordPress content for SEO after human approval: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'text'         => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'seopress_seo_content_rewrite',
					'strict' => true,
					'schema' => $schema,
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . $settings['openai_api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'OpenAI API error.';
			return new WP_Error( 'vsspa_openai_error', $message );
		}

		$text = $this->extract_openai_output_text( $data );
		if ( '' === $text ) {
			return new WP_Error( 'vsspa_openai_empty', 'Reponse OpenAI vide.' );
		}

		$rewrite = $this->decode_suggestion_json( $text );
		if ( is_wp_error( $rewrite ) ) {
			return $rewrite;
		}

		return $this->sanitize_content_rewrite( $rewrite );
	}

	private function request_anthropic_content_rewrite( $context, $settings ) {
		$schema       = $this->content_rewrite_schema();
		$instructions = $this->content_rewrite_instructions();
		$user_prompt  = implode(
			"\n\n",
			array(
				'Rewrite this WordPress content for SEO after human approval.',
				'Return one valid JSON object only. Do not include Markdown fences, comments, prose, or explanations outside the JSON.',
				'Required JSON schema: ' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'WordPress content: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		$body = array(
			'model'      => $settings['anthropic_model'],
			'max_tokens' => 6000,
			'system'     => $instructions,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
		);

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 90,
				'headers' => array(
					'x-api-key'         => $settings['anthropic_api_key'],
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Anthropic API error.';
			return new WP_Error( 'vsspa_anthropic_error', $message );
		}

		$text = $this->extract_anthropic_output_text( $data );
		if ( '' === $text ) {
			return new WP_Error( 'vsspa_anthropic_empty', 'Reponse Claude vide.' );
		}

		$rewrite = $this->decode_suggestion_json( $text );
		if ( is_wp_error( $rewrite ) ) {
			return $rewrite;
		}

		return $this->sanitize_content_rewrite( $rewrite );
	}

	private function request_llm_suggestion( $context, $settings ) {
		$provider = $this->selected_provider( $settings );
		if ( 'anthropic' === $provider ) {
			return $this->request_anthropic_suggestion( $context, $settings );
		}

		return $this->request_openai_suggestion( $context, $settings );
	}

	private function request_openai_suggestion( $context, $settings ) {
		$schema       = $this->suggestion_schema();
		$instructions = $this->seo_instructions();

		$body = array(
			'model'        => $settings['openai_model'],
			'instructions' => $instructions,
			'input'        => 'Generate SEOPress SEO metadata and article optimization suggestions for this WordPress content: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'text'         => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'seopress_seo_article_suggestion',
					'strict' => true,
					'schema' => $schema,
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $settings['openai_api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'OpenAI API error.';
			return new WP_Error( 'vsspa_openai_error', $message );
		}

		$text = $this->extract_openai_output_text( $data );
		if ( '' === $text ) {
			return new WP_Error( 'vsspa_openai_empty', 'Reponse OpenAI vide.' );
		}

		$suggestion = $this->decode_suggestion_json( $text );
		if ( is_wp_error( $suggestion ) ) {
			return $suggestion;
		}

		return $this->sanitize_suggestion( $suggestion );
	}

	private function request_anthropic_suggestion( $context, $settings ) {
		$schema       = $this->suggestion_schema();
		$instructions = $this->seo_instructions();
		$user_prompt  = implode(
			"\n\n",
			array(
				'Generate SEOPress SEO metadata and article optimization suggestions for this WordPress content.',
				'Return one valid JSON object only. Do not include Markdown fences, comments, prose, or explanations outside the JSON.',
				'Required JSON schema: ' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'WordPress content: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		$body = array(
			'model'      => $settings['anthropic_model'],
			'max_tokens' => 2200,
			'system'     => $instructions,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
		);

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 60,
				'headers' => array(
					'x-api-key'         => $settings['anthropic_api_key'],
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Anthropic API error.';
			return new WP_Error( 'vsspa_anthropic_error', $message );
		}

		$text = $this->extract_anthropic_output_text( $data );
		if ( '' === $text ) {
			return new WP_Error( 'vsspa_anthropic_empty', 'Reponse Claude vide.' );
		}

		$suggestion = $this->decode_suggestion_json( $text );
		if ( is_wp_error( $suggestion ) ) {
			return $suggestion;
		}

		return $this->sanitize_suggestion( $suggestion );
	}

	private function extract_openai_output_text( $data ) {
		if ( isset( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
			return trim( $data['output_text'] );
		}

		if ( empty( $data['output'] ) || ! is_array( $data['output'] ) ) {
			return '';
		}

		$texts = array();
		foreach ( $data['output'] as $item ) {
			if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}
			foreach ( $item['content'] as $content ) {
				if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
					$texts[] = $content['text'];
				}
			}
		}

		return trim( implode( "\n", $texts ) );
	}

	private function extract_anthropic_output_text( $data ) {
		if ( empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return '';
		}

		$texts = array();
		foreach ( $data['content'] as $content ) {
			if ( isset( $content['type'], $content['text'] ) && 'text' === $content['type'] && is_string( $content['text'] ) ) {
				$texts[] = $content['text'];
			}
		}

		return trim( implode( "\n", $texts ) );
	}

	private function decode_suggestion_json( $text ) {
		$text = trim( (string) $text );
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$json = substr( $text, $start, $end - $start + 1 );
			$data = json_decode( $json, true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return new WP_Error( 'vsspa_llm_json', 'La reponse du modele n est pas un JSON valide.' );
	}

	private function sanitize_suggestion( $suggestion ) {
		$keywords = $this->sanitize_string_list( isset( $suggestion['secondary_keywords'] ) ? $suggestion['secondary_keywords'] : array() );

		return array(
			'seo_title'                        => isset( $suggestion['seo_title'] ) ? sanitize_text_field( $suggestion['seo_title'] ) : '',
			'meta_description'                 => isset( $suggestion['meta_description'] ) ? sanitize_text_field( $suggestion['meta_description'] ) : '',
			'focus_keyphrase'                  => isset( $suggestion['focus_keyphrase'] ) ? sanitize_text_field( $suggestion['focus_keyphrase'] ) : '',
			'secondary_keywords'               => array_values( array_unique( $keywords ) ),
			'og_title'                         => isset( $suggestion['og_title'] ) ? sanitize_text_field( $suggestion['og_title'] ) : '',
			'og_description'                   => isset( $suggestion['og_description'] ) ? sanitize_text_field( $suggestion['og_description'] ) : '',
			'article_optimization_summary'     => isset( $suggestion['article_optimization_summary'] ) ? sanitize_textarea_field( $suggestion['article_optimization_summary'] ) : '',
			'content_recommendations'          => $this->sanitize_string_list( isset( $suggestion['content_recommendations'] ) ? $suggestion['content_recommendations'] : array() ),
			'heading_recommendations'          => $this->sanitize_string_list( isset( $suggestion['heading_recommendations'] ) ? $suggestion['heading_recommendations'] : array() ),
			'internal_linking_recommendations' => $this->sanitize_string_list( isset( $suggestion['internal_linking_recommendations'] ) ? $suggestion['internal_linking_recommendations'] : array() ),
			'content_gap_recommendations'      => $this->sanitize_string_list( isset( $suggestion['content_gap_recommendations'] ) ? $suggestion['content_gap_recommendations'] : array() ),
			'readability_recommendations'      => $this->sanitize_string_list( isset( $suggestion['readability_recommendations'] ) ? $suggestion['readability_recommendations'] : array() ),
			'reading_recommendations'          => $this->sanitize_reading_recommendations( isset( $suggestion['reading_recommendations'] ) ? $suggestion['reading_recommendations'] : array() ),
			'confidence'                       => isset( $suggestion['confidence'] ) ? (float) $suggestion['confidence'] : 0,
			'notes'                            => isset( $suggestion['notes'] ) ? sanitize_text_field( $suggestion['notes'] ) : '',
		);
	}

	private function sanitize_content_rewrite( $rewrite ) {
		return array(
			'optimized_content' => isset( $rewrite['optimized_content'] ) ? wp_kses_post( $rewrite['optimized_content'] ) : '',
			'change_summary'    => $this->sanitize_string_list( isset( $rewrite['change_summary'] ) ? $rewrite['change_summary'] : array() ),
			'notes'             => isset( $rewrite['notes'] ) ? sanitize_textarea_field( $rewrite['notes'] ) : '',
		);
	}

	private function sanitize_reading_recommendations( $values ) {
		$clean = array();
		if ( ! is_array( $values ) ) {
			return $clean;
		}

		foreach ( $values as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			$post_id = isset( $value['post_id'] ) ? absint( $value['post_id'] ) : 0;
			$title   = isset( $value['title'] ) ? sanitize_text_field( $value['title'] ) : '';
			$url     = isset( $value['url'] ) ? esc_url_raw( $value['url'] ) : '';
			if ( ! $post_id || '' === $title || '' === $url ) {
				continue;
			}

			$clean[] = array(
				'post_id'        => $post_id,
				'title'          => $title,
				'url'            => $url,
				'anchor_text'    => isset( $value['anchor_text'] ) ? sanitize_text_field( $value['anchor_text'] ) : '',
				'reason'         => isset( $value['reason'] ) ? sanitize_text_field( $value['reason'] ) : '',
				'placement_hint' => isset( $value['placement_hint'] ) ? sanitize_text_field( $value['placement_hint'] ) : '',
			);

			if ( count( $clean ) >= 5 ) {
				break;
			}
		}

		return $clean;
	}

	private function sanitize_reading_json_payload( $raw ) {
		$raw  = trim( (string) $raw );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return '';
		}

		$clean = $this->sanitize_reading_recommendations( $data );
		if ( ! $clean ) {
			return '';
		}

		return wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private function format_summary_list( $values ) {
		$values = $this->sanitize_string_list( $values );
		$lines  = array();
		foreach ( $values as $value ) {
			$lines[] = '- ' . $value;
		}

		return implode( "\n", $lines );
	}

	private function sanitize_string_list( $values ) {
		$clean = array();
		if ( ! is_array( $values ) ) {
			return $clean;
		}

		foreach ( $values as $value ) {
			$value = sanitize_text_field( $value );
			if ( '' !== $value ) {
				$clean[] = $value;
			}
		}

		return $clean;
	}

	private function validate_suggestion( $suggestion, $post_id ) {
		$score       = 100;
		$warnings    = array();
		$title       = isset( $suggestion['seo_title'] ) ? $suggestion['seo_title'] : '';
		$description = isset( $suggestion['meta_description'] ) ? $suggestion['meta_description'] : '';
		$focus       = isset( $suggestion['focus_keyphrase'] ) ? $suggestion['focus_keyphrase'] : '';

		if ( $this->text_length( $title ) < 30 ) {
			$score     -= 12;
			$warnings[] = 'Title court';
		}
		if ( $this->text_length( $title ) > 70 ) {
			$score     -= 15;
			$warnings[] = 'Title long';
		}
		if ( $this->text_length( $description ) < 90 ) {
			$score     -= 12;
			$warnings[] = 'Description courte';
		}
		if ( $this->text_length( $description ) > 175 ) {
			$score     -= 15;
			$warnings[] = 'Description longue';
		}
		if ( '' === $focus ) {
			$score     -= 15;
			$warnings[] = 'Requete cible manquante';
		}
		if ( $title && $this->has_duplicate_meta( '_seopress_titles_title', $title, $post_id ) ) {
			$score     -= 20;
			$warnings[] = 'Title deja utilise ailleurs';
		}
		if ( $description && $this->has_duplicate_meta( '_seopress_titles_desc', $description, $post_id ) ) {
			$score     -= 20;
			$warnings[] = 'Description deja utilisee ailleurs';
		}

		$score = max( 0, min( 100, $score ) );
		if ( ! $warnings ) {
			$warnings[] = 'OK';
		}

		return array(
			'score'    => $score,
			'warnings' => $warnings,
		);
	}

	private function has_duplicate_meta( $meta_key, $value, $post_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post__not_in'   => array( (int) $post_id ),
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'value'   => $value,
						'compare' => '=',
					),
				),
			)
		);

		return $query->have_posts();
	}

	private function compact_spaces( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	private function truncate_text( $text, $limit ) {
		$text = (string) $text;
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text, 'UTF-8' ) <= $limit ) {
				return $text;
			}
			return mb_substr( $text, 0, $limit, 'UTF-8' );
		}

		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		return substr( $text, 0, $limit );
	}

	private function text_length( $text ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( (string) $text, 'UTF-8' );
		}

		return strlen( (string) $text );
	}
}

VSSPA_Plugin::instance();
