<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// Manager-maintained top+bottom combinations VFRB sells as one uniform set. An empty list approves nothing.
final class SetPairs
{
    public static function approved(?string $top, ?string $bottom): bool
    {
        return $top && $bottom && DB::table('approved_set_pairs')->where('top_garment', $top)->where('bottom_garment', $bottom)->exists();
    }
}
