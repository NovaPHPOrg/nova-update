<title id="title">系统更新 - {$title}</title>
<style id="style">
    /* base 无固定图标盒；状态色复用 --tag-* */
    .update-kpi-icon {
        flex: 0 0 48px;
        height: 48px;
        border-radius: 12px;
        background: rgba(var(--mdui-color-primary-container), .55);
        color: rgb(var(--mdui-color-primary));
    }
    .update-kpi-icon mdui-icon { font-size: 26px; }
    .update-kpi-icon.is-new {
        background: rgb(var(--tag-info-bg));
        color: rgb(var(--tag-info-fg));
    }
    .update-kpi-icon.is-ok {
        background: rgb(var(--tag-success-bg));
        color: rgb(var(--tag-success-fg));
    }
    /* MarkdownPreviewer 挂载后避免被宿主固定高度压扁 */
    #changelog.markdown-host--preview { height: auto; min-height: 4.5rem; }
</style>

<div id="container" class="container p-4">
    <div class="row col-space16">
        <div class="col-xs12 title-large center-vertical mb-4">
            <mdui-icon name="system_update" class="mr-2"></mdui-icon>
            <span>系统更新</span>
            <div class="flex-1"></div>
            <span id="update_status" class="tag tag-neutral">未检查</span>
        </div>

        <div class="col-xs12 col-sm6">
            <mdui-card class="shadow-none bg-surface-container-low d-flex items-center gap-3 p-3 w-full">
                <div class="update-kpi-icon center-both">
                    <mdui-icon name="inventory_2"></mdui-icon>
                </div>
                <div class="d-flex flex-col min-w-0">
                    <div id="current_version" class="headline-small font-bold">-</div>
                    <div class="body-small text-on-surface-variant">当前版本</div>
                </div>
            </mdui-card>
        </div>

        <div class="col-xs12 col-sm6">
            <mdui-card class="shadow-none bg-surface-container-low d-flex items-center gap-3 p-3 w-full">
                <div id="latest_icon" class="update-kpi-icon center-both">
                    <mdui-icon name="new_releases"></mdui-icon>
                </div>
                <div class="d-flex flex-col min-w-0">
                    <div id="latest_version" class="headline-small font-bold">-</div>
                    <div class="body-small text-on-surface-variant">最新版本</div>
                </div>
            </mdui-card>
        </div>

        <div class="col-xs12">
            <div id="update_tip" class="bg-surface-container rounded-lg p-3 body-small text-on-surface-variant">
                无须更新
            </div>
        </div>

        <div class="col-xs12">
            <div class="title-small mb-2 font-semibold">更新说明</div>
            <div id="changelog" class="overflow-auto p-3 rounded-lg bg-surface-container"></div>
        </div>

        <div class="col-xs12 d-flex justify-end gap-2 flex-wrap mt-2">
            <mdui-button id="btn_check" icon="refresh" variant="tonal" type="button">检查更新</mdui-button>
            <mdui-button id="btn_apply" icon="download" type="button" disabled>立即更新</mdui-button>
        </div>
    </div>
</div>

<script id="script" src="/update/static/js/update.js?v={$__v}"></script>
