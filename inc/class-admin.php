<?php

namespace Edel\AiChatbotPlus\Admin;

use Edel\AiChatbotPlus\API\EdelAiChatbotOpenAIAPI;
use \WP_Error;

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

        $setting_page_hook = EDEL_AI_CHATBOT_PLUS_SLUG . '_page_' . 'edel-ai-chatbot-setting'; // 親スラッグ_page_サブスラッグ

        // ★★★ 現在のページが「設定」サブメニューページかどうかを完全一致で判定 ★★★
        if ($hook === $setting_page_hook) {

            $version = (defined('EDEL_AI_CHATBOT_PLUS_DEVELOP') && true === EDEL_AI_CHATBOT_PLUS_DEVELOP) ? time() : EDEL_AI_CHATBOT_PLUS_VERSION;

            // スタイルとスクリプトを登録 (登録はページに関わらず行っても良いが、ここでまとめてもOK)
            wp_register_style(EDEL_AI_CHATBOT_PLUS_SLUG . '-admin', EDEL_AI_CHATBOT_PLUS_URL . '/css/admin.css', array(), $version);
            wp_register_script(EDEL_AI_CHATBOT_PLUS_SLUG . '-admin', EDEL_AI_CHATBOT_PLUS_URL . '/js/admin.js', array('jquery'), $version, true); // フッター読み込み推奨

            // スタイルとスクリプトを読み込み (エンキュー)
            wp_enqueue_style(EDEL_AI_CHATBOT_PLUS_SLUG . '-admin');
            wp_enqueue_script(EDEL_AI_CHATBOT_PLUS_SLUG . '-admin');

            // JSにデータを渡す (wp_localize_script)
            $batch_nonce = wp_create_nonce(EDEL_AI_CHATBOT_PLUS_PREFIX . 'batch_learning_nonce'); // Ajaxハンドラで検証するアクション名
            $localized_data = array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => $batch_nonce,
                'action'  => EDEL_AI_CHATBOT_PLUS_PREFIX . 'batch_learning' // JSから送るAjaxアクション名
            );
            wp_localize_script(EDEL_AI_CHATBOT_PLUS_SLUG . '-admin', 'edel_chatbot_admin_params', $localized_data);
        }
    }

    function plugin_action_links($links) {
        $url = '<a href="' . esc_url(admin_url("/admin.php?page=edel-ai-chatbot-plus")) . '">設定</a>';
        array_unshift($links, $url);
        return $links;
    }

    public function handle_batch_learning_ajax() {
        // 1. Nonce検証 (JSから送られてくる nonce を検証)
        //    wp_localize_script で指定した Nonce アクション名と合わせる
        check_ajax_referer(EDEL_AI_CHATBOT_PLUS_PREFIX . 'batch_learning_nonce', 'nonce');

        // 2. ステップを判別
        $step = isset($_POST['step']) ? sanitize_key($_POST['step']) : '';

        try {
            if ($step === 'get_list') {
                // --- ステップ1: 学習対象リストの取得と一時保存 ---

                // 設定値を取得
                $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
                $options = get_option($option_name, []);
                $learning_post_types = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_post_types'] ?? ['post', 'page'];
                $learning_categories = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_categories'] ?? [];
                $exclude_ids_str     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'exclude_ids'] ?? '';
                $exclude_ids         = !empty($exclude_ids_str) ? array_map('intval', explode(',', $exclude_ids_str)) : [];

                // get_posts 引数準備
                $args = [ /* ... (以前 show_setting_page で組んだものと同じ) ... */
                    'post_type'      => $learning_post_types,
                    'post_status'    => 'publish',
                    'has_password'   => false,
                    'posts_per_page' => -1, // 全件取得
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                ];
                if (!empty($learning_categories)) {
                    $args['category__in'] = $learning_categories;
                }
                if (!empty($exclude_ids)) {
                    $args['post__not_in'] = $exclude_ids;
                }

                // 投稿IDリスト取得
                $post_ids = get_posts($args);
                $total_items = count($post_ids);

                if ($total_items > 0) {
                    // ★ 取得したIDリストをTransient APIで一時保存 ★
                    // キー名はユーザーごとにユニークにする (他のユーザーと干渉しないように)
                    $transient_key = EDEL_AI_CHATBOT_PLUS_PREFIX . 'batch_ids_' . get_current_user_id();
                    // 有効期限を مثلاً 1時間 (3600秒) に設定
                    set_transient($transient_key, $post_ids, HOUR_IN_SECONDS);
                }

                // フロントエンドに応答を返す
                wp_send_json_success([
                    'status'      => 'list_received',
                    'total_items' => $total_items,
                    'log_message' => $total_items > 0 ? $total_items . '件の学習対象が見つかりました。処理を開始します。' : '学習対象が見つかりませんでした。'
                ]);
            } elseif ($step === 'process_item') {
                // --- ステップ2: 個別アイテムの処理 ---

                // offset (何番目のアイテムから処理するか) を取得
                $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
                // 1回のリクエストで処理する件数 (今回は1件ずつ)
                $limit = 1;

                // TransientからIDリストを取得
                $transient_key = EDEL_AI_CHATBOT_PLUS_PREFIX . 'batch_ids_' . get_current_user_id();
                $post_ids = get_transient($transient_key);

                if ($post_ids === false || !is_array($post_ids)) {
                    throw new \Exception('一時保存された学習対象リストが見つかりませんでした。最初からやり直してください。');
                }

                $total_items = count($post_ids);

                // 今回処理するIDを取得 (配列の $offset 番目から $limit 件)
                $items_to_process = array_slice($post_ids, $offset, $limit);

                if (empty($items_to_process)) {
                    // 処理するアイテムがない場合 = 完了
                    delete_transient($transient_key); // 不要になったTransientを削除
                    wp_send_json_success([
                        'status' => 'complete',
                        'log_message' => '全ての処理が完了しました。'
                    ]);
                } else {
                    // ★ 処理を実行 (process_single_post_learning メソッドを呼び出す) ★
                    $processed_id = $items_to_process[0]; // 今回は1件だけ
                    $result = $this->process_single_post_learning(['post_id' => $processed_id]);

                    $next_offset = $offset + $limit;
                    $is_complete = ($next_offset >= $total_items);
                    if ($is_complete) {
                        delete_transient($transient_key); // 完了したらTransient削除
                    }

                    // フロントエンドに応答を返す
                    wp_send_json_success([
                        'status'          => $is_complete ? 'complete' : 'processing',
                        'offset'          => $next_offset, // 次のリクエストで使うオフセット
                        'processed_count' => count($items_to_process), // 今回処理した件数
                        'log_message'     => '投稿ID ' . $processed_id . ' の処理を実行しました。' . ($is_complete ? ' 全工程完了。' : '')
                    ]);
                }
            } else {
                // 不明なステップ
                throw new \Exception('無効な処理ステップです。');
            }
        } catch (\Exception $e) {
            // エラー発生時の処理
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Batch Learning AJAX Error: ' . $e->getMessage());
            // 設定されたエラーメッセージを取得して返す
            $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
            $options = get_option($option_name, []);
            $error_message_setting = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'] ?? 'エラーが発生しました。';
            wp_send_json_error(['message' => $error_message_setting . ' (詳細: ' . $e->getMessage() . ')']); // エラー詳細も少し含める
        }

        // Ajaxハンドラは通常 wp_send_json_* で終了するため、最後に wp_die() は不要
    } // end handle_batch_learning_ajax

    /**
     * [Action Scheduler Callback / Batch Process Unit] 指定された投稿IDの学習処理を実行する
     * (最終更新日時をチェックし、更新がある場合のみ処理)
     *
     * @param array $args Action Schedulerから渡される引数 (['post_id' => ID])
     */
    public function process_single_post_learning(array $args) { // ★ アクセス権限を public に変更推奨
        if (!isset($args['post_id']) || !is_numeric($args['post_id'])) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Invalid arguments passed to process_single_post_learning.');
            return; // 引数が不正なら終了
        }
        $post_id = (int) $args['post_id'];

        global $wpdb;
        $vector_table_name = $wpdb->prefix . EDEL_AI_CHATBOT_PLUS_PREFIX . 'vectors';
        $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
        $options = get_option($option_name, []);
        $api_key = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] ?? '';

        if (empty($api_key)) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Error: API Key not found for post ID: ' . $post_id);
            return;
        }

        try {
            // 1. 投稿オブジェクト取得と最終更新日時取得
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish' || !empty($post->post_password)) {
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Info: Skipping post ID: ' . $post_id . ' (Not found, not published, or password protected).');
                return; // 対象外ならスキップ
            }
            // WordPressの投稿更新日時 (GMT) を取得
            $current_modified_gmt = $post->post_modified_gmt;
            // $current_modified = $post->post_modified; // サイト設定のタイムゾーン日時

            // 2. DBに保存されている最終更新日時を取得
            $db_modified_gmt = $wpdb->get_var($wpdb->prepare(
                "SELECT source_post_modified FROM {$vector_table_name} WHERE source_post_id = %d ORDER BY id DESC LIMIT 1", // LIMIT 1 で最新のものを取得
                $post_id
            ));

            // 3. 更新日時の比較
            // $db_modified_gmt が null (DBにない) か、現在の更新日時の方が新しい場合に処理実行
            if ($db_modified_gmt === null || strtotime($current_modified_gmt) > strtotime($db_modified_gmt)) {

                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Processing post ID: ' . $post_id . ' (Needs update or first time)');

                // ★★★ 4. 古いデータの削除 (更新の場合) ★★★
                if ($db_modified_gmt !== null) {
                    error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Deleting old vectors for post ID: ' . $post_id);
                    $wpdb->delete($vector_table_name, ['source_post_id' => $post_id], ['%d']);
                }

                // 5. コンテンツ取得・前処理
                $content = apply_filters('the_content', $post->post_content);
                $clean_content = trim(wp_strip_all_tags(strip_shortcodes($content)));

                if (empty($clean_content)) {
                    error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Info: Content is empty after cleaning for post ID: ' . $post_id);
                    return; // コンテンツがなければ終了
                }

                // 6. チャンキング
                $chunk_size = 500;
                $chunk_overlap = 50;
                $text_chunks = $this->chunk_text_fixed_length($clean_content, $chunk_size, $chunk_overlap); //★アクセス権限注意

                if (empty($text_chunks)) {
                    error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Info: No chunks generated for post ID: ' . $post_id);
                    return; // チャンクがなければ終了
                }

                // 7. APIクライアント準備
                // ★ require_once は最初の一回だけで良いように工夫推奨 ★
                require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-openai-api.php';
                $openai_api = new \Edel\AiChatbotPlus\API\EdelAiChatbotOpenAIAPI($api_key); // use宣言があれば短い名前で

                // 8. チャンクループ: ベクトル化とDB保存
                $post_chunks_saved = 0;
                $post_errors = 0;
                foreach ($text_chunks as $chunk) {
                    try {
                        $vector = $openai_api->get_embedding($chunk);
                        if (is_wp_error($vector)) {
                            throw new \Exception($vector->get_error_message());
                        }

                        $vector_json = json_encode($vector);
                        $inserted = $wpdb->insert(
                            $vector_table_name,
                            [
                                'source_post_id' => $post_id,
                                'source_post_modified' => $current_modified_gmt, // ★ 現在の更新日時を保存
                                'source_text'    => $chunk,
                                'vector_data'    => $vector_json
                            ],
                            ['%d', '%s', '%s', '%s'] // ★ フォーマットに %s (datetime) を追加
                        );
                        if ($inserted === false) {
                            throw new \Exception('DB保存失敗: ' . $wpdb->last_error);
                        }
                        $post_chunks_saved++;
                        // usleep(200000);

                    } catch (\Exception $chunk_error) {
                        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Chunk processing error for post ID ' . $post_id . ': ' . $chunk_error->getMessage());
                        $post_errors++;
                    }
                } // end foreach chunk

                $log_message = sprintf('%d チャンク保存完了', $post_chunks_saved);
                if ($post_errors > 0) {
                    $log_message .= sprintf(' (%d エラー)', $post_errors);
                }
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Finished processing updated post ID: ' . $post_id . '. ' . $log_message);
                // ★ 正常処理完了の戻り値 ★
                return ['status' => 'processed', 'message' => $log_message];
            } else {
                // 更新されていない場合はスキップ
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Skipping post ID: ' . $post_id . ' (Not modified)');
                return ['status' => 'skipped', 'message' => '更新なし (スキップ)'];
            }
        } catch (\Exception $e) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Error processing post ID ' . $post_id . ': ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'エラー: ' . $e->getMessage()];
        }
    } // end process_single_post_learning

    /**
     * 固定長チャンキング（オーバーラップ付き）を行うプライベートメソッド
     *
     * @param string $text 対象テキスト (UTF-8)
     * @param int $chunk_size チャンクサイズ (文字数)
     * @param int $chunk_overlap オーバーラップさせる文字数
     * @return array 分割されたテキストチャンクの配列
     */
    public function chunk_text_fixed_length(string $text, int $chunk_size = 500, int $chunk_overlap = 50) {
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
        global $wpdb;
        $vector_table_name = $wpdb->prefix . EDEL_AI_CHATBOT_PLUS_PREFIX . 'vectors';
        $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';

        // --- 設定保存ボタンが押された場合の処理 ---
        $nonce_action_settings = EDEL_AI_CHATBOT_PLUS_PREFIX . 'save_settings_nonce'; // 設定保存用 Nonce アクション名
        $submit_name_settings  = EDEL_AI_CHATBOT_PLUS_PREFIX . 'submit_settings';   // 設定保存用 送信ボタンの name 属性値
        $nonce_action_delete   = EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_all_vectors_nonce'; // 全削除用 Nonce アクション名
        $submit_name_delete    = 'delete_all_vectors';                                  // 全削除用 送信ボタンの name 属性値
        $button_id_learn       = EDEL_AI_CHATBOT_PLUS_PREFIX . 'start-learning-button';
        $ajax_action_learn     = EDEL_AI_CHATBOT_PLUS_PREFIX . 'batch_learning'; // JSで使うAjaxアクション名

        // --- 全データ削除ボタンが押された場合の処理 ---
        if (isset($_POST['delete_all_vectors']) && check_admin_referer($nonce_action_delete)) {
            // TRUNCATE TABLE でテーブル内容を全て削除
            $result = $wpdb->query("TRUNCATE TABLE {$vector_table_name}");

            // 処理結果をメッセージで表示
            if ($result !== false) {
                echo '<div class="notice notice-success is-dismissible"><p>全ての学習データ（ベクトル）を削除しました。</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>学習データの削除に失敗しました。データベースエラー: ' . esc_html($wpdb->last_error) . '</p></div>';
            }
        } // --- 全データ削除処理 ここまで ---

        if (isset($_POST[$submit_name_settings]) && check_admin_referer($nonce_action_settings)) {
            // 現在のオプション値を取得（なければ空配列）
            $options_to_save = get_option($option_name, []);

            // 各設定項目を $_POST から取得し、サニタイズして配列に格納
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'enabled'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'enabled']) ? '1' : '0';
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open']) ? '1' : '0';
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_on_uninstall'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_on_uninstall']) ? '1' : '0';

            // APIキー (サニタイズ + trim)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'])
                ? sanitize_text_field(trim($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key']))
                : '';

            // ヘッダータイトル (サニタイズ)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'])
                ? sanitize_text_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'])
                : 'AIチャットボット'; // デフォルト値

            // 挨拶メッセージ (サニタイズ)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'])
                ? sanitize_textarea_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'])
                : 'こんにちは！何かお手伝いできることはありますか？'; // デフォルト値

            // モデル選択 (サニタイズ + 許可リストチェック)
            if (isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'])) {
                $allowed_models = ['gpt-4', 'gpt-4-turbo', 'gpt-3.5-turbo'];
                $selected_model = sanitize_text_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model']);
                $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'] = in_array($selected_model, $allowed_models) ? $selected_model : 'gpt-3.5-turbo';
            } else {
                $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'] = 'gpt-3.5-turbo'; // デフォルト値
            }

            // エラー時メッセージ (サニタイズ)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'])
                ? sanitize_text_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'])
                : 'エラーが発生しました。お手数ですが管理者にお問い合わせください。'; // デフォルト値

            // 対象投稿タイプ (配列サニタイズ)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_post_types'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_post_types']) && is_array($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_post_types'])
                ? array_map('sanitize_key', $_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_post_types'])
                : ['post', 'page']; // デフォルト値

            // 対象カテゴリ (配列サニタイズ - 数値のみ)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_categories'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_categories']) && is_array($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_categories'])
                ? array_map('intval', $_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_categories'])
                : []; // デフォルトは空

            // 除外ID (文字列サニタイズ -> 数値配列 -> 文字列)
            if (isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'exclude_ids'])) {
                $raw_ids = sanitize_textarea_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'exclude_ids']);
                $ids = array_map('trim', explode(',', $raw_ids));
                $numeric_ids = array_filter($ids, 'is_numeric');
                // intvalを適用してからimplode (0や空を除外するためarray_filterを通す)
                $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'exclude_ids'] = implode(',', array_filter(array_map('intval', $numeric_ids)));
            } else {
                $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'exclude_ids'] = ''; // デフォルトは空
            }

            // ★★★ Pinecone 設定の保存処理を追加 ★★★
            // APIキー (テキスト、前後の空白除去)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'])
                ? sanitize_text_field(trim($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key']))
                : '';
            // 環境名 (通常 英小文字・数字・ハイフンなので sanitize_key)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'])
                ? sanitize_key(trim($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment']))
                : '';
            // インデックス名 (通常 英小文字・数字・ハイフンなので sanitize_key)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'])
                ? sanitize_key(trim($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name']))
                : '';
            // ホストURL (URLとして適切かチェックしつつ保存)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'])
                ? esc_url_raw(trim($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'])) // DB保存用には esc_url_raw が推奨
                : '';

            // データベースにオプションを保存
            update_option($option_name, $options_to_save);

            // 保存完了メッセージを表示
            echo '<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>';
        } // --- 設定保存処理 ここまで ---


        // --- 設定値の読み込み (フォーム表示用) ---
        // ★★★ 常に最新の値をDBから読み込む ★★★
        $options = get_option($option_name, []);
        // 各設定値を変数に格納 (デフォルト値も指定)
        $is_enabled = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'enabled'] ?? '1';
        $api_key        = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] ?? '';
        $selected_model = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'] ?? 'gpt-3.5-turbo';
        $header_title     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'] ?? 'AIチャットボット';
        $greeting_message = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'] ?? 'こんにちは！何かお手伝いできることはありますか？';
        $error_message  = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'] ?? 'エラーが発生しました。お手数ですが管理者にお問い合わせください。';
        $default_open   = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open'] ?? '0';
        $delete_on_uninstall = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_on_uninstall'] ?? '0';
        $learning_post_types = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_post_types'] ?? ['post', 'page'];
        $learning_categories = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_categories'] ?? [];
        $exclude_ids_str     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'exclude_ids'] ?? '';

        $pinecone_api_key     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'] ?? '';
        $pinecone_environment = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'] ?? '';
        $pinecone_index_name  = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'] ?? '';
        $pinecone_host        = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'] ?? '';
        // ★★★ 読み込み処理ここまで ★★★
    ?>
        <div class="wrap">
            <h1><?php echo esc_html(EDEL_AI_CHATBOT_PLUS_NAME); ?> 設定</h1>
            <form method="POST">
                <?php wp_nonce_field($nonce_action_settings); ?>
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
                <h2>自動学習設定 (Plus版機能)</h2>
                <p>サイト内のコンテンツを自動的に読み込み、チャットボットの学習データとして登録するための設定です。（実際の学習実行機能は開発中です）</p>
                <table class="form-table">
                    <tr>
                        <th>対象投稿タイプ</th>
                        <td>
                            <?php
                            $post_types = get_post_types(['public' => true], 'objects');
                            foreach ($post_types as $post_type) {
                                if ($post_type->name === 'attachment') continue; // 添付ファイルは除外
                                $checked = in_array($post_type->name, $learning_post_types) ? 'checked' : '';
                                echo "<label style='margin-right: 15px;'><input type='checkbox' name='" . EDEL_AI_CHATBOT_PLUS_PREFIX . "learning_post_types[]' value='" . esc_attr($post_type->name) . "' " . $checked . "> " . esc_html($post_type->label) . "</label>";
                            }
                            ?>
                            <p class="description">学習対象とする投稿タイプを選択してください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>対象カテゴリー</th>
                        <td>
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px;">
                                <?php
                                $categories = get_categories(['hide_empty' => false, 'orderby' => 'name']);
                                echo "<label><input type='checkbox' class='edel-cat-select-all'> <strong>全て選択/解除</strong></label><hr style='margin: 5px 0;'>"; // 全選択チェックボックス (JSが必要)
                                foreach ($categories as $category) {
                                    $checked = in_array($category->term_id, $learning_categories) ? 'checked' : '';
                                    echo "<label style='display: block;'><input type='checkbox' name='" . EDEL_AI_CHATBOT_PLUS_PREFIX . "learning_categories[]' value='" . esc_attr($category->term_id) . "' " . $checked . "> " . esc_html($category->name) . "</label>";
                                    // 子カテゴリ対応が必要な場合は、階層表示の工夫が必要
                                }
                                ?>
                            </div>
                            <p class="description">学習対象とするカテゴリーを選択してください。何も選択しない場合は全てのカテゴリーが対象になります。</p>
                            <?php // 全選択/解除用の簡単なJS例 (admin_enqueueで読み込む必要あり)
                            ?>
                            <script>
                                jQuery(document).ready(function($) {
                                    $('.edel-cat-select-all').on('change', function() {
                                        $(this).closest('div').find('input[type="checkbox"]').not(this).prop('checked', this.checked);
                                    });
                                });
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'exclude_ids'; ?>">除外する投稿/ページID</label></th>
                        <td>
                            <textarea id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'exclude_ids'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'exclude_ids'; ?>"
                                rows="3"
                                class="large-text"
                                placeholder="例: 10, 25, 130"><?php echo esc_textarea($exclude_ids_str); ?></textarea>
                            <p class="description">学習対象から除外したい投稿や固定ページのIDをカンマ区切りで入力してください。</p>
                        </td>
                    </tr>
                </table>

                <hr>
                <h2>Pinecone連携設定 (Plus版機能)</h2>
                <p>ベクトルデータの保存と高速な類似検索のために、<a href="https://www.pinecone.io/" target="_blank" rel="noopener noreferrer">Pinecone</a> と連携します。事前にPineconeでアカウント登録、APIキー発行、インデックス作成を行ってください。</p>
                <table class="form-table">
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'; ?>">Pinecone APIキー</label></th>
                        <td>
                            <input type="password"
                                id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'; ?>"
                                value="<?php echo esc_attr($pinecone_api_key); // 読み込んだ値を表示 
                                        ?>"
                                class="regular-text"
                                autocomplete="new-password"> <?php // ブラウザの自動入力を抑制 
                                                                ?>
                            <p class="description">Pineconeコンソールで取得したAPIキーを入力してください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'; ?>">Pinecone 環境名 (Environment)</label></th>
                        <td>
                            <input type="text"
                                id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'; ?>"
                                value="<?php echo esc_attr($pinecone_environment); ?>"
                                class="regular-text"
                                placeholder="例: gcp-starter または us-east-1 など">
                            <p class="description">PineconeのAPIキーページなどで確認できる環境名を入力してください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'; ?>">Pinecone インデックス名</label></th>
                        <td>
                            <input type="text"
                                id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'; ?>"
                                value="<?php echo esc_attr($pinecone_index_name); ?>"
                                class="regular-text"
                                placeholder="例: edel-chatbot-plus">
                            <p class="description">Pineconeで作成したインデックスの名前を入力してください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'; ?>">Pinecone ホストURL (Endpoint)</label></th>
                        <td>
                            <?php // URL入力には type="url" を使うとブラウザのバリデーションも効く 
                            ?>
                            <input type="url"
                                id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'; ?>"
                                value="<?php echo esc_attr($pinecone_host); ?>"
                                class="large-text" <?php // URLは長いため large-text 
                                                    ?>
                                placeholder="例: https://your-index-xxxxxx.svc.your-env.pinecone.io">
                            <p class="description">Pineconeコンソールで確認できるインデックスのホストURL (Endpoint) を入力してください。</p>
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
                        name="<?php echo $submit_name_settings; ?>"
                        class="button button-primary"
                        value="設定を保存">
                </p>
            </form>

            <hr>

            <h2>サイトコンテンツからの学習</h2>
            <p>上記「自動学習設定」で指定された条件に基づき、サイト内の投稿や固定ページから情報を読み込み、ベクトル化してチャットボットの学習データとして登録します。<br>サイトのコンテンツ量によっては処理に時間がかかる場合があります。</p>
            <p class="submit">
                <button type="button"
                    id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>start-learning-button"
                    class="button button-primary">
                    サイトコンテンツから学習を開始<span class="spinner" id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>learning-spinner" style="float: none; vertical-align: middle;"></span>
                </button>
            <div id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>learning-progress" style="margin-top: 1em; padding: 10px; border: 1px solid #ccc; background: #f9f9f9; min-height: 50px; display: none;">
                <p style="margin:0;"><strong>処理状況:</strong> <span class="status">待機中...</span></p>
                <div style="background: #eee; border-radius: 3px; overflow: hidden; margin-top: 5px;">
                    <div class="progress-bar" style="width: 0%; background: #007bff; height: 10px; text-align: center; color: white; font-size: 8px; line-height: 10px;">0%</div>
                </div>
                <p style="margin:5px 0 0 0; font-size: smaller;" class="log"></p>
            </div>
            <hr>

            <h2>学習データ管理</h2>
            <form method="POST" onsubmit="return confirm('本当に全ての学習データ（ベクトル）を削除しますか？この操作は元に戻せません。');">
                <?php wp_nonce_field($nonce_action_delete); ?>
                <p>データベースに保存されている学習データ（ベクトル化されたテキストチャンク）を全て削除します。</p>
                <p class="submit">
                    <input type="submit"
                        name="delete_all_vectors"
                        class="button button-secondary"
                        value="全学習データを削除">
                </p>
            </form>

            </p>
        </div>
<?php
    } // end show_setting_page()

    public static function create_custom_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . EDEL_AI_CHATBOT_PLUS_PREFIX . 'vectors';
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            source_post_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00', /* ★ 追加: 元投稿の最終更新日時 */
            source_text longtext NOT NULL,
            vector_data longtext NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            INDEX idx_source_post_id (source_post_id), /* 既存のインデックス */
            INDEX idx_source_post_modified (source_post_modified) /* ★ 追加: 更新日時にもインデックス推奨 */
        ) {$charset_collate};";

        // MySQLi のエラー例外を無効化
        if (isset($wpdb->dbh) && $wpdb->dbh instanceof mysqli) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        // dbDelta 関数でテーブルを作成・更新
        $result = dbDelta($sql);
    }
}
