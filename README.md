# nova-update

检查 GitHub Releases，下载标准 zip，覆盖升级。

## 用法

1. 配置 `version`、`update.repo` / `update.name` / `update.asset`
2. `framework_start` 注册 `nova\plugin\update\UpdateManager`
3. 后台「系统更新」进入即检查；确认后覆盖

保留：`config.php`、`config/`（节点拆分配置）、`runtime/`、`uploads/`。只吃 `{name}-{version}.zip`，不管 Docker/Windows 包。

检查更新走 [api.ankio.net/gh](https://api.ankio.net/docs/gh) 代理，用户不用填 GitHub token。

下载走 `/gh/mirrors?url=` 镜像队列：优先 `runtime/update/preferred_mirror` 里上次成功的前缀，失败则试下一个，最后回落官方地址；成功记下前缀。

Release 请求缓存走 `HttpClient::cache()`（默认 24h）。

## API

- `POST /update/api/check` — 查远端（命中 HttpClient 缓存则不打代理）
- `GET /update/api/apply` — SSE 下载覆盖（chunk/result/done）
