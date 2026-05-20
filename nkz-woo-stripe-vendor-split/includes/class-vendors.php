<?php
/**
 * Vendor CPT + admin meta box.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Vendors {

	public const POST_TYPE = 'nkv_vendor';

	private static ?Vendors $instance = null;
	public static function instance(): Vendors { return self::$instance ??= new self(); }

	public function init(): void {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save' ], 10, 2 );
	}

	public function register_cpt(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'        => __( 'Prodejci (Stripe)', 'nkz-woo-stripe-vendor-split' ),
				'labels'       => [
					'name'          => __( 'Prodejci', 'nkz-woo-stripe-vendor-split' ),
					'singular_name' => __( 'Prodejce', 'nkz-woo-stripe-vendor-split' ),
					'add_new_item'  => __( 'Přidat prodejce', 'nkz-woo-stripe-vendor-split' ),
					'edit_item'     => __( 'Upravit prodejce', 'nkz-woo-stripe-vendor-split' ),
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-businessperson',
				'supports'     => [ 'title' ],
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			]
		);
	}

	public function add_meta_box(): void {
		add_meta_box(
			'nkv_vendor_data',
			__( 'Údaje prodejce', 'nkz-woo-stripe-vendor-split' ),
			[ $this, 'render_meta_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'nkv_vendor_save_' . $post->ID, 'nkv_vendor_nonce' );
		$this->render_onboarding_panel( $post->ID );
		$fields = [
			'_nkv_vendor_status'          => [ 'label' => __( 'Stav prodejce', 'nkz-woo-stripe-vendor-split' ), 'type' => 'select', 'options' => [ 'active' => __( 'aktivní', 'nkz-woo-stripe-vendor-split' ), 'inactive' => __( 'neaktivní', 'nkz-woo-stripe-vendor-split' ) ] ],
			'_nkv_default_fee_percent'    => [ 'label' => __( 'Provize platformy (%)', 'nkz-woo-stripe-vendor-split' ), 'type' => 'number', 'step' => '0.01' ],
			'_nkv_default_fee_fixed'      => [ 'label' => __( 'Fixní poplatek (v haléřích, volitelné)', 'nkz-woo-stripe-vendor-split' ), 'type' => 'number', 'step' => '1' ],
			'_nkv_vendor_email'           => [ 'label' => __( 'Email prodejce', 'nkz-woo-stripe-vendor-split' ), 'type' => 'email' ],
			'_nkv_vendor_ico'             => [ 'label' => __( 'IČO / DIČ', 'nkz-woo-stripe-vendor-split' ), 'type' => 'text' ],
			'_nkv_vendor_currency'        => [ 'label' => __( 'Měna (ISO, volitelné)', 'nkz-woo-stripe-vendor-split' ), 'type' => 'text', 'placeholder' => 'CZK' ],
			'_nkv_internal_note'          => [ 'label' => __( 'Interní poznámka', 'nkz-woo-stripe-vendor-split' ), 'type' => 'textarea' ],
		];
		echo '<table class="form-table">';
		foreach ( $fields as $key => $cfg ) {
			$value = get_post_meta( $post->ID, $key, true );
			echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $cfg['label'] ) . '</label></th><td>';
			switch ( $cfg['type'] ) {
				case 'textarea':
					printf( '<textarea name="%s" id="%s" rows="3" cols="50">%s</textarea>', esc_attr( $key ), esc_attr( $key ), esc_textarea( (string) $value ) );
					break;
				case 'select':
					echo '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '">';
					foreach ( $cfg['options'] as $ov => $ol ) {
						printf( '<option value="%s" %s>%s</option>', esc_attr( $ov ), selected( $value, $ov, false ), esc_html( $ol ) );
					}
					echo '</select>';
					break;
				default:
					printf(
						'<input type="%s" name="%s" id="%s" value="%s" placeholder="%s" %s class="regular-text" />',
						esc_attr( $cfg['type'] ),
						esc_attr( $key ),
						esc_attr( $key ),
						esc_attr( (string) $value ),
						esc_attr( $cfg['placeholder'] ?? '' ),
						isset( $cfg['step'] ) ? 'step="' . esc_attr( $cfg['step'] ) . '"' : ''
					);
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	private function render_onboarding_panel( int $vendor_id ): void {
		$account_id = (string) get_post_meta( $vendor_id, '_nkv_stripe_account_id', true );
		$status     = (string) ( get_post_meta( $vendor_id, '_nkv_stripe_account_status', true ) ?: 'unknown' );
		$due_json   = (string) get_post_meta( $vendor_id, '_nkv_stripe_requirements_due', true );
		$due        = $due_json ? (array) json_decode( $due_json, true ) : [];
		$email      = (string) get_post_meta( $vendor_id, '_nkv_vendor_email', true );

		$flash = isset( $_GET['nkv_onboarding'] ) ? sanitize_text_field( wp_unslash( $_GET['nkv_onboarding'] ) ) : '';
		$msg   = isset( $_GET['nkv_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['nkv_msg'] ) ) : '';

		echo '<div class="nkv-onboarding" style="padding:14px;border:1px solid #ccd0d4;background:#fff;margin-bottom:12px;border-radius:4px;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Stripe Connect — onboarding prodejce', 'nkz-woo-stripe-vendor-split' ) . '</h3>';

		switch ( $flash ) {
			case 'synced':
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Stav byl aktualizován ze Stripe.', 'nkz-woo-stripe-vendor-split' ) . '</p></div>';
				break;
			case 'reset':
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Stripe účet odpojen od prodejce. Můžeš ho onboardovat znovu.', 'nkz-woo-stripe-vendor-split' ) . '</p></div>';
				break;
			case 'email_sent':
				echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Email s onboarding odkazem byl odeslán prodejci.', 'nkz-woo-stripe-vendor-split' ) . '</p></div>';
				break;
			case 'email_failed':
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Email se nepodařilo odeslat. Zkontroluj konfiguraci WP e-mailu.', 'nkz-woo-stripe-vendor-split' ) . '</p></div>';
				break;
			case 'error':
				if ( '' !== $msg ) {
					echo '<div class="notice notice-error inline"><p>' . esc_html( $msg ) . '</p></div>';
				}
				break;
		}

		// Status badge (only if account exists).
		if ( '' !== $account_id ) {
			$labels = [
				'enabled'    => [ __( 'Aktivní', 'nkz-woo-stripe-vendor-split' ),    '#46b450' ],
				'pending'    => [ __( 'Probíhá ověření', 'nkz-woo-stripe-vendor-split' ), '#ffb900' ],
				'restricted' => [ __( 'Omezený', 'nkz-woo-stripe-vendor-split' ),    '#dc3232' ],
				'unknown'    => [ __( 'Neznámý', 'nkz-woo-stripe-vendor-split' ),    '#888'    ],
			];
			$badge = $labels[ $status ] ?? $labels['unknown'];
			printf(
				'<p style="margin:0 0 10px;"><strong>%s:</strong> <code>%s</code><br><strong>%s:</strong> <span style="display:inline-block;padding:2px 10px;border-radius:3px;color:#fff;background:%s;font-weight:600;">%s</span></p>',
				esc_html__( 'Stripe účet', 'nkz-woo-stripe-vendor-split' ),
				esc_html( $account_id ),
				esc_html__( 'Stav', 'nkz-woo-stripe-vendor-split' ),
				esc_attr( $badge[1] ),
				esc_html( $badge[0] )
			);
			if ( ! empty( $due ) ) {
				echo '<p><strong>' . esc_html__( 'Stripe ještě vyžaduje', 'nkz-woo-stripe-vendor-split' ) . ':</strong><br><code style="font-size:11px;">' . esc_html( implode( ', ', array_map( 'strval', $due ) ) ) . '</code></p>';
			}
		} else {
			echo '<p style="color:#50575e;">' . esc_html__( 'Prodejce ještě není připojený ke Stripe. Pošli mu níže uvedený odkaz — všechny údaje vyplní sám přímo u Stripe.', 'nkz-woo-stripe-vendor-split' ) . '</p>';
		}

		// Onboarding section — only when account is missing or not yet fully enabled.
		$show_onboarding = ( '' === $account_id ) || ( 'enabled' !== $status );

		if ( $show_onboarding ) {
			$onboarding_link = \NKVSVS\Onboarding_Controller::vendor_start_url( $vendor_id );
			$input_id = 'nkv-onboarding-link-' . $vendor_id;
			echo '<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:12px;margin:12px 0;">';
			echo '<p style="margin:0 0 8px;font-weight:600;">' . esc_html__( 'Onboarding odkaz pro prodejce', 'nkz-woo-stripe-vendor-split' ) . '</p>';
			echo '<div style="display:flex;gap:6px;align-items:stretch;">';
			printf(
				'<input type="text" id="%s" readonly value="%s" onclick="this.select();" style="flex:1;font-family:monospace;font-size:12px;padding:6px;" />',
				esc_attr( $input_id ),
				esc_attr( $onboarding_link )
			);
			printf(
				'<button type="button" class="button" data-nkv-copy="%s" style="white-space:nowrap;">%s</button>',
				esc_attr( $input_id ),
				esc_html__( 'Kopírovat', 'nkz-woo-stripe-vendor-split' )
			);
			echo '</div>';
			echo '<p style="margin:8px 0 0;font-size:12px;color:#50575e;">' . esc_html__( 'Odkaz je trvalý — pokud prodejce onboarding přeruší, může se přes něj kdykoliv vrátit a pokračovat.', 'nkz-woo-stripe-vendor-split' ) . '</p>';
			echo '</div>';

			static $script_printed = false;
			if ( ! $script_printed ) {
				$script_printed = true;
				?>
				<script>
				(function(){
					document.addEventListener('click', function(e){
						var btn = e.target.closest('[data-nkv-copy]');
						if (!btn) return;
						e.preventDefault();
						var input = document.getElementById(btn.getAttribute('data-nkv-copy'));
						if (!input) return;
						var done = function(){
							var orig = btn.textContent;
							btn.textContent = <?php echo wp_json_encode( __( 'Zkopírováno ✓', 'nkz-woo-stripe-vendor-split' ) ); ?>;
							btn.disabled = true;
							setTimeout(function(){ btn.textContent = orig; btn.disabled = false; }, 1500);
						};
						if (navigator.clipboard && window.isSecureContext) {
							navigator.clipboard.writeText(input.value).then(done, function(){
								input.select(); document.execCommand('copy'); done();
							});
						} else {
							input.select(); document.execCommand('copy'); done();
						}
					});
				})();
				</script>
				<?php
			}

			// Email + mailto action buttons (only while onboarding is relevant).
			$mailto_subject = rawurlencode( sprintf( __( '[%s] Dokonči svou registraci přes Stripe', 'nkz-woo-stripe-vendor-split' ), get_bloginfo( 'name' ) ) );
			$mailto_body    = rawurlencode( sprintf(
				__( "Ahoj,\n\nabys mohl/a na platformě %1\$s přijímat platby, dokonči prosím registraci u našeho platebního partnera Stripe na tomto odkazu:\n\n%2\$s\n\nOdkaz je trvalý — pokud onboarding přerušíš, můžeš se přes něj kdykoliv vrátit.\n\nDíky", 'nkz-woo-stripe-vendor-split' ),
				get_bloginfo( 'name' ),
				$onboarding_link
			) );
			$mailto = 'mailto:' . rawurlencode( $email ) . '?subject=' . $mailto_subject . '&body=' . $mailto_body;

			if ( is_email( $email ) ) {
				printf(
					'<a href="%s" class="button button-primary">%s</a> ',
					esc_url( Onboarding_Controller::email_url( $vendor_id ) ),
					esc_html__( 'Odeslat odkaz emailem', 'nkz-woo-stripe-vendor-split' )
				);
				printf(
					'<a href="%s" class="button">%s</a> ',
					esc_url( $mailto ),
					esc_html__( 'Otevřít v mém emailu', 'nkz-woo-stripe-vendor-split' )
				);
			} else {
				echo '<p style="color:#dc3232;">' . esc_html__( 'Vyplň prodejci email níže a ulož, pak budeš moct odeslat onboarding link přímo z WP.', 'nkz-woo-stripe-vendor-split' ) . '</p>';
			}

			printf(
				'<a href="%s" class="button" target="_blank" rel="noopener">%s</a> ',
				esc_url( $onboarding_link ),
				esc_html__( 'Otevřít onboarding (test)', 'nkz-woo-stripe-vendor-split' )
			);
		}

		// Always-visible actions for existing accounts.
		if ( '' !== $account_id ) {
			printf(
				'<a href="%s" class="button">%s</a> ',
				esc_url( Onboarding_Controller::sync_url( $vendor_id ) ),
				esc_html__( 'Obnovit stav ze Stripe', 'nkz-woo-stripe-vendor-split' )
			);
			printf(
				'<a href="%s" class="button" target="_blank" rel="noopener">%s</a> ',
				esc_url( Onboarding_Controller::dashboard_url( $vendor_id ) ),
				esc_html__( 'Stripe Dashboard prodejce', 'nkz-woo-stripe-vendor-split' )
			);
			$confirm = esc_attr__( 'Opravdu odpojit tento Stripe účet od prodejce? Stripe účet samotný se nesmaže — bude pouze odpojen od tohoto prodejce ve WP a budeš ho moct znovu onboardovat.', 'nkz-woo-stripe-vendor-split' );
			printf(
				'<a href="%s" class="button button-link-delete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( Onboarding_Controller::reset_url( $vendor_id ) ),
				esc_attr( $confirm ),
				esc_html__( 'Odpojit Stripe účet', 'nkz-woo-stripe-vendor-split' )
			);
		}

		echo '</div>';
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['nkv_vendor_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nkv_vendor_nonce'] ) ), 'nkv_vendor_save_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$map = [
			'_nkv_vendor_status'         => 'enum:active,inactive',
			'_nkv_default_fee_percent'   => 'float',
			'_nkv_default_fee_fixed'     => 'int',
			'_nkv_vendor_email'          => 'email',
			'_nkv_vendor_ico'            => 'text',
			'_nkv_vendor_currency'       => 'currency',
			'_nkv_internal_note'         => 'textarea',
		];

		foreach ( $map as $key => $type ) {
			$raw = $_POST[ $key ] ?? '';
			$val = self::sanitize( $type, $raw );
			update_post_meta( $post_id, $key, $val );
		}
	}

	private static function sanitize( string $type, $raw ) {
		if ( str_starts_with( $type, 'enum:' ) ) {
			$allowed = explode( ',', substr( $type, 5 ) );
			$raw     = sanitize_text_field( (string) wp_unslash( $raw ) );
			return in_array( $raw, $allowed, true ) ? $raw : $allowed[0];
		}
		switch ( $type ) {
			case 'text':     return sanitize_text_field( (string) wp_unslash( $raw ) );
			case 'textarea': return sanitize_textarea_field( (string) wp_unslash( $raw ) );
			case 'email':    return sanitize_email( (string) wp_unslash( $raw ) );
			case 'float':    return (float) $raw;
			case 'int':      return (int) $raw;
			case 'currency': return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $raw ) );
		}
		return sanitize_text_field( (string) wp_unslash( $raw ) );
	}

	/**
	 * List active vendors for dropdowns.
	 *
	 * @return array<int,string>
	 */
	public static function dropdown_options(): array {
		$posts = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			]
		);
		$out = [ 0 => __( '— No vendor —', 'nkz-woo-stripe-vendor-split' ) ];
		foreach ( $posts as $p ) {
			$status = get_post_meta( $p->ID, '_nkv_vendor_status', true ) ?: 'active';
			$label  = $p->post_title . ( 'active' === $status ? '' : ' (' . $status . ')' );
			$out[ $p->ID ] = $label;
		}
		return $out;
	}
}
