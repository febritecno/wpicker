<?php
/**
 * Shared admin header: product name, tagline and section tabs.
 *
 * @package WPicker\Admin
 * @var string               $active    Slug of the current screen.
 * @var array<string,string> $tabs      Slug => label.
 * @var int                  $in_review Pushes waiting for review.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<header class="wpicker-header">
	<div class="wpicker-brand">
		<img class="wpicker-brand-mark" src="<?php echo esc_url( WPICKER_URL . 'admin/img/logo-96.png' ); ?>" width="44" height="44" alt="">
		<div>
			<h1 class="wpicker-brand-name">WPicker</h1>
			<p class="wpicker-brand-tagline"><?php esc_html_e( 'Develop locally, deploy safely.', 'wpicker' ); ?></p>
		</div>
		<span class="wpicker-version">v<?php echo esc_html( WPICKER_VERSION ); ?></span>
	</div>
	<nav class="wpicker-tabs" aria-label="<?php esc_attr_e( 'WPicker sections', 'wpicker' ); ?>">
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<a class="wpicker-tab<?php echo $slug === $active ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
				<?php echo $slug === $active ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
				<?php if ( \WPicker\Admin\Dashboard::SLUG_VAULT === $slug && $in_review > 0 ) : ?>
					<span class="wpicker-tab-count"><?php echo esc_html( (string) $in_review ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>
</header>
<?php
// WordPress moves admin notices to just after the first heading in .wrap —
// which here is the product name, above the tabs. This marker tells it to
// put them below the header instead.
?>
<hr class="wp-header-end">
