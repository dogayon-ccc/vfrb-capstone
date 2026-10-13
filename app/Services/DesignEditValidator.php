<?php
// app/Services/DesignEditValidator.php
namespace App\Services;

/**
 * Keeps Gemini's Design Studio edits inside one template's real capabilities.
 *   template()  -> checks the template facts the Studio sent (zones, placements, patterns, current texts)
 *   schema()    -> JSON schema whose enums are exactly that template's values
 *   validate()  -> re-checks every operation; anything unknown is rejected with a reason, never repaired
 * The Studio computes the facts with the same functions that draw the garment (dsShared.zonesFor/placementsFor, PATTERNS),
 * and checks every returned operation against them again before applying it. Nothing here describes garment geometry.
 * Plain arrays only, so it is unit-testable like MaterialCatalogMatcher.
 */
final class DesignEditValidator
{
    // DesignState vocabulary (designSerialization.js colors keys, dsShared.LOGO_PRESETS ids), not geometry.
    public const ZONE_KEYS     = ['body', 'collar', 'sleeve', 'pocket', 'tipping'];
    public const PLACEMENT_IDS = ['left_chest', 'center_chest', 'back_center', 'left_arm', 'right_arm'];
    public const OPS           = ['set_color', 'set_pattern', 'add_text', 'edit_text', 'move_text'];
    public const MAX_OPS       = 12;
    public const MAX_TEXT      = 40;
    private const ID_RULE      = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * @return array{zones:string[],placements:string[],patterns:string[],texts:array<string,string>}|string normalized facts, or an error
     */
    public function template(array $t, array $texts): array|string
    {
        $zones = array_values(array_unique(array_filter((array) ($t['zones'] ?? []), 'is_string')));
        if (!$zones || array_diff($zones, self::ZONE_KEYS)) {
            return 'Unknown or missing colour zones for this template.';
        }
        $placements = array_values(array_unique(array_filter((array) ($t['placements'] ?? []), 'is_string')));
        if (array_diff($placements, self::PLACEMENT_IDS)) {
            return 'Unknown placement for this template.';
        }
        $patterns = array_values(array_unique(array_filter((array) ($t['patterns'] ?? []), 'is_string')));
        foreach ($patterns as $p) {
            if (!preg_match(self::ID_RULE, $p)) {
                return 'Invalid pattern id.';
            }
        }
        $byId = [];
        foreach ($texts as $tx) {
            $id = $tx['id'] ?? null;
            if (!is_string($id) || !preg_match(self::ID_RULE, $id)) {
                return 'Invalid text item id.';
            }
            $byId[$id] = $this->cleanText($tx['text'] ?? '');
        }
        return ['zones' => $zones, 'placements' => $placements, 'patterns' => $patterns, 'texts' => $byId];
    }

    /** Gemini responseJsonSchema: every enum is this template's own values; a missing capability is simply not offered. */
    public function schema(array $facts): array
    {
        $enum = fn (array $v) => $v ? ['type' => 'string', 'enum' => array_values($v)] : ['type' => 'string'];
        return [
            'type'       => 'object',
            'properties' => [
                'operations' => [
                    'type'     => 'array',
                    'maxItems' => self::MAX_OPS,
                    'items'    => [
                        'type'       => 'object',
                        'properties' => [
                            'op'        => $enum($this->opsFor($facts)),
                            'zone'      => $enum($facts['zones']),
                            'hex'       => ['type' => 'string'],
                            'pattern'   => $enum($facts['patterns']),
                            'text'      => ['type' => 'string'],
                            'placement' => $enum($facts['placements']),
                            'target'    => $enum(array_keys($facts['texts'])),
                        ],
                        'required' => ['op'],
                    ],
                ],
                'summary' => ['type' => 'string'],
            ],
            'required' => ['operations', 'summary'],
        ];
    }

    /**
     * @return array{operations:array<int,array<string,string>>,rejected:array<int,array{index:int,op:mixed,reason:string}>}
     */
    public function validate(mixed $ops, array $facts): array
    {
        $ok = []; $rejected = [];
        foreach (array_slice(is_array($ops) ? array_values($ops) : [], 0, self::MAX_OPS) as $i => $op) {
            $reason = is_array($op) ? $this->reasonInvalid($op, $facts) : 'Not an operation object.';
            if ($reason) {
                $rejected[] = ['index' => $i, 'op' => is_array($op) ? ($op['op'] ?? null) : null, 'reason' => $reason];
                continue;
            }
            $ok[] = $this->normalize($op);
        }
        return ['operations' => $ok, 'rejected' => $rejected];
    }

    private function opsFor(array $facts): array
    {
        return array_values(array_filter(self::OPS, fn ($o) => match ($o) {
            'set_pattern' => (bool) $facts['patterns'],
            'add_text'    => (bool) $facts['placements'],
            'edit_text'   => (bool) $facts['texts'],
            'move_text'   => $facts['texts'] && $facts['placements'],
            default       => true,
        }));
    }

    private function reasonInvalid(array $op, array $f): ?string
    {
        $extra = array_diff(array_keys($op), ['op', 'zone', 'hex', 'pattern', 'text', 'placement', 'target']);
        if ($extra) {
            return 'Unknown field: ' . implode(', ', $extra) . '.';
        }
        $has = fn (string $k, array $allowed) => isset($op[$k]) && is_string($op[$k]) && in_array($op[$k], $allowed, true);
        return match ($op['op'] ?? null) {
            'set_color'   => !$has('zone', $f['zones']) ? 'Zone is not on this garment.'
                : (!is_string($op['hex'] ?? null) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $op['hex']) ? 'Colour must be #RRGGBB.' : null),
            'set_pattern' => !$has('zone', $f['zones']) ? 'Zone is not on this garment.'
                : (!$has('pattern', $f['patterns']) ? 'Pattern is not available for this template.' : null),
            'add_text'    => $this->cleanText($op['text'] ?? '') === '' ? 'Text is empty.'
                : (!$has('placement', $f['placements']) ? 'Placement is not on this garment.' : null),
            'edit_text'   => !$has('target', array_keys($f['texts'])) ? 'No such text on the design.'
                : ($this->cleanText($op['text'] ?? '') === '' ? 'Text is empty.' : null),
            'move_text'   => !$has('target', array_keys($f['texts'])) ? 'No such text on the design.'
                : (!$has('placement', $f['placements']) ? 'Placement is not on this garment.' : null),
            default       => 'Unknown operation.',
        };
    }

    // Only the fields each operation uses, in a fixed shape.
    private function normalize(array $op): array
    {
        return match ($op['op']) {
            'set_color'   => ['op' => 'set_color', 'zone' => $op['zone'], 'hex' => strtoupper($op['hex'])],
            'set_pattern' => ['op' => 'set_pattern', 'zone' => $op['zone'], 'pattern' => $op['pattern']],
            'add_text'    => ['op' => 'add_text', 'text' => $this->cleanText($op['text']), 'placement' => $op['placement']],
            'edit_text'   => ['op' => 'edit_text', 'target' => $op['target'], 'text' => $this->cleanText($op['text'])],
            'move_text'   => ['op' => 'move_text', 'target' => $op['target'], 'placement' => $op['placement']],
        };
    }

    public function cleanText(mixed $v): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = trim(preg_replace('/[\p{C}]+/u', ' ', strip_tags($v)));
        return mb_substr(preg_replace('/\s+/u', ' ', $v), 0, self::MAX_TEXT);
    }
}
