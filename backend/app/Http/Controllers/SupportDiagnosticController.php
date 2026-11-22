<?php

namespace App\Http\Controllers;

use App\Models\SupportDiagnosticRun;
use App\Models\SupportIncident;
use App\Services\Audit;
use App\Services\SupportReadOnlyDiagnostics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SupportDiagnosticController extends Controller
{
    public function index(int $id)
    {
        Gate::authorize('supportDiagnostics');
        $incident = SupportIncident::findOrFail($id);

        return SupportDiagnosticRun::query()
            ->where('support_incident_id', $incident->id)
            ->with([])
            ->latest('id')
            ->limit(20)
            ->get(['id', 'check_name', 'status', 'duration_ms', 'created_at']);
    }

    public function run(Request $request, int $id, SupportReadOnlyDiagnostics $diagnostics)
    {
        Gate::authorize('supportDiagnostics');

        // This endpoint does not accept user-defined commands or parameters.
        // Reject rather than silently ignore unexpected fields.
        if (array_diff(array_keys($request->all()), ['check'])) {
            throw ValidationException::withMessages([
                'payload' => 'Champs supplémentaires non autorisés.',
            ]);
        }

        $data = $request->validate([
            'check' => ['required', 'string', Rule::in(SupportReadOnlyDiagnostics::CHECKS)],
        ]);

        $incident = SupportIncident::findOrFail($id);
        abort_if($incident->status === 'closed', 409, 'Diagnostic indisponible sur un incident clôturé.');
        abort_unless($incident->escalation_level === 3, 409, 'Escalade N3 nécessaire.');

        $result = $diagnostics->execute($data['check']);

        DB::transaction(function () use ($incident, $result) {
            SupportDiagnosticRun::create([
                'support_incident_id' => $incident->id,
                'triggered_by' => auth()->id(),
                'check_name' => $result['check'],
                'status' => $result['status'],
                'duration_ms' => $result['duration_ms'],
            ]);
            Audit::record('support.diagnostic_executed', $incident, [
                'check' => $result['check'],
                'status' => $result['status'],
            ]);
        });

        return response()->json($result);
    }
}
