<?php

namespace Edel\AiChatbotPlus\Admin;

use Edel\AiChatbotPlus\API\EdelAiChatbotOpenAIAPI;

class EdelAiChatbotAdmin {
    function admin_menu() {
        add_menu_page(
            EDEL_AI_CHATBOT_PLUS_NAME,
            EDEL_AI_CHATBOT_PLUS_NAME,
            'manage_options',
            EDEL_AI_CHATBOT_PLUS_SLUG,
            array($this, 'show_main_page'),
            'dashicons-format-chat',
            10
        );

        add_submenu_page(
            EDEL_AI_CHATBOT_PLUS_SLUG,
            '設定',
            '設定',
            'manage_options',
            'edel-ai-chatbot-setting',
            array($this, 'show_setting_page'),
            1
        );
    }

    function admin_enqueue($hook) {
        $version = (defined('EDEL_AI_CHATBOT_PLUS_DEVELOP') && true === EDEL_AI_CHATBOT_PLUS_DEVELOP) ? time() : EDEL_AI_CHATBOT_PLUS_VERSION;

        wp_register_script(EDEL_AI_CHATBOT_PLUS_SLUG . '-admin', EDEL_AI_CHATBOT_PLUS_URL . '/js/admin.js', array('jquery'), $version);
        wp_register_style(EDEL_AI_CHATBOT_PLUS_SLUG . '-admin',  EDEL_AI_CHATBOT_PLUS_URL . '/css/admin.css', array(), $version);

        // if (strpos($hook, EDEL_AI_CHATBOT_PLUS_SLUG) !== false) {
        //     $params = array('ajaxurl' => admin_url('admin-ajax.php'));
        //     wp_localize_script(EDEL_AI_CHATBOT_PLUS_SLUG . 'admin', 'params', $params );
        //     wp_enqueue_style(EDEL_AI_CHATBOT_PLUS_SLUG . 'admin');
        //     wp_enqueue_script(EDEL_AI_CHATBOT_PLUS_SLUG . 'admin');
        // }
    }

    function plugin_action_links($links) {
        $url = '<a href="' . esc_url(admin_url("/admin.php?page=edel-ai-chatbot-plus")) . '">設定</a>';
        array_unshift($links, $url);
        return $links;
    }

    /**
     * 固定長チャンキング（オーバーラップ付き）を行うプライベートメソッド
     *
     * @param string $text 対象テキスト (UTF-8)
     * @param int $chunk_size チャンクサイズ (文字数)
     * @param int $chunk_overlap オーバーラップさせる文字数
     * @return array 分割されたテキストチャンクの配列
     */
    private function chunk_text_fixed_length(string $text, int $chunk_size = 500, int $chunk_overlap = 50): array {
        // 入力テキストが空なら空配列を返す
        if (mb_strlen($text, 'UTF-8') === 0) {
            return [];
        }
        // オーバーラップがチャンクサイズ以上にならないように調整 (例: サイズの10%)
        if ($chunk_overlap >= $chunk_size) {
            $chunk_overlap = intval($chunk_size / 10);
        }
        // チャンクサイズより小さいテキストはそのまま返す
        if (mb_strlen($text, 'UTF-8') <= $chunk_size) {
            return [$text];
        }

        $chunks = [];
        $text_length = mb_strlen($text, 'UTF-8');
        $start = 0;

        while ($start < $text_length) {
            // チャンクの終了位置を計算 (テキストの終端を超えないように)
            $end = min($start + $chunk_size, $text_length);
            // テキストを部分文字列として取得 (チャンク)
            $chunks[] = mb_substr($text, $start, $end - $start, 'UTF-8');

            // 次のチャンクの開始位置を計算 (オーバーラップ分戻る)
            $next_start = $start + $chunk_size - $chunk_overlap;

            // 次の開始位置が現在と同じか前になる場合 (無限ループ防止)、または終端に達したらループ終了
            if ($next_start <= $start || $next_start >= $text_length) {
                break;
            }
            $start = $next_start;
        }
        return $chunks;
    }


    /**
     * メインページ（学習データ登録）を表示するメソッド
     */
    function show_main_page() {
        // --- データ登録処理 ---
        global $wpdb;
        $table_name = $wpdb->prefix . EDEL_AI_CHATBOT_PLUS_PREFIX . 'vectors';
        $nonce_action_learn = EDEL_AI_CHATBOT_PLUS_PREFIX . 'learn_data_nonce';
        $submit_name_learn  = EDEL_AI_CHATBOT_PLUS_PREFIX . 'submit_learn_data';

        // フォームが送信され、Nonceが有効かチェック
        if (isset($_POST[$submit_name_learn]) && check_admin_referer($nonce_action_learn)) {

            $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
            $options = get_option($option_name, []);
            $api_key = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] ?? '';

            if (!empty($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_text'])) {
                // 1. テキスト取得 & 前処理
                // $_POST の値は add_magic_quotes の影響を受けることがあるため wp_unslash() を通すのが安全
                $raw_text = wp_unslash($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_text']);
                $clean_text = strip_tags($raw_text);
                $clean_text = trim($clean_text);

                if (!empty($clean_text)) {
                    // 2. テキストチャンキング
                    $chunk_size = 500;
                    $chunk_overlap = 50;
                    $text_chunks = $this->chunk_text_fixed_length($clean_text, $chunk_size, $chunk_overlap);

                    if (!empty($text_chunks)) {
                        echo '<div class="notice notice-info is-dismissible"><p>' . count($text_chunks) . ' 個のテキストチャンクに分割されました。ベクトル化とDB保存を開始します...</p></div>';

                        // ★OpenAI API連携クラスを読み込み、インスタンス化★
                        require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-openai-api.php'; // ファイルパスを確認
                        $openai_api = new EdelAiChatbotOpenAIAPI($api_key);

                        $success_count = 0;
                        $error_count = 0;
                        $db_error_count = 0;

                        // ★ループしてベクトル化とDB保存★
                        foreach ($text_chunks as $index => $chunk) {
                            // 3. ベクトル化
                            $embedding_result = $openai_api->get_embedding($chunk);

                            if (is_wp_error($embedding_result)) {
                                // APIエラー
                                echo '<div class="notice notice-error is-dismissible"><p>チャンク ' . ($index + 1) . ' のベクトル化に失敗しました: ' . esc_html($embedding_result->get_error_message()) . '</p></div>';
                                $error_count++;
                                continue; // 次のチャンクへ
                            }

                            // 4. DB保存
                            $vector_json = json_encode($embedding_result); // ベクトル配列をJSON文字列に変換

                            $inserted = $wpdb->insert(
                                $table_name,
                                [
                                    'source_text' => $chunk,
                                    'vector_data' => $vector_json
                                ],
                                [
                                    '%s', // source_text は文字列
                                    '%s'  // vector_data も文字列 (JSON)
                                ]
                            );

                            if ($inserted === false) {
                                // DB挿入エラー
                                echo '<div class="notice notice-error is-dismissible"><p>チャンク ' . ($index + 1) . ' のデータベース保存に失敗しました。DBエラー: ' . esc_html($wpdb->last_error) . '</p></div>';
                                $db_error_count++;
                            } else {
                                $success_count++;
                            }

                            // （オプション）レートリミット対策で少し待機する
                            // usleep(200000); // 0.2秒待機 (200,000マイクロ秒)
                        }

                        // 処理結果のサマリーを表示
                        echo '<div class="notice notice-success is-dismissible"><p>処理完了: ' . $success_count . ' 個のチャンクをベクトル化しDBに保存しました。</p></div>';
                        if ($error_count > 0) {
                            echo '<div class="notice notice-warning is-dismissible"><p>' . $error_count . ' 個のチャンクでベクトル化エラーが発生しました。</p></div>';
                        }
                        if ($db_error_count > 0) {
                            echo '<div class="notice notice-warning is-dismissible"><p>' . $db_error_count . ' 個のチャンクでデータベース保存エラーが発生しました。</p></div>';
                        }
                    } else {
                        echo '<div class="notice notice-warning is-dismissible"><p>テキストをチャンクに分割できませんでした。</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-warning is-dismissible"><p>入力されたテキストが空か、HTMLタグを除去したら空になりました。</p></div>';
                }
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>学習させるテキストを入力してください。</p></div>';
            }
        } // --- データ登録処理 ここまで ---
        // --- フォーム表示 ---
?>
        <div class="wrap">
            <h1><?php echo esc_html(EDEL_AI_CHATBOT_PLUS_NAME); ?> - 学習データ登録</h1>
            <p>ここにチャットボットに学習させたいテキスト（製品情報、FAQ、マニュアルなど）を貼り付けてください。HTMLタグは自動的に除去されます。</p>
            <form method="POST">
                <?php wp_nonce_field($nonce_action_learn); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_text'; ?>">学習テキスト</label></th>
                        <td>
                            <textarea id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_text'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_text'; ?>"
                                rows="15"
                                class="large-text"
                                placeholder="ここにテキストを貼り付け..."></textarea>
                            <p class="description">入力されたテキストはチャンク（断片）に分割され、ベクトル化されてデータベースに保存されます。</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit"
                        name="<?php echo $submit_name_learn; ?>"
                        class="button button-primary"
                        value="学習データを登録・ベクトル化">
                    <?php // 注意：現時点ではベクトル化とDB保存は行われません
                    ?>
                </p>
            </form>
        </div>
    <?php
    } // end show_main_page()

    function show_setting_page() {
        global $wpdb; // $wpdb を使うために追加
        $vector_table_name = $wpdb->prefix . EDEL_AI_CHATBOT_PLUS_PREFIX . 'vectors'; // テーブル名

        // --- ★★★ 全データ削除処理 ★★★ ---
        $nonce_action_delete = EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_all_vectors_nonce';
        if (isset($_POST['delete_all_vectors']) && check_admin_referer($nonce_action_delete)) {
            $result = $wpdb->query("TRUNCATE TABLE {$vector_table_name}");

            if ($result !== false) {
                echo '<div class="notice notice-success is-dismissible"><p>全ての学習データ（ベクトル）を削除しました。</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>学習データの削除に失敗しました。データベースエラー: ' . esc_html($wpdb->last_error) . '</p></div>';
            }
        }

        // --- 1. 保存処理 ---
        $nonce_action = EDEL_AI_CHATBOT_PLUS_PREFIX . 'save_settings_nonce'; // Nonceアクション名
        $submit_name  = EDEL_AI_CHATBOT_PLUS_PREFIX . 'submit_settings';   // 送信ボタンのname属性値

        if (isset($_POST[$submit_name]) && check_admin_referer($nonce_action)) {
            // 既存の設定値を取得 (なければ空配列)
            $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
            $options = get_option($option_name, []);

            $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'enabled'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'enabled']) ? '1' : '0';
            $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open']) ? '1' : '0';
            $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_on_uninstall'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_on_uninstall']) ? '1' : '0';

            if (isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'])) {
                $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] = sanitize_text_field(trim($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key']));
            } else {
                $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] = '';
            }

            if (isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'])) {
                $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'] = sanitize_text_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title']);
            } else {
                $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'] = 'AIチャットボット'; // デフォルト値
            }

            if (isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'])) {
                // ある程度のHTMLを許可する場合は wp_kses_post, 単純なテキストなら sanitize_textarea_field
                $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'] = sanitize_textarea_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message']);
            } else {
                $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'] = 'こんにちは！何かお手伝いできることはありますか？'; // デフォルト値
            }

            // (例) モデル選択
            if (isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'])) {
                // 想定されるモデルかチェックするとなお良い
                $allowed_models = ['gpt-4', 'gpt-4-turbo', 'gpt-3.5-turbo']; // 例
                if (in_array($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'], $allowed_models)) {
                    $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'] = sanitize_text_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model']);
                } else {
                    $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'] = 'gpt-3.5-turbo'; // 不正な値ならデフォルトに
                }
            } else {
                $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'] = 'gpt-3.5-turbo'; // 未送信ならデフォルトに
            }

            // (例) エラー時メッセージ
            if (isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'])) {
                $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'] = sanitize_text_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message']);
            } else {
                $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'] = '';
            }

            // オプションをデータベースに保存
            update_option($option_name, $options);

            // 保存完了メッセージを表示
            echo '<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>';
        } // --- 保存処理 ここまで ---


        // --- 2. 設定値の読み込み ---
        $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
        $options = get_option($option_name, []);

        $header_title     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'] ?? 'AIチャットボット';
        $greeting_message = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'] ?? 'こんにちは！何かお手伝いできることはありますか？';

        // 各設定値を変数に格納 (デフォルト値も指定)
        $api_key        = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] ?? '';
        $selected_model = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'] ?? 'gpt-3.5-turbo'; // デフォルトモデル
        $error_message  = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'] ?? 'エラーが発生しました。お手数ですが管理者にお問い合わせください。'; // デフォルトメッセージ
        $is_enabled = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'enabled'] ?? '1';
        $default_open   = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open'] ?? '0'; // デフォルトは閉じた状態 ('0')
        $delete_on_uninstall = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_on_uninstall'] ?? '0'; // デフォルトは削除しない ('0')

        // --- 3. フォーム表示 ---
    ?>
        <div class="wrap">
            <h1><?php echo esc_html(EDEL_AI_CHATBOT_PLUS_NAME); ?> 設定</h1>
            <form method="POST">
                <?php wp_nonce_field($nonce_action); ?>
                <h2>基本設定</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'enabled'; ?>">チャットボット有効化</label></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                    id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'enabled'; ?>"
                                    name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'enabled'; ?>"
                                    value="1"
                                    <?php checked($is_enabled, '1'); ?>>
                                サイト全体でチャットボット機能を表示する
                            </label>
                            <p class="description">チェックを外すと、サイト上にチャットボットが表示されなくなります。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'; ?>">OpenAI APIキー</label></th>
                        <td>
                            <input type="password"
                                id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'; ?>"
                                value="<?php echo esc_attr($api_key); ?>"
                                class="regular-text">
                            <p class="description">OpenAIのAPIキーを入力してください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'; ?>">OpenAI モデル</label></th>
                        <td>
                            <?php
                            $models = ['gpt-4' => 'GPT-4', 'gpt-4-turbo' => 'GPT-4 Turbo', 'gpt-3.5-turbo' => 'GPT-3.5 Turbo']; // 選択肢
                            ?>
                            <select id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'; ?>">
                                <?php foreach ($models as $value => $label) : ?>
                                    <option value='<?php echo esc_attr($value); ?>' <?php selected($selected_model, $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">利用するOpenAIのモデルを選択してください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'; ?>">チャットヘッダータイトル</label></th>
                        <td>
                            <input type="text"
                                id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'; ?>"
                                value="<?php echo esc_attr($header_title); ?>"
                                class="regular-text">
                            <p class="description">チャットウィンドウ上部に表示されるタイトルです。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'; ?>">最初の挨拶メッセージ</label></th>
                        <td>
                            <textarea id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'; ?>"
                                rows="3"
                                class="large-text"><?php echo esc_textarea($greeting_message); ?></textarea>
                            <p class="description">チャットを開いた時に最初にボットが表示するメッセージです。改行も反映されます。</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'; ?>">エラー時メッセージ</label></th>
                        <td>
                            <input type="text"
                                id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'; ?>"
                                value="<?php echo esc_attr($error_message); ?>"
                                class="regular-text">
                            <p class="description">チャットボットでエラーが発生した際に表示するメッセージです。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open'; ?>">初期表示</label></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                    id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open'; ?>"
                                    name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open'; ?>"
                                    value="1"
                                    <?php checked($default_open, '1'); // 保存された値が '1' ならチェック状態にする
                                    ?>>
                                ページ読み込み時にチャットウィンドウを最初から開いておく
                            </label>
                            <p class="description">チェックを入れない場合、ユーザーがアイコンをクリックするまでウィンドウは閉じた状態です。</p>
                        </td>
                    </tr>
                </table>

                <hr>
                <h2>アンインストール設定</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_on_uninstall'; ?>">データ削除</label></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                    id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_on_uninstall'; ?>"
                                    name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_on_uninstall'; ?>"
                                    value="1"
                                    <?php checked($delete_on_uninstall, '1'); ?>>
                                <span style="color: red; font-weight: bold;">プラグイン削除時に、データベーステーブルと設定情報を完全に削除する</span>
                            </label>
                            <p class="description">
                                <span style="color: red;">注意: このオプションを有効にすると、WordPress管理画面からこのプラグインを「削除」した際に、保存された学習データ（ベクトルテーブル）とこのプラグインの設定（オプション）が完全に失われ、元に戻すことはできません。</span><br>
                                プラグインを一時的に「停止」するだけではデータは削除されません。通常はこのオプションを有効にする必要はありません。
                            </p>
                        </td>
                    </tr>
                </table>

                <hr>
                <h2>データ送信に関する注意</h2>
                <p class="description">
                    このチャットボットはOpenAI APIを利用しています。ユーザーが入力した質問や、場合によっては学習データの一部が応答生成のためにOpenAI社のサーバーに送信される可能性があります。詳細については、操作ガイドおよびOpenAIの利用規約をご確認ください。
                </p>

                <p class="submit">
                    <input type="submit"
                        name="<?php echo $submit_name; ?>"
                        class="button button-primary"
                        value="設定を保存">
                </p>
            </form>

            <hr>

            <h2>学習データ管理</h2>
            <form method="POST" onsubmit="return confirm('本当に全ての学習データ（ベクトル）を削除しますか？この操作は元に戻せません。');">
                <?php wp_nonce_field($nonce_action_delete); // 削除用Nonce
                ?>
                <p>データベースに保存されている学習データ（ベクトル化されたテキストチャンク）を全て削除します。</p>
                <p class="submit">
                    <input type="submit"
                        name="delete_all_vectors"
                        class="button button-secondary"
                        value="全学習データを削除">
                </p>
            </form>

        </div>
<?php
    } // end show_setting_page()

    public static function create_custom_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . EDEL_AI_CHATBOT_PLUS_PREFIX . 'vectors';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // SQL文の作成
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_text longtext NOT NULL,
            vector_data longtext NOT NULL, /* ベクトルデータをJSON文字列として保存想定 */
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // dbDelta 関数でテーブルを作成・更新
        dbDelta($sql);
    }
}
