<?php

declare(strict_types=1);

namespace nova\plugin\update;

use function nova\framework\config;

use nova\framework\core\Instance;

use nova\framework\http\Request;
use nova\framework\http\Response;

use function nova\framework\route;

use nova\framework\route\Route;
use nova\plugin\login\AdminPageInterface;
use nova\plugin\tpl\ViewResponse;

class UpdateTpl extends Instance implements AdminPageInterface
{
    public function registerRouter(string $model, string $controller): void
    {
        $default = route($model, $controller, 'init');
        Route::getInstance()
            ->get('/update', $default)
            ->get('/update/index', $default);
    }

    public function route(ViewResponse $view, Request $request): ?Response
    {
        $path = $request->getPath();
        if ($path !== '/update' && $path !== '/update/index') {
            return null;
        }

        return $view->asTpl(ROOT_PATH . DS . 'nova/plugin/update/tpl/index', [
            'current_version' => config('version') ?? '0.0.0',
        ]);
    }

    public function menu(): array
    {
        return [
            'title' => '系统更新',
            'icon' => 'system_update',
            'url' => '/update',
            'pjax' => true,
        ];
    }
}
