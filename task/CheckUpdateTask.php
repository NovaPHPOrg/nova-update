<?php

declare(strict_types=1);

namespace nova\plugin\update\task;

use nova\framework\core\Logger;
use nova\plugin\corn\schedule\TaskerAbstract;
use nova\plugin\update\Updater;
use Throwable;

class CheckUpdateTask extends TaskerAbstract
{
    public function getTimeOut(): int
    {
        return 120;
    }

    public function onStart(): void
    {
        try {
            $info = (new Updater())->check();
            Logger::info($info['updatable']
                ? '[Update] 新版本 ' . $info['latest']
                : '[Update] 已最新 ' . $info['current']);
        } catch (Throwable $e) {
            Logger::warning('[Update] 检查失败：' . $e->getMessage());
        }
    }

    public function onStop(): void
    {
    }

    public function onAbort(Throwable $e): void
    {
        Logger::error('[Update] ' . $e->getMessage());
    }
}
