<?php
/**
 * Media Slider — render.php
 * Rendu serveur avec :
 *   - Overlay configurable par slide (texte, position, couleurs)
 *   - Angle diagonal via clip-path
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ── Attributs ─────────────────────────────────────────────────────────────────
$categories       = isset( $attributes['categories'] )      ? (array)  $attributes['categories']      : [];
$number           = isset( $attributes['numberOfImages'] )  ? (int)    $attributes['numberOfImages']  : 12;
$order_by         = isset( $attributes['orderBy'] )         ? (string) $attributes['orderBy']         : 'date';
$show_title       = isset( $attributes['showTitle'] )       ? (bool)   $attributes['showTitle']       : false;
$display_title    = isset( $attributes['displaySlideTitle'] ) ? (bool) $attributes['displaySlideTitle'] : true;
$show_caption     = isset( $attributes['showCaption'] )     ? (bool)   $attributes['showCaption']     : false;
$link_images      = isset( $attributes['linkImages'] )      ? (bool)   $attributes['linkImages']      : true;
$slides_visible   = isset( $attributes['slidesVisible'] )   ? (int)    $attributes['slidesVisible']   : 3;
$slides_tablet    = isset( $attributes['slidesTablet'] )    ? (int)    $attributes['slidesTablet']    : 2;
$slides_mobile    = isset( $attributes['slidesMobile'] )    ? (int)    $attributes['slidesMobile']    : 1;
$gap              = isset( $attributes['gap'] )             ? (int)    $attributes['gap']             : 16;
$autoplay         = isset( $attributes['autoplay'] )        ? (bool)   $attributes['autoplay']        : true;
$autoplay_speed   = isset( $attributes['autoplaySpeed'] )   ? (int)    $attributes['autoplaySpeed']   : 4000;
$transition_speed = isset( $attributes['transitionSpeed'] ) ? (int)    $attributes['transitionSpeed'] : 600;
$show_arrows      = isset( $attributes['showArrows'] )      ? (bool)   $attributes['showArrows']      : true;
$show_dots        = isset( $attributes['showDots'] )        ? (bool)   $attributes['showDots']        : true;
$loop             = isset( $attributes['loop'] )            ? (bool)   $attributes['loop']            : true;
$aspect_ratio     = isset( $attributes['aspectRatio'] )     ? (string) $attributes['aspectRatio']     : '16/9';
$object_fit       = isset( $attributes['objectFit'] )       ? (string) $attributes['objectFit']       : 'cover';
$border_radius    = isset( $attributes['borderRadius'] )    ? (int)    $attributes['borderRadius']    : 0;
$arrow_color      = isset( $attributes['arrowColor'] )      ? (string) $attributes['arrowColor']      : '#ffffff';
$arrow_bg_color   = isset( $attributes['arrowBgColor'] )    ? (string) $attributes['arrowBgColor']    : 'rgba(0,0,0,0.5)';
$dot_color        = isset( $attributes['dotColor'] )        ? (string) $attributes['dotColor']        : 'rgba(255,255,255,0.5)';
$dot_active_color = isset( $attributes['dotActiveColor'] )  ? (string) $attributes['dotActiveColor']  : '#ffffff';
$title_color      = isset( $attributes['titleColor'] )      ? (string) $attributes['titleColor']      : '#ffffff';
$max_width        = isset( $attributes['maxWidth'] )        ? esc_attr( $attributes['maxWidth'] )      : '100%';
$block_height     = isset( $attributes['blockHeight'] )     ? esc_attr( $attributes['blockHeight'] )   : '';

// Angle diagonal
$image_angle      = isset( $attributes['imageAngle'] )      ? abs( (int) $attributes['imageAngle'] )  : 0;
$angle_direction  = isset( $attributes['angleDirection'] )  ? $attributes['angleDirection']            : 'right';

// Overlays par slide
$slide_overlays   = isset( $attributes['slideOverlays'] ) && is_array( $attributes['slideOverlays'] )
    ? $attributes['slideOverlays']
    : [];

// Overlay global
$global_overlay   = isset( $attributes['globalOverlay'] ) && is_array( $attributes['globalOverlay'] )
    ? $attributes['globalOverlay']
    : [];

// ── Clip-path helper ──────────────────────────────────────────────────────────
if ( ! function_exists( 'msb_clip_path' ) ) {
    function msb_clip_path( int $angle, string $direction ): string {
        if ( $angle <= 0 ) return '';
        if ( $direction === 'left' ) {
            return sprintf(
                'clip-path: polygon(%dpx 0, 100%% 0, 100%% 100%%, 0 100%%, 0 %dpx);',
                $angle, $angle
            );
        }
        return sprintf(
            'clip-path: polygon(0 0, calc(100%% - %dpx) 0, 100%% %dpx, 100%% 100%%, 0 100%%);',
            $angle, $angle
        );
    }
}

$clip_style = msb_clip_path( $image_angle, $angle_direction );

// ── Récupération des images ───────────────────────────────────────────────────
$category_ids = array_map( 'intval', $categories );
$images       = class_exists( 'MSB_Media_Ajax' )
    ? MSB_Media_Ajax::query_images( $category_ids, $number, $order_by )
    : [];

if ( empty( $images ) ) {
    echo '<div class="msb-slider-empty">'
       . esc_html__( 'No images found.', 'media-slider-blocks' )
       . '</div>';
    return;
}

// ── CSS scoped ────────────────────────────────────────────────────────────────
$uid = wp_unique_id( 'msb-' );
$inline_css = "
#{$uid} .msb-arrow { color:{$arrow_color}; background:{$arrow_bg_color}; }
#{$uid} .msb-dot   { background:{$dot_color}; }
#{$uid} .msb-dot.is-active { background:{$dot_active_color}; }
";

// ── Config JS ─────────────────────────────────────────────────────────────────
$cfg = esc_attr( wp_json_encode( [
    'autoplay'        => $autoplay,
    'autoplaySpeed'   => $autoplay_speed,
    'transitionSpeed' => $transition_speed,
    'slidesVisible'   => $slides_visible,
    'slidesTablet'    => $slides_tablet,
    'slidesMobile'    => $slides_mobile,
    'gap'             => $gap,
    'loop'            => $loop,
    'showArrows'      => $show_arrows,
    'showDots'        => $show_dots,
] ) );

// ── Slides HTML ───────────────────────────────────────────────────────────────
$slides_html = '';

foreach ( $images as $img ) {
    $img_id  = (int) $img['id'];
    $src      = ! empty( $img['src']['large'] ) ? $img['src']['large'] : $img['src']['full'];
    $full_src = ! empty( $img['src']['full'] ) ? $img['src']['full'] : $src;
    $title    = $img['title'] ?? '';
    $caption  = $img['caption'] ?? '';
    $alt      = esc_attr( $img['alt'] ?: $title );

    // ── Clip path sur l'image
    // On remet aspect-ratio sur le slide-inner — c'est lui qui contrôle la hauteur.
    // Le track a aussi aspect-ratio comme double contrainte.
    $inner_style = 'border-radius:' . $border_radius . 'px;';
    $media_style = 'aspect-ratio:' . esc_attr( $aspect_ratio ) . ';border-radius:' . $border_radius . 'px;position:relative;overflow:hidden;';
    if ( $block_height ) {
        $media_style .= 'height:' . esc_attr( $block_height ) . ';aspect-ratio:unset;';
    }
    $img_style   = 'object-fit:' . esc_attr( $object_fit ) . ';border-radius:' . $border_radius . 'px;';
    if ( $clip_style ) {
        $media_style .= $clip_style;
    }

    // ── Overlay par slide
    $overlay_html = '';
    $overlay = $slide_overlays[ $img_id ] ?? [];

    // Fallback : titre/caption globals si activés
    if ( empty( $overlay ) && ( $show_title || $show_caption ) ) {
        $overlay = [
            'text'      => $show_title   ? $title   : ( $show_caption ? $caption : '' ),
            'position'  => 'bottom-left',
            'textColor' => $title_color,
            'bgColor'   => 'rgba(0,0,0,0.45)',
            'fontSize'  => 16,
        ];
    }

    if ( ! empty( $overlay ) && ! empty( $overlay['text'] ) ) {
        $pos      = $overlay['position']  ?? 'bottom-left';
        $tcol     = $overlay['textColor'] ?? '#ffffff';
        $bgcol    = $overlay['bgColor']   ?? 'rgba(0,0,0,0.45)';
        $fsize    = isset( $overlay['fontSize'] ) ? (int) $overlay['fontSize'] : 16;

        // Position → CSS
        $pos_map = [
            'top-left'      => 'top:16px;left:16px;',
            'top-center'    => 'top:16px;left:50%;transform:translateX(-50%);',
            'top-right'     => 'top:16px;right:16px;',
            'middle-left'   => 'top:50%;left:16px;transform:translateY(-50%);',
            'middle-center' => 'top:50%;left:50%;transform:translate(-50%,-50%);',
            'middle-right'  => 'top:50%;right:16px;transform:translateY(-50%);',
            'bottom-left'   => 'bottom:16px;left:16px;',
            'bottom-center' => 'bottom:16px;left:50%;transform:translateX(-50%);',
            'bottom-right'  => 'bottom:16px;right:16px;',
        ];
        $pos_css = $pos_map[ $pos ] ?? $pos_map['bottom-left'];

        $overlay_html = sprintf(
            '<div class="msb-slide-overlay" style="%s background:%s; color:%s; font-size:%dpx;">%s</div>',
            esc_attr( $pos_css ),
            esc_attr( $bgcol ),
            esc_attr( $tcol ),
            $fsize,
            esc_html( $overlay['text'] )
        );
    }

    // ── Overlay global (affiché sur toutes les slides)
    $go_html = '';
    if ( ! empty( $global_overlay ) && ( ! empty( $global_overlay['title'] ) || ! empty( $global_overlay['text'] ) ) ) {
        $go_title   = $global_overlay['title']     ?? '';
        $go_text    = $global_overlay['text']      ?? '';
        $go_pos     = $global_overlay['position']  ?? 'bottom-left';
        $go_color   = $global_overlay['textColor'] ?? '#ffffff';
        $go_bg_hex  = $global_overlay['bgColor']   ?? '#000000';
        $go_opacity = isset( $global_overlay['opacity'] ) ? (int) $global_overlay['opacity'] : 50;
        $go_fsize   = isset( $global_overlay['fontSize'] ) ? (int) $global_overlay['fontSize'] : 18;

        // hex → rgba avec opacité
        if ( preg_match( '/^#([a-f0-9]{6})$/i', $go_bg_hex, $hex_m ) ) {
            $rgb = [
                hexdec( substr( $hex_m[1], 0, 2 ) ),
                hexdec( substr( $hex_m[1], 2, 2 ) ),
                hexdec( substr( $hex_m[1], 4, 2 ) ),
            ];
            $go_bg_rgba = 'rgba(' . implode( ',', $rgb ) . ',' . round( $go_opacity / 100, 2 ) . ')';
        } else {
            $go_bg_rgba = $go_bg_hex;
        }

        $go_pos_map = [
            'top-left'      => 'top:16px;left:16px;',
            'top-center'    => 'top:16px;left:50%;transform:translateX(-50%);',
            'top-right'     => 'top:16px;right:16px;',
            'middle-left'   => 'top:50%;left:16px;transform:translateY(-50%);',
            'middle-center' => 'top:50%;left:50%;transform:translate(-50%,-50%);',
            'middle-right'  => 'top:50%;right:16px;transform:translateY(-50%);',
            'bottom-left'   => 'bottom:16px;left:16px;',
            'bottom-center' => 'bottom:16px;left:50%;transform:translateX(-50%);',
            'bottom-right'  => 'bottom:16px;right:16px;',
        ];
        $go_pos_css = $go_pos_map[ $go_pos ] ?? $go_pos_map['bottom-left'];

        $go_html = '<div class="msb-slide-overlay msb-overlay-global" style="'
            . esc_attr( $go_pos_css )
            . 'background:' . esc_attr( $go_bg_rgba ) . ';color:' . esc_attr( $go_color ) . ';">';
        if ( $go_title ) {
            $go_html .= '<div class="msb-overlay-title" style="font-size:' . $go_fsize . 'px;font-weight:700;line-height:1.2;' . ( $go_text ? 'margin-bottom:4px;' : '' ) . '">' . esc_html( $go_title ) . '</div>';
        }
        if ( $go_text ) {
            $go_html .= '<div class="msb-overlay-text" style="font-size:' . round( $go_fsize * 0.7 ) . 'px;line-height:1.4;opacity:.9;">' . esc_html( $go_text ) . '</div>';
        }
        $go_html .= '</div>';
    }

    $title_html = $display_title && $title
        ? '<h3 class="msb-slide-title">' . esc_html( $title ) . '</h3>'
        : '';
    $caption_html = $show_caption && $caption
        ? '<p class="msb-slide-caption">' . esc_html( $caption ) . '</p>'
        : '';
    $body_html = ( $title_html || $caption_html )
        ? '<div class="msb-slide-body">' . $title_html . $caption_html . '</div>'
        : '';

    $inner = '<div class="msb-slide-inner" style="' . $inner_style . '">'
           . '<div class="msb-slide-media" style="' . $media_style . '">'
           . '<img src="' . esc_url( $src ) . '" alt="' . $alt . '" loading="lazy" style="' . $img_style . '">'
           . $overlay_html
           . $go_html
           . '</div>'
           . $body_html
           . '</div>';

    if ( $link_images ) {
        $inner = '<button type="button" class="msb-slide-lightbox-trigger msb-lightbox-trigger" data-msb-full="' . esc_url( $full_src ) . '" data-msb-title="' . esc_attr( $title ) . '" data-msb-caption="' . esc_attr( $caption ) . '" aria-label="' . esc_attr__( 'Open image gallery', 'media-slider-blocks' ) . '">' . $inner . '</button>';
    }

    $slides_html .= '<div class="msb-slide">' . $inner . '</div>';
}

// ── Navigation ────────────────────────────────────────────────────────────────
$arrows_html = '';
if ( $show_arrows ) {
    $arrows_html  = '<button class="msb-arrow msb-arrow-prev" aria-label="' . esc_attr__( 'Previous', 'media-slider-blocks' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></button>';
    $arrows_html .= '<button class="msb-arrow msb-arrow-next" aria-label="' . esc_attr__( 'Next', 'media-slider-blocks' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></button>';
}
$dots_html = $show_dots ? '<div class="msb-dots" role="tablist"></div>' : '';

$wrapper_attrs = get_block_wrapper_attributes( [
    'id'       => $uid,
    'class'    => 'msb-slider-wrapper',
    'data-msb' => esc_attr( $cfg ),
    'style'    => 'max-width:' . $max_width . ';',
] );

// Style du track : ratio + hauteur forcée si définie
$track_style = '--msb-ratio:' . esc_attr( $aspect_ratio ) . ';';
?>
<style><?php echo $inline_css; // phpcs:ignore ?></style>
<div <?php echo $wrapper_attrs; // phpcs:ignore ?>>
    <div class="msb-slider-track" style="<?php echo esc_attr( $track_style ); ?>">
        <div class="msb-slides"><?php echo $slides_html; // phpcs:ignore ?></div>
    </div>
    <?php echo $arrows_html; // phpcs:ignore ?>
    <?php echo $dots_html;   // phpcs:ignore ?>
</div>
