<?php
/**
 * Admin tools page – akce, které admin spouští ručně (migrace, status změny).
 *
 * Umístění: WooCommerce → NKZ Marketplace Tools.
 *
 * @package NKZMP
 */

namespace NKZMP\Admin;

use NKZMP\Audit\Recorder as AuditRecorder;
use NKZMP\Support\Capabilities;
use NKZMP\Vendor\MetaMigrator;
use NKZMP\Vendor\Status;
use NKZMP\Vendor\StatusService;

defined( 'ABSPATH' ) || exit;

final class ToolsPage {

	private const NONCE_ACTION = 'nkzmp_tools_action';

	private static ?ToolsPage $instance = null;

	public static function instance(): ToolsPage {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_nkzmp_migrate_vendors', [ $this, 'handle_migrate' ] );
		add_action( 'admin_post_nkzmp_vendor_status', [ $this, 'handle_status' ] );
		add_action( 'admin_post_nkzmp_run_reconcile', [ $this, 'handle_reconcile' ] );
		add_action( 'admin_post_nkzmp_run_backfill', [ $this, 'handle_backfill' ] );
		add_action( 'admin_post_nkzmp_cleanup_roles', [ $this, 'handle_cleanup_roles' ] );
		add_action( 'wp_ajax_nkzmp_convert_heic_batch', [ $this, 'ajax_convert_heic_batch' ] );
	}

	private const LEGACY_ROLES = [
		'vendor',
		'wcfm_vendor',
		'dc_vendor',
		'seller',
		'wcv_vendor',
		'store_owner',
		'rejected_vendor',
		'pending_vendor',
		'dokan_vendor',
		'wcmp_vendor',
	];

	public function register_menu(): void {
		add_submenu_page(
			NKZMP_ADMIN_MENU_SLUG,
			__( 'Tools', 'nkz-marketplace' ),
			__( 'Tools', 'nkz-marketplace' ),
			Capabilities::MANAGE_VENDORS,
			'nkz-marketplace-tools',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}

		$flash = isset( $_GET['nkzmp_flash'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_flash'] ) ) : '';
		$msg   = isset( $_GET['nkzmp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_msg'] ) ) : '';

		echo '<div class="wrap">';
		echo '<h1>NKZ Marketplace – Tools</h1>';

		if ( $flash === 'ok' ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $msg ?: __( 'Hotovo.', 'nkz-marketplace' ) ) . '</p></div>';
		} elseif ( $flash === 'err' ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ?: __( 'Chyba.', 'nkz-marketplace' ) ) . '</p></div>';
		}

		$this->render_migration_section();
		echo '<hr style="margin:24px 0;">';
		$this->render_status_section();
		echo '<hr style="margin:24px 0;">';
		$this->render_backfill_section();
		echo '<hr style="margin:24px 0;">';
		$this->render_reconcile_section();
		echo '<hr style="margin:24px 0;">';
		$this->render_cleanup_roles_section();
		echo '<hr style="margin:24px 0;">';
		$this->render_heic_section();
		echo '</div>';
	}

	/** Najde HEIC/HEIF přílohy v Médiích. */
	private static function find_heic_attachments( int $limit = 200 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, pm.meta_value AS relpath
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'
			   AND ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s )
			 ORDER BY p.ID DESC LIMIT %d",
			'%.heic', '%.heif', $limit
		) );
		return (array) $rows;
	}

	private function render_heic_section(): void {
		$supported = class_exists( \NKZMP\Dashboard\HeicUploads::class )
			&& \NKZMP\Dashboard\HeicUploads::server_supports_heic();
		$items = self::find_heic_attachments();

		echo '<h2>' . esc_html__( 'HEIC fotky (iPhone)', 'nkz-marketplace' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Fotky ve formátu HEIC většina prohlížečů nezobrazí (v katalogu je místo nich rozbitá ikona). Tenhle nástroj je převede na JPEG a přepíše odkaz u produktů.', 'nkz-marketplace' ) . '</p>';

		if ( empty( $items ) ) {
			echo '<p style="color:#46b450;">' . esc_html__( 'Žádné HEIC fotky v Médiích. 👍', 'nkz-marketplace' ) . '</p>';
			return;
		}

		printf(
			'<p><strong>%s</strong></p>',
			esc_html( sprintf(
				/* translators: %d: počet */
				__( 'Nalezeno HEIC fotek: %d', 'nkz-marketplace' ),
				count( $items )
			) )
		);

		echo '<ul style="margin:0 0 14px 18px;list-style:disc;">';
		foreach ( array_slice( $items, 0, 20 ) as $it ) {
			printf(
				'<li>#%d — %s <code style="font-size:11px;">%s</code></li>',
				(int) $it->ID,
				esc_html( (string) $it->post_title ),
				esc_html( (string) $it->relpath )
			);
		}
		echo '</ul>';
		if ( count( $items ) > 20 ) {
			printf( '<p class="description">%s</p>', esc_html( sprintf( __( '…a dalších %d.', 'nkz-marketplace' ), count( $items ) - 20 ) ) );
		}

		if ( ! $supported ) {
			echo '<div class="notice notice-error inline"><p><strong>'
				. esc_html__( 'Server neumí číst HEIC.', 'nkz-marketplace' ) . '</strong><br>'
				. esc_html__( 'Imagick na tomhle serveru nemá HEIC delegát, takže převod nelze provést automaticky. Požádej hosting o doplnění podpory HEIC do Imagicku – pak stačí kliknout na Převést. Do té doby musí prodejci fotky nahrát znovu jako JPEG.', 'nkz-marketplace' )
				. '</p></div>';
			return;
		}

		// Převod běží po malých dávkách přes AJAX. Imagick je pameťově náročný –
		// 200 fotek v jednom requestu vyčerpá paměť/timeout a shodí stránku.
		$nonce = wp_create_nonce( 'nkzmp_heic_batch' );
		?>
		<div id="nkzmp-heic-tool" data-total="<?php echo (int) count( $items ); ?>">
			<button type="button" class="button button-primary" id="nkzmp-heic-start">
				<?php esc_html_e( 'Převést HEIC → JPEG', 'nkz-marketplace' ); ?>
			</button>
			<div id="nkzmp-heic-progress" style="display:none;margin-top:14px;max-width:520px;">
				<div style="height:12px;background:#e5e5e5;border-radius:999px;overflow:hidden;">
					<div id="nkzmp-heic-bar" style="height:100%;width:0;background:#2271b1;transition:width .25s ease;"></div>
				</div>
				<p id="nkzmp-heic-status" style="margin:8px 0 0;font-size:13px;"></p>
			</div>
		</div>
		<script>
		(function(){
			var box = document.getElementById('nkzmp-heic-tool');
			if (!box) return;
			var btn    = document.getElementById('nkzmp-heic-start');
			var wrap   = document.getElementById('nkzmp-heic-progress');
			var bar    = document.getElementById('nkzmp-heic-bar');
			var status = document.getElementById('nkzmp-heic-status');
			var total  = parseInt(box.getAttribute('data-total'), 10) || 0;
			var done = 0, failed = 0;

			function render(){
				var pct = total ? Math.round((done + failed) / total * 100) : 100;
				bar.style.width = pct + '%';
				status.textContent = 'Převedeno ' + done + ' z ' + total +
					(failed ? ' (nepovedlo se: ' + failed + ')' : '') + ' — ' + pct + ' %';
			}

			function step(){
				var body = new FormData();
				body.append('action', 'nkzmp_convert_heic_batch');
				body.append('_ajax_nonce', <?php echo wp_json_encode( $nonce ); ?>);
				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST', body: body, credentials: 'same-origin'
				})
				.then(function(r){ return r.json(); })
				.then(function(res){
					if (!res || !res.success) {
						status.textContent = 'Chyba: ' + ((res && res.data) ? res.data : 'neznámá');
						btn.disabled = false;
						return;
					}
					done   += res.data.done;
					failed += res.data.failed;
					render();
					if (res.data.remaining > 0) {
						setTimeout(step, 300); // pauza, ať server dýchá
					} else {
						status.textContent = 'Hotovo. Převedeno: ' + done +
							(failed ? ', nepovedlo se: ' + failed : '') + '. Obnov stránku.';
						btn.disabled = false;
					}
				})
				.catch(function(e){
					status.textContent = 'Spojení selhalo: ' + e.message + ' — zkus to prosím znovu.';
					btn.disabled = false;
				});
			}

			btn.addEventListener('click', function(){
				if (!confirm(<?php echo wp_json_encode( __( 'Převést HEIC fotky na JPEG? Poběží to po dávkách, nechej okno otevřené. Původní soubory zůstanou na serveru.', 'nkz-marketplace' ) ); ?>)) return;
				btn.disabled = true;
				wrap.style.display = '';
				done = 0; failed = 0;
				render();
				step();
			});
		})();
		</script>
		<?php
	}

	/** Jedna dávka převodu (AJAX). Malá, ať se request stihne a nesežere paměť. */
	public function ajax_convert_heic_batch(): void {
		check_ajax_referer( 'nkzmp_heic_batch' );
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_send_json_error( __( 'Nedostatečná oprávnění.', 'nkz-marketplace' ), 403 );
		}
		if ( ! class_exists( \NKZMP\Dashboard\HeicUploads::class )
			|| ! \NKZMP\Dashboard\HeicUploads::server_supports_heic() ) {
			wp_send_json_error( __( 'Server neumí číst HEIC.', 'nkz-marketplace' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$batch = (int) apply_filters( 'nkzmp/v1/tools/heic_batch_size', 3 );
		$items = self::find_heic_attachments( $batch );
		$done  = 0;
		$fail  = 0;

		foreach ( $items as $it ) {
			if ( self::convert_attachment( (int) $it->ID ) ) {
				$done++;
			} else {
				$fail++;
			}
		}

		// Kolik ještě zbývá (po tomhle kole). Neúspěšné by se točily dokola,
		// proto je odečteme – frontend pak správně skončí.
		$remaining = max( 0, count( self::find_heic_attachments( 500 ) ) - $fail );

		if ( $done > 0 && class_exists( \NKZMP\Audit\Recorder::class ) ) {
			( new \NKZMP\Audit\Recorder() )->record(
				action:      'media.heic_converted',
				entity_type: 'system',
				entity_id:   0,
				summary:     sprintf( 'HEIC → JPEG dávka: %d převedeno, %d selhalo', $done, $fail ),
			);
		}

		wp_send_json_success( [
			'done'      => $done,
			'failed'    => $fail,
			'remaining' => $remaining,
		] );
	}

	/** Převede jednu přílohu HEIC → JPEG. */
	private static function convert_attachment( int $att_id ): bool {
		$path = get_attached_file( $att_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return false;
		}
		$new_path = preg_replace( '/\.(heic|heif)$/i', '.jpg', $path );
		if ( ! $new_path || $new_path === $path ) {
			return false;
		}
		try {
			$img = new \Imagick( $path );
			$img->setImageFormat( 'jpeg' );
			$img->setImageCompressionQuality( 88 );
			if ( method_exists( $img, 'autoOrient' ) ) {
				$img->autoOrient();
			}
			$img->stripImage();
			$ok = $img->writeImage( $new_path );
			$img->clear();
			$img->destroy();
			if ( ! $ok ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] HEIC převod #' . $att_id . ' selhal: ' . $e->getMessage() );
			return false;
		}

		// Přepneme přílohu na nový soubor a přegenerujeme náhledy. Původní
		// .heic soubor schválně necháváme (kdyby bylo potřeba se vrátit).
		update_attached_file( $att_id, $new_path );
		wp_update_post( [ 'ID' => $att_id, 'post_mime_type' => 'image/jpeg' ] );
		$meta = wp_generate_attachment_metadata( $att_id, $new_path );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( $att_id, $meta );
		}
		return true;
	}

	private function render_cleanup_roles_section(): void {
		echo '<h2>' . esc_html__( 'Smazat staré vendor role (Dokan / WC Vendors / WCFM …)', 'nkz-marketplace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Najde role, které zbyly po předchozích marketplace pluginech. Smaže jen ty s 0 uživateli — bezpečné.', 'nkz-marketplace' ) . '</p>';

		$found = [];
		foreach ( self::LEGACY_ROLES as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			$users = get_users( [ 'role' => $role_slug, 'number' => 1, 'fields' => 'ID' ] );
			$count = count( get_users( [ 'role' => $role_slug, 'fields' => 'ID' ] ) );
			$found[] = [ 'slug' => $role_slug, 'count' => $count ];
		}

		if ( empty( $found ) ) {
			echo '<p><em>' . esc_html__( 'Žádné staré role nenalezeny. Čisto.', 'nkz-marketplace' ) . '</em></p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:600px;margin-bottom:12px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Role', 'nkz-marketplace' ) . '</th>';
		echo '<th>' . esc_html__( 'Uživatelů', 'nkz-marketplace' ) . '</th>';
		echo '<th>' . esc_html__( 'Stav', 'nkz-marketplace' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $found as $r ) {
			$safe = $r['count'] === 0;
			echo '<tr>';
			echo '<td><code>' . esc_html( $r['slug'] ) . '</code></td>';
			echo '<td>' . (int) $r['count'] . '</td>';
			echo '<td style="color:' . ( $safe ? '#46b450' : '#dc3232' ) . ';">' . esc_html( $safe ? __( 'lze smazat', 'nkz-marketplace' ) : __( 'NE — má uživatele', 'nkz-marketplace' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nkzmp_cleanup_roles" />';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Smazat prázdné role', 'nkz-marketplace' ) . '</button>';
		echo '</form>';
	}

	public function handle_cleanup_roles(): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}
		$removed = [];
		$skipped = [];
		foreach ( self::LEGACY_ROLES as $role_slug ) {
			if ( ! get_role( $role_slug ) ) {
				continue;
			}
			$user_count = count( get_users( [ 'role' => $role_slug, 'fields' => 'ID' ] ) );
			if ( $user_count > 0 ) {
				$skipped[] = $role_slug;
				continue;
			}
			remove_role( $role_slug );
			$removed[] = $role_slug;
		}

		if ( class_exists( \NKZMP\Audit\Recorder::class ) ) {
			( new \NKZMP\Audit\Recorder() )->record(
				action:      'roles.cleanup',
				entity_type: 'system',
				entity_id:   0,
				summary:     sprintf( 'Removed %d legacy roles, %d skipped (non-empty)', count( $removed ), count( $skipped ) ),
				payload:     [ 'removed' => $removed, 'skipped' => $skipped ],
			);
		}

		$flash = empty( $skipped ) ? 'ok' : ( empty( $removed ) ? 'err' : 'ok' );
		$msg   = sprintf( __( 'Smazáno: %d. Přeskočeno (mají uživatele): %d.', 'nkz-marketplace' ), count( $removed ), count( $skipped ) );
		$this->redirect( $flash, $msg );
	}

	private function render_backfill_section(): void {
		echo '<h2>' . esc_html__( 'Backfill historických transferů', 'nkz-marketplace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Naimportuje legacy Stripe transfery z order meta _nkv_split_transfers do nového ledgeru. Idempotentní – opakované spuštění nevytvoří duplikáty.', 'nkz-marketplace' ) . '</p>';

		$preview = \NKZMP\Integration\BackfillService::run( true, 0, null );
		echo '<p><strong>' . esc_html__( 'Dry-run preview:', 'nkz-marketplace' ) . '</strong></p>';
		echo '<ul>';
		echo '<li>' . esc_html( sprintf( __( 'Orderů s legacy transfery: %d', 'nkz-marketplace' ), $preview['orders'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Transfer records celkem: %d', 'nkz-marketplace' ), $preview['records'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Completed (k importu): %d', 'nkz-marketplace' ), $preview['completed'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Skipped (non-completed): %d', 'nkz-marketplace' ), $preview['skipped'] ) ) . '</li>';
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nkzmp_run_backfill" />';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<button type="submit" class="button button-primary"' . ( $preview['completed'] > 0 ? '' : ' disabled' ) . '>'
			. esc_html__( 'Spustit backfill (write)', 'nkz-marketplace' ) . '</button>';
		echo '</form>';
	}

	public function handle_backfill(): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}
		$stats = \NKZMP\Integration\BackfillService::run( false, 0, null );

		( new AuditRecorder() )->record(
			action:      'ledger.backfill',
			entity_type: 'system',
			entity_id:   0,
			summary:     sprintf( 'Backfill: %d orders, %d transfers imported, %d errors', $stats['orders'], $stats['completed'], $stats['errors'] ),
			payload:     $stats,
		);

		$flash = $stats['errors'] > 0 ? 'err' : 'ok';
		$msg   = sprintf( __( 'Backfill: %d orderů, %d transferů importováno, %d chyb.', 'nkz-marketplace' ), $stats['orders'], $stats['completed'], $stats['errors'] );
		$this->redirect( $flash, $msg );
	}

	private function render_reconcile_section(): void {
		$baseline = (int) get_option( 'nkzmp_reconcile_baseline_ts', 0 );

		echo '<h2>' . esc_html__( 'Reconciliation', 'nkz-marketplace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Porovná ledger PAYOUT řádky se Stripe transfery ve zvoleném časovém okně. Drift se zaloguje do auditu.', 'nkz-marketplace' ) . '</p>';
		if ( $baseline > 0 ) {
			echo '<p style="font-size:12px;color:#777;">' . esc_html( sprintf( __( 'Baseline: ignorují se PSP transfery před %s (fresh install ochrana).', 'nkz-marketplace' ), gmdate( 'Y-m-d H:i', $baseline ) . ' UTC' ) ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nkzmp_run_reconcile" />';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label>' . esc_html__( 'Okno (since)', 'nkz-marketplace' ) . '</label></th>';
		echo '<td><select name="since"><option value="24h">24h</option><option value="7d" selected>7d</option><option value="30d">30d</option><option value="90d">90d</option></select></td></tr>';
		echo '</tbody></table>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Spustit reconcile', 'nkz-marketplace' ) . '</button>';
		echo '</form>';

		$last = get_transient( 'nkzmp_last_reconcile_result' );
		if ( $last && is_array( $last ) ) {
			echo '<h3 style="margin-top:18px;">' . esc_html__( 'Poslední běh', 'nkz-marketplace' ) . '</h3>';
			foreach ( $last as $name => $r ) {
				echo '<table class="widefat striped" style="max-width:700px;margin-bottom:8px;"><tbody>';
				echo '<tr><th>Adapter</th><td><code>' . esc_html( $name ) . '</code></td></tr>';
				if ( isset( $r['error'] ) ) {
					echo '<tr><th>Error</th><td style="color:#dc3232;">' . esc_html( (string) $r['error'] ) . '</td></tr>';
				} else {
					echo '<tr><th>Window</th><td>' . esc_html( gmdate( 'Y-m-d H:i', (int) $r['from_ts'] ) ) . ' → ' . esc_html( gmdate( 'Y-m-d H:i', (int) $r['to_ts'] ) ) . ' UTC</td></tr>';
					echo '<tr><th>Source</th><td>' . (int) $r['source_count'] . '</td></tr>';
					echo '<tr><th>Ledger</th><td>' . (int) $r['ledger_count'] . '</td></tr>';
					echo '<tr><th>Matched</th><td><strong style="color:' . ( $r['matched_count'] > 0 ? '#46b450' : '#777' ) . ';">' . (int) $r['matched_count'] . '</strong></td></tr>';
					echo '<tr><th>Drift</th><td><strong style="color:' . ( $r['drift_count'] > 0 ? '#dc3232' : '#46b450' ) . ';">' . (int) $r['drift_count'] . '</strong></td></tr>';
				}
				echo '</tbody></table>';
				if ( ! empty( $r['drift'] ) ) {
					echo '<details><summary>' . esc_html__( 'Drift detail', 'nkz-marketplace' ) . '</summary><pre style="background:#f6f7f7;padding:8px;overflow:auto;">' . esc_html( wp_json_encode( $r['drift'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
				}
			}
		}
	}

	public function handle_reconcile(): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}

		$since = isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '7d';
		$secs  = match ( $since ) {
			'24h' => DAY_IN_SECONDS,
			'30d' => 30 * DAY_IN_SECONDS,
			'90d' => 90 * DAY_IN_SECONDS,
			default => 7 * DAY_IN_SECONDS,
		};

		$to_ts   = time();
		$from_ts = $to_ts - $secs;
		$drivers = \NKZMP\Reconciliation\Service::drivers();
		if ( ! $drivers ) {
			$this->redirect( 'err', __( 'Žádné reconciliation drivery (chybí Stripe adapter ≥ 0.6.6?).', 'nkz-marketplace' ) );
		}

		$service = new \NKZMP\Reconciliation\Service();
		$out     = [];
		$totals  = [ 'matched' => 0, 'drift' => 0 ];
		foreach ( $drivers as $name => $driver ) {
			try {
				$report             = $service->run( $driver, $from_ts, $to_ts );
				$out[ $name ]       = $report->to_array();
				$totals['matched'] += $report->matched_count;
				$totals['drift']   += count( $report->drift );
			} catch ( \Throwable $e ) {
				$out[ $name ] = [ 'error' => $e->getMessage() ];
			}
		}
		set_transient( 'nkzmp_last_reconcile_result', $out, 6 * HOUR_IN_SECONDS );

		$msg = sprintf( __( 'Reconcile dokončen: %d matched, %d drift.', 'nkz-marketplace' ), $totals['matched'], $totals['drift'] );
		$this->redirect( $totals['drift'] > 0 ? 'err' : 'ok', $msg );
	}

	private function render_migration_section(): void {
		$summary = MetaMigrator::migrate_all( true, 0 ); // dry run preview

		echo '<h2>' . esc_html__( 'Meta migrace _nkv_* → _nkzmp_*', 'nkz-marketplace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Idempotentní. Legacy klíče zůstávají na místě pro rollback.', 'nkz-marketplace' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Dry-run preview:', 'nkz-marketplace' ) . '</strong></p>';
		echo '<ul>';
		echo '<li>' . esc_html( sprintf( __( 'Vendoři: %d', 'nkz-marketplace' ), $summary['processed'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Klíče k překopírování: %d', 'nkz-marketplace' ), $summary['copied'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Klíče už existují (skip): %d', 'nkz-marketplace' ), $summary['skipped_exists'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Legacy klíče prázdné (skip): %d', 'nkz-marketplace' ), $summary['skipped_empty'] ) ) . '</li>';
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nkzmp_migrate_vendors" />';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<button type="submit" class="button button-primary"' . ( $summary['copied'] > 0 ? '' : ' disabled' ) . '>'
			. esc_html__( 'Spustit migraci (write)', 'nkz-marketplace' ) . '</button>';
		echo '</form>';
	}

	private function render_status_section(): void {
		echo '<h2>' . esc_html__( 'Změna statusu vendora', 'nkz-marketplace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Provede validovaný přechod statusu. Změna se zaznamená do auditu.', 'nkz-marketplace' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nkzmp_vendor_status" />';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label>' . esc_html__( 'Vendor ID', 'nkz-marketplace' ) . '</label></th>';
		echo '<td><input type="number" name="vendor_id" required min="1" /></td></tr>';
		echo '<tr><th><label>' . esc_html__( 'Nový status', 'nkz-marketplace' ) . '</label></th>';
		echo '<td><select name="status">';
		foreach ( Status::cases() as $s ) {
			echo '<option value="' . esc_attr( $s->value ) . '">' . esc_html( $s->value ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label>' . esc_html__( 'Reason (volitelné)', 'nkz-marketplace' ) . '</label></th>';
		echo '<td><input type="text" name="reason" size="60" /></td></tr>';
		echo '</tbody></table>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Provést přechod', 'nkz-marketplace' ) . '</button>';
		echo '</form>';
	}

	public function handle_migrate(): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}
		$summary = MetaMigrator::migrate_all( false, 0 );

		( new AuditRecorder() )->record(
			action:      'vendor.meta_migrated',
			entity_type: 'system',
			entity_id:   0,
			summary:     sprintf( 'Meta migration: %d vendors, %d keys copied', $summary['processed'], $summary['copied'] ),
			payload:     $summary,
		);

		$msg = sprintf( __( 'Migrace: %d vendorů, %d klíčů zkopírováno.', 'nkz-marketplace' ), $summary['processed'], $summary['copied'] );
		$this->redirect( 'ok', $msg );
	}

	public function handle_status(): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}
		$vendor_id = isset( $_POST['vendor_id'] ) ? (int) $_POST['vendor_id'] : 0;
		$status    = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$reason    = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		$target = Status::tryFrom( $status );
		if ( $vendor_id <= 0 || ! $target ) {
			$this->redirect( 'err', __( 'Vendor ID nebo status je špatně.', 'nkz-marketplace' ) );
		}

		try {
			( new StatusService() )->transition( $vendor_id, $target, [ 'reason' => $reason ] );
			$this->redirect( 'ok', sprintf( __( 'Vendor #%d je teď %s.', 'nkz-marketplace' ), $vendor_id, $target->value ) );
		} catch ( \Throwable $e ) {
			$this->redirect( 'err', $e->getMessage() );
		}
	}

	private function redirect( string $flash, string $msg ): void {
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'nkz-marketplace-tools', 'nkzmp_flash' => $flash, 'nkzmp_msg' => rawurlencode( $msg ) ],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
