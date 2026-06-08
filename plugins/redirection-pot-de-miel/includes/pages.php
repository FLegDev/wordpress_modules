<?php
// Création des pages à l'activation du plugin
register_activation_hook(__FILE__, 'rpm_create_redirection_pages');

function rpm_create_redirection_pages() {
    $pages = [
        'banniere-haute' => 'Bannière Haute',
        'article-1' => 'Article 1',
        'article-2' => 'Article 2',
        'banniere-mediane' => 'Bannière Médiane',
        'banniere-inferieure' => 'Bannière Inférieure',
        'footer' => 'Footer',
    ];

    foreach ($pages as $slug => $title) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if (!$page) {
            wp_insert_post([
                'post_title' => $title,
                'post_name' => $slug,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_content' => '<!-- Page gérée par Redirection Pot de Miel -->'
            ]);
        }
    }
}

// Suppression des pages à la désinstallation du plugin
register_uninstall_hook(__FILE__, 'rpm_delete_redirection_pages');

function rpm_delete_redirection_pages() {
    $slugs = rpm_get_page_slugs();
    foreach ($slugs as $slug) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if ($page) {
            wp_delete_post($page->ID, true);
        }
    }
}
