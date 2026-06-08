<?php
/**
 * Plugin Name: GEO AI Optimizer - Alpha
 * Description: Generative Engine Optimization toolkit for WordPress: llms.txt, markdown mirrors, GEO audits, and LLM-powered page/article recommendations.
 * Version: 0.1.0-alpha
 * Author: Codex
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VGEO_Plugin {
	const VERSION    = '0.1.0-alpha';
	const OPTION     = 'vgeo_settings';
	const NONCE      = 'vgeo_admin';
	const META_BRIEF = '_vgeo_geo_brief';
	const META_SCORE = '_vgeo_geo_score';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate() {
		self::instance()->register_rewrite_rules();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_public_file' ), 0 );
		add_action( 'wp_head', array( $this, 'render_llms_discovery_links' ), 2 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_editor_meta_box' ), 10, 2 );
		add_action( 'wp_ajax_vgeo_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_vgeo_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_vgeo_preview_llms', array( $this, 'ajax_preview_llms' ) );
	}

	public function register_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?vgeo_llms=1', 'top' );
		add_rewrite_rule( '^llm\.txt$', 'index.php?vgeo_llms=1', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?vgeo_llms_full=1', 'top' );
		add_rewrite_rule( '^llm-full\.txt$', 'index.php?vgeo_llms_full=1', 'top' );
		add_rewrite_rule( '^geo-md/([0-9]+)\.md$', 'index.php?vgeo_md=$matches[1]', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = 'vgeo_llms';
		$vars[] = 'vgeo_llms_full';
		$vars[] = 'vgeo_md';
		return $vars;
	}

	public function maybe_render_public_file() {
		$settings = $this->settings();
		if ( (int) get_query_var( 'vgeo_llms' ) ) {
			if ( empty( $settings['enable_llms'] ) ) {
				status_header( 404 );
				exit;
			}
			$this->send_text_response( $this->generate_llms_txt( false ), 'text/plain; charset=utf-8' );
		}

		if ( (int) get_query_var( 'vgeo_llms_full' ) ) {
			if ( empty( $settings['enable_llms_full'] ) ) {
				status_header( 404 );
				exit;
			}
			$this->send_text_response( $this->generate_llms_txt( true ), 'text/plain; charset=utf-8' );
		}

		$post_id = absint( get_query_var( 'vgeo_md' ) );
		if ( $post_id ) {
			if ( empty( $settings['enable_markdown'] ) ) {
				status_header( 404 );
				exit;
			}
			$post = get_post( $post_id );
			if ( ! $this->is_public_content( $post, $settings ) ) {
				status_header( 404 );
				exit;
			}
			$this->send_text_response( $this->post_to_markdown( $post ), 'text/markdown; charset=utf-8' );
		}
	}

	public function render_llms_discovery_links() {
		$settings = $this->settings();
		if ( empty( $settings['enable_discovery_links'] ) || empty( $settings['enable_llms'] ) ) {
			return;
		}
		printf( '<link rel="alternate" type="text/plain" title="llms.txt" href="%s" />' . "\n", esc_url( home_url( '/llms.txt' ) ) );
		if ( ! empty( $settings['enable_llms_full'] ) ) {
			printf( '<link rel="alternate" type="text/plain" title="llms-full.txt" href="%s" />' . "\n", esc_url( home_url( '/llms-full.txt' ) ) );
		}
	}

	private function send_text_response( $body, $content_type ) {
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: ' . $content_type );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function admin_menu() {
		add_management_page(
			'GEO AI Optimizer',
			'GEO AI Optimizer',
			'manage_options',
			'vgeo',
			array( $this, 'render_admin_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'vgeo_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->default_settings(),
			)
		);
	}

	public function enqueue_admin_assets( $hook ) {
		$should_enqueue = 'tools_page_vgeo' === $hook;
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
			'vgeo-admin',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'vgeo-admin',
			plugins_url( 'assets/admin.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);
		wp_localize_script(
			'vgeo-admin',
			'VGEO',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'generating' => 'Analyse GEO en cours...',
					'saving'     => 'Enregistrement du brief...',
					'previewing' => 'Generation de l apercu...',
					'done'       => 'Termine',
					'error'      => 'Erreur',
					'confirmBatch' => 'Analyser tous les contenus visibles ?',
				),
			)
		);
	}

	private function default_settings() {
		return array(
			'provider'               => 'openai',
			'openai_api_key'         => '',
			'openai_model'           => 'gpt-5-mini',
			'anthropic_api_key'      => '',
			'anthropic_model'        => 'claude-sonnet-4-6',
			'post_types'             => array( 'post', 'page' ),
			'max_posts'              => 30,
			'max_llms_links'         => 30,
			'max_full_posts'         => 20,
			'max_content_chars'      => 12000,
			'enable_llms'            => 1,
			'enable_llms_full'       => 1,
			'enable_markdown'        => 1,
			'enable_discovery_links' => 1,
			'organization_name'      => get_bloginfo( 'name' ),
			'site_summary'           => get_bloginfo( 'description' ),
			'target_audiences'       => '',
			'key_topics'             => '',
			'proof_points'           => '',
			'excluded_paths'         => '',
			'language'               => '',
		);
	}

	private function settings() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $this->default_settings() );
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

		return array(
			'provider'               => $provider,
			'openai_api_key'         => $openai_api_key,
			'openai_model'           => isset( $input['openai_model'] ) ? sanitize_text_field( wp_unslash( $input['openai_model'] ) ) : 'gpt-5-mini',
			'anthropic_api_key'      => $anthropic_api_key,
			'anthropic_model'        => isset( $input['anthropic_model'] ) ? sanitize_text_field( wp_unslash( $input['anthropic_model'] ) ) : 'claude-sonnet-4-6',
			'post_types'             => $post_types ? array_values( array_unique( $post_types ) ) : array( 'post', 'page' ),
			'max_posts'              => isset( $input['max_posts'] ) ? max( 5, min( 100, absint( $input['max_posts'] ) ) ) : 30,
			'max_llms_links'         => isset( $input['max_llms_links'] ) ? max( 5, min( 80, absint( $input['max_llms_links'] ) ) ) : 30,
			'max_full_posts'         => isset( $input['max_full_posts'] ) ? max( 1, min( 50, absint( $input['max_full_posts'] ) ) ) : 20,
			'max_content_chars'      => isset( $input['max_content_chars'] ) ? max( 2000, min( 50000, absint( $input['max_content_chars'] ) ) ) : 12000,
			'enable_llms'            => ! empty( $input['enable_llms'] ) ? 1 : 0,
			'enable_llms_full'       => ! empty( $input['enable_llms_full'] ) ? 1 : 0,
			'enable_markdown'        => ! empty( $input['enable_markdown'] ) ? 1 : 0,
			'enable_discovery_links' => ! empty( $input['enable_discovery_links'] ) ? 1 : 0,
			'organization_name'      => isset( $input['organization_name'] ) ? sanitize_text_field( wp_unslash( $input['organization_name'] ) ) : get_bloginfo( 'name' ),
			'site_summary'           => isset( $input['site_summary'] ) ? sanitize_textarea_field( wp_unslash( $input['site_summary'] ) ) : '',
			'target_audiences'       => isset( $input['target_audiences'] ) ? sanitize_textarea_field( wp_unslash( $input['target_audiences'] ) ) : '',
			'key_topics'             => isset( $input['key_topics'] ) ? sanitize_textarea_field( wp_unslash( $input['key_topics'] ) ) : '',
			'proof_points'           => isset( $input['proof_points'] ) ? sanitize_textarea_field( wp_unslash( $input['proof_points'] ) ) : '',
			'excluded_paths'         => isset( $input['excluded_paths'] ) ? sanitize_textarea_field( wp_unslash( $input['excluded_paths'] ) ) : '',
			'language'               => isset( $input['language'] ) ? sanitize_text_field( wp_unslash( $input['language'] ) ) : '',
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.' ) );
		}

		$settings          = $this->settings();
		$public_post_types = get_post_types( array( 'public' => true ), 'objects' );
		$posts             = $this->get_admin_posts( $settings );
		$provider          = $this->selected_provider( $settings );
		$readiness         = $this->site_readiness( $settings );
		?>
		<div class="wrap vgeo-wrap">
			<h1>GEO AI Optimizer - Alpha</h1>
			<p class="vgeo-lead">Optimisation pour les moteurs generatifs et les agents LLM : fichier <code>llms.txt</code>, miroirs Markdown, briefs GEO et recommandations de contenu.</p>

			<div class="vgeo-grid">
				<section class="vgeo-panel">
					<h2>Reglages GEO</h2>
					<form method="post" action="options.php">
						<?php settings_fields( 'vgeo_settings' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="vgeo-provider">Fournisseur IA</label></th>
								<td>
									<select id="vgeo-provider" name="<?php echo esc_attr( self::OPTION ); ?>[provider]">
										<option value="openai" <?php selected( $settings['provider'], 'openai' ); ?>>OpenAI</option>
										<option value="anthropic" <?php selected( $settings['provider'], 'anthropic' ); ?>>Claude / Anthropic</option>
									</select>
									<p class="description">Fournisseur actif : <strong><?php echo esc_html( $this->provider_label( $provider ) ); ?></strong>. Sans cle API, le plugin utilise une analyse heuristique locale.</p>
								</td>
							</tr>
							<tr class="vgeo-provider-row vgeo-provider-openai-row">
								<th scope="row"><label for="vgeo-openai-api-key">Cle OpenAI</label></th>
								<td>
									<input id="vgeo-openai-api-key" type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[openai_api_key]" value="" placeholder="<?php echo esc_attr( empty( $settings['openai_api_key'] ) ? 'sk-...' : 'Cle deja enregistree' ); ?>">
									<p class="description">Laisser vide conserve la cle existante.</p>
								</td>
							</tr>
							<tr class="vgeo-provider-row vgeo-provider-openai-row">
								<th scope="row"><label for="vgeo-openai-model">Modele OpenAI</label></th>
								<td><input id="vgeo-openai-model" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[openai_model]" value="<?php echo esc_attr( $settings['openai_model'] ); ?>"></td>
							</tr>
							<tr class="vgeo-provider-row vgeo-provider-anthropic-row">
								<th scope="row"><label for="vgeo-anthropic-api-key">Cle Anthropic</label></th>
								<td>
									<input id="vgeo-anthropic-api-key" type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[anthropic_api_key]" value="" placeholder="<?php echo esc_attr( empty( $settings['anthropic_api_key'] ) ? 'sk-ant-...' : 'Cle deja enregistree' ); ?>">
									<p class="description">Utilisee quand Claude / Anthropic est selectionne.</p>
								</td>
							</tr>
							<tr class="vgeo-provider-row vgeo-provider-anthropic-row">
								<th scope="row"><label for="vgeo-anthropic-model">Modele Claude</label></th>
								<td><input id="vgeo-anthropic-model" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[anthropic_model]" value="<?php echo esc_attr( $settings['anthropic_model'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row">Types de contenus</th>
								<td>
									<?php foreach ( $public_post_types as $post_type ) : ?>
										<label class="vgeo-check">
											<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $settings['post_types'], true ) ); ?>>
											<?php echo esc_html( $post_type->label ); ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="vgeo-organization-name">Nom public</label></th>
								<td><input id="vgeo-organization-name" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[organization_name]" value="<?php echo esc_attr( $settings['organization_name'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="vgeo-site-summary">Resume du site</label></th>
								<td><textarea id="vgeo-site-summary" class="large-text" rows="3" name="<?php echo esc_attr( self::OPTION ); ?>[site_summary]"><?php echo esc_textarea( $settings['site_summary'] ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="vgeo-target-audiences">Audiences cibles</label></th>
								<td><textarea id="vgeo-target-audiences" class="large-text" rows="3" name="<?php echo esc_attr( self::OPTION ); ?>[target_audiences]"><?php echo esc_textarea( $settings['target_audiences'] ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="vgeo-key-topics">Sujets / entites prioritaires</label></th>
								<td><textarea id="vgeo-key-topics" class="large-text" rows="3" name="<?php echo esc_attr( self::OPTION ); ?>[key_topics]"><?php echo esc_textarea( $settings['key_topics'] ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="vgeo-proof-points">Preuves et signaux d'autorite</label></th>
								<td><textarea id="vgeo-proof-points" class="large-text" rows="3" name="<?php echo esc_attr( self::OPTION ); ?>[proof_points]"><?php echo esc_textarea( $settings['proof_points'] ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="vgeo-language">Langue cible</label></th>
								<td>
									<input id="vgeo-language" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[language]" value="<?php echo esc_attr( $settings['language'] ); ?>" placeholder="<?php echo esc_attr( get_locale() ); ?>">
									<p class="description">Laisser vide pour utiliser la langue WordPress.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Fichiers publics</th>
								<td>
									<label class="vgeo-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enable_llms]" value="1" <?php checked( ! empty( $settings['enable_llms'] ) ); ?>> Activer <code>/llms.txt</code></label>
									<label class="vgeo-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enable_llms_full]" value="1" <?php checked( ! empty( $settings['enable_llms_full'] ) ); ?>> Activer <code>/llms-full.txt</code></label>
									<label class="vgeo-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enable_markdown]" value="1" <?php checked( ! empty( $settings['enable_markdown'] ) ); ?>> Activer les miroirs Markdown <code>/geo-md/{id}.md</code></label>
									<label class="vgeo-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enable_discovery_links]" value="1" <?php checked( ! empty( $settings['enable_discovery_links'] ) ); ?>> Ajouter des liens de decouverte dans <code>&lt;head&gt;</code></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="vgeo-max-posts">Limites</label></th>
								<td>
									<input id="vgeo-max-posts" type="number" min="5" max="100" name="<?php echo esc_attr( self::OPTION ); ?>[max_posts]" value="<?php echo esc_attr( $settings['max_posts'] ); ?>"> contenus dans l'admin
									<input type="number" min="5" max="80" name="<?php echo esc_attr( self::OPTION ); ?>[max_llms_links]" value="<?php echo esc_attr( $settings['max_llms_links'] ); ?>"> liens dans llms.txt
									<input type="number" min="1" max="50" name="<?php echo esc_attr( self::OPTION ); ?>[max_full_posts]" value="<?php echo esc_attr( $settings['max_full_posts'] ); ?>"> contenus dans llms-full.txt
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="vgeo-excluded-paths">Chemins exclus</label></th>
								<td>
									<textarea id="vgeo-excluded-paths" class="large-text code" rows="3" name="<?php echo esc_attr( self::OPTION ); ?>[excluded_paths]" placeholder="/tag/&#10;/author/"><?php echo esc_textarea( $settings['excluded_paths'] ); ?></textarea>
									<p class="description">Un chemin par ligne. Utilise pour eviter d'inclure certains contenus dans les fichiers LLM.</p>
								</td>
							</tr>
						</table>
						<?php submit_button( 'Enregistrer les reglages' ); ?>
					</form>
				</section>

				<section class="vgeo-panel">
					<h2>Etat GEO</h2>
					<div class="vgeo-score-card">
						<strong><?php echo esc_html( $readiness['score'] ); ?>/100</strong>
						<span><?php echo esc_html( $readiness['label'] ); ?></span>
					</div>
					<ul class="vgeo-checklist">
						<?php foreach ( $readiness['checks'] as $check ) : ?>
							<li class="<?php echo $check['ok'] ? 'is-ok' : 'is-warning'; ?>"><?php echo esc_html( $check['label'] ); ?></li>
						<?php endforeach; ?>
					</ul>
					<h3>Endpoints</h3>
					<p><a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a></p>
					<p><a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/llms-full.txt' ) ); ?></a></p>
					<p class="description">Apres activation du plugin, si ces URLs donnent 404, aller dans Reglages > Permaliens puis enregistrer une fois.</p>
					<div class="vgeo-actions">
						<button type="button" class="button" id="vgeo-preview-llms">Apercu llms.txt</button>
						<button type="button" class="button" id="vgeo-preview-full">Apercu llms-full.txt</button>
					</div>
					<textarea class="large-text code vgeo-preview" rows="16" readonly></textarea>
				</section>
			</div>

			<section class="vgeo-panel">
				<h2>Optimisation GEO des contenus</h2>
				<div class="vgeo-actions">
					<button type="button" class="button button-primary" id="vgeo-batch-generate">Analyser les contenus visibles</button>
					<span class="vgeo-active-provider">Mode : <?php echo esc_html( $this->provider_label( $provider ) ); ?></span>
				</div>
				<table class="widefat striped vgeo-table">
					<thead>
						<tr>
							<th>Contenu</th>
							<th>Score</th>
							<th>Brief GEO</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $posts as $post ) : ?>
							<?php $brief = $this->get_post_brief( $post->ID ); ?>
							<tr class="vgeo-row" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-has-brief="<?php echo empty( $brief ) ? '0' : '1'; ?>">
								<td class="vgeo-content-cell">
									<strong><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></strong>
									<div class="vgeo-meta-line"><?php echo esc_html( get_post_type_object( $post->post_type )->labels->singular_name ); ?> · <?php echo esc_html( get_the_date( '', $post ) ); ?></div>
									<a class="vgeo-url" href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_permalink( $post ) ); ?></a>
								</td>
								<td>
									<div class="vgeo-score"><?php echo esc_html( isset( $brief['score'] ) ? $brief['score'] : $this->heuristic_score( $post )['score'] ); ?>/100</div>
									<div class="vgeo-status"></div>
								</td>
								<td>
									<label>Resume IA</label>
									<textarea class="large-text vgeo-ai-summary" rows="2"><?php echo esc_textarea( isset( $brief['ai_summary'] ) ? $brief['ai_summary'] : '' ); ?></textarea>
									<label>Reponse directe</label>
									<textarea class="large-text vgeo-direct-answer" rows="3"><?php echo esc_textarea( isset( $brief['direct_answer'] ) ? $brief['direct_answer'] : '' ); ?></textarea>
									<label>Description llms.txt</label>
									<textarea class="large-text vgeo-llms-description" rows="2"><?php echo esc_textarea( isset( $brief['llms_description'] ) ? $brief['llms_description'] : '' ); ?></textarea>
									<label>Entites</label>
									<input type="text" class="large-text vgeo-entities" value="<?php echo esc_attr( isset( $brief['entities'] ) ? implode( ', ', (array) $brief['entities'] ) : '' ); ?>">
									<label>Actions recommandees</label>
									<textarea class="large-text vgeo-improvements" rows="4"><?php echo esc_textarea( isset( $brief['content_improvements'] ) ? implode( "\n", (array) $brief['content_improvements'] ) : '' ); ?></textarea>
									<label>FAQ / questions a couvrir</label>
									<textarea class="large-text vgeo-faq" rows="4"><?php echo esc_textarea( isset( $brief['faq'] ) ? $this->faq_to_text( $brief['faq'] ) : '' ); ?></textarea>
									<label>Schema / donnees structurees</label>
									<textarea class="large-text vgeo-schema" rows="3"><?php echo esc_textarea( isset( $brief['schema_recommendations'] ) ? implode( "\n", (array) $brief['schema_recommendations'] ) : '' ); ?></textarea>
								</td>
								<td class="vgeo-button-cell">
									<button type="button" class="button vgeo-generate">Analyser GEO</button>
									<button type="button" class="button button-primary vgeo-save">Enregistrer brief</button>
									<a class="button" href="<?php echo esc_url( home_url( '/geo-md/' . $post->ID . '.md' ) ); ?>" target="_blank" rel="noopener">Markdown</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		</div>
		<?php
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
			'vgeo-editor-box',
			'GEO AI Optimizer',
			array( $this, 'render_editor_meta_box' ),
			$post_type,
			'normal',
			'high'
		);
	}

	public function render_editor_meta_box( $post ) {
		$brief = $this->get_post_brief( $post->ID );
		$score = isset( $brief['score'] ) ? $brief['score'] : $this->heuristic_score( $post )['score'];
		?>
		<div class="vgeo-editor-box vgeo-row" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<p><strong>Score GEO :</strong> <span class="vgeo-score"><?php echo esc_html( $score ); ?>/100</span></p>
			<p class="description">Genere un brief GEO depuis Outils > GEO AI Optimizer, ou directement ici.</p>
			<div class="vgeo-actions">
				<button type="button" class="button vgeo-generate">Analyser GEO</button>
				<button type="button" class="button button-primary vgeo-save">Enregistrer brief</button>
				<a class="button" href="<?php echo esc_url( home_url( '/geo-md/' . $post->ID . '.md' ) ); ?>" target="_blank" rel="noopener">Voir Markdown</a>
			</div>
			<div class="vgeo-status"></div>
			<label>Resume IA</label>
			<textarea class="large-text vgeo-ai-summary" rows="2"><?php echo esc_textarea( isset( $brief['ai_summary'] ) ? $brief['ai_summary'] : '' ); ?></textarea>
			<label>Reponse directe</label>
			<textarea class="large-text vgeo-direct-answer" rows="3"><?php echo esc_textarea( isset( $brief['direct_answer'] ) ? $brief['direct_answer'] : '' ); ?></textarea>
			<label>Description llms.txt</label>
			<textarea class="large-text vgeo-llms-description" rows="2"><?php echo esc_textarea( isset( $brief['llms_description'] ) ? $brief['llms_description'] : '' ); ?></textarea>
			<label>Entites</label>
			<input type="text" class="large-text vgeo-entities" value="<?php echo esc_attr( isset( $brief['entities'] ) ? implode( ', ', (array) $brief['entities'] ) : '' ); ?>">
			<label>Actions recommandees</label>
			<textarea class="large-text vgeo-improvements" rows="4"><?php echo esc_textarea( isset( $brief['content_improvements'] ) ? implode( "\n", (array) $brief['content_improvements'] ) : '' ); ?></textarea>
			<label>FAQ / questions a couvrir</label>
			<textarea class="large-text vgeo-faq" rows="4"><?php echo esc_textarea( isset( $brief['faq'] ) ? $this->faq_to_text( $brief['faq'] ) : '' ); ?></textarea>
			<label>Schema / donnees structurees</label>
			<textarea class="large-text vgeo-schema" rows="3"><?php echo esc_textarea( isset( $brief['schema_recommendations'] ) ? implode( "\n", (array) $brief['schema_recommendations'] ) : '' ); ?></textarea>
		</div>
		<?php
	}

	public function ajax_generate() {
		$this->verify_ajax();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Contenu introuvable.' ), 404 );
		}

		$settings = $this->settings();
		if ( ! in_array( $post->post_type, $settings['post_types'], true ) ) {
			wp_send_json_error( array( 'message' => 'Type de contenu non autorise.' ), 403 );
		}

		$context = $this->post_context( $post, $settings );
		$brief   = $this->request_geo_brief( $context, $settings );
		if ( is_wp_error( $brief ) ) {
			wp_send_json_error( array( 'message' => $brief->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'brief' => $brief ) );
	}

	public function ajax_save() {
		$this->verify_ajax();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Permission refusee.' ), 403 );
		}

		$brief = $this->sanitize_brief(
			array(
				'score'                  => isset( $_POST['score'] ) ? absint( $_POST['score'] ) : 0,
				'ai_summary'             => isset( $_POST['ai_summary'] ) ? wp_unslash( $_POST['ai_summary'] ) : '',
				'direct_answer'          => isset( $_POST['direct_answer'] ) ? wp_unslash( $_POST['direct_answer'] ) : '',
				'llms_description'       => isset( $_POST['llms_description'] ) ? wp_unslash( $_POST['llms_description'] ) : '',
				'entities'               => isset( $_POST['entities'] ) ? $this->csv_to_array( wp_unslash( $_POST['entities'] ) ) : array(),
				'content_improvements'   => isset( $_POST['content_improvements'] ) ? $this->lines_to_array( wp_unslash( $_POST['content_improvements'] ) ) : array(),
				'faq'                    => isset( $_POST['faq'] ) ? $this->text_to_faq( wp_unslash( $_POST['faq'] ) ) : array(),
				'schema_recommendations' => isset( $_POST['schema_recommendations'] ) ? $this->lines_to_array( wp_unslash( $_POST['schema_recommendations'] ) ) : array(),
				'generated_at'           => current_time( 'mysql' ),
				'generator'              => 'manual_admin',
			)
		);

		update_post_meta( $post_id, self::META_BRIEF, $brief );
		update_post_meta( $post_id, self::META_SCORE, $brief['score'] );
		wp_send_json_success( array( 'brief' => $brief ) );
	}

	public function ajax_preview_llms() {
		$this->verify_ajax();
		$full = ! empty( $_POST['full'] );
		wp_send_json_success( array( 'content' => $this->generate_llms_txt( $full ) ) );
	}

	private function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission refusee.' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private function get_admin_posts( $settings ) {
		return get_posts(
			array(
				'post_type'      => $settings['post_types'],
				'post_status'    => 'publish',
				'posts_per_page' => (int) $settings['max_posts'],
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
	}

	private function llms_posts( $settings, $limit ) {
		$posts = get_posts(
			array(
				'post_type'      => $settings['post_types'],
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'modified'   => 'DESC',
				),
			)
		);

		$filtered = array();
		foreach ( $posts as $post ) {
			if ( $this->is_excluded_url( get_permalink( $post ), $settings ) ) {
				continue;
			}
			$filtered[] = $post;
		}

		return $filtered;
	}

	private function generate_llms_txt( $full = false ) {
		$settings = $this->settings();
		$name     = $settings['organization_name'] ? $settings['organization_name'] : get_bloginfo( 'name' );
		$summary  = $settings['site_summary'] ? $settings['site_summary'] : get_bloginfo( 'description' );
		if ( ! $summary ) {
			$summary = sprintf( '%s publie des contenus et ressources sur %s.', $name, home_url( '/' ) );
		}

		$posts = $this->llms_posts( $settings, (int) $settings['max_llms_links'] );
		$pages = array();
		$articles = array();
		foreach ( $posts as $post ) {
			if ( 'page' === $post->post_type ) {
				$pages[] = $post;
			} else {
				$articles[] = $post;
			}
		}

		$lines = array();
		$lines[] = '# ' . $this->markdown_line( $name );
		$lines[] = '';
		$lines[] = '> ' . $this->markdown_line( $summary );
		$lines[] = '';
		$lines[] = 'Ce fichier fournit une carte concise des contenus canoniques du site pour les assistants IA et les workflows de retrieval.';
		$lines[] = 'URL canonique du site : ' . home_url( '/' );
		if ( ! empty( $settings['target_audiences'] ) ) {
			$lines[] = 'Audiences principales : ' . $this->markdown_line( $settings['target_audiences'] );
		}
		if ( ! empty( $settings['key_topics'] ) ) {
			$lines[] = 'Sujets prioritaires : ' . $this->markdown_line( $settings['key_topics'] );
		}
		if ( ! empty( $settings['proof_points'] ) ) {
			$lines[] = 'Signaux d autorite : ' . $this->markdown_line( $settings['proof_points'] );
		}
		if ( ! empty( $settings['enable_llms_full'] ) ) {
			$lines[] = 'Version etendue : ' . home_url( '/llms-full.txt' );
		}

		if ( $pages ) {
			$lines[] = '';
			$lines[] = '## Pages principales';
			foreach ( $pages as $post ) {
				$lines[] = $this->llms_link_line( $post, $settings );
			}
		}

		if ( $articles ) {
			$lines[] = '';
			$lines[] = '## Articles et guides';
			foreach ( $articles as $post ) {
				$lines[] = $this->llms_link_line( $post, $settings );
			}
		}

		$lines[] = '';
		$lines[] = '## Optional';
		$lines[] = '- [Sitemap XML](' . esc_url_raw( home_url( '/wp-sitemap.xml' ) ) . '): Inventaire complet des contenus indexables WordPress.';
		if ( ! empty( $settings['enable_markdown'] ) ) {
			$lines[] = '- [Markdown mirrors](' . esc_url_raw( home_url( '/geo-md/{post_id}.md' ) ) . '): Versions texte propres des contenus publics inclus dans ce fichier.';
		}

		if ( $full ) {
			$lines[] = '';
			$lines[] = '---';
			$lines[] = '';
			$lines[] = '# Contenus etendus';
			$count = 0;
			foreach ( $posts as $post ) {
				if ( $count >= (int) $settings['max_full_posts'] ) {
					break;
				}
				$lines[] = '';
				$lines[] = '## ' . $this->markdown_line( get_the_title( $post ) );
				$lines[] = '';
				$lines[] = 'URL: ' . get_permalink( $post );
				if ( ! empty( $settings['enable_markdown'] ) ) {
					$lines[] = 'Markdown: ' . home_url( '/geo-md/' . $post->ID . '.md' );
				}
				$brief = $this->get_post_brief( $post->ID );
				if ( ! empty( $brief['direct_answer'] ) ) {
					$lines[] = '';
					$lines[] = 'Reponse directe: ' . $this->markdown_line( $brief['direct_answer'] );
				}
				if ( ! empty( $brief['ai_summary'] ) ) {
					$lines[] = '';
					$lines[] = 'Resume: ' . $this->markdown_line( $brief['ai_summary'] );
				}
				$lines[] = '';
				$lines[] = $this->content_plain_text( $post, 3500 );
				$count++;
			}
		}

		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	private function llms_link_line( $post, $settings ) {
		$url = ! empty( $settings['enable_markdown'] ) ? home_url( '/geo-md/' . $post->ID . '.md' ) : get_permalink( $post );
		$brief = $this->get_post_brief( $post->ID );
		$description = '';
		if ( ! empty( $brief['llms_description'] ) ) {
			$description = $brief['llms_description'];
		} elseif ( has_excerpt( $post ) ) {
			$description = get_the_excerpt( $post );
		} else {
			$description = wp_trim_words( $this->content_plain_text( $post, 600 ), 24, '.' );
		}

		return sprintf(
			'- [%s](%s): %s',
			$this->markdown_line( get_the_title( $post ) ),
			esc_url_raw( $url ),
			$this->markdown_line( $description )
		);
	}

	private function is_public_content( $post, $settings ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}
		if ( ! in_array( $post->post_type, $settings['post_types'], true ) ) {
			return false;
		}
		return ! $this->is_excluded_url( get_permalink( $post ), $settings );
	}

	private function is_excluded_url( $url, $settings ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$rules = $this->lines_to_array( $settings['excluded_paths'] );
		foreach ( $rules as $rule ) {
			if ( '' !== $rule && false !== strpos( (string) $path, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	private function post_to_markdown( $post ) {
		$brief = $this->get_post_brief( $post->ID );
		$lines = array();
		$lines[] = '# ' . $this->markdown_line( get_the_title( $post ) );
		$lines[] = '';
		$lines[] = 'Canonical URL: ' . get_permalink( $post );
		$lines[] = 'Published: ' . get_the_date( DATE_W3C, $post );
		$lines[] = 'Modified: ' . get_the_modified_date( DATE_W3C, $post );
		if ( ! empty( $brief['ai_summary'] ) ) {
			$lines[] = '';
			$lines[] = '> ' . $this->markdown_line( $brief['ai_summary'] );
		}
		if ( ! empty( $brief['direct_answer'] ) ) {
			$lines[] = '';
			$lines[] = '## Direct answer';
			$lines[] = '';
			$lines[] = $this->markdown_line( $brief['direct_answer'] );
		}
		if ( ! empty( $brief['entities'] ) ) {
			$lines[] = '';
			$lines[] = '## Entities';
			foreach ( (array) $brief['entities'] as $entity ) {
				$lines[] = '- ' . $this->markdown_line( $entity );
			}
		}
		if ( ! empty( $brief['faq'] ) ) {
			$lines[] = '';
			$lines[] = '## FAQ';
			foreach ( (array) $brief['faq'] as $item ) {
				if ( empty( $item['question'] ) || empty( $item['answer'] ) ) {
					continue;
				}
				$lines[] = '';
				$lines[] = '### ' . $this->markdown_line( $item['question'] );
				$lines[] = '';
				$lines[] = $this->markdown_line( $item['answer'] );
			}
		}
		$lines[] = '';
		$lines[] = '## Content';
		$lines[] = '';
		$lines[] = $this->content_plain_text( $post, 12000 );

		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	private function request_geo_brief( $context, $settings ) {
		$provider = $this->selected_provider( $settings );
		if ( 'none' === $provider ) {
			return $this->heuristic_brief_from_context( $context );
		}
		if ( 'anthropic' === $provider ) {
			return $this->request_anthropic_brief( $context, $settings );
		}
		return $this->request_openai_brief( $context, $settings );
	}

	private function selected_provider( $settings ) {
		if ( 'anthropic' === $settings['provider'] && ! empty( $settings['anthropic_api_key'] ) ) {
			return 'anthropic';
		}
		if ( ! empty( $settings['openai_api_key'] ) ) {
			return 'openai';
		}
		return 'none';
	}

	private function provider_label( $provider ) {
		if ( 'anthropic' === $provider ) {
			return 'Claude / Anthropic';
		}
		if ( 'openai' === $provider ) {
			return 'OpenAI';
		}
		return 'Heuristique locale';
	}

	private function request_openai_brief( $context, $settings ) {
		$body = array(
			'model'        => $settings['openai_model'],
			'instructions' => $this->geo_instructions(),
			'input'        => 'Create a GEO optimization brief for this WordPress content. Return JSON only: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'text'         => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'geo_optimization_brief',
					'strict' => true,
					'schema' => $this->brief_schema(),
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 75,
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
			return new WP_Error( 'vgeo_openai_error', $message );
		}

		$text = $this->extract_openai_output_text( $data );
		return $this->decode_and_sanitize_brief( $text, 'openai' );
	}

	private function request_anthropic_brief( $context, $settings ) {
		$user_prompt = implode(
			"\n\n",
			array(
				'Create a GEO optimization brief for this WordPress content.',
				'Return one valid JSON object only. No Markdown fences, comments, or prose outside JSON.',
				'Required JSON schema: ' . wp_json_encode( $this->brief_schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'WordPress content: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		$body = array(
			'model'      => $settings['anthropic_model'],
			'max_tokens' => 1800,
			'system'     => $this->geo_instructions(),
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
				'timeout' => 75,
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
			return new WP_Error( 'vgeo_anthropic_error', $message );
		}

		$text = $this->extract_anthropic_output_text( $data );
		return $this->decode_and_sanitize_brief( $text, 'anthropic' );
	}

	private function geo_instructions() {
		return implode(
			' ',
			array(
				'You are a senior Generative Engine Optimization strategist for WordPress publishers.',
				'Help the site become easier for LLMs and retrieval agents to understand, cite, and summarize.',
				'Focus on clear direct answers, entity disambiguation, verifiable claims, canonical URLs, FAQ coverage, internal links, and structured data recommendations.',
				'Do not invent facts, awards, prices, dates, statistics, product claims, medical/legal claims, or source citations not present in the content or site context.',
				'When evidence is missing, put it in missing_evidence instead of making it up.',
				'Write in the target language provided in the context.',
				'Return concise, actionable JSON only.',
			)
		);
	}

	private function brief_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array(
				'score',
				'ai_summary',
				'direct_answer',
				'llms_description',
				'entities',
				'facts',
				'faq',
				'schema_recommendations',
				'content_improvements',
				'internal_link_targets',
				'missing_evidence',
				'priority',
				'notes',
			),
			'properties'           => array(
				'score'                  => array( 'type' => 'integer' ),
				'ai_summary'             => array( 'type' => 'string' ),
				'direct_answer'          => array( 'type' => 'string' ),
				'llms_description'       => array( 'type' => 'string' ),
				'entities'               => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'facts'                  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'faq'                    => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'question', 'answer' ),
						'properties'           => array(
							'question' => array( 'type' => 'string' ),
							'answer'   => array( 'type' => 'string' ),
						),
					),
				),
				'schema_recommendations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'content_improvements'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'internal_link_targets'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'missing_evidence'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'priority'               => array( 'type' => 'string' ),
				'notes'                  => array( 'type' => 'string' ),
			),
		);
	}

	private function decode_and_sanitize_brief( $text, $generator ) {
		if ( '' === trim( (string) $text ) ) {
			return new WP_Error( 'vgeo_empty_response', 'Reponse IA vide.' );
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			}
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vgeo_invalid_json', 'La reponse IA n est pas un JSON valide.' );
		}
		$data['generator']    = $generator;
		$data['generated_at'] = current_time( 'mysql' );
		return $this->sanitize_brief( $data );
	}

	private function sanitize_brief( $brief ) {
		$sanitized = array(
			'score'                  => isset( $brief['score'] ) ? max( 0, min( 100, absint( $brief['score'] ) ) ) : 0,
			'ai_summary'             => isset( $brief['ai_summary'] ) ? sanitize_textarea_field( $brief['ai_summary'] ) : '',
			'direct_answer'          => isset( $brief['direct_answer'] ) ? sanitize_textarea_field( $brief['direct_answer'] ) : '',
			'llms_description'       => isset( $brief['llms_description'] ) ? sanitize_textarea_field( $brief['llms_description'] ) : '',
			'entities'               => $this->sanitize_array_strings( isset( $brief['entities'] ) ? $brief['entities'] : array(), 20 ),
			'facts'                  => $this->sanitize_array_strings( isset( $brief['facts'] ) ? $brief['facts'] : array(), 12 ),
			'faq'                    => $this->sanitize_faq( isset( $brief['faq'] ) ? $brief['faq'] : array() ),
			'schema_recommendations' => $this->sanitize_array_strings( isset( $brief['schema_recommendations'] ) ? $brief['schema_recommendations'] : array(), 12 ),
			'content_improvements'   => $this->sanitize_array_strings( isset( $brief['content_improvements'] ) ? $brief['content_improvements'] : array(), 20 ),
			'internal_link_targets'  => $this->sanitize_array_strings( isset( $brief['internal_link_targets'] ) ? $brief['internal_link_targets'] : array(), 12 ),
			'missing_evidence'       => $this->sanitize_array_strings( isset( $brief['missing_evidence'] ) ? $brief['missing_evidence'] : array(), 12 ),
			'priority'               => isset( $brief['priority'] ) ? sanitize_key( $brief['priority'] ) : 'medium',
			'notes'                  => isset( $brief['notes'] ) ? sanitize_textarea_field( $brief['notes'] ) : '',
			'generator'              => isset( $brief['generator'] ) ? sanitize_key( $brief['generator'] ) : 'unknown',
			'generated_at'           => isset( $brief['generated_at'] ) ? sanitize_text_field( $brief['generated_at'] ) : current_time( 'mysql' ),
		);
		if ( ! in_array( $sanitized['priority'], array( 'low', 'medium', 'high' ), true ) ) {
			$sanitized['priority'] = 'medium';
		}
		return $sanitized;
	}

	private function heuristic_brief_from_context( $context ) {
		$score = isset( $context['heuristic']['score'] ) ? (int) $context['heuristic']['score'] : 50;
		$warnings = isset( $context['heuristic']['warnings'] ) ? (array) $context['heuristic']['warnings'] : array();
		$title = isset( $context['title'] ) ? $context['title'] : '';
		$excerpt = isset( $context['excerpt'] ) && $context['excerpt'] ? $context['excerpt'] : wp_trim_words( isset( $context['content'] ) ? $context['content'] : '', 30, '.' );

		return $this->sanitize_brief(
			array(
				'score'                  => $score,
				'ai_summary'             => $excerpt,
				'direct_answer'          => $excerpt,
				'llms_description'       => $excerpt ? $excerpt : sprintf( 'Page canonique a propos de %s.', $title ),
				'entities'               => $this->guess_entities( $title . ' ' . ( isset( $context['content'] ) ? $context['content'] : '' ) ),
				'facts'                  => array(),
				'faq'                    => array(
					array(
						'question' => sprintf( 'Que faut-il retenir de %s ?', $title ),
						'answer'   => $excerpt,
					),
				),
				'schema_recommendations' => array( 'Verifier le schema Article, WebPage, FAQPage ou Organization selon le type de contenu.' ),
				'content_improvements'   => $warnings ? $warnings : array( 'Ajouter un resume direct en debut de contenu et des preuves verifiables.' ),
				'internal_link_targets'  => array(),
				'missing_evidence'       => array( 'Ajouter des sources, dates, auteurs ou preuves pour les affirmations importantes.' ),
				'priority'               => $score < 70 ? 'high' : 'medium',
				'notes'                  => 'Brief genere par analyse heuristique locale faute de cle API active.',
				'generator'              => 'heuristic',
				'generated_at'           => current_time( 'mysql' ),
			)
		);
	}

	private function post_context( $post, $settings ) {
		$content = $this->content_plain_text( $post, (int) $settings['max_content_chars'] );
		return array(
			'site'            => array(
				'name'             => $settings['organization_name'],
				'url'              => home_url( '/' ),
				'summary'          => $settings['site_summary'],
				'target_audiences' => $settings['target_audiences'],
				'key_topics'       => $settings['key_topics'],
				'proof_points'     => $settings['proof_points'],
				'language'         => $settings['language'] ? $settings['language'] : get_locale(),
			),
			'post_id'         => $post->ID,
			'post_type'       => $post->post_type,
			'title'           => get_the_title( $post ),
			'url'             => get_permalink( $post ),
			'markdown_url'    => home_url( '/geo-md/' . $post->ID . '.md' ),
			'date_published'  => get_the_date( DATE_W3C, $post ),
			'date_modified'   => get_the_modified_date( DATE_W3C, $post ),
			'excerpt'         => has_excerpt( $post ) ? get_the_excerpt( $post ) : '',
			'content'         => $content,
			'heuristic'       => $this->heuristic_score( $post ),
			'existing_brief'  => $this->get_post_brief( $post->ID ),
		);
	}

	private function heuristic_score( $post ) {
		$content = $this->content_plain_text( $post, 50000 );
		$html = $post->post_content;
		$score = 35;
		$warnings = array();
		$word_count = str_word_count( wp_strip_all_tags( $content ) );
		if ( $word_count >= 500 ) {
			$score += 15;
		} else {
			$warnings[] = 'Le contenu est court pour un assistant IA ; viser au moins 500 mots si le sujet le justifie.';
		}
		if ( preg_match( '/<(h2|h3)[^>]*>/i', $html ) || false !== strpos( $html, '<!-- wp:heading' ) ) {
			$score += 10;
		} else {
			$warnings[] = 'Ajouter des intertitres H2/H3 qui formulent les sous-questions du sujet.';
		}
		if ( preg_match( '/\?/', $content ) ) {
			$score += 8;
		} else {
			$warnings[] = 'Ajouter des questions-reponses ou une FAQ courte.';
		}
		$link_count = preg_match_all( '/https?:\/\//i', $html, $link_matches );
		if ( $link_count >= 2 ) {
			$score += 8;
		} else {
			$warnings[] = 'Ajouter des liens vers des sources ou pages canoniques pertinentes.';
		}
		if ( has_excerpt( $post ) ) {
			$score += 8;
		} else {
			$warnings[] = 'Renseigner un extrait clair reutilisable comme resume LLM.';
		}
		if ( get_the_modified_date( 'U', $post ) >= strtotime( '-18 months' ) ) {
			$score += 6;
		} else {
			$warnings[] = 'Verifier la fraicheur des informations et mettre a jour la date si necessaire.';
		}
		if ( strlen( get_the_title( $post ) ) >= 25 ) {
			$score += 5;
		} else {
			$warnings[] = 'Rendre le titre plus explicite et moins ambigu.';
		}
		if ( preg_match( '/schema\.org|application\/ld\+json|FAQPage|Article|Organization/i', $html ) ) {
			$score += 5;
		}

		return array(
			'score'    => max( 0, min( 100, $score ) ),
			'warnings' => $warnings,
		);
	}

	private function site_readiness( $settings ) {
		$checks = array(
			array( 'label' => 'Resume du site renseigne', 'ok' => ! empty( $settings['site_summary'] ) ),
			array( 'label' => 'Audiences cibles explicites', 'ok' => ! empty( $settings['target_audiences'] ) ),
			array( 'label' => 'Sujets / entites prioritaires renseignes', 'ok' => ! empty( $settings['key_topics'] ) ),
			array( 'label' => '/llms.txt active', 'ok' => ! empty( $settings['enable_llms'] ) ),
			array( 'label' => '/llms-full.txt active', 'ok' => ! empty( $settings['enable_llms_full'] ) ),
			array( 'label' => 'Miroirs Markdown actifs', 'ok' => ! empty( $settings['enable_markdown'] ) ),
			array( 'label' => 'Fournisseur IA configure ou mode heuristique disponible', 'ok' => true ),
		);
		$ok = 0;
		foreach ( $checks as $check ) {
			if ( $check['ok'] ) {
				$ok++;
			}
		}
		$score = (int) round( ( $ok / count( $checks ) ) * 100 );
		$label = $score >= 85 ? 'Bonne base GEO' : ( $score >= 60 ? 'Base correcte a renforcer' : 'Configuration a completer' );
		return array( 'score' => $score, 'label' => $label, 'checks' => $checks );
	}

	private function content_plain_text( $post, $max_chars ) {
		$content = $post instanceof WP_Post ? $post->post_content : (string) $post;
		if ( function_exists( 'do_blocks' ) ) {
			$content = do_blocks( $content );
		}
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$content = preg_replace( '/[ \t]+/', ' ', $content );
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );
		$content = trim( $content );
		if ( strlen( $content ) > $max_chars ) {
			$content = substr( $content, 0, $max_chars ) . '...';
		}
		return $content;
	}

	private function get_post_brief( $post_id ) {
		$brief = get_post_meta( $post_id, self::META_BRIEF, true );
		return is_array( $brief ) ? $brief : array();
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

	private function sanitize_array_strings( $items, $limit ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			$items = $this->lines_to_array( $items );
		}
		foreach ( $items as $item ) {
			$item = sanitize_text_field( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function sanitize_faq( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = isset( $item['question'] ) ? sanitize_text_field( $item['question'] ) : '';
			$answer = isset( $item['answer'] ) ? sanitize_textarea_field( $item['answer'] ) : '';
			if ( $question && $answer ) {
				$out[] = array( 'question' => $question, 'answer' => $answer );
			}
			if ( count( $out ) >= 8 ) {
				break;
			}
		}
		return $out;
	}

	private function csv_to_array( $text ) {
		return array_filter( array_map( 'trim', explode( ',', (string) $text ) ) );
	}

	private function lines_to_array( $text ) {
		if ( is_array( $text ) ) {
			return $text;
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ) ) );
	}

	private function text_to_faq( $text ) {
		$items = array();
		$blocks = preg_split( '/\n\s*\n/', trim( (string) $text ) );
		foreach ( $blocks as $block ) {
			$lines = $this->lines_to_array( $block );
			if ( count( $lines ) >= 2 ) {
				$items[] = array(
					'question' => preg_replace( '/^Q\s*:\s*/i', '', $lines[0] ),
					'answer'   => preg_replace( '/^A\s*:\s*/i', '', implode( ' ', array_slice( $lines, 1 ) ) ),
				);
			}
		}
		return $items;
	}

	private function faq_to_text( $faq ) {
		$blocks = array();
		foreach ( (array) $faq as $item ) {
			if ( ! is_array( $item ) || empty( $item['question'] ) || empty( $item['answer'] ) ) {
				continue;
			}
			$blocks[] = 'Q: ' . $item['question'] . "\n" . 'A: ' . $item['answer'];
		}
		return implode( "\n\n", $blocks );
	}

	private function guess_entities( $text ) {
		preg_match_all( '/\b[A-ZÀ-ÖØ-Þ][A-Za-zÀ-ÖØ-öø-ÿ0-9\'’-]{2,}(?:\s+[A-ZÀ-ÖØ-Þ][A-Za-zÀ-ÖØ-öø-ÿ0-9\'’-]{2,}){0,3}\b/u', $text, $matches );
		$entities = isset( $matches[0] ) ? array_slice( array_unique( $matches[0] ), 0, 10 ) : array();
		return $entities;
	}

	private function markdown_line( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = str_replace( array( "\r", "\n" ), ' ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}
}

register_activation_hook( __FILE__, array( 'VGEO_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VGEO_Plugin', 'deactivate' ) );
VGEO_Plugin::instance();
