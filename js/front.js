jQuery(document).ready(function ($) {
    // ローカライズされたデータ (変更なし)
    const ajax_url = edel_chatbot_params.ajax_url;
    const nonce = edel_chatbot_params.nonce;
    const action = edel_chatbot_params.action;

    // 要素を取得
    const $container = $('#edel_ai_chatbot_widget-container');
    const $openButton = $('#edel_ai_chatbot_open-button');
    const $window = $('#edel_ai_chatbot_window');
    const $closeButton = $('#edel_ai_chatbot_close-button');
    const $form = $('#edel_ai_chatbot_form');
    const $input = $('#edel_ai_chatbot_input');
    const $history = $('#edel_ai_chatbot_history');
    const $submitButton = $('#edel_ai_chatbot_submit');
    const $loading = $('#edel_ai_chatbot_loading');

    console.log('Edel AI Chatbot (Floating UI) script loaded.');

    const $maximizeButton = $('#edel_ai_chatbot_maximize-button');
    const iconExpand = `<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" fill="currentColor" class="bi bi-arrows-fullscreen" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M5.828 10.172a.5.5 0 0 0-.707 0l-4.096 4.096V11.5a.5.5 0 0 0-1 0v3.975a.5.5 0 0 0 .5.5H4.5a.5.5 0 0 0 0-1H1.732l4.096-4.096a.5.5 0 0 0 0-.707zm4.344 0a.5.5 0 0 1 .707 0l4.096 4.096V11.5a.5.5 0 1 1 1 0v3.975a.5.5 0 0 1-.5.5H11.5a.5.5 0 0 1 0-1h2.768l-4.096-4.096a.5.5 0 0 1 0-.707zm0-4.344a.5.5 0 0 0 .707 0l4.096-4.096V4.5a.5.5 0 1 0 1 0V.525a.5.5 0 0 0-.5-.5H11.5a.5.5 0 0 0 0 1h2.768l-4.096 4.096a.5.5 0 0 0 0 .707zm-4.344 0a.5.5 0 0 1-.707 0L1.025 1.732V4.5a.5.5 0 0 1-1 0V.525a.5.5 0 0 1 .5-.5H4.5a.5.5 0 0 1 0 1H1.732l4.096 4.096a.5.5 0 0 1 0 .707z"/></svg>`;
    const iconContract = `<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" fill="currentColor" class="bi bi-arrows-angle-contract" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M.172 15.828a.5.5 0 0 0 .707 0l4.096-4.096V14.5a.5.5 0 1 0 1 0v-3.975a.5.5 0 0 0-.5-.5H1.5a.5.5 0 0 0 0 1h2.768L.172 15.121a.5.5 0 0 0 0 .707zM15.828.172a.5.5 0 0 0-.707 0l-4.096 4.096V1.5a.5.5 0 1 0-1 0v3.975a.5.5 0 0 0 .5.5H14.5a.5.5 0 0 0 0-1h-2.768L15.828.879a.5.5 0 0 0 0-.707z"/></svg>`;

    // --- 開閉機能 ---
    // 開くボタンのクリックイベント
    $openButton.on('click', function () {
        toggleWindow(true);
    });

    // 閉じるボタンのクリックイベント
    $closeButton.on('click', function () {
        toggleWindow(false);
    });

    // ウィンドウ表示/非表示を切り替える関数
    function toggleWindow(show) {
        if (show) {
            $window.addClass('is-visible'); // 表示用クラスを追加
            $openButton.hide(); // 開いたらボタンを隠す（お好みで）
            // $input.focus(); // 開いたら入力欄にフォーカス（オプション）
        } else {
            $window.removeClass('is-visible'); // 表示用クラスを削除
            $openButton.show(); // 閉じたらボタンを表示（お好みで）
        }
    }
    // --- 開閉機能ここまで ---

    $maximizeButton.on('click', function () {
        // is-maximized クラスをトグル（付け外し）
        $window.toggleClass('is-maximized');

        // クラスの有無でアイコンを切り替え
        if ($window.hasClass('is-maximized')) {
            $(this).html(iconContract); // 縮小アイコンに変更
            $(this).attr('title', '縮小'); // title属性も変更
        } else {
            $(this).html(iconExpand); // 拡大アイコンに戻す
            $(this).attr('title', '拡大'); // title属性も戻す
        }

        // (オプション) サイズ変更後に履歴の高さを再計算・スクロールなどが必要な場合がある
        // adjustHistoryHeight();
        // $history.scrollTop($history[0].scrollHeight);
    });

    // フォーム送信時の処理 (Ajax部分はまだコメントアウト)
    $form.on('submit', function (e) {
        e.preventDefault();
        const userMessage = $input.val().trim();
        if (userMessage === '') return;

        appendMessage(userMessage, 'user');
        $input.val('');
        adjustTextareaHeight(); // 送信後に入力欄の高さをリセット
        showLoading(true);

        // ====[ Ajax送信 (次のステップ) ]====
        console.log('Sending message:', userMessage);
        $.ajax({
            url: ajax_url, // wp_localize_script で渡したURL
            type: 'POST',
            data: {
                action: action, // wp_localize_script で渡したアクション名
                nonce: nonce, // wp_localize_script で渡したNonce
                message: userMessage // ユーザーが入力したメッセージ
            },
            dataType: 'json', // サーバーからの応答はJSON形式と期待
            success: function (response) {
                // ← エラーはこの中で発生している可能性 (95行目付近)
                console.log('AJAX Success:', response);
                // サーバーからの応答が成功した場合
                if (response.success && response.data.message) {
                    // ★ appendMessage の呼び出し (エラー箇所 137行目はこの関数内のはず)
                    appendMessage(response.data.message, 'bot');
                } else {
                    // サーバー側で success: false または data.message がない場合
                    // ★★★ const errorMessage を let に変更する必要があるかも？ ★★★
                    // (もしこの errorMessage に後で再代入する可能性があるなら let にする)
                    let errorMessage = response.data?.message || 'エラーが発生しました。(応答なし)';
                    // ★ appendMessage の呼び出し (エラー箇所 137行目はこの関数内のはず)
                    appendMessage(errorMessage, 'bot', true); // エラーメッセージとして表示
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                // ★★★ error コールバック ★★★
                // --- エラー時の処理 ---
                console.error('AJAX Error:', textStatus, errorThrown);
                console.error('Response Text:', jqXHR.responseText); // ★サーバーからの応答内容をログに出力

                let errorMessage = 'サーバーとの通信に失敗しました。'; // デフォルトのエラーメッセージ

                // サーバーからのJSON応答がresponseTextに含まれているか試す
                try {
                    const errorResponse = JSON.parse(jqXHR.responseText); // ここは const でOK
                    if (errorResponse && errorResponse.data && errorResponse.data.message) {
                        errorMessage = errorResponse.data.message; // let なので再代入OK
                    }
                } catch (e) {
                    // JSONとしてパースできなかった場合
                    console.error('Could not parse error response:', e);
                }
                // ★ appendMessage の呼び出し (エラー箇所 137行目はこの関数内のはず)
                appendMessage(errorMessage, 'bot', true); // エラーメッセージを表示
            }, // ★★★ error コールバックここまで ★★★
            complete: function (jqXHR, textStatus) {
                // ★★★ complete コールバック ★★★
                console.log('AJAX Complete. Status:', textStatus);
                showLoading(false); // ★★★ 成功・失敗に関わらずここでローディングを必ず停止 ★★★
            }
        });
        // ====[ Ajax送信 ここまで ]====
    });

    /**
     * メッセージをチャット履歴に追加する関数
     * @param {string} message 表示するメッセージ内容
     * @param {string} sender 送信者 ('user' または 'bot')
     * @param {boolean} [isError=false] エラーメッセージかどうか (ボットからのメッセージの場合のみ有効)
     */
    function appendMessage(message, sender, isError = false) {
        // 1. 送信者に応じた基本クラスを設定
        const messageClass = sender === 'user' ? 'edel-chatbot-message-user' : 'edel-chatbot-message-bot';

        // 2. 表示するメッセージを準備 (エラー時に補足情報を追加する可能性あり)
        let displayMessage = message;

        // 3. エラー時に追加するクラスを準備 (初期値は空)
        let errorClass = '';

        // 4. isErrorがtrue かつ ボットからのメッセージの場合にエラー処理を行う
        if (isError && sender === 'bot') {
            errorClass = ' edel-chatbot-message-error'; // エラークラス名を設定
            // 補足情報をメッセージに追加 (改行コード \n を使用)
            displayMessage += '\n\n' + '（問題が解決しない場合は、しばらく時間をおいて再度試すか、管理者にご連絡ください。）';
        }

        // 5. メッセージ要素をjQueryオブジェクトとして作成
        //    基本クラスとエラークラス(あれば)を結合して設定
        const $messageDiv = $('<div class="edel-chatbot-message ' + messageClass + errorClass + '"><p></p></div>');

        // 6. メッセージ内容を <p> タグにテキストとして設定
        //    - .text() を使うことでHTMLタグはエスケープされ、安全に表示される
        //    - 改行文字(\n)はそのまま残り、CSSの white-space: pre-wrap で改行として表示される
        //    - エラー時の補足情報が含まれる displayMessage を使用
        $messageDiv.find('p').text(displayMessage);

        // 7. 作成したメッセージ要素を履歴エリア ($history) に追加
        $history.append($messageDiv);

        // 8. 履歴エリアを常に最下部にスクロールさせる
        //    scrollHeight は要素のコンテンツ全体の高さを取得
        $history.scrollTop($history[0].scrollHeight);
    }

    // ローディング表示関数 (入力欄の無効化/有効化も追加)
    function showLoading(show) {
        if (show) {
            $loading.show();
            $submitButton.prop('disabled', true);
            $input.prop('disabled', true); // 入力欄も無効化
        } else {
            $loading.hide();
            $submitButton.prop('disabled', false);
            $input.prop('disabled', false); // 入力欄を有効化
            $input.focus(); // 入力欄にフォーカスを戻す
        }
    }

    // テキストエリアの高さ自動調整
    function adjustTextareaHeight() {
        $input.css('height', 'auto'); // 一旦高さをリセット
        // scrollHeightが最小高さ(min-height)より小さい場合はmin-heightを使う
        const newHeight = Math.max($input[0].scrollHeight, parseInt($input.css('min-height')));
        $input.css('height', newHeight + 'px');
    }
    $input.on('input', adjustTextareaHeight); // 入力時にも高さを調整

    // (オプション) ウィンドウ外クリックで閉じる
    // $(document).on('click', function(event) {
    //    if ($window.hasClass('is-visible') && !$(event.target).closest('#edel_ai_chatbot_window').length && !$(event.target).closest('#edel_ai_chatbot_open-button').length) {
    //        toggleWindow(false);
    //    }
    // });
});
