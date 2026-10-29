<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff = "Administrator (Station)": allow-listed admin routes only, production writes for their assigned stage, no money in responses.
// Applies to the staff role only; every other role passes straight through.
class StationScope
{
    // "METHOD route-uri" pairs a station may call.
    public const ALLOWED = [
        'GET api/admin/dashboard',
        'GET api/admin/notifications',
        'GET api/admin/notifications/unread-count',
        'POST api/admin/notifications/read-all',
        'PATCH api/admin/notifications/{id}/read',
        'GET api/admin/orders',
        'GET api/admin/orders/garment-types',
        'GET api/admin/orders/production-summaries',
        'GET api/admin/orders/{id}',
        'GET api/admin/orders/{id}/production',
        'GET api/admin/orders/{id}/production-summary',
        'GET api/admin/orders/{orderId}/qc',
        'GET api/admin/qc-checklists',
        'GET api/admin/output-logs',
        'GET api/admin/output-logs/{id}',
        'GET api/admin/output-logs/summary/{orderId}',
        'GET api/admin/inventory',
        'GET api/admin/materials',
        'GET api/admin/materials/{id}',
        'GET api/admin/messages',
        'GET api/admin/messages/unread-count',
        'GET api/admin/messages/{id}',
        'POST api/admin/messages',
        'POST api/admin/output-logs',
        'POST api/admin/orders/{orderId}/qc',
        'POST api/admin/orders/{id}/defects',
        'POST api/admin/feedback',
        'PUT api/admin/profile/password',
    ];

    // Production writes and the stage they belong to; null = the stage named in the request body.
    private const STAGE_WRITES = [
        'POST api/admin/output-logs' => null,
        'POST api/admin/orders/{orderId}/qc' => 'qc',
        'POST api/admin/orders/{id}/defects' => 'sewing',
    ];

    // Keys removed from every JSON response a station receives.
    public const MONEY_KEYS = [
        'revenue', 'monthly_revenue', 'monthly_sales', 'agreed_total', 'discount_rate', 'down_payment_amount',
        'amount_paid', 'amount_total', 'balance_due', 'balance', 'paid', 'dp_pct', 'total_value',
        'unit_cost', 'unit_price', 'total_cost', 'price', 'cost', 'transactions', 'invoices',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (! $user || RoleMiddleware::roleOf($request) !== 'staff') {
            return $next($request);
        }

        $key = $request->method().' '.$request->route()?->uri();
        if ($request->method() === 'HEAD') {
            $key = 'GET '.$request->route()?->uri();
        }
        if (! in_array($key, self::ALLOWED, true)) {
            return response()->json(['message' => 'Forbidden. This area is not available to station accounts.'], 403);
        }

        if (array_key_exists($key, self::STAGE_WRITES)) {
            $stage = self::STAGE_WRITES[$key] ?? $request->input('stage');
            if ($user->assigned_stage === null || $stage !== $user->assigned_stage) {
                return response()->json(['message' => 'Forbidden. You can only record work for your assigned stage.', 'assigned_stage' => $user->assigned_stage], 403);
            }
        }

        $response = $next($request);
        if ($response instanceof JsonResponse) {
            $response->setData(self::stripMoney($response->getData(true)));
        }

        return $response;
    }

    public static function stripMoney(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::MONEY_KEYS, true)) {
                unset($data[$k]);
            } else {
                $data[$k] = self::stripMoney($v);
            }
        }

        return $data;
    }
}
