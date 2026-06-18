<?php
/**
 * Admin view: Vulnerability Scan
 *
 * @package WPicker
 */

defined( 'ABSPATH' ) || exit;

// We run the scan server-side for simplicity if requested.
// A more robust approach uses AJAX, but for simplicity we do it here.
$action = $_GET['action'] ?? '';
$report = null;

if ( $action === 'scan' && current_user_can( 'manage_options' ) ) {
	check_admin_referer( 'wpicker_scan' );
	$scanner = new \WPicker\Security\Scanner();
	$report  = $scanner->scan();
}

if ( $action === 'download' && current_user_can( 'manage_options' ) ) {
	check_admin_referer( 'wpicker_download' );
	$scanner = new \WPicker\Security\Scanner();
	$report  = $scanner->scan();
	
	header( 'Content-Type: application/json' );
	header( 'Content-Disposition: attachment; filename="vulnerability-report-' . date('Y-m-d') . '.json"' );
	echo wp_json_encode( $report, JSON_PRETTY_PRINT );
	exit;
}
?>

<div class="wrap wpicker-wrap">
	<h1><?php esc_html_e( 'WPicker - Vulnerability Scanner', 'wpicker' ); ?></h1>
	<p><?php esc_html_e( 'Scan your installed plugins against the WPVulnerability.net database.', 'wpicker' ); ?></p>

	<div class="card" style="max-width: 800px; margin-top: 20px;">
		<h2><?php esc_html_e( 'Scan Actions', 'wpicker' ); ?></h2>
		<p>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wpicker-vuln&action=scan' ), 'wpicker_scan' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Run Vulnerability Scan', 'wpicker' ); ?>
			</a>
			
			<?php if ( $report !== null ) : ?>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wpicker-vuln&action=download' ), 'wpicker_download' ) ); ?>" class="button">
				<?php esc_html_e( 'Download Report (JSON)', 'wpicker' ); ?>
			</a>
			<?php endif; ?>
		</p>
	</div>

	<?php if ( $report !== null ) : ?>
		<div class="card" style="max-width: 800px; margin-top: 20px;">
			<h2><?php esc_html_e( 'Scan Results', 'wpicker' ); ?></h2>
			
			<?php if ( empty( $report ) ) : ?>
				<p style="color: green;"><strong><?php esc_html_e( 'Great news! No known vulnerabilities found in your installed plugins.', 'wpicker' ); ?></strong></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'wpicker' ); ?></th>
							<th><?php esc_html_e( 'Version', 'wpicker' ); ?></th>
							<th><?php esc_html_e( 'Vulnerabilities', 'wpicker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report as $plugin ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $plugin['name'] ); ?></strong><br><small><?php echo esc_html( $plugin['slug'] ); ?></small></td>
								<td><?php echo esc_html( $plugin['installed_version'] ); ?></td>
								<td>
									<ul style="margin: 0; padding-left: 15px;">
										<?php foreach ( $plugin['vulnerabilities'] as $v ) : ?>
											<li>
												<strong><?php echo esc_html( $v['name'] ); ?></strong>
												<br>
												<span style="color: <?php echo ( $v['severity'] === 'critical' || $v['severity'] === 'high' ) ? 'red' : 'orange'; ?>">
													<?php echo esc_html( strtoupper( $v['severity'] ) ); ?> 
													<?php if ( $v['score'] ) echo esc_html( '(' . $v['score'] . ')' ); ?>
												</span>
											</li>
										<?php endforeach; ?>
									</ul>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
