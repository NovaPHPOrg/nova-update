<?php

declare(strict_types=1);

namespace nova\plugin\update\controller;

use nova\framework\http\Response;
use nova\plugin\login\controller\BaseAPIController;
use nova\plugin\update\Updater;
use Throwable;

class Update extends BaseAPIController
{
    public function check(): Response
    {
        $updater = new Updater();
        try {
            $info = $updater->check();
            return Response::asJson([
                'code' => 200,
                'msg' => $info['updatable'] ? ('发现新版本 ' . $info['latest']) : '已是最新版本',
                'data' => $info,
            ]);
        } catch (Throwable $e) {
            return Response::asJson([
                'code' => 400,
                'msg' => $e->getMessage(),
                'data' => [
                    'current' => $updater->version(),
                    'latest' => '',
                    'updatable' => false,
                    'changelog' => '',
                    'download_url' => '',
                ],
            ]);
        }
    }

    public function apply(): Response
    {
        try {
            $result = (new Updater())->apply();
            return Response::asJson([
                'code' => 200,
                'msg' => '已更新到 ' . $result['to'],
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return Response::asJson(['code' => 400, 'msg' => $e->getMessage()]);
        }
    }
}
