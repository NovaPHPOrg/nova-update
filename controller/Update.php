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
                    'size' => 0,
                ],
            ]);
        }
    }

    /**
     * 覆盖升级：SSE 推送进度。
     * 事件：chunk -> {type,text,percent?}（type ∈ progress/error）；result -> {from,to}；done -> end
     */
    public function apply(): Response
    {
        return Response::asSSE(function (callable $emit): void {
            $send = static function (string $type, string $text, ?int $percent = null) use ($emit): void {
                $payload = ['type' => $type, 'text' => $text];
                if ($percent !== null) {
                    $payload['percent'] = $percent;
                }
                $emit(json_encode($payload, JSON_UNESCAPED_UNICODE), 'chunk');
            };

            try {
                $result = (new Updater())->apply(static function (array $p) use ($send): void {
                    $send('progress', (string)$p['text'], $p['percent'] ?? null);
                });
                $emit(json_encode($result, JSON_UNESCAPED_UNICODE), 'result');
            } catch (Throwable $e) {
                $send('error', $e->getMessage());
            }

            $emit('end', 'done');
        });
    }
}
