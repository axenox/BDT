<?php
namespace axenox\BDT\Behat\DatabaseFormatter;

use axenox\BDT\Behat\Common\Traits\AuthenticatorTimeStampingTrait;
use Behat\Behat\EventDispatcher\ServiceContainer\EventDispatcherExtension;
use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use exface\Core\CommonLogic\Workbench;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Behaviors\TimeStampingBehavior;
use exface\Core\Interfaces\Model\BehaviorInterface;

class DatabaseFormatterExtension implements Extension
{
    use AuthenticatorTimeStampingTrait;
    /** Max boot attempts before giving up on resolving the CLI identity. */
    private const IDENTITY_BOOT_MAX_ATTEMPTS = 10;
    /** Upper bound (ms) of the random pre-boot stagger that desynchronizes parallel workers. */
    private const IDENTITY_BOOT_STAGGER_MAX_MS = 500;
    /** Per-attempt linear backoff base (ms); multiplied by the attempt number. */
    private const IDENTITY_BOOT_BACKOFF_BASE_MS = 100;
    /** Random jitter (ms) added on top of the backoff so retrying workers do not realign. */
    private const IDENTITY_BOOT_BACKOFF_JITTER_MS = 100;
    /** The object whose single shared row every worker's CLI identity resolution writes to. */
    private const AUTHENTICATOR_OBJECT_ALIAS = 'exface.Core.USER_AUTHENTICATOR';
    
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
        // Boot + CLI-identity resolution run under a bounded retry. In a PARALLEL run (identified by an
        // injected run_uid) the identity resolution additionally runs with the authenticator's
        // TimeStampingBehavior disabled in THIS process - see the method docblock.
        $workbench = $this->bootWorkbenchWithIdentityRetry();
        // $container is a Symfony DI container: https://symfony.com/doc/current/components/dependency_injection.html
        $container->set('database_formatter.workbench', $workbench);
    }

    /*
     * Boots the formatter workbench and eagerly resolves the ambient CLI identity, returning the ready
     * workbench.
     *
     * WHY THIS EXISTS: on boot the workbench resolves the CLI process identity (the OS user, e.g.
     * "wampuser") via the default CLI authenticator, which updates last_authenticated_on on a SINGLE
     * shared USER_AUTHENTICATOR row. That row is guarded by TimeStampingBehavior optimistic locking, so
     * when several parallel workers - all running as the same OS user - boot at the same instant, they
     * collide on that row and all but one die with a "changed in the meantime" conflict.
     *
     * WHY THE BEHAVIOR IS DISABLED FOR PARALLEL WORKERS: the optimistic-lock check that raises the
     * conflict runs inside the process performing the write, i.e. in THIS worker. Disabling the behavior
     * on the worker's own model instance therefore removes the conflict at its source, without touching
     * the stored model and without affecting any other process - the web server keeps its full locking.
     * Losing lost-update protection on the field in question is harmless: it is a last-login timestamp,
     * so "last writer wins" is exactly the semantics we want. The behavior is re-enabled immediately
     * after the identity is resolved, so the rest of the worker's run (which writes real test data) has
     * its normal guarantees back.
     *
     * WHY PARALLEL ONLY: a single interactive run has no herd to collide with, so it has no reason to
     * give up a safety net. The disable is therefore bound to attach-mode (an injected run_uid).
     *
     * WHY THE RETRY REMAINS: booting the workbench may itself touch the identity row before we get a
     * chance to disable anything. The retry stays as the backstop for that window - and it is what keeps
     * the single-process path working unchanged.
     *
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
                // inside this retry, instead of lazily later on an unwrapped path. The guard removes the
                // usual cause of the conflict; the surrounding retry stays as a backstop for whatever the
                // guard cannot reach.
                self::withoutAuthenticatorTimeStamping(
                    $workbench,
                    fn() => $workbench->getSecurity()->getAuthenticatedToken()
                );
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