<?php

namespace App\Update;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Lets Cyllos (back-office) see and trigger this site's update. It only ever
 * runs fixed commands: git (status) and deploy/self-update.sh (update),
 * as the user PHP-FPM runs under, never anything taken from a request.
 */
class SelfUpdater
{
    private const STALE_RUN_SECONDS = 900;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{commit: ?string, branch: ?string, remoteCommit: ?string, remoteDate: ?string, behind: int, ahead: int, state: string, running: bool, log: string, error: ?string}
     */
    public function status(): array
    {
        $commit = $this->git(['rev-parse', 'HEAD']);
        $branch = $this->git(['rev-parse', '--abbrev-ref', 'HEAD']);
        $fetch = $this->git(['fetch', '--quiet', 'origin'], 30.0);
        $remoteCommit = $branch !== null ? $this->git(['rev-parse', 'origin/' . $branch]) : null;

        $behind = $ahead = 0;
        if ($commit !== null && $remoteCommit !== null) {
            $counts = $this->git(['rev-list', '--left-right', '--count', 'HEAD...origin/' . $branch]);
            if ($counts !== null && preg_match('/^(\d+)\s+(\d+)$/', $counts, $m) === 1) {
                [$ahead, $behind] = [(int) $m[1], (int) $m[2]];
            }
        }

        return [
            'commit' => $commit,
            'branch' => $branch,
            'remoteCommit' => $remoteCommit,
            'remoteDate' => $remoteCommit !== null ? $this->git(['log', '-1', '--format=%cI', $remoteCommit]) : null,
            'behind' => $behind,
            'ahead' => $ahead,
            'state' => $this->readState(),
            'running' => $this->isRunning(),
            'log' => $this->logTail(),
            'error' => $commit === null ? 'Dépôt git illisible (droits ou dépôt absent).' : ($fetch === null ? 'git fetch a échoué : GitHub injoignable ou accès refusé.' : null),
        ];
    }

    /** Starts the update in the background. @return bool false when one is already running */
    public function start(): bool
    {
        if ($this->isRunning()) {
            return false;
        }

        $script = $this->projectDir . '/deploy/self-update.sh';
        // Detached: the script outlives this request (it clears the cache this request runs on).
        $process = Process::fromShellCommandline('nohup bash ' . escapeshellarg($script) . ' >/dev/null 2>&1 &', $this->projectDir, $this->environment());
        $process->run();

        return true;
    }

    private function isRunning(): bool
    {
        $file = $this->projectDir . '/var/self-update.state';
        if (!is_file($file) || trim((string) file_get_contents($file)) !== 'running') {
            return false;
        }

        // A crashed script must not block updates forever.
        return time() - (int) filemtime($file) < self::STALE_RUN_SECONDS;
    }

    /** 'running', 'ok', 'failed' or 'never'. */
    private function readState(): string
    {
        $file = $this->projectDir . '/var/self-update.state';
        $state = is_file($file) ? trim((string) file_get_contents($file)) : '';

        if ($state === 'running' && !$this->isRunning()) {
            return 'failed';
        }

        return \in_array($state, ['running', 'ok', 'failed'], true) ? $state : 'never';
    }

    private function logTail(): string
    {
        $file = $this->projectDir . '/var/log/self-update.log';
        if (!is_file($file)) {
            return '';
        }

        $lines = file($file, \FILE_IGNORE_NEW_LINES) ?: [];

        return implode("\n", \array_slice($lines, -40));
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args, float $timeout = 10.0): ?string
    {
        $process = new Process(['git', ...$args], $this->projectDir, $this->environment(), null, $timeout);
        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * PHP-FPM runs with no HOME: git and composer need it to find their credentials.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $home = \function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? null) : null;

        return $home !== null ? ['HOME' => $home, 'COMPOSER_HOME' => $home . '/.composer', 'GIT_TERMINAL_PROMPT' => '0'] : ['GIT_TERMINAL_PROMPT' => '0'];
    }
}
