<?php

namespace Edel\AiChatbotPlus\API;

// Exit if accessed directly.
if (!defined('ABSPATH')) exit();

use \WP_Error;

class EdelAiChatbotOpenAIAPI {

    private $api_key;
    private $embedding_model = 'text-embedding-3-small';
    private $embedding_api_url = 'https://api.openai.com/v1/embeddings';
    private $chat_api_url = 'https://api.openai.com/v1/chat/completions'; // Chat API URLを追加

    /**
     * コンストラクタ
     * @param string $api_key OpenAI APIキー
     */
    public function __construct(string $api_key) {
        $this->api_key = $api_key;
    }

    /**
     * テキストからEmbedding（ベクトル）を取得する
     *
     * @param string $text ベクトル化するテキスト
     * @return array|WP_Error 成功時はベクトル配列(float[])、失敗時はWP_Errorオブジェクト
     */
    public function get_embedding(string $text) {
        if (empty($this->api_key)) {
            return new WP_Error('api_key_missing', 'OpenAI APIキーが設定されていません。');
        }
        if (empty($text)) {
            return new WP_Error('text_empty', 'ベクトル化するテキストが空です。');
        }

        $body = json_encode([
            'input' => $text,
            'model' => $this->embedding_model,
        ]);

        $args = [
            'body'        => $body,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 30, // タイムアウトを30秒に設定 (必要に応じて調整)
        ];

        // wp_remote_post を使ってAPIリクエスト送信
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Calling get_embedding for text: ' . substr($text, 0, 50) . '...'); // ★ログ追加
        $response = wp_remote_post($this->embedding_api_url, $args);

        // レスポンスのエラーチェック
        if (is_wp_error($response)) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' get_embedding wp_remote_post WP_Error: ' . $response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' get_embedding API Response (' . ($response_code ?: 'No Code') . '): ' . $response_body);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);

        if ($response_code !== 200 || !$decoded_body || !isset($decoded_body['data'][0]['embedding'])) {
            // APIからのエラーレスポンス
            $error_message = 'APIからエラーが返されました。';
            if ($decoded_body && isset($decoded_body['error']['message'])) {
                $error_message .= ' 詳細: ' . $decoded_body['error']['message'];
            } elseif ($response_body) {
                $error_message .= ' レスポンスボディ: ' . substr(esc_html($response_body), 0, 200) . '...'; // 長すぎる場合は省略
            }
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Embedding API Error: Code ' . $response_code . ' Body: ' . $response_body); // エラーログに残す
            return new WP_Error('api_error', $error_message, ['status' => $response_code, 'body' => $response_body]);
        }

        // 成功：ベクトル配列を返す
        return $decoded_body['data'][0]['embedding'];
    }

    /**
     * OpenAI Chat APIを呼び出して応答を取得するメソッド (新規追加)
     *
     * @param array $messages OpenAI APIの messages 配列 (例: [['role' => 'system', 'content' => '...'], ['role' => 'user', 'content' => '...']])
     * @param string $model 使用するモデル名 (例: 'gpt-3.5-turbo')
     * @param float $temperature 応答のランダム性 (0.0-2.0)
     * @param int $max_tokens 最大応答トークン数
     * @return string|WP_Error 成功時は応答テキスト、失敗時はWP_Errorオブジェクト
     */
    public function get_chat_completion(array $messages, string $model = 'gpt-3.5-turbo', float $temperature = 0.7, int $max_tokens = 1000) {
        if (empty($this->api_key)) {
            return new WP_Error('api_key_missing', 'OpenAI APIキーが設定されていません。');
        }
        if (empty($messages)) {
            return new WP_Error('messages_empty', 'Chat APIに送信するメッセージが空です。');
        }

        $body = json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $max_tokens,
        ]);

        $args = [
            'body'        => $body,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 60, // 応答生成に時間がかかる場合があるので長めに設定
        ];

        // wp_remote_post を使ってAPIリクエスト送信
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Calling get_chat_completion with model: ' . $model); // ★ログ追加
        $response = wp_remote_post($this->chat_api_url, $args);

        // レスポンスのエラーチェック
        if (is_wp_error($response)) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' get_chat_completion wp_remote_post WP_Error: ' . $response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' get_chat_completion API Response (' . ($response_code ?: 'No Code') . '): ' . $response_body);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' API Response (' . ($response_code ?: 'No Code') . '): ' . $response_body);

        // 応答内容のチェック (choices[0].message.content が存在するか)
        if ($response_code !== 200 || !$decoded_body || !isset($decoded_body['choices'][0]['message']['content'])) {
            $error_message = 'API(Chat)からエラーまたは予期しない応答';
            if ($decoded_body && isset($decoded_body['error']['message'])) {
                $error_message .= ' 詳細: ' . $decoded_body['error']['message'];
            } elseif ($response_body) {
                $error_message .= ' レスポンスボディ: ' . substr(esc_html($response_body), 0, 200) . '...';
            }
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Chat API Error: Code ' . $response_code . ' Body: ' . $response_body);
            return new WP_Error('api_error', $error_message, ['status' => $response_code, 'body' => $response_body]);
        }

        $response_text = trim($decoded_body['choices'][0]['message']['content']);
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Chat completion result: ' . substr($response_text, 0, 100) . '...'); // 長い場合に備えて一部だけログ

        // 成功：応答テキストを返す
        return trim($decoded_body['choices'][0]['message']['content']);
    }



    // --- 将来的に Chat API 用のメソッドもここに追加できる ---
    // public function get_chat_completion(...) { ... }
}
