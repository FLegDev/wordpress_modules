<?php
/**
 * Recent News — render.php (v1.1 — structure HTML corrigée)
 *
 * Structure HTML :
 *
 * .msb-rn-grid
 *   ├── .msb-rn-main                     (colonne gauche, pleine hauteur)
 *   │     ├── .msb-rn-main__img-wrap     (grande image)
 *   │     ├── .msb-rn-main__body         (titre + extrait)
 *   │     └── .msb-rn-bar               (barre colorée)
 *   │
 *   └── .msb-rn-secondary               (colonne droite, 2 cartes empilées)
 *         ├── .msb-rn-card
 *         │     ├── .msb-rn-card__inner  (image 40% | texte 60% — grid)
 *         │     │     ├── .msb-rn-card__img-wrap
 *         │     │     └── .msb-rn-card__body
 *         │     └── .msb-rn-bar
 *         └── .msb-rn-card  (idem)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$section_title       = isset( $attributes['sectionTitle'] )       ? $attributes['sectionTitle']                         : 'Recent News';
$section_title_color = isset( $attributes['sectionTitleColor'] )  ? esc_attr( $attributes['sectionTitleColor'] )        : '#151515';
$title_color         = isset( $attributes['titleColor'] )         ? esc_attr( $attributes['titleColor'] )               : '#151515';
$excerpt_color       = isset( $attributes['excerptColor'] )       ? esc_attr( $attributes['excerptColor'] )             : '#9B9B9B';

$main_img     = isset( $attributes['mainImageUrl'] )  ? esc_url( $attributes['mainImageUrl'] )  : '';
$main_alt     = isset( $attributes['mainImageAlt'] )  ? esc_attr( $attributes['mainImageAlt'] ) : '';
$main_title   = isset( $attributes['mainTitle'] )     ? $attributes['mainTitle']                 : '';
$main_excerpt = isset( $attributes['mainExcerpt'] )   ? $attributes['mainExcerpt']               : '';
$main_url     = isset( $attributes['mainUrl'] )       ? esc_url( $attributes['mainUrl'] )        : '#';
$main_bar     = isset( $attributes['mainBarColor'] )  ? esc_attr( $attributes['mainBarColor'] )  : '#9AC14E';

$n1_img     = isset( $attributes['news1ImageUrl'] ) ? esc_url( $attributes['news1ImageUrl'] )  : '';
$n1_alt     = isset( $attributes['news1ImageAlt'] ) ? esc_attr( $attributes['news1ImageAlt'] ) : '';
$n1_title   = isset( $attributes['news1Title'] )    ? $attributes['news1Title']                 : '';
$n1_excerpt = isset( $attributes['news1Excerpt'] )  ? $attributes['news1Excerpt']               : '';
$n1_url     = isset( $attributes['news1Url'] )      ? esc_url( $attributes['news1Url'] )        : '#';
$n1_bar     = isset( $attributes['news1BarColor'] ) ? esc_attr( $attributes['news1BarColor'] )  : '#78ABF1';

$n2_img     = isset( $attributes['news2ImageUrl'] ) ? esc_url( $attributes['news2ImageUrl'] )  : '';
$n2_alt     = isset( $attributes['news2ImageAlt'] ) ? esc_attr( $attributes['news2ImageAlt'] ) : '';
$n2_title   = isset( $attributes['news2Title'] )    ? $attributes['news2Title']                 : '';
$n2_excerpt = isset( $attributes['news2Excerpt'] )  ? $attributes['news2Excerpt']               : '';
$n2_url     = isset( $attributes['news2Url'] )      ? esc_url( $attributes['news2Url'] )        : '#';
$n2_bar     = isset( $attributes['news2BarColor'] ) ? esc_attr( $attributes['news2BarColor'] )  : '#FF9138';

$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'msb-recent-news' ] );

// Helper : image ou placeholder — protégé contre la redéclaration
if ( ! function_exists( 'msb_rn_img' ) ) {
    function msb_rn_img( string $img, string $alt, string $url, string $css_class, string $placeholder_label ): string {
        ob_start();
        ?>
        <a class="<?php echo esc_attr( $css_class ); ?>" href="<?php echo $url; ?>">
            <?php if ( $img ) : ?>
            <img src="<?php echo $img; ?>" alt="<?php echo $alt; ?>" loading="lazy">
            <?php else : ?>
            <div class="msb-rn-placeholder">
                <span><?php echo esc_html( $placeholder_label ); ?></span>
            </div>
            <?php endif; ?>
        </a>
        <?php
        return ob_get_clean();
    }
}
?>

<div <?php echo $wrapper_attrs; // phpcs:ignore ?>>

    <?php if ( $section_title ) : ?>
    <h2 class="msb-rn-section-title" style="color:<?php echo $section_title_color; ?>">
        <?php echo esc_html( $section_title ); ?>
    </h2>
    <?php endif; ?>

    <div class="msb-rn-grid">

        <!-- ── Colonne gauche ───────────────────────────────────────────────── -->
        <div class="msb-rn-main">

            <?php echo msb_rn_img( $main_img, $main_alt, $main_url, 'msb-rn-main__img-wrap', 'Image principale' ); // phpcs:ignore ?>

            <div class="msb-rn-main__body">
                <?php if ( $main_title ) : ?>
                <h3 class="msb-rn-title" style="color:<?php echo $title_color; ?>">
                    <a href="<?php echo $main_url; ?>"><?php echo esc_html( $main_title ); ?></a>
                </h3>
                <?php endif; ?>
                <?php if ( $main_excerpt ) : ?>
                <p class="msb-rn-excerpt" style="color:<?php echo $excerpt_color; ?>">
                    <?php echo esc_html( $main_excerpt ); ?>
                </p>
                <?php endif; ?>
            </div>

            <div class="msb-rn-bar" style="background-color:<?php echo $main_bar; ?>"></div>

        </div><!-- .msb-rn-main -->

        <!-- ── Colonne droite ───────────────────────────────────────────────── -->
        <div class="msb-rn-secondary">

            <?php
            foreach ( [
                [ $n1_img, $n1_alt, $n1_title, $n1_excerpt, $n1_url, $n1_bar, 'Image article 1' ],
                [ $n2_img, $n2_alt, $n2_title, $n2_excerpt, $n2_url, $n2_bar, 'Image article 2' ],
            ] as $item ) :
                [ $img, $alt, $ttl, $exc, $url, $bar, $ph ] = $item;
            ?>
            <div class="msb-rn-card">

                <!-- .msb-rn-card__inner : grid image 40% | texte 60% -->
                <div class="msb-rn-card__inner">

                    <?php echo msb_rn_img( $img, $alt, $url, 'msb-rn-card__img-wrap', $ph ); // phpcs:ignore ?>

                    <div class="msb-rn-card__body">
                        <?php if ( $ttl ) : ?>
                        <h3 class="msb-rn-title" style="color:<?php echo $title_color; ?>">
                            <a href="<?php echo $url; ?>"><?php echo esc_html( $ttl ); ?></a>
                        </h3>
                        <?php endif; ?>
                        <?php if ( $exc ) : ?>
                        <p class="msb-rn-excerpt" style="color:<?php echo $excerpt_color; ?>">
                            <?php echo esc_html( $exc ); ?>
                        </p>
                        <?php endif; ?>
                    </div><!-- .msb-rn-card__body -->

                </div><!-- .msb-rn-card__inner -->

                <div class="msb-rn-bar" style="background-color:<?php echo $bar; ?>"></div>

            </div><!-- .msb-rn-card -->
            <?php endforeach; ?>

        </div><!-- .msb-rn-secondary -->

    </div><!-- .msb-rn-grid -->
</div>
