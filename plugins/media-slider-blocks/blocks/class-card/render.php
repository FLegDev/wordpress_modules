<?php
/**
 * Class Card — render.php v1.2
 *
 * Angles diagonaux indépendants sur 3 zones :
 *   1. Image
 *   2. Zone titre / sous-titre (gauche de la barre)
 *   3. Bouton SEE MORE (droite de la barre)
 *
 * Chaque zone a : angle (px) + direction
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ── Attributs ─────────────────────────────────────────────────────────────────
$image_url      = isset( $attributes['imageUrl'] )        ? esc_url( $attributes['imageUrl'] )        : '';
$image_alt      = isset( $attributes['imageAlt'] )        ? esc_attr( $attributes['imageAlt'] )        : '';
$title          = isset( $attributes['title'] )           ? $attributes['title']                        : '';
$subtitle       = isset( $attributes['subtitle'] )        ? $attributes['subtitle']                     : '';
$button_text    = isset( $attributes['buttonText'] )      ? $attributes['buttonText']                   : 'SEE MORE';
$button_url     = isset( $attributes['buttonUrl'] )       ? esc_url( $attributes['buttonUrl'] )         : '#';
$button_target  = ! empty( $attributes['buttonTarget'] )  ? '_blank'                                    : '_self';
$bar_bg         = isset( $attributes['barBgColor'] )      ? esc_attr( $attributes['barBgColor'] )       : '#363D41';
$title_color    = isset( $attributes['titleColor'] )      ? esc_attr( $attributes['titleColor'] )       : '#ffffff';
$subtitle_color = isset( $attributes['subtitleColor'] )   ? esc_attr( $attributes['subtitleColor'] )    : '#cccccc';
$btn_bg         = isset( $attributes['buttonBgColor'] )   ? esc_attr( $attributes['buttonBgColor'] )    : '#9AC14E';
$btn_bg_img     = isset( $attributes['buttonBgImage'] )   ? esc_url( $attributes['buttonBgImage'] )     : '';
$btn_text_color = isset( $attributes['buttonTextColor'] ) ? esc_attr( $attributes['buttonTextColor'] )  : '#ffffff';
$ratio          = isset( $attributes['imageRatio'] )      ? esc_attr( $attributes['imageRatio'] )       : '4/3';

// ── Angles par zone ───────────────────────────────────────────────────────────
$img_px  = isset( $attributes['imageAngle'] )           ? abs( (int) $attributes['imageAngle'] )           : 0;
$img_dir = isset( $attributes['imageAngleDirection'] )  ? (string) $attributes['imageAngleDirection']      : 'bottom-right';

$ttl_px  = isset( $attributes['titleAngle'] )           ? abs( (int) $attributes['titleAngle'] )           : 0;
$ttl_dir = isset( $attributes['titleAngleDirection'] )  ? (string) $attributes['titleAngleDirection']      : 'right';

$btn_px  = isset( $attributes['buttonAngle'] )          ? abs( (int) $attributes['buttonAngle'] )          : 0;
$btn_dir = isset( $attributes['buttonAngleDirection'] ) ? (string) $attributes['buttonAngleDirection']     : 'left';

// ── Helpers clip-path (protégés contre la redéclaration) ─────────────────────

if ( ! function_exists( 'msb_clip' ) ) {
    /**
     * Génère un clip-path CSS inline pour un angle diagonal.
     *
     * Conventions de direction :
     *   Image :
     *     bottom-right  → bas-droit coupé    polygon(0 0, 100% 0, (100%-px) 100%, 0 100%)
     *     bottom-left   → bas-gauche coupé   polygon(0 0, 100% 0, 100% 100%, px 100%)
     *     top-right     → haut-droit coupé   polygon(0 0, (100%-px) 0, 100% px, 100% 100%, 0 100%)
     *     top-left      → haut-gauche coupé  polygon(px 0, 100% 0, 100% 100%, 0 100%, 0 px)
     *
     *   Titre (zone gauche barre) :
     *     right  → bord droit diagonal (rentre vers le bouton)
     *     left   → bord gauche diagonal
     *
     *   Bouton (zone droite barre) :
     *     left   → bord gauche diagonal (rentre vers le titre)
     *     right  → bord droit diagonal
     */
    function msb_clip( int $px, string $dir ): string {
        if ( $px <= 0 ) return '';

        $clips = [
            // Image
            'bottom-right' => 'polygon(0 0,100%% 0,calc(100%% - %1$dpx) 100%%,0 100%%)',
            'bottom-left'  => 'polygon(0 0,100%% 0,100%% 100%%,%1$dpx 100%%)',
            'top-right'    => 'polygon(0 0,calc(100%% - %1$dpx) 0,100%% %1$dpx,100%% 100%%,0 100%%)',
            'top-left'     => 'polygon(%1$dpx 0,100%% 0,100%% 100%%,0 100%%,0 %1$dpx)',
            // Titre / Bouton
            'right'        => 'polygon(0 0,calc(100%% - %1$dpx) 0,100%% 100%%,0 100%%)',
            'left'         => 'polygon(%1$dpx 0,100%% 0,100%% 100%%,0 100%%)',
        ];

        $tpl = $clips[ $dir ] ?? $clips['bottom-right'];
        return 'clip-path:' . sprintf( $tpl, $px ) . ';';
    }
}

// ── Calcul des styles clip-path ───────────────────────────────────────────────
$img_clip = msb_clip( $img_px, $img_dir );
$ttl_clip = msb_clip( $ttl_px, $ttl_dir );
$btn_clip = msb_clip( $btn_px, $btn_dir );

// ── Style bouton ──────────────────────────────────────────────────────────────
$btn_bg_style = $btn_bg_img
    ? "background-image:url({$btn_bg_img});background-size:cover;background-position:center;"
    : "background-color:{$btn_bg};";

// ── Wrapper Gutenberg ─────────────────────────────────────────────────────────
$uid           = wp_unique_id( 'cc-' );
$wrapper_attrs = get_block_wrapper_attributes( [
    'class' => 'msb-class-card',
    'id'    => $uid,
] );
?>

<div <?php echo $wrapper_attrs; // phpcs:ignore ?>>

    <?php if ( $image_url ) : ?>
    <div class="msb-cc-image" style="aspect-ratio:<?php echo $ratio; ?>;<?php echo $img_clip; ?>">
        <img src="<?php echo $image_url; ?>" alt="<?php echo $image_alt; ?>" loading="lazy">
    </div>
    <?php else : ?>
    <div class="msb-cc-image msb-cc-image--placeholder" style="aspect-ratio:<?php echo $ratio; ?>">
        <span><?php esc_html_e( 'No image selected', 'media-slider-blocks' ); ?></span>
    </div>
    <?php endif; ?>

    <div class="msb-cc-bar" style="background-color:<?php echo $bar_bg; ?>">

        <!-- Zone titre — angle indépendant -->
        <div class="msb-cc-bar-content" style="<?php echo $ttl_clip; ?>background-color:<?php echo $bar_bg; ?>">
            <?php if ( $title ) : ?>
            <h3 class="msb-cc-title" style="color:<?php echo $title_color; ?>"><?php echo esc_html( $title ); ?></h3>
            <?php endif; ?>
            <?php if ( $subtitle ) : ?>
            <p class="msb-cc-subtitle" style="color:<?php echo $subtitle_color; ?>"><?php echo esc_html( $subtitle ); ?></p>
            <?php endif; ?>
        </div>

        <!-- Bouton SEE MORE — angle indépendant -->
        <a class="msb-cc-button"
           href="<?php echo $button_url; ?>"
           target="<?php echo esc_attr( $button_target ); ?>"
           <?php echo $button_target === '_blank' ? 'rel="noopener noreferrer"' : ''; ?>
           style="<?php echo $btn_bg_style; ?>color:<?php echo $btn_text_color; ?>;<?php echo $btn_clip; ?>"
        >
            <?php echo esc_html( $button_text ); ?>
        </a>

    </div>
</div>
