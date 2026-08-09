# nova-update

检查 GitHub Releases，下载标准 zip，覆盖升级。

## 用法

1. 配置 `version`、`update.repo` / `update.name` / `update.asset`
2. `framework_start` 注册 `nova\plugin\update\UpdateManager`
3. 后台「系统更新」进入即检查；确认后覆盖

保留：`config.php`、`runtime/`、`uploads/`。只吃 `{name}-{version}.zip`，不管 Docker/Windows 包。

可选：`update.token` 提高 GitHub API 限额。

GitHub API 请求缓存走 `HttpClient::cache()`（默认 24h），不再单独存业务结果。

## API

- `POST /update/api/check` — 查远端（命中 HttpClient 缓存则不打 GitHub）
- `POST /update/api/apply` — 下载覆盖
