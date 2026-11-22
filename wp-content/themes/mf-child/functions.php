<?php
/**
 * MF Child – funkcje motywu potomnego (L5).
 *
 * Prefiks mf_ przy każdej funkcji chroni przed konfliktami nazw
 * z rodzicem i wtyczkami; teksty przechodzą przez __()/esc_html__()
 * z domeną tekstową 'mf-child'.
 */

/**
 * Ładuje arkusz potomka PO arkuszu rodzica – TT1 rejestruje styl
 * pod uchwytem 'twenty-twenty-one-style', więc dodaję go jako zależność.
 */
add_action( 'wp_enqueue_scripts', 'mf_child_enqueue_styles' );
function mf_child_enqueue_styles() {
	wp_enqueue_style(
		'mf-child-style',
		get_stylesheet_uri(),
		array( 'twenty-twenty-one-style' ),
		wp_get_theme()->get( 'Version' )
	);
}

/**
 * Tłumaczenia motywu potomnego (katalog languages/).
 */
add_action( 'after_setup_theme', 'mf_child_setup' );
function mf_child_setup() {
	load_child_theme_textdomain( 'mf-child', get_stylesheet_directory() . '/languages' );
}

/**
 * Zajawka: 25 słów zamiast domyślnych 55 (krótsze karty na listach).
 */
add_filter( 'excerpt_length', 'mf_excerpt_length', 20 );
function mf_excerpt_length( $length ) {
	return 25; // dobrane pod karty wpisów na stronach list
}

/**
 * Koniec zajawki: samo wielokropek, bez doklejonego linku
 * „Continue reading” (TT1 po angielsku, brak tłumaczeń offline).
 * Bez tego esc_html( get_the_excerpt() ) w page-o-nas.php wypisze
 * znaki znaczników jako tekst. Priorytet 20, bo TT1 sam podmienia
 * excerpt_more w priorytecie 10, a functions.php rodzica ładuje się
 * PO potomku – z priorytetem 10 wygrywałby TT1.
 */
add_filter( 'excerpt_more', 'mf_excerpt_more', 20 );
function mf_excerpt_more( $more ) {
	return '…'; // znak UTF-8, nie encja HTML – esc_html() jej nie ruszy
}

/**
 * Notka kontaktowa pod treścią pojedynczego wpisu.
 * Tylko główna pętla pojedynczego wpisu – nie widżety, fragmenty ani listy.
 */
add_filter( 'the_content', 'mf_add_note' );
function mf_add_note( $content ) {
	if ( is_single() && in_the_loop() && is_main_query() ) {
		$note     = esc_html__( 'Masz pytania? Zadzwoń: +48 600 000 000.', 'mf-child' );
		$content .= '<p class="mf-note">' . $note . '</p>';
	}

	return $content; // filtr zawsze zwraca wartość
}

/**
 * Własne style bloków – widoczne w panelu „Style” edytora (L5 G4).
 *
 * mf-cien: przycisk z twardym cieniem w kolorze marki.
 * mf-karta: wybrana grupa jako karta (padding, obramowanie, zaokrąglenie).
 */
add_action( 'init', 'mf_register_block_styles' );
function mf_register_block_styles() {
	register_block_style(
		'core/button',
		array(
			'name'         => 'mf-cien',
			'label'        => __( 'Z cieniem', 'mf-child' ),
			'inline_style' => '.wp-block-button.is-style-mf-cien .wp-block-button__link {'
				. 'box-shadow: 0 4px 0 var(--mf-accent, #b45309);}',
		)
	);

	register_block_style(
		'core/group',
		array(
			'name'         => 'mf-karta',
			'label'        => __( 'Karta', 'mf-child' ),
			'inline_style' => '.wp-block-group.is-style-mf-karta {'
				. 'padding: 1.5rem; border: 1px solid var(--mf-accent, #b45309);'
				. 'border-radius: 12px; background: #f4ede6;}',
		)
	);
}