<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Parresia_Blocks
{
    private static ?Parresia_Blocks $instance = null;

    public static function instance(): Parresia_Blocks
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'register_assets']);
        add_action('init', [$this, 'register_blocks']);
        add_action('init', [$this, 'register_patterns']);
        add_action('widgets_init', [$this, 'register_widgets']);
        add_shortcode('parresia_block', [$this, 'render_shortcode']);
    }

    public function register_assets(): void
    {
        $editor_js = PARRESIA_BLOCKS_DIR . 'assets/editor.js';
        $style_css = PARRESIA_BLOCKS_DIR . 'assets/style.css';
        $editor_css = PARRESIA_BLOCKS_DIR . 'assets/editor.css';

        wp_register_script(
            'parresia-blocks-editor',
            PARRESIA_BLOCKS_URL . 'assets/editor.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render'],
            file_exists($editor_js) ? (string) filemtime($editor_js) : PARRESIA_BLOCKS_VERSION,
            true
        );

        wp_register_style(
            'parresia-blocks-style',
            PARRESIA_BLOCKS_URL . 'assets/style.css',
            [],
            file_exists($style_css) ? (string) filemtime($style_css) : PARRESIA_BLOCKS_VERSION
        );

        wp_register_style(
            'parresia-blocks-editor',
            PARRESIA_BLOCKS_URL . 'assets/editor.css',
            ['parresia-blocks-style'],
            file_exists($editor_css) ? (string) filemtime($editor_css) : PARRESIA_BLOCKS_VERSION
        );
    }

    public function register_blocks(): void
    {
        register_block_type('parresia/template-block', [
            'api_version' => 2,
            'editor_script' => 'parresia-blocks-editor',
            'style' => 'parresia-blocks-style',
            'editor_style' => 'parresia-blocks-editor',
            'render_callback' => [$this, 'render_block'],
            'attributes' => $this->get_attributes_schema(),
            'supports' => [
                'align' => ['wide', 'full'],
                'anchor' => true,
                'spacing' => [
                    'margin' => true,
                    'padding' => true,
                ],
            ],
        ]);
    }

    public function register_patterns(): void
    {
        if (!function_exists('register_block_pattern_category')) {
            return;
        }

        register_block_pattern_category('parresia', [
            'label' => __('Parresia', 'parresia-blocks'),
        ]);

        register_block_pattern('parresia/home-editorial-stack', [
            'title' => __('Parresia homepage stack', 'parresia-blocks'),
            'categories' => ['parresia'],
            'content' => '<!-- wp:parresia/template-block {"blockType":"hero","title":"Nouvelles recommandations HAS sur l antibiotherapie","kicker":"Reglementation","theme":"light"} /--><!-- wp:parresia/template-block {"blockType":"video-card","title":"Chirurgie implantaire guidee : demonstration complete","kicker":"Videos","theme":"dark","showViews":true,"views":2400} /--><!-- wp:parresia/template-block {"blockType":"ad","title":"Annonceur","adMode":"placement","theme":"light"} /-->',
        ]);

        register_block_pattern('parresia/sidebar-rail', [
            'title' => __('Parresia sidebar rail', 'parresia-blocks'),
            'categories' => ['parresia'],
            'content' => '<!-- wp:parresia/template-block {"blockType":"rail-list","title":"Top 5","itemCount":5,"queryCount":5,"queryOrderBy":"views","topMode":"most-read-posts","theme":"light","compact":true,"showViews":true} /--><!-- wp:parresia/template-block {"blockType":"magazine","title":"Lire le magazine","theme":"dark","compact":true,"contentSource":"query","kioskPostType":"kiosque"} /--><!-- wp:parresia/template-block {"blockType":"ad","adMode":"id","compact":true} /-->',
        ]);
    }

    public function register_widgets(): void
    {
        register_widget(Parresia_Block_Widget::class);
    }

    public function get_attributes_schema(): array
    {
        return [
            'blockType' => ['type' => 'string', 'default' => 'article-card'],
            'title' => ['type' => 'string', 'default' => 'Titre du bloc'],
            'kicker' => ['type' => 'string', 'default' => 'Actualites'],
            'text' => ['type' => 'string', 'default' => 'Texte de description du bloc editorial.'],
            'linkUrl' => ['type' => 'string', 'default' => '#'],
            'linkLabel' => ['type' => 'string', 'default' => 'Lire'],
            'imageUrl' => ['type' => 'string', 'default' => ''],
            'theme' => ['type' => 'string', 'default' => 'light'],
            'layout' => ['type' => 'string', 'default' => 'card'],
            'accent' => ['type' => 'string', 'default' => 'red'],
            'itemCount' => ['type' => 'number', 'default' => 3],
            'showViews' => ['type' => 'boolean', 'default' => false],
            'views' => ['type' => 'number', 'default' => 0],
            'contentSource' => ['type' => 'string', 'default' => 'manual'],
            'postType' => ['type' => 'string', 'default' => 'post'],
            'queryTaxonomy' => ['type' => 'string', 'default' => 'category'],
            'queryTerms' => ['type' => 'string', 'default' => ''],
            'queryCount' => ['type' => 'number', 'default' => 1],
            'queryOrderBy' => ['type' => 'string', 'default' => 'date'],
            'viewsMetaKey' => ['type' => 'string', 'default' => 'post_views_count'],
            'topMode' => ['type' => 'string', 'default' => 'most-read-posts'],
            'kioskPostType' => ['type' => 'string', 'default' => 'kiosque'],
            'compact' => ['type' => 'boolean', 'default' => false],
            'itemsText' => ['type' => 'string', 'default' => 'Implantologie|Les atouts du cone beam|2400' . "\n" . 'Strategie|Developper son cabinet dentaire|1800' . "\n" . 'Parodontologie|Les espoirs de l intelligence artificielle|980'],
            'adMode' => ['type' => 'string', 'default' => 'id'],
            'adId' => ['type' => 'string', 'default' => ''],
            'adPlacement' => ['type' => 'string', 'default' => ''],
            'adShortcode' => ['type' => 'string', 'default' => ''],
        ];
    }

    public function render_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'type' => 'article-card',
            'title' => 'Titre du bloc',
            'kicker' => 'Actualites',
            'text' => 'Texte de description du bloc editorial.',
            'link_url' => '#',
            'link_label' => 'Lire',
            'theme' => 'light',
            'layout' => 'card',
            'accent' => 'red',
            'count' => 3,
            'views' => 0,
            'show_views' => false,
            'source' => 'manual',
            'post_type' => 'post',
            'taxonomy' => 'category',
            'terms' => '',
            'orderby' => 'date',
            'views_meta_key' => 'post_views_count',
            'top_mode' => 'most-read-posts',
            'kiosk_post_type' => 'kiosque',
            'compact' => false,
            'ad_mode' => 'id',
            'ad_id' => '',
            'ad_placement' => '',
            'ad_shortcode' => '',
        ], $atts, 'parresia_block');

        return $this->render_block([
            'blockType' => sanitize_key($atts['type']),
            'title' => sanitize_text_field($atts['title']),
            'kicker' => sanitize_text_field($atts['kicker']),
            'text' => sanitize_textarea_field($atts['text']),
            'linkUrl' => esc_url_raw($atts['link_url']),
            'linkLabel' => sanitize_text_field($atts['link_label']),
            'theme' => sanitize_key($atts['theme']),
            'layout' => sanitize_key($atts['layout']),
            'accent' => sanitize_key($atts['accent']),
            'itemCount' => absint($atts['count']),
            'views' => absint($atts['views']),
            'showViews' => filter_var($atts['show_views'], FILTER_VALIDATE_BOOLEAN),
            'contentSource' => sanitize_key($atts['source']),
            'postType' => sanitize_key($atts['post_type']),
            'queryTaxonomy' => sanitize_key($atts['taxonomy']),
            'queryTerms' => sanitize_text_field($atts['terms']),
            'queryCount' => absint($atts['count']),
            'queryOrderBy' => sanitize_key($atts['orderby']),
            'viewsMetaKey' => sanitize_key($atts['views_meta_key']),
            'topMode' => sanitize_key($atts['top_mode']),
            'kioskPostType' => sanitize_key($atts['kiosk_post_type']),
            'compact' => filter_var($atts['compact'], FILTER_VALIDATE_BOOLEAN),
            'adMode' => sanitize_key($atts['ad_mode']),
            'adId' => sanitize_text_field($atts['ad_id']),
            'adPlacement' => sanitize_text_field($atts['ad_placement']),
            'adShortcode' => wp_kses_post($atts['ad_shortcode']),
        ]);
    }

    public function render_block(array $attrs, string $content = ''): string
    {
        $attrs = wp_parse_args($attrs, array_map(static fn($schema) => $schema['default'] ?? '', $this->get_attributes_schema()));
        $type = sanitize_key((string) $attrs['blockType']);
        $theme = sanitize_key((string) $attrs['theme']);
        $layout = sanitize_key((string) $attrs['layout']);
        $accent = sanitize_key((string) $attrs['accent']);
        $classes = [
            'parresia-block',
            'parresia-block--' . $type,
            'parresia-block--theme-' . $theme,
            'parresia-block--layout-' . $layout,
            'parresia-block--accent-' . $accent,
        ];

        if (!empty($attrs['compact'])) {
            $classes[] = 'is-compact';
        }

        switch ($type) {
            case 'hero':
                $inner = $this->render_hero($attrs);
                break;
            case 'slider':
                $inner = $this->render_slider($attrs);
                break;
            case 'video-card':
                $inner = $this->render_media_card($attrs, true);
                break;
            case 'magazine':
                $inner = $this->render_magazine($attrs);
                break;
            case 'subscription':
                $inner = $this->render_subscription($attrs);
                break;
            case 'agenda':
                $inner = $this->render_list($attrs, 'agenda');
                break;
            case 'poll':
                $inner = $this->render_poll($attrs);
                break;
            case 'ad':
                $inner = $this->render_ad($attrs);
                break;
            case 'rail-list':
                $inner = $this->render_top_or_list($attrs);
                break;
            case 'expo-logo-strip':
                $inner = $this->render_logo_strip($attrs);
                break;
            case 'masterclass':
                $inner = $this->render_media_card($attrs, false, 'masterclass');
                break;
            case 'feature':
            case 'article-card':
            default:
                $inner = $this->render_media_card($attrs);
                break;
        }

        return sprintf(
            '<section class="%s">%s</section>',
            esc_attr(implode(' ', $classes)),
            $inner
        );
    }

    private function render_hero(array $attrs): string
    {
        $posts = $this->get_query_posts($attrs);
        if (!empty($posts)) {
            $attrs = $this->attrs_from_post($posts[0], $attrs);
        }

        return sprintf(
            '<a class="pb-hero" href="%s">%s<div class="pb-hero__copy"><span class="pb-kicker">%s</span><h2>%s</h2><p>%s</p><span class="pb-button">%s</span></div></a>',
            esc_url($attrs['linkUrl']),
            $this->render_image($attrs, 'pb-hero__media'),
            esc_html($attrs['kicker']),
            esc_html($attrs['title']),
            esc_html($attrs['text']),
            esc_html($attrs['linkLabel'])
        );
    }

    private function render_media_card(array $attrs, bool $video = false, string $variant = 'article'): string
    {
        $posts = $this->get_query_posts($attrs);
        if (count($posts) > 1) {
            return $this->render_cards_collection($posts, $attrs, $video, $variant);
        }

        if (!empty($posts)) {
            $attrs = $this->attrs_from_post($posts[0], $attrs);
        }

        $views = !empty($attrs['showViews']) ? sprintf('<span>%s vues</span>', esc_html($this->format_views((int) $attrs['views']))) : '';
        $play = $video ? '<span class="pb-play" aria-hidden="true">▶</span>' : '';

        return sprintf(
            '<a class="pb-card pb-card--%s" href="%s"><span class="pb-card__media">%s%s</span><span class="pb-card__body"><span class="pb-kicker">%s</span><strong>%s</strong><span class="pb-text">%s</span><span class="pb-meta">%s%s</span></span></a>',
            esc_attr($variant),
            esc_url($attrs['linkUrl']),
            $this->render_image($attrs, 'pb-card__image', false),
            $play,
            esc_html($attrs['kicker']),
            esc_html($attrs['title']),
            esc_html($attrs['text']),
            $views,
            $video ? '<span>Video</span>' : ''
        );
    }

    private function render_magazine(array $attrs): string
    {
        $latest = $this->get_latest_kiosk_post($attrs);
        $cover = '';

        if ($latest instanceof WP_Post) {
            $attrs = $this->attrs_from_post($latest, $attrs);
            $cover = get_the_post_thumbnail($latest, 'medium', ['class' => 'pb-magazine__thumb']);
        }

        return sprintf(
            '<a class="pb-magazine" href="%s"><span class="pb-magazine__cover">%s<span>dentaire365</span><strong>%s</strong></span><span class="pb-magazine__copy"><span class="pb-kicker">%s</span><strong>%s</strong><em>%s</em></span></a>',
            esc_url($attrs['linkUrl']),
            $cover,
            esc_html($attrs['kicker']),
            esc_html($attrs['kicker']),
            esc_html($attrs['title']),
            esc_html($attrs['linkLabel'])
        );
    }

    private function render_subscription(array $attrs): string
    {
        return sprintf(
            '<div class="pb-subscription"><span class="pb-kicker">%s</span><h2>%s</h2><p>%s</p><a class="pb-button pb-button--subscribe" href="%s">%s</a></div>',
            esc_html($attrs['kicker']),
            esc_html($attrs['title']),
            esc_html($attrs['text']),
            esc_url($attrs['linkUrl']),
            esc_html($attrs['linkLabel'])
        );
    }

    private function render_list(array $attrs, string $variant): string
    {
        $posts = $this->get_query_posts($attrs);
        if (!empty($posts)) {
            return $this->render_posts_list($posts, $attrs, $variant);
        }

        $items = array_slice($this->parse_items((string) $attrs['itemsText']), 0, max(1, (int) $attrs['itemCount']));
        $html = sprintf('<div class="pb-list pb-list--%s"><h2>%s</h2><ul>', esc_attr($variant), esc_html($attrs['title']));

        foreach ($items as $item) {
            $html .= sprintf(
                '<li><a href="%s"><span class="pb-kicker">%s</span><strong>%s</strong>%s</a></li>',
                esc_url($attrs['linkUrl']),
                esc_html($item['kicker']),
                esc_html($item['title']),
                $item['views'] ? '<em>' . esc_html($this->format_views($item['views'])) . ' vues</em>' : ''
            );
        }

        return $html . '</ul></div>';
    }

    private function render_slider(array $attrs): string
    {
        $posts = $this->get_query_posts($attrs);
        $html = sprintf('<div class="pb-slider"><div class="pb-slider__head"><h2>%s</h2></div><div class="pb-slider__track">', esc_html($attrs['title']));

        if (!empty($posts)) {
            foreach ($posts as $post) {
                $html .= $this->render_media_card($this->attrs_from_post($post, $attrs), (bool) ('video-card' === ($attrs['layout'] ?? '')));
            }
        } else {
            $items = array_slice($this->parse_items((string) $attrs['itemsText']), 0, max(1, (int) $attrs['itemCount']));
            foreach ($items as $item) {
                $card_attrs = array_merge($attrs, [
                    'kicker' => $item['kicker'],
                    'title' => $item['title'],
                    'views' => $item['views'],
                ]);
                $html .= $this->render_media_card($card_attrs);
            }
        }

        return $html . '</div></div>';
    }

    private function render_cards_collection(array $posts, array $attrs, bool $video = false, string $variant = 'article'): string
    {
        $html = sprintf('<div class="pb-card-collection"><h2>%s</h2><div class="pb-card-collection__grid">', esc_html($attrs['title']));

        foreach ($posts as $post) {
            $html .= $this->render_media_card($this->attrs_from_post($post, $attrs), $video, $variant);
        }

        return $html . '</div></div>';
    }

    private function render_posts_list(array $posts, array $attrs, string $variant): string
    {
        $html = sprintf('<div class="pb-list pb-list--%s"><h2>%s</h2><ul>', esc_attr($variant), esc_html($attrs['title']));

        foreach ($posts as $post) {
            $views = (int) get_post_meta($post->ID, (string) $attrs['viewsMetaKey'], true);
            $terms = get_the_terms($post, 'category');
            $kicker = (!is_wp_error($terms) && !empty($terms)) ? $terms[0]->name : get_post_type_object($post->post_type)->labels->singular_name;
            $html .= sprintf(
                '<li><a href="%s"><span class="pb-kicker">%s</span><strong>%s</strong>%s</a></li>',
                esc_url(get_permalink($post)),
                esc_html($kicker),
                esc_html(get_the_title($post)),
                !empty($attrs['showViews']) ? '<em>' . esc_html($this->format_views($views)) . ' vues</em>' : ''
            );
        }

        return $html . '</ul></div>';
    }

    private function render_top_or_list(array $attrs): string
    {
        $mode = sanitize_key((string) $attrs['topMode']);

        if ('top-tags' === $mode || 'top-categories' === $mode) {
            return $this->render_top_terms($attrs, 'top-tags' === $mode ? 'post_tag' : 'category');
        }

        if ('top-kiosks' === $mode) {
            $attrs['postType'] = $attrs['kioskPostType'];
            $attrs['contentSource'] = 'query';
            $attrs['queryOrderBy'] = 'views';
            return $this->render_posts_list($this->get_query_posts($attrs), $attrs, 'rail');
        }

        $attrs['contentSource'] = 'query';
        $attrs['queryOrderBy'] = 'views';
        return $this->render_posts_list($this->get_query_posts($attrs), $attrs, 'rail');
    }

    private function render_top_terms(array $attrs, string $taxonomy): string
    {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'number' => max(1, (int) $attrs['itemCount']),
            'orderby' => 'count',
            'order' => 'DESC',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return $this->render_list($attrs, 'rail');
        }

        $html = sprintf('<div class="pb-list pb-list--rail"><h2>%s</h2><ul>', esc_html($attrs['title']));
        foreach ($terms as $term) {
            $html .= sprintf(
                '<li><a href="%s"><span class="pb-kicker">%s</span><strong>%s</strong><em>%d contenus</em></a></li>',
                esc_url(get_term_link($term)),
                esc_html('post_tag' === $taxonomy ? __('Etiquette', 'parresia-blocks') : __('Categorie', 'parresia-blocks')),
                esc_html($term->name),
                absint($term->count)
            );
        }

        return $html . '</ul></div>';
    }

    private function render_poll(array $attrs): string
    {
        $items = array_slice($this->parse_items((string) $attrs['itemsText']), 0, max(1, (int) $attrs['itemCount']));
        $html = sprintf('<div class="pb-poll"><h2>%s</h2><p>%s</p>', esc_html($attrs['title']), esc_html($attrs['text']));

        foreach ($items as $item) {
            $value = max(0, min(100, $item['views']));
            $html .= sprintf(
                '<div class="pb-poll__row"><span>%s</span><strong>%d%%</strong><i style="--pb-value:%d%%"></i></div>',
                esc_html($item['title']),
                $value,
                $value
            );
        }

        return $html . '</div>';
    }

    private function render_logo_strip(array $attrs): string
    {
        $count = max(1, min(12, (int) $attrs['itemCount']));
        $html = '<div class="pb-logo-strip" aria-label="' . esc_attr($attrs['title']) . '">';

        for ($i = 1; $i <= $count; $i++) {
            $html .= '<span>Logo</span>';
        }

        return $html . '</div>';
    }

    private function get_query_posts(array $attrs): array
    {
        if ('query' !== ($attrs['contentSource'] ?? 'manual')) {
            return [];
        }

        $post_type = sanitize_key((string) ($attrs['postType'] ?? 'post'));
        if (!post_type_exists($post_type)) {
            $post_type = 'post';
        }

        $args = [
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(24, (int) ($attrs['queryCount'] ?? $attrs['itemCount'] ?? 1))),
            'ignore_sticky_posts' => true,
        ];

        $taxonomy = sanitize_key((string) ($attrs['queryTaxonomy'] ?? 'category'));
        $terms = $this->csv_to_array((string) ($attrs['queryTerms'] ?? ''));
        if (!empty($terms) && taxonomy_exists($taxonomy)) {
            $args['tax_query'] = [
                [
                    'taxonomy' => $taxonomy,
                    'field' => 'slug',
                    'terms' => $terms,
                ],
            ];
        }

        if ('views' === ($attrs['queryOrderBy'] ?? 'date')) {
            $args['meta_key'] = sanitize_key((string) ($attrs['viewsMetaKey'] ?? 'post_views_count'));
            $args['orderby'] = 'meta_value_num';
            $args['order'] = 'DESC';
        } else {
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
        }

        $query = new WP_Query($args);
        return $query->posts;
    }

    private function attrs_from_post(WP_Post $post, array $base_attrs): array
    {
        $excerpt = has_excerpt($post)
            ? get_the_excerpt($post)
            : wp_trim_words(wp_strip_all_tags((string) $post->post_content), 24);
        $terms = get_the_terms($post, 'category');
        $kicker = (!is_wp_error($terms) && !empty($terms)) ? $terms[0]->name : ($base_attrs['kicker'] ?? '');
        $views = (int) get_post_meta($post->ID, (string) ($base_attrs['viewsMetaKey'] ?? 'post_views_count'), true);

        return array_merge($base_attrs, [
            'contentSource' => 'manual',
            'title' => get_the_title($post),
            'kicker' => $kicker,
            'text' => $excerpt,
            'linkUrl' => get_permalink($post),
            'imageUrl' => get_the_post_thumbnail_url($post, 'large') ?: '',
            'views' => $views,
        ]);
    }

    private function get_latest_kiosk_post(array $attrs): ?WP_Post
    {
        if ('query' !== ($attrs['contentSource'] ?? 'manual')) {
            return null;
        }

        $preferred = sanitize_key((string) ($attrs['kioskPostType'] ?? 'kiosque'));
        $post_types = array_values(array_unique(array_filter([$preferred, 'kiosque', 'kiosk', 'magazine', 'post'], 'post_type_exists')));

        foreach ($post_types as $post_type) {
            $query = new WP_Query([
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'ignore_sticky_posts' => true,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);

            if (!empty($query->posts[0])) {
                return $query->posts[0];
            }
        }

        return null;
    }

    private function csv_to_array(string $value): array
    {
        return array_values(array_filter(array_map(static function ($item) {
            return sanitize_title(trim($item));
        }, explode(',', $value))));
    }

    private function render_ad(array $attrs): string
    {
        $mode = sanitize_key((string) $attrs['adMode']);
        $output = '';

        if ('shortcode' === $mode && !empty($attrs['adShortcode'])) {
            $output = do_shortcode((string) $attrs['adShortcode']);
        } elseif ('placement' === $mode && !empty($attrs['adPlacement'])) {
            $output = do_shortcode('[the_ad_placement id="' . esc_attr((string) $attrs['adPlacement']) . '"]');
        } elseif (!empty($attrs['adId'])) {
            $output = do_shortcode('[the_ad id="' . esc_attr((string) $attrs['adId']) . '"]');
        }

        if (trim($output) === '') {
            $output = '<div class="pb-ad__placeholder"><span>Publicite</span><strong>Advanced Ads</strong></div>';
        }

        return '<div class="pb-ad">' . $output . '</div>';
    }

    private function render_image(array $attrs, string $class, bool $wrap = true): string
    {
        if (!empty($attrs['imageUrl'])) {
            $img = sprintf('<img src="%s" alt="">', esc_url($attrs['imageUrl']));
        } else {
            $img = '<span class="pb-placeholder"></span>';
        }

        return $wrap ? sprintf('<span class="%s">%s</span>', esc_attr($class), $img) : $img;
    }

    private function parse_items(string $items_text): array
    {
        $rows = preg_split('/\r\n|\r|\n/', $items_text);
        $items = [];

        foreach ($rows as $row) {
            $parts = array_map('trim', explode('|', $row));
            if (empty($parts[0]) && empty($parts[1])) {
                continue;
            }

            $items[] = [
                'kicker' => sanitize_text_field($parts[0] ?? ''),
                'title' => sanitize_text_field($parts[1] ?? $parts[0]),
                'views' => absint($parts[2] ?? 0),
            ];
        }

        return $items;
    }

    private function format_views(int $views): string
    {
        if ($views >= 1000) {
            return str_replace('.', ',', number_format_i18n($views / 1000, 1)) . 'k';
        }

        return number_format_i18n($views);
    }
}

final class Parresia_Block_Widget extends WP_Widget
{
    public function __construct()
    {
        parent::__construct(
            'parresia_block_widget',
            __('Parresia Block', 'parresia-blocks'),
            ['description' => __('Display a Parresia block in a sidebar.', 'parresia-blocks')]
        );
    }

    public function widget($args, $instance): void
    {
        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        echo Parresia_Blocks::instance()->render_block([
            'blockType' => sanitize_key($instance['blockType'] ?? 'rail-list'),
            'title' => sanitize_text_field($instance['title'] ?? 'Les plus lus'),
            'kicker' => sanitize_text_field($instance['kicker'] ?? 'Actualites'),
            'theme' => sanitize_key($instance['theme'] ?? 'light'),
            'itemCount' => absint($instance['itemCount'] ?? 3),
            'contentSource' => 'query',
            'postType' => sanitize_key($instance['postType'] ?? 'post'),
            'queryTaxonomy' => sanitize_key($instance['queryTaxonomy'] ?? 'category'),
            'queryTerms' => sanitize_text_field($instance['queryTerms'] ?? ''),
            'queryCount' => absint($instance['itemCount'] ?? 3),
            'queryOrderBy' => sanitize_key($instance['queryOrderBy'] ?? 'views'),
            'viewsMetaKey' => sanitize_key($instance['viewsMetaKey'] ?? 'post_views_count'),
            'topMode' => sanitize_key($instance['topMode'] ?? 'most-read-posts'),
            'kioskPostType' => sanitize_key($instance['kioskPostType'] ?? 'kiosque'),
            'views' => absint($instance['views'] ?? 0),
            'showViews' => !empty($instance['showViews']),
            'compact' => true,
            'adMode' => sanitize_key($instance['adMode'] ?? 'id'),
            'adId' => sanitize_text_field($instance['adId'] ?? ''),
            'adPlacement' => sanitize_text_field($instance['adPlacement'] ?? ''),
        ]);

        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function form($instance): void
    {
        $title = esc_attr($instance['title'] ?? 'Les plus lus');
        $type = esc_attr($instance['blockType'] ?? 'rail-list');
        $theme = esc_attr($instance['theme'] ?? 'light');
        $count = absint($instance['itemCount'] ?? 3);
        $ad_id = esc_attr($instance['adId'] ?? '');
        $post_type = esc_attr($instance['postType'] ?? 'post');
        $taxonomy = esc_attr($instance['queryTaxonomy'] ?? 'category');
        $terms = esc_attr($instance['queryTerms'] ?? '');
        $top_mode = esc_attr($instance['topMode'] ?? 'most-read-posts');
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title', 'parresia-blocks'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo $title; ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('blockType')); ?>"><?php esc_html_e('Block type', 'parresia-blocks'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('blockType')); ?>" name="<?php echo esc_attr($this->get_field_name('blockType')); ?>">
                <?php foreach (['rail-list', 'article-card', 'video-card', 'magazine', 'subscription', 'agenda', 'poll', 'ad'] as $option) : ?>
                    <option value="<?php echo esc_attr($option); ?>" <?php selected($type, $option); ?>><?php echo esc_html($option); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('theme')); ?>"><?php esc_html_e('Theme', 'parresia-blocks'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('theme')); ?>" name="<?php echo esc_attr($this->get_field_name('theme')); ?>">
                <option value="light" <?php selected($theme, 'light'); ?>>Light</option>
                <option value="dark" <?php selected($theme, 'dark'); ?>>Dark</option>
                <option value="soft" <?php selected($theme, 'soft'); ?>>Soft</option>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('itemCount')); ?>"><?php esc_html_e('Item count', 'parresia-blocks'); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr($this->get_field_id('itemCount')); ?>" name="<?php echo esc_attr($this->get_field_name('itemCount')); ?>" type="number" min="1" max="12" value="<?php echo esc_attr((string) $count); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('topMode')); ?>"><?php esc_html_e('Top 5 mode', 'parresia-blocks'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('topMode')); ?>" name="<?php echo esc_attr($this->get_field_name('topMode')); ?>">
                <?php foreach (['most-read-posts' => 'Most read articles', 'top-tags' => 'Best tags', 'top-categories' => 'Best categories', 'top-kiosks' => 'Most viewed kiosks'] as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($top_mode, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('postType')); ?>"><?php esc_html_e('Post type', 'parresia-blocks'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('postType')); ?>" name="<?php echo esc_attr($this->get_field_name('postType')); ?>" type="text" value="<?php echo $post_type; ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('queryTaxonomy')); ?>"><?php esc_html_e('Taxonomy', 'parresia-blocks'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('queryTaxonomy')); ?>" name="<?php echo esc_attr($this->get_field_name('queryTaxonomy')); ?>" type="text" value="<?php echo $taxonomy; ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('queryTerms')); ?>"><?php esc_html_e('Term slugs', 'parresia-blocks'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('queryTerms')); ?>" name="<?php echo esc_attr($this->get_field_name('queryTerms')); ?>" type="text" value="<?php echo $terms; ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('adId')); ?>"><?php esc_html_e('Advanced Ads ID', 'parresia-blocks'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('adId')); ?>" name="<?php echo esc_attr($this->get_field_name('adId')); ?>" type="text" value="<?php echo $ad_id; ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance): array
    {
        return [
            'title' => sanitize_text_field($new_instance['title'] ?? ''),
            'blockType' => sanitize_key($new_instance['blockType'] ?? 'rail-list'),
            'theme' => sanitize_key($new_instance['theme'] ?? 'light'),
            'itemCount' => absint($new_instance['itemCount'] ?? 3),
            'postType' => sanitize_key($new_instance['postType'] ?? 'post'),
            'queryTaxonomy' => sanitize_key($new_instance['queryTaxonomy'] ?? 'category'),
            'queryTerms' => sanitize_text_field($new_instance['queryTerms'] ?? ''),
            'topMode' => sanitize_key($new_instance['topMode'] ?? 'most-read-posts'),
            'adId' => sanitize_text_field($new_instance['adId'] ?? ''),
        ];
    }
}
