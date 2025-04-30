jQuery(document).ready(function ($) {
    // --- 変数・要素取得 ---
    const prefix = 'edel_ai_chatbot_plus_'; // Plus版プレフィックスを確認
    const params = window.edel_chatbot_params || window.edel_chatbot_admin_params || {}; // 存在チェック
    const ajax_url = params.ajax_url;
    const nonce = params.nonce; // フロントチャット送信用Nonce
    const chat_action = params.action; // フロントチャット送信用Action

    // フロントチャットUI要素
    const $container = $('#' + prefix + 'widget-container');
    const $openButton = $('#' + prefix + 'open-button');
    const $window = $('#' + prefix + 'window');
    const $closeButton = $('#' + prefix + 'close-button');
    const $maximizeButton = $('#' + prefix + 'maximize-button');
    const $form = $('#' + prefix + 'form');
    const $input = $('#' + prefix + 'input');
    const $history = $('#' + prefix + 'history');
    const $submitButton = $('#' + prefix + 'submit'); // ★IDが submit か submit-button か確認
    const $loading = $('#' + prefix + 'loading');

    // ★★★ 会話履歴用 変数・定数を追加 ★★★
    let chatHistory = []; // 会話履歴を保持する配列
    const MAX_HISTORY = 50; // 保存する最大件数 (50件程度が妥当か)
    const STORAGE_KEY = 'edelChatPlusHistory'; // LocalStorageのキー名 (プラグイン固有の名前に)

    console.log('Edel AI Chatbot (Floating UI) script loaded.');
    loadHistory();

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

        console.log('Before AJAX call.');
        console.log('AJAX URL:', ajax_url);
        console.log('Nonce:', nonce);
        console.log('Action:', chat_action);

        // ====[ Ajax送信 (次のステップ) ]====
        console.log('Sending message:', userMessage);
        $.ajax({
            url: ajax_url, // wp_localize_script で渡したURL
            type: 'POST',
            data: {
                action: 'edel_ai_chatbot_plus_send_message', // wp_localize_script で渡したアクション名
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

                const errorText = 'サーバーとの通信に失敗しました。';
                appendMessage(errorText, 'bot', true);

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
     * ★ 画面のチャット履歴にメッセージを表示する関数 ★
     * (LocalStorageへの保存は行わない)
     * ※もし未作成なら追加、作成済みなら内容を確認
     */
    function renderMessage(message, sender, isError = false) {
        const messageClass = sender === 'user' ? 'edel-chatbot-message-user' : 'edel-chatbot-message-bot';
        let displayMessage = message;
        let errorClass = '';
        // エラーフラグに基づいてクラスと補足メッセージを追加
        // (注意: 履歴から復元する場合、補足メッセージが二重にならないか？
        //        履歴保存時に補足情報を付与しない方が管理しやすいかも)
        if (isError && sender === 'bot') {
            errorClass = ' edel-chatbot-message-error';
            // ★ 履歴データに補足が含まれていない前提なら、ここで追加 ★
            // displayMessage += '\n\n' + '（問題が解決しない場合は～）';
        }
        const $messageDiv = $('<div class="edel-chatbot-message ' + messageClass + errorClass + '"><p></p></div>');
        // text() を使って安全にメッセージを設定 (CSSで改行は処理)
        $messageDiv.find('p').text(displayMessage);
        $history.append($messageDiv);
        // スクロールは loadHistory の最後など、まとめて行う方が効率的
    }

    /**
     * ★★★ 新規追加：LocalStorageから履歴を読み込み、画面に復元する関数 ★★★
     */
    function loadHistory() {
        console.log('loadHistory function called.'); // ★ログ追加
        try {
            const storedHistory = localStorage.getItem(STORAGE_KEY);
            if (storedHistory) {
                // 保存されていた履歴をJSONから配列に戻す
                const parsedHistory = JSON.parse(storedHistory);
                console.log('Parsed history array:', parsedHistory); // ★ログ追加 (パース結果確認)

                // 念のため配列かチェック
                if (Array.isArray(parsedHistory)) {
                    chatHistory = parsedHistory; // グローバル変数を復元した履歴で上書き
                    console.log('Chat history loaded from localStorage. Count:', chatHistory.length);

                    // 画面上の既存メッセージをクリア(もしあれば)
                    $history.html('');

                    console.log('Rendering stored messages...'); // ★ログ追加

                    // 読み込んだ履歴を一件ずつ画面に表示
                    chatHistory.forEach((item) => {
                        // 保存された各メッセージ情報を使って表示関数を呼び出す
                        renderMessage(item.message, item.sender, item.isError || false);
                    });

                    // 履歴表示後、一番下にスクロール
                    $history.scrollTop($history[0].scrollHeight);
                    console.log('History rendered and scrolled.'); // ★ログ追加
                } else {
                    console.warn('Stored chat history is not a valid array.');
                    localStorage.removeItem(STORAGE_KEY); // 不正なデータは削除
                }
            } else {
                console.log('No chat history found in localStorage.');
                // 必要なら、履歴がない場合の初期メッセージをここで表示しても良い
                // renderMessage('こんにちは！何かお手伝いできることはありますか？', 'bot');
            }
        } catch (e) {
            console.error('Failed to load or parse chat history from localStorage:', e);
            // エラーが発生した場合も、壊れたデータを削除しておくのが安全
            localStorage.removeItem(STORAGE_KEY);
            chatHistory = []; // 念のため配列をリセット
        }
    }

    /**
     * メッセージをチャット履歴に追加する関数
     * @param {string} message 表示するメッセージ内容
     * @param {string} sender 送信者 ('user' または 'bot')
     * @param {boolean} [isError=false] エラーメッセージかどうか (ボットからのメッセージの場合のみ有効)
     */
    function appendMessage(message, sender, isError = false) {
        // 1. 履歴配列に追加
        const newMessage = {
            sender: sender,
            message: message,
            isError: isError,
            timestamp: new Date().toISOString() // ★ タイムスタンプも追加 (オプション)
        };
        chatHistory.push(newMessage);

        // 2. 履歴が最大件数を超えたら古いものから削除 (配列の先頭を削除)
        if (chatHistory.length > MAX_HISTORY) {
            chatHistory.shift();
        }

        // 3. 更新された履歴配列をLocalStorageにJSON文字列として保存
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(chatHistory));
            console.log('Chat history saved to localStorage.'); // 保存確認ログ
        } catch (e) {
            console.error('Failed to save chat history to localStorage:', e);
            // LocalStorageがいっぱいなどの場合にエラーになる可能性
        }

        // 4. 画面に表示 (renderMessage を呼び出す)
        renderMessage(message, sender, isError); // ★ 表示処理はこちら

        // 5. スクロール (renderMessage の後 or ここで)
        $history.scrollTop($history[0].scrollHeight);
    } // end appendMessage

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
    //    if ($window.hasClass('is-visible') && !$(event.target).closest('#edel_ai_chatbot_plus_window').length && !$(event.target).closest('#edel_ai_chatbot_plus_open-button').length) {
    //        toggleWindow(false);
    //    }
    // });
});
