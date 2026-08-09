<?php

declare(strict_types=1);

namespace nova\plugin\update\service;

use function nova\framework\config;

use nova\framework\core\File;

use function nova\framework\isWorkerman;

use nova\plugin\http\HttpClient;
use nova\plugin\http\HttpException;
use RuntimeException;

use Throwable;
use ZipArchive;

/**
 * 下载标准 zip 并覆盖安装目录；保留 config.php / runtime / uploads。
 */
class UpdateApplier
{
    private const LOCK_FILE = 'lock';
    private const PACKAGE_FILE = 'package.zip';
    private const EXTRACT_DIR = 'extract';
    private const LAST_FILE = 'last.json';

    /** @var list<string> 相对 ROOT_PATH 永不覆盖的顶层项 */
    private const PRESERVE = ['config.php', 'runtime', 'uploads'];

    public function __construct(
        private readonly ReleaseChecker $checker = new ReleaseChecker()
    ) {
    }

    /**
     * @return array{from: string, to: string, reload_hint: string}
     */
    public function apply(): array
    {
        $this->assertEnvironment();

        $info = $this->checker->check(true);
        if (!$info['updatable']) {
            throw new RuntimeException('当前已是最新版本：' . $info['current']);
        }

        $work = $this->checker->workDir();
        $lock = $work . DS . self::LOCK_FILE;
        if (is_file($lock)) {
            $age = time() - (int)filemtime($lock);
            if ($age < 1800) {
                throw new RuntimeException('已有更新任务进行中，请稍后再试');
            }
            @unlink($lock);
        }

        File::write($lock, (string)time());

        $zipPath = $work . DS . self::PACKAGE_FILE;
        $extractDir = $work . DS . self::EXTRACT_DIR;

        try {
            $this->download($info['download_url'], $zipPath, (int)$info['size']);
            $this->cleanDir($extractDir);
            File::mkDir($extractDir);
            $this->extract($zipPath, $extractDir);

            $packageRoot = $this->resolvePackageRoot($extractDir);
            $this->overlay($packageRoot, ROOT_PATH);
            $this->mergeConfig($packageRoot, (string)$info['latest']);
            $this->clearCaches();

            $from = (string)$info['current'];
            $to = (string)$info['latest'];
            $last = [
                'from' => $from,
                'to' => $to,
                'at' => time(),
                'asset' => $info['asset'],
            ];
            File::write($work . DS . self::LAST_FILE, json_encode($last, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            @unlink($zipPath);
            $this->cleanDir($extractDir);

            return [
                'from' => $from,
                'to' => $to,
                'reload_hint' => isWorkerman()
                    ? '代码已更新。若使用 Workerman，请执行 reload 使进程加载新代码。'
                    : '代码已更新，请刷新页面。',
            ];
        } catch (Throwable $e) {
            throw $e;
        } finally {
            @unlink($lock);
        }
    }

    private function assertEnvironment(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('缺少 zip 扩展（ZipArchive）');
        }
        if (!is_writable(ROOT_PATH)) {
            throw new RuntimeException('应用根目录不可写，无法在线更新');
        }
        if (!is_dir(RUNTIME_PATH) || !is_writable(RUNTIME_PATH)) {
            throw new RuntimeException('runtime 目录不可写，无法在线更新');
        }
        if (file_exists('/.dockerenv') && !is_writable(ROOT_PATH . DS . 'public')) {
            throw new RuntimeException('检测到 Docker 且目录只读，请使用镜像/compose 方式更新，不要使用在线覆盖');
        }
    }

    private function download(string $url, string $dest, int $expectedSize): void
    {
        if (is_file($dest)) {
            @unlink($dest);
        }
        File::mkDir(dirname($dest));

        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            throw new RuntimeException('无法创建下载文件：' . $dest);
        }

        $written = 0;
        try {
            // browser_download_url 会 302 到对象存储；不要带 Authorization，否则跳转后易 400
            $client = HttpClient::init('')
                ->timeout(600)
                ->setHeaders([
                    'User-Agent' => 'nova-update',
                    'Accept' => 'application/octet-stream',
                ]);

            $client->get()->stream(
                $url,
                [],
                static function (string $chunk) use ($fp, &$written): void {
                    $n = fwrite($fp, $chunk);
                    if ($n === false) {
                        throw new RuntimeException('写入下载文件失败');
                    }
                    $written += $n;
                }
            );
        } catch (HttpException $e) {
            fclose($fp);
            @unlink($dest);
            throw new RuntimeException('下载更新包失败：' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            fclose($fp);
            @unlink($dest);
            throw $e;
        }

        fclose($fp);

        if ($written <= 0 || !is_file($dest)) {
            @unlink($dest);
            throw new RuntimeException('下载更新包失败：文件为空');
        }

        if ($expectedSize > 0 && $written !== $expectedSize) {
            // GitHub 偶发不带准确长度时以实际文件为准；明显过小则失败
            if ($written < (int)($expectedSize * 0.5)) {
                @unlink($dest);
                throw new RuntimeException("下载不完整：期望 {$expectedSize} 字节，实际 {$written}");
            }
        }
    }

    private function extract(string $zipPath, string $extractDir): void
    {
        $zip = new ZipArchive();
        $ok = $zip->open($zipPath);
        if ($ok !== true) {
            throw new RuntimeException('无法打开更新包（ZipArchive code ' . $ok . '）');
        }
        try {
            if (!$zip->extractTo($extractDir)) {
                throw new RuntimeException('解压更新包失败');
            }
        } finally {
            $zip->close();
        }
    }

    private function resolvePackageRoot(string $extractDir): string
    {
        if ($this->looksLikeAppRoot($extractDir)) {
            return $extractDir;
        }

        $entries = array_values(array_filter(
            scandir($extractDir) ?: [],
            static fn (string $n): bool => $n !== '.' && $n !== '..'
        ));

        if (count($entries) === 1) {
            $only = $extractDir . DS . $entries[0];
            if (is_dir($only) && $this->looksLikeAppRoot($only)) {
                return $only;
            }
        }

        throw new RuntimeException('更新包结构无效：未找到 public/ 或 index.php');
    }

    private function looksLikeAppRoot(string $dir): bool
    {
        return is_dir($dir . DS . 'public') || is_file($dir . DS . 'index.php');
    }

    private function overlay(string $srcRoot, string $destRoot): void
    {
        $srcRoot = rtrim($srcRoot, '/\\');
        $destRoot = rtrim($destRoot, '/\\');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $srcPath = $item->getPathname();
            $relative = ltrim(substr($srcPath, strlen($srcRoot)), '/\\');
            if ($relative === '' || $this->isPreserved($relative)) {
                continue;
            }

            $destPath = $destRoot . DS . str_replace(['/', '\\'], DS, $relative);
            if ($item->isDir()) {
                File::mkDir($destPath);
                continue;
            }

            if (!File::copyFile($srcPath, $destPath)) {
                throw new RuntimeException('覆盖文件失败：' . $relative);
            }
        }
    }

    private function isPreserved(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);
        foreach (self::PRESERVE as $name) {
            if ($relative === $name || str_starts_with($relative, $name . '/')) {
                return true;
            }
        }
        return false;
    }

    private function mergeConfig(string $packageRoot, string $latestVersion): void
    {
        $exampleFile = $packageRoot . DS . 'example.config.php';
        if (!is_file($exampleFile)) {
            config('version', $latestVersion);
            return;
        }

        /** @var mixed $example */
        $example = include $exampleFile;
        if (is_array($example)) {
            $this->mergeMissing($example, '');
        }

        config('version', $latestVersion);
    }

    /**
     * @param array<string, mixed> $example
     */
    private function mergeMissing(array $example, string $prefix): void
    {
        foreach ($example as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            $current = config($path);

            if ($current === null) {
                config($path, $value);
                continue;
            }

            if (is_array($value) && is_array($current)) {
                $this->mergeMissing($value, $path);
            }
        }
    }

    private function clearCaches(): void
    {
        foreach (['view', 'static', 'cache'] as $sub) {
            $dir = RUNTIME_PATH . DS . $sub;
            if (is_dir($dir)) {
                try {
                    File::del($dir, true);
                } catch (Throwable) {
                    // 清缓存失败不阻断升级
                }
            }
        }
    }

    private function cleanDir(string $dir): void
    {
        if (is_dir($dir)) {
            File::del($dir);
        }
    }
}
