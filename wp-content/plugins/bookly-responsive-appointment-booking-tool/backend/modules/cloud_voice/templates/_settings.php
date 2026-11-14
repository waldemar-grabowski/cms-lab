<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Settings\Selects;
/**
 * @var \Bookly\Backend\Modules\CloudVoice\Page $self
 * @var \Bookly\Lib\Cloud\Voice $voice
 */
?>
<div class="row">
    <div class="col-md-12">
        <?php Selects::renderSingleValue( 'bookly_cloud_voice_language', $voice->language, __( 'Language', 'bookly-responsive-appointment-booking-tool' ), __( 'Select the language of your notifications', 'bookly-responsive-appointment-booking-tool' ), $self::getLanguages() ) ?>
    </div>
</div>