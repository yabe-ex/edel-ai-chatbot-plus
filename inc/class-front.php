<?php

namespace Edel\AiChatbotPlus\Front;

use Edel\AiChatbotPlus\API\EdelAiChatbotOpenAIAPI;
use Edel\AiChatbotPlus\API\EdelAiChatbotPlusPineconeClient;
use \WP_Error;
use \Exception;
use \Throwable;

class EdelAiChatbotFront {
    function front_enqueue() {
        $version  = (defined('EDEL_AI_CHATBOT_PLUS_DEVELOP') && true === EDEL_AI_CHATBOT_PLUS_DEVELOP) ? time() : EDEL_AI_CHATBOT_PLUS_VERSION;
        $strategy = array('in_footer' => true, 'strategy'  => 'defer');

        wp_register_style(EDEL_AI_CHATBOT_PLUS_SLUG . '-front',  EDEL_AI_CHATBOT_PLUS_URL . '/css/front.css', array(), $version);
        wp_register_script(EDEL_AI_CHATBOT_PLUS_SLUG . '-front', EDEL_AI_CHATBOT_PLUS_URL . '/js/front.js', array('jquery'), $version, $strategy);

        wp_enqueue_style(EDEL_AI_CHATBOT_PLUS_SLUG . '-front');
        wp_enqueue_script(EDEL_AI_CHATBOT_PLUS_SLUG . '-front');

        // ★★★ Ajax通信用の情報をJavaScriptに渡す ★★★
        $ajax_nonce = wp_create_nonce(EDEL_AI_CHATBOT_PLUS_PREFIX . 'ajax_nonce'); // Nonceを生成
        $localized_data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => $ajax_nonce,
            'action'   => EDEL_AI_CHATBOT_PLUS_PREFIX . 'send_message' // Ajaxアクション名
        );
        wp_localize_script(EDEL_AI_CHATBOT_PLUS_SLUG . '-front', 'edel_chatbot_params', $localized_data);
    }

    /**
     * フローティングチャットボットUIのHTMLをフッターに出力する
     */
    public function output_floating_chatbot_ui() {
        $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
        $options = get_option($option_name, []);

        $is_enabled = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'enabled'] ?? '1'; // デフォルト有効
        if ($is_enabled !== '1') {
            return;
        }

        $can_display = apply_filters('EDEL_AI_CHATBOT_PLUS_display_permission', true);
        if ($can_display !== true) {
            return;
        }

        $header_title     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'header_title'] ?? 'AIチャットボット';
        $greeting_message = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'greeting_message'] ?? 'こんにちは！何かお手伝いできることはありますか？';
        $is_default_open = isset($options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open']) && $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'default_open'] === '1';

        // ★★★ 初期状態に応じてクラスとスタイルを設定 ★★★
        $window_classes = 'edel-chatbot-window';
        $button_style = '';
        if ($is_default_open) {
            $window_classes .= ' is-visible'; // 最初から表示クラスを付与
            $button_style = 'style="display: none;"'; // 最初からアイコンボタンを非表示
        }
        // 特定の条件下でのみ表示する、などのロジックもここに追加可能
        // if ( is_admin() || is_embed() /* || 他の条件 */ ) { return; }
?>
        <div id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>widget-container">
            <button id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>open-button" class="edel-chatbot-open-button" <?php echo $button_style; ?>>
                <?php
                $default_icon_html = '<span class="edel-chatbot-icon">🤖</span>';
                echo apply_filters('EDEL_AI_CHATBOT_PLUS_floating_icon', $default_icon_html);
                ?>
                <span class="edel-chatbot-button-text">Botに聞く</span>
            </button>

            <?php // --- チャットウィンドウ本体 (最初は非表示) ---
            ?>
            <div id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>window" class="<?php echo esc_attr($window_classes); ?>">
                <div class="edel-chatbot-header">
                    <span class="edel-chatbot-title"><?php echo esc_html($header_title); ?></span>
                    <div>
                        <button id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>maximize-button" class="edel-chatbot-header-button edel-chatbot-maximize-button" title="拡大/縮小">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" fill="currentColor" class="bi bi-arrows-fullscreen" viewBox="0 0 16 16">
                                <path fill-rule="evenodd" d="M5.828 10.172a.5.5 0 0 0-.707 0l-4.096 4.096V11.5a.5.5 0 0 0-1 0v3.975a.5.5 0 0 0 .5.5H4.5a.5.5 0 0 0 0-1H1.732l4.096-4.096a.5.5 0 0 0 0-.707zm4.344 0a.5.5 0 0 1 .707 0l4.096 4.096V11.5a.5.5 0 1 1 1 0v3.975a.5.5 0 0 1-.5.5H11.5a.5.5 0 0 1 0-1h2.768l-4.096-4.096a.5.5 0 0 1 0-.707zm0-4.344a.5.5 0 0 0 .707 0l4.096-4.096V4.5a.5.5 0 1 0 1 0V.525a.5.5 0 0 0-.5-.5H11.5a.5.5 0 0 0 0 1h2.768l-4.096 4.096a.5.5 0 0 0 0 .707zm-4.344 0a.5.5 0 0 1-.707 0L1.025 1.732V4.5a.5.5 0 0 1-1 0V.525a.5.5 0 0 1 .5-.5H4.5a.5.5 0 0 1 0 1H1.732l4.096 4.096a.5.5 0 0 1 0 .707z" />
                            </svg>
                        </button>
                        <button id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>close-button" class="edel-chatbot-header-button edel-chatbot-close-button" title="閉じる">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-lg" viewBox="0 0 16 16">
                                <path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8 2.146 2.854Z" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>history" class="edel-chatbot-history">
                    <div class="edel-chatbot-message edel-chatbot-message-bot">
                        <p><?php echo nl2br(esc_html($greeting_message)); ?></p>
                    </div>
                </div>

                <?php // メッセージ入力フォーム
                ?>
                <form id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>form" class="edel-chatbot-form">
                    <textarea id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>input" placeholder="メッセージを入力..." rows="1"></textarea>
                    <button type="submit" id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>submit" class="edel-chatbot-submit-button">
                        <span class="edel-chatbot-send-icon">➤</span>
                    </button>
                    <div id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>loading" class="edel-chatbot-loading" style="display: none;"><span></span><span></span><span></span></div>
                </form>

            </div>

        </div>
    <?php
    }

    /**
     * チャットボットUIを表示するショートコードのレンダリング関数
     *
     * @param array $atts ショートコード属性 (今回は未使用)
     * @return string チャットボットUIのHTML
     */
    public function render_chatbot_ui($atts = []) {
        // ショートコード属性は今回は使わないが、将来的な拡張のために引数は用意しておく
        // $atts = shortcode_atts( array(
        //     'attribute' => 'default_value',
        // ), $atts, 'edel_chatbot' );

        ob_start(); // 出力バッファリング開始
    ?>
        <div id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>container" class="edel-chatbot-container">
            <div id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>history" class="edel-chatbot-history">
                <div class="edel-chatbot-message edel-chatbot-message-bot">
                    <p>こんにちは！何かお手伝いできることはありますか？</p>
                </div>
            </div>
            <form id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>form" class="edel-chatbot-form">
                <input type="text" id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>input" placeholder="メッセージを入力..." required>
                <button type="submit" id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>submit">送信</button>
                <div id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX; ?>loading" class="edel-chatbot-loading" style="display: none;"><span></span><span></span><span></span></div>
            </form>
        </div>
<?php
        return ob_get_clean(); // バッファリングした内容を文字列として返す
    }

    /**
     * フロントエンドからのチャットメッセージ受信Ajaxハンドラ (修正)
     */
    public function handle_send_message() {
        error_log('--- handle_send_message METHOD CALLED! ---');
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' handle_send_message started.'); // ★ログ追加 (開始)

        global $wpdb; // DBアクセス用
        $vector_table_name = $wpdb->prefix . EDEL_AI_CHATBOT_PLUS_PREFIX . 'vectors'; // ベクトルテーブル名

        // Nonce検証 (変更なし)
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], EDEL_AI_CHATBOT_PLUS_PREFIX . 'ajax_nonce')) {
            wp_send_json_error(['message' => '不正なリクエストです。'], 403);
            return;
        }
        // メッセージ取得・サニタイズ (変更なし)
        $user_message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        if (empty($user_message)) {
            wp_send_json_error(['message' => 'メッセージが空です。'], 400);
            return;
        }

        // ====[ AI応答生成ロジック ここから ]====
        try {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' API Key found, proceeding...'); // ★ログ追加

            // --- 準備：APIキーとモデル取得 ---
            $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
            $options = get_option($option_name, []);
            $api_key = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] ?? '';
            $chat_model = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'] ?? 'gpt-3.5-turbo'; // 設定されたChatモデル

            $ai_service = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service'] ?? 'openai';

            $openai_api_key        = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] ?? '';
            $openai_chat_model     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'] ?? 'gpt-3.5-turbo';
            $google_api_key        = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'google_api_key'] ?? '';
            $gemini_chat_model     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'gemini_model'] ?? 'gemini-1.5-flash';

            $pinecone_api_key     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'] ?? '';
            $pinecone_environment = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'] ?? '';
            $pinecone_index_name  = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'] ?? '';
            $pinecone_host        = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'] ?? '';

            if ($ai_service === 'openai' && empty($openai_api_key)) {
                throw new \Exception('OpenAI APIキーが設定されていません。');
            }
            if ($ai_service === 'gemini' && empty($google_api_key)) {
                throw new \Exception('Google APIキーが設定されていません。');
            }

            if (empty($api_key) || empty($pinecone_api_key) || empty($pinecone_environment) || empty($pinecone_index_name) || empty($pinecone_host)) {
                throw new Exception('APIキーまたはPinecone設定が不足しています。管理画面で設定を確認してください。');
            }
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' API Key and Pinecone settings found...');

            // --- APIクライアント準備 ---
            require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-openai-api.php'; // クラスファイル読み込み
            $openai_api = new EdelAiChatbotOpenAIAPI($api_key);

            require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-pinecone-client.php';
            $pinecone_client = new \Edel\AiChatbotPlus\API\EdelAiChatbotPlusPineconeClient(
                $pinecone_api_key,
                $pinecone_environment,
                $pinecone_index_name,
                $pinecone_host
            );

            // --- (a) 質問のベクトル化 ---
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Got question embedding. Starting similarity search...');
            $question_vector = $openai_api->get_embedding($user_message);
            if (is_wp_error($question_vector)) {
                throw new Exception('質問のベクトル化に失敗しました: ' . $question_vector->get_error_message());
            }
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Got question embedding. Querying Pinecone...');

            $top_n = 3; // 取得する類似チャンク数
            $query_result = $pinecone_client->query(
                $question_vector,
                $top_n,
                null, // filter (必要なら追加)
                null, // namespace (必要なら追加)
                true, // includeMetadata (メタデータを取得)
                false // includeValues (ベクトル値は不要)
            );

            if (is_wp_error($query_result)) {
                throw new \Exception('Pineconeでの類似情報検索に失敗しました: ' . $query_result->get_error_message());
            }

            $context_chunks = []; // まず空で初期化
            if (isset($query_result['matches']) && is_array($query_result['matches'])) {
                // Pineconeが返した matches 配列をそのまま使う (topKで件数指定済みのため)
                $context_chunks = $query_result['matches'];
            } else {
                // matches がないか配列でない場合 (エラー応答など)
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone query did not return expected matches array. Response: ' . print_r($query_result, true));
            }

            // ★★★ ログの位置を $context_chunks 定義後に移動 ★★★
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone query done. Found ' . count($context_chunks) . ' matches. Creating prompt...');

            // --- (c) プロンプト作成 ---
            $context_text = '';
            $context_chunks_count = 0;
            // Pineconeの応答形式 ('matches' 配列) からメタデータを抽出
            if (isset($query_result['matches']) && !empty($query_result['matches'])) {
                $context_text = "以下は関連する可能性のある情報です。これを参考に回答してください。\n---\n";
                foreach ($query_result['matches'] as $match) {
                    // score や metadata は存在するか確認する方が安全
                    $text_preview = $match['metadata']['text_preview'] ?? ''; // メタデータからテキストプレビュー取得
                    $similarity_score = $match['score'] ?? 0; // 類似度スコアも取得可能

                    // (オプション) 類似度スコアが低いものは除外する
                    // if ($similarity_score < 0.7) continue;

                    if (!empty($text_preview)) {
                        $context_text .= $text_preview . "\n---\n"; // プレビューをコンテキストに追加
                        $context_chunks_count++;
                    }
                    // (注意) text_preview だけだと情報が足りない場合は、
                    // source_post_id を使ってDBから完全な source_text を引く処理が必要になる場合も
                }
            }

            if ($context_chunks_count === 0) {
                $context_text = "関連情報は見つかりませんでした。";
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' No relevant context chunks found from Pinecone.');
            } else {
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Found ' . $context_chunks_count . ' context chunks from Pinecone.');
            }

            // OpenAIに渡すメッセージ配列を作成
            $messages = [
                ['role' => 'system', 'content' => "あなたは親切なAIアシスタントです。提供された情報を元に、ユーザーの質問に日本語で回答してください。情報がない場合は、正直に「分かりません」と答えてください。"],
                ['role' => 'user', 'content' => $context_text . "\n\nユーザーの質問:\n" . $user_message]
            ];

            // --- (d) 応答生成 ---
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Calling get_chat_completion...'); // Chat API呼び出し前のログ
            $bot_response =  '';

            if ($ai_service === 'gemini') {
                // === Google Gemini の場合 ===
                // APIクライアント準備 (Gemini Chat用)
                require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-google-gemini-api.php';
                $gemini_api = new \Edel\AiChatbotPlus\API\EdelAiChatbotPlusGeminiAPI($google_api_key);

                // プロンプトを Gemini API の contents 形式に変換
                $prompt_text = $context_text . "\n\nユーザーの質問:\n" . $user_message;
                $gemini_contents = [
                    [
                        'role' => 'user', // userロールにコンテキストと質問を入れる
                        'parts' => [['text' => $prompt_text]]
                    ]
                    // 必要ならシステム指示を別の形で追加（モデルによる）
                ];
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Calling Gemini API (' . $gemini_chat_model . ')');
                $bot_response_or_error = $gemini_api->generateContent($gemini_contents, $gemini_chat_model);
            } else {
                // === OpenAI の場合 (デフォルト) ===
                // APIクライアント準備 (OpenAI Chat用 - Embeddingと同じキーを使う)
                $openai_chat_client = $openai_embedding_client; // 同じインスタンスでOK

                // プロンプトを OpenAI API の messages 形式に変換
                $openai_messages = [
                    ['role' => 'system', 'content' => "あなたは親切なAIアシスタントです。提供された情報を元に、ユーザーの質問に日本語で回答してください。情報がない場合は、正直に「分かりません」と答えてください。"],
                    ['role' => 'user', 'content' => $context_text . "\n\nユーザーの質問:\n" . $user_message]
                ];
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Calling OpenAI Chat API (' . $openai_chat_model . ')');
                $bot_response_or_error = $openai_chat_client->get_chat_completion($openai_messages, $openai_chat_model);
            }
            // API応答チェック
            if (is_wp_error($bot_response_or_error)) {
                throw new \Exception('AIからの応答生成に失敗しました: ' . $bot_response_or_error->get_error_message());
            }
            $bot_response = $bot_response_or_error; // 正常な応答テキスト
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' AI response received.');

            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Sending success JSON.');
            wp_send_json_success(['message' => $bot_response]);
        } catch (Exception $e) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Exception caught in handle_send_message: ' . $e->getMessage());

            $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
            $options = get_option($option_name, []);
            $error_message_setting = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'error_message'] ?? 'エラーが発生しました。しばらくしてからもう一度お試しください。';

            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Sending error response from settings: ' . $error_message_setting);
            wp_send_json_error(['message' => $error_message_setting]);
        }
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' handle_send_message finished.');
    } // end handle_send_message

    /**
     * 二つのベクトル間のコサイン類似度を計算する
     * @param array $vecA ベクトルA (floatの配列)
     * @param array $vecB ベクトルB (floatの配列)
     * @return float|false 類似度 (-1から1、通常は0以上) またはエラー時 false
     */
    private function calculate_cosine_similarity(array $vecA, array $vecB) {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $countA = count($vecA);
        $countB = count($vecB);

        // ベクトルの次元が違うか、空の場合は計算不可
        if ($countA === 0 || $countA !== $countB) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Cosine Similarity Error: Vector dimension mismatch or empty.');
            return false;
        }

        for ($i = 0; $i < $countA; $i++) {
            // 配列要素が数値であることを確認
            if (!is_numeric($vecA[$i]) || !is_numeric($vecB[$i])) {
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Cosine Similarity Error: Non-numeric value found in vector at index ' . $i);
                return false;
            }
            $dotProduct += floatval($vecA[$i]) * floatval($vecB[$i]);
            $normA += floatval($vecA[$i]) * floatval($vecA[$i]);
            $normB += floatval($vecB[$i]) * floatval($vecB[$i]);
        }

        // ゼロ除算を避ける
        if ($normA == 0 || $normB == 0) {
            return 0.0; // または false を返すなど、仕様による
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
}
