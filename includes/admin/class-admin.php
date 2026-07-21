<?php
/**
 * Admin UI: menu, status card, settings, audit table, "Check now".
 *
 * @package OrderMend
 */

namespace OrderMend\Admin;

use OrderMend\Audit\Audit_Log;
use OrderMend\Audit\Audit_List_Table;
use OrderMend\Scheduler\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce submenu page for OrderMend.
 */
class Admin {

	const MENU_SLUG        = 'ordermend';
	const CHECK_ACTION     = 'ordermend_check_now';
	const SETTINGS_ACTION  = 'ordermend_save_settings';
	const EXPORT_ACTION    = 'ordermend_export_csv';
	const CAPABILITY       = 'manage_woocommerce';

	/**
	 * Audit log.
	 *
	 * @var Audit_Log
	 */
	private $audit;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Constructor.
	 *
	 * @param Audit_Log $audit     Audit log.
	 * @param Scheduler $scheduler Scheduler.
	 */
	public function __construct( Audit_Log $audit, Scheduler $scheduler ) {
		$this->audit     = $audit;
		$this->scheduler = $scheduler;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'handle_check_now' ) );
		add_action( 'admin_post_' . self::SETTINGS_ACTION, array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export_csv' ) );
	}

	/**
	 * Register the WooCommerce submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'OrderMend', 'ordermend' ),
			__( 'OrderMend', 'ordermend' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the "Check now" admin-post request.
	 *
	 * @return void
	 */
	public function handle_check_now() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ordermend' ) );
		}
		check_admin_referer( self::CHECK_ACTION );

		$summary = $this->scheduler->dispatch( 'manual' );

		$redirect = add_query_arg(
			array(
				'page'            => self::MENU_SLUG,
				'ordermend_ran'   => 1,
				'recovered'       => (int) $summary['recovered'],
				'scanned'         => (int) $summary['scanned'],
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle settings save.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ordermend' ) );
		}
		check_admin_referer( self::SETTINGS_ACTION );

		$lookback = isset( $_POST['ordermend_lookback_days'] ) ? absint( wp_unslash( $_POST['ordermend_lookback_days'] ) ) : 14;
		$lookback = max( 1, min( 365, $lookback ) );
		update_option( 'ordermend_lookback_days', $lookback );

		$all_gateways = array( 'stripe', 'stripe_cc', 'ppcp-gateway', 'paypal' );
		$gateways     = isset( $_POST['ordermend_enabled_gateways'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ordermend_enabled_gateways'] ) ) : array();
		$gateways     = array_values( array_intersect( $gateways, $all_gateways ) );
		if ( empty( $gateways ) ) {
			$gateways = $all_gateways;
		}
		update_option( 'ordermend_enabled_gateways', $gateways );

		$email = isset( $_POST['ordermend_alert_email'] ) ? sanitize_email( wp_unslash( $_POST['ordermend_alert_email'] ) ) : '';
		update_option( 'ordermend_alert_email', $email );

		$redirect = add_query_arg(
			array(
				'page'            => self::MENU_SLUG,
				'ordermend_saved' => 1,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Stream the audit log as a CSV download.
	 *
	 * @return void
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ordermend' ) );
		}
		check_admin_referer( self::EXPORT_ACTION );

		$csv = $this->audit->export_csv();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ordermend-audit-' . gmdate( 'Ymd-His' ) . '.csv' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV payload.
		exit;
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$last_run  = (int) get_option( 'ordermend_last_run', 0 );
		$summary   = get_option( 'ordermend_last_run_summary', array() );
		$lifetime  = (float) get_option( 'ordermend_recovered_lifetime_total', 0 );
		$lookback  = (int) get_option( 'ordermend_lookback_days', 14 );
		$email     = (string) get_option( 'ordermend_alert_email', get_option( 'admin_email' ) );
		$enabled   = (array) get_option( 'ordermend_enabled_gateways', array( 'stripe', 'stripe_cc', 'ppcp-gateway', 'paypal' ) );

		$last_run_label = $last_run > 0
			// translators: %s human-readable time difference.
			? sprintf( __( '%s ago', 'ordermend' ), human_time_diff( $last_run, time() ) )
			: __( 'never', 'ordermend' );

		$gateways = array(
			'stripe'       => __( 'Stripe', 'ordermend' ),
			'stripe_cc'    => __( 'Stripe (cards)', 'ordermend' ),
			'ppcp-gateway' => __( 'PayPal (PPCP)', 'ordermend' ),
			'paypal'       => __( 'PayPal (legacy)', 'ordermend' ),
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OrderMend – Payment Recovery', 'ordermend' ); ?></h1>

			<?php if ( isset( $_GET['ordermend_ran'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						/* translators: 1: recovered count, 2: scanned count. */
						esc_html__( 'Scan complete. %1$d order(s) recovered out of %2$d checked.', 'ordermend' ),
						(int) ( $_GET['recovered'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						(int) ( $_GET['scanned'] ?? 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					);
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['ordermend_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'ordermend' ); ?></p></div>
			<?php endif; ?>

			<div class="ordermend-status-card" style="display:flex;gap:24px;margin:16px 0;padding:16px;background:#fff;border:1px solid #ccd0d4;">
				<div>
					<strong><?php esc_html_e( 'Last run', 'ordermend' ); ?></strong><br>
					<?php echo esc_html( $last_run_label ); ?>
				</div>
				<div>
					<strong><?php esc_html_e( 'Recovered (total)', 'ordermend' ); ?></strong><br>
					<?php echo esc_html( number_format_i18n( $lifetime, 2 ) ); ?>
				</div>
				<div>
					<strong><?php esc_html_e( 'Last scan', 'ordermend' ); ?></strong><br>
					<?php
					if ( ! empty( $summary ) ) {
						printf(
							/* translators: 1: scanned, 2: recovered, 3: drift, 4: failed. */
							esc_html__( '%1$d scanned · %2$d recovered · %3$d drift · %4$d failed', 'ordermend' ),
							(int) ( $summary['scanned'] ?? 0 ),
							(int) ( $summary['recovered'] ?? 0 ),
							(int) ( $summary['drift'] ?? 0 ),
							(int) ( $summary['failed'] ?? 0 )
						);
					} else {
						esc_html_e( 'No scan yet.', 'ordermend' );
					}
					?>
				</div>
				<div style="margin-left:auto;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::CHECK_ACTION ); ?>">
						<?php wp_nonce_field( self::CHECK_ACTION ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Check now', 'ordermend' ); ?></button>
					</form>
				</div>
			</div>

			<h2><?php esc_html_e( 'Settings', 'ordermend' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SETTINGS_ACTION ); ?>">
				<?php wp_nonce_field( self::SETTINGS_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ordermend_lookback_days"><?php esc_html_e( 'Look-back window (days)', 'ordermend' ); ?></label></th>
						<td><input name="ordermend_lookback_days" id="ordermend_lookback_days" type="number" min="1" max="365" value="<?php echo esc_attr( (string) $lookback ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Only orders created within this many days are scanned.', 'ordermend' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled gateways', 'ordermend' ); ?></th>
						<td>
							<?php foreach ( $gateways as $slug => $label ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="ordermend_enabled_gateways[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $enabled, true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ordermend_alert_email"><?php esc_html_e( 'Alert e-mail', 'ordermend' ); ?></label></th>
						<td><input name="ordermend_alert_email" id="ordermend_alert_email" type="email" value="<?php echo esc_attr( $email ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Where recovery alerts are sent. Leave empty to disable e-mail alerts.', 'ordermend' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'ordermend' ) ); ?>
			</form>

			<h2 style="display:flex;align-items:center;gap:12px;">
				<?php esc_html_e( 'Audit log', 'ordermend' ); ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ), self::EXPORT_ACTION ) ); ?>"><?php esc_html_e( 'Export CSV', 'ordermend' ); ?></a>
			</h2>
			<?php
			$table = new Audit_List_Table( $this->audit );
			$table->prepare_items();
			$table->display();
			?>
		</div>
		<?php
	}
}
