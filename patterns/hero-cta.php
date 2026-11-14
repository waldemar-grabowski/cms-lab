<?php
/**
 * Title: Hero z wezwaniem do działania
 * Slug: autoserwis-waldi/hero-cta
 * Categories: featured, banner
 * Description: Sekcja otwierająca: nagłówek H1, korzyść i przycisk CTA na zdjęciu warsztatu.
 */

$cta = esc_html__( 'Umów wizytę online', 'autoserwis-waldi' );
// Zdjęcie: Pexels 33169327 (Licencja Pexels), z biblioteki mediów; w L4 można je przenieść do assets/ motywu i użyć get_theme_file_uri().
$img = esc_url( content_url( '/uploads/2026/11/pexels-warsztat-33169327.jpg' ) );
$alt = esc_attr__( 'Mechanicy przy samochodach w warsztacie', 'autoserwis-waldi' );
?>
<!-- wp:cover {"url":"<?php echo $img; ?>","alt":"<?php echo $alt; ?>","dimRatio":70,"customOverlayColor":"#393232","isUserOverlayColor":false,"contentPosition":"top left","focalPoint":{"x":0.6,"y":0.7},"sizeSlug":"full","align":"full"} -->
<div class="wp-block-cover alignfull has-custom-content-position is-position-top-left"><img class="wp-block-cover__image-background size-full" alt="<?php echo $alt; ?>" src="<?php echo $img; ?>" style="object-position:60% 70%" data-object-fit="cover" data-object-position="60% 70%"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim-70 has-background-dim" style="background-color:#393232"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading"><?php esc_html_e( 'Naprawa i diagnostyka samochodów', 'autoserwis-waldi' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p><?php esc_html_e( 'Osobowe i dostawcze do 3.5 t, także dla małych firm', 'autoserwis-waldi' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:buttons {"layout":{"type":"flex","verticalAlignment":"bottom","justifyContent":"left"}} -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/umow-wizyte/"><?php echo $cta; ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover -->
