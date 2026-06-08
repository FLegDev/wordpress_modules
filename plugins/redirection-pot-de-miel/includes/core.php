<?php
// Redirection principale
add_action('template_redirect', 'rpm_redirection_via_param');

function rpm_redirection_via_param() {
    $slugs = rpm_get_page_slugs();

    if (is_page($slugs)) {
        if (isset($_GET['redirect'])) {
            $url_destination = urldecode($_GET['redirect']);

            if (filter_var($url_destination, FILTER_VALIDATE_URL)) {
                if (!isset($_GET['robot_check']) || empty($_GET['robot_check'])) {
                    get_header();
                    echo '<main style="text-align: center; margin-top: 50px;">';
                    echo '<h1>Redirection en cours...</h1>';
                    echo '<p>Vous allez être redirigé dans un instant.</p>';
                    echo '<script>
                        setTimeout(function() {
                            window.location.href = "' . esc_url($url_destination) . '";
                        }, 300);
                    </script>';
                    echo '</main>';
                    get_footer();
                    exit;
                } else {
                    wp_die('Redirection bloquée (pot de miel activé).');
                }
            } else {
                wp_die('URL invalide ou manquante.');
            }
        } else {
            wp_die('Paramètre de redirection manquant.');
        }
    }
}

// Liste des slugs partagée
function rpm_get_page_slugs() {
    return ['banniere-haute', 'article-1', 'article-2', 'banniere-mediane', 'banniere-inferieure', 'footer'];
}
