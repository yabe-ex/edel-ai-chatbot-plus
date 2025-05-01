<?php

namespace Edel\AiChatbotPlus\API; // 他のAPIクラスと同じ名前空間

// Exit if accessed directly.
if (!defined('ABSPATH')) exit();

use \WP_Error;

/**
 * Anthropic Claude API と通信するためのクライアントクラス
 */
class EdelAiChatbotPlusClaudeAPI {

    private $api_key;
    private $api_base_url = 'https://api.anthropic.com/v1/messages'; // Messages API エンドポイント
    private $api_version = '2023-06-01'; // APIバージョン (ヘッダーで指定)

    /**
     * コンストラクタ
     *
     * @param string $api_key Anthropic API キー
     */
    public function __construct(string $api_key) {
        if (empty($api_key)) {
            throw new \InvalidArgumentException('Anthropic API Key is required.');
        }
        $this->api_key = $api_key;
    }

    /**
     * Claude API を呼び出してメッセージ応答を生成する (Messages API)
     *
     * @param array $messages Claude API の messages 配列形式 [['role' => 'user'|'assistant', 'content' => '...']]
     * @param string $model 使用するモデル名 (例: 'claude-3-haiku-20240307')
     * @param int $max_tokens 最大応答トークン数
     * @param float $temperature 温度 (0.0-1.0)
     * @param string|null $system システムプロンプト (オプション)
     * @return string|WP_Error 成功時は応答テキスト、失敗時は WP_Error
     */
    public function createMessage(array $messages, string $model, int $max_tokens = 1024, float $temperature = 0.7, ?string $system = null): string|\WP_Error {

        $endpoint_url = $this->api_base_url;

        // リクエストボディを作成
        $payload = [
            'model'      => $model,
            'messages'   => $messages, // ★ OpenAI とほぼ同じ形式
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
            // 'stream'     => false, // ストリーミングしない場合
        ];
        // システムプロンプトがあれば追加
        if ($system !== null && !empty(trim($system))) {
            $payload['system'] = $system;
        }

        // wp_remote_post の引数配列
        $args = [
            'method'      => 'POST',
            'headers'     => [
                'x-api-key'         => $this->api_key,         // ★ Anthropic 用ヘッダー
                'anthropic-version' => $this->api_version,     // ★ Anthropic 用ヘッダー
                'content-type'      => 'application/json',
            ],
            'body'        => json_encode($payload),
            'data_format' => 'body',
            'timeout'     => 60, // 応答生成時間を考慮
        ];

        // APIリクエスト実行
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Calling Claude API (' . $model . ')');
        $response = wp_remote_post($endpoint_url, $args);

        // レスポンスチェック (WordPress HTTP APIレベル)
        if (is_wp_error($response)) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Claude API WP_Error: ' . $response->get_error_message());
            return new \WP_Error('claude_request_failed', 'Claude APIへのリクエスト送信に失敗しました。', $response->get_error_data());
        }

        // レスポンスコードとボディを取得
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);

        // Claude API からの応答をチェック
        // 成功時(200 OK)は 'content' 配列の最初の要素の 'text' に応答が含まれる
        if ($response_code === 200 && isset($decoded_body['content'][0]['type']) && $decoded_body['content'][0]['type'] === 'text' && isset($decoded_body['content'][0]['text'])) {
            $responseText = $decoded_body['content'][0]['text'];
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Claude API Success. Response text (start): ' . substr($responseText, 0, 100) . '...');
            return trim($responseText); // 応答テキストを返す
        } else {
            // 失敗時の処理
            $error_message = 'Claude APIからエラーまたは予期しない応答。';
            if (isset($decoded_body['error']['type']) && isset($decoded_body['error']['message'])) { // Anthropicのエラー形式
                $error_message .= ' 詳細: (' . $decoded_body['error']['type'] . ') ' . $decoded_body['error']['message'];
            } elseif (isset($decoded_body['stop_reason']) && $decoded_body['stop_reason'] !== 'end_turn') {
                // 応答が生成されずに停止した場合
                $error_message .= ' 停止理由: ' . $decoded_body['stop_reason'];
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Claude API Finish Reason: ' . $decoded_body['stop_reason'] . ' Response: ' . $response_body);
            } elseif ($response_body) {
                $error_message .= ' Response Body: ' . substr(esc_html($response_body), 0, 200) . '...';
            }
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Claude API Error: Code ' . $response_code . ' Body: ' . $response_body);
            return new \WP_Error('claude_api_error', $error_message, ['status' => $response_code, 'body' => $decoded_body]);
        }
    } // end createMessage()

} // End Class