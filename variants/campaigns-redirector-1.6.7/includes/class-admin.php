<?php
/**
 * Admin Module - Settings UI with tabs
 */

if (!defined('ABSPATH')) exit;

class CRMS_Admin {
	
	private static $instance = null;
	
	public static function instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	private function __construct() {
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_init', [$this, 'handle_actions']);
	}
	
	public function register_menu() {
		add_options_page(
			'Campaigns Redirector',
			'Campaigns Redirector',
			'manage_options',
			'crms-settings',
			[$this, 'render_settings_page']
		);
	}
	
	public function handle_actions() {
		if (!current_user_can('manage_options')) return;
		
		// Clear logs
		if (isset($_POST['crms_clear_log']) && check_admin_referer('crms_clear_log_action', 'crms_clear_log_nonce')) {
			CRMS_Logger::instance()->clear_logs();
			add_settings_error('crms_messages', 'crms_message', 'Logs vidés avec succès', 'success');
		}
		
		// Generate static shell
		if (isset($_POST['crms_generate_shell']) && check_admin_referer('crms_generate_shell_action', 'crms_generate_shell_nonce')) {
			$result = CRMS_Performance::instance()->generate_static_shell();
			if (is_wp_error($result)) {
				add_settings_error('crms_messages', 'crms_message', 'Erreur: ' . $result->get_error_message(), 'error');
			} else {
				add_settings_error('crms_messages', 'crms_message', 'Fichier statique généré avec succès', 'success');
			}
		}
		
		// Delete static shell
		if (isset($_POST['crms_delete_shell']) && check_admin_referer('crms_delete_shell_action', 'crms_delete_shell_nonce')) {
			CRMS_Performance::instance()->delete_static_shell();
			add_settings_error('crms_messages', 'crms_message', 'Fichier statique supprimé', 'success');
		}
		
		// Save settings
		if (isset($_POST['crms_save_settings']) && check_admin_referer('crms_settings_action', 'crms_settings_nonce')) {
			$tab = isset($_POST['tab']) ? sanitize_key($_POST['tab']) : 'core';
			
			switch ($tab) {
				case 'core':
					$this->save_core_settings($_POST);
					break;
				case 'security':
					$this->save_security_settings($_POST);
					break;
				case 'performance':
					$this->save_performance_settings($_POST);
					break;
				case 'logger':
					$this->save_logger_settings($_POST);
					break;
			}
			
			add_settings_error('crms_messages', 'crms_message', 'Paramètres sauvegardés', 'success');
		}
	}
	
	private function save_core_settings($post) {
		$core = CRMS_Core::instance();
		$opt = $core->get_options();
		
		if (isset($post['matomo_url'])) {
			$url = trim((string)$post['matomo_url']);
			if ($url && !preg_match('#^https://#i', $url)) {
				add_settings_error('crms_messages', 'crms_error', 'L\'URL Matomo doit commencer par https://', 'error');
			}
			if ($url && substr($url, -1) !== '/') $url .= '/';
			$opt['matomo_url'] = esc_url_raw($url);
		}
		
		if (isset($post['site_id'])) {
			$site = preg_replace('/\D+/', '', (string)$post['site_id']);
			$opt['site_id'] = $site ?: '1';
		}
		
		if (isset($post['mode'])) {
			$opt['mode'] = in_array($post['mode'], ['logo', 'stealth'], true) ? $post['mode'] : 'logo';
		}
		
		if (isset($post['logo_url'])) {
			$opt['logo_url'] = esc_url_raw(trim((string)$post['logo_url']));
		}
		
		$core->update_options($opt);
	}
	
	private function save_security_settings($post) {
		$security = CRMS_Security::instance();
		$opt = $security->get_options();
		
		$opt['enabled'] = !empty($post['enabled']);
		$opt['scanner_block'] = !empty($post['scanner_block']);
		$opt['temp_ban'] = !empty($post['temp_ban']);
		
		if (isset($post['ban_ttl'])) {
			$ttl = (int) $post['ban_ttl'];
			if ($ttl < CRMS_Security::BAN_TTL_MIN) $ttl = CRMS_Security::BAN_TTL_MIN;
			if ($ttl > CRMS_Security::BAN_TTL_MAX) $ttl = CRMS_Security::BAN_TTL_MAX;
			$opt['ban_ttl'] = (string) $ttl;
		}
		
		$opt['enable_allowlist'] = !empty($post['enable_allowlist']);
		
		if (isset($post['allowlist_hosts'])) {
			$opt['allowlist_hosts'] = (string) $post['allowlist_hosts'];
		}

		if (isset($post['min_interval_ms'])) {
			$min = (int) $post['min_interval_ms'];
			if ($min < 0) $min = 0;
			if ($min > 5000) $min = 5000;
			$opt['min_interval_ms'] = (string) $min;
		}
		
		$security->update_options($opt);
	}
	
	private function save_performance_settings($post) {
		$perf = CRMS_Performance::instance();
		$opt = $perf->get_options();
		
		$opt['enabled'] = !empty($post['enabled']);
		$opt['static_shell'] = !empty($post['static_shell']);
		
		$perf->update_options($opt);
	}
	
	private function save_logger_settings($post) {
		$logger = CRMS_Logger::instance();
		$opt = $logger->get_options();
		
		$opt['enabled'] = !empty($post['enabled']);
		$opt['log_failures'] = !empty($post['log_failures']);
		$opt['anonymize_ip'] = !empty($post['anonymize_ip']);
		
		if (isset($post['max_entries'])) {
			$max = (int) $post['max_entries'];
			if ($max < 10) $max = 10;
			if ($max > 2000) $max = 2000;
			$opt['max_entries'] = (string) $max;
		}
		
		$logger->update_options($opt);
	}
	
	public function render_settings_page() {
		if (!current_user_can('manage_options')) return;
		
		$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'core';
		
		// Check for conflicts
		$this->check_conflicts();
		
		settings_errors('crms_messages');
		
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?> <small>v<?php echo CRMS_VERSION; ?></small></h1>
			
			<nav class="nav-tab-wrapper">
				<a href="?page=crms-settings&tab=core" class="nav-tab <?php echo $active_tab === 'core' ? 'nav-tab-active' : ''; ?>">
					🎯 Core
				</a>
				<a href="?page=crms-settings&tab=security" class="nav-tab <?php echo $active_tab === 'security' ? 'nav-tab-active' : ''; ?>">
					🔒 Security
				</a>
				<a href="?page=crms-settings&tab=performance" class="nav-tab <?php echo $active_tab === 'performance' ? 'nav-tab-active' : ''; ?>">
					⚡ Performance
				</a>
				<a href="?page=crms-settings&tab=logger" class="nav-tab <?php echo $active_tab === 'logger' ? 'nav-tab-active' : ''; ?>">
					📊 Logs
				</a>
				<a href="?page=crms-settings&tab=diagnostic" class="nav-tab <?php echo $active_tab === 'diagnostic' ? 'nav-tab-active' : ''; ?>">
					🧪 Diagnostic
				</a>
			</nav>
			
			<div class="tab-content" style="background: white; padding: 20px; margin-top: 0; border: 1px solid #ccd0d4; border-top: none;">
				<?php
				switch ($active_tab) {
					case 'core':
						$this->render_core_tab();
						break;
					case 'security':
						$this->render_security_tab();
						break;
					case 'performance':
						$this->render_performance_tab();
						break;
					case 'logger':
						$this->render_logger_tab();
						break;
					case 'diagnostic':
						$this->render_diagnostic_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}
	
	private function check_conflicts() {
		$campaigns_page = get_page_by_path(CRMS_PAGE_SLUG);
		if ($campaigns_page) {
			echo '<div class="notice notice-info">';
			echo '<p><strong>Page WordPress détectée :</strong> Une page "' . CRMS_PAGE_SLUG . '" existe (ID: ' . $campaigns_page->ID . ').</p>';
			echo '<p>Le plugin intercepte uniquement les URLs contenant <code>target=</code>. Sans cible de redirection, WordPress sert la page active normalement.</p>';
			echo '<p><a href="' . get_edit_post_link($campaigns_page->ID) . '" class="button">Éditer la page</a></p>';
			echo '</div>';
		}
	}
	
	private function render_core_tab() {
		$core = CRMS_Core::instance();
		$opt = $core->get_options();
		?>
		<h2>Configuration Matomo & Redirection</h2>
		<p>Configurer les valeurs utilisées par la page <code>/campaigns</code> pour envoyer le hit Matomo avant redirection.</p>
		
		<form method="post">
			<?php wp_nonce_field('crms_settings_action', 'crms_settings_nonce'); ?>
			<input type="hidden" name="tab" value="core">
			
			<table class="form-table">
				<tr>
					<th scope="row"><label for="matomo_url">URL Matomo</label></th>
					<td>
						<input type="url" id="matomo_url" name="matomo_url" value="<?php echo esc_attr($opt['matomo_url']); ?>" class="regular-text" placeholder="https://matomo.exemple.fr/">
						<p class="description">Doit commencer par <code>https://</code> et se terminer par <code>/</code></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="site_id">ID site Matomo</label></th>
					<td>
						<input type="number" id="site_id" name="site_id" value="<?php echo esc_attr($opt['site_id']); ?>" class="small-text" min="1" step="1">
					</td>
				</tr>
				<tr>
					<th scope="row">Mode d'affichage</th>
					<td>
						<label><input type="radio" name="mode" value="logo" <?php checked($opt['mode'], 'logo'); ?>> Logo animé (sans texte)</label><br>
						<label><input type="radio" name="mode" value="stealth" <?php checked($opt['mode'], 'stealth'); ?>> Stealth (invisible)</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="logo_url">URL du logo</label></th>
					<td>
						<input type="url" id="logo_url" name="logo_url" value="<?php echo esc_attr($opt['logo_url']); ?>" class="regular-text" placeholder="https://.../logo.svg">
						<p class="description">Optionnel. Si laissé vide, le plugin utilisera le <em>custom logo</em> du thème.</p>
					</td>
				</tr>
			</table>
			
			<?php submit_button('Sauvegarder', 'primary', 'crms_save_settings'); ?>
		</form>
		<?php
	}
	
	private function render_security_tab() {
		$security = CRMS_Security::instance();
		$opt = $security->get_options();
		?>
		<h2>Anti-spam & Sécurité</h2>
		<p>Protection contre les scanners, bots et open redirect.</p>

		<?php if (empty($opt['enable_allowlist'])): ?>
			<div class="notice notice-warning inline">
				<p><strong>Allowlist désactivée.</strong> Le plugin accepte toute cible HTTPS valide. En production, activez-la avec les domaines réellement attendus.</p>
			</div>
		<?php endif; ?>
		
		<form method="post">
			<?php wp_nonce_field('crms_settings_action', 'crms_settings_nonce'); ?>
			<input type="hidden" name="tab" value="security">
			
			<table class="form-table">
				<tr>
					<th scope="row">Module activé</th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked(!empty($opt['enabled'])); ?>>
							Activer le module de sécurité
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Blocage scanners</th>
					<td>
						<label>
							<input type="checkbox" name="scanner_block" value="1" <?php checked(!empty($opt['scanner_block'])); ?>>
							Bloquer silencieusement (204) les scanners détectés
						</label>
						<p class="description">Détecte SafeLinks, Proofpoint, Mimecast, etc.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Ban IP temporaire</th>
					<td>
						<label>
							<input type="checkbox" name="temp_ban" value="1" <?php checked(!empty($opt['temp_ban'])); ?>>
							Bannir l'IP temporairement quand un scanner est détecté
						</label>
						<p class="description">Impact limité à <code>/campaigns</code></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ban_ttl">TTL ban (secondes)</label></th>
					<td>
						<input type="number" id="ban_ttl" name="ban_ttl" value="<?php echo esc_attr($opt['ban_ttl']); ?>" class="small-text" min="<?php echo CRMS_Security::BAN_TTL_MIN; ?>" max="<?php echo CRMS_Security::BAN_TTL_MAX; ?>" step="30">
						<p class="description">Par défaut 300s (5 min). Min <?php echo CRMS_Security::BAN_TTL_MIN; ?> / max <?php echo CRMS_Security::BAN_TTL_MAX; ?>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="min_interval_ms">Anti-burst</label></th>
					<td>
						<input type="number" id="min_interval_ms" name="min_interval_ms" value="<?php echo esc_attr($opt['min_interval_ms'] ?? '800'); ?>" class="small-text" min="0" max="5000" step="100"> ms
						<p class="description">Intervalle minimal entre deux requêtes suspectes depuis la même IP. Les clics humains GET avec User-Agent ne sont plus bloqués par ce seuil.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Allowlist domaines</th>
					<td>
						<label>
							<input type="checkbox" name="enable_allowlist" value="1" <?php checked(!empty($opt['enable_allowlist'])); ?>>
							N'autoriser que les hosts listés ci-dessous
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="allowlist_hosts">Hosts autorisés</label></th>
					<td>
						<textarea id="allowlist_hosts" name="allowlist_hosts" rows="6" class="large-text code"><?php echo esc_textarea($opt['allowlist_hosts']); ?></textarea>
						<p class="description">Un host par ligne. Accepte <code>bgds.fr</code>, <code>www.bgds.fr</code>, <code>*.bgds.fr</code>, ou une URL complète dont seul le host sera retenu.</p>
					</td>
				</tr>
			</table>
			
			<?php submit_button('Sauvegarder', 'primary', 'crms_save_settings'); ?>
		</form>
		<?php
	}
	
	private function render_performance_tab() {
		$perf = CRMS_Performance::instance();
		$opt = $perf->get_options();
		$is_stale = $perf->is_static_shell_stale();
		?>
		<h2>Optimisation Performance</h2>
		
		<div class="notice notice-info">
			<p><strong>📌 Recommandation :</strong> Commencez par configurer Cloudflare Page Rules avant d'activer le Static Shell.</p>
		</div>
		
		<h3>1. Cloudflare Page Rules (recommandé)</h3>
		<div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
			<p><strong>Configuration Cloudflare :</strong></p>
			<ol>
				<li>Allez dans : <strong>Websites → votre-site.fr → Rules → Page Rules</strong></li>
				<li>Créez une nouvelle règle pour : <code>*votre-site.fr/campaigns*</code></li>
				<li>Paramètres :
					<ul>
						<li>Cache Level: <strong>Cache Everything</strong></li>
						<li>Edge Cache TTL: <strong>30 seconds</strong></li>
						<li>Browser Cache TTL: <strong>10 seconds</strong></li>
						<li>Bypass Cache on Cookie: <code>wp-*|wordpress_*|comment_*</code></li>
					</ul>
				</li>
			</ol>
			<p><em>Cette configuration réduit la charge serveur de 80-90% sans impacter le tracking Matomo.</em></p>
		</div>
		
		<h3>2. Static Shell (performance extrême)</h3>
		<p>Génère un fichier HTML statique (sans PHP) pour performances maximales. <strong>Nécessite configuration Nginx.</strong></p>
		
		<form method="post">
			<?php wp_nonce_field('crms_settings_action', 'crms_settings_nonce'); ?>
			<input type="hidden" name="tab" value="performance">
			
			<table class="form-table">
				<tr>
					<th scope="row">Module activé</th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked(!empty($opt['enabled'])); ?>>
							Activer le module de performance
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Static Shell</th>
					<td>
						<label>
							<input type="checkbox" name="static_shell" value="1" <?php checked(!empty($opt['static_shell'])); ?>>
							Utiliser le fichier HTML statique
						</label>
					</td>
				</tr>
			</table>
			
			<?php submit_button('Sauvegarder', 'primary', 'crms_save_settings'); ?>
		</form>
		
		<?php if (!empty($opt['enabled']) && !empty($opt['static_shell'])): ?>
			<hr>
			<h3>Gestion du fichier statique</h3>
			
			<?php if (empty($opt['shell_path']) || !file_exists($opt['shell_path'])): ?>
				<form method="post">
					<?php wp_nonce_field('crms_generate_shell_action', 'crms_generate_shell_nonce'); ?>
					<p>
						<button type="submit" name="crms_generate_shell" value="1" class="button button-primary">
							Générer le fichier statique
						</button>
					</p>
				</form>
			<?php else: ?>
				<div class="notice notice-success inline">
					<p><strong>✅ Fichier statique généré :</strong> <code><?php echo esc_html($opt['shell_path']); ?></code></p>
					<?php if (!empty($opt['shell_generated_at'])): ?>
						<p><strong>Généré le :</strong> <?php echo esc_html($opt['shell_generated_at']); ?></p>
					<?php endif; ?>
				</div>
				<?php if ($is_stale): ?>
					<div class="notice notice-warning inline">
						<p><strong>Configuration modifiée depuis la génération.</strong> Régénérez le fichier statique avant une campagne.</p>
					</div>
				<?php endif; ?>
				
				<form method="post" style="display: inline-block; margin-right: 10px;">
					<?php wp_nonce_field('crms_generate_shell_action', 'crms_generate_shell_nonce'); ?>
					<button type="submit" name="crms_generate_shell" value="1" class="button">
						Regénérer le fichier
					</button>
				</form>
				
				<form method="post" style="display: inline-block;">
					<?php wp_nonce_field('crms_delete_shell_action', 'crms_delete_shell_nonce'); ?>
					<button type="submit" name="crms_delete_shell" value="1" class="button" onclick="return confirm('Supprimer le fichier statique ?');">
						Supprimer le fichier
					</button>
				</form>
				
				<hr>
				<h3>Configuration Nginx requise</h3>
				<p>Demandez à votre hébergeur (Aquaray) d'ajouter cette configuration dans votre vhost Nginx :</p>
				<pre style="background: #23282d; color: #f0f0f1; padding: 15px; overflow-x: auto;"><?php echo esc_html($perf->get_nginx_instructions()); ?></pre>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	private function render_diagnostic_tab() {
		$result = null;
		$url = '';

		if (isset($_POST['crms_run_diagnostic'])) {
			$nonce = isset($_POST['crms_diagnostic_nonce']) ? sanitize_text_field(wp_unslash($_POST['crms_diagnostic_nonce'])) : '';
			if (wp_verify_nonce($nonce, 'crms_diagnostic_action')) {
				$url = isset($_POST['crms_diagnostic_url']) ? trim((string)wp_unslash($_POST['crms_diagnostic_url'])) : '';
				$result = CRMS_Core::instance()->diagnose_url($url);
			}
		}
		?>
		<h2>Diagnostic de lien</h2>
		<p>Collez une URL complète <code>/campaigns?...</code> ou seulement sa query string pour vérifier ce que le plugin fera avant l'envoi.</p>

		<form method="post">
			<?php wp_nonce_field('crms_diagnostic_action', 'crms_diagnostic_nonce'); ?>
			<textarea name="crms_diagnostic_url" rows="4" class="large-text code" placeholder="https://votre-site.fr/campaigns?target=https%3A%2F%2Fexample.com%2F&pk_campaign=..."><?php echo esc_textarea($url); ?></textarea>
			<?php submit_button('Analyser le lien', 'primary', 'crms_run_diagnostic', false); ?>
		</form>

		<?php if (is_array($result)): ?>
			<hr>
			<h3>Résultat</h3>
			<table class="widefat striped" style="max-width: 1100px;">
				<tbody>
					<tr><th scope="row">query</th><td><code><?php echo esc_html($result['query']); ?></code></td></tr>
					<tr><th scope="row">target_raw</th><td><code><?php echo esc_html($result['target_raw']); ?></code></td></tr>
					<tr><th scope="row">target_final</th><td><code><?php echo esc_html($result['target_final']); ?></code></td></tr>
					<tr><th scope="row">normalize_method</th><td><code><?php echo esc_html($result['normalize_method']); ?></code></td></tr>
					<tr><th scope="row">loop</th><td><?php echo !empty($result['is_loop']) ? 'Oui, rejet loop_prevention' : 'Non'; ?></td></tr>
					<tr><th scope="row">allowlist</th><td><?php echo !empty($result['allowlist']) ? 'OK' : 'Rejet ou cible invalide'; ?></td></tr>
					<tr><th scope="row">pk_campaign</th><td><?php echo esc_html($result['pk_params']['pk_campaign'] ?? ''); ?></td></tr>
					<tr><th scope="row">pk_source</th><td><?php echo esc_html($result['pk_params']['pk_source'] ?? ''); ?></td></tr>
					<tr><th scope="row">pk_medium</th><td><?php echo esc_html($result['pk_params']['pk_medium'] ?? ''); ?></td></tr>
					<tr><th scope="row">pk_content</th><td><?php echo esc_html($result['pk_params']['pk_content'] ?? ''); ?></td></tr>
					<tr><th scope="row">pk_keyword</th><td><?php echo esc_html($result['pk_params']['pk_keyword'] ?? ''); ?></td></tr>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
	
	private function render_logger_tab() {
		$logger = CRMS_Logger::instance();
		$opt = $logger->get_options();
		$logs = $logger->get_logs();
		?>
		<h2>Logs & Monitoring</h2>
		<p><strong>Recommandation :</strong> désactiver en prod hors debug (écritures DB).</p>
		
		<form method="post">
			<?php wp_nonce_field('crms_settings_action', 'crms_settings_nonce'); ?>
			<input type="hidden" name="tab" value="logger">
			
			<table class="form-table">
				<tr>
					<th scope="row">Logs activés</th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked(!empty($opt['enabled'])); ?>>
							Enregistrer les événements
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Logger REJECT</th>
					<td>
						<label>
							<input type="checkbox" name="log_failures" value="1" <?php checked(!empty($opt['log_failures'])); ?>>
							Enregistrer aussi les rejets
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">RGPD</th>
					<td>
						<label>
							<input type="checkbox" name="anonymize_ip" value="1" <?php checked(!empty($opt['anonymize_ip'])); ?>>
							Masquer dernier octet IP dans les logs
						</label>
						<p class="description"><strong>Optionnel.</strong> Active uniquement si nécessaire pour conformité RGPD. Désactivé par défaut pour faciliter les tests/debug.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="max_entries">Taille du log</label></th>
					<td>
						<input type="number" id="max_entries" name="max_entries" value="<?php echo esc_attr($opt['max_entries']); ?>" class="small-text" min="10" max="2000" step="10"> entrées max
					</td>
				</tr>
			</table>
			
			<?php submit_button('Sauvegarder', 'primary', 'crms_save_settings'); ?>
		</form>
		
		<hr>
		<h2>Logs enregistrés</h2>
		
		<form method="post" style="margin: 12px 0;">
			<?php wp_nonce_field('crms_clear_log_action', 'crms_clear_log_nonce'); ?>
			<button type="submit" name="crms_clear_log" value="1" class="button button-secondary" <?php echo empty($logs) ? 'disabled' : ''; ?>>
				Vider le log
			</button>
		</form>
		
		<?php if (empty($opt['enabled'])): ?>
			<div class="notice notice-warning inline">
				<p>Logs désactivés.</p>
			</div>
		<?php endif; ?>
		
		<?php if (empty($logs)): ?>
			<p><em>Aucune entrée.</em></p>
		<?php else: ?>
			<div style="max-width: 100%; overflow: auto;">
				<table class="widefat striped" style="min-width: 1550px;">
					<thead>
						<tr>
							<th>Date</th>
							<th>Statut</th>
							<th>Raison</th>
							<th>normalize</th>
							<th>IP</th>
							<th>target_final</th>
							<th>target_raw</th>
							<th>pk_campaign</th>
							<th>pk_source</th>
							<th>pk_medium</th>
							<th>pk_content</th>
							<th>pk_keyword</th>
							<th>UA</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($logs as $row): ?>
							<tr>
								<td><?php echo esc_html($row['ts_local'] ?? ''); ?></td>
								<td><strong><?php echo esc_html($row['status'] ?? ''); ?></strong></td>
								<td><?php echo esc_html($row['reason'] ?? ''); ?></td>
								<td><code><?php echo esc_html($row['normalize_method'] ?? ''); ?></code></td>
								<td><?php echo esc_html($row['ip'] ?? ''); ?></td>
								<td><code style="white-space: nowrap;"><?php echo esc_html($row['target_final'] ?? ''); ?></code></td>
								<td><code style="white-space: nowrap;"><?php echo esc_html($row['target_raw'] ?? ''); ?></code></td>
								<td><?php echo esc_html($row['pk_campaign'] ?? ''); ?></td>
								<td><?php echo esc_html($row['pk_source'] ?? ''); ?></td>
								<td><?php echo esc_html($row['pk_medium'] ?? ''); ?></td>
								<td><?php echo esc_html($row['pk_content'] ?? ''); ?></td>
								<td><?php echo esc_html($row['pk_keyword'] ?? ''); ?></td>
								<td><code style="white-space: nowrap;"><?php echo esc_html($row['ua'] ?? ''); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
	}
}
