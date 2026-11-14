<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly ?>
<div class="bookly-js-dashboard-appointments">
    <div style="text-align: right">
    <span>
        <?php esc_html_e( 'Period', 'bookly-responsive-appointment-booking-tool' ) ?>
    </span>
        <span>
        <select id="bookly-filter-date">
            <option value="<?php echo date( 'Y-m-d', strtotime( '-7 days' ) ) ?> - <?php echo date( 'Y-m-d' ) ?>"><?php esc_html_e( 'Last 7 days', 'bookly-responsive-appointment-booking-tool' ) ?></option>
                <option value="<?php echo date( 'Y-m-d', strtotime( '-30 days' ) ) ?> - <?php echo date( 'Y-m-d' ) ?>"><?php esc_html_e( 'Last 30 days', 'bookly-responsive-appointment-booking-tool' ) ?></option>
                <option value="<?php echo date( 'Y-m-d', strtotime( 'first day of this month' ) ) ?> - <?php echo date( 'Y-m-d', strtotime( 'last day of this month' ) ) ?>"><?php esc_html_e( 'This month', 'bookly-responsive-appointment-booking-tool' ) ?></option>
                <option value="<?php echo date( 'Y-m-d', strtotime( 'first day of previous month' ) ) ?> - <?php echo date( 'Y-m-d', strtotime( 'last day of previous month' ) ) ?>"><?php esc_html_e( 'Last month', 'bookly-responsive-appointment-booking-tool' ) ?></option>
        </select>
    </span>
    </div>
    <table style="width: 100%">
        <tr>
            <td><?php esc_html_e( 'Approved appointments', 'bookly-responsive-appointment-booking-tool' ) ?></td>
            <td style="text-align: right"><a href="#" class="bookly-js-approved bookly-js-href-approved"></a></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Pending appointments', 'bookly-responsive-appointment-booking-tool' ) ?></td>
            <td style="text-align: right"><a href="#" class="bookly-js-pending bookly-js-href-pending"></a></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Total appointments', 'bookly-responsive-appointment-booking-tool' ) ?></td>
            <td style="text-align: right"><a href="#" class="bookly-js-total bookly-js-href-total"></a></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Revenue', 'bookly-responsive-appointment-booking-tool' ) ?></td>
            <td style="text-align: right"><a href="#" class="bookly-js-revenue bookly-js-href-revenue"></a></td>
        </tr>
    </table>
    <hr>
    <div id="bookly-dashboard-trend"></div>
</div>