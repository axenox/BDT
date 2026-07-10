<?php
namespace axenox\BDT\Behat\DatabaseFormatter;

use Behat\Behat\EventDispatcher\ServiceContainer\EventDispatcherExtension;
use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use exface\Core\CommonLogic\Workbench;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use exface\Core\Exceptions\RuntimeException;

class DatabaseFormatterExtension implements Extension
{
    /** Max boot attempts before giving up on resolving the CLI identity. */
    private const IDENTITY_BOOT_MAX_ATTEMPTS = 10;
    /** Upper bound (ms) of the random pre-boot stagger that desynchronizes parallel workers. */
    private const IDENTITY_BOOT_STAGGER_MAX_MS = 500;
    /** Per-attempt linear backoff base (ms); multiplied by the attempt number. */
    private const IDENTITY_BOOT_BACKOFF_BASE_MS = 100;
    /** Random jitter (ms) added on top of the backoff so retrying workers do not realign. */
    private const IDENTITY_BOOT_BACKOFF_JITTER_MS = 100;
    
    public function getConfigKey(): string
    {
        return 'database_formatter';
    }

    public function initialize(ExtensionManager $extensionManager) {}

    public function configure(ArrayNodeDefinition $builder) {
        $builder
            ->children()
            ->arrayNode('chrome')
            ->children()
            ->scalarNode('port')->defaultNull()->end()
            ->scalarNode('executable')->defaultNull()->end()
            ->scalarNode('user_data_dir')->defaultNull()->end()
            ->end()
            ->end()
            ->scalarNode('run_uid')->defaultNull()->end()
            ->scalarNode('lane_id')->defaultNull()->end()
            ->end();
    }

    public function load(ContainerBuilder $container, array $config)
    {
        // Register the formatter as a service
        $definition = new Definition(DatabaseFormatter::class, [
            new Reference('database_formatter.workbench'),
            new Reference('screenshot.provider'),
            new Reference(EventDispatcherExtension::DISPATCHER_ID),
            new Reference('suite.registry'),
            $config['chrome'] ?? [],
            $config['run_uid'] ?? null,
            $config['lane_id'] ?? null
        ]);

        $definition->addTag('output.formatter');

        $container->setDefinition('database_formatter.formatter', $definition);
        // Start a new workbench for the test run formatter. Disable monitor to avoid flooding the DB
        // with all the errors it registers - these are test results, not system-monitoring events.
        // Boot + CLI-identity resolution run under a bounded retry: parallel workers booting at the
        // same instant otherwise collide on the shared authenticator row's optimistic lock. See the
        // method docblock for the full rationale.
        $workbench = $this->bootWorkbenchWithIdentityRetry();
        // $container is a Symfony DI container: https://symfony.com/doc/current/components/dependency_injection.html
        $container->set('database_formatter.workbench', $workbench);
    }
    
    /**
     * Boots the formatter workbench and eagerly resolves the ambient CLI identity under a bounded,
     * jittered retry, returning the ready workbench.
     *
     * WHY THIS EXISTS: on boot the workbench resolves the CLI process identity (the OS user, e.g.
     * "wampuser") via the default CLI authenticator, which updates last_authenticated_on on a SINGLE
     * shared USER_AUTHENTICATOR row. That row is guarded by TimeStampingBehavior optimistic locking,
     * so when several parallel workers - all running as the same OS user - boot at the same instant,
     * they collide on that row and all but one die with a "changed in the meantime" conflict. The
     * contended field is only a last-login timestamp, so retrying is safe: each fresh attempt re-reads
     * the row's current version and one racer wins per round until all have written. Resolving the
     * identity HERE, at the earliest per-worker point, absorbs the collision before any later code
     * touches security on an unwrapped path.
     *
     * WHY RETRY AND NOT A LOCK: the same row is also written by the web-server process on browser
     * logins, which a worker-side lock cannot reach. Retry tolerates the conflict regardless of which
     * process caused it, so it covers a case a lock structurally cannot.
     *
     * NOTE: this is a stopgap for the parallel CLI boot herd; the durable fix is core-side (keep the
     * last-login timestamp out of optimistic locking). See the accompanying core task.
     */
    private function bootWorkbenchWithIdentityRetry(): Workbench
    {
        // Small random stagger so the simultaneous boot of parallel workers does not hit the shared
        // identity row at the exact same instant on the very first attempt.
        usleep(random_int(0, self::IDENTITY_BOOT_STAGGER_MAX_MS) * 1000);

        $lastConflict = null;
        for ($attempt = 1; $attempt <= self::IDENTITY_BOOT_MAX_ATTEMPTS; $attempt++) {
            $workbench = null;
            try {
                $workbench = Workbench::startNewInstance(['MONITOR.ENABLED' => false]);
                // Force the ambient CLI identity resolution (and its shared-row write) to happen now,
                // inside this retry, instead of lazily later on an unwrapped path.
                $workbench->getSecurity()->getAuthenticatedToken();
                return $workbench;
            } catch (\Throwable $e) {
                // Discard the half-booted instance so a failed attempt never leaks a workbench.
                if ($workbench !== null) {
                    try { $workbench->stop(); } catch (\Throwable $ignored) {}
                }
                // Only a concurrent-write conflict on the shared identity row is transient here. Any
                // other boot failure is real and must surface immediately, never be masked by retries.
                if (! $this->isConcurrentWriteConflict($e)) {
                    throw $e;
                }
                $lastConflict = $e;
                if ($attempt === self::IDENTITY_BOOT_MAX_ATTEMPTS) {
                    break;
                }
                // Jittered backoff: the re-read on the next attempt is what clears the conflict; the
                // jitter only keeps the retrying workers from realigning on the same instant again.
                $backoffMs = self::IDENTITY_BOOT_BACKOFF_BASE_MS * $attempt
                    + random_int(0, self::IDENTITY_BOOT_BACKOFF_JITTER_MS);
                usleep($backoffMs * 1000);
            }
        }
        throw new RuntimeException(
            'Could not resolve the CLI identity for the BDT formatter workbench after '
            . self::IDENTITY_BOOT_MAX_ATTEMPTS . ' attempts due to repeated concurrent-write conflicts '
            . 'on the shared authenticator row',
            null,
            $lastConflict
        );
    }

    /**
     * Returns TRUE if the throwable is a TimeStampingBehavior optimistic-locking conflict ("changed
     * in the meantime"), the only transient failure the boot retry should tolerate.
     *
     * WHY MESSAGE-BASED (AND ITS LIMIT): the concrete concurrent-write exception class is not
     * confirmed here, so we match on the stable part of the conflict message. This is deliberately
     * narrow so unrelated boot errors are never retried. It should be replaced with an instanceof
     * check against the core concurrent-write exception type once that type is confirmed - see the
     * core task.
     */
    private function isConcurrentWriteConflict(\Throwable $e): bool
    {
        return mb_stripos($e->getMessage(), 'in the meantime') !== false;
    }
    
    public function process(ContainerBuilder $container) {}
}