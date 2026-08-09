<?php

declare(strict_types=1);

namespace nova\plugin\update;

use nova\framework\core\StaticRegister;
use nova\framework\route\RouteTrait;
use nova\plugin\login\AdminPage;
use nova\plugin\login\route\Permission;

class UpdateManager extends StaticRegister
{
    use RouteTrait;

    public function __construct()
    {
        $this->controllerNamespace = 'nova\\plugin\\update\\controller\\';
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $this->post('/update/api/check', $this->map('update', 'check'));
        $this->get('/update/api/apply', $this->map('update', 'apply'));
    }

    public static function registerInfo(): void
    {
        Permission::getInstance()->registerPermissions('系统更新', 'update_manage', [
            'ANY /update*',
        ]);

        self::getInstance()->bindPrefixDispatch('/update');
        AdminPage::bind(UpdateTpl::getInstance());
    }
}
