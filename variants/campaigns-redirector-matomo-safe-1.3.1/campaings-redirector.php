<?php
/**
 * Plugin Name: Campaigns Redirector (Matomo-safe)
 * Description: Intercepte la page /campaigns, envoie un hit Matomo (pk_*) puis redirige côté navigateur vers ?target=… après un court délai. HTTPS uniquement.
 * Version: 1.3.1
 * Author: FLegDev + Assistant
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) { exit; }

class Campaigns_Redirector_PageOnly {
	const PAGE_SLUG = 'campaigns';

	public static function instance() {
		static $inst = null; return $inst ?: $inst = new self();
	}

	private function __construct() {
		// Très tôt pour devancer d'éventuelles redirections d'autres plugins
		add_action('template_redirect', [$this, 'maybe_intercept'], 0);
	}

	public function maybe_intercept() {
		if (!function_exists('is_page') || !is_page(self::PAGE_SLUG)) return;

		// Empêche cache & indexation
		nocache_headers();
		header('X-Robots-Tag: noindex, nofollow', true);

		$target = isset($_GET['target']) ? $_GET['target'] : '';
		if (!$target) return $this->safe_home_redirect();

		$decoded = $target;
		// double-encoding fréquent
		if (strpos($decoded, '%') !== false) $decoded = rawurldecode($decoded);
		$decoded = trim($decoded);

		// 🔒 HTTPS obligatoire
		if (!preg_match('#^https://#i', $decoded)) return $this->safe_home_redirect();

		// URL valide
		if (!filter_var($decoded, FILTER_VALIDATE_URL)) return $this->safe_home_redirect();

		// Anti-boucle (évite /campaigns/?target=<url courante>)
		$current_url = home_url(add_query_arg(null, null));
		if (rtrim($decoded, '/?#') === rtrim($current_url, '/?#')) return $this->safe_home_redirect();

		// Récup Matomo (via constantes OU filtres) — valeurs par défaut fournies par le client
		$defaultTracker = 'https://matomo.parresia.fr/';
		$defaultSiteId  = '10';
		$trackerUrl = trailingslashit( defined('CAMPAIGNS_REDIRECTOR_MATOMO_URL') ? CAMPAIGNS_REDIRECTOR_MATOMO_URL : apply_filters('campaigns_redirector_matomo_url', $defaultTracker) );
		$siteId     = defined('CAMPAIGNS_REDIRECTOR_MATOMO_SITE_ID') ? CAMPAIGNS_REDIRECTOR_MATOMO_SITE_ID : apply_filters('campaigns_redirector_matomo_site_id', $defaultSiteId);

		// Prépare le rendu HTML (page tampon)
		$target_attr  = esc_attr($decoded);
		$target_js    = esc_js($decoded);
		$matomo_url   = esc_js($trackerUrl);
		$site_id_js   = esc_js($siteId);
		$action_name  = 'Campaigns redirect';
		$noscript_img = esc_attr($trackerUrl . 'matomo.php?idsite=' . rawurlencode($siteId) . '&rec=1&action_name=' . rawurlencode($action_name) . '&url=' . rawurlencode($current_url));

		// Entête content-type avant toute sortie
		if (!headers_sent()) {
			header('Content-Type: text/html; charset=utf-8');
		}

		?>
<!doctype html>
<html lang="fr">
<head>
	<meta charset="utf-8">
	<title>Redirection…</title>
	<meta name="robots" content="noindex,nofollow">
	<meta http-equiv="refresh" content="3;url=<?php echo $target_attr; ?>"><!-- fallback noscript -->
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<style>
		html,body{height:100%;margin:0}
		body{display:flex;align-items:center;justify-content:center;font:16px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Arial}
		.card{max-width:600px;padding:24px;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.06)}
		.muted{opacity:.7;font-size:14px;margin-top:8px}
	</style>
</head>
<body>
	<div class="card">
		<p>Un instant, nous enregistrons votre clic de campagne puis nous vous redirigeons…</p>
		<p class="muted">Si rien ne se passe, <a href="<?php echo $target_attr; ?>">cliquez ici</a>.</p>
	</div>

	<script>
		// Matomo – envoi via Beacon pour ne pas perdre le hit pendant la navigation
		var _paq = window._paq = window._paq || [];
		_paq.push(['setRequestMethod', 'beacon']);
		_paq.push(['enableLinkTracking']);
		_paq.push(['trackPageView']);

		(function() {
			var u = "<?php echo $matomo_url; ?>";
			_paq.push(['setTrackerUrl', u + 'matomo.php']);
			_paq.push(['setSiteId', '<?php echo $site_id_js; ?>']);
			var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
			g.async = true; g.src = u + 'matomo.js'; s.parentNode.insertBefore(g,s);
		})();

		// Quand Matomo est initialisé, mini délai puis redirection
		_paq.push([function() {
			setTimeout(function() {
				window.location.replace("<?php echo $target_js; ?>");
			}, 200);
		}]);

		// Fallback dur après 1500 ms (bloqueurs / cas extrêmes)
		setTimeout(function() {
			window.location.replace("<?php echo $target_js; ?>");
		}, 1500);
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

	private function safe_home_redirect() {
		// Redirection rapide mais propre quand target est absent/incorrect
		nocache_headers();
		header('X-Robots-Tag: noindex, nofollow', true);
		wp_safe_redirect(home_url('/'), 302);
		exit;
	}
}
Campaigns_Redirector_PageOnly::instance();
