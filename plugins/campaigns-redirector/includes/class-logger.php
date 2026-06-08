<?php
/**
 * Logger Module - Event logging with GDPR
 */

if (!defined('ABSPATH')) exit;

class CRMS_Logger {
	
	const OPT_KEY = 'crms_options_logger';
	const LOG_KEY = 'crms_logs';
	const LOG_MAX_DEFAULT = 200;
	
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
		
		if (get_option(self::LOG_KEY, null) === null) {
			add_option(self::LOG_KEY, [], '', false);
		}
	}
	
	public static function default_options() {
		return [
			'enabled'      => false,
			'log_failures' => true,
			'anonymize_ip' => false,
			'max_entries'  => (string) self::LOG_MAX_DEFAULT,
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
	
	public function log_event($status, $reason, $normalize_method, $target_final, $target_raw, array $params = []) {
		$opt = $this->get_options();
		
		if (!$opt['enabled']) return;
		if ($status === 'REJECT' && !$opt['log_failures']) return;
		
		$max = (int) ($opt['max_entries'] ?? self::LOG_MAX_DEFAULT);
		if ($max < 10) $max = 10;
		if ($max > 2000) $max = 2000;
		
		$logs = $this->get_logs();
		
		$ip = $this->get_client_ip();
		
		if ($opt['anonymize_ip']) {
			$ip = $this->anonymize_ip($ip);
		}
		
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
		$signals = [];
		foreach (['HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_SEC_PURPOSE', 'HTTP_X_MOZ'] as $key) {
			if (!empty($_SERVER[$key])) {
				$signals[] = strtolower(str_replace('HTTP_', '', $key)) . '=' . (string) wp_unslash($_SERVER[$key]);
			}
		}
		
		$entry = [
			'ts'               => time(),
			'ts_local'         => function_exists('wp_date') ? wp_date('Y-m-d H:i:s') : date('Y-m-d H:i:s'),
			'status'           => (string) $status,
			'reason'           => (string) $reason,
			'normalize_method' => (string) $normalize_method,
			'method'           => (string) $method,
			'signals'          => implode('; ', $signals),
			'target_final'     => (string) $target_final,
			'target_raw'       => (string) $target_raw,
			'ip'               => (string) $ip,
			'ua'               => (string) $ua,
			'pk_campaign'      => (string) ($params['pk_campaign'] ?? ''),
			'pk_source'        => (string) ($params['pk_source'] ?? ''),
			'pk_medium'        => (string) ($params['pk_medium'] ?? ''),
			'pk_content'       => (string) ($params['pk_content'] ?? ''),
			'pk_keyword'       => (string) ($params['pk_keyword'] ?? ''),
		];
		
		array_unshift($logs, $entry);
		if (count($logs) > $max) {
			$logs = array_slice($logs, 0, $max);
		}
		
		update_option(self::LOG_KEY, $logs, false);
	}
	
	public function get_logs() {
		$logs = get_option(self::LOG_KEY, []);
		return is_array($logs) ? $logs : [];
	}
	
	public function clear_logs() {
		update_option(self::LOG_KEY, [], false);
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
	
	private function anonymize_ip($ip) {
		if (!$ip) return '';
		
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return preg_replace('/\.\d+$/', '.0', $ip);
		}
		
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$parts = explode(':', $ip);
			return implode(':', array_slice($parts, 0, 3)) . '::';
		}
		
		return $ip;
	}
}
