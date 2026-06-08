<?php

if (!defined('ABSPATH')) {
    exit;
}

class GD_WP_Sync
{
    const OPTION_NAME = 'gd_wp_sync_settings';
    const LAST_RESULT_OPTION = 'gd_wp_sync_last_result';
    const CRON_HOOK = 'gd_wp_sync_daily_event';

    /**
     * @var GD_WP_Sync_Collector
     */
    private $collector;

    /**
     * @var GD_WP_Sync_Advanced_Ads_Collector
     */
    private $advanced_ads_collector;

    /**
     * @var GD_WP_Sync_API
     */
    private $api;

    /**
     * @var GD_WP_Sync_Admin|null
     */
    private $admin;

    public function init()
    {
        $this->collector = new GD_WP_Sync_Collector();
        $this->advanced_ads_collector = new GD_WP_Sync_Advanced_Ads_Collector();
        $this->api = new GD_WP_Sync_API();

        add_action(self::CRON_HOOK, array($this, 'run_cron_sync'));

        if (is_admin()) {
            $this->admin = new GD_WP_Sync_Admin($this);
            $this->admin->init();
        }

        if (defined('WP_CLI') && WP_CLI) {
            $this->register_cli_command();
        }
    }

    public static function activate()
    {
        $settings = self::get_settings();

        if (!empty($settings['schedule_enabled'])) {
            self::schedule_cron();
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function get_default_settings()
    {
        return array(
            'api_endpoint' => '',
            'api_token' => '',
            'auth_header_name' => 'Authorization',
            'auth_header_prefix' => 'Bearer',
            'request_timeout' => 20,
            'advanced_ads_enabled' => 0,
            'django_stats_api_endpoint' => '',
            'django_stats_api_token' => '',
            'django_stats_auth_header_name' => 'Authorization',
            'django_stats_auth_header_prefix' => 'Bearer',
            'advanced_ads_impressions_table' => '',
            'advanced_ads_clicks_table' => '',
            'schedule_enabled' => 0,
            'schedule_period' => 'previous_day',
            'article_post_types' => 'post',
            'article_post_statuses' => 'publish',
            'paywall_meta_keys' => '_paywall,paywall,is_paywalled,has_paywall',
            'paywall_meta_values' => '1,true,yes,on,paid,paywall',
            'paywall_taxonomies' => 'category,post_tag',
            'paywall_term_slugs' => 'paywall,payant,abonne,abonnes',
            'datawall_meta_keys' => '_datawall,datawall,is_datawalled,has_datawall',
            'datawall_meta_values' => '1,true,yes,on,datawall,registration',
            'datawall_taxonomies' => 'category,post_tag',
            'datawall_term_slugs' => 'datawall,inscription,registre',
            'classified_post_types' => 'petite_annonce,petites_annonces,classified,annonce',
            'classified_post_statuses' => 'publish',
            'classified_revenue_meta_keys' => '_price,price,amount,total,revenue,_gd_classified_revenue',
        );
    }

    public static function get_settings()
    {
        $stored = get_option(self::OPTION_NAME, array());

        if (!is_array($stored)) {
            $stored = array();
        }

        return wp_parse_args($stored, self::get_default_settings());
    }

    public static function update_settings($settings)
    {
        update_option(self::OPTION_NAME, wp_parse_args($settings, self::get_settings()));

        if (!empty($settings['schedule_enabled'])) {
            self::schedule_cron();
        } else {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    public static function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public function run_cron_sync()
    {
        $period = $this->get_scheduled_period();
        $this->run_sync($period['start_date'], $period['end_date'], 'cron');
    }

    public function run_sync($start_date, $end_date, $trigger = 'manual')
    {
        $settings = self::get_settings();
        $period = $this->normalize_period($start_date, $end_date);

        if (is_wp_error($period)) {
            $this->store_last_result(array(
                'success' => false,
                'trigger' => $trigger,
                'message' => $period->get_error_message(),
                'time' => current_time('mysql'),
            ));

            return $period;
        }

        $payload = $this->collector->collect($period['start_date'], $period['end_date'], $settings);
        $payload['trigger'] = $trigger;
        $payload = apply_filters('gd_wp_sync_payload', $payload, $settings, $trigger);

        $response = $this->api->push($payload, $settings);
        $global_result = $this->format_api_result($response);
        $advanced_ads_payload = null;
        $advanced_ads_response = null;
        $advanced_ads_result = null;

        if (!empty($settings['advanced_ads_enabled'])) {
            $advanced_ads_payload = $this->advanced_ads_collector->collect($period['start_date'], $period['end_date'], $settings);
            $advanced_ads_payload['trigger'] = $trigger;
            $advanced_ads_payload = apply_filters('gd_wp_sync_django_stats_payload', $advanced_ads_payload, $settings, $trigger);
            $advanced_ads_response = $this->api->push_django_stats($advanced_ads_payload, $settings);
            $advanced_ads_result = $this->format_api_result($advanced_ads_response);
        }

        $result = array(
            'success' => !empty($global_result['success']) && (null === $advanced_ads_result || !empty($advanced_ads_result['success'])),
            'trigger' => $trigger,
            'period' => $period,
            'payload' => $payload,
            'global_digital' => $global_result,
            'time' => current_time('mysql'),
        );

        if (null !== $advanced_ads_result) {
            $result['advanced_ads'] = array(
                'payload' => $advanced_ads_payload,
                'api' => $advanced_ads_result,
            );
        }

        $result['status_code'] = isset($global_result['status_code']) ? (int) $global_result['status_code'] : 0;
        $result['message'] = $this->summarize_results($global_result, $advanced_ads_result);
        $result['response_body'] = isset($global_result['body']) ? $global_result['body'] : '';

        $this->store_last_result($result);
        do_action('gd_wp_sync_after_push', $result, $payload, $settings);

        return array(
            'success' => $result['success'],
            'global_digital' => $global_result,
            'advanced_ads' => $advanced_ads_result,
            'message' => $result['message'],
        );
    }

    public function preview_payload($start_date, $end_date)
    {
        $settings = self::get_settings();
        $period = $this->normalize_period($start_date, $end_date);

        if (is_wp_error($period)) {
            return $period;
        }

        $payload = $this->collector->collect($period['start_date'], $period['end_date'], $settings);

        if (empty($settings['advanced_ads_enabled'])) {
            return $payload;
        }

        return array(
            'global_digital' => $payload,
            'advanced_ads_stats' => $this->advanced_ads_collector->collect($period['start_date'], $period['end_date'], $settings),
        );
    }

    public static function get_last_result()
    {
        $result = get_option(self::LAST_RESULT_OPTION, array());

        return is_array($result) ? $result : array();
    }

    private function store_last_result($result)
    {
        update_option(self::LAST_RESULT_OPTION, $result, false);
    }

    private function format_api_result($response)
    {
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
                'status_code' => 0,
                'body' => '',
            );
        }

        return array(
            'success' => !empty($response['success']),
            'message' => isset($response['message']) ? $response['message'] : '',
            'status_code' => isset($response['status_code']) ? (int) $response['status_code'] : 0,
            'body' => isset($response['body']) ? $response['body'] : '',
        );
    }

    private function summarize_results($global_result, $advanced_ads_result)
    {
        $messages = array();
        $messages[] = 'Global Digital: ' . (isset($global_result['message']) ? $global_result['message'] : '');

        if (null !== $advanced_ads_result) {
            $messages[] = 'Django Stats: ' . (isset($advanced_ads_result['message']) ? $advanced_ads_result['message'] : '');
        }

        return implode(' ', array_filter($messages));
    }

    private function normalize_period($start_date, $end_date)
    {
        $start_date = sanitize_text_field((string) $start_date);
        $end_date = sanitize_text_field((string) $end_date);

        if (!$this->is_date($start_date) || !$this->is_date($end_date)) {
            return new WP_Error('gd_wp_sync_invalid_period', __('Start and end dates must use YYYY-MM-DD.', 'global-digital-wp-sync'));
        }

        if (strtotime($start_date) > strtotime($end_date)) {
            return new WP_Error('gd_wp_sync_invalid_period_order', __('Start date must be before end date.', 'global-digital-wp-sync'));
        }

        return array(
            'start_date' => $start_date,
            'end_date' => $end_date,
        );
    }

    private function is_date($date)
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    private function get_scheduled_period()
    {
        $settings = self::get_settings();
        $mode = isset($settings['schedule_period']) ? $settings['schedule_period'] : 'previous_day';
        $timestamp = current_time('timestamp');

        if ('current_month' === $mode) {
            return array(
                'start_date' => date('Y-m-01', $timestamp),
                'end_date' => date('Y-m-d', $timestamp),
            );
        }

        if ('previous_month' === $mode) {
            $previous_month = strtotime('first day of previous month', $timestamp);

            return array(
                'start_date' => date('Y-m-01', $previous_month),
                'end_date' => date('Y-m-t', $previous_month),
            );
        }

        $yesterday = strtotime('-1 day', $timestamp);

        return array(
            'start_date' => date('Y-m-d', $yesterday),
            'end_date' => date('Y-m-d', $yesterday),
        );
    }

    private function register_cli_command()
    {
        WP_CLI::add_command('global-digital-sync push', function ($args, $assoc_args) {
            $start = isset($assoc_args['start']) ? $assoc_args['start'] : date('Y-m-01', current_time('timestamp'));
            $end = isset($assoc_args['end']) ? $assoc_args['end'] : date('Y-m-d', current_time('timestamp'));
            $response = $this->run_sync($start, $end, 'wp-cli');

            if (is_wp_error($response)) {
                WP_CLI::error($response->get_error_message());
            }

            if (empty($response['success'])) {
                WP_CLI::error(isset($response['message']) ? $response['message'] : 'Global Digital API rejected the payload.');
            }

            WP_CLI::success('Global Digital sync completed.');
        });
    }
}
