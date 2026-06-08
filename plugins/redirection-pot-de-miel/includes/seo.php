<?php
// Meta noindex
add_action('wp_head', 'rpm_add_noindex_meta');
function rpm_add_noindex_meta() {
    $slugs = rpm_get_page_slugs();
    if (is_page($slugs)) {
        echo '<meta name="robots" content="noindex, nofollow">';
    }
}

// Exclusion du sitemap Yoast SEO
add_filter('wpseo_exclude_from_sitemap_by_post_ids', 'rpm_exclude_pages_from_sitemap');
function rpm_exclude_pages_from_sitemap($excluded_posts) {
    $slugs = rpm_get_page_slugs();
    foreach ($slugs as $slug) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if ($page) {
            $excluded_posts[] = $page->ID;
        }
    }
    return $excluded_posts;
}
