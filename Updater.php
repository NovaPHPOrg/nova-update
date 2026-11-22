<?php

declare(strict_types=1);

namespace nova\plugin\update;

use function nova\framework\config;

use nova\framework\core\Context;
use nova\framework\core\File;
use nova\plugin\http\HttpClient;
use nova\plugin\http\HttpException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * 检查 GitHub Release → 下载标准 zip → 覆盖安装目录。
 * 保留 config.php、config/（节点拆分配置）、runtime、uploads。
 */
class Updater
{
    private const PRESERVE = ['config.php', 'config', 'runtime', 'uploads'];
    /** GitHub releases/latest 请求缓存（秒），走 HttpClient 自带缓存 */
    private const HTTP_CACHE_TTL = 86400;
    /** 下载进度：至少增长 1% 或 256KB 才推送一次 */
    private const PROGRESS_MIN_BYTES = 262144;
    /** 单个镜像下载超时（秒） */
    private const MIRROR_ATTEMPT_TIMEOUT = 120;
    /** 上次成功的 release 镜像前缀，存在 runtime/update/ */
    private const PREFERRED_MIRROR_FILE = 'preferred_mirror';

    public function version(): string
    {
        return config('version') ?? '0.0.0';
    }

    /**
     * @return array{current: string, latest: string, updatable: bool, changelog: string, download_url: string, size: int}
     */
    public function check(): array
    {
        $cfg = Context::instance()->config();
        $repo = (string)$cfg->get('update.repo', '');
        $name = (string)$cfg->get('update.name', 'app');
        $assetTpl = (string)$cfg->get('update.asset', '{name}-{version}.zip');

        if ($repo === '' || !str_contains($repo, '/')) {
            throw new RuntimeException('请配置 update.repo（owner/repo）');
        }

        $release = $this->github($repo, '/releases/latest');
        $latest = ltrim(trim((string)($release['tag_name'] ?? '')), 'vV');
        if ($latest === '') {
            throw new RuntimeException('无法解析远端版本');
        }

        $assetName = str_replace(['{name}', '{version}'], [$name, $latest], $assetTpl);
        $url = '';
        $size = 0;
        foreach ($release['assets'] ?? [] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? '') === $assetName) {
                $url = (string)($asset['browser_download_url'] ?? '');
                $size = (int)($asset['size'] ?? 0);
                break;
            }
        }
        if ($url === '') {
            throw new RuntimeException('未找到标准包 ' . $assetName . '（不支持 Docker/Windows 包）');
        }

        return $this->result([
            'latest' => $latest,
            'changelog' => (string)($release['body'] ?? ''),
            'download_url' => $url,
            'size' => $size,
        ]);
    }

    /**
     * @param  (callable(array{stage: string, text: string, percent: ?int}): void)|null $onProgress
     * @return array{from: string, to: string}
     */
    public function apply(?callable $onProgress = null): array
    {
        $progress = static function (string $stage, string $text, ?int $percent = null) use ($onProgress): void {
            if ($onProgress === null) {
                return;
            }
            $onProgress(['stage' => $stage, 'text' => $text, 'percent' => $percent]);
        };

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('缺少 zip 扩展');
        }
        if (!is_writable(ROOT_PATH) || !is_writable(RUNTIME_PATH)) {
            throw new RuntimeException('应用目录或 runtime 不可写');
        }

        $progress('checking', '正在检查更新…');
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
            $progress('downloading', '正在下载更新包…', 0);
            $this->downloadViaMirrors($info['download_url'], $zip, (int)$info['size'], $onProgress);

            $progress('extracting', '正在解压…');
            if (is_dir($extract)) {
                File::del($extract);
            }
            File::mkDir($extract);

            $za = new ZipArchive();
            if ($za->open($zip) !== true || !$za->extractTo($extract)) {
                throw new RuntimeException('解压失败');
            }
            $za->close();

            $progress('installing', '正在安装…');
            $root = $this->packageRoot($extract);
            $this->overlay($root);
            $this->mergeConfig($root, $info['latest']);

            File::del($zip);
            File::del($extract);

            $progress('done', '已更新到 ' . $info['latest'], 100);

            return ['from' => $info['current'], 'to' => $info['latest']];
        } finally {
            File::del($lock);
        }
    }

    /**
     * @param  array<string, mixed>                                                                                        $data
     * @return array{current: string, latest: string, updatable: bool, changelog: string, download_url: string, size: int}
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
            'size' => (int)($data['size'] ?? 0),
        ];
    }

    /**
     * 经 api.ankio.net/gh 代理查 Release，无需用户配置 GitHub token。
     * @see https://api.ankio.net/docs/gh
     *
     * @return array<string, mixed>
     */
    private function github(string $repo, string $path): array
    {
        $client = HttpClient::init('https://api.ankio.net')
            ->timeout(30)
            ->cache(self::HTTP_CACHE_TTL)
            ->setHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'nova-update',
            ]);

        try {
            $response = $client->get()->send('/gh/repos/' . $repo . $path);
        } catch (HttpException $e) {
            throw new RuntimeException('请求更新接口失败：' . $e->getMessage(), 0, $e);
        }

        $code = $response?->getHttpCode() ?? 0;
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('更新接口 HTTP ' . $code);
        }

        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data)) {
            throw new RuntimeException('更新接口响应无效');
        }

        return $data;
    }

    /**
     * 经 /gh/mirrors 拉镜像队列：优先上次成功前缀 → 其余镜像 → 官方地址。
     * 失败的镜像丢到队尾逻辑里「跳过继续下一个」；成功则记下前缀。
     * @see https://api.ankio.net/docs/gh
     *
     * @param (callable(array{stage: string, text: string, percent: ?int}): void)|null $onProgress
     */
    private function downloadViaMirrors(string $githubUrl, string $dest, int $expectedSize, ?callable $onProgress): void
    {
        $queue = $this->buildMirrorQueue($githubUrl);
        $errors = [];
        $total = count($queue);

        foreach ($queue as $i => $url) {
            $n = $i + 1;
            if ($onProgress !== null) {
                $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
                $onProgress([
                    'stage' => 'downloading',
                    'text' => "尝试镜像 {$n}/{$total}：{$host}",
                    'percent' => 0,
                ]);
            }

            try {
                $this->downloadOne($url, $dest, $expectedSize, $onProgress);
                $this->rememberPreferredMirror($url, $githubUrl);
                if ($onProgress !== null) {
                    $onProgress(['stage' => 'downloading', 'text' => '下载完成', 'percent' => 100]);
                }
                return;
            } catch (Throwable $e) {
                File::del($dest);
                $errors[] = ($n) . '. ' . ((string)(parse_url($url, PHP_URL_HOST) ?: $url)) . '：' . $e->getMessage();
            }
        }

        throw new RuntimeException('所有镜像下载失败：' . implode(' | ', $errors));
    }

    /**
     * @return list<string>
     */
    private function buildMirrorQueue(string $githubUrl): array
    {
        $mirrors = $this->fetchMirrorUrls($githubUrl);
        $preferred = $this->loadPreferredMirror();
        $queue = [];

        if ($preferred !== '') {
            foreach ($mirrors as $url) {
                if (str_starts_with($url, $preferred)) {
                    $queue[] = $url;
                }
            }
        }

        foreach ($mirrors as $url) {
            if (!in_array($url, $queue, true)) {
                $queue[] = $url;
            }
        }

        if (!in_array($githubUrl, $queue, true)) {
            $queue[] = $githubUrl;
        }

        return $queue;
    }

    /**
     * @return list<string>
     */
    private function fetchMirrorUrls(string $githubUrl): array
    {
        try {
            $response = HttpClient::init('https://api.ankio.net')
                ->timeout(15)
                ->setHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'nova-update',
                ])
                ->get()
                ->send('/gh/mirrors', ['url' => $githubUrl]);
        } catch (HttpException) {
            return [];
        }

        if (($response?->getHttpCode() ?? 0) < 200 || ($response?->getHttpCode() ?? 0) >= 300) {
            return [];
        }

        $data = json_decode((string)$response->getBody(), true);
        $list = $data['data']['mirrors'] ?? null;
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $url) {
            if (is_string($url) && $url !== '') {
                $out[] = $url;
            }
        }
        return $out;
    }

    private function preferredMirrorPath(): string
    {
        return RUNTIME_PATH . DS . 'update' . DS . self::PREFERRED_MIRROR_FILE;
    }

    private function loadPreferredMirror(): string
    {
        $file = $this->preferredMirrorPath();
        if (!is_file($file)) {
            return '';
        }
        return trim((string)file_get_contents($file));
    }

    private function rememberPreferredMirror(string $workedUrl, string $githubUrl): void
    {
        if ($workedUrl === $githubUrl) {
            return;
        }

        $prefix = '';
        $githubHostPath = 'https://github.com';
        if (str_starts_with($githubUrl, $githubHostPath) && str_ends_with($workedUrl, substr($githubUrl, strlen($githubHostPath)))) {
            $prefix = substr($workedUrl, 0, strlen($workedUrl) - (strlen($githubUrl) - strlen($githubHostPath)));
        }

        if ($prefix === '') {
            return;
        }

        $dir = dirname($this->preferredMirrorPath());
        File::mkDir($dir);
        File::write($this->preferredMirrorPath(), $prefix);
    }

    /**
     * @param (callable(array{stage: string, text: string, percent: ?int}): void)|null $onProgress
     */
    private function downloadOne(string $url, string $dest, int $expectedSize, ?callable $onProgress): void
    {
        File::del($dest);
        File::mkDir(dirname($dest));
        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            throw new RuntimeException('无法创建临时文件');
        }

        $written = 0;
        $lastPercent = -1;
        $lastBytes = 0;
        $ok = false;

        try {
            HttpClient::init('')
                ->timeout(self::MIRROR_ATTEMPT_TIMEOUT)
                ->setHeaders([
                    'User-Agent' => 'nova-update',
                    'Accept' => 'application/octet-stream',
                ])
                ->get()
                ->stream($url, [], static function (string $chunk) use (
                    $fp,
                    $expectedSize,
                    $onProgress,
                    &$written,
                    &$lastPercent,
                    &$lastBytes
                ): void {
                    $n = fwrite($fp, $chunk);
                    if ($n === false) {
                        throw new RuntimeException('写入失败');
                    }
                    $written += $n;

                    if ($onProgress === null) {
                        return;
                    }

                    $percent = null;
                    if ($expectedSize > 0) {
                        $percent = min(99, (int)floor($written * 100 / $expectedSize));
                    }

                    $bytesDelta = $written - $lastBytes;
                    $percentDelta = $percent === null ? 0 : ($percent - $lastPercent);
                    if ($bytesDelta < self::PROGRESS_MIN_BYTES && $percentDelta < 1 && $written !== $expectedSize) {
                        return;
                    }

                    $lastBytes = $written;
                    if ($percent !== null) {
                        $lastPercent = $percent;
                    }

                    $text = $percent === null
                        ? ('正在下载… ' . self::formatBytes($written))
                        : ('正在下载… ' . $percent . '%');
                    $onProgress(['stage' => 'downloading', 'text' => $text, 'percent' => $percent]);
                });
            fflush($fp);
            $ok = $written > 0;
        } catch (HttpException $e) {
            throw new RuntimeException('下载失败：' . $e->getMessage(), 0, $e);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if (!$ok) {
                File::del($dest);
            }
        }

        if (!$ok) {
            throw new RuntimeException('下载失败：文件为空');
        }

        if ($expectedSize > 0 && $written !== $expectedSize) {
            File::del($dest);
            throw new RuntimeException('大小不符：期望 ' . $expectedSize . ' 实得 ' . $written);
        }

        $head = (string)file_get_contents($dest, false, null, 0, 2);
        if ($head !== 'PK') {
            File::del($dest);
            throw new RuntimeException('不是有效 zip');
        }
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
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
     * @param array<string, mixed>|null $batch
     */
    private function fillMissing(array $example, string $prefix = '', ?array &$batch = null): void
    {
        $root = $batch === null;
        if ($root) {
            $batch = [];
        }
        foreach ($example as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            $current = config($path);
            if ($current === null) {
                $batch[$path] = $value;
            } elseif (is_array($value) && is_array($current)) {
                $this->fillMissing($value, $path, $batch);
            }
        }
        if ($root && $batch !== []) {
            Context::instance()->config()->setMany($batch);
        }
    }
}
