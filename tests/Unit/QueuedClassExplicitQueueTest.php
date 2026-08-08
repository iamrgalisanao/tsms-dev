<?php

namespace Tests\Unit;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;

/**
 * T046a: Architecture/static regression test guarding against the
 * implicit-default-queue gap (ShouldQueue/ShouldBroadcast classes that fall
 * back to Redis's configured default queue, `'default'`, which no staging or
 * production Horizon supervisor consumes — see
 * specs/001-100-tenant-resilience/tasks.md T046a).
 *
 * Detection approach: PHP's own Reflection API plus `PhpToken::tokenize()`
 * (PHP 8.0+) inspection of individual method bodies — never a raw
 * regex-over-whole-file-text scan — following the tokenizer-based approach
 * already established by tests/Unit/TenantShardModuloStaticAnalysisTest.php.
 * Reflection alone can prove a class implements ShouldQueue/ShouldBroadcast,
 * has a `public $queue` property (and its literal default value), or
 * declares `viaQueues()`/`broadcastQueue()`/`__construct()`. Tokenizing the
 * *body* of those specific methods (never their result — nothing here is
 * ever executed/instantiated) is what proves whether `$this->onQueue(...)`
 * is actually called, and whether `viaQueues()`'s returned array's keys
 * cover every channel string literal `via()` can produce.
 *
 * An "explicit queue assignment" is any of:
 *   1. A non-null `public $queue` property.
 *   2. `$this->onQueue(...)` called in the class's own `__construct()`.
 *   3. A `viaQueues()` method whose returned array keys cover every channel
 *      string literal that the class's own `via()` method can emit (a
 *      `null`/unset `$queue` is acceptable ONLY under this mechanism — this
 *      mirrors the precedent already established by
 *      `TransactionFailureThresholdExceeded`/`TenantInactivityAlert`, which
 *      map every real channel via `viaQueues()` without ever setting
 *      `$queue` to a literal string).
 *   4. A non-null `broadcastQueue()` method (`ShouldBroadcast` events).
 */
class QueuedClassExplicitQueueTest extends TestCase
{
    /**
     * The 9 classes covered by T046a's approved mapping, each mapped to the
     * mechanism it is expected to use (per tasks.md's "Final approved
     * mapping"). This is the authoritative required-coverage list — every
     * one of these must both exist and pass the detector with exactly this
     * mechanism.
     */
    private const REQUIRED_MECHANISM_MAP = [
        // NOTE: these three are NOT `public $queue = '...';` properties.
        // Queueable already declares an untyped `public $queue;` (default
        // null), and PHP 8.4 treats a class re-declaring that trait
        // property with a different default value as an incompatible
        // composition — a hard fatal error at class-load time, not merely a
        // silent override as in older PHP versions. `onQueue()` in the
        // constructor is used instead: an equally explicit, approved
        // assignment mechanism per T046a's own acceptance criteria.
        \App\Jobs\CheckCircuitBreakerStatus::class => 'onQueue() in constructor',
        \App\Jobs\CheckTransactionFailureThresholdsJob::class => 'onQueue() in constructor',
        \App\Jobs\DispatchWebhookRetryJob::class => 'onQueue() in constructor',
        \App\Notifications\BatchProcessingFailure::class => 'viaQueues() covering via()',
        \App\Notifications\SecurityAuditAlert::class => 'viaQueues() covering via()',
        \App\Notifications\TransactionResultNotification::class => 'viaQueues() covering via()',
        \App\Events\TransactionUpdated::class => 'broadcastQueue()',
        \App\Events\TransactionStatusUpdated::class => 'broadcastQueue()',
        \App\Events\TransactionRetryUpdated::class => 'broadcastQueue()',
    ];

    /**
     * Classes intentionally excluded from the blanket app/ sweep below
     * because their queue is assigned entirely at the dispatch call site
     * (a chained `->onQueue(...)` on the `PendingDispatch`), never inside
     * the class itself — so the class-level detector legitimately finds
     * nothing, by design, and that is not a T046a-scoped gap:
     *
     * - ProcessTransactionIntakeJob: both real dispatch sites
     *   (app/Services/TransactionIntakeService.php:346 and
     *   app/Console/Commands/ReconcileStrandedIntake.php:60) chain
     *   ->onQueue(...) explicitly. Pre-existing pattern, not one of T046a's
     *   9 approved-mapping classes; out of scope to refactor here.
     */
    private const CALL_SITE_QUEUE_ALLOWLIST = [
        \App\Jobs\ProcessTransactionIntakeJob::class,
    ];

    // ------------------------------------------------------------------
    // Tokenizer helpers
    // ------------------------------------------------------------------

    private static function methodBodySource(ReflectionMethod $method): string
    {
        $lines = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $start;

        return '<?php '.implode('', array_slice($lines, $start, $length));
    }

    /**
     * @return array<int, \PhpToken>
     */
    private static function meaningfulTokens(string $code): array
    {
        $tokens = \PhpToken::tokenize($code);
        $out = [];
        foreach ($tokens as $token) {
            if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = $token;
        }

        return $out;
    }

    private static function ownMethod(ReflectionClass $ref, string $name): ?ReflectionMethod
    {
        if (! $ref->hasMethod($name)) {
            return null;
        }

        $method = $ref->getMethod($name);

        // Only inspect source actually declared on this class — an inherited
        // method proves nothing about whether *this* class explicitly
        // assigns a queue.
        if ($method->getDeclaringClass()->getName() !== $ref->getName()) {
            return null;
        }

        return $method;
    }

    private static function constructorCallsOnQueue(ReflectionClass $ref): bool
    {
        $ctor = self::ownMethod($ref, '__construct');
        if ($ctor === null) {
            return false;
        }

        $tokens = self::meaningfulTokens(self::methodBodySource($ctor));
        $count = count($tokens);

        for ($i = 0; $i < $count - 2; $i++) {
            if ($tokens[$i]->text === '$this'
                && $tokens[$i + 1]->text === '->'
                && strcasecmp($tokens[$i + 2]->text, 'onQueue') === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collects every string-literal channel name that `via()` can possibly
     * return: direct `return ['a', 'b'];` literals, plus any string literal
     * ever assigned (`$var = [...]`) or pushed (`$var[] = 'x';`) into a
     * variable that is returned somewhere in the method. Conditional
     * branches are deliberately unioned rather than tracked precisely — a
     * channel reachable down ANY branch must be covered by viaQueues(),
     * so over-approximating what "via() can emit" is the safe direction.
     *
     * @return array<int, string>
     */
    private static function emittedViaChannels(ReflectionClass $ref): array
    {
        $via = self::ownMethod($ref, 'via');
        if ($via === null) {
            return [];
        }

        $tokens = self::meaningfulTokens(self::methodBodySource($via));
        $count = count($tokens);
        $channels = [];

        $returnedVars = [];
        for ($i = 0; $i < $count; $i++) {
            if (strcasecmp($tokens[$i]->text, 'return') === 0
                && $i + 1 < $count
                && $tokens[$i + 1]->id === T_VARIABLE
            ) {
                $returnedVars[$tokens[$i + 1]->text] = true;
            }
        }

        // Direct `return [ ... ];` literal arrays.
        for ($i = 0; $i < $count; $i++) {
            if (strcasecmp($tokens[$i]->text, 'return') === 0
                && $i + 1 < $count
                && $tokens[$i + 1]->text === '['
            ) {
                $depth = 0;
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->text === '[') {
                        $depth++;

                        continue;
                    }
                    if ($tokens[$j]->text === ']') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }

                        continue;
                    }
                    if ($tokens[$j]->id === T_CONSTANT_ENCAPSED_STRING) {
                        $channels[] = trim($tokens[$j]->text, "'\"");
                    }
                }
            }
        }

        // `$var = [ ... ];` and `$var[] = 'x';` for every variable that is
        // ever returned by this method.
        foreach (array_keys($returnedVars) as $varName) {
            for ($i = 0; $i < $count; $i++) {
                if ($tokens[$i]->id !== T_VARIABLE || $tokens[$i]->text !== $varName) {
                    continue;
                }

                if ($i + 2 < $count && $tokens[$i + 1]->text === '=' && $tokens[$i + 2]->text === '[') {
                    $depth = 0;
                    for ($j = $i + 2; $j < $count; $j++) {
                        if ($tokens[$j]->text === '[') {
                            $depth++;

                            continue;
                        }
                        if ($tokens[$j]->text === ']') {
                            $depth--;
                            if ($depth === 0) {
                                break;
                            }

                            continue;
                        }
                        if ($tokens[$j]->id === T_CONSTANT_ENCAPSED_STRING) {
                            $channels[] = trim($tokens[$j]->text, "'\"");
                        }
                    }
                }

                if ($i + 3 < $count
                    && $tokens[$i + 1]->text === '['
                    && $tokens[$i + 2]->text === ']'
                    && $tokens[$i + 3]->text === '='
                ) {
                    for ($j = $i + 4; $j < $count && $tokens[$j]->text !== ';'; $j++) {
                        if ($tokens[$j]->id === T_CONSTANT_ENCAPSED_STRING) {
                            $channels[] = trim($tokens[$j]->text, "'\"");
                        }
                    }
                }
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * @return array<int, string>|null Null when no own `viaQueues()` exists.
     */
    private static function viaQueuesKeys(ReflectionClass $ref): ?array
    {
        $method = self::ownMethod($ref, 'viaQueues');
        if ($method === null) {
            return null;
        }

        $tokens = self::meaningfulTokens(self::methodBodySource($method));
        $count = count($tokens);
        $keys = [];

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id === T_CONSTANT_ENCAPSED_STRING
                && $i + 1 < $count
                && $tokens[$i + 1]->id === T_DOUBLE_ARROW
            ) {
                $keys[] = trim($tokens[$i]->text, "'\"");
            }
        }

        return array_values(array_unique($keys));
    }

    private static function broadcastQueueReturnsNonNull(ReflectionClass $ref): bool
    {
        $method = self::ownMethod($ref, 'broadcastQueue');
        if ($method === null) {
            return false;
        }

        $tokens = self::meaningfulTokens(self::methodBodySource($method));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (strcasecmp($tokens[$i]->text, 'return') !== 0) {
                continue;
            }

            if ($i + 1 >= $count || $tokens[$i + 1]->text === ';') {
                return false; // bare `return;`
            }

            if (strcasecmp($tokens[$i + 1]->text, 'null') === 0) {
                return false; // `return null;`
            }

            return true;
        }

        return false;
    }

    /**
     * @return array{ok: bool, mechanism: string}
     */
    private static function explicitQueueAssignment(string $class): array
    {
        $ref = new ReflectionClass($class);

        if ($ref->implementsInterface(ShouldBroadcast::class)) {
            return self::broadcastQueueReturnsNonNull($ref)
                ? ['ok' => true, 'mechanism' => 'broadcastQueue()']
                : ['ok' => false, 'mechanism' => 'none'];
        }

        if ($ref->hasProperty('queue')) {
            $prop = $ref->getProperty('queue');
            if ($prop->isPublic() && ! $prop->isStatic() && $prop->getDefaultValue() !== null) {
                return ['ok' => true, 'mechanism' => 'public $queue property'];
            }
        }

        if (self::constructorCallsOnQueue($ref)) {
            return ['ok' => true, 'mechanism' => 'onQueue() in constructor'];
        }

        $viaQueuesKeys = self::viaQueuesKeys($ref);
        if ($viaQueuesKeys !== null) {
            $emitted = self::emittedViaChannels($ref);
            if (! empty($emitted) && empty(array_diff($emitted, $viaQueuesKeys))) {
                return ['ok' => true, 'mechanism' => 'viaQueues() covering via()'];
            }
        }

        return ['ok' => false, 'mechanism' => 'none'];
    }

    // ------------------------------------------------------------------
    // Self-test fixtures: prove the detector itself is not vacuous.
    // ------------------------------------------------------------------

    public function test_detector_rejects_class_with_no_explicit_queue_mechanism(): void
    {
        $fixture = new class implements ShouldQueue
        {
            use \Illuminate\Bus\Queueable;

            public function handle(): void {}
        };

        $result = self::explicitQueueAssignment(get_class($fixture));

        $this->assertFalse($result['ok'], 'Detector must reject a class with none of the 4 mechanisms.');
    }

    public function test_detector_accepts_public_queue_property(): void
    {
        // Deliberately does NOT `use Queueable;` here: Queueable already
        // declares an untyped `public $queue;` (default null), and PHP 8.4
        // treats a class re-declaring that trait property with a different
        // default as an incompatible composition (hard fatal error at
        // class-load time) — this is exactly why the real
        // CheckCircuitBreakerStatus/CheckTransactionFailureThresholdsJob/
        // DispatchWebhookRetryJob classes use `onQueue()` in their
        // constructor instead of this mechanism. ShouldQueue itself is a
        // pure marker interface with no required methods, so the fixture
        // doesn't need the trait to exercise this mechanism in isolation.
        $fixture = new class implements ShouldQueue
        {
            public $queue = 'low';

            public function handle(): void {}
        };

        $result = self::explicitQueueAssignment(get_class($fixture));

        $this->assertTrue($result['ok']);
        $this->assertSame('public $queue property', $result['mechanism']);
    }

    public function test_detector_accepts_on_queue_in_constructor(): void
    {
        $fixture = new class implements ShouldQueue
        {
            use \Illuminate\Bus\Queueable;

            public function __construct()
            {
                $this->onQueue('low');
            }

            public function handle(): void {}
        };

        $result = self::explicitQueueAssignment(get_class($fixture));

        $this->assertTrue($result['ok']);
        $this->assertSame('onQueue() in constructor', $result['mechanism']);
    }

    public function test_detector_accepts_via_queues_covering_via(): void
    {
        $fixture = new class implements ShouldQueue
        {
            use \Illuminate\Bus\Queueable;

            public function via($notifiable): array
            {
                return ['mail', 'database'];
            }

            public function viaQueues(): array
            {
                return ['mail' => 'notifications', 'database' => 'notifications'];
            }
        };

        $result = self::explicitQueueAssignment(get_class($fixture));

        $this->assertTrue($result['ok']);
        $this->assertSame('viaQueues() covering via()', $result['mechanism']);
    }

    public function test_detector_accepts_broadcast_queue_method(): void
    {
        $fixture = new class implements ShouldBroadcast
        {
            public function broadcastOn()
            {
                return [];
            }

            public function broadcastQueue(): string
            {
                return 'notifications';
            }
        };

        $result = self::explicitQueueAssignment(get_class($fixture));

        $this->assertTrue($result['ok']);
        $this->assertSame('broadcastQueue()', $result['mechanism']);
    }

    /**
     * Mirrors the precedent already established by
     * `TransactionFailureThresholdExceeded`: an explicit `public $queue =
     * null;` (or entirely unset) is acceptable ONLY because `viaQueues()`
     * covers every channel `via()` emits.
     */
    public function test_detector_accepts_null_queue_property_when_via_queues_covers(): void
    {
        $fixture = new class implements ShouldQueue
        {
            use \Illuminate\Bus\Queueable;

            public $queue = null;

            public function via($notifiable): array
            {
                return ['mail', 'database'];
            }

            public function viaQueues(): array
            {
                return ['mail' => 'notifications', 'database' => 'notifications'];
            }
        };

        $result = self::explicitQueueAssignment(get_class($fixture));

        $this->assertTrue($result['ok'], 'null $queue with covering viaQueues() must be accepted.');
    }

    public function test_detector_rejects_null_queue_property_without_via_queues_coverage(): void
    {
        $fixture = new class implements ShouldQueue
        {
            use \Illuminate\Bus\Queueable;

            public $queue = null;

            public function handle(): void {}
        };

        $result = self::explicitQueueAssignment(get_class($fixture));

        $this->assertFalse($result['ok'], 'null $queue with no covering viaQueues() must be rejected.');
    }

    public function test_detector_rejects_via_queues_that_does_not_cover_every_channel(): void
    {
        $fixture = new class implements ShouldQueue
        {
            use \Illuminate\Bus\Queueable;

            public function via($notifiable): array
            {
                $channels = ['database'];
                $channels[] = 'webhook';

                return $channels;
            }

            public function viaQueues(): array
            {
                // Only covers 'database' — 'webhook' is left unmapped.
                return ['database' => 'notifications'];
            }
        };

        $result = self::explicitQueueAssignment(get_class($fixture));

        $this->assertFalse($result['ok'], 'Partial viaQueues() coverage must be rejected.');
    }

    // ------------------------------------------------------------------
    // Required-coverage assertion: the 9 approved-mapping classes.
    // ------------------------------------------------------------------

    public function test_all_nine_required_classes_have_explicit_queue_assignment(): void
    {
        $this->assertCount(
            9,
            self::REQUIRED_MECHANISM_MAP,
            'Expected exactly the 9 classes from T046a\'s approved mapping.'
        );

        foreach (self::REQUIRED_MECHANISM_MAP as $class => $expectedMechanism) {
            $this->assertTrue(class_exists($class), "Expected class {$class} to exist.");

            $result = self::explicitQueueAssignment($class);

            $this->assertTrue($result['ok'], "{$class} lacks an explicit queue assignment mechanism.");
            $this->assertSame(
                $expectedMechanism,
                $result['mechanism'],
                "{$class} used mechanism '{$result['mechanism']}', expected '{$expectedMechanism}'."
            );
        }
    }

    // ------------------------------------------------------------------
    // Blanket sweep over real app/ files.
    // ------------------------------------------------------------------

    public function test_no_should_queue_or_should_broadcast_class_lacks_explicit_queue_assignment(): void
    {
        $appPath = dirname(__DIR__, 2).'/app';

        $targets = [
            ['dir' => $appPath.'/Jobs', 'namespace' => 'App\\Jobs', 'interface' => ShouldQueue::class],
            ['dir' => $appPath.'/Notifications', 'namespace' => 'App\\Notifications', 'interface' => ShouldQueue::class],
            ['dir' => $appPath.'/Events', 'namespace' => 'App\\Events', 'interface' => ShouldBroadcast::class],
        ];

        $violations = [];
        $discoveredRequired = [];

        foreach ($targets as $target) {
            if (! is_dir($target['dir'])) {
                continue;
            }

            $finder = Finder::create()->files()->in($target['dir'])->name('*.php');

            foreach ($finder as $file) {
                $relative = substr($file->getRelativePathname(), 0, -4); // strip '.php'
                $class = $target['namespace'].'\\'.str_replace('/', '\\', $relative);

                if (! class_exists($class)) {
                    continue;
                }

                $ref = new ReflectionClass($class);
                if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait()) {
                    continue;
                }
                if (! $ref->implementsInterface($target['interface'])) {
                    continue;
                }

                if (array_key_exists($class, self::REQUIRED_MECHANISM_MAP)) {
                    $discoveredRequired[$class] = true;
                }

                if (in_array($class, self::CALL_SITE_QUEUE_ALLOWLIST, true)) {
                    continue;
                }

                $result = self::explicitQueueAssignment($class);
                if (! $result['ok']) {
                    $violations[] = $class;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Found ShouldQueue/ShouldBroadcast classes under app/Jobs, app/Notifications, or app/Events with no '
                ."explicit queue assignment (no supervisor consumes Redis's default queue, so these would "
                .'accumulate unprocessed indefinitely): '.implode(', ', $violations)
        );

        foreach (array_keys(self::REQUIRED_MECHANISM_MAP) as $required) {
            $this->assertArrayHasKey(
                $required,
                $discoveredRequired,
                "Expected the app/ sweep to discover required class {$required}."
            );
        }
    }
}
