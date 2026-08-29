<?php
// app/Http/Controllers/Api/FeedbackController.php
// Minimal feedback system — any authenticated user can submit, manager
// views/triages. Deliberately no upvoting/public board — see migration
// comment for scope rationale.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FeedbackController extends Controller
{
    // POST /api/customer/feedback  and  POST /api/admin/feedback
    // Same handler for both — any authenticated user (customer, staff, or
    // manager) can submit feedback about the system itself.
    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => 'nullable|in:bug,suggestion,other',
            'message'  => 'required|string|max:2000',
        ]);

        $feedback = Feedback::create([
            'user_id'  => Auth::id(),
            'category' => $data['category'] ?? 'other',
            'message'  => $data['message'],
            'status'   => 'new',
        ]);

        return response()->json($feedback, 201);
    }

    // GET /api/admin/feedback — manager-only triage list
    public function index(Request $request)
    {
        $query = Feedback::with('user:user_id,name,email')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->paginate(20));
    }

    // PATCH /api/admin/feedback/{id} — manager marks reviewed/archived
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => 'required|in:new,reviewed,archived',
        ]);

        $feedback = Feedback::findOrFail($id);
        $feedback->update(['status' => $data['status']]);

        return response()->json($feedback);
    }
}
