<?php

if (!defined('ABSPATH')) {
    exit;
}

class GD_WP_Sync_Advanced_Ads_Advertisers
{
    const TAXONOMY = 'gd_advertiser';

    public function init()
    {
        add_action('init', array($this, 'register_taxonomy'), 50);
        add_action('admin_init', array($this, 'attach_to_existing_post_types'));
    }

    public function register_taxonomy()
    {
        $settings = GD_WP_Sync::get_settings();

        if (empty($settings['advanced_ads_advertiser_taxonomy_enabled'])) {
            return;
        }

        $post_types = $this->post_types($settings);

        register_taxonomy(self::TAXONOMY, $post_types, array(
            'labels' => array(
                'name' => __('Annonceurs', 'global-digital-wp-sync'),
                'singular_name' => __('Annonceur', 'global-digital-wp-sync'),
                'search_items' => __('Rechercher des annonceurs', 'global-digital-wp-sync'),
                'popular_items' => __('Annonceurs frequents', 'global-digital-wp-sync'),
                'all_items' => __('Tous les annonceurs', 'global-digital-wp-sync'),
                'edit_item' => __('Modifier l annonceur', 'global-digital-wp-sync'),
                'update_item' => __('Mettre a jour l annonceur', 'global-digital-wp-sync'),
                'add_new_item' => __('Ajouter un annonceur', 'global-digital-wp-sync'),
                'new_item_name' => __('Nom du nouvel annonceur', 'global-digital-wp-sync'),
                'separate_items_with_commas' => __('Separer les annonceurs par des virgules', 'global-digital-wp-sync'),
                'add_or_remove_items' => __('Ajouter ou retirer des annonceurs', 'global-digital-wp-sync'),
                'choose_from_most_used' => __('Choisir parmi les annonceurs les plus utilises', 'global-digital-wp-sync'),
                'menu_name' => __('Annonceurs', 'global-digital-wp-sync'),
            ),
            'hierarchical' => false,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_menu' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud' => false,
            'show_in_quick_edit' => true,
            'show_in_rest' => true,
            'rewrite' => false,
            'capabilities' => array(
                'manage_terms' => 'edit_posts',
                'edit_terms' => 'edit_posts',
                'delete_terms' => 'edit_posts',
                'assign_terms' => 'edit_posts',
            ),
        ));

        foreach ($post_types as $post_type) {
            register_taxonomy_for_object_type(self::TAXONOMY, $post_type);
        }
    }

    public function attach_to_existing_post_types()
    {
        if (!taxonomy_exists(self::TAXONOMY)) {
            return;
        }

        foreach ($this->post_types(GD_WP_Sync::get_settings()) as $post_type) {
            if (post_type_exists($post_type)) {
                register_taxonomy_for_object_type(self::TAXONOMY, $post_type);
            }
        }
    }

    private function post_types($settings)
    {
        $configured = isset($settings['advanced_ads_ad_post_types']) ? $settings['advanced_ads_ad_post_types'] : 'advanced_ads';
        $post_types = preg_split('/[\r\n,]+/', (string) $configured);
        $post_types = array_map('trim', $post_types);
        $post_types = array_filter($post_types, function ($post_type) {
            return '' !== $post_type && preg_match('/^[A-Za-z0-9_-]+$/', $post_type);
        });

        if (empty($post_types)) {
            $post_types = array('advanced_ads');
        }

        return array_values(array_unique(apply_filters('gd_wp_sync_advanced_ads_advertiser_post_types', $post_types, $settings)));
    }
}
