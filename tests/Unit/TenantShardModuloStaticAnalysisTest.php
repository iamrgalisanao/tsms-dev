<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * T037: Architecture/static regression test guarding against reintroduction of
 * hardcoded tenant-shard modulo routing (e.g. `$tenantId % 8`).
 *
 * Background: T040-T043 replaced four production call sites that computed
 * their processing queue shard with a literal `$tenantId % 8` (see
 * app/Http/Controllers/TestTransactionController.php,
 * app/Http/Controllers/API/V1/RetryHistoryController.php,
 * app/Jobs/BulkGenerateTransactionsJob.php,
 * app/Console/Commands/TestTransactionPipeline.php — all now dispatch via
 * IngestionQueueRouter::processingQueueForTenant()). This test statically
 * scans `app/` so that if the same bug shape is ever reintroduced by new
 * code, a test fails instead of relying on developers remembering the rule.
 *
 * Detection approach: PHP's own tokenizer (`PhpToken::tokenize()`, PHP 8.0+)
 * rather than a raw text/regex scan of file contents. This guarantees we
 * only ever inspect real `%` *operator* tokens — never characters that
 * merely look like `%` inside a string literal (e.g. a SQL `LIKE "%foo%"`
 * clause) or inside a comment, both of which are tokenized by PHP as single
 * opaque string/comment tokens and are simply skipped, not scanned character
 * by character. A flagged operator must additionally satisfy both:
 *
 *   1. Its right-hand operand is a literal integer (T_LNUMBER), optionally
 *      wrapped in parens — i.e. "modulo by a hardcoded shard count". This is
 *      what distinguishes the dangerous shape from the canonical router's
 *      own `crc32(...) % $shardCount` (a *variable*, sourced from config),
 *      which is therefore never flagged without needing a special-case path
 *      exclusion for IngestionQueueRouter.php.
 *   2. Its left-hand operand expression (walked backwards token-by-token,
 *      honoring paren nesting, stopping at statement boundaries such as
 *      `;`, `{`, `}`, `=`, `=>`, `,`, `return`) either:
 *        a) contains an identifier that looks like a tenant/store/user/
 *           terminal id (`/\$?(tenant|store|user|terminal)s?[-_]?id/i`),
 *           which catches `$tenantId % 8`, `($x->tenant_id ?? 0) % 8`,
 *           `$storeId % 4`, `$userId % 16`, etc.; or
 *        b) contains a `crc32(` call, which catches a new violation that
 *           re-derives the router's own hashing trick outside the router
 *           (`crc32($id) % 8`) even when the variable name doesn't happen
 *           to match (a) — crc32-then-modulo-by-literal is not a pattern
 *           this codebase has any legitimate use for outside the router.
 *
 * This intentionally does NOT flag `%` in general (pagination, percentages,
 * batch-size counters, day-of-week math, etc.) because those either have a
 * non-literal right-hand operand or a left-hand operand with no
 * tenant/store/user/terminal/crc32 shape — see
 * test_detector_allows_unrelated_modulo_and_canonical_router_pattern below,
 * which exercises real shapes found elsewhere in this codebase
 * (app/Http/Controllers/DashboardController.php:325 and
 * app/Services/Security/SecurityReportCsvWriter.php:132) to prove they
 * pass clean.
 */
class TenantShardModuloStaticAnalysisTest extends TestCase
{
    /**
     * @return array<int, array{line:int, snippet:string}>
     */
    private static function findModuloViolations(string $code): array
    {
        $violations = [];

        $tokens = \PhpToken::tokenize($code);
        $meaningful = [];
        foreach ($tokens as $token) {
            if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $meaningful[] = $token;
        }

        $count = count($meaningful);
        $boundary = [';', '{', '}', '=', '=>', ',', 'return'];

        foreach ($meaningful as $i => $token) {
            if ($token->text !== '%') {
                continue;
            }

            // Right-hand side must be a literal integer (a hardcoded shard
            // count), optionally parenthesized. A variable/config-driven
            // right-hand side (the canonical router's `% $shardCount`) is
            // allowed and is simply not a match here.
            $j = $i + 1;
            while ($j < $count && $meaningful[$j]->text === '(') {
                $j++;
            }
            if ($j >= $count || $meaningful[$j]->id !== T_LNUMBER) {
                continue;
            }
            $literal = $meaningful[$j]->text;

            // Walk the left-hand operand expression backwards, honoring
            // paren nesting so `($x->tenant_id ?? 0)` is captured whole,
            // stopping at a real statement/expression boundary.
            $leftParts = [];
            $depth = 0;
            $k = $i - 1;
            while ($k >= 0) {
                $text = $meaningful[$k]->text;

                if ($text === ')') {
                    $depth++;
                    $leftParts[] = $text;
                    $k--;

                    continue;
                }

                if ($text === '(') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                    $leftParts[] = $text;
                    $k--;

                    continue;
                }

                if ($depth === 0 && in_array(strtolower($text), $boundary, true)) {
                    break;
                }

                $leftParts[] = $text;
                $k--;

                if (count($leftParts) > 60) {
                    break; // safety cap; not a realistic operand length
                }
            }

            $leftConcat = implode('', array_reverse($leftParts));

            $looksLikeShardIdentifier = (bool) preg_match(
                '/\$?(tenant|store|user|terminal)s?[-_]?id/i',
                $leftConcat
            );
            $looksLikeCrc32Hash = stripos($leftConcat, 'crc32(') !== false;

            if ($looksLikeShardIdentifier || $looksLikeCrc32Hash) {
                $violations[] = [
                    'line' => $token->line,
                    'snippet' => trim($leftConcat).' % '.$literal,
                ];
            }
        }

        return $violations;
    }

    /**
     * Proves the detector is not vacuously passing: representative shapes of
     * the actual bug that T040-T043 fixed (and plausible new variants of it)
     * must be flagged when run directly against in-memory fixture snippets.
     */
    public function test_detector_flags_known_dangerous_patterns(): void
    {
        $dangerousSnippets = [
            'original T040-T043 shape' => '<?php $shard = $tenantId % 8;',
            'wrapped with null coalesce (actual pre-fix BulkGenerateTransactionsJob shape)' => '<?php $shard = ($transaction->tenant_id ?? 0) % 8;',
            'property access shape' => '<?php $shard = $request->tenant_id % 8;',
            'different identifier, different literal' => '<?php $shard = $storeId % 4;',
            'userId variant' => '<?php $shard = $userId % 16;',
            'terminalId variant' => '<?php $shard = $terminalId % 8;',
            'crc32-then-modulo outside the router, non-matching variable name' => '<?php $shard = crc32((string) $id) % 8;',
        ];

        foreach ($dangerousSnippets as $label => $snippet) {
            $violations = self::findModuloViolations($snippet);
            $this->assertNotEmpty(
                $violations,
                "Detector failed to flag dangerous pattern ({$label}): {$snippet}"
            );
        }
    }

    /**
     * Proves the detector does not produce false positives on legitimate,
     * unrelated modulo usage, including real shapes already present
     * elsewhere in this codebase, and explicitly allows the canonical
     * router pattern (dynamic right-hand side, not a literal).
     */
    public function test_detector_allows_unrelated_modulo_and_canonical_router_pattern(): void
    {
        $benignSnippets = [
            'day-of-week math (DashboardController.php:325)' => '<?php $dayIndex = ((int) $row->day_of_week + 5) % 7;',
            'batch-size counter (SecurityReportCsvWriter.php:132)' => '<?php if ($count % self::BATCH_SIZE === 0) { flush(); }',
            'canonical router pattern: dynamic shard count, not a literal' => '<?php return crc32((string) $tenantId) % $shardCount;',
            'plain percentage math, unrelated identifier' => '<?php $growthRate = ($new - $old) % 100;',
            'pagination offset, unrelated identifier' => '<?php $page = $offset % $perPage;',
            'SQL LIKE wildcard string, contains a literal % character but no operator' => '<?php $q->where("name", "like", "%{$search}%");',
        ];

        foreach ($benignSnippets as $label => $snippet) {
            $violations = self::findModuloViolations($snippet);
            $this->assertSame(
                [],
                $violations,
                "Detector produced a false positive on benign pattern ({$label}): {$snippet}"
            );
        }
    }

    /**
     * The actual regression guard: scans all production PHP source under
     * app/ (excluding IngestionQueueRouter.php, the one file explicitly
     * allowed to compute `crc32(...) % $shardCount` since it IS the
     * canonical implementation new code is supposed to delegate to) for the
     * hardcoded tenant-shard modulo shape. Confirms T040-T043's fix is
     * complete today and guards against reintroduction going forward.
     */
    public function test_app_source_has_no_hardcoded_tenant_shard_modulo(): void
    {
        $appPath = dirname(__DIR__, 2).'/app';
        $allowedFile = realpath($appPath.'/Services/IngestionQueueRouter.php');

        $finder = Finder::create()->files()->in($appPath)->name('*.php');

        $allViolations = [];

        foreach ($finder as $file) {
            $realPath = $file->getRealPath();

            if ($allowedFile !== false && $realPath === $allowedFile) {
                continue;
            }

            $violations = self::findModuloViolations($file->getContents());

            foreach ($violations as $violation) {
                $allViolations[] = sprintf(
                    '%s:%d — `%s`',
                    $file->getRelativePathname(),
                    $violation['line'],
                    $violation['snippet']
                );
            }
        }

        $this->assertSame(
            [],
            $allViolations,
            'Found hardcoded tenant/store/user/terminal shard modulo routing outside IngestionQueueRouter. '
            ."Use IngestionQueueRouter::processingQueueForTenant() / intakeQueueForTenant() instead:\n"
            .implode("\n", $allViolations)
        );
    }
}
