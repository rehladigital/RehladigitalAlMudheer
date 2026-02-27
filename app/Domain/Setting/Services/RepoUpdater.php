<?php

namespace Leantime\Domain\Setting\Services;

use Leantime\Core\Configuration\AppSettings;
use Leantime\Core\Configuration\Environment;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;
use ZipArchive;

class RepoUpdater
{
    private const DEFAULT_REPO = 'rehladigital/Al-Mudheer';

    private string $appRoot;

    private string $lockFile;

    private string $phpBinary;

    public function __construct(
        private Environment $config,
        private AppSettings $appSettings
    ) {
        $this->appRoot = APP_ROOT;
        $this->lockFile = $this->appRoot.'/storage/framework/repo-updater.lock';
        $this->phpBinary = is_file('/opt/alt/php83/usr/bin/php') ? '/opt/alt/php83/usr/bin/php' : PHP_BINARY;
    }

    public function canUseGit(): bool
    {
        return $this->runCommand(['git', '--version'])['ok'];
    }

    public function listVersions(int $limit = 30): array
    {
        if (! $this->canUseGit()) {
            return [];
        }

        $result = $this->runCommand(['git', 'tag', '--sort=-v:refname']);
        if (! $result['ok']) {
            return [];
        }

        $versions = array_filter(array_map('trim', explode(PHP_EOL, $result['output'])));

        return array_slice(array_values($versions), 0, $limit);
    }

    public function getCurrentRef(): string
    {
        if (! $this->canUseGit()) {
            return 'v'.$this->appSettings->appVersion;
        }

        $result = $this->runCommand(['git', 'describe', '--tags', '--always']);

        return $result['ok'] ? trim($result['output']) : '';
    }

    public function getVersionStatus(): array
    {
        $current = $this->getCurrentRef();
        $latest = $this->getLatestReleaseTag();

        return [
            'current' => $current,
            'latest' => $latest,
            'hasUpdate' => $this->isNewerVersion($latest, $current),
            'canUseGit' => $this->canUseGit(),
        ];
    }

    public function getLatestReleaseTag(): string
    {
        $repo = $this->getRepositoryName();
        $url = 'https://api.github.com/repos/'.$repo.'/releases/latest';

        $response = $this->fetchJson($url);
        if (! is_array($response) || empty($response['tag_name'])) {
            return '';
        }

        return trim((string) $response['tag_name']);
    }

    public function updateToVersion(string $version): array
    {
        if (! $this->canUseGit()) {
            return ['ok' => false, 'message' => 'Git is not available on this server. Use ZIP package update instead.', 'log' => ''];
        }

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

    public function updateFromUploadedArchive(string $archivePath, string $originalName = ''): array
    {
        if (! is_file($archivePath)) {
            return ['ok' => false, 'message' => 'Uploaded file could not be read.', 'log' => ''];
        }

        $name = strtolower(trim($originalName));
        if ($name !== '' && ! str_ends_with($name, '.zip')) {
            return ['ok' => false, 'message' => 'Please upload a .zip release package.', 'log' => ''];
        }

        if (! is_dir(dirname($this->lockFile))) {
            mkdir(dirname($this->lockFile), 0775, true);
        }

        $lockHandle = fopen($this->lockFile, 'c+');
        if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            return ['ok' => false, 'message' => 'Another update is currently in progress.', 'log' => ''];
        }

        $tmpRoot = $this->appRoot.'/storage/framework/repo-updater-tmp/'.uniqid('pkg_', true);
        $extractRoot = $tmpRoot.'/extract';
        mkdir($extractRoot, 0775, true);

        try {
            $zip = new ZipArchive;
            if ($zip->open($archivePath) !== true) {
                return ['ok' => false, 'message' => 'Could not open ZIP archive.', 'log' => ''];
            }

            if (! $zip->extractTo($extractRoot)) {
                $zip->close();

                return ['ok' => false, 'message' => 'Could not extract ZIP archive.', 'log' => ''];
            }
            $zip->close();

            $sourceRoot = $this->detectSourceRoot($extractRoot);
            if ($sourceRoot === '') {
                return ['ok' => false, 'message' => 'Uploaded package is empty.', 'log' => ''];
            }

            $copyLog = $this->copyPackageIntoApp($sourceRoot);
            if (! $copyLog['ok']) {
                return $copyLog;
            }

            $commands = [
                [$this->phpBinary, 'composer.phar', 'install', '--no-dev', '--prefer-dist', '-o', '--ignore-platform-reqs'],
                [$this->phpBinary, 'bin/leantime', 'cache:clearAll'],
            ];

            $combinedLog = $copyLog['log'];
            foreach ($commands as $command) {
                $run = $this->runCommand($command);
                $combinedLog .= '$ '.implode(' ', $command).PHP_EOL.$run['output'].PHP_EOL;
                if (! $run['ok']) {
                    return ['ok' => false, 'message' => 'Update failed while executing: '.implode(' ', $command), 'log' => $combinedLog];
                }
            }

            return [
                'ok' => true,
                'message' => 'Package update completed. Runtime files such as .env, storage, and userfiles were preserved.',
                'log' => $combinedLog,
            ];
        } finally {
            $this->deleteDirectory($tmpRoot);
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }
    }

    private function copyPackageIntoApp(string $sourceRoot): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $copied = 0;
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $absolute = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(str_replace(str_replace('\\', '/', $sourceRoot), '', $absolute), '/');
            if ($relative === '' || $this->shouldSkipPath($relative)) {
                continue;
            }

            $target = $this->appRoot.'/'.$relative;
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0775, true);
                }
                continue;
            }

            $targetDir = dirname($target);
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }

            if (! @copy($absolute, $target)) {
                return ['ok' => false, 'message' => 'Failed to copy update file: '.$relative, 'log' => ''];
            }
            $copied++;
        }

        return ['ok' => true, 'message' => '', 'log' => 'Copied files: '.$copied.PHP_EOL];
    }

    private function shouldSkipPath(string $relativePath): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        $blockedPrefixes = [
            '.git/',
            'storage/',
            'userfiles/',
            'node_modules/',
        ];

        foreach ($blockedPrefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return $normalized === 'config/.env';
    }

    private function detectSourceRoot(string $extractRoot): string
    {
        $entries = array_values(array_filter(scandir($extractRoot) ?: [], fn ($name) => $name !== '.' && $name !== '..'));
        if (count($entries) === 1 && is_dir($extractRoot.'/'.$entries[0])) {
            return $extractRoot.'/'.$entries[0];
        }

        return $extractRoot;
    }

    private function deleteDirectory(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private function getRepositoryName(): string
    {
        $configured = trim((string) ($this->config->repoUpdaterGithubRepo ?? ''));

        return $configured !== '' ? $configured : self::DEFAULT_REPO;
    }

    private function fetchJson(string $url): mixed
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'header' => "User-Agent: AlMudheer-Updater\r\nAccept: application/vnd.github+json\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        return json_decode($raw, true);
    }

    private function isNewerVersion(string $latest, string $current): bool
    {
        if ($latest === '' || $current === '') {
            return false;
        }

        $normalizedLatest = ltrim($latest, "vV");
        $normalizedCurrent = ltrim($current, "vV");

        if (preg_match('/^\d+\.\d+\.\d+$/', $normalizedLatest) && preg_match('/^\d+\.\d+\.\d+$/', $normalizedCurrent)) {
            return version_compare($normalizedLatest, $normalizedCurrent, '>');
        }

        return $latest !== $current;
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
