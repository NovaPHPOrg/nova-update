# nova-update

检查 GitHub Releases，下载标准 zip，覆盖升级。

## 用法

1. 配置 `version`、`update.repo` / `update.name` / `update.asset`
2. `framework_start` 注册 `nova\plugin\update\UpdateManager`
3. 后台「系统更新」→ 检查 → 确认后覆盖

保留：`config.php`、`runtime/`、`uploads/`。只吃 `{name}-{version}.zip`，不管 Docker/Windows 包。

可选：`update.token` 提高 GitHub API 限额。

## API

- `GET /update/api/status` — 读缓存
- `POST /update/api/check` — 查远端
- `POST /update/api/apply` — 下载覆盖
