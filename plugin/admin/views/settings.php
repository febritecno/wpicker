<?php
/**
 * Settings admin view.
 *
 * @package WPicker\Admin
 * @var array<string,mixed>            $settings Current settings with defaults applied.
 * @var array<int,array<string,string>> $audit    Latest diagnostic accesses, newest first.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wpicker-admin">
	<?php $this->render_header( \WPicker\Admin\Dashboard::SLUG_SETTINGS ); ?>
	<?php settings_errors(); ?>

	<form method="post" action="options.php" class="wpicker-settings">
		<?php settings_fields( 'wpicker_settings_group' ); ?>

		<section class="wpicker-card">
			<h2><?php esc_html_e( 'When a device pushes', 'wpicker' ); ?></h2>
			<p class="wpicker-muted"><?php esc_html_e( 'Choose whether pushed files go live right away or wait for you.', 'wpicker' ); ?></p>

			<div class="wpicker-options">
				<label class="wpicker-option">
					<input type="radio" name="wpicker_settings[push_mode]" value="direct" <?php checked( 'direct', $settings['push_mode'] ); ?>>
					<span class="wpicker-option-body">
						<strong><?php esc_html_e( 'Direct', 'wpicker' ); ?></strong>
						<span><?php esc_html_e( 'Apply immediately. Fastest loop for a staging or development site.', 'wpicker' ); ?></span>
					</span>
				</label>
				<label class="wpicker-option">
					<input type="radio" name="wpicker_settings[push_mode]" value="review" <?php checked( 'review', $settings['push_mode'] ); ?>>
					<span class="wpicker-option-body">
						<strong><?php esc_html_e( 'Review first', 'wpicker' ); ?></strong>
						<span><?php esc_html_e( 'Hold every push. Nothing changes on the site until an administrator checks the diff and clicks Apply under Snapshots.', 'wpicker' ); ?></span>
					</span>
				</label>
			</div>
		</section>

		<section class="wpicker-card">
			<h2><?php esc_html_e( 'Safety checks', 'wpicker' ); ?></h2>

			<label class="wpicker-check">
				<input type="checkbox" name="wpicker_settings[lint_enabled]" value="1" <?php checked( 1, (int) $settings['lint_enabled'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Syntax check', 'wpicker' ); ?></strong>
					<span class="wpicker-muted"><?php esc_html_e( 'Reject a push if any PHP file has a syntax error. Nothing is written.', 'wpicker' ); ?></span>
				</span>
			</label>

			<label class="wpicker-check">
				<input type="checkbox" name="wpicker_settings[health_check]" value="1" <?php checked( 1, (int) $settings['health_check'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Health check with automatic restore', 'wpicker' ); ?></strong>
					<span class="wpicker-muted"><?php esc_html_e( 'After applying, load the site. If it now returns a server error, put the previous files back.', 'wpicker' ); ?></span>
				</span>
			</label>
		</section>

		<section class="wpicker-card">
			<h2><?php esc_html_e( 'Backups', 'wpicker' ); ?></h2>

			<label class="wpicker-check">
				<input type="checkbox" name="wpicker_settings[allow_backup]" value="1" <?php checked( 1, (int) $settings['allow_backup'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Allow full-site backups', 'wpicker' ); ?></strong>
					<span class="wpicker-muted"><?php esc_html_e( 'Lets administrators — here and from a paired device — create and download backups of the whole site: database (including user accounts), plugins, themes and uploads. Turn off when you are not using it.', 'wpicker' ); ?></span>
				</span>
			</label>

			<p class="wpicker-field">
				<label for="wpicker-backup-keep"><?php esc_html_e( 'Backups to keep', 'wpicker' ); ?></label>
				<input type="number" id="wpicker-backup-keep" name="wpicker_settings[backup_keep]" min="1" max="20" value="<?php echo esc_attr( (string) (int) $settings['backup_keep'] ); ?>">
				<span class="wpicker-muted"><?php esc_html_e( 'Each backup is a full copy of the site. The oldest is deleted when a new one would exceed this number.', 'wpicker' ); ?></span>
			</p>
		</section>

		<section class="wpicker-card">
			<h2><?php esc_html_e( 'Diagnostics', 'wpicker' ); ?></h2>
			<p class="wpicker-muted"><?php esc_html_e( 'For finding out what is wrong on this site — by you, or by an AI agent working through a paired device.', 'wpicker' ); ?></p>

			<label class="wpicker-check">
				<input type="checkbox" name="wpicker_settings[allow_inspect]" value="1" <?php checked( 1, (int) $settings['allow_inspect'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Diagnostic access', 'wpicker' ); ?></strong>
					<span class="wpicker-muted"><?php esc_html_e( 'Lets a paired administrator device read this site\'s error logs, the files under wp-content, and the database — read-only. Passwords, keys, tokens and sessions are left out. Everything else can be read, including customer data, and what an AI agent reads is sent to its provider. Leave off unless you are diagnosing.', 'wpicker' ); ?></span>
				</span>
			</label>

			<label class="wpicker-check">
				<input type="checkbox" name="wpicker_settings[debug_log]" value="1" <?php checked( 1, (int) $settings['debug_log'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'WordPress debug logging', 'wpicker' ); ?></strong>
					<span class="wpicker-muted">
						<?php esc_html_e( 'Record every PHP error, warning, notice and WordPress deprecation into a private log — what WP_DEBUG and WP_DEBUG_LOG do, without editing wp-config.php. Nothing is shown to visitors. Costs a little speed; switch off when done.', 'wpicker' ); ?>
						<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
							<em><?php esc_html_e( 'WP_DEBUG is already on in wp-config.php.', 'wpicker' ); ?></em>
						<?php endif; ?>
					</span>
				</span>
			</label>

			<?php if ( array() !== $audit ) : ?>
				<h3 class="wpicker-subhead"><?php esc_html_e( 'Recent diagnostic access', 'wpicker' ); ?></h3>
				<table class="wpicker-table wpicker-audit">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When (GMT)', 'wpicker' ); ?></th>
							<th><?php esc_html_e( 'Who', 'wpicker' ); ?></th>
							<th><?php esc_html_e( 'What', 'wpicker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $audit as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( str_replace( array( 'T', '+00:00' ), array( ' ', '' ), (string) $entry['time_gmt'] ) ); ?></td>
								<td><?php echo esc_html( (string) $entry['device'] ); ?></td>
								<td class="wpicker-wrap"><strong><?php echo esc_html( (string) $entry['action'] ); ?></strong> <code><?php echo esc_html( (string) $entry['detail'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="wpicker-card">
			<h2><?php esc_html_e( 'Limits', 'wpicker' ); ?></h2>

			<p class="wpicker-field">
				<label for="wpicker-vault-keep"><?php esc_html_e( 'Snapshots to keep', 'wpicker' ); ?></label>
				<input type="number" id="wpicker-vault-keep" name="wpicker_settings[vault_keep]" min="1" max="200" value="<?php echo esc_attr( (string) (int) $settings['vault_keep'] ); ?>">
				<span class="wpicker-muted"><?php esc_html_e( 'Older ones are deleted. Failed pushes and pushes waiting for review are always kept.', 'wpicker' ); ?></span>
			</p>

			<p class="wpicker-field">
				<label for="wpicker-pin-ttl"><?php esc_html_e( 'Pairing PIN lifetime (seconds)', 'wpicker' ); ?></label>
				<input type="number" id="wpicker-pin-ttl" name="wpicker_settings[pin_ttl]" min="30" max="900" value="<?php echo esc_attr( (string) (int) $settings['pin_ttl'] ); ?>">
			</p>
		</section>

		<?php submit_button( __( 'Save settings', 'wpicker' ) ); ?>
	</form>
</div>
