<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ADDED Sept 7 2026 (QA account, emergency fix): this model never existed.
// Confirmed via storage/logs/laravel.log — 2 real, live, fatal
// "Class App\Models\Feedback not found" errors at FeedbackController.php:39
// (the store() method). The migration (2026_08_27_000003_create_feedback_
// table.php), FeedbackController.php, and the frontend (Feedback.jsx,
// FeedbackWidget.jsx per project memory) all already assume this file
// exists. Every POST to /api/customer/feedback or /api/admin/feedback,
// and every GET to /api/admin/feedback, has been a guaranteed fatal 500
// until this file exists. Columns match the migration exactly.
class Feedback extends Model
{
    protected $table = 'feedback';
    protected $primaryKey = 'feedback_id';

    protected $fillable = [
        'user_id', 'category', 'message', 'status',
    ];

    // FeedbackController::index() calls ->with('user:user_id,name,email') —
    // relation name 'user' must match that call exactly.
    public function user(): BelongsTo
    { return $this->belongsTo(User::class, 'user_id', 'user_id'); }
}
