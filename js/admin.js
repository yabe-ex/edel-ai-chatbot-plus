jQuery(document).ready(function ($) {
    console.log('jquery loaded');
    const prefix = 'edel_ai_chatbot_plus_'; // JS内で使うプレフィックス
    const $startButton = $('#' + prefix + 'start-learning-button');
    const $spinner = $('#' + prefix + 'learning-spinner');
    const $progressArea = $('#' + prefix + 'learning-progress');
    const $progressBar = $progressArea.find('.progress-bar');
    const $progressStatus = $progressArea.find('.status');
    const $progressLog = $progressArea.find('.log');

    // --- 自動学習バッチ処理 ---
    let totalItems = 0; // 総処理アイテム数
    let processedItems = 0; // 処理済みアイテム数
    let isProcessing = false; // 処理中フラグ

    // 学習開始ボタンのクリックイベント
    $startButton.on('click', function () {
        if (isProcessing) return; // 処理中はボタンを無効化 (UI改善)

        if (!confirm('サイトコンテンツの学習を開始しますか？ コンテンツ量によっては時間がかかります。処理中はブラウザを閉じないでください。')) {
            return;
        }

        // UIリセットと処理開始表示
        isProcessing = true;
        $startButton.prop('disabled', true);
        $spinner.addClass('is-active');
        $progressArea.show();
        $progressBar.css('width', '0%').text('0%');
        $progressStatus.text('対象コンテンツのリストを取得中...');
        $progressLog.html(''); // ログクリア

        // ★ 最初のAjaxリクエストを送信 (まずはリスト取得) ★
        sendBatchRequest({ step: 'get_list' });
    });

    // Ajaxリクエストを送信する関数
    function sendBatchRequest(data) {
        const ajaxData = {
            action: prefix + 'batch_learning', // ★ 新しいAjaxアクション名
            nonce: edel_chatbot_admin_params.nonce, // ★ Nonce (admin_enqueueで渡す必要あり)
            ...data // step や offset などの情報
        };
        console.log('Sending AJAX:', ajaxData); // デバッグ用

        $.ajax({
            url: edel_chatbot_admin_params.ajaxurl, // ★ admin_enqueueで渡す必要あり
            type: 'POST',
            data: ajaxData,
            dataType: 'json',
            success: function (response) {
                console.log('AJAX Success:', response);
                if (response.success) {
                    // 成功時の処理
                    updateProgress(response.data); // 進捗を更新

                    if (response.data.status === 'processing') {
                        // まだ処理が残っている場合、次のバッチをリクエスト
                        sendBatchRequest({ step: 'process_item', offset: response.data.offset });
                    } else if (response.data.status === 'complete') {
                        // 全ての処理が完了した場合
                        $progressStatus.html('<strong>学習処理が完了しました！</strong>'); // ★ 太字にするなど
                        $progressBar.css('width', '100%').text('100%'); // 100% 表示のままにするか、リセットするかはお好みで
                        $progressLog.append('<p><strong>全工程が完了しました。</strong></p>'); // 完了ログ追加
                        // UIリセット（ボタン有効化、スピナー非表示）
                        isProcessing = false;
                        $startButton.prop('disabled', false);
                        $spinner.removeClass('is-active');
                        // ★ 完了後、進捗エリアを隠す場合 ★
                        setTimeout(function () {
                            $progressArea.fadeOut(function () {
                                // フェードアウト完了後にプログレスバーなどを初期状態に戻す
                                $progressBar.css('width', '0%').text('0%');
                                $progressStatus.text('待機中...');
                                $progressLog.html('');
                            });
                        }, 5000); // 5秒後にフェードアウト開始
                    } else if (response.data.status === 'list_received') {
                        // リスト取得が完了した場合、最初のアイテム処理を開始
                        totalItems = response.data.total_items || 0;
                        processedItems = 0;
                        if (totalItems > 0) {
                            $progressStatus.text('投稿/ページの処理を開始します (0/' + totalItems + ')');
                            sendBatchRequest({ step: 'process_item', offset: 0 }); // offset 0 から開始
                        } else {
                            $progressStatus.text('学習対象の投稿/ページが見つかりませんでした。');
                            resetUI();
                        }
                    } else {
                        // 予期しないステータス
                        $progressStatus.text('予期しない応答を受け取りました。');
                        $progressLog.append('<p style="color:orange;">予期しない応答: ' + JSON.stringify(response.data) + '</p>');
                        resetUI();
                    }
                } else {
                    // サーバー側でエラー (wp_send_json_error)
                    const errorMessage = response.data?.message || 'サーバー側でエラーが発生しました。';
                    $progressStatus.text('エラーが発生しました。');
                    $progressLog.append('<p style="color:red;">エラー: ' + escapeHtml(errorMessage) + '</p>');
                    resetUI();
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                // 通信エラー
                console.error('AJAX Error:', textStatus, errorThrown);
                $progressStatus.text('通信エラーが発生しました。');
                $progressLog.append('<p style="color:red;">通信エラー: ' + escapeHtml(textStatus) + '</p>');
                resetUI();
            }
            // complete コールバックはここでは不要 (ループ処理で制御するため)
        });
    }

    // 進捗表示を更新する関数
    function updateProgress(data) {
        // 処理済み件数を加算 (data.processed_count があれば)
        if (data.processed_count !== undefined) {
            processedItems += data.processed_count;
        }
        // プログレスバー更新
        if (totalItems > 0) {
            const percentage = Math.min(100, Math.round((processedItems / totalItems) * 100));
            $progressBar.css('width', percentage + '%').text(percentage + '%');
        }
        // ★★★ 詳細ログメッセージを表示 (data.log_message があれば) ★★★
        if (data.log_message) {
            // item_statusに応じて色を変える (オプション)
            let logStyle = '';
            if (data.item_status === 'skipped') {
                logStyle = 'style="color: #777;"'; // グレー
            } else if (data.item_status === 'error') {
                logStyle = 'style="color: red;"'; // 赤
            }
            $progressLog.append('<p ' + logStyle + '>' + escapeHtml(data.log_message) + '</p>');
            // ログエリアをスクロール
            $progressLog.scrollTop($progressLog[0].scrollHeight);
            // 古いログを削除 (オプション)
            if ($progressLog.children().length > 50) {
                // 例: 50件まで保持
                $progressLog.children().first().remove();
            }
        }
        // ステータステキスト更新
        if (data.status === 'processing') {
            $progressStatus.text('投稿/ページの処理中 (' + processedItems + '/' + totalItems + ')');
        }
    } // end updateProgress()

    // UIをリセットする関数
    function resetUI() {
        isProcessing = false;
        $startButton.prop('disabled', false);
        $spinner.removeClass('is-active');
        // プログレスバーは完了状態を示すなどしても良い
    }

    // HTMLエスケープ用関数 (簡易版)
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // カテゴリ全選択/解除JS (既存)
    $('.edel-cat-select-all').on('change', function () {
        $(this).closest('div').find('input[type="checkbox"]').not(this).prop('checked', this.checked);
    });
});
