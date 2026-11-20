<?php

namespace App\Support;

// Rules for one parent order that carries a top size matrix and a bottom size matrix (a complete uniform set).
final class SetOrder
{
    public const SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];

    public const PIECES = ['top', 'bottom'];

    public const MIN_PER_PIECE = 100;

    public const ORDERABLE = ['accepted', 'approved'];

    /** Returns [matrix, error]. The matrix is normalised to whole-number quantities for every configured size. */
    public static function normalise(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || array_diff(array_keys($raw), self::PIECES) || count($raw) !== 2) {
            return [null, 'Send one size matrix for the top and one for the bottom.'];
        }
        $out = [];
        foreach (self::PIECES as $piece) {
            if (! is_array($raw[$piece] ?? null) || array_diff(array_map('strtoupper', array_keys($raw[$piece])), self::SIZES)) {
                return [null, "The {$piece} matrix has a size that is not offered."];
            }
            $row = array_fill_keys(self::SIZES, 0);
            foreach ($raw[$piece] as $size => $qty) {
                if (! (is_int($qty) && $qty >= 0) && ! (is_string($qty) && ctype_digit($qty))) {
                    return [null, "The {$piece} quantities must be whole numbers, zero or more."];
                }
                $row[strtoupper($size)] = (int) $qty;
            }
            $out[$piece] = $row;
        }

        return [$out, null];
    }

    public static function check(array $matrix): ?string
    {
        $top = array_sum($matrix['top']);
        $bottom = array_sum($matrix['bottom']);
        $min = self::MIN_PER_PIECE;
        if ($top < $min || $bottom < $min) {
            return "Each piece needs at least {$min} pieces (tops {$top}, bottoms {$bottom}).";
        }
        if ($top !== $bottom) {
            return 'Tops and bottoms must match for a full set: '.$top.' tops vs '.$bottom.' bottoms ('.abs($top - $bottom).' apart).';
        }

        return null;
    }

    /** Message when an order's stored reference verdict no longer allows quoting or payment; null when fine or not a set order. */
    public static function blockedMessage(object $order, ?object $validation): ?string
    {
        if (empty($order->reference_validation_id)) {
            return null;
        }
        if (! $validation || ! in_array($validation->status, self::ORDERABLE, true)) {
            return 'The uploaded reference has not been confirmed as a complete uniform set.';
        }

        return null;
    }
}
