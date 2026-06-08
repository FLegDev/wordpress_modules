<?php
/**
 * Security Module - Anti-spam, rate limiting, allowlist
 */

if (!defined('ABSPATH')) exit;

class CRMS_Security {

	const OPT_KEY = 'crms_options_security';
	const BAN_TTL_DEFAULT = 300;
	const BAN_TTL_MIN = 60;
	const BAN_TTL_MAX = 3600;

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
			'enabled'          => true,
			'scanner_block'    => true,
			'temp_ban'         => true,
			'ban_ttl'          => (string) self::BAN_TTL_DEFAULT,

			// Open redirect hardening
			'enable_allowlist' => false,
			'allowlist_hosts'  => "www.bgds.fr\nbgds.fr",

			// Anti-burst (reduces CPU spikes during NL sends)
			'burst_protection' => true,
			'min_interval_ms'  => 800,
			'burst_ban_ttl'    => 300,
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

	public function should_silent_drop($reason) {
		$r = (string)$reason;
		return (
			strpos($r, 'scanner_block:') === 0 ||
			$r === 'ip_temp_ban' ||
			$r === 'burst_rate_limit'
		);
	}

	/**
	 * Check request security
	 * @return true|string True if OK, otherwise reason
	 */
	public function check_request($params) {
		$opt = $this->get_options();
		if (empty($opt['enabled'])) return true;

		$ip = $this->get_client_ip();

		// IP ban
		if (!empty($opt['temp_ban']) && $ip) {
			if ($this->is_ip_banned($ip)) {
				return 'ip_temp_ban';
			}
		}

		// Scanner detection
		if (!empty($opt['scanner_block'])) {
			$why = '';
			if ($this->is_suspicious_scanner($why)) {
				if (!empty($opt['temp_ban']) && $ip) {
					$ttl = (int)($opt['ban_ttl'] ?? self::BAN_TTL_DEFAULT);
					$this->ban_ip($ip, $ttl);
				}
				return 'scanner_block:' . $why;
			}
		}

		// Burst protection (double clicks / scan storms)
		if (!empty($opt['burst_protection']) && $ip) {
			$min_ms = (int)($opt['min_interval_ms'] ?? 800);
			if ($min_ms < 0) $min_ms = 0;
			if ($min_ms > 5000) $min_ms = 5000;

			if ($min_ms > 0 && $this->is_burst($ip, $min_ms)) {
				if ($this->looks_like_human_click()) return true;
				return 'burst_rate_limit';
			}
		}

		return true;
	}

	public function allowlist_check($url) {
		$opt = $this->get_options();
		if (empty($opt['enable_allowlist'])) return true;

		$host = $this->normalize_host(parse_url((string)$url, PHP_URL_HOST));
		if (!$host) return false;

		$lines = preg_split('/\R+/', (string)$opt['allowlist_hosts']);
		foreach ($lines as $line) {
			if ($this->host_matches_rule($host, $line)) return true;
		}
		return false;
	}

	public function normalize_host($host) {
		$host = strtolower(trim((string)$host));
		$host = preg_replace('#^https?://#i', '', $host);
		$host = preg_replace('#[/:].*$#', '', $host);
		$host = rtrim($host, '.');
		return $host;
	}

	private function host_matches_rule($host, $rule) {
		$host = $this->normalize_host($host);
		$rule = trim((string)$rule);
		if ($host === '' || $rule === '' || strpos($rule, '#') === 0) return false;

		$wildcard = false;
		if (strpos($rule, '*.') === 0) {
			$wildcard = true;
			$rule = substr($rule, 2);
		}

		$rule = $this->normalize_host($rule);
		if ($rule === '') return false;

		if ($wildcard) {
			return $host !== $rule && substr($host, -strlen('.' . $rule)) === '.' . $rule;
		}

		if ($host === $rule) return true;
		if ($host === 'www.' . $rule) return true;
		if (strpos($rule, 'www.') === 0 && substr($rule, 4) === $host) return true;

		return false;
	}

	private function looks_like_human_click() {
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
		if ($method !== 'GET') return false;

		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string)wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

		$purpose = isset($_SERVER['HTTP_PURPOSE']) ? strtolower((string)$_SERVER['HTTP_PURPOSE']) : '';
		$xpurpose = isset($_SERVER['HTTP_X_PURPOSE']) ? strtolower((string)$_SERVER['HTTP_X_PURPOSE']) : '';
		return $purpose !== 'prefetch' && $xpurpose !== 'prefetch';
	}

	private function is_suspicious_scanner(&$why = '') {
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
		if ($method === 'HEAD') {
			$why = 'head';
			return true;
		}

		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
		if (trim($ua) === '') {
			return false;
		}

		$ua_l = strtolower($ua);

		$patterns = [
			'safelinks', 'microsoft defender', 'outlook', 'office', 'msoffice',
			'proofpoint', 'mimecast', 'barracuda', 'symantec', 'trendmicro', 'trend micro',
			'forcepoint', 'ironport', 'cisco', 'fortimail', 'sophos',
			'urlscan', 'scanner', 'security', 'spam',
		];

		foreach ($patterns as $p) {
			if (strpos($ua_l, $p) !== false) {
				$why = 'ua:' . $p;
				return true;
			}
		}

		$automation_patterns = [
			'curl', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
			'java/', 'okhttp', 'httpclient', 'libwww-perl', 'perl', 'php/',
			'node-fetch', 'axios', 'aiohttp', 'scrapy', 'http.rb',
		];

		foreach ($automation_patterns as $p) {
			if (strpos($ua_l, $p) !== false) {
				$why = 'ua_automation:' . $p;
				return true;
			}
		}

		$purpose = isset($_SERVER['HTTP_PURPOSE']) ? strtolower((string) $_SERVER['HTTP_PURPOSE']) : '';
		$xpurpose = isset($_SERVER['HTTP_X_PURPOSE']) ? strtolower((string) $_SERVER['HTTP_X_PURPOSE']) : '';
		if ($purpose === 'prefetch' || $xpurpose === 'prefetch') {
			$why = 'prefetch';
			return true;
		}

		return false;
	}

	private function get_client_ip() {
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			$ip = trim((string) wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
			if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
		}
		if (!empty($_SERVER['REMOTE_ADDR'])) {
			$ip = trim((string) wp_unslash($_SERVER['REMOTE_ADDR']));
			if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
		}
		return '';
	}

	private function ip_ban_key($ip) {
		return 'crms_ipban_' . substr(sha1((string)$ip), 0, 20);
	}

	private function is_ip_banned($ip) {
		if (!$ip) return false;
		return (bool) get_transient($this->ip_ban_key($ip));
	}

	private function ban_ip($ip, $ttl_seconds) {
		if (!$ip) return;
		$ttl = (int)$ttl_seconds;
		if ($ttl < self::BAN_TTL_MIN) $ttl = self::BAN_TTL_MIN;
		if ($ttl > self::BAN_TTL_MAX) $ttl = self::BAN_TTL_MAX;
		set_transient($this->ip_ban_key($ip), 1, $ttl);
	}

	private function is_burst($ip, $min_interval_ms) {
		$key = 'crms_last_' . substr(sha1((string)$ip), 0, 20);
		$now = (int) floor(microtime(true) * 1000);

		$last = get_transient($key);
		set_transient($key, $now, 300);

		if (!$last) return false;
		return (($now - (int)$last) < (int)$min_interval_ms);
	}
}
