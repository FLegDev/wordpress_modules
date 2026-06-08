<?php
	/**
	 * Plugin Name: Campaigns Redirector (HTTPS only)
	 * Description: Redirige UNIQUEMENT la page /campaigns/ vers l’URL passée en ?target=, en n’autorisant que les cibles HTTPS et en ouvrant dynamiquement l’hôte pour wp_safe_redirect().
	 * Version: 1.2.2
	 * Author: FLegDev
	 * License: GPL-2.0+
	 */

	if (!defined('ABSPATH')) { exit; }

	class Campaigns_Redirector_PageOnly {
		const PAGE_SLUG = 'campaigns';

		public static function instance() {
			static $inst = null; return $inst ?: $inst = new self();
		}
		private function __construct() {
			add_action('template_redirect', [$this, 'maybe_redirect']);
		}

		private function target_page_matches(): bool {
			$page_id = (int) apply_filters('campaigns_redirector_page_id', 0);
			if ($page_id > 0) return is_page($page_id);
			$slug = apply_filters('campaigns_redirector_page_slug', self::PAGE_SLUG);
			return is_page($slug);
		}

		public function maybe_redirect() {
			if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
			if ($_SERVER['REQUEST_METHOD'] !== 'GET') return;
			if (!$this->target_page_matches()) return;

			// target brut
			$raw = isset($_GET['target']) ? wp_unslash($_GET['target']) : '';
			if ($raw === '' || !is_string($raw)) return $this->safe_home_redirect();

			// Décodage (certaines NL encodent 2x)
			$decoded = rawurldecode($raw);
			if (strpos($decoded, '%') !== false) $decoded = rawurldecode($decoded);
			$decoded = trim($decoded);

			// 🔒 HTTPS obligatoire
			if (!preg_match('#^https://#i', $decoded)) return $this->safe_home_redirect();

			// URL valide
			if (!filter_var($decoded, FILTER_VALIDATE_URL)) return $this->safe_home_redirect();

			// Anti-boucle (évite /campaigns/?target=<url courante>)
			$current_url = home_url(add_query_arg([]));
			if (rtrim($decoded, '/?#') === rtrim($current_url, '/?#')) return $this->safe_home_redirect();

			// Autorise dynamiquement l’hôte cible pour wp_safe_redirect()
			$host = parse_url($decoded, PHP_URL_HOST);
			if ($host) {
				add_filter('allowed_redirect_hosts', function ($hosts) use ($host) {
					$hosts[] = $host;
					$home_host = parse_url(home_url(), PHP_URL_HOST);
					if ($home_host) $hosts[] = $home_host;
					return array_values(array_unique(array_filter($hosts)));
				});
			}

			// Entêtes utiles
			nocache_headers();
			header('X-Robots-Tag: noindex, nofollow', true);

			wp_safe_redirect($decoded, 302);
			exit;
		}

		private function safe_home_redirect() {
			nocache_headers();
			header('X-Robots-Tag: noindex, nofollow', true);
			wp_safe_redirect(home_url('/'), 302);
			exit;
		}
	}
	Campaigns_Redirector_PageOnly::instance();