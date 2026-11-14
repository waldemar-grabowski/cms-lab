<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
/** @var string $text */
?>
<div class="bookly:alert bookly:alert-info bookly:mt-4 <?php echo esc_attr( $class ?? 'bookly:mb-0' ) ?>">
    <svg class="bookly:alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
    <div class="bookly:alert-content">
        <?php echo $text ?>
    </div>
</div>
