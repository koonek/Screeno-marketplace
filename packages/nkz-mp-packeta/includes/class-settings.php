<?php
/**
 * Settings – Packeta API klíč (pro widget) + výchozí cena fallback.
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'nkzmp_packeta_settings';

	private static ?Settings $instance = null;

	public static function instance(): Settings {
		return self::$instance ??= new self();
	}

	public const CRON_HOOK = 'nkzmp_packeta_check_senders';

	public function init(): void {
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'wp_ajax_nkzmp_packeta_validate_sender', [ $this, 'ajax_validate_sender' ] );

		// Denní hlídka odesílatelů – přejmenování v Zásilkovně se jinak pozná
		// až ve chvíli, kdy prodejce nemůže odeslat objednávku.
		add_action( self::CRON_HOOK, [ $this, 'check_senders' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		add_action( 'admin_notices', [ $this, 'sender_notice' ] );

		if ( class_exists( \NKZMP\Admin\SettingsHub::class ) && \NKZMP\Admin\SettingsHub::available() ) {
			add_filter( 'nkzmp/v1/admin/settings/tabs', [ $this, 'register_tab' ] );
		} else {
			add_action( 'admin_menu', [ $this, 'menu' ], 35 );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/** AJAX: ověření jednoho názvu odesílatele proti Zásilkovně. */
	public function ajax_validate_sender(): void {
		check_ajax_referer( 'nkzmp_packeta_sender' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Nedostatečná oprávnění.', 'nkz-mp-packeta' ), 403 );
		}
		if ( ! self::is_configured() ) {
			wp_send_json_error( __( 'Nejdřív vyplň a ulož API heslo.', 'nkz-mp-packeta' ) );
		}
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$res   = ( new ApiClient( self::api_password() ) )->validate_sender( $label );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( $res->get_error_message() );
		}
		wp_send_json_success( sprintf(
			/* translators: %s: název odesílatele */
			__( 'Odesílatel „%s" v Zásilkovně existuje.', 'nkz-mp-packeta' ),
			$label
		) );
	}

	/**
	 * Denní kontrola všech používaných odesílatelů (globální + per-vendor).
	 * Výsledek uložíme; nefunkční ukážeme jako upozornění v adminu.
	 */
	public function check_senders(): void {
		if ( ! self::is_configured() ) {
			return;
		}
		$labels = [];
		$global = self::sender_label();
		if ( $global !== '' ) {
			$labels[ $global ] = __( 'globální', 'nkz-mp-packeta' );
		}
		$vendors = get_posts( [
			'post_type'      => [ 'nkv_vendor', 'nkzmp_vendor' ],
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
		] );
		foreach ( $vendors as $vid ) {
			$l = (string) get_post_meta( $vid, NKZMP_PACKETA_VENDOR_SENDER_LABEL_META, true );
			if ( $l !== '' && ! isset( $labels[ $l ] ) ) {
				$labels[ $l ] = get_the_title( $vid );
			}
		}

		$api    = new ApiClient( self::api_password() );
		$broken = [];
		foreach ( $labels as $label => $who ) {
			$res = $api->validate_sender( (string) $label );
			if ( is_wp_error( $res ) ) {
				$broken[] = [ 'label' => (string) $label, 'who' => (string) $who ];
			}
		}
		update_option( 'nkzmp_packeta_broken_senders', $broken, false );
	}

	/** Upozornění v adminu, když nějaký odesílatel přestal platit. */
	public function sender_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$broken = get_option( 'nkzmp_packeta_broken_senders', [] );
		if ( ! is_array( $broken ) || empty( $broken ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'Zásilkovna: odesílatel neexistuje', 'nkz-mp-packeta' ) . '</strong><br>'
			. esc_html__( 'Těmto prodejcům se nepodaří vytvořit štítek. Nejspíš se odesílatel v Zásilkovně přejmenoval.', 'nkz-mp-packeta' )
			. '</p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( array_slice( $broken, 0, 10 ) as $b ) {
			printf( '<li><code>%s</code> — %s</li>', esc_html( $b['label'] ), esc_html( $b['who'] ) );
		}
		echo '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=nkz-mp-packeta' ) ) . '">'
			. esc_html__( 'Otevřít nastavení Zásilkovny', 'nkz-mp-packeta' ) . '</a></p></div>';
	}

	public function register_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'packeta',
			'label'    => __( 'Zásilkovna', 'nkz-mp-packeta' ),
			'render'   => [ $this, 'render_panel' ],
			'priority' => 30,
		];
		return $tabs;
	}

	public static function get(): array {
		$defaults = [
			'api_key'        => '',   // Packeta widget API key (veřejný, pro widget)
			'api_password'   => '',   // Packeta REST API heslo (tajné, pro createPacket/štítky)
			'sender_label'   => '',   // výchozí odesílatel (eshop label v Packeta účtu)
			'default_weight' => 1,    // kg, fallback když produkt nemá vyplněnou váhu
			'default_price'  => 0,    // fallback pokud nkz-mp-shipping není aktivní
		];
		$saved = get_option( self::OPTION, [] );
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	public static function api_key(): string {
		return (string) self::get()['api_key'];
	}

	public static function is_configured(): bool {
		return self::api_key() !== '';
	}

	/** REST API heslo pro zakládání zásilek / štítky. */
	public static function api_password(): string {
		return (string) self::get()['api_password'];
	}

	/** Je nakonfigurované zakládání zásilek (auto-štítky)? */
	public static function api_configured(): bool {
		return self::api_password() !== '';
	}

	public static function sender_label(): string {
		return (string) self::get()['sender_label'];
	}

	public static function default_weight(): float {
		$w = (float) self::get()['default_weight'];
		return $w > 0 ? $w : 1.0;
	}

	public function register(): void {
		register_setting( 'nkzmp_packeta', self::OPTION );
	}

	public function menu(): void {
		$parent = defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'woocommerce';
		add_submenu_page(
			$parent,
			__( 'Zásilkovna', 'nkz-mp-packeta' ),
			__( 'Zásilkovna', 'nkz-mp-packeta' ),
			'manage_woocommerce',
			'nkz-mp-packeta',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'NKZ Marketplace – Zásilkovna', 'nkz-mp-packeta' ) . '</h1>';
		$this->render_panel();
		echo '</div>';
	}

	public function render_panel(): void {
		$s = self::get();

		if ( ! self::is_configured() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Zatím není vyplněný API klíč. Bez něj se widget pro výběr výdejny nenačte.', 'nkz-mp-packeta' ) . '</p></div>';
		}

		echo '<p>' . wp_kses_post( __( 'Údaje najdeš v <strong>Klientská sekce Zásilkovny → Nastavení → API</strong>. <strong>API klíč</strong> (veřejný) je pro widget výběru výdejny, <strong>API heslo</strong> (tajné) je pro zakládání zásilek a tisk štítků.', 'nkz-mp-packeta' ) ) . '</p>';
		echo '<p>' . esc_html__( 'Cena dopravy se bere z per-vendor paušálu (NKZ Marketplace → Doprava). Bez API hesla jedou štítky ručně; s heslem si je prodejci generují přímo v dashboardu.', 'nkz-mp-packeta' ) . '</p>';

		echo '<form method="post" action="options.php">';
		settings_fields( 'nkzmp_packeta' );
		echo '<table class="form-table"><tr>';
		echo '<th><label for="api_key">' . esc_html__( 'Packeta API klíč (widget)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="api_key" type="text" name="' . esc_attr( self::OPTION ) . '[api_key]" value="' . esc_attr( (string) $s['api_key'] ) . '" style="width:420px" />';
		echo '<p class="description">' . esc_html__( 'Veřejný klíč pro widget výběru výdejny v checkoutu.', 'nkz-mp-packeta' ) . '</p></td>';
		echo '</tr><tr>';
		echo '<th><label for="api_password">' . esc_html__( 'Packeta API heslo (štítky)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="api_password" type="text" name="' . esc_attr( self::OPTION ) . '[api_password]" value="' . esc_attr( (string) $s['api_password'] ) . '" style="width:420px" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'Tajné API heslo pro zakládání zásilek a tisk štítků. Bez něj se auto-štítky nevytvoří.', 'nkz-mp-packeta' ) . '</p></td>';
		echo '</tr><tr>';
		echo '<th><label for="sender_label">' . esc_html__( 'Výchozí odesílatel (eshop label)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="sender_label" type="text" name="' . esc_attr( self::OPTION ) . '[sender_label]" value="' . esc_attr( (string) $s['sender_label'] ) . '" style="width:420px" />';
		echo ' <button type="button" class="button" id="nkzmp-packeta-verify">' . esc_html__( 'Ověřit', 'nkz-mp-packeta' ) . '</button>';
		echo ' <span id="nkzmp-packeta-verify-out" style="margin-left:8px;font-weight:500;"></span>';
		echo '<p class="description">' . wp_kses_post( __( 'Pozor: vyplň <strong>„Označení"</strong>, ne „Název". V klientské zóně Zásilkovny (Nastavení → Odesílatelé) je to samostatný sloupec — např. odesílatel s názvem „Art of život Market" může mít označení <code>Marketplace</code>. API pracuje s označením (SENDER_INDICATION), takže s názvem to skončí chybou <code>SenderNotExists</code>.<br>Použije se, když prodejce nemá vlastní. Tlačítkem „Ověřit" si hned zkontroluješ, že ho Zásilkovna zná — nemusíš čekat na první objednávku.', 'nkz-mp-packeta' ) ) . '</p></td>';
		echo '</tr><tr>';
		echo '<th><label for="default_weight">' . esc_html__( 'Výchozí váha balíku (kg)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="default_weight" type="number" min="0.1" step="0.1" name="' . esc_attr( self::OPTION ) . '[default_weight]" value="' . esc_attr( (string) $s['default_weight'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Použije se, když produkt nemá vyplněnou váhu. Výdejní místa berou zhruba do 5 kg.', 'nkz-mp-packeta' ) . '</p></td>';
		echo '</tr><tr>';
		echo '<th><label for="default_price">' . esc_html__( 'Fallback cena (Kč)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="default_price" type="number" min="0" name="' . esc_attr( self::OPTION ) . '[default_price]" value="' . esc_attr( (string) $s['default_price'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Použije se jen pokud není aktivní modul Doprava (per-vendor paušál).', 'nkz-mp-packeta' ) . '</p></td>';
		echo '</tr></table>';
		submit_button();
		echo '</form>';

		// Ověření odesílatele proti Zásilkovně (bez ukládání).
		$nonce = wp_create_nonce( 'nkzmp_packeta_sender' );
		?>
		<script>
		(function(){
			var btn = document.getElementById('nkzmp-packeta-verify');
			var out = document.getElementById('nkzmp-packeta-verify-out');
			var fld = document.getElementById('sender_label');
			if (!btn || !out || !fld) return;
			btn.addEventListener('click', function(){
				out.textContent = '…';
				out.style.color = '';
				var body = new FormData();
				body.append('action', 'nkzmp_packeta_validate_sender');
				body.append('_ajax_nonce', <?php echo wp_json_encode( $nonce ); ?>);
				body.append('label', fld.value);
				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST', body: body, credentials: 'same-origin'
				})
				.then(function(r){ return r.json(); })
				.then(function(res){
					var ok = res && res.success;
					out.style.color = ok ? '#46b450' : '#b00020';
					out.textContent = (ok ? '✓ ' : '✗ ') + ((res && res.data) ? res.data : '');
				})
				.catch(function(e){
					out.style.color = '#b00020';
					out.textContent = '✗ ' + e.message;
				});
			});
		})();
		</script>
		<?php

		$this->render_sender_overview();
	}

	/**
	 * Přehled odesílatelů u prodejců.
	 *
	 * Per-vendor odesílatel má přednost před globálním, takže po přejmenování
	 * odesílatele v Zásilkovně padají štítky jen některým prodejcům – a hledat
	 * to po profilech je otrava. Tady je to na jednom místě.
	 */
	private function render_sender_overview(): void {
		$vendors = get_posts( [
			'post_type'      => [ 'nkv_vendor', 'nkzmp_vendor' ],
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		if ( empty( $vendors ) ) {
			return;
		}

		$global = self::sender_label();
		$custom = [];
		foreach ( $vendors as $v ) {
			$label = (string) get_post_meta( $v->ID, NKZMP_PACKETA_VENDOR_SENDER_LABEL_META, true );
			if ( $label !== '' ) {
				$custom[] = [ 'post' => $v, 'label' => $label ];
			}
		}

		echo '<hr style="margin:28px 0;">';
		echo '<h2>' . esc_html__( 'Odesílatelé u prodejců', 'nkz-mp-packeta' ) . '</h2>';
		echo '<p class="description" style="max-width:720px;">'
			. esc_html__( 'Název odesílatele musí přesně odpovídat tomu v účtu Zásilkovny. Když se tam odesílatel přejmenuje, štítky začnou padat s chybou „Sender is not given". Prodejci s vlastním odesílatelem ignorují globální nastavení – ty je potřeba opravit zvlášť.', 'nkz-mp-packeta' )
			. '</p>';

		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html__( 'Globální odesílatel:', 'nkz-mp-packeta' ),
			$global !== ''
				? '<code>' . esc_html( $global ) . '</code>'
				: '<span style="color:#b00020;">' . esc_html__( 'nevyplněný — prodejci bez vlastního odesílatele štítek nevytvoří', 'nkz-mp-packeta' ) . '</span>'
		);

		if ( empty( $custom ) ) {
			echo '<p style="color:#46b450;">' . esc_html__( 'Žádný prodejce nemá vlastní odesílatele — všichni jedou na globálním. Stačí opravit pole výše. 👍', 'nkz-mp-packeta' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:820px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Prodejce', 'nkz-mp-packeta' ) . '</th>';
		echo '<th>' . esc_html__( 'Vlastní odesílatel', 'nkz-mp-packeta' ) . '</th>';
		echo '<th>' . esc_html__( 'Akce', 'nkz-mp-packeta' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $custom as $row ) {
			$same = ( $global !== '' && $row['label'] === $global );
			printf(
				'<tr><td>%s</td><td><code>%s</code>%s</td><td><a href="%s">%s</a></td></tr>',
				esc_html( get_the_title( $row['post'] ) ),
				esc_html( $row['label'] ),
				$same ? ' <span style="color:#666;font-size:12px;">' . esc_html__( '(stejný jako globální)', 'nkz-mp-packeta' ) . '</span>' : '',
				esc_url( (string) get_edit_post_link( $row['post']->ID ) ),
				esc_html__( 'Upravit profil', 'nkz-mp-packeta' )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Tip: když prodejce nemá důvod mít vlastní, vymaž mu pole — pak automaticky použije globální a stačí ho měnit na jednom místě.', 'nkz-mp-packeta' ) . '</p>';
	}
}
