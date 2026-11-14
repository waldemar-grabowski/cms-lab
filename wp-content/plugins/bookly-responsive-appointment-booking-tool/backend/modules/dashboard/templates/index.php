<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Dashboard;
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Backend\Modules\Dashboard\Proxy;
use Bookly\Lib\Utils\DateTime;
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'Dashboard', 'bookly-responsive-appointment-booking-tool' ) ) ?>

    <?php // ── Unified first-load skeleton ──────────────────────────────────────
          // Painted server-side so the page shows its final shape instantly. The whole
          // dashboard loads in one combined request (bookly_get_dashboard_data); once it
          // has rendered every section, dashboard.js removes this skeleton and reveals
          // #bookly-dashboard-content all at once — no per-section flashes. ?>
    <div id="bookly-dashboard-skeleton" aria-hidden="true">
        <!-- Filter bar -->
        <div class="bookly:card bookly:mb-4">
            <div class="bookly:card-body">
                <div class="bookly:flex bookly:flex-wrap bookly:items-center bookly:gap-2">
                    <div class="bookly:h-9 bookly:w-48 bookly:rounded-md bookly:bg-muted bookly:animate-pulse"></div>
                    <div class="bookly:h-9 bookly:w-40 bookly:rounded-md bookly:bg-muted bookly:animate-pulse"></div>
                    <div class="bookly:h-9 bookly:w-40 bookly:rounded-md bookly:bg-muted bookly:animate-pulse"></div>
                    <div class="bookly:h-9 bookly:w-28 bookly:rounded-md bookly:bg-muted bookly:animate-pulse"></div>
                </div>
            </div>
        </div>
        <!-- KPI row (mirrors DashboardKpi grid) -->
        <div class="bookly:grid bookly:grid-cols-1 bookly:md:grid-cols-3 bookly:gap-3.5 bookly:mb-4">
            <?php for ( $i = 0; $i < 3; $i++ ) : ?>
                <div class="bookly:card bookly:p-5">
                    <div class="bookly:h-3 bookly:w-20 bookly:rounded bookly:bg-muted bookly:animate-pulse"></div>
                    <div class="bookly:h-7 bookly:w-28 bookly:mt-3 bookly:mb-3 bookly:rounded bookly:bg-muted bookly:animate-pulse"></div>
                    <div class="bookly:h-3 bookly:w-32 bookly:rounded bookly:bg-muted bookly:animate-pulse"></div>
                </div>
            <?php endfor; ?>
        </div>
        <!-- Needs-attention row -->
        <div class="bookly:flex bookly:flex-wrap bookly:gap-3 bookly:mb-4">
            <?php for ( $i = 0; $i < 2; $i++ ) : ?>
                <div class="bookly:card bookly:flex bookly:items-center bookly:gap-3 bookly:flex-1 bookly:min-w-[240px] bookly:px-4 bookly:py-3.5">
                    <div class="bookly:size-9 bookly:rounded-lg bookly:bg-muted bookly:animate-pulse"></div>
                    <div class="bookly:flex bookly:flex-col bookly:gap-2">
                        <div class="bookly:h-4 bookly:w-44 bookly:rounded bookly:bg-muted bookly:animate-pulse"></div>
                        <div class="bookly:h-3 bookly:w-28 bookly:rounded bookly:bg-muted bookly:animate-pulse"></div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
        <!-- Trend chart -->
        <div class="bookly:card bookly:mb-4">
            <div class="bookly:card-body">
                <div class="bookly:h-[280px] bookly:w-full bookly:rounded-md bookly:bg-muted bookly:animate-pulse"></div>
            </div>
        </div>
        <!-- Analytics table -->
        <div class="bookly:card">
            <div class="bookly:card-body">
                <div class="bookly:flex bookly:items-center bookly:gap-2 bookly:mb-4">
                    <div class="bookly:h-9 bookly:w-64 bookly:rounded-md bookly:bg-muted bookly:animate-pulse"></div>
                    <div class="bookly:h-9 bookly:w-24 bookly:rounded-md bookly:bg-muted bookly:animate-pulse bookly:ms-auto"></div>
                </div>
                <?php for ( $i = 0; $i < 6; $i++ ) : ?>
                    <div class="bookly:h-10 bookly:w-full bookly:rounded bookly:bg-muted bookly:animate-pulse bookly:mb-2"></div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <?php // Real content — hidden until the first combined response renders every section. ?>
    <div id="bookly-dashboard-content" class="bookly:hidden">
        <div class="bookly:card bookly:mb-4">
            <div class="bookly:card-body">
                <div id="bookly-dashboard-filters"></div>
            </div>
        </div>

        <div id="bookly-dashboard-kpi"></div>

        <div class="bookly:card bookly:mb-4">
            <div class="bookly:card-body">
                <?php Dashboard\Appointments\Widget::renderChart() ?>
            </div>
        </div>

        <?php Proxy\Pro::renderAnalytics() ?>
    </div>
</div>
