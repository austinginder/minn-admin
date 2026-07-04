<?php
/**
 * Bundled adapter: Query Monitor.
 *
 * Minn's app document fires none of the footer hooks QM's HTML dispatcher
 * listens for, so QM never realizes the page is dispatchable. Everything else
 * about modern QM already works here: it prints its own assets inline (no
 * wp_head needed), dispatches on `shutdown` (which runs after Minn's exit),
 * and Minn renders at template_redirect so QM's did_action('wp') requirement
 * is met. The integration is two moves:
 *
 *  1. Arm the dispatcher's footer flag from Minn's template hook.
 *  2. Provide the `#wp-admin-bar-query-monitor` element QM's JS looks for —
 *     it portals its whole toggle into it (summary text, click-to-open,
 *     panel menu). Minn styles it as a floating chip; without the element QM
 *     boots but renders no launcher.
 *
 * Capability checks stay QM's own (view_query_monitor / auth cookie). The
 * panel covers THIS document request — Minn's boot — the SPA's subsequent
 * REST calls are separate requests, same as any admin screen.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_action( 'minn_admin_template_footer', function () {
	if ( ! class_exists( 'QM_Dispatchers' ) || ! class_exists( 'QM_Dispatcher_Html' ) ) {
		return;
	}
	$html = QM_Dispatchers::get( 'html' );
	if ( ! $html || ! is_callable( array( $html, 'action_footer' ) ) ) {
		return;
	}
	if ( ! QM_Dispatcher_Html::user_can_view() ) {
		return;
	}
	$html->action_footer(); // arms the shutdown output
	?>
<div id="wp-admin-bar-query-monitor"></div>
<style>
/* QM launcher chip — QM's JS portals its toggle (summary + click handler)
   into the div above; empty means QM decided not to dispatch. */
#wp-admin-bar-query-monitor { position: fixed; right: 12px; bottom: 12px; z-index: 50; }
#wp-admin-bar-query-monitor:empty { display: none; }
#wp-admin-bar-query-monitor > .ab-item {
	display: block; font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 600;
	color: var(--text2); background: var(--panel); border: 1px solid var(--border);
	padding: 5px 10px; border-radius: 7px; text-decoration: none; box-shadow: var(--shadow-sm);
	white-space: nowrap; cursor: pointer;
}
#wp-admin-bar-query-monitor > .ab-item:hover { color: var(--accent2); border-color: var(--accent); }
#wp-admin-bar-query-monitor.qm-error > .ab-item { color: var(--red); border-color: var(--red); }
/* The admin-bar hover submenu has no admin-bar CSS here — the panel has its own menu. */
#wp-admin-bar-query-monitor .ab-sub-wrapper { display: none; }
</style>
	<?php
} );
