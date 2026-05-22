<?php
/**
 * Audit Recorder – zápis + read.
 *
 * Zápis je vždy append-only. Actor info se odvozuje z `wp_get_current_user()`,
 * pokud volající explicitně neuvede `actor_user_id`. Pro cron / WP-CLI události
 * actor zůstane 0 a actor_label nese původ (např. „cron", „cli", „webhook:stripe").
 *
 * @package NKZMP
 */

namespace NKZMP\Audit;

defined( 'ABSPATH' ) || exit;

final class Recorder {

	public function record(
		string $action,
		string $entity_type,
		int $entity_id,
		?string $summary = null,
		array $payload = [],
		?int $actor_user_id = null,
		?string $actor_label = null
	): Event {
		global $wpdb;

		if ( $actor_user_id === null ) {
			$user          = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
			$actor_user_id = $user && $user->ID ? (int) $user->ID : 0;
			if ( $actor_label === null && $user && $user->ID ) {
				$actor_label = (string) ( $user->user_login ?: $user->display_name );
			}
		}

		$occurred_at = time();
		$ip          = $this->client_ip();
		$ua          = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 255 ) : null;

		$wpdb->insert(
			Schema::table_name(),
			[
				'occurred_at'   => $occurred_at,
				'actor_user_id' => $actor_user_id,
				'actor_label'   => $actor_label,
				'action'        => $action,
				'entity_type'   => $entity_type,
				'entity_id'     => $entity_id,
				'summary'       => $summary !== null ? substr( $summary, 0, 255 ) : null,
				'payload_json'  => $payload ? wp_json_encode( $payload ) : null,
				'ip'            => $ip,
				'user_agent'    => $ua,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		$event = new Event(
			id:            (int) $wpdb->insert_id,
			occurred_at:   $occurred_at,
			actor_user_id: $actor_user_id,
			actor_label:   $actor_label,
			action:        $action,
			entity_type:   $entity_type,
			entity_id:     $entity_id,
			summary:       $summary,
			payload:       $payload,
			ip:            $ip,
			user_agent:    $ua,
		);

		do_action( 'nkzmp/v1/audit/event_recorded', $event );

		return $event;
	}

	/**
	 * @return Event[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;
		$table = Schema::table_name();

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['entity_type'] ) ) {
			$where[]  = 'entity_type = %s';
			$params[] = (string) $args['entity_type'];
		}
		if ( ! empty( $args['entity_id'] ) ) {
			$where[]  = 'entity_id = %d';
			$params[] = (int) $args['entity_id'];
		}
		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = (string) $args['action'];
		}
		if ( ! empty( $args['actor_user_id'] ) ) {
			$where[]  = 'actor_user_id = %d';
			$params[] = (int) $args['actor_user_id'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'occurred_at >= %d';
			$params[] = (int) $args['since'];
		}

		$limit  = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	private function hydrate( array $row ): Event {
		return new Event(
			id:            (int) $row['id'],
			occurred_at:   (int) $row['occurred_at'],
			actor_user_id: (int) $row['actor_user_id'],
			actor_label:   $row['actor_label'] !== null ? (string) $row['actor_label'] : null,
			action:        (string) $row['action'],
			entity_type:   (string) $row['entity_type'],
			entity_id:     (int) $row['entity_id'],
			summary:       $row['summary'] !== null ? (string) $row['summary'] : null,
			payload:       $row['payload_json'] ? (array) json_decode( $row['payload_json'], true ) : [],
			ip:            $row['ip'] !== null ? (string) $row['ip'] : null,
			user_agent:    $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
		);
	}

	private function client_ip(): ?string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = trim( (string) wp_unslash( $_SERVER[ $key ] ) );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return null;
	}
}
