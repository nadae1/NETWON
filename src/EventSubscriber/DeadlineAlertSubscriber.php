<?php

namespace App\EventSubscriber;

use App\Service\DeadlineAlertService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class DeadlineAlertSubscriber implements EventSubscriberInterface
{
    private const COOLDOWN_SECONDS = 300;

    public function __construct(
        private DeadlineAlertService $deadlineAlertService,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onKernelTerminate',
        ];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $lockPath = $this->projectDir . '/var/locks/deadline_alerts.lock';
        $statePath = $this->projectDir . '/var/locks/deadline_alerts.last_run';

        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            $this->logger->warning('Deadline alert automation skipped: unable to create lock directory.');
            return;
        }

        $handle = @fopen($lockPath, 'c+');
        if (!$handle) {
            $this->logger->warning('Deadline alert automation skipped: unable to open lock file.');
            return;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }

            $lastRun = $this->readLastRun($statePath);
            if ($lastRun !== null && (time() - $lastRun) < self::COOLDOWN_SECONDS) {
                return;
            }

            $created = $this->deadlineAlertService->checkAndNotify();
            $this->writeLastRun($statePath, time());

            $this->logger->info(sprintf(
                'Automatic deadline alert run completed with %d notification(s).',
                $created
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Automatic deadline alert run failed: ' . $e->getMessage());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readLastRun(string $statePath): ?int
    {
        if (!is_file($statePath)) {
            return null;
        }

        $content = trim((string) @file_get_contents($statePath));
        if ($content === '' || !ctype_digit($content)) {
            return null;
        }

        return (int) $content;
    }

    private function writeLastRun(string $statePath, int $timestamp): void
    {
        @file_put_contents($statePath, (string) $timestamp, LOCK_EX);
    }
}