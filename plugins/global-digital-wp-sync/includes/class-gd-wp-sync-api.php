<?php

if (!defined('ABSPATH')) {
    exit;
}

class GD_WP_Sync_API
{
    public function push($payload, $settings)
    {
        $endpoint = isset($settings['api_endpoint']) ? esc_url_raw($settings['api_endpoint']) : '';

        if (empty($endpoint)) {
            return new WP_Error('gd_wp_sync_missing_endpoint', __('Global Digital API endpoint is missing.', 'global-digital-wp-sync'));
        }

        $body = wp_json_encode($payload);

        if (false === $body) {
            return new WP_Error('gd_wp_sync_json_error', __('Unable to encode Global Digital payload as JSON.', 'global-digital-wp-sync'));
        }

        $args = array(
            'timeout' => isset($settings['request_timeout']) ? max(5, (int) $settings['request_timeout']) : 20,
            'headers' => $this->headers($settings),
            'body' => $body,
            'data_format' => 'body',
        );

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $success = $status_code >= 200 && $status_code < 300;

        return array(
            'success' => $success,
            'status_code' => $status_code,
            'message' => $success ? 'Payload accepted by Global Digital API.' : 'Global Digital API returned an error.',
            'body' => is_string($response_body) ? wp_trim_words($response_body, 80, '...') : '',
        );
    }

    private function headers($settings)
    {
        $headers = array(
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
            'User-Agent' => 'GlobalDigitalWPSync/' . (defined('GD_WP_SYNC_VERSION') ? GD_WP_SYNC_VERSION : '0.0.0'),
        );

        $token = isset($settings['api_token']) ? trim((string) $settings['api_token']) : '';
        $header_name = isset($settings['auth_header_name']) ? trim((string) $settings['auth_header_name']) : 'Authorization';
        $prefix = isset($settings['auth_header_prefix']) ? trim((string) $settings['auth_header_prefix']) : 'Bearer';

        if ('' !== $token && '' !== $header_name) {
            $headers[$header_name] = '' !== $prefix ? $prefix . ' ' . $token : $token;
        }

        return apply_filters('gd_wp_sync_api_headers', $headers, $settings);
    }
}
