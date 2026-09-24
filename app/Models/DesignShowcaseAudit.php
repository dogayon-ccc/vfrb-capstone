<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DesignShowcaseAudit extends Model
{
    public $timestamps = false; // schema has created_at only, no updated_at
    protected $primaryKey = 'log_id';

    protected $fillable = ['design_id', 'actor_user_id', 'action', 'note'];

    protected $attributes = ['created_at' => null];

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->created_at ??= now());
    }
}
