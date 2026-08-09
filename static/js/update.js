window.pageLoadFiles = ['MarkdownPreviewer', 'Layer'];

window.pageOnLoad = function () {
    let updatable = false;
    let latest = '';
    let applySource = null;
    const preview = new MarkdownPreviewer('changelog', { value: '' });

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

        preview.setMarkdown(data.changelog || (latest ? '无更新说明' : ''));
        $('#btn_apply').prop('disabled', !updatable);
    }

    function busy(on) {
        $('#btn_check').prop('disabled', on);
        $('#btn_apply').prop('disabled', on || !updatable);
    }

    function checkUpdate(notify) {
        busy(true);
        $('#update_status').removeClass('tag-info tag-success').addClass('tag-neutral').text('检查中');
        $('#update_tip').text('正在检查更新…');
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

    function stopApply(closeEs) {
        if (closeEs && applySource) {
            applySource.close();
        }
        applySource = null;
        $('body').closeLoading();
        busy(false);
    }

    function startApply() {
        let ok = false;
        busy(true);
        $('body').showLoading('准备更新…');

        applySource = $.request.sse('/update/api/apply', {
            autoReconnect: false,
            eventHandlers: {
                chunk: function (data) {
                    if (!data || typeof data !== 'object') return;
                    if (data.type === 'error') {
                        stopApply(true);
                        $.toaster.error(data.text || '更新失败');
                        return;
                    }
                    let text = data.text || '更新中…';
                    if (typeof data.percent === 'number' && data.percent >= 0 && data.percent < 100
                        && text.indexOf('%') === -1) {
                        text += ' ' + data.percent + '%';
                    }
                    $('body').updateLoading(text);
                },
                result: function (data) {
                    ok = true;
                    const to = data && data.to ? data.to : latest;
                    $.toaster.success('已更新到 ' + to);
                },
                done: function () {
                    stopApply(true);
                    if (ok) {
                        setTimeout(() => location.reload(), 800);
                    }
                },
            },
            onError: function () {
                if (!applySource) return;
                stopApply(true);
                $.toaster.error('更新连接中断');
            },
        });
    }

    $('#btn_check').on('click', () => checkUpdate(true));

    $('#btn_apply').on('click', function () {
        if (!updatable) return;
        $.layer.confirm({
            title: '确认更新',
            msg: '升级到 ' + latest + '，将覆盖程序文件（配置与数据保留）。',
            yes: startApply,
            no: function () {},
        });
    });

    checkUpdate(false);

    window.pageOnUnLoad = function () {
        if (applySource) {
            applySource.close();
            applySource = null;
        }
        preview.destroy();
    };
};
