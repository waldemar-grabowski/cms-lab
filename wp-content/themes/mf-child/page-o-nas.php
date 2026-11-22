<?php
/**
 * page-o-nas.php – szablon tylko dla strony o slugu "o-nas".
 *
 * Hierarchia: page-o-nas.php (potomek) → page.php (rodzic) → index.php.
 * Twenty Twenty-One otwiera znacznik <main> w header.php, więc tu wypisuję
 * tylko <article> – main nie może się powtórzyć.
 *
 * @package mf-child
 */

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class( 'mf-o-nas' ); ?>>
		<header class="entry-header alignwide">
			<h1 class="entry-title"><?php the_title(); ?></h1>
			<p class="mf-lead"><?php echo esc_html( get_the_excerpt() ); ?></p>
		</header>

		<div class="entry-content">
			<?php the_content(); ?>
		</div>

		<section class="mf-dane-firmy">
			<h2><?php esc_html_e( 'Dane warsztatu', 'mf-child' ); ?></h2>

			<address>
				<?php echo esc_html( get_bloginfo( 'name' ) ); ?><br />
				<?php esc_html_e( 'ul. Warsztatowa 7, 60-000 Poznań', 'mf-child' ); ?><br />
				<?php
				// Telefon klikalny: tel: bez spacji (escaping: esc_url dla href, esc_html dla tekstu).
				$mf_telefon = '+48 600 000 000';
				?>
				<?php esc_html_e( 'tel.', 'mf-child' ); ?>
				<a href="<?php echo esc_url( 'tel:' . preg_replace( '/\s+/', '', $mf_telefon ) ); ?>">
					<?php echo esc_html( $mf_telefon ); ?>
				</a><br />
				<a href="<?php echo esc_url( 'mailto:kontakt@autoserwis-waldi.example' ); ?>">
					<?php esc_html_e( 'kontakt@autoserwis-waldi.example', 'mf-child' ); ?>
				</a>
			</address>

			<p><?php esc_html_e( 'Godziny otwarcia: pn–pt 8:00–17:00, sob 9:00–13:00.', 'mf-child' ); ?></p>
			<p><em><?php esc_html_e( 'Dane fikcyjne – projekt edukacyjny na laboratorium CMS.', 'mf-child' ); ?></em></p>
		</section>
	</article>
	<?php
endwhile;

get_footer();