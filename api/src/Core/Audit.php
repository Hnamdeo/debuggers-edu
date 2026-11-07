<?php
declare(strict_types=1);

namespace School\Core;

/** Who changed what, and when. Listens to every event, so modules never call it directly. */
final class Audit
{
    public function __construct(private readonly Db $db) {}

    public function listen(Events $events): void
    {
        $events->on('*', function (array $e, ?Request $req): void {
            $this->db->insert('audit_log', [
                'school_id' => $e['school_id'] ?? $req?->tenant?->schoolId,
                'user_id' => $req?->user['id'] ?? null,
                'event' => $e['event'],
                'entity_id' => $e['id'] ?? null,
                'data' => json_encode(array_diff_key($e, ['event' => 1]), JSON_UNESCAPED_UNICODE),
            ]);
        });
    }
}
