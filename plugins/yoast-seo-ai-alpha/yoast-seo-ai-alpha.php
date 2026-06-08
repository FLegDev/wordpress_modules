<?php
/**
 * Plugin Name: Multilingual SEO AI for Yoast - Alpha
 * Description: Admin tool to generate multilingual SEO metadata with OpenAI or Claude and apply it to Yoast SEO fields.
 * Version: 0.3.1-alpha
 * Author: Codex
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VSMYA_Plugin {
	const VERSION = '0.3.1-alpha';
	const OPTION = 'vsmya_settings';
	const NONCE = 'vsmya_admin';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_vsmya_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_vsmya_apply', array( $this, 'ajax_apply' ) );
	}

	public function admin_menu() {
		add_management_page(
			'SEO Meta AI',
			'SEO Meta AI',
			'manage_options',
			'vsmya',
			array( $this, 'render_admin_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'vsmya_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->default_settings(),
			)
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( 'tools_page_vsmya' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'vsmya-admin',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'vsmya-admin',
			plugins_url( 'assets/admin.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);
		wp_localize_script(
			'vsmya-admin',
			'VSMYA',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'generating'   => 'Generation en cours...',
					'applying'     => 'Ecriture dans Yoast...',
					'done'         => 'Termine',
					'error'        => 'Erreur',
					'confirmBatch' => 'Appliquer toutes les suggestions visibles dans Yoast SEO ?',
				),
			)
		);
	}

	private function default_settings() {
		return array(
			'provider'          => 'openai',
			'openai_api_key'    => '',
			'openai_model'      => 'gpt-5-mini',
			'anthropic_api_key' => '',
			'anthropic_model'   => 'claude-sonnet-4-5-20250929',
			'language_mode'     => 'site',
			'manual_language'   => '',
			'post_types'        => array( 'post', 'page' ),
			'max_posts'         => 20,
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
			'anthropic_model'   => isset( $input['anthropic_model'] ) ? sanitize_text_field( wp_unslash( $input['anthropic_model'] ) ) : 'claude-sonnet-4-5-20250929',
			'language_mode'     => $language_mode,
			'manual_language'   => isset( $input['manual_language'] ) ? sanitize_text_field( wp_unslash( $input['manual_language'] ) ) : '',
			'post_types'        => $post_types ? array_values( array_unique( $post_types ) ) : array( 'post' ),
			'max_posts'         => isset( $input['max_posts'] ) ? max( 1, min( 100, absint( $input['max_posts'] ) ) ) : 20,
			'max_content_chars' => isset( $input['max_content_chars'] ) ? max( 1000, min( 30000, absint( $input['max_content_chars'] ) ) ) : 9000,
		);
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
		$yoast_active      = defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
		$provider          = $this->selected_provider( $settings );
		$provider_label    = $this->provider_label( $provider );
		$site_language     = $this->site_language_context();
		?>
		<div class="wrap vsmya-wrap">
			<h1>SEO Meta AI - Yoast Alpha</h1>

			<?php if ( ! $yoast_active ) : ?>
				<div class="notice notice-warning">
					<p>Yoast SEO ne semble pas actif. Le plugin peut enregistrer les champs meta, mais Yoast doit etre actif pour les utiliser dans le rendu SEO.</p>
				</div>
			<?php endif; ?>

			<div class="vsmya-grid">
				<section class="vsmya-panel">
					<h2>Reglages</h2>
					<form method="post" action="options.php">
						<?php settings_fields( 'vsmya_settings' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="vsmya-provider">Fournisseur</label></th>
								<td>
									<select id="vsmya-provider" name="<?php echo esc_attr( self::OPTION ); ?>[provider]">
										<option value="openai" <?php selected( $settings['provider'], 'openai' ); ?>>OpenAI</option>
										<option value="anthropic" <?php selected( $settings['provider'], 'anthropic' ); ?>>Claude / Anthropic</option>
									</select>
									<p class="description">Fournisseur actif apres enregistrement : <strong><?php echo esc_html( $provider_label ); ?></strong>.</p>
								</td>
							</tr>
							<tr class="vsmya-provider-row vsmya-provider-openai-row">
								<th scope="row"><label for="vsmya-openai-api-key">Cle OpenAI</label></th>
								<td>
									<input id="vsmya-openai-api-key" type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[openai_api_key]" value="" placeholder="<?php echo esc_attr( empty( $settings['openai_api_key'] ) ? 'sk-...' : 'Cle deja enregistree' ); ?>">
									<p class="description">Laisser vide conserve la cle existante.</p>
								</td>
							</tr>
							<tr class="vsmya-provider-row vsmya-provider-openai-row">
								<th scope="row"><label for="vsmya-openai-model">Modele OpenAI</label></th>
								<td>
									<input id="vsmya-openai-model" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[openai_model]" value="<?php echo esc_attr( $settings['openai_model'] ); ?>">
								</td>
							</tr>
							<tr class="vsmya-provider-row vsmya-provider-anthropic-row">
								<th scope="row"><label for="vsmya-anthropic-api-key">Cle Anthropic</label></th>
								<td>
									<input id="vsmya-anthropic-api-key" type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[anthropic_api_key]" value="" placeholder="<?php echo esc_attr( empty( $settings['anthropic_api_key'] ) ? 'sk-ant-...' : 'Cle deja enregistree' ); ?>">
									<p class="description">Utilisee quand le fournisseur choisi est Claude / Anthropic.</p>
								</td>
							</tr>
							<tr class="vsmya-provider-row vsmya-provider-anthropic-row">
								<th scope="row"><label for="vsmya-anthropic-model">Modele Claude</label></th>
								<td>
									<input id="vsmya-anthropic-model" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[anthropic_model]" value="<?php echo esc_attr( $settings['anthropic_model'] ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="vsmya-language-mode">Langue principale des metas</label></th>
								<td>
									<select id="vsmya-language-mode" name="<?php echo esc_attr( self::OPTION ); ?>[language_mode]">
										<option value="site" <?php selected( $settings['language_mode'], 'site' ); ?>>Detecter depuis la langue principale du site</option>
										<option value="manual" <?php selected( $settings['language_mode'], 'manual' ); ?>>Forcer une langue manuelle</option>
									</select>
									<p class="description">Langue principale detectee : <strong><?php echo esc_html( $site_language['label'] ); ?></strong> (<code><?php echo esc_html( $site_language['locale'] ); ?></code>). Utilisee pour tous les contenus.</p>
								</td>
							</tr>
							<tr class="vsmya-language-manual-row">
								<th scope="row"><label for="vsmya-manual-language">Langue manuelle</label></th>
								<td>
									<input id="vsmya-manual-language" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[manual_language]" value="<?php echo esc_attr( $settings['manual_language'] ); ?>" placeholder="ex: French, Vietnamese, English, fr-FR, vi-VN">
									<p class="description">A utiliser seulement si la langue principale detectee par WordPress n'est pas celle que tu veux.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Types de contenus</th>
								<td>
									<?php foreach ( $public_post_types as $post_type => $object ) : ?>
										<label class="vsmya-check">
											<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $settings['post_types'], true ) ); ?>>
											<?php echo esc_html( $object->labels->name ); ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="vsmya-max-posts">Articles a afficher</label></th>
								<td>
									<input id="vsmya-max-posts" type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION ); ?>[max_posts]" value="<?php echo esc_attr( $settings['max_posts'] ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="vsmya-max-content">Caracteres envoyes au LLM</label></th>
								<td>
									<input id="vsmya-max-content" type="number" min="1000" max="30000" step="500" name="<?php echo esc_attr( self::OPTION ); ?>[max_content_chars]" value="<?php echo esc_attr( $settings['max_content_chars'] ); ?>">
								</td>
							</tr>
						</table>
						<?php submit_button( 'Enregistrer les reglages' ); ?>
					</form>
				</section>

				<section class="vsmya-panel">
					<h2>Mode alpha</h2>
					<p>Cette version genere les suggestions dans l'admin, puis ecrit dans Yoast seulement quand tu cliques sur appliquer.</p>
					<ul>
						<li>SEO title : <code>_yoast_wpseo_title</code></li>
						<li>Meta description : <code>_yoast_wpseo_metadesc</code></li>
						<li>Requete cible : <code>_yoast_wpseo_focuskw</code></li>
						<li>Open Graph : <code>_yoast_wpseo_opengraph-title</code> et <code>_yoast_wpseo_opengraph-description</code></li>
					</ul>
				</section>
			</div>

			<div class="vsmya-actions">
				<span class="vsmya-active-provider">Generation avec : <strong><?php echo esc_html( $provider_label ); ?></strong></span>
				<button type="button" class="button button-primary" id="vsmya-batch-generate">Generer les suggestions visibles</button>
				<button type="button" class="button" id="vsmya-batch-apply">Appliquer les suggestions generees</button>
			</div>

			<?php if ( $is_static_front ) : ?>
				<h2>Page d'accueil</h2>
				<table class="widefat striped vsmya-table">
					<thead>
						<tr>
							<th>Contenu</th>
							<th>Yoast actuel</th>
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
			<table class="widefat striped vsmya-table">
				<thead>
					<tr>
						<th>Contenu</th>
						<th>Yoast actuel</th>
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

	private function render_post_row( $post, $badge, $settings ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$post_id    = (int) $post->ID;
		$yoast      = $this->get_yoast_meta( $post_id );
		$language   = $this->detect_post_language_context( $post_id, $settings );
		$edit_link  = get_edit_post_link( $post_id, '' );
		$permalink  = get_permalink( $post_id );
		$post_title = get_the_title( $post_id );
		?>
		<tr class="vsmya-row" data-post-id="<?php echo esc_attr( $post_id ); ?>">
			<td class="vsmya-content-cell">
				<strong>
					<?php if ( $edit_link ) : ?>
						<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $post_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $post_title ); ?>
					<?php endif; ?>
				</strong>
				<div class="vsmya-meta-line">
					#<?php echo esc_html( $post_id ); ?> · <?php echo esc_html( $post->post_type ); ?> · <?php echo esc_html( $post->post_status ); ?>
					<?php if ( $badge ) : ?>
						<span class="vsmya-badge"><?php echo esc_html( $badge ); ?></span>
					<?php endif; ?>
					<span class="vsmya-badge">Langue principale : <?php echo esc_html( $language['label'] ); ?></span>
				</div>
				<?php if ( $permalink ) : ?>
					<a class="vsmya-url" href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $permalink ); ?></a>
				<?php endif; ?>
			</td>
			<td>
				<label>Title</label>
				<div class="vsmya-current"><?php echo esc_html( $yoast['title'] ? $yoast['title'] : '(vide)' ); ?></div>
				<label>Description</label>
				<div class="vsmya-current"><?php echo esc_html( $yoast['description'] ? $yoast['description'] : '(vide)' ); ?></div>
				<label>Requete cible</label>
				<div class="vsmya-current"><?php echo esc_html( $yoast['focuskw'] ? $yoast['focuskw'] : '(vide)' ); ?></div>
			</td>
			<td class="vsmya-suggestion-cell">
				<label>SEO title</label>
				<input type="text" class="widefat vsmya-field vsmya-title" maxlength="90">
				<label>Meta description</label>
				<textarea class="widefat vsmya-field vsmya-description" rows="3"></textarea>
				<label>Requete cible</label>
				<input type="text" class="widefat vsmya-field vsmya-focuskw">
				<label>Mots-cles secondaires</label>
				<input type="text" class="widefat vsmya-field vsmya-secondary" readonly>
				<label>Open Graph title</label>
				<input type="text" class="widefat vsmya-field vsmya-og-title">
				<label>Open Graph description</label>
				<textarea class="widefat vsmya-field vsmya-og-description" rows="2"></textarea>
				<div class="vsmya-quality"></div>
			</td>
			<td class="vsmya-button-cell">
				<button type="button" class="button button-primary vsmya-generate">Generer</button>
				<button type="button" class="button vsmya-apply" disabled>Appliquer a Yoast</button>
				<div class="vsmya-status" aria-live="polite"></div>
			</td>
		</tr>
		<?php
	}

	private function get_yoast_meta( $post_id ) {
		return array(
			'title'          => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'description'    => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'focuskw'        => (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
			'og_title'       => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true ),
			'og_description' => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true ),
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

		if ( '' === $seo_title || '' === $description ) {
			wp_send_json_error( array( 'message' => 'SEO title et meta description sont obligatoires.' ), 400 );
		}

		$before = $this->get_yoast_meta( $post_id );
		$after  = array(
			'title'          => $seo_title,
			'description'    => $description,
			'focuskw'        => $focuskw,
			'og_title'       => $og_title,
			'og_description' => $og_description,
			'secondary'      => $secondary,
		);

		add_post_meta(
			$post_id,
			'_vsmya_yoast_backup',
			wp_json_encode(
				array(
					'at'      => current_time( 'mysql' ),
					'user_id' => get_current_user_id(),
					'before'  => $before,
					'after'   => $after,
				)
			)
		);

		update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focuskw );
		update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $og_title ? $og_title : $seo_title );
		update_post_meta( $post_id, '_yoast_wpseo_opengraph-description', $og_description ? $og_description : $description );
		update_post_meta( $post_id, '_vsmya_secondary_keywords', $secondary );

		wp_send_json_success(
			array(
				'message' => 'Champs Yoast mis a jour.',
				'editUrl' => get_edit_post_link( $post_id, '' ),
				'viewUrl' => get_permalink( $post_id ),
			)
		);
	}

	private function assert_ajax_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission refusee.' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
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

		$yoast = $this->get_yoast_meta( $post_id );
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
			'current_yoast_title'      => $yoast['title'],
			'current_yoast_metadesc'   => $yoast['description'],
			'current_yoast_focuskw'    => $yoast['focuskw'],
			'content_excerpt'          => $content,
		);
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
				'confidence'         => array( 'type' => 'number' ),
				'notes'              => array( 'type' => 'string' ),
			),
		);
	}

	private function seo_instructions() {
		return implode(
			' ',
			array(
				'You are a senior multilingual SEO editor for WordPress sites using Yoast SEO.',
				'Generate metadata in the target_language provided in the content JSON.',
				'Use the target language naturally, including correct accents, script, punctuation, word order, and local search phrasing.',
				'If target_language and source content disagree, prioritize the target_language unless the content is clearly in another language and mention uncertainty in notes.',
				'For homepage pages, emphasize brand, core offer, audience, and market positioning.',
				'For articles, summarize the actual article and match the search intent.',
				'Do not invent facts, prices, dates, claims, guarantees, locations, or services not supported by the content.',
				'Do not create a legacy HTML meta keywords tag; secondary keywords are editorial guidance only.',
				'Keep SEO title around 45 to 65 characters when possible.',
				'Keep meta description around 120 to 165 characters when possible.',
				'Avoid keyword stuffing. Return only JSON matching the schema.',
			)
		);
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
			'input'        => 'Optimize Yoast SEO metadata for this WordPress content: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'text'         => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'yoast_seo_meta_suggestion',
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
			return new WP_Error( 'vsmya_openai_error', $message );
		}

		$text = $this->extract_openai_output_text( $data );
		if ( '' === $text ) {
			return new WP_Error( 'vsmya_openai_empty', 'Reponse OpenAI vide.' );
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
				'Optimize Yoast SEO metadata for this WordPress content.',
				'Return one valid JSON object only. Do not include Markdown fences, comments, prose, or explanations outside the JSON.',
				'Required JSON schema: ' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'WordPress content: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		$body = array(
			'model'      => $settings['anthropic_model'],
			'max_tokens' => 1200,
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
			return new WP_Error( 'vsmya_anthropic_error', $message );
		}

		$text = $this->extract_anthropic_output_text( $data );
		if ( '' === $text ) {
			return new WP_Error( 'vsmya_anthropic_empty', 'Reponse Claude vide.' );
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

		return new WP_Error( 'vsmya_llm_json', 'La reponse du modele n est pas un JSON valide.' );
	}

	private function sanitize_suggestion( $suggestion ) {
		$keywords = array();
		if ( isset( $suggestion['secondary_keywords'] ) && is_array( $suggestion['secondary_keywords'] ) ) {
			foreach ( $suggestion['secondary_keywords'] as $keyword ) {
				$keyword = sanitize_text_field( $keyword );
				if ( '' !== $keyword ) {
					$keywords[] = $keyword;
				}
			}
		}

		return array(
			'seo_title'          => isset( $suggestion['seo_title'] ) ? sanitize_text_field( $suggestion['seo_title'] ) : '',
			'meta_description'   => isset( $suggestion['meta_description'] ) ? sanitize_text_field( $suggestion['meta_description'] ) : '',
			'focus_keyphrase'    => isset( $suggestion['focus_keyphrase'] ) ? sanitize_text_field( $suggestion['focus_keyphrase'] ) : '',
			'secondary_keywords' => array_values( array_unique( $keywords ) ),
			'og_title'           => isset( $suggestion['og_title'] ) ? sanitize_text_field( $suggestion['og_title'] ) : '',
			'og_description'     => isset( $suggestion['og_description'] ) ? sanitize_text_field( $suggestion['og_description'] ) : '',
			'confidence'         => isset( $suggestion['confidence'] ) ? (float) $suggestion['confidence'] : 0,
			'notes'              => isset( $suggestion['notes'] ) ? sanitize_text_field( $suggestion['notes'] ) : '',
		);
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
		if ( $title && $this->has_duplicate_meta( '_yoast_wpseo_title', $title, $post_id ) ) {
			$score     -= 20;
			$warnings[] = 'Title deja utilise ailleurs';
		}
		if ( $description && $this->has_duplicate_meta( '_yoast_wpseo_metadesc', $description, $post_id ) ) {
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

VSMYA_Plugin::instance();
