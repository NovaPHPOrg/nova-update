<?php

declare(strict_types=1);

namespace nova\plugin\update\controller;

use nova\framework\http\Response;
use nova\plugin\login\controller\BaseAPIController;
use nova\plugin\update\Updater;
use Throwable;

class Update extends BaseAPIController
{
    public function status(): Response
    {
        $updater = new Updater();
        $data = $updater->cached() ?? [
            'current' => $updater->version(),
            'latest' => '',
            'updatable' => false,
            'changelog' => '',
            'download_url' => '',
        ];
        return Response::asJson(['code' => 200, 'data' => $data]);
    }

    public function check(): Response
    {
        try {
            $info = (new Updater())->check();
            return Response::asJson([
                'code' => 200,
                'msg' => $info['updatable'] ? ('发现新版本 ' . $info['latest']) : '已是最新版本',
                'data' => $info,
            ]);
        } catch (Throwable $e) {
            return Response::asJson(['code' => 400, 'msg' => $e->getMessage()]);
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
