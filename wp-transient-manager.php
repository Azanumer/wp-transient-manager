<?php
/**
 * Plugin Name: WP Transient Manager
 * Plugin URI: https://github.com/Azanumer/wp-transient-manager
 * Description: See, search and clean up WordPress transients from Tools → Transient Manager. Delete expired transients, oversized ones, or everything at once — with a dry-run count first.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-transient-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WTM_VERSION', '1.0.0' );

/**
 * Fetch transients from an options-style table.
 *
 * @param string $table  Table name (wp_options or wp_sitemeta).
 * @param string $prefix Transient prefix: '_transient_' or '_site_transient_'.
 * @param string $search Optional substring search on the transient name.
 * @param int    $offset Pagination offset.
 * @param int    $limit  Rows per page.
 * @return array{rows: array, total: int} Rows with name, size, expiry (0 = no expiry).
 */
function wtm_get_transients( $table, $prefix, $search = '', $offset = 0, $limit = 50 ) {
	global $wpdb;

	$name_col  = ( $table === $wpdb->sitemeta ) ? 'meta_key' : 'option_name';
	$value_col = ( $table === $wpdb->sitemeta ) ? 'meta_value' : 'option_value';

	$like = $wpdb->esc_like( $prefix ) . '%';
	$sql  = "SELECT {$name_col} AS tname, LENGTH({$value_col}) AS tsize
			 FROM {$table}
			 WHERE {$name_col} LIKE %s
			 AND {$name_col} NOT LIKE %s";
	$args = array( $like, $wpdb->esc_like( $prefix . 'timeout_' ) . '%' );

	if ( '' !== $search ) {
		$sql   .= " AND {$name_col} LIKE %s";
		$args[] = '%' . $wpdb->esc_like( $search ) . '%';
	}

	// Count matching transients first, then fetch the page of rows.
	$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$name_col} LIKE %s AND {$name_col} NOT LIKE %s";
	$count_args = array_slice( $args, 0, 2 );
	if ( '' !== $search ) {
		$count_sql .= " AND {$name_col} LIKE %s";
		$count_args[] = $args[2];
	}
	$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	$sql .= ' ORDER BY tsize DESC LIMIT %d, %d';
	$args[] = $offset;
	$args[] = $limit;
	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// Map timeout rows in one query so we can show expiry.
	$out = array();
	if ( $rows ) {
		$names = array();
		foreach ( $rows as $row ) {
			$names[] = $prefix . 'timeout_' . substr( $row->tname, strlen( $prefix ) );
		}
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$timeouts     = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT {$name_col} AS tname, {$value_col} AS texpiry FROM {$table} WHERE {$name_col} IN ({$placeholders})",
				$names
			),
			OBJECT_K
		);
		foreach ( $rows as $row ) {
			$short        = substr( $row->tname, strlen( $prefix ) );
			$timeout_key  = $prefix . 'timeout_' . $short;
			$expiry       = isset( $timeouts[ $timeout_key ] ) ? (int) $timeouts[ $timeout_key ]->expiry : 0;
			$out[]        = array(
				'name'   => $short,
				'size'   => (int) $row->tsize,
				'expiry' => $expiry,
			);
		}
	}

	return array( 'rows' => $out, 'total' => $total );
}

/**
 * Count expired transients (timeout in the past) in a table.
 */
function wtm_count_expired( $table, $prefix ) {
	global $wpdb;

	$name_col  = ( $table === $wpdb->sitemeta ) ? 'meta_key' : 'option_name';
	$value_col = ( $table === $wpdb->sitemeta ) ? 'meta_value' : 'option_value';

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE {$name_col} LIKE %s
			 AND CAST({$value_col} AS UNSIGNED) < %d
			 AND CAST({$value_col} AS UNSIGNED) > 0",
			$wpdb->esc_like( $prefix . 'timeout_' ) . '%',
			time()
		)
	);
}

/**
 * Delete expired transients from a table. Returns rows deleted.
 */
function wtm_delete_expired( $table, $prefix ) {
	global $wpdb;

	$name_col  = ( $table === $wpdb->sitemeta ) ? 'meta_key' : 'option_name';
	$value_col = ( $table === $wpdb->sitemeta ) ? 'meta_value' : 'option_value';

	// Collect expired timeout keys first, then delete each transient + its timeout row.
	$expired = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT {$name_col} FROM {$table}
			 WHERE {$name_col} LIKE %s
			 AND CAST({$value_col} AS UNSIGNED) < %d
			 AND CAST({$value_col} AS UNSIGNED) > 0",
			$wpdb->esc_like( $prefix . 'timeout_' ) . '%',
			time()
		)
	);

	$deleted = 0;
	foreach ( $expired as $timeout_key ) {
		$short = substr( $timeout_key, strlen( $prefix . 'timeout_' ) );
		$wpdb->delete( $table, array( $name_col => $timeout_key ) );
		$wpdb->delete( $table, array( $name_col => $prefix . $short ) );
		$deleted++;
	}
	return $deleted;
}

/**
 * Delete ALL transients (and their timeout rows) from a table. Returns rows deleted.
 */
function wtm_delete_all( $table, $prefix ) {
	global $wpdb;

	$name_col = ( $table === $wpdb->sitemeta ) ? 'meta_key' : 'option_name';

	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE {$name_col} LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);
	return false === $deleted ? 0 : (int) $deleted;
}

add_action( 'admin_menu', 'wtm_register_menu' );
function wtm_register_menu() {
	add_management_page(
		__( 'Transient Manager', 'wp-transient-manager' ),
		__( 'Transient Manager', 'wp-transient-manager' ),
		'manage_options',
		'wtm-transient-manager',
		'wtm_render_page'
	);
}

function wtm_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-transient-manager' ) );
	}

	global $wpdb;

	$scope = isset( $_GET['scope'] ) && 'site' === $_GET['scope'] ? 'site' : 'regular';
	if ( 'site' === $scope && ! is_multisite() ) {
		$scope = 'regular';
	}
	$table  = ( 'site' === $scope ) ? $wpdb->sitemeta : $wpdb->options;
	$prefix = ( 'site' === $scope ) ? '_site_transient_' : '_transient_';

	$notice = '';
	// --- Actions ---------------------------------------------------------
	if ( isset( $_POST['wtm_action'] ) && check_admin_referer( 'wtm_bulk_action' ) ) {
		$action = sanitize_key( $_POST['wtm_action'] );
		if ( 'delete_expired' === $action ) {
			$n      = wtm_delete_expired( $table, $prefix );
			$notice = sprintf(
				/* translators: %d: number of transients deleted. */
				_n( '%d expired transient deleted.', '%d expired transients deleted.', $n, 'wp-transient-manager' ),
				$n
			);
		} elseif ( 'delete_all' === $action && isset( $_POST['wtm_confirm_all'] ) && 'DELETE' === $_POST['wtm_confirm_all'] ) {
			$n      = wtm_delete_all( $table, $prefix );
			$notice = sprintf(
				/* translators: %d: number of rows deleted. */
				__( '%d transient rows deleted.', 'wp-transient-manager' ),
				$n
			);
		} elseif ( 'delete_all' === $action ) {
			$notice = __( 'Type DELETE in the confirmation box to delete all transients.', 'wp-transient-manager' );
		}
	}
	if ( isset( $_GET['wtm_delete'] ) && check_admin_referer( 'wtm_delete_single' ) ) {
		$name = sanitize_key( wp_unslash( $_GET['wtm_delete'] ) );
		if ( '' !== $name ) {
			if ( 'site' === $scope ) {
				delete_site_transient( $name );
			} else {
				delete_transient( $name );
			}
			$notice = sprintf(
				/* translators: %s: transient name. */
				__( 'Transient "%s" deleted.', 'wp-transient-manager' ),
				$name
			);
		}
	}

	$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	$perpage = 50;
	$data    = wtm_get_transients( $table, $prefix, $search, ( $paged - 1 ) * $perpage, $perpage );
	$expired = wtm_count_expired( $table, $prefix );
	$pages   = max( 1, (int) ceil( $data['total'] / $perpage ) );
	$base    = add_query_arg(
		array(
			'page'  => 'wtm-transient-manager',
			'scope' => $scope,
			's'     => $search,
		),
		admin_url( 'tools.php' )
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Transient Manager', 'wp-transient-manager' ); ?></h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Transients are WordPress\'s built-in temporary cache. Stuck or expired ones bloat wp_options and slow every page load. This page lists them biggest first so you can see what is eating space.', 'wp-transient-manager' ); ?></p>

		<?php if ( is_multisite() ) : ?>
			<h2 class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'regular' === $scope ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'scope', 'regular', $base ) ); ?>"><?php esc_html_e( 'Regular transients', 'wp-transient-manager' ); ?></a>
				<a class="nav-tab <?php echo 'site' === $scope ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'scope', 'site', $base ) ); ?>"><?php esc_html_e( 'Site transients', 'wp-transient-manager' ); ?></a>
			</h2>
		<?php endif; ?>

		<form method="get" style="margin:12px 0;">
			<input type="hidden" name="page" value="wtm-transient-manager">
			<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search transient name…', 'wp-transient-manager' ); ?>">
			<?php submit_button( __( 'Search', 'wp-transient-manager' ), 'secondary', '', false ); ?>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'wtm_bulk_action' ); ?>
			<p>
				<strong><?php printf( esc_html__( '%d total · %d expired', 'wp-transient-manager' ), $data['total'], $expired ); ?></strong>
			</p>
			<button type="submit" name="wtm_action" value="delete_expired" class="button" <?php disabled( 0, $expired ); ?>>
				<?php esc_html_e( 'Delete expired', 'wp-transient-manager' ); ?>
			</button>
			<label style="margin-left:16px;">
				<?php esc_html_e( 'Delete ALL — type DELETE to confirm:', 'wp-transient-manager' ); ?>
				<input type="text" name="wtm_confirm_all" autocomplete="off" size="8">
			</label>
			<button type="submit" name="wtm_action" value="delete_all" class="button button-link-delete">
				<?php esc_html_e( 'Delete all transients', 'wp-transient-manager' ); ?>
			</button>
		</form>

		<table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Transient', 'wp-transient-manager' ); ?></th>
					<th><?php esc_html_e( 'Size', 'wp-transient-manager' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'wp-transient-manager' ); ?></th>
					<th><?php esc_html_e( 'Action', 'wp-transient-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $data['rows'] ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No transients found.', 'wp-transient-manager' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $data['rows'] as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $row['name'] ); ?></code></td>
						<td><?php echo esc_html( size_format( $row['size'] ) ); ?></td>
						<td>
							<?php
							if ( 0 === $row['expiry'] ) {
								esc_html_e( 'Never', 'wp-transient-manager' );
							} elseif ( $row['expiry'] < time() ) {
								echo '<strong style="color:#b32d2e;">' . esc_html__( 'Expired', 'wp-transient-manager' ) . '</strong>';
							} else {
								echo esc_html( human_time_diff( time(), $row['expiry'] ) );
							}
							?>
						</td>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wtm_delete', rawurlencode( $row['name'] ), $base ), 'wtm_delete_single' ) ); ?>"
							   onclick="return confirm('<?php esc_attr_e( 'Delete this transient?', 'wp-transient-manager' ); ?>');">
								<?php esc_html_e( 'Delete', 'wp-transient-manager' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', $base ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => __( '&laquo;', 'wp-transient-manager' ),
						'next_text' => __( '&raquo;', 'wp-transient-manager' ),
					)
				);
				?>
			</div></div>
		<?php endif; ?>
	</div>
	<?php
}
