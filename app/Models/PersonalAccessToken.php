<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

// Sanctum writes last_used_at on every request (~100 ms to Supabase); nothing reads it, so record it at most every 5 minutes.
class PersonalAccessToken extends SanctumToken
{
    public function save(array $options = [])
    {
        $onlyLastUsed = $this->exists && array_keys($this->getDirty()) === ['last_used_at'];
        $recent = $this->getOriginal('last_used_at') && $this->getOriginal('last_used_at')->gt(now()->subMinutes(5));

        return $onlyLastUsed && $recent ? true : parent::save($options);
    }
}
