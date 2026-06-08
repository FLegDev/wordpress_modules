<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'PA_Main' ) ) {
class PA_Main {
    private static $widget_rendered = false;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
        add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
        add_action( 'save_post_classified_ad', array( __CLASS__, 'save_meta_box' ) );
        add_filter( 'the_content', array( __CLASS__, 'inject_ad_details' ), 20 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_shortcode( 'pa_submit_ad', array( __CLASS__, 'submit_shortcode' ) );
        add_shortcode( 'pa_user_ads', array( __CLASS__, 'user_ads_shortcode' ) );
        add_action( 'init', array( __CLASS__, 'handle_submit' ) );
    }

    public static function activate() {
        self::register_post_type();
        self::register_taxonomy();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function register_post_type() {
        register_post_type( 'classified_ad', array(
            'labels' => array(
                'name' => __( 'Annonces', 'parresia-annonces' ),
                'singular_name' => __( 'Annonce', 'parresia-annonces' ),
            ),
            'public' => true,
            'has_archive' => true,
            'rewrite' => array( 'slug' => 'annonces', 'with_front' => false ),
            'supports' => array( 'title', 'editor', 'author', 'thumbnail' ),
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-megaphone',
        ) );
    }

    public static function register_taxonomy() {
        register_taxonomy( 'ad_category', 'classified_ad', array(
            'labels' => array( 'name' => __( 'Catégories', 'parresia-annonces' ) ),
            'public' => true,
            'hierarchical' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
        ) );
    }

    public static function register_widget() {
        require_once PA_PLUGIN_PATH . 'includes/class-pa-widget.php';
        register_widget( 'PA_Ad_Details_Widget' );
    }

    public static function mark_widget_rendered() {
        self::$widget_rendered = true;
    }

    public static function enqueue_assets() {
        if ( ! is_singular( 'classified_ad' ) ) {
            return;
        }
        wp_enqueue_style( 'pa-front', PA_PLUGIN_URL . 'assets/css/front.css', array(), PA_PLUGIN_VERSION );
        wp_enqueue_script( 'pa-front', PA_PLUGIN_URL . 'assets/js/front.js', array(), PA_PLUGIN_VERSION, true );
    }

    public static function register_meta_box() {
        add_meta_box( 'pa_ad_details', __( 'Détails de l’annonce', 'parresia-annonces' ), array( __CLASS__, 'render_meta_box' ), 'classified_ad', 'normal', 'default' );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'pa_save_meta', 'pa_meta_nonce' );
        $fields = self::get_meta_fields( $post->ID );
        $statuses = array(
            'submitted' => 'Soumise',
            'under_review' => 'En revue',
            'approved' => 'Approuvée',
            'rejected' => 'Refusée',
            'awaiting_payment' => 'En attente de paiement',
            'paid' => 'Payée',
            'expired' => 'Expirée',
        );
        ?>
        <p><label><strong>Prix</strong></label><br><input type="text" class="widefat" name="pa_price" value="<?php echo esc_attr( $fields['price'] ); ?>"></p>
        <p><label><strong>Ville</strong></label><br><input type="text" class="widefat" name="pa_city" value="<?php echo esc_attr( $fields['city'] ); ?>"></p>
        <p><label><strong>Code postal</strong></label><br><input type="text" class="widefat" name="pa_postal_code" value="<?php echo esc_attr( $fields['postal_code'] ); ?>"></p>
        <p><label><strong>Nom du contact</strong></label><br><input type="text" class="widefat" name="pa_contact_name" value="<?php echo esc_attr( $fields['contact_name'] ); ?>"></p>
        <p><label><strong>Email du contact</strong></label><br><input type="email" class="widefat" name="pa_contact_email" value="<?php echo esc_attr( $fields['contact_email'] ); ?>"></p>
        <p><label><strong>Téléphone</strong></label><br><input type="text" class="widefat" name="pa_contact_phone" value="<?php echo esc_attr( $fields['contact_phone'] ); ?>"></p>
        <p><label><strong>Statut métier</strong></label><br><select class="widefat" name="pa_status"><?php foreach ( $statuses as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $fields['status'], $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></p>
        <p><label><strong>Expiration</strong></label><br><input type="datetime-local" class="widefat" name="pa_expires_at" value="<?php echo esc_attr( $fields['expires_at'] ); ?>"></p>
        <?php
    }

    public static function save_meta_box( $post_id ) {
        if ( ! isset( $_POST['pa_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pa_meta_nonce'] ) ), 'pa_save_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $map = array(
            'pa_price' => '_pa_price',
            'pa_city' => '_pa_city',
            'pa_postal_code' => '_pa_postal_code',
            'pa_contact_name' => '_pa_contact_name',
            'pa_contact_email' => '_pa_contact_email',
            'pa_contact_phone' => '_pa_contact_phone',
            'pa_status' => '_pa_status',
            'pa_expires_at' => '_pa_expires_at',
        );
        foreach ( $map as $field => $meta ) {
            if ( isset( $_POST[ $field ] ) ) {
                $value = wp_unslash( $_POST[ $field ] );
                $value = ( 'pa_contact_email' === $field ) ? sanitize_email( $value ) : sanitize_text_field( $value );
                update_post_meta( $post_id, $meta, $value );
            }
        }
    }

    public static function get_meta_fields( $post_id ) {
        return array(
            'price' => get_post_meta( $post_id, '_pa_price', true ),
            'city' => get_post_meta( $post_id, '_pa_city', true ),
            'postal_code' => get_post_meta( $post_id, '_pa_postal_code', true ),
            'contact_name' => get_post_meta( $post_id, '_pa_contact_name', true ),
            'contact_email' => get_post_meta( $post_id, '_pa_contact_email', true ),
            'contact_phone' => get_post_meta( $post_id, '_pa_contact_phone', true ),
            'status' => get_post_meta( $post_id, '_pa_status', true ),
            'expires_at' => get_post_meta( $post_id, '_pa_expires_at', true ),
        );
    }

    public static function get_currency_symbol() {
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        if ( strpos( $locale, 'en_GB' ) === 0 ) return '£';
        if ( strpos( $locale, 'en_US' ) === 0 ) return '$';
        if ( strpos( $locale, 'ja' ) === 0 ) return '¥';
        return '€';
    }

    public static function format_price( $price ) {
        if ( $price === '' || $price === null ) {
            return '';
        }
        return trim( $price ) . ' ' . self::get_currency_symbol();
    }

    public static function format_phone_href( $phone ) {
        return preg_replace( '/[^0-9\+]/', '', (string) $phone );
    }

    public static function render_details_html( $post_id, $mobile = false ) {
        $m = self::get_meta_fields( $post_id );
        $rows = array();
        if ( $m['price'] !== '' ) $rows[] = array( 'Prix', self::format_price( $m['price'] ) );
        if ( $m['city'] !== '' ) $rows[] = array( 'Ville', esc_html( $m['city'] ) );
        if ( $m['postal_code'] !== '' ) $rows[] = array( 'Code postal', esc_html( $m['postal_code'] ) );
        if ( $m['contact_name'] !== '' ) $rows[] = array( 'Nom du contact', esc_html( $m['contact_name'] ) );
        if ( $m['contact_email'] !== '' ) $rows[] = array( 'Email', '<a href="mailto:' . esc_attr( $m['contact_email'] ) . '">' . esc_html( $m['contact_email'] ) . '</a>' );
        if ( $m['contact_phone'] !== '' ) $rows[] = array( 'Téléphone', '<a href="tel:' . esc_attr( self::format_phone_href( $m['contact_phone'] ) ) . '">' . esc_html( $m['contact_phone'] ) . '</a>' );
        if ( $m['expires_at'] !== '' ) {
            $expires = str_replace( 'T', ' ', $m['expires_at'] );
            $rows[] = array( 'Expiration', esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires ) ) );
        }
        if ( empty( $rows ) ) {
            return '';
        }
        ob_start();
        ?>
        <div class="pa-ad-details-card<?php echo $mobile ? ' pa-mobile-only' : ''; ?>">
            <h3 class="pa-ad-details-title"><?php esc_html_e( 'Détails de l’annonce', 'parresia-annonces' ); ?></h3>
            <div class="pa-ad-details-list">
                <?php foreach ( $rows as $row ) : ?>
                    <div class="pa-ad-detail-row"><span class="pa-ad-detail-label"><?php echo esc_html( $row[0] ); ?> :</span> <span class="pa-ad-detail-value"><?php echo wp_kses_post( $row[1] ); ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function inject_ad_details( $content ) {
        if ( ! is_singular( 'classified_ad' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $post_id = get_the_ID();
        $mobile = '<div class="pa-mobile-accordion"><button type="button" class="pa-mobile-toggle" aria-expanded="false">' . esc_html__( 'Détails de l’annonce', 'parresia-annonces' ) . '</button><div class="pa-mobile-panel" hidden>' . self::render_details_html( $post_id, true ) . '</div></div>';

        if ( self::$widget_rendered ) {
            return $mobile . '<div class="pa-ad-content-only">' . $content . '</div>';
        }

        $fallback = '<div class="pa-ad-layout"><div class="pa-ad-content">' . $content . '</div><aside class="pa-ad-fallback-sidebar">' . self::render_details_html( $post_id, false ) . '</aside></div>';
        return $mobile . $fallback;
    }

    public static function submit_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<p>Vous devez être connecté pour déposer une annonce.</p>';
        }
        $terms = get_terms( array( 'taxonomy' => 'ad_category', 'hide_empty' => false ) );
        ob_start();
        ?>
        <form method="post" class="pa-submit-form">
            <?php wp_nonce_field( 'pa_front_submit', 'pa_front_submit_nonce' ); ?>
            <input type="text" name="pa_website" class="pa-honeypot" tabindex="-1" autocomplete="off">
            <p><label>Titre</label><input type="text" name="pa_title" required></p>
            <p><label>Description</label><textarea name="pa_description" rows="8" required></textarea></p>
            <p><label>Catégorie</label><select name="pa_category" required><option value="">Choisir</option><?php foreach ( $terms as $t ) : ?><option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?></select></p>
            <p><label>Prix</label><input type="text" name="pa_price"></p>
            <p><label>Ville</label><input type="text" name="pa_city"></p>
            <p><label>Code postal</label><input type="text" name="pa_postal_code"></p>
            <p><label>Nom du contact</label><input type="text" name="pa_contact_name"></p>
            <p><label>Email du contact</label><input type="email" name="pa_contact_email" required></p>
            <p><label>Téléphone</label><input type="text" name="pa_contact_phone"></p>
            <p><button type="submit" name="pa_front_submit_btn" value="1">Envoyer mon annonce</button></p>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function handle_submit() {
        if ( ! isset( $_POST['pa_front_submit_btn'] ) ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            return;
        }
        if ( ! isset( $_POST['pa_front_submit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pa_front_submit_nonce'] ) ), 'pa_front_submit' ) ) {
            return;
        }
        if ( ! empty( $_POST['pa_website'] ) ) {
            return;
        }

        $title = sanitize_text_field( wp_unslash( $_POST['pa_title'] ?? '' ) );
        $description = wp_kses_post( wp_unslash( $_POST['pa_description'] ?? '' ) );
        $category = absint( $_POST['pa_category'] ?? 0 );
        $email = sanitize_email( wp_unslash( $_POST['pa_contact_email'] ?? '' ) );

        if ( $title === '' || $description === '' || ! $category || ! is_email( $email ) ) {
            return;
        }

        $post_id = wp_insert_post( array(
            'post_type' => 'classified_ad',
            'post_title' => $title,
            'post_content' => $description,
            'post_status' => 'pending',
            'post_author' => get_current_user_id(),
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return;
        }

        wp_set_post_terms( $post_id, array( $category ), 'ad_category' );

        $fields = array( 'price', 'city', 'postal_code', 'contact_name', 'contact_email', 'contact_phone' );
        foreach ( $fields as $f ) {
            $key = 'pa_' . $f;
            $val = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
            $val = ( 'contact_email' === $f ) ? sanitize_email( $val ) : sanitize_text_field( $val );
            update_post_meta( $post_id, '_pa_' . $f, $val );
        }
        update_post_meta( $post_id, '_pa_status', 'submitted' );

        wp_safe_redirect( get_permalink( $post_id ) );
        exit;
    }

    public static function user_ads_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<p>Vous devez être connecté pour consulter vos annonces.</p>';
        }
        $q = new WP_Query( array(
            'post_type' => 'classified_ad',
            'author' => get_current_user_id(),
            'post_status' => array( 'pending', 'publish', 'draft' ),
            'posts_per_page' => 20,
        ) );
        ob_start();
        ?>
        <div class="pa-user-ads"><h2>Mes annonces</h2><?php if ( $q->have_posts() ) : ?><ul><?php while ( $q->have_posts() ) : $q->the_post(); ?><li><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></li><?php endwhile; wp_reset_postdata(); ?></ul><?php else : ?><p>Aucune annonce.</p><?php endif; ?></div>
        <?php
        return ob_get_clean();
    }
}
}
