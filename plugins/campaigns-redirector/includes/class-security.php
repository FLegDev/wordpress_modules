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
			'block_blank_ua'   => true,
			'scanner_ua_patterns' => implode("\n", self::default_scanner_ua_patterns()),
			'automation_ua_patterns' => implode("\n", self::default_automation_ua_patterns()),

			// Open redirect hardening
			'enable_allowlist' => false,
			'allowlist_hosts'  => "www.bgds.fr\nbgds.fr",

			// Anti-burst (reduces CPU spikes during NL sends)
			'burst_protection' => true,
			'min_interval_ms'  => 800,
			'burst_ban_ttl'    => 300,
		];
	}

	private static function default_scanner_ua_patterns() {
		return [
			'safelinks',
			'microsoft defender',
			'mdatp',
			'microsoft office',
			'msoffice',
			'outlook',
			'proofpoint',
			'urldefense',
			'mimecast',
			'mimecastprotect',
			'barracuda',
			'symantec',
			'broadcom',
			'messagelabs',
			'trendmicro',
			'trend micro',
			'forcepoint',
			'ironport',
			'cisco',
			'fortimail',
			'fortinet',
			'sophos',
			'bitdefender',
			'checkpoint',
			'check point',
			'fireeye',
			'mandiant',
			'zscaler',
			'netskope',
			'palo alto',
			'urlscan',
			'url scan',
			'phish',
			'phishing',
			'malware',
			'sandbox',
			'detonation',
			'googleimageproxy',
			'google-inspectiontool',
			'google-safety',
			'yahoo! slurp',
			'yahoo mail proxy',
			'applebot',
			'mailprivacy',
			'mail privacy',
			'slackbot-linkexpanding',
			'discordbot',
			'facebookexternalhit',
			'linkedinbot',
		];
	}

	private static function default_automation_ua_patterns() {
		return [
			'curl',
			'wget',
			'python-requests',
			'python-urllib',
			'go-http-client',
			'java/',
			'okhttp',
			'httpclient',
			'libwww-perl',
			'perl',
			'php/',
			'node-fetch',
			'axios',
			'aiohttp',
			'scrapy',
			'http.rb',
			'headlesschrome',
			'phantomjs',
			'playwright',
			'puppeteer',
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
		$why = '';
		return !$this->is_suspicious_scanner($why);
	}

	private function is_suspicious_scanner(&$why = '') {
		$opt = $this->get_options();
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
		if ($method !== 'GET') {
			$why = 'method:' . strtolower($method);
			return true;
		}

		if ($this->has_suspicious_fetch_header($why)) {
			return true;
		}

		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
		if (trim($ua) === '') {
			if (!empty($opt['block_blank_ua'])) {
				$why = 'ua:blank';
				return true;
			}
			return false;
		}
		$ua_l = strtolower($ua);

		$scanner_match = '';
		if ($this->matches_patterns($ua_l, $this->get_patterns_from_option('scanner_ua_patterns', self::default_scanner_ua_patterns()), $scanner_match)) {
			$why = 'ua_scanner:' . $scanner_match;
			return true;
		}

		$automation_match = '';
		if ($this->matches_patterns($ua_l, $this->get_patterns_from_option('automation_ua_patterns', self::default_automation_ua_patterns()), $automation_match)) {
			$why = 'ua_automation:' . $automation_match;
			return true;
		}

		return false;
	}

	private function has_suspicious_fetch_header(&$why = '') {
		$headers = [
			'HTTP_PURPOSE' => 'purpose',
			'HTTP_X_PURPOSE' => 'x-purpose',
			'HTTP_SEC_PURPOSE' => 'sec-purpose',
			'HTTP_X_MOZ' => 'x-moz',
		];
		$markers = ['prefetch', 'prerender', 'preview'];

		foreach ($headers as $server_key => $label) {
			if (empty($_SERVER[$server_key])) continue;
			$value = strtolower((string) wp_unslash($_SERVER[$server_key]));
			foreach ($markers as $marker) {
				if (strpos($value, $marker) !== false) {
					$why = 'header:' . $label . ':' . $marker;
					return true;
				}
			}
		}

		return false;
	}

	private function get_patterns_from_option($option_key, array $fallback) {
		$opt = $this->get_options();
		if (!array_key_exists($option_key, $opt)) {
			return $fallback;
		}

		$raw = $opt[$option_key];
		$lines = is_array($raw) ? $raw : preg_split('/\R+/', (string)$raw);
		$patterns = [];

		foreach ((array)$lines as $line) {
			$pattern = strtolower(trim((string)$line));
			if ($pattern === '' || strpos($pattern, '#') === 0) continue;
			$patterns[] = $pattern;
		}

		return array_values(array_unique($patterns));
	}

	private function matches_patterns($haystack, array $patterns, &$match = '') {
		foreach ($patterns as $pattern) {
			if ($pattern !== '' && strpos($haystack, $pattern) !== false) {
				$match = $pattern;
				return true;
			}
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
