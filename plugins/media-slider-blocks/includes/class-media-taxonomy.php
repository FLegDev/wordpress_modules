<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MSB_Media_Taxonomy {

    const TAXONOMY = 'media_category';

    public static function init(): void {
        add_action( 'init',                        [ __CLASS__, 'register' ] );
        add_filter( 'attachment_fields_to_edit',   [ __CLASS__, 'add_field' ], 10, 2 );
        add_filter( 'attachment_fields_to_save',   [ __CLASS__, 'save_field' ], 10, 2 );
        add_action( 'restrict_manage_posts',       [ __CLASS__, 'list_filter' ] );
        add_filter( 'parse_query',                 [ __CLASS__, 'apply_list_filter' ] );
        add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'ajax_filter' ] );
        add_action( 'print_media_templates',       [ __CLASS__, 'modal_dropdown' ] );
    }

    public static function register(): void {
        register_taxonomy( self::TAXONOMY, 'attachment', [
            'labels' => [
                'name'          => __( 'Media Categories', 'media-slider-blocks' ),
                'singular_name' => __( 'Media Category',   'media-slider-blocks' ),
                'all_items'     => __( 'All Categories',   'media-slider-blocks' ),
                'edit_item'     => __( 'Edit Category',    'media-slider-blocks' ),
                'add_new_item'  => __( 'Add New Category', 'media-slider-blocks' ),
                'menu_name'     => __( 'Media Categories', 'media-slider-blocks' ),
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,   // requis pour l'API REST utilisée par l'éditeur
            'rewrite'           => [ 'slug' => 'media-category' ],
        ] );
    }

    public static function add_field( array $fields, WP_Post $post ): array {
        $terms    = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
        $selected = wp_get_post_terms( $post->ID, self::TAXONOMY, [ 'fields' => 'ids' ] );

        $html = '<div class="msb-media-cats">';
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            $html .= '<em>' . esc_html__( 'No categories yet.', 'media-slider-blocks' ) . '</em>';
        } else {
            foreach ( $terms as $term ) {
                $ck   = in_array( $term->term_id, $selected, true ) ? ' checked' : '';
                $html .= '<label><input type="checkbox" name="msb_media_category[]" value="'
                       . esc_attr( $term->term_id ) . '"' . $ck . '> '
                       . esc_html( $term->name ) . '</label>';
            }
        }
        $html .= '</div>';

        $fields['msb_media_category'] = [
            'label' => __( 'Categories', 'media-slider-blocks' ),
            'input' => 'html',
            'html'  => $html,
        ];
        return $fields;
    }

    public static function save_field( array $post, array $attachment ): array {
        $ids = isset( $attachment['msb_media_category'] )
            ? array_map( 'intval', (array) $attachment['msb_media_category'] )
            : [];
        wp_set_post_terms( $post['ID'], $ids, self::TAXONOMY );
        return $post;
    }

    public static function list_filter(): void {
        global $pagenow;
        if ( 'upload.php' !== $pagenow ) return;
        $terms    = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
        $selected = sanitize_text_field( $_GET['media_category'] ?? '' );
        echo '<select name="media_category"><option value="">' . esc_html__( 'All Media Categories', 'media-slider-blocks' ) . '</option>';
        foreach ( $terms as $term ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $term->slug ), selected( $selected, $term->slug, false ), esc_html( $term->name ) );
        }
        echo '</select>';
    }

    public static function apply_list_filter( WP_Query $q ): void {
        global $pagenow;
        if ( 'upload.php' !== $pagenow || empty( $_GET['media_category'] ) ) return;
        $q->set( 'tax_query', [ [ 'taxonomy' => self::TAXONOMY, 'field' => 'slug', 'terms' => sanitize_text_field( $_GET['media_category'] ) ] ] );
    }

    public static function ajax_filter( array $query ): array {
        if ( ! empty( $_REQUEST['query']['media_category'] ) ) {
            $query['tax_query'] = [ [ 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => array_map( 'intval', (array) $_REQUEST['query']['media_category'] ) ] ];
        }
        return $query;
    }

    public static function modal_dropdown(): void {
        $terms = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
        if ( empty( $terms ) || is_wp_error( $terms ) ) return;
        ?>
        <script>
        (function($){
            if(!wp.media?.view?.AttachmentFilters)return;
            var F=wp.media.view.AttachmentFilters.extend({
                createFilters:function(){
                    var f={all:{text:'<?php echo esc_js(__('All Categories','media-slider-blocks'));?>',props:{media_category:null},priority:10}};
                    <?php foreach($terms as $t):?>
                    f['<?php echo esc_js($t->slug);?>']={text:'<?php echo esc_js($t->name);?>',props:{media_category:[<?php echo(int)$t->term_id;?>]},priority:20};
                    <?php endforeach;?>
                    this.filters=f;
                }
            });
            var O=wp.media.view.MediaFrame.Select;
            wp.media.view.MediaFrame.Select=O.extend({
                browseContent:function(r){
                    O.prototype.browseContent.apply(this,arguments);
                    r.view?.toolbar?.set('MSBFilter',new F({controller:this,model:this.state().get('library'),priority:-75}).render());
                }
            });
        }(jQuery));
        </script>
        <?php
    }

    public static function get_all_terms() {
        return get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
    }
}
