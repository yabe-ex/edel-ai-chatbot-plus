<?php

namespace Edel\AiChatbotPlus\API; // 他のAPIクラスと同じ名前空間にするか、別にするかはお好みで

// Exit if accessed directly.
if (!defined('ABSPATH')) exit();

use \WP_Error; // WP_Error を使うため

/**
 * Google Gemini API と通信するためのクライアントクラス
 */
class EdelAiChatbotPlusGeminiAPI { // クラス名に Gemini を入れる

    private $api_key;
    private $api_base_url = 'https://generativelanguage.googleapis.com/v1beta/models/'; // ベースURL

    /**
     * コンストラクタ
     *
     * @param string $api_key Google AI Studio で取得した API キー
     */
    public function __construct(string $api_key) {
        if (empty($api_key)) {
            throw new \InvalidArgumentException('Google API Key is required.');
        }
        $this->api_key = $api_key;
    }

    /**
     * Gemini API を呼び出してコンテンツ (テキスト応答) を生成する
     *
     * @param array $contents Gemini API の contents 配列形式。例: [['role' => 'user', 'parts' => [['text' => 'プロンプト全体']]]]
     * @param string $model 使用するモデル名 (例: 'gemini-1.5-flash')
     * @param float $temperature 応答のランダム性 (0.0-1.0 が一般的)
     * @param int $max_output_tokens 最大応答トークン数
     * @return string|WP_Error 成功時は応答テキスト、失敗時は WP_Error オブジェクト
     */
    public function generateContent(array $contents, string $model = 'gemini-1.5-flash', float $temperature = 0.7, int $max_output_tokens = 1000): string|\WP_Error { // 戻り値の型ヒントに \WP_Error

        // APIエンドポイントURLを構築
        $endpoint_url = rtrim($this->api_base_url, '/') . '/' . $model . ':generateContent?key=' . $this->api_key;

        // APIに送信するリクエストボディ (JSON) を作成
        $payload = [
            'contents' => $contents, // ★ OpenAIの 'messages' と形式が違うので注意
            'generationConfig' => [ // 生成に関する設定
                'temperature' => $temperature,
                'maxOutputTokens' => $max_output_tokens,
                // 'stopSequences' => ["\n"], // 必要なら停止シーケンス
            ],
            'safetySettings' => [ // 安全性設定 (オプション、必要に応じて調整)
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ],
        ];

        // wp_remote_post の引数配列を準備
        $args = [
            'method'      => 'POST',
            'headers'     => [
                'Content-Type' => 'application/json',
            ],
            'body'        => json_encode($payload), // PHP配列をJSON文字列に変換
            'data_format' => 'body',
            'timeout'     => 60, // 応答生成には時間がかかる場合があるので長めに
        ];

        // APIリクエスト実行
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Calling Gemini API (' . $model . ')');
        $response = wp_remote_post($endpoint_url, $args);

        // WordPress HTTP APIレベルのエラーチェック
        if (is_wp_error($response)) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Gemini API WP_Error: ' . $response->get_error_message());
            return new \WP_Error('gemini_request_failed', 'Gemini APIへのリクエスト送信に失敗しました。', $response->get_error_data());
        }

        // レスポンスコードとボディを取得
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);

        // Gemini APIからの応答をチェック
        // 成功時：candidates[0].content.parts[0].text に応答テキストが含まれる
        if ($response_code === 200 && isset($decoded_body['candidates'][0]['content']['parts'][0]['text'])) {
            $responseText = $decoded_body['candidates'][0]['content']['parts'][0]['text'];
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Gemini API Success. Response text (start): ' . substr($responseText, 0, 100) . '...');
            return trim($responseText); // 応答テキストを返す
        } else {
            // 失敗時の処理
            $error_message = 'Gemini APIからエラーまたは予期しない応答。';
            if (isset($decoded_body['error']['message'])) { // Google API 標準エラー形式
                $error_message .= ' 詳細: ' . $decoded_body['error']['message'];
            } elseif (isset($decoded_body['candidates'][0]['finishReason']) && $decoded_body['candidates'][0]['finishReason'] !== 'STOP') {
                // 応答が生成されずに停止した場合 (安全性、トークン数超過など)
                $error_message .= ' 停止理由: ' . $decoded_body['candidates'][0]['finishReason'];
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Gemini API Finish Reason: ' . $decoded_body['candidates'][0]['finishReason'] . ' Response: ' . $response_body);
            } elseif ($response_body) {
                $error_message .= ' Response Body: ' . substr(esc_html($response_body), 0, 200) . '...';
            }
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Gemini API Error: Code ' . $response_code . ' Body: ' . $response_body);
            // WP_Errorオブジェクトを返す
            return new \WP_Error('gemini_api_error', $error_message, ['status' => $response_code, 'body' => $decoded_body]);
        }
    } // end generateContent()

    // (オプション) Embedding 用のメソッドも必要なら後で追加
    // public function generateEmbedding(...) { ... }

} // End Class