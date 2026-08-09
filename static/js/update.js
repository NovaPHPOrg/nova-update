window.pageLoadFiles = ['MarkdownPreviewer'];

window.pageOnLoad = function () {
    let updatable = false;
    let latest = '';
    let preview = null;

    function setChangelog(md) {
        const text = md || '';
        if (preview) {
            preview.setMarkdown(text);
            return;
        }
        $('#changelog').text(text);
    }

    function render(data) {
        data = data || {};
        updatable = !!data.updatable;
        latest = data.latest || '';

        $('#current_version').text(data.current || '-');
        $('#latest_version').text(latest || '—');

        const $icon = $('#latest_icon');
        const $status = $('#update_status');
        $icon.removeClass('is-new is-ok');
        $status.removeClass('tag-neutral tag-info tag-success');

        if (!latest) {
            $status.addClass('tag-neutral').text('检查失败');
        } else if (updatable) {
            $icon.addClass('is-new');
            $status.addClass('tag-info').text('有新版本');
            $('#update_tip').text('发现新版本 ' + latest + '，确认后将下载并覆盖安装（配置与数据保留）。');
        } else {
            $icon.addClass('is-ok');
            $status.addClass('tag-success').text('已是最新');
            $('#update_tip').text('当前已是最新版本，无需更新。');
        }

        setChangelog(data.changelog || (latest ? '无更新说明' : ''));
        $('#btn_apply').prop('disabled', !updatable);
    }

    function busy(on) {
        $('#btn_check').prop('disabled', on);
        $('#btn_apply').prop('disabled', on || !updatable);
    }

    function checking() {
        $('#update_status').removeClass('tag-info tag-success').addClass('tag-neutral').text('检查中');
        $('#update_tip').text('正在检查更新…');
    }

    function checkUpdate(notify) {
        busy(true);
        checking();
        $.request.postForm('/update/api/check', {}, (res) => {
            busy(false);
            if (res.code !== 200) {
                render(res.data || {});
                $('#update_tip').text(res.msg || '检查失败');
                if (notify) $.toaster.error(res.msg || '检查失败');
                return;
            }
            render(res.data);
            if (notify) $.toaster.success(res.msg);
        }, () => {
            busy(false);
            $('#update_status').removeClass('tag-info tag-success').addClass('tag-neutral').text('检查失败');
            $('#update_tip').text('检查失败');
            if (notify) $.toaster.error('检查失败');
        });
    }

    function bindActions() {
        $('#btn_check').on('click', function () {
            checkUpdate(true);
        });

        $('#btn_apply').on('click', function () {
            if (!updatable) return;
            $.layer.confirm({
                title: '确认更新',
                msg: '升级到 ' + latest + '，将覆盖程序文件（配置与数据保留）。',
                yes: function () {
                    busy(true);
                    $.request.postForm('/update/api/apply', {}, (res) => {
                        busy(false);
                        if (res.code !== 200) {
                            $.toaster.error(res.msg || '更新失败');
                            return;
                        }
                        $.toaster.success(res.msg);
                        setTimeout(() => location.reload(), 800);
                    }, () => {
                        busy(false);
                        $.toaster.error('更新失败');
                    });
                },
                no: function () {},
            });
        });
    }

    function start() {
        if (typeof window.MarkdownPreviewer === 'function') {
            preview = new window.MarkdownPreviewer('changelog', { value: '' });
        } else {
            $.logger?.error('MarkdownPreviewer 未加载，回退纯文本');
        }
        bindActions();
        checkUpdate(false);
    }

    let started = false;
    const run = () => {
        if (started) return;
        started = true;
        start();
    };

    // bundle 以 "use strict" 开头时，class 声明不会挂到 window；等赋值后再初始化
    if (typeof window.MarkdownPreviewer === 'function') {
        run();
    } else {
        $.waitProp(window, 'MarkdownPreviewer', run);
        setTimeout(run, 2000); // 超时回退纯文本，避免按钮永远不绑定
    }

    window.pageOnUnLoad = function () {
        preview?.destroy();
        preview = null;
    };
};
