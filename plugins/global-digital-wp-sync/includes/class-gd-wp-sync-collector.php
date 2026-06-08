<?php

if (!defined('ABSPATH')) {
    exit;
}

class GD_WP_Sync_Collector
{
    public function collect($start_date, $end_date, $settings)
    {
        $article_post_types = $this->csv($settings['article_post_types']);
        $article_statuses = $this->csv($settings['article_post_statuses']);
        $classified_post_types = $this->csv($settings['classified_post_types']);
        $classified_statuses = $this->csv($settings['classified_post_statuses']);

        $metrics = array(
            'wp_articles_published' => array(
                'label' => 'Articles publies sur la periode',
                'source' => 'wordpress',
                'value' => $this->count_posts($article_post_types, $article_statuses, $start_date, $end_date),
                'type' => 'integer',
            ),
            'wp_articles_datawall' => array(
                'label' => 'Articles sous datawall sur la periode',
                'source' => 'wordpress',
                'value' => $this->count_flagged_posts(
                    $article_post_types,
                    $article_statuses,
                    $start_date,
                    $end_date,
                    $this->csv($settings['datawall_meta_keys']),
                    $this->csv($settings['datawall_meta_values']),
                    $this->csv($settings['datawall_taxonomies']),
                    $this->csv($settings['datawall_term_slugs'])
                ),
                'type' => 'integer',
            ),
            'wp_articles_paywall' => array(
                'label' => 'Articles sous paywall sur la periode',
                'source' => 'wordpress',
                'value' => $this->count_flagged_posts(
                    $article_post_types,
                    $article_statuses,
                    $start_date,
                    $end_date,
                    $this->csv($settings['paywall_meta_keys']),
                    $this->csv($settings['paywall_meta_values']),
                    $this->csv($settings['paywall_taxonomies']),
                    $this->csv($settings['paywall_term_slugs'])
                ),
                'type' => 'integer',
            ),
            'wp_existing_accounts' => array(
                'label' => 'Comptes existants',
                'source' => 'wordpress',
                'value' => $this->count_users(),
                'type' => 'integer',
            ),
            'wp_classified_ads_count' => array(
                'label' => 'Nombre de petites annonces',
                'source' => 'wordpress',
                'value' => $this->count_posts($classified_post_types, $classified_statuses, $start_date, $end_date),
                'type' => 'integer',
            ),
            'wp_classified_ads_revenue' => array(
                'label' => 'Chiffre affaires Petites Annonces sur la periode',
                'source' => 'wordpress',
                'value' => $this->sum_post_meta(
                    $classified_post_types,
                    $classified_statuses,
                    $start_date,
                    $end_date,
                    $this->csv($settings['classified_revenue_meta_keys'])
                ),
                'type' => 'decimal',
            ),
        );

        $metrics = apply_filters('gd_wp_sync_metrics', $metrics, $start_date, $end_date, $settings);

        return array(
            'source' => 'wordpress',
            'site' => array(
                'name' => get_bloginfo('name'),
                'url' => home_url('/'),
                'timezone' => wp_timezone_string(),
            ),
            'period' => array(
                'start_date' => $start_date,
                'end_date' => $end_date,
            ),
            'generated_at' => gmdate('c'),
            'plugin' => array(
                'name' => 'global-digital-wp-sync',
                'version' => defined('GD_WP_SYNC_VERSION') ? GD_WP_SYNC_VERSION : '0.0.0',
            ),
            'metrics' => $metrics,
        );
    }

    private function count_posts($post_types, $statuses, $start_date, $end_date)
    {
        if (empty($post_types) || empty($statuses)) {
            return 0;
        }

        $query = new WP_Query(array(
            'post_type' => $post_types,
            'post_status' => $statuses,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'date_query' => array(
                array(
                    'column' => 'post_date',
                    'after' => $start_date . ' 00:00:00',
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true,
                ),
            ),
        ));

        return (int) $query->found_posts;
    }

    private function count_users()
    {
        $counts = count_users();

        if (isset($counts['total_users'])) {
            return (int) $counts['total_users'];
        }

        return 0;
    }

    private function count_flagged_posts($post_types, $statuses, $start_date, $end_date, $meta_keys, $truthy_values, $taxonomies, $term_slugs)
    {
        $ids = array();

        foreach ($this->find_posts_by_meta($post_types, $statuses, $start_date, $end_date, $meta_keys, $truthy_values) as $id) {
            $ids[(int) $id] = true;
        }

        foreach ($this->find_posts_by_terms($post_types, $statuses, $start_date, $end_date, $taxonomies, $term_slugs) as $id) {
            $ids[(int) $id] = true;
        }

        return count($ids);
    }

    private function find_posts_by_meta($post_types, $statuses, $start_date, $end_date, $meta_keys, $truthy_values)
    {
        global $wpdb;

        if (empty($post_types) || empty($statuses) || empty($meta_keys)) {
            return array();
        }

        $post_type_placeholders = $this->placeholders($post_types);
        $status_placeholders = $this->placeholders($statuses);
        $meta_key_placeholders = $this->placeholders($meta_keys);
        $params = array_merge($post_types, $statuses, array($start_date . ' 00:00:00', $end_date . ' 23:59:59'), $meta_keys);
        $truthy_sql = '';

        if (!empty($truthy_values)) {
            $truthy_sql = ' AND LOWER(pm.meta_value) IN (' . $this->placeholders($truthy_values) . ')';
            $params = array_merge($params, array_map('strtolower', $truthy_values));
        } else {
            $truthy_sql = " AND pm.meta_value NOT IN ('', '0', 'false', 'no', 'off')";
        }

        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type IN ($post_type_placeholders)
                AND p.post_status IN ($status_placeholders)
                AND p.post_date BETWEEN %s AND %s
                AND pm.meta_key IN ($meta_key_placeholders)
                $truthy_sql
        ";

        return $wpdb->get_col($wpdb->prepare($sql, $params));
    }

    private function find_posts_by_terms($post_types, $statuses, $start_date, $end_date, $taxonomies, $term_slugs)
    {
        global $wpdb;

        if (empty($post_types) || empty($statuses) || empty($taxonomies) || empty($term_slugs)) {
            return array();
        }

        $post_type_placeholders = $this->placeholders($post_types);
        $status_placeholders = $this->placeholders($statuses);
        $taxonomy_placeholders = $this->placeholders($taxonomies);
        $term_placeholders = $this->placeholders($term_slugs);
        $params = array_merge($post_types, $statuses, array($start_date . ' 00:00:00', $end_date . ' 23:59:59'), $taxonomies, $term_slugs);

        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE p.post_type IN ($post_type_placeholders)
                AND p.post_status IN ($status_placeholders)
                AND p.post_date BETWEEN %s AND %s
                AND tt.taxonomy IN ($taxonomy_placeholders)
                AND t.slug IN ($term_placeholders)
        ";

        return $wpdb->get_col($wpdb->prepare($sql, $params));
    }

    private function sum_post_meta($post_types, $statuses, $start_date, $end_date, $meta_keys)
    {
        global $wpdb;

        if (empty($post_types) || empty($statuses) || empty($meta_keys)) {
            return 0.0;
        }

        $post_type_placeholders = $this->placeholders($post_types);
        $status_placeholders = $this->placeholders($statuses);
        $meta_key_placeholders = $this->placeholders($meta_keys);
        $params = array_merge($post_types, $statuses, array($start_date . ' 00:00:00', $end_date . ' 23:59:59'), $meta_keys);

        $sql = "
            SELECT SUM(CAST(REPLACE(pm.meta_value, ',', '.') AS DECIMAL(20, 4)))
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type IN ($post_type_placeholders)
                AND p.post_status IN ($status_placeholders)
                AND p.post_date BETWEEN %s AND %s
                AND pm.meta_key IN ($meta_key_placeholders)
                AND pm.meta_value REGEXP '^-?[0-9]+([,.][0-9]+)?$'
        ";

        return round((float) $wpdb->get_var($wpdb->prepare($sql, $params)), 2);
    }

    private function csv($value)
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\r\n,]+/', (string) $value);
        }

        $items = array_map('trim', $items);
        $items = array_filter($items, function ($item) {
            return '' !== $item;
        });

        return array_values(array_unique($items));
    }

    private function placeholders($items)
    {
        return implode(',', array_fill(0, count($items), '%s'));
    }
}
