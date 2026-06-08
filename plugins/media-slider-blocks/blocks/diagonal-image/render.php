<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$image_url    = isset( $attributes['imageUrl'] )    ? esc_url( $attributes['imageUrl'] )    : '';
$image_alt    = isset( $attributes['imageAlt'] )    ? esc_attr( $attributes['imageAlt'] )    : '';
$ratio        = isset( $attributes['imageRatio'] )  ? esc_attr( $attributes['imageRatio'] )  : '16/9';
$fit          = isset( $attributes['objectFit'] )   ? esc_attr( $attributes['objectFit'] )   : 'cover';
$link_url     = isset( $attributes['linkUrl'] )     ? esc_url( $attributes['linkUrl'] )      : '';
$link_target  = ! empty( $attributes['linkTarget'] ) ? '_blank'                              : '_self';
$enable_lightbox = ! empty( $attributes['enableLightbox'] );
$radius       = isset( $attributes['borderRadius'] ) ? (int) $attributes['borderRadius']    : 0;
$angle_px     = isset( $attributes['angleSize'] )   ? abs( (int) $attributes['angleSize'] ) : 0;
$angle_dir    = isset( $attributes['angleDirection'] ) ? $attributes['angleDirection']       : 'bottom-right';

// Utilise la même fonction msb_clip() déclarée dans le class-card render.php
// Si ce bloc est rendu seul, on la déclare ici aussi
if ( ! function_exists( 'msb_clip' ) ) {
    function msb_clip( int $px, string $dir ): string {
        if ( $px <= 0 ) return '';
        $clips = [
            'bottom-right' => 'polygon(0 0,100%% 0,calc(100%% - %1$dpx) 100%%,0 100%%)',
            'bottom-left'  => 'polygon(0 0,100%% 0,100%% 100%%,%1$dpx 100%%)',
            'top-right'    => 'polygon(0 0,calc(100%% - %1$dpx) 0,100%% %1$dpx,100%% 100%%,0 100%%)',
            'top-left'     => 'polygon(%1$dpx 0,100%% 0,100%% 100%%,0 100%%,0 %1$dpx)',
            'right'        => 'polygon(0 0,calc(100%% - %1$dpx) 0,100%% 100%%,0 100%%)',
            'left'         => 'polygon(%1$dpx 0,100%% 0,100%% 100%%,0 100%%)',
        ];
        $tpl = $clips[ $dir ] ?? $clips['bottom-right'];
        return 'clip-path:' . sprintf( $tpl, $px ) . ';';
    }
}

$clip = msb_clip( $angle_px, $angle_dir );

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'msb-diagonal-image' ] );
?>

<div <?php echo $wrapper_attrs; // phpcs:ignore ?>>
    <?php if ( $image_url ) : ?>

    <?php if ( $enable_lightbox ) : ?>
    <button type="button"
            class="msb-di-lightbox-trigger msb-lightbox-trigger"
            data-msb-full="<?php echo $image_url; ?>"
            data-msb-title="<?php echo esc_attr( $image_alt ); ?>"
            data-msb-caption=""
            aria-label="<?php esc_attr_e( 'Open image gallery', 'media-slider-blocks' ); ?>">
    <?php elseif ( $link_url ) : ?>
    <a href="<?php echo $link_url; ?>" target="<?php echo esc_attr( $link_target ); ?>"
       <?php echo $link_target === '_blank' ? 'rel="noopener noreferrer"' : ''; ?>>
    <?php endif; ?>

    <div class="msb-di-wrap" style="aspect-ratio:<?php echo $ratio; ?>;border-radius:<?php echo $radius; ?>px;<?php echo $clip; ?>">
        <img src="<?php echo $image_url; ?>" alt="<?php echo $image_alt; ?>"
             loading="lazy"
             style="object-fit:<?php echo $fit; ?>;border-radius:<?php echo $radius; ?>px">
    </div>

    <?php if ( $enable_lightbox ) : ?>
    </button>
    <?php elseif ( $link_url ) : ?>
    </a>
    <?php endif; ?>

    <?php else : ?>
    <div class="msb-di-placeholder">
        <span><?php esc_html_e( 'Cliquer pour sélectionner une image', 'media-slider-blocks' ); ?></span>
    </div>
    <?php endif; ?>
</div>
