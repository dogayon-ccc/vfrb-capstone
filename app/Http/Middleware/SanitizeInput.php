<?php
// app/Http/Middleware/SanitizeInput.php
// Strips XSS payloads from incoming inputs safely.
// Protects against stored XSS while avoiding corruption of sensitive fields.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    /**
     * Fields that must NEVER be sanitized
     * (sanitizing these can break authentication or data integrity)
     */
    private array $exempt = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'hash',
        'email', // ✅ CRITICAL FIX: prevents login issues
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // FIX (Aug 10 2026): $request->all() includes uploaded FILE objects,
        // not just string/array input — Laravel merges $request->files into
        // the array all() returns. The old code sanitized that whole array
        // (harmless for files, since is_string()/is_array() both fail for
        // an UploadedFile) but then called $request->merge($input) with the
        // file objects still in it — writing them into the request's
        // regular parameter bag (meant for strings/arrays) instead of
        // leaving them alone in the files bag. That's what silently broke
        // the underlying temp file reference, surfacing later as Flysystem's
        // "Path must not be empty" the moment code actually tried to read
        // from it — this exact "custom sanitize-all middleware breaks file
        // uploads" pattern is a well-known, documented Laravel gotcha.
        // Fix: only ever touch $request->request (POST/PUT string+array
        // fields) — $request->files is never read, sanitized, or merged,
        // so it reaches the controller exactly as PHP received it.
        $input = $request->request->all(); // excludes files entirely

        $this->sanitize($input);

        $request->request->replace($input); // same bag in, same bag out

        return $next($request);
    }

    /**
     * Recursively sanitize input data
     */
    private function sanitize(array &$data): void
    {
        foreach ($data as $key => &$value) {

            // Skip exempt fields
            if (in_array($key, $this->exempt, true)) {
                continue;
            }

            if (is_string($value)) {

                // Remove null bytes (basic injection vector)
                $value = str_replace("\0", '', $value);

                // Remove <script> tags (XSS)
                $value = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $value);

                // Remove inline JS events (onclick, onerror, etc.)
                $value = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/', '', $value);

                // Trim whitespace
                $value = trim($value);

            } elseif (is_array($value)) {
                // Recursive sanitization
                $this->sanitize($value);
            }
        }
    }
}