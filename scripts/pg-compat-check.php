<?php
// Static guard: MySQL-only SQL must go through App\Support\Sql so the same code runs on MySQL (local) and Postgres (Supabase).
// Run: php scripts/pg-compat-check.php   (exit 1 = problem found). Needs no database and no composer install.

$root = dirname(__DIR__);
$flags = ['passed', 'stock_adjusted', 'reconciled', 'is_default', 'is_active', 'ai_eligible', 'customer_accepted', 'email_enabled',
    'sms_enabled', 'deadline_negotiated', 'qc_required', 'color_mismatch', 'color_confirmed', 'auto_generated', 'scanned_via_qr',
    'stitching_ok', 'color_ok', 'size_ok', 'label_ok', 'finish_ok', 'button_ok'];
$flagRe = '/\b(?<![>\w$])(' . implode('|', $flags) . ')\b\s*(=|<>|!=)\s*[01]\b/';
$mysqlOnly = '/\b(DATE_FORMAT|GROUP_CONCAT|IFNULL|STR_TO_DATE|FIND_IN_SET|UNIX_TIMESTAMP|CURDATE|DATEDIFF)\s*\(|\b(ON DUPLICATE KEY|INSERT IGNORE)\b/i';
$rawCall = '/(selectRaw|whereRaw|havingRaw|orderByRaw|groupByRaw|DB::raw|DB::select|DB::statement|DB::unprepared)/';
$problems = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php' || str_ends_with($f->getPathname(), 'app/Support/Sql.php')) continue;
    $lines = file($f->getPathname());
    $hasRaw = (bool) preg_match($rawCall, implode('', $lines)); // a raw string can span lines, so judge by file
    foreach ($lines as $n => $line) {
        $isComment = preg_match('#^\s*(//|\*|/\*)#', $line);
        if ($isComment) continue;
        if (preg_match($mysqlOnly, $line, $m)) $problems[] = [$f->getPathname(), $n + 1, "MySQL-only SQL ({$m[0]}): use a Sql:: helper"];
        if ($hasRaw && preg_match('/\b(CASE WHEN|WHERE|AND|OR|HAVING|ON|SUM|SELECT)\b/i', $line) && preg_match($flagRe, $line, $m)) $problems[] = [$f->getPathname(), $n + 1, "raw SQL compares flag '{$m[1]}' to a number: use Sql::flag('{$m[1]}')"];
    }
}

// Migrations: a MySQL-only statement must sit behind a driver guard in the same file.
foreach (glob("$root/database/migrations/*.php") as $file) {
    $src = file_get_contents($file);
    if (preg_match('/MODIFY COLUMN|ENGINE\s*=|FULLTEXT/i', $src) && ! preg_match('/getDriverName\(\)|DB_CONNECTION|isPostgres|=== ?\'mysql\'|!== ?\'pgsql\'/', $src)) {
        $problems[] = [$file, 1, 'MySQL-only statement without a driver guard (the Supabase copy would fail or be skipped silently)'];
    }
}

foreach ($problems as [$file, $line, $why]) echo str_replace("$root/", '', $file) . ":$line  $why\n";
echo $problems ? count($problems) . " problem(s)\n" : "OK: no MySQL-only SQL outside Sql.php, no unguarded flag comparisons, migrations guarded\n";
exit($problems ? 1 : 0);
