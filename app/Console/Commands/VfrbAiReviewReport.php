<?php

namespace App\Console\Commands;

use App\Support\AiReviewStats;
use Illuminate\Console\Command;

/**
 * Step 2: turn the run file and the rated sheet into the counts and percentages for the thesis table.
 *   php artisan vfrb:ai-review-report storage/app/ai-review/run-<stamp>.json storage/app/ai-review/rating-sheet-<stamp>.csv [--json]
 */
class VfrbAiReviewReport extends Command
{
    protected $signature = 'vfrb:ai-review-report {run : run-<stamp>.json} {sheet : the rated rating-sheet CSV} {--json}';
    protected $description = 'Summarise a staff-rated AI recommendation review (counts, percentages, fallback and rejection rates)';

    public function handle(): int
    {
        $run = json_decode((string) @file_get_contents($this->argument('run')), true);
        if (!is_array($run) || !isset($run['orders'])) { $this->error('Run file unreadable.'); return self::FAILURE; }
        $fh = @fopen($this->argument('sheet'), 'r');
        if (!$fh) { $this->error('Sheet unreadable.'); return self::FAILURE; }
        $head = fgetcsv($fh); $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]); $rows = [];
        while (($r = fgetcsv($fh)) !== false) { if ($r !== [null] && count($r) === count($head)) { $rows[] = array_combine($head, $r); } }
        fclose($fh);

        $out = ['meta' => $run['meta'] ?? [], 'run' => AiReviewStats::run($run['orders']), 'ratings' => AiReviewStats::ratings($rows), 'against_past_orders' => AiReviewStats::againstPast($rows)];
        if ($this->option('json')) { $this->line(json_encode($out, JSON_PRETTY_PRINT)); return self::SUCCESS; }

        $m = $out['meta']; $x = $out['ratings'];
        $this->line("Model: {$m['model']} (fallback {$m['fallback_model']}) | tested {$m['tested_at']} | outputs can vary between runs");
        $this->table(['Rating', 'Frequency', 'Percent'], array_map(fn ($k) => [$k, $x['counts'][$k], ($x['percent'][$k] ?? '-') . '%'], AiReviewStats::RATINGS));
        $this->line("Rated {$x['rated']} suggestions; unrated {$x['unrated']}; invalid rating values {$x['invalid']}.");
        $this->line(json_encode($out['run']));
        $this->line(json_encode($out['against_past_orders']));
        if ($x['unrated'] > 0) { $this->warn('Some suggestions are unrated: do not report percentages until the reviewer finishes.'); }
        return self::SUCCESS;
    }
}
