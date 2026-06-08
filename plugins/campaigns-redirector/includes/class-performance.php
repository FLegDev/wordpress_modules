<?php
/**
 * Performance Module - Static shell & caching
 */

if (!defined('ABSPATH')) exit;

class CRMS_Performance {

	const OPT_KEY = 'crms_options_performance';

	private static $instance = null;
	private $options_cache = null;

	public static function instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Passive module
	}

	public static function activate() {
		$defaults = self::default_options();
		if (!get_option(self::OPT_KEY)) {
			add_option(self::OPT_KEY, $defaults, '', false);
		}
	}

	public static function default_options() {
		return [
			'enabled'          => false,
			'static_shell'     => false,
			'shell_path'       => '',
			'shell_generated_at' => '',
			'shell_config_hash'  => '',
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

	public function is_static_shell_active() {
		$opt = $this->get_options();
		return !empty($opt['enabled']) && !empty($opt['static_shell']) && !empty($opt['shell_path']);
	}

	public function generate_static_shell() {
		$core_opt = CRMS_Core::instance()->get_options();

		$upload_dir = wp_upload_dir();
		$shell_dir = $upload_dir['basedir'] . '/campaigns-cache';

		if (!file_exists($shell_dir)) {
			wp_mkdir_p($shell_dir);
		}

		$shell_path = $shell_dir . '/index.html';
		$html = $this->get_static_shell_template($core_opt);

		$result = file_put_contents($shell_path, $html);
		if ($result === false) {
			return new WP_Error('write_failed', 'Impossible d\'écrire le fichier statique');
		}

		$htaccess_path = $shell_dir . '/.htaccess';
		$htaccess_content = "# Campaigns Redirector - Protection\n<Files \"index.html\">\n  Require all granted\n</Files>\n";
		@file_put_contents($htaccess_path, $htaccess_content);

		$opt = $this->get_options();
		$opt['shell_path'] = $shell_path;
		$opt['shell_generated_at'] = current_time('mysql');
		$opt['shell_config_hash'] = $this->get_static_shell_config_hash($core_opt);
		$this->update_options($opt);

		return $shell_path;
	}

	public function delete_static_shell() {
		$opt = $this->get_options();

		if (!empty($opt['shell_path']) && file_exists($opt['shell_path'])) {
			@unlink($opt['shell_path']);
		}

		$opt['shell_path'] = '';
		$opt['shell_generated_at'] = '';
		$opt['shell_config_hash'] = '';
		$this->update_options($opt);
	}

	public function get_static_shell_config_hash($core_opt = null) {
		if ($core_opt === null) {
			$core_opt = CRMS_Core::instance()->get_options();
		}
		$payload = [
			'matomo_url' => $core_opt['matomo_url'] ?? '',
			'site_id'    => $core_opt['site_id'] ?? '',
			'mode'       => $core_opt['mode'] ?? '',
			'logo_url'   => $core_opt['logo_url'] ?? '',
		];
		return sha1(wp_json_encode($payload));
	}

	public function is_static_shell_stale() {
		$opt = $this->get_options();
		if (empty($opt['shell_path']) || !file_exists($opt['shell_path'])) return false;
		return empty($opt['shell_config_hash']) || $opt['shell_config_hash'] !== $this->get_static_shell_config_hash();
	}

	private function get_static_shell_template($core_opt) {
		$matomo_url = rtrim((string)$core_opt['matomo_url'], '/') . '/';
		$site_id = (string)$core_opt['site_id'];
		$mode = $core_opt['mode'];
		$logo_url = $core_opt['logo_url'];

		$logo_attr = esc_attr($logo_url);
		$matomo_js = esc_js($matomo_url);
		$site_js = esc_js($site_id);

		ob_start();
?>
<!doctype html>
<html lang="fr">
<head>
	<meta charset="utf-8">
	<title>Redirection…</title>
	<meta name="robots" content="noindex,nofollow">
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
		var MATOMO_BASE = "<?php echo $matomo_js; ?>";
		var SITE_ID = "<?php echo $site_js; ?>";
		var ACTION = "Campaigns redirect";
		var REDIRECT_DELAY = 150;

		function qsRaw() { return (window.location.search || "").replace(/^\?/, ""); }

		function extractTargetRaw() {
			var qs = qsRaw();
			var match = /(^|&)target=/.exec(qs);
			if (!match) return "";
			var tail = qs.substring(match.index + match[0].length);

			// Cut at pk_* if present after target
			var needles = ["&pk_campaign=","&pk_source=","&pk_medium=","&pk_content=","&pk_keyword="];
			var cut = -1;
			for (var i=0;i<needles.length;i++){
				var p = tail.indexOf(needles[i]);
				if (p !== -1) cut = (cut === -1) ? p : Math.min(cut, p);
			}
			if (cut !== -1) tail = tail.substring(0, cut);

			return tail;
		}

		function safeDecodeURIComponent(s) { try { return decodeURIComponent(s); } catch(e) { return s; } }

		function normalizeTarget(raw) {
			if (!raw) return "";
			raw = raw.replace(/&amp;/g, "&").trim();
			var tries = [raw, safeDecodeURIComponent(raw), safeDecodeURIComponent(safeDecodeURIComponent(raw))];
			for (var i=0;i<tries.length;i++){
				var t = (tries[i] || "").trim().replace(/ /g, "%20");
				if (/^http:\/\//i.test(t)) t = "https://" + t.substring(7);
				if (/^https:\/\//i.test(t)) return t;
			}
			return "";
		}

		function buildCleanPkUrl() {
			var base = window.location.origin + "/<?php echo CRMS_PAGE_SLUG; ?>";
			var params = new URLSearchParams(window.location.search);
			params.delete("target");
			var out = new URLSearchParams();
			["pk_campaign","pk_source","pk_medium","pk_content","pk_keyword"].forEach(function(k){
				var v = params.get(k);
				if (v) out.set(k, v);
			});
			var q = out.toString();
			return q ? (base + "?" + q) : base;
		}

		function buildMatomoHitUrl() {
			var u = MATOMO_BASE.replace(/\/?$/, "/") + "matomo.php";
			var pageUrl = buildCleanPkUrl();
			var qs = "idsite=" + encodeURIComponent(SITE_ID)
				+ "&rec=1"
				+ "&action_name=" + encodeURIComponent(ACTION)
				+ "&url=" + encodeURIComponent(pageUrl)
				+ "&rand=" + Math.floor(Math.random() * 1e9);
			return u + "?" + qs;
		}

		function fireMatomo() {
			var url = buildMatomoHitUrl();
			if (navigator.sendBeacon) { try { navigator.sendBeacon(url, ""); return true; } catch(e) {} }
			if (window.fetch) { try { fetch(url, {method:"GET", mode:"no-cors", keepalive:true, credentials:"omit"}); return true; } catch(e) {} }
			try { (new Image()).src = url; return true; } catch(e) {}
			return false;
		}

		var target = normalizeTarget(extractTargetRaw());
		if (!target) { window.location.replace("/"); return; }

		fireMatomo();
		setTimeout(function(){ window.location.replace(target); }, REDIRECT_DELAY);
		setTimeout(function(){ window.location.replace(target); }, 900);
	})();
	</script>

	<noscript>
		<p>Redirection en cours… Si vous n'êtes pas redirigé automatiquement, <a href="/">cliquez ici</a>.</p>
	</noscript>
</body>
</html>
<?php
		return ob_get_clean();
	}

	public function get_nginx_instructions() {
		$opt = $this->get_options();
		if (empty($opt['shell_path'])) return '';

		$relative_path = str_replace(ABSPATH, '/', $opt['shell_path']);
		return "# Campaigns Redirector - Static Shell\nlocation = /" . CRMS_PAGE_SLUG . " {\n    try_files " . $relative_path . " /index.php\$is_args\$args;\n}";
	}
}
