<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2\Mobile;

use App\Http\Controllers\Controller;
use App\Models\V2\ParentAccount;
use App\Models\V2\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GateHistoryController extends Controller
{
    public function __invoke(Request $request, int $student)
    {
        $parent = $request->user();
        abort_unless($parent instanceof ParentAccount && $parent->status === 'active' && ! $parent->deleted_at, 403);
        $record = DB::table('ec_students')->where('id', $student)->whereNull('deleted_at')
            ->where('mobile_visible', true)->whereIn('status', Student::MOBILE_VISIBLE_STATUSES)->first();
        abort_unless($record && DB::table('ec_parent_student_links')->where('student_id', $student)
            ->where('parent_account_id', $parent->id)->where('tenant_id', $record->tenant_id)->where('school_id', $record->school_id)
            ->where('status', 'active')->whereNull('deleted_at')->exists(), 404);
        abort_unless(config('gate.enabled') && in_array((int) $record->school_id, config('gate.school_ids', []), true), 409, 'Gate history is not enabled.');
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'day' => ['sometimes', 'date_format:Y-m-d']]);
        $query = DB::table('ec_gate_movements as m')->join('ec_gate_days as d', 'd.id', '=', 'm.day_id')
            ->where('d.student_id', $student)->where('d.tenant_id', $record->tenant_id)->where('d.school_id', $record->school_id);
        if (isset($filters['day'])) {
            $query->where('d.day', $filters['day']);
        }
        $events = $query->orderByDesc('d.day')->orderByDesc('m.created_at')->orderByDesc('m.event_id')
            ->select(['m.event_id', 'm.revision', 'm.payload', 'd.day', 'd.state as day_state'])->paginate(50)
            ->through(static function ($row): array {
                $payload = json_decode($row->payload, true, 16, JSON_THROW_ON_ERROR);

                return ['event_id' => $row->event_id, 'revision' => (int) $row->revision,
                    'day' => $row->day, 'recorded_day_state' => $row->day_state,
                    'event' => array_intersect_key($payload, array_flip(['movement', 'state', 'occurred_at', 'received_at', 'timezone', 'late', 'related_event_id']))];
            });

        return response()->json(['status' => 'success', 'data' => $events,
            'notice' => 'Policy-derived gate records; recognition alone does not prove physical passage.'])
            ->header('Cache-Control', 'private, no-store');
    }
}
