<?php
/**
 * Audit event value object.
 *
 * @package NKZMP
 */

namespace NKZMP\Audit;

defined( 'ABSPATH' ) || exit;

final class Event {

	public function __construct(
		public readonly ?int $id,
		public readonly int $occurred_at,
		public readonly int $actor_user_id,
		public readonly ?string $actor_label,
		public readonly string $action,
		public readonly string $entity_type,
		public readonly int $entity_id,
		public readonly ?string $summary,
		public readonly array $payload = [],
		public readonly ?string $ip = null,
		public readonly ?string $user_agent = null,
	) {}

	public function to_array(): array {
		return [
			'id'            => $this->id,
			'occurred_at'   => $this->occurred_at,
			'actor_user_id' => $this->actor_user_id,
			'actor_label'   => $this->actor_label,
			'action'        => $this->action,
			'entity_type'   => $this->entity_type,
			'entity_id'     => $this->entity_id,
			'summary'       => $this->summary,
			'payload'       => $this->payload,
			'ip'            => $this->ip,
			'user_agent'    => $this->user_agent,
		];
	}
}
