<?php

namespace Leantime\Domain\Setting\Services;

use Leantime\Core\Configuration\Environment;
use Symfony\Component\Process\Process;

class RepoUpdater
{
    private string $appRoot;

    private string $lockFile;

    private string $phpBinary;

    public function __construct(
        private Environment $config
    ) {
        $this->appRoot = APP_ROOT;
        $this->lockFile = $this->appRoot.'/storage/framework/repo-updater.lock';
        $this->phpBinary = is_file('/opt/alt/php83/usr/bin/php') ? '/opt/alt/php83/usr/bin/php' : PHP_BINARY;
    }

    public function listVersions(int $limit = 30): array
    {
        $result = $this->runCommand(['git', 'tag', '--sort=-v:refname']);
        if (! $result['ok']) {
            return [];
        }

        $versions = array_filter(array_map('trim', explode(PHP_EOL, $result['output'])));

        return array_slice(array_values($versions), 0, $limit);
    }

    public function getCurrentRef(): string
    {
        $result = $this->runCommand(['git', 'describe', '--tags', '--always']);

        return $result['ok'] ? trim($result['output']) : '';
    }

    public function updateToVersion(string $version): array
    {
        $version = trim($version);
        if (! preg_match('/^[a-zA-Z0-9._\\/-]+$/', $version)) {
            return ['ok' => false, 'message' => 'Invalid version value.', 'log' => ''];
        }

        $tagCheck = $this->runCommand(['git', 'show-ref', '--verify', '--quiet', 'refs/tags/'.$version]);
        if (! $tagCheck['ok']) {
            return ['ok' => false, 'message' => 'Selected version tag was not found.', 'log' => $tagCheck['output']];
        }

        if (! is_dir(dirname($this->lockFile))) {
            mkdir(dirname($this->lockFile), 0775, true);
        }

        $lockHandle = fopen($this->lockFile, 'c+');
        if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            return ['ok' => false, 'message' => 'Another update is currently in progress.', 'log' => ''];
        }

        try {
            $status = $this->runCommand(['git', 'status', '--porcelain', '--untracked-files=no']);
            if (! $status['ok']) {
                return ['ok' => false, 'message' => 'Could not verify repository state.', 'log' => $status['output']];
            }
            if (trim($status['output']) !== '') {
                return ['ok' => false, 'message' => 'Repository has local tracked changes. Update aborted.', 'log' => $status['output']];
            }

            $commands = [
                ['git', 'fetch', '--tags', 'origin'],
                ['git', 'checkout', '--force', 'tags/'.$version],
                [$this->phpBinary, 'composer.phar', 'install', '--no-dev', '--prefer-dist', '-o', '--ignore-platform-reqs'],
                [$this->phpBinary, 'bin/leantime', 'cache:clearAll'],
            ];

            $combinedLog = '';
            foreach ($commands as $command) {
                $run = $this->runCommand($command);
                $combinedLog .= '$ '.implode(' ', $command).PHP_EOL.$run['output'].PHP_EOL;

                if (! $run['ok']) {
                    return ['ok' => false, 'message' => 'Update failed while executing: '.implode(' ', $command), 'log' => $combinedLog];
                }
            }

            return ['ok' => true, 'message' => 'Repository updated successfully to '.$version.'.', 'log' => $combinedLog];
        } finally {
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }
    }

    private function runCommand(array $command): array
    {
        $process = new Process($command, $this->appRoot);
        $process->setTimeout(1800);
        $process->run();

        $output = trim($process->getOutput().PHP_EOL.$process->getErrorOutput());
        return [
            'ok' => $process->isSuccessful(),
            'output' => $output,
        ];
    }
}
