<?php

if (!defined('ABSPATH')) {
    exit;
}

class GD_WP_Sync_Admin
{
    /**
     * @var GD_WP_Sync
     */
    private $plugin;

    public function __construct(GD_WP_Sync $plugin)
    {
        $this->plugin = $plugin;
    }

    public function init()
    {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_gd_wp_sync_manual_push', array($this, 'handle_manual_push'));
        add_action('admin_post_gd_wp_sync_preview', array($this, 'handle_preview'));
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    public function add_menu()
    {
        add_options_page(
            __('Global Digital WP Sync', 'global-digital-wp-sync'),
            __('Global Digital Sync', 'global-digital-wp-sync'),
            'manage_options',
            'global-digital-wp-sync',
            array($this, 'render_page')
        );
    }

    public function register_settings()
    {
        register_setting(
            'gd_wp_sync_settings_group',
            GD_WP_Sync::OPTION_NAME,
            array($this, 'sanitize_settings')
        );

        add_settings_section(
            'gd_wp_sync_api',
            __('API Global Digital', 'global-digital-wp-sync'),
            '__return_false',
            'global-digital-wp-sync'
        );

        $this->field('api_endpoint', __('Endpoint API', 'global-digital-wp-sync'), 'url', 'gd_wp_sync_api');
        $this->field('api_token', __('Jeton API', 'global-digital-wp-sync'), 'password', 'gd_wp_sync_api');
        $this->field('auth_header_name', __('Header authentification', 'global-digital-wp-sync'), 'text', 'gd_wp_sync_api');
        $this->field('auth_header_prefix', __('Prefixe authentification', 'global-digital-wp-sync'), 'text', 'gd_wp_sync_api');
        $this->field('request_timeout', __('Timeout requete', 'global-digital-wp-sync'), 'number', 'gd_wp_sync_api');

        add_settings_section(
            'gd_wp_sync_collection',
            __('Donnees WordPress', 'global-digital-wp-sync'),
            '__return_false',
            'global-digital-wp-sync'
        );

        $this->field('article_post_types', __('Types de contenus articles', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('article_post_statuses', __('Statuts articles', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('paywall_meta_keys', __('Meta keys paywall', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('paywall_meta_values', __('Valeurs paywall', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('paywall_taxonomies', __('Taxonomies paywall', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('paywall_term_slugs', __('Slugs de termes paywall', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('datawall_meta_keys', __('Meta keys datawall', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('datawall_meta_values', __('Valeurs datawall', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('datawall_taxonomies', __('Taxonomies datawall', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('datawall_term_slugs', __('Slugs de termes datawall', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('classified_post_types', __('Types de contenus petites annonces', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('classified_post_statuses', __('Statuts petites annonces', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');
        $this->field('classified_revenue_meta_keys', __('Meta keys CA petites annonces', 'global-digital-wp-sync'), 'textarea', 'gd_wp_sync_collection');

        add_settings_section(
            'gd_wp_sync_schedule',
            __('Synchronisation automatique', 'global-digital-wp-sync'),
            '__return_false',
            'global-digital-wp-sync'
        );

        $this->field('schedule_enabled', __('Activer WP-Cron quotidien', 'global-digital-wp-sync'), 'checkbox', 'gd_wp_sync_schedule');
        $this->field('schedule_period', __('Periode envoyee automatiquement', 'global-digital-wp-sync'), 'select', 'gd_wp_sync_schedule');
    }

    private function field($key, $label, $type, $section)
    {
        add_settings_field(
            $key,
            $label,
            array($this, 'render_field'),
            'global-digital-wp-sync',
            $section,
            array(
                'key' => $key,
                'type' => $type,
            )
        );
    }

    public function sanitize_settings($input)
    {
        if (!is_array($input)) {
            $input = array();
        }

        $defaults = GD_WP_Sync::get_default_settings();
        $clean = GD_WP_Sync::get_settings();

        foreach ($defaults as $key => $default) {
            if (!isset($input[$key])) {
                if ('schedule_enabled' === $key) {
                    $clean[$key] = 0;
                }
                continue;
            }

            $value = $input[$key];

            if ('api_endpoint' === $key) {
                $clean[$key] = esc_url_raw($value);
                continue;
            }

            if ('request_timeout' === $key) {
                $clean[$key] = max(5, min(120, absint($value)));
                continue;
            }

            if ('schedule_enabled' === $key) {
                $clean[$key] = empty($value) ? 0 : 1;
                continue;
            }

            if ('schedule_period' === $key) {
                $allowed = array('previous_day', 'current_month', 'previous_month');
                $clean[$key] = in_array($value, $allowed, true) ? $value : 'previous_day';
                continue;
            }

            $clean[$key] = sanitize_textarea_field($value);
        }

        if (!empty($clean['schedule_enabled'])) {
            GD_WP_Sync::schedule_cron();
        } else {
            wp_clear_scheduled_hook(GD_WP_Sync::CRON_HOOK);
        }

        return $clean;
    }

    public function render_field($args)
    {
        $settings = GD_WP_Sync::get_settings();
        $key = $args['key'];
        $type = $args['type'];
        $name = GD_WP_Sync::OPTION_NAME . '[' . $key . ']';
        $value = isset($settings[$key]) ? $settings[$key] : '';

        if ('textarea' === $type) {
            printf(
                '<textarea class="large-text code" rows="2" name="%1$s">%2$s</textarea><p class="description">%3$s</p>',
                esc_attr($name),
                esc_textarea($value),
                esc_html__('Liste separee par des virgules ou des retours ligne.', 'global-digital-wp-sync')
            );
            return;
        }

        if ('checkbox' === $type) {
            printf(
                '<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>',
                esc_attr($name),
                checked(1, (int) $value, false),
                esc_html__('Envoyer automatiquement selon la periode choisie.', 'global-digital-wp-sync')
            );
            return;
        }

        if ('select' === $type) {
            $options = array(
                'previous_day' => __('Jour precedent', 'global-digital-wp-sync'),
                'current_month' => __('Mois courant', 'global-digital-wp-sync'),
                'previous_month' => __('Mois precedent', 'global-digital-wp-sync'),
            );
            echo '<select name="' . esc_attr($name) . '">';
            foreach ($options as $option_value => $label) {
                printf(
                    '<option value="%1$s" %2$s>%3$s</option>',
                    esc_attr($option_value),
                    selected($value, $option_value, false),
                    esc_html($label)
                );
            }
            echo '</select>';
            return;
        }

        printf(
            '<input class="regular-text" type="%1$s" name="%2$s" value="%3$s">',
            esc_attr($type),
            esc_attr($name),
            esc_attr($value)
        );
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $now = current_time('timestamp');
        $default_start = date('Y-m-01', $now);
        $default_end = date('Y-m-d', $now);
        $last_result = GD_WP_Sync::get_last_result();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Global Digital WP Sync', 'global-digital-wp-sync'); ?></h1>
            <p><?php esc_html_e('Collecte uniquement les indicateurs dont la source est WordPress, puis les envoie vers l API Global Digital.', 'global-digital-wp-sync'); ?></p>

            <h2><?php esc_html_e('Envoyer une periode', 'global-digital-wp-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gd_wp_sync_manual_push'); ?>
                <input type="hidden" name="action" value="gd_wp_sync_manual_push">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="gd_wp_sync_start_date"><?php esc_html_e('Date debut', 'global-digital-wp-sync'); ?></label></th>
                        <td><input type="date" id="gd_wp_sync_start_date" name="start_date" value="<?php echo esc_attr($default_start); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gd_wp_sync_end_date"><?php esc_html_e('Date fin', 'global-digital-wp-sync'); ?></label></th>
                        <td><input type="date" id="gd_wp_sync_end_date" name="end_date" value="<?php echo esc_attr($default_end); ?>" required></td>
                    </tr>
                </table>
                <?php submit_button(__('Envoyer a Global Digital', 'global-digital-wp-sync')); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gd_wp_sync_preview'); ?>
                <input type="hidden" name="action" value="gd_wp_sync_preview">
                <input type="hidden" name="start_date" value="<?php echo esc_attr($default_start); ?>">
                <input type="hidden" name="end_date" value="<?php echo esc_attr($default_end); ?>">
                <?php submit_button(__('Previsualiser le payload du mois courant', 'global-digital-wp-sync'), 'secondary'); ?>
            </form>

            <?php if (!empty($last_result)) : ?>
                <h2><?php esc_html_e('Dernier envoi', 'global-digital-wp-sync'); ?></h2>
                <table class="widefat striped" style="max-width: 980px;">
                    <tbody>
                        <tr>
                            <th><?php esc_html_e('Statut', 'global-digital-wp-sync'); ?></th>
                            <td><?php echo !empty($last_result['success']) ? esc_html__('Succes', 'global-digital-wp-sync') : esc_html__('Erreur', 'global-digital-wp-sync'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Date', 'global-digital-wp-sync'); ?></th>
                            <td><?php echo isset($last_result['time']) ? esc_html($last_result['time']) : ''; ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Message', 'global-digital-wp-sync'); ?></th>
                            <td><?php echo isset($last_result['message']) ? esc_html($last_result['message']) : ''; ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php esc_html_e('Reglages', 'global-digital-wp-sync'); ?></h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('gd_wp_sync_settings_group');
                do_settings_sections('global-digital-wp-sync');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function handle_manual_push()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'global-digital-wp-sync'));
        }

        check_admin_referer('gd_wp_sync_manual_push');

        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        $response = $this->plugin->run_sync($start_date, $end_date, 'manual');
        $status = is_wp_error($response) || empty($response['success']) ? 'error' : 'success';

        wp_safe_redirect(add_query_arg(array(
            'page' => 'global-digital-wp-sync',
            'gd_wp_sync_status' => $status,
        ), admin_url('options-general.php')));
        exit;
    }

    public function handle_preview()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'global-digital-wp-sync'));
        }

        check_admin_referer('gd_wp_sync_preview');

        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        $payload = $this->plugin->preview_payload($start_date, $end_date);

        if (is_wp_error($payload)) {
            wp_die(esc_html($payload->get_error_message()));
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT);
        exit;
    }

    public function admin_notices()
    {
        if (!isset($_GET['page']) || 'global-digital-wp-sync' !== $_GET['page'] || !isset($_GET['gd_wp_sync_status'])) {
            return;
        }

        $status = sanitize_key(wp_unslash($_GET['gd_wp_sync_status']));

        if ('success' === $status) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Payload Global Digital envoye.', 'global-digital-wp-sync') . '</p></div>';
            return;
        }

        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Envoi Global Digital impossible. Consultez le dernier resultat.', 'global-digital-wp-sync') . '</p></div>';
    }
}
