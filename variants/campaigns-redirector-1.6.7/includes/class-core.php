<?php
/**
 * Core Module - Redirection & Matomo tracking
 */

if (!defined('ABSPATH')) exit;

class CRMS_Core {

	const OPT_KEY = 'crms_options_core';

	private static $instance = null;
	private $options_cache = null;

	public static function instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('template_redirect', [$this, 'maybe_intercept'], 0);
	}

	public static function activate() {
		$defaults = self::default_options();
		if (!get_option(self::OPT_KEY)) {
			add_option(self::OPT_KEY, $defaults, '', false);
		}
	}

	public static function default_options() {
		return [
			'matomo_url' => 'https://matomo.parresia.fr/',
			'site_id'    => '10',
			'mode'       => 'logo',
			'logo_url'   => '',

			// Tracking robustness/perf
			'redirect_delay_ms' => 150, // minimal redirect delay (ms)
			'use_matomo_js'     => false, // recommended OFF (direct matomo.php beacon is more reliable)
		];
	}

	public function get_options() {
		if ($this->options_cache === null) {
			$opt = get_option(self::OPT_KEY, []);
			if (!is_array($opt)) $opt = [];
			$this->options_cache = wp_parse_args($opt, self::default_options());
		}
		return $this->options_cache;
	}

	public function update_options($new_options) {
		$this->options_cache = null;
		update_option(self::OPT_KEY, $new_options, false);
	}

	/**
	 * Migration depuis v1.6.2 (clé unique) vers options modulaires
	 */
	public static function maybe_migrate_options() {
		$old_key = 'crms_options';
		$old_opt = get_option($old_key);

		if (!$old_opt || !is_array($old_opt)) return;
		if (get_option('crms_migrated_to_163')) return;

		$core_opt = [
			'matomo_url' => $old_opt['matomo_url'] ?? 'https://matomo.parresia.fr/',
			'site_id'    => $old_opt['site_id'] ?? '10',
			'mode'       => $old_opt['mode'] ?? 'logo',
			'logo_url'   => $old_opt['logo_url'] ?? '',
			'redirect_delay_ms' => 150,
			'use_matomo_js'     => false,
		];
		update_option(CRMS_Core::OPT_KEY, $core_opt, false);

		$logger_opt = [
			'enabled'        => !empty($old_opt['logging']),
			'log_failures'   => !empty($old_opt['log_failures']),
			'anonymize_ip'   => !empty($old_opt['anonymize_ip']),
			'max_entries'    => $old_opt['log_max_entries'] ?? '200',
		];
		update_option(CRMS_Logger::OPT_KEY, $logger_opt, false);

		$security_opt = [
			'enabled'              => !empty($old_opt['enable_scanner_block']) || !empty($old_opt['enable_temp_ip_ban']),
			'scanner_block'        => !empty($old_opt['enable_scanner_block']),
			'temp_ban'             => !empty($old_opt['enable_temp_ip_ban']),
			'ban_ttl'              => $old_opt['ip_ban_ttl_sec'] ?? '300',
			'enable_allowlist'     => !empty($old_opt['enable_allowlist']),
			'allowlist_hosts'      => $old_opt['allowlist_hosts'] ?? "www.bgds.fr\nbgds.fr",

			// new defaults
			'burst_protection'     => true,
			'min_interval_ms'      => 800,
			'burst_ban_ttl'        => 300,
		];
		update_option(CRMS_Security::OPT_KEY, $security_opt, false);

		$old_logs = get_option('crms_logs');
		if ($old_logs) {
			update_option(CRMS_Logger::LOG_KEY, $old_logs, false);
		}

		update_option('crms_migrated_to_163', true, false);
	}

	public function maybe_intercept() {
		$path = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
		$path = rtrim((string)$path, '/');

		if ($path !== '/' . CRMS_PAGE_SLUG) return;

		$target_raw = $this->extract_target_raw();
		if (!$target_raw) {
			return;
		}

		nocache_headers();
		header('X-Robots-Tag: noindex, nofollow', true);

		$params = $this->get_pk_params();

		$security = CRMS_Security::instance();
		$security_result = $security->check_request($params);

		if ($security_result !== true) {
			CRMS_Logger::instance()->log_event('REJECT', (string)$security_result, 'n/a', '', '', $params);

			// Silent 204 for scanners/bans/bursts to reduce CPU spikes
			if ($security->should_silent_drop((string)$security_result)) {
				return $this->silent_drop_204();
			}

			return $this->safe_home_redirect();
		}

		$normalize_method = '';
		$target_final = $this->normalize_target_url($target_raw, $normalize_method);
		if (!$target_final) {
			CRMS_Logger::instance()->log_event('REJECT', 'invalid_target_url', $normalize_method, '', $target_raw, $params);
			return $this->safe_home_redirect();
		}

		if ($this->is_loop($target_final)) {
			CRMS_Logger::instance()->log_event('REJECT', 'loop_prevention', $normalize_method, $target_final, $target_raw, $params);
			return $this->safe_home_redirect();
		}

		if (!$security->allowlist_check($target_final)) {
			CRMS_Logger::instance()->log_event('REJECT', 'allowlist_block', $normalize_method, $target_final, $target_raw, $params);
			return $this->safe_home_redirect();
		}

		CRMS_Logger::instance()->log_event('OK', 'redirect', $normalize_method, $target_final, $target_raw, $params);

		$this->render_redirect_page($target_final, $params);
	}

	private function get_pk_params() {
		return [
			'pk_campaign' => isset($_GET['pk_campaign']) ? sanitize_text_field(wp_unslash($_GET['pk_campaign'])) : '',
			'pk_source'   => isset($_GET['pk_source'])   ? sanitize_text_field(wp_unslash($_GET['pk_source']))   : '',
			'pk_medium'   => isset($_GET['pk_medium'])   ? sanitize_text_field(wp_unslash($_GET['pk_medium']))   : '',
			'pk_content'  => isset($_GET['pk_content'])  ? sanitize_text_field(wp_unslash($_GET['pk_content']))  : '',
			'pk_keyword'  => isset($_GET['pk_keyword'])  ? sanitize_text_field(wp_unslash($_GET['pk_keyword']))  : '',
		];
	}

	private function extract_target_raw() {
		$qs = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
		return $this->extract_target_raw_from_query($qs);
	}

	public function extract_target_raw_from_query($qs) {
		$qs = (string)$qs;
		if ($qs === '') return '';

		if (!preg_match('/(?:^|&)target=/', $qs, $match, PREG_OFFSET_CAPTURE)) return '';

		$tail = substr($qs, $match[0][1] + strlen($match[0][0]));

		// If target is not URL-encoded, PHP will truncate $_GET['target'] at the first "&".
		// We rebuild the full target from the raw query string:
		// - take everything after target=
		// - keep appending "&<part>" unless the part is one of our top-level keys (pk_*)
		$parts = explode('&', $tail);
		if (empty($parts)) return '';

		$target = array_shift($parts);

		$stop_keys = ['pk_campaign','pk_source','pk_medium','pk_content','pk_keyword'];
		foreach ($parts as $p) {
			foreach ($stop_keys as $k) {
				if (strpos($p, $k . '=') === 0) {
					return $target;
				}
			}
			$target .= '&' . $p;
		}

		return $target;
	}

	public function normalize_target_url($target_raw, &$method_used = '') {
		$t = trim((string)$target_raw);
		if ($t === '') {
			$method_used = 'empty';
			return '';
		}

		$t = str_replace('&amp;', '&', $t);

		$candidates = [
			['as_is', $t],
			['rawurldecode_1', rawurldecode($t)],
			['urldecode_1', urldecode($t)],
			['rawurldecode_2', rawurldecode(rawurldecode($t))],
			['urldecode_2', urldecode(urldecode($t))],
		];

		foreach ($candidates as [$label, $cand]) {
			$cand = trim((string)$cand);
			$cand = str_replace(' ', '%20', $cand);
			if (stripos($cand, 'http://') === 0) {
				$cand = 'https://' . substr($cand, 7);
				$label .= '_https_upgrade';
			}
			$cand = esc_url_raw($cand);

			if (stripos($cand, 'https://') !== 0) continue;
			if (!wp_http_validate_url($cand)) continue;

			$method_used = $label;
			return $cand;
		}

		$method_used = 'no_valid_candidate';
		return '';
	}

	public function is_loop($target_final) {
		$current_host = parse_url(home_url('/'), PHP_URL_HOST);
		$target_host  = parse_url($target_final, PHP_URL_HOST);
		$target_path  = parse_url($target_final, PHP_URL_PATH);

		if (!$current_host || !$target_host) return false;

		if (strtolower($current_host) === strtolower($target_host)) {
			$tp = rtrim((string)$target_path, '/');
			if ($tp === '/' . CRMS_PAGE_SLUG) return true;
		}
		return false;
	}

	public function diagnose_url($url) {
		$url = trim((string)$url);
		$parts = wp_parse_url($url);
		$query = '';

		if (is_array($parts) && isset($parts['query'])) {
			$query = (string)$parts['query'];
		} elseif (strpos($url, '?') !== false) {
			$query = (string)parse_url($url, PHP_URL_QUERY);
		} else {
			$query = ltrim($url, '?');
		}

		$params = [];
		wp_parse_str($query, $params);

		$pk_params = [];
		foreach (['pk_campaign','pk_source','pk_medium','pk_content','pk_keyword'] as $key) {
			$value = isset($params[$key]) && !is_array($params[$key]) ? $params[$key] : '';
			$pk_params[$key] = sanitize_text_field(wp_unslash($value));
		}

		$target_raw = $this->extract_target_raw_from_query($query);
		$normalize_method = '';
		$target_final = $this->normalize_target_url($target_raw, $normalize_method);
		$security = CRMS_Security::instance();

		return [
			'query'            => $query,
			'target_raw'       => $target_raw,
			'target_final'     => $target_final,
			'normalize_method' => $normalize_method,
			'is_loop'          => $target_final ? $this->is_loop($target_final) : false,
			'allowlist'        => $target_final ? $security->allowlist_check($target_final) : false,
			'pk_params'        => $pk_params,
		];
	}

	private function build_matomo_page_url(array $pk_params) {
		// Send a "clean" URL to Matomo: /campaigns + pk_* (no target=)
		$base = home_url('/' . CRMS_PAGE_SLUG);
		$clean = [];

		foreach (['pk_campaign','pk_source','pk_medium','pk_content','pk_keyword'] as $k) {
			$v = isset($pk_params[$k]) ? (string)$pk_params[$k] : '';
			if ($v !== '') $clean[$k] = $v;
		}

		if (empty($clean)) return $base;

		return $base . '?' . http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
	}

	private function render_redirect_page($target_final, array $pk_params) {
		$opt = $this->get_options();

		$trackerUrl = trailingslashit(
			defined('CAMPAIGNS_REDIRECTOR_MATOMO_URL')
				? CAMPAIGNS_REDIRECTOR_MATOMO_URL
				: apply_filters('campaigns_redirector_matomo_url', $opt['matomo_url'])
		);
		$siteId = defined('CAMPAIGNS_REDIRECTOR_MATOMO_SITE_ID')
			? CAMPAIGNS_REDIRECTOR_MATOMO_SITE_ID
			: apply_filters('campaigns_redirector_matomo_site_id', $opt['site_id']);
		$mode = defined('CAMPAIGNS_REDIRECTOR_MODE')
			? CAMPAIGNS_REDIRECTOR_MODE
			: apply_filters('campaigns_redirector_mode', $opt['mode']);

		$logo_url = defined('CAMPAIGNS_REDIRECTOR_LOGO_URL')
			? CAMPAIGNS_REDIRECTOR_LOGO_URL
			: apply_filters('campaigns_redirector_logo_url', $opt['logo_url']);

		if (!$logo_url && function_exists('get_theme_mod')) {
			$custom_logo_id = get_theme_mod('custom_logo');
			if ($custom_logo_id) {
				$img = wp_get_attachment_image_src($custom_logo_id, 'full');
				if ($img && !empty($img[0])) $logo_url = $img[0];
			}
		}

		$delay_ms = isset($opt['redirect_delay_ms']) ? (int)$opt['redirect_delay_ms'] : 150;
		if ($delay_ms < 50) $delay_ms = 50;
		if ($delay_ms > 1500) $delay_ms = 1500;

		$use_matomo_js = !empty($opt['use_matomo_js']);

		$action_name = 'Campaigns redirect';
		$matomo_page_url = $this->build_matomo_page_url($pk_params);

		$target_attr = esc_attr($target_final);
		$logo_attr   = esc_attr($logo_url);

		$target_json = wp_json_encode($target_final, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$matomo_base_json = wp_json_encode($trackerUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$siteid_json = wp_json_encode((string)$siteId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$action_json = wp_json_encode($action_name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$pageurl_json = wp_json_encode($matomo_page_url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$delay_json = (int)$delay_ms;

		$noscript_img = esc_attr(
			$trackerUrl
			. 'matomo.php?idsite=' . rawurlencode($siteId)
			. '&rec=1'
			. '&action_name=' . rawurlencode($action_name)
			. '&url=' . rawurlencode($matomo_page_url)
			. '&rand=' . mt_rand(100000, 999999)
		);

		if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fr">
<head>
	<meta charset="utf-8">
	<title>Redirection…</title>
	<meta name="robots" content="noindex,nofollow">
	<meta http-equiv="refresh" content="3;url=<?php echo $target_attr; ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<style>
		:root { --bg: #ffffff; }
		html,body{height:100%;margin:0;background:var(--bg)}
		<?php if ($mode === 'stealth'): ?>
		body{opacity:0;}
		<?php else: ?>
		body{display:flex;align-items:center;justify-content:center}
		.logo-wrap{display:flex;align-items:center;justify-content:center;width:160px;height:160px;border-radius:28px;box-shadow:0 8px 30px rgba(0,0,0,.06)}
		.logo-wrap img{max-width:120px;max-height:120px;display:block}
		@keyframes pulse { 0%{transform:scale(1)} 50%{transform:scale(1.04)} 100%{transform:scale(1)} }
		.anim{animation:pulse 1.2s ease-in-out infinite}
		@media (prefers-reduced-motion: reduce){ .anim{animation:none} }
		<?php endif; ?>
	</style>
</head>
<body>
	<?php if ($mode === 'logo'): ?>
		<div class="logo-wrap">
			<?php if ($logo_attr): ?>
				<img src="<?php echo $logo_attr; ?>" alt="" aria-hidden="true" class="anim" />
			<?php else: ?>
				<div class="anim" style="width:96px;height:96px;border-radius:24px;box-shadow:inset 0 0 0 1px rgba(0,0,0,.08)"></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<script>
	(function () {
		var target = <?php echo $target_json; ?>;
		var base   = <?php echo $matomo_base_json; ?>;
		var siteId = <?php echo $siteid_json; ?>;
		var action = <?php echo $action_json; ?>;
		var pageUrl= <?php echo $pageurl_json; ?>;
		var delay  = <?php echo (int)$delay_json; ?>;
		var pk     = <?php echo wp_json_encode($pk_params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

		function buildMatomoUrl() {
			var u = base.replace(/\/?$/, '/') + 'matomo.php';
			var qs = 'idsite=' + encodeURIComponent(siteId)
				+ '&rec=1'
				+ '&action_name=' + encodeURIComponent(action)
				+ '&url=' + encodeURIComponent(pageUrl)
				+ '&pk_campaign=' + encodeURIComponent((pk && pk.pk_campaign) ? pk.pk_campaign : '')
				+ '&pk_source=' + encodeURIComponent((pk && pk.pk_source) ? pk.pk_source : '')
				+ '&pk_medium=' + encodeURIComponent((pk && pk.pk_medium) ? pk.pk_medium : '')
				+ '&pk_content=' + encodeURIComponent((pk && pk.pk_content) ? pk.pk_content : '')
				+ '&pk_keyword=' + encodeURIComponent((pk && pk.pk_keyword) ? pk.pk_keyword : '')
				+ '&rand=' + Math.floor(Math.random() * 1e9);
			return u + '?' + qs;
		}

		function fireMatomo() {
			var url = buildMatomoUrl();

			// 1) sendBeacon
			if (navigator.sendBeacon) {
				try { navigator.sendBeacon(url, ''); return true; } catch(e) {}
			}
			// 2) fetch keepalive
			if (window.fetch) {
				try {
					fetch(url, { method: 'GET', mode: 'no-cors', keepalive: true, credentials: 'omit' });
					return true;
				} catch(e) {}
			}
			// 3) fallback image
			try {
				var img = new Image();
				img.referrerPolicy = 'no-referrer-when-downgrade';
				img.src = url;
				return true;
			} catch(e) {}

			return false;
		}

		<?php if ($use_matomo_js): ?>
		// Optional matomo.js (less reliable if redirect is very fast)
		(function() {
			var _paq = window._paq = window._paq || [];
			_paq.push(['setRequestMethod', 'beacon']);
			_paq.push(['setTrackerUrl', base.replace(/\/?$/, '/') + 'matomo.php']);
			_paq.push(['setSiteId', siteId]);
			_paq.push(['enableLinkTracking']);
			_paq.push(['trackPageView']);
			var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
			g.async = true; g.src = base.replace(/\/?$/, '/') + 'matomo.js'; s.parentNode.insertBefore(g,s);
		})();
		<?php else: ?>
		// Recommended: direct hit to matomo.php (beacon/fetch/image)
		fireMatomo();
		<?php endif; ?>

		setTimeout(function(){ window.location.replace(target); }, delay);
		setTimeout(function(){ window.location.replace(target); }, Math.max(800, delay + 600));
	})();
	</script>

	<noscript>
		<img src="<?php echo $noscript_img; ?>" alt="" referrerpolicy="no-referrer-when-downgrade" style="border:0;position:absolute;left:-9999px;width:1px;height:1px;">
		<meta http-equiv="refresh" content="0;url=<?php echo $target_attr; ?>">
	</noscript>
</body>
</html>
<?php
		exit;
	}

	private function silent_drop_204() {
		if (!headers_sent()) {
			status_header(204);
			header('Content-Type: text/plain; charset=utf-8');
			header('X-Robots-Tag: noindex, nofollow', true);
			nocache_headers();
		}
		exit;
	}

	private function safe_home_redirect() {
		nocache_headers();
		header('X-Robots-Tag: noindex, nofollow', true);
		wp_safe_redirect(home_url('/'), 302);
		exit;
	}
}
