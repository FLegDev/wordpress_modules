<?php

if (!defined('ABSPATH')) {
    exit;
}

class GD_WP_Sync_Advanced_Ads_Collector
{
    public function collect($start_date, $end_date, $settings)
    {
        $impressions = $this->collect_event(
            'impressions',
            $start_date,
            $end_date,
            isset($settings['advanced_ads_impressions_table']) ? $settings['advanced_ads_impressions_table'] : ''
        );
        $clicks = $this->collect_event(
            'clicks',
            $start_date,
            $end_date,
            isset($settings['advanced_ads_clicks_table']) ? $settings['advanced_ads_clicks_table'] : ''
        );

        $ads = $this->merge_event_rows($impressions['rows'], $clicks['rows']);
        $totals = array(
            'impressions' => $this->sum_event_rows($impressions['rows']),
            'clicks' => $this->sum_event_rows($clicks['rows']),
        );
        $totals['ctr'] = $this->ctr($totals['clicks'], $totals['impressions']);

        $payload = array(
            'source' => 'wordpress_advanced_ads',
            'site' => array(
                'name' => get_bloginfo('name'),
                'url' => home_url('/'),
                'timezone' => wp_timezone_string(),
                'blog_id' => function_exists('get_current_blog_id') ? get_current_blog_id() : 1,
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
            'advanced_ads' => array(
                'available' => $impressions['available'] || $clicks['available'],
                'totals' => $totals,
                'ads' => $ads,
                'detected' => array(
                    'impressions' => $impressions['detected'],
                    'clicks' => $clicks['detected'],
                ),
                'errors' => array_values(array_filter(array_merge($impressions['errors'], $clicks['errors']))),
            ),
        );

        return apply_filters('gd_wp_sync_advanced_ads_payload', $payload, $settings);
    }

    private function collect_event($event, $start_date, $end_date, $configured_table)
    {
        $table = $this->resolve_table($event, $configured_table);
        $detected = array(
            'table' => $table,
            'ad_id_column' => null,
            'date_column' => null,
            'count_column' => null,
            'period_filtered' => false,
            'count_mode' => 'rows',
        );

        if (!$table) {
            return array(
                'available' => false,
                'rows' => array(),
                'detected' => $detected,
                'errors' => array(sprintf('Advanced Ads %s table not found.', $event)),
            );
        }

        $columns = $this->describe_table($table);

        if (empty($columns)) {
            return array(
                'available' => false,
                'rows' => array(),
                'detected' => $detected,
                'errors' => array(sprintf('Unable to inspect Advanced Ads %s table.', $event)),
            );
        }

        $ad_id_column = $this->find_column($columns, $this->ad_id_candidates());
        $date_column = $this->find_column($columns, $this->date_candidates());
        $count_column = $this->find_count_column($columns, $event);

        $detected['ad_id_column'] = $ad_id_column;
        $detected['date_column'] = $date_column;
        $detected['count_column'] = $count_column;
        $detected['period_filtered'] = (bool) $date_column;
        $detected['count_mode'] = $count_column ? 'sum_column' : 'rows';

        if (!$date_column) {
            return array(
                'available' => true,
                'rows' => array(),
                'detected' => $detected,
                'errors' => array(sprintf('No date column found in Advanced Ads %s table; period cannot be filtered.', $event)),
            );
        }

        return array(
            'available' => true,
            'rows' => $this->query_event_rows($table, $columns, $ad_id_column, $date_column, $count_column, $start_date, $end_date),
            'detected' => $detected,
            'errors' => array(),
        );
    }

    private function resolve_table($event, $configured_table)
    {
        global $wpdb;

        $candidates = array();
        $configured_table = trim((string) $configured_table);

        if ('' !== $configured_table) {
            $candidates[] = $configured_table;

            if (0 !== strpos($configured_table, $wpdb->prefix)) {
                $candidates[] = $wpdb->prefix . $configured_table;
            }
        }

        $default_candidates = array(
            'impressions' => array(
                'advads_impressions',
                'advanced_ads_impressions',
                'advads_tracking_impressions',
                'advanced_ads_tracking_impressions',
            ),
            'clicks' => array(
                'advads_clicks',
                'advanced_ads_clicks',
                'advads_tracking_clicks',
                'advanced_ads_tracking_clicks',
            ),
        );

        $default_candidates = apply_filters('gd_wp_sync_advanced_ads_table_candidates', $default_candidates, $event);

        if (isset($default_candidates[$event]) && is_array($default_candidates[$event])) {
            foreach ($default_candidates[$event] as $candidate) {
                $candidate = trim((string) $candidate);

                if ('' === $candidate) {
                    continue;
                }

                $candidates[] = 0 === strpos($candidate, $wpdb->prefix) ? $candidate : $wpdb->prefix . $candidate;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $candidate = $this->sanitize_identifier($candidate);

            if ($candidate && $this->table_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function table_exists($table)
    {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));

        return $found === $table;
    }

    private function describe_table($table)
    {
        global $wpdb;

        $table = $this->sanitize_identifier($table);

        if (!$table) {
            return array();
        }

        $rows = $wpdb->get_results('DESCRIBE ' . $this->quote_identifier($table));
        $columns = array();

        foreach ($rows as $row) {
            if (isset($row->Field)) {
                $columns[$row->Field] = isset($row->Type) ? strtolower((string) $row->Type) : '';
            }
        }

        return $columns;
    }

    private function query_event_rows($table, $columns, $ad_id_column, $date_column, $count_column, $start_date, $end_date)
    {
        global $wpdb;

        $period = $this->period_sql($columns, $date_column, $start_date, $end_date);
        $params = $period['params'];
        $ad_select = $ad_id_column ? $this->quote_identifier($ad_id_column) : 'NULL';
        $count_select = $count_column ? $this->sum_expression($count_column) : 'COUNT(*)';
        $group_by = $ad_id_column ? ' GROUP BY ' . $this->quote_identifier($ad_id_column) : '';

        $sql = sprintf(
            'SELECT %s AS ad_id, %s AS total FROM %s WHERE %s%s',
            $ad_select,
            $count_select,
            $this->quote_identifier($table),
            $period['sql'],
            $group_by
        );

        $prepared = $wpdb->prepare($sql, $params);
        $results = $wpdb->get_results($prepared);
        $rows = array();

        foreach ($results as $result) {
            $ad_id = isset($result->ad_id) && null !== $result->ad_id ? (string) $result->ad_id : '_total';
            $rows[$ad_id] = (int) round((float) $result->total);
        }

        return $rows;
    }

    private function period_sql($columns, $date_column, $start_date, $end_date)
    {
        $quoted = $this->quote_identifier($date_column);
        $type = isset($columns[$date_column]) ? $columns[$date_column] : '';

        if ($this->is_numeric_type($type)) {
            $unix_start = strtotime($start_date . ' 00:00:00');
            $unix_end = strtotime($end_date . ' 23:59:59');
            $ymd_start = (int) str_replace('-', '', $start_date);
            $ymd_end = (int) str_replace('-', '', $end_date);

            return array(
                'sql' => '(' . $quoted . ' BETWEEN %d AND %d OR ' . $quoted . ' BETWEEN %d AND %d)',
                'params' => array($unix_start, $unix_end, $ymd_start, $ymd_end),
            );
        }

        return array(
            'sql' => $quoted . ' BETWEEN %s AND %s',
            'params' => array($start_date . ' 00:00:00', $end_date . ' 23:59:59'),
        );
    }

    private function sum_expression($column)
    {
        $quoted = $this->quote_identifier($column);

        return "SUM(CASE WHEN {$quoted} REGEXP '^-?[0-9]+([,.][0-9]+)?$' THEN CAST(REPLACE({$quoted}, ',', '.') AS DECIMAL(20, 4)) ELSE 0 END)";
    }

    private function merge_event_rows($impressions, $clicks)
    {
        $ad_ids = array_unique(array_merge(array_keys($impressions), array_keys($clicks)));
        sort($ad_ids);
        $ads = array();

        foreach ($ad_ids as $ad_id) {
            if ('_total' === $ad_id) {
                continue;
            }

            $impression_count = isset($impressions[$ad_id]) ? (int) $impressions[$ad_id] : 0;
            $click_count = isset($clicks[$ad_id]) ? (int) $clicks[$ad_id] : 0;
            $post = ctype_digit((string) $ad_id) ? get_post((int) $ad_id) : null;

            $ads[] = array(
                'ad_id' => ctype_digit((string) $ad_id) ? (int) $ad_id : $ad_id,
                'title' => $post ? get_the_title($post) : '',
                'status' => $post ? $post->post_status : '',
                'post_type' => $post ? $post->post_type : '',
                'impressions' => $impression_count,
                'clicks' => $click_count,
                'ctr' => $this->ctr($click_count, $impression_count),
            );
        }

        return $ads;
    }

    private function sum_event_rows($rows)
    {
        if (isset($rows['_total'])) {
            return (int) $rows['_total'];
        }

        return (int) array_sum($rows);
    }

    private function ctr($clicks, $impressions)
    {
        $impressions = (int) $impressions;

        if ($impressions <= 0) {
            return 0.0;
        }

        return round(((int) $clicks / $impressions) * 100, 4);
    }

    private function find_count_column($columns, $event)
    {
        $event_candidates = 'impressions' === $event
            ? array('impressions', 'impression_count', 'views', 'view_count', 'count', 'total', 'value')
            : array('clicks', 'click_count', 'count', 'total', 'value');

        return $this->find_column($columns, apply_filters('gd_wp_sync_advanced_ads_count_column_candidates', $event_candidates, $event));
    }

    private function find_column($columns, $candidates)
    {
        $columns_lower = array();

        foreach (array_keys($columns) as $column) {
            $columns_lower[strtolower($column)] = $column;
        }

        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string) $candidate));

            if (isset($columns_lower[$candidate])) {
                return $columns_lower[$candidate];
            }
        }

        return null;
    }

    private function ad_id_candidates()
    {
        return apply_filters('gd_wp_sync_advanced_ads_ad_id_column_candidates', array(
            'ad_id',
            'ad',
            'post_id',
            'banner_id',
            'creative_id',
            'item_id',
        ));
    }

    private function date_candidates()
    {
        return apply_filters('gd_wp_sync_advanced_ads_date_column_candidates', array(
            'timestamp',
            'time',
            'date',
            'day',
            'created_at',
            'created',
            'recorded_at',
            'datetime',
        ));
    }

    private function is_numeric_type($type)
    {
        return (bool) preg_match('/int|decimal|float|double|numeric|year/', strtolower((string) $type));
    }

    private function sanitize_identifier($identifier)
    {
        $identifier = trim((string) $identifier, " \t\n\r\0\x0B`");

        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            return null;
        }

        return $identifier;
    }

    private function quote_identifier($identifier)
    {
        $identifier = $this->sanitize_identifier($identifier);

        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
