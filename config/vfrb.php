<?php

return [
    // Working days of lead time per 100 pieces, used for the suggested promised date.
    // PLACEHOLDER: VFRB must confirm this number. Admin can override it per order (lead_days).
    'lead_days_per_100' => (int) env('VFRB_LEAD_DAYS_PER_100', 14),

    // Every order: the promised date must be at least this many days after confirmation (no rush orders).
    'min_promised_days' => 7,
];
