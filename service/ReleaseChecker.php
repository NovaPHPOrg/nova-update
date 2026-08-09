<?php

declare(strict_types=1);

namespace nova\plugin\update\service;

use nova\framework\core\File;
use nova\plugin\http\HttpClient;
use nova\plugin\http\HttpException;
use RuntimeException;

use function nova\framework\config;

/**
 * 从 GitHub Releases 检查可用更新，结果缓存到 runtime/update/check.json。
 */
class ReleaseChecker
{
    public const CACHE_FILE = 'check.json';

    /**
     * @return array{
     *   current: string,
     *   latest: string,
     *   updatable: bool,
     *   changelog: string,
     *   download_url: string,
     *   size: int,
     *   asset: string,
     *   checked_at: int,
     *   tag: string
     * }
     */
    public function check(bool $force = false): array
    {
        if (!$force) {
            $cached = $this->readCache();
            if ($cached !== null && !$this->cacheExpired($cached)) {
                $cached['current'] = $this->currentVersion();
                $cached['updatable'] = version_compare($cached['latest'], $cached['current'], '>');
                return $cached;
            }
        }

        $cfg = $this->updateConfig();
        $repo = $cfg['repo'];
        if ($repo === '' || !str_contains($repo, '/')) {
            throw new RuntimeException('update.repo 未配置或格式错误（需要 owner/repo）');
        }

        $release = $this->fetchRelease($repo, $cfg['channel'], $cfg['token']);
        $latest = $this->normalizeVersion((string)($release['tag_name'] ?? ''));
        if ($latest === '') {
            throw new RuntimeException('无法解析远端版本号');
        }

        $assetName = str_replace(
            ['{name}', '{version}'],
            [$cfg['name'], $latest],
            $cfg['asset']
        );

        $asset = $this->findAsset($release['assets'] ?? [], $assetName);
        if ($asset === null) {
            throw new RuntimeException('Release 中未找到标准更新包：' . $assetName . '（Docker/Windows 包不支持在线覆盖）');
        }

        $current = $this->currentVersion();
        $result = [
            'current' => $current,
            'latest' => $latest,
            'updatable' => version_compare($latest, $current, '>'),
            'changelog' => (string)($release['body'] ?? ''),
            'download_url' => (string)($asset['browser_download_url'] ?? ''),
            'size' => (int)($asset['size'] ?? 0),
            'asset' => (string)($asset['name'] ?? $assetName),
            'checked_at' => time(),
            'tag' => (string)($release['tag_name'] ?? ('v' . $latest)),
        ];

        if ($result['download_url'] === '') {
            throw new RuntimeException('更新包下载地址为空');
        }

        $this->writeCache($result);
        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readCache(): ?array
    {
        $file = $this->workDir() . DS . self::CACHE_FILE;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function currentVersion(): string
    {
        return $this->normalizeVersion((string)config('version', '0.0.0'));
    }

    /**
     * @return array{repo: string, asset: string, name: string, channel: string, token: string, check_interval: int}
     */
    public function updateConfig(): array
    {
        $raw = config('update');
        if (!is_array($raw)) {
            $raw = [];
        }

        return [
            'repo' => (string)($raw['repo'] ?? ''),
            'asset' => (string)($raw['asset'] ?? '{name}-{version}.zip'),
            'name' => (string)($raw['name'] ?? 'app'),
            'channel' => (string)($raw['channel'] ?? 'stable'),
            'token' => (string)($raw['token'] ?? ''),
            'check_interval' => max(60, (int)($raw['check_interval'] ?? 86400)),
        ];
    }

    public function workDir(): string
    {
        $dir = RUNTIME_PATH . DS . 'update';
        File::mkDir($dir);
        return $dir;
    }

    /**
     * @param array<string, mixed> $cached
     */
    private function cacheExpired(array $cached): bool
    {
        $checkedAt = (int)($cached['checked_at'] ?? 0);
        $interval = $this->updateConfig()['check_interval'];
        return $checkedAt <= 0 || (time() - $checkedAt) >= $interval;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRelease(string $repo, string $channel, string $token): array
    {
        if ($channel === 'stable') {
            try {
                $latest = $this->githubGet($repo, '/releases/latest', $token);
                if (!($latest['prerelease'] ?? false) && !($latest['draft'] ?? false)) {
                    return $latest;
                }
            } catch (RuntimeException) {
                // fall through to list
            }
        }

        $list = $this->githubGet($repo, '/releases', $token);
        if (!is_array($list)) {
            throw new RuntimeException('GitHub Releases 响应格式错误');
        }

        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!empty($item['draft'])) {
                continue;
            }
            if ($channel === 'stable' && !empty($item['prerelease'])) {
                continue;
            }
            return $item;
        }

        throw new RuntimeException('未找到可用的 Release');
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function githubGet(string $repo, string $path, string $token): array
    {
        $client = HttpClient::init('https://api.github.com')
            ->timeout(30)
            ->setHeaders([
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'nova-update',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);

        if ($token !== '') {
            $client->setHeader('Authorization', 'Bearer ' . $token);
        }

        try {
            $response = $client->get()->send('/repos/' . $repo . $path);
        } catch (HttpException $e) {
            throw new RuntimeException('请求 GitHub 失败：' . $e->getMessage(), 0, $e);
        }

        if ($response === null) {
            throw new RuntimeException('请求 GitHub 无响应');
        }

        $code = $response->getHttpCode();
        $body = $response->getBody();
        if ($code === 404) {
            throw new RuntimeException('仓库或 Release 不存在：' . $repo);
        }
        if ($code === 403) {
            throw new RuntimeException('GitHub API 被限流或无权限，可配置 update.token');
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('GitHub API 错误 HTTP ' . $code);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('GitHub API 返回非 JSON');
        }

        return $data;
    }

    /**
     * @param list<mixed> $assets
     * @return array<string, mixed>|null
     */
    private function findAsset(array $assets, string $assetName): ?array
    {
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['name'] ?? '') === $assetName) {
                return $asset;
            }
        }
        return null;
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if (str_starts_with($version, 'v') || str_starts_with($version, 'V')) {
            $version = substr($version, 1);
        }
        return $version;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function writeCache(array $result): void
    {
        $file = $this->workDir() . DS . self::CACHE_FILE;
        File::write($file, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
