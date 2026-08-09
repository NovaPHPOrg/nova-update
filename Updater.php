<?php

declare(strict_types=1);

namespace nova\plugin\update;

use function nova\framework\config;

use nova\framework\core\Context;
use nova\framework\core\File;
use nova\plugin\http\HttpClient;
use nova\plugin\http\HttpException;
use RuntimeException;
use ZipArchive;

/**
 * 检查 GitHub Release → 下载标准 zip → 覆盖安装目录。
 * 保留 config.php / runtime / uploads。
 */
class Updater
{
    private const PRESERVE = ['config.php', 'runtime', 'uploads'];
    private const CACHE_KEY = 'update/check';
    private const CACHE_TTL = 86400;

    public function version(): string
    {
        return config('version') ?? '0.0.0';
    }

    /**
     * @return array{current: string, latest: string, updatable: bool, changelog: string, download_url: string}|null
     */
    public function cached(): ?array
    {
        $data = Context::instance()->cache->get(self::CACHE_KEY);
        if (!is_array($data) || empty($data['latest'])) {
            return null;
        }

        return $this->result($data);
    }

    /**
     * @return array{current: string, latest: string, updatable: bool, changelog: string, download_url: string}
     */
    public function check(): array
    {
        $cfg = Context::instance()->config();
        $repo = (string)$cfg->get('update.repo', '');
        $name = (string)$cfg->get('update.name', 'app');
        $assetTpl = (string)$cfg->get('update.asset', '{name}-{version}.zip');
        $token = (string)$cfg->get('update.token', '');

        if ($repo === '' || !str_contains($repo, '/')) {
            throw new RuntimeException('请配置 update.repo（owner/repo）');
        }

        $release = $this->github($repo, '/releases/latest', $token);
        $latest = ltrim(trim((string)($release['tag_name'] ?? '')), 'vV');
        if ($latest === '') {
            throw new RuntimeException('无法解析远端版本');
        }

        $assetName = str_replace(['{name}', '{version}'], [$name, $latest], $assetTpl);
        $url = '';
        foreach ($release['assets'] ?? [] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? '') === $assetName) {
                $url = (string)($asset['browser_download_url'] ?? '');
                break;
            }
        }
        if ($url === '') {
            throw new RuntimeException('未找到标准包 ' . $assetName . '（不支持 Docker/Windows 包）');
        }

        $result = $this->result([
            'latest' => $latest,
            'changelog' => (string)($release['body'] ?? ''),
            'download_url' => $url,
        ]);
        Context::instance()->cache->set(self::CACHE_KEY, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * @return array{from: string, to: string}
     */
    public function apply(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('缺少 zip 扩展');
        }
        if (!is_writable(ROOT_PATH) || !is_writable(RUNTIME_PATH)) {
            throw new RuntimeException('应用目录或 runtime 不可写');
        }

        $info = $this->check();
        if (!$info['updatable']) {
            throw new RuntimeException('已是最新版本 ' . $info['current']);
        }

        $work = RUNTIME_PATH . DS . 'update';
        File::mkDir($work);
        $lock = $work . DS . 'lock';
        if (is_file($lock) && (time() - (int)filemtime($lock)) < 1800) {
            throw new RuntimeException('更新进行中，请稍后再试');
        }
        File::write($lock, (string)time());

        $zip = $work . DS . 'package.zip';
        $extract = $work . DS . 'extract';

        try {
            $this->download($info['download_url'], $zip);

            if (is_dir($extract)) {
                File::del($extract);
            }
            File::mkDir($extract);

            $za = new ZipArchive();
            if ($za->open($zip) !== true || !$za->extractTo($extract)) {
                throw new RuntimeException('解压失败');
            }
            $za->close();

            $root = $this->packageRoot($extract);
            $this->overlay($root);
            $this->mergeConfig($root, $info['latest']);

            File::del($zip);
            File::del($extract);

            return ['from' => $info['current'], 'to' => $info['latest']];
        } finally {
            File::del($lock);
        }
    }

    /**
     * @param  array<string, mixed>                                                                             $data
     * @return array{current: string, latest: string, updatable: bool, changelog: string, download_url: string}
     */
    private function result(array $data): array
    {
        $current = $this->version();
        $latest = (string)($data['latest'] ?? '');

        return [
            'current' => $current,
            'latest' => $latest,
            'updatable' => $latest !== '' && version_compare($latest, $current, '>'),
            'changelog' => (string)($data['changelog'] ?? ''),
            'download_url' => (string)($data['download_url'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function github(string $repo, string $path, string $token): array
    {
        $client = HttpClient::init('https://api.github.com')
            ->timeout(30)
            ->setHeaders([
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'nova-update',
            ]);
        if ($token !== '') {
            $client->setHeader('Authorization', 'Bearer ' . $token);
        }

        try {
            $response = $client->get()->send('/repos/' . $repo . $path);
        } catch (HttpException $e) {
            throw new RuntimeException('请求 GitHub 失败：' . $e->getMessage(), 0, $e);
        }

        $code = $response?->getHttpCode() ?? 0;
        if ($code === 403) {
            throw new RuntimeException('GitHub API 限流，请配置 update.token');
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('GitHub API HTTP ' . $code);
        }

        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data)) {
            throw new RuntimeException('GitHub 响应无效');
        }

        return $data;
    }

    private function download(string $url, string $dest): void
    {
        @unlink($dest);
        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            throw new RuntimeException('无法创建临时文件');
        }

        $ok = false;
        try {
            HttpClient::init('')
                ->timeout(600)
                ->setHeaders([
                    'User-Agent' => 'nova-update',
                    'Accept' => 'application/octet-stream',
                ])
                ->get()
                ->stream($url, [], static function (string $chunk) use ($fp): void {
                    if (fwrite($fp, $chunk) === false) {
                        throw new RuntimeException('写入失败');
                    }
                });
            $ok = filesize($dest) > 0;
        } catch (HttpException $e) {
            throw new RuntimeException('下载失败：' . $e->getMessage(), 0, $e);
        } finally {
            fclose($fp);
            if (!$ok) {
                @unlink($dest);
            }
        }

        if (!$ok) {
            throw new RuntimeException('下载失败：文件为空');
        }
    }

    private function packageRoot(string $extract): string
    {
        $isRoot = static fn (string $dir): bool => is_dir($dir . DS . 'public') || is_file($dir . DS . 'index.php');

        if ($isRoot($extract)) {
            return $extract;
        }

        $entries = array_values(array_filter(
            scandir($extract) ?: [],
            static fn (string $n): bool => $n !== '.' && $n !== '..'
        ));
        if (count($entries) === 1) {
            $only = $extract . DS . $entries[0];
            if (is_dir($only) && $isRoot($only)) {
                return $only;
            }
        }

        throw new RuntimeException('更新包结构无效');
    }

    private function overlay(string $src): void
    {
        $src = rtrim($src, '/\\');
        $dest = rtrim(ROOT_PATH, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($src))), '/');
            if ($rel === '') {
                continue;
            }
            foreach (self::PRESERVE as $name) {
                if ($rel === $name || str_starts_with($rel, $name . '/')) {
                    continue 2;
                }
            }

            $target = $dest . DS . str_replace('/', DS, $rel);
            if ($item->isDir()) {
                File::mkDir($target);
                continue;
            }
            if (!File::copyFile($item->getPathname(), $target)) {
                throw new RuntimeException('覆盖失败：' . $rel);
            }
        }
    }

    private function mergeConfig(string $packageRoot, string $latest): void
    {
        $file = $packageRoot . DS . 'example.config.php';
        if (is_file($file)) {
            $example = include $file;
            if (is_array($example)) {
                $this->fillMissing($example, '');
            }
        }
        config('version', $latest);
    }

    /**
     * @param array<string|int, mixed> $example
     */
    private function fillMissing(array $example, string $prefix): void
    {
        foreach ($example as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            $current = config($path);
            if ($current === null) {
                config($path, $value);
            } elseif (is_array($value) && is_array($current)) {
                $this->fillMissing($value, $path);
            }
        }
    }
}
