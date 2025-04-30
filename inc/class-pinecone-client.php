<?php

namespace Edel\AiChatbotPlus\API; // 名前空間 (APIサブ名前空間を使う例)

// Exit if accessed directly.
if (!defined('ABSPATH')) exit();

/**
 * Pinecone REST API と通信するためのクライアントクラス
 */
class EdelAiChatbotPlusPineconeClient {

    private $api_key;
    private $environment;
    private $index_name;
    private $host_url;
    // private $project_id = null; // プロジェクトIDが必要な場合もある（Serverlessではホストに含まれることが多い）

    /**
     * 指定されたベクトルに類似するベクトルをPineconeから検索する
     *
     * @param array $vector クエリベクトル (floatの配列)
     * @param int $topK 取得する類似ベクトルの数 (デフォルト: 3)
     * @param array|null $filter メタデータによるフィルタ条件 (オプション) 例: ['genre' => ['$eq' => 'drama']]
     * @param string|null $namespace 名前空間 (オプション)
     * @param bool $include_metadata メタデータを含めるか (デフォルト: true)
     * @param bool $include_values ベクトル値を含めるか (デフォルト: false)
     * @return array|WP_Error 成功時は検索結果の配列 (Pineconeの応答形式に基づく), 失敗時は WP_Error
     */
    public function query(array $vector, int $topK = 3, ?array $filter = null, ?string $namespace = null, bool $include_metadata = true, bool $include_values = false) {
        $query_url = $this->host_url . '/query';

        // APIリクエストのボディを作成
        $payload = [
            'vector'          => $vector,
            'topK'            => $topK,
            'includeMetadata' => $include_metadata,
            'includeValues'   => $include_values,
        ];
        // フィルタが指定されていれば追加
        if ($filter !== null && !empty($filter)) {
            $payload['filter'] = (object) $filter; // フィルタはオブジェクト形式が期待される場合あり
        }
        // 名前空間が指定されていれば追加
        if ($namespace !== null) {
            $payload['namespace'] = $namespace;
        }

        // wp_remote_post の引数を準備
        $args = [
            'method'      => 'POST',
            'headers'     => [
                'Content-Type' => 'application/json',
                'Api-Key'      => $this->api_key,
                'Accept'       => 'application/json',
            ],
            'body'        => json_encode($payload),
            'data_format' => 'body',
            'timeout'     => 15, // クエリのタイムアウト (秒)
        ];

        // APIリクエスト実行
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Querying Pinecone...'); // ログ追加
        $response = wp_remote_post($query_url, $args);

        // レスポンスチェック
        if (is_wp_error($response)) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone Query WP_Error: ' . $response->get_error_message());
            return new \WP_Error('pinecone_request_failed', 'Pineconeへのクエリ送信に失敗しました。', $response->get_error_data());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);

        // Pinecone の Query は成功時 200 OK で、body に 'matches' 配列などが含まれる
        if ($response_code === 200 && isset($decoded_body['matches'])) {
            // 成功：応答ボディ全体（または必要な部分だけ）を返す
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone Query Success. Found ' . count($decoded_body['matches']) . ' matches.');
            return $decoded_body; // または return $decoded_body['matches'];
        } else {
            // 失敗
            $error_message = 'Pineconeからの類似ベクトル検索(Query)に失敗しました。';
            if ($decoded_body && isset($decoded_body['message'])) {
                $error_message .= ' Pinecone Error: ' . $decoded_body['message'];
            } elseif ($response_body) {
                $error_message .= ' Response Body: ' . substr(esc_html($response_body), 0, 200) . '...';
            }
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone Query Error: Code ' . $response_code . ' Body: ' . $response_body);
            return new \WP_Error('pinecone_query_failed', $error_message, ['status' => $response_code, 'body' => $decoded_body]);
        }
    } // end query()

    /**
     * コンストラクタ
     *
     * @param string $api_key      Pinecone APIキー
     * @param string $environment  Pinecone 環境名
     * @param string $index_name   Pinecone インデックス名
     * @param string $host_url     Pinecone インデックスのホストURL (Endpoint)
     * @param string|null $project_id Pinecone プロジェクトID (オプション)
     */
    public function __construct(string $api_key, string $environment, string $index_name, string $host_url, ?string $project_id = null) {
        if (empty($api_key) || empty($environment) || empty($index_name) || empty($host_url)) {
            // 必須パラメータが足りない場合はエラーを投げるか、エラー状態にする
            throw new \InvalidArgumentException('Pinecone client requires API Key, Environment, Index Name, and Host URL.');
        }
        $this->api_key     = $api_key;
        $this->environment = $environment;
        $this->index_name  = $index_name;
        // ホストURLの末尾に / がない場合は追加する (APIエンドポイントのパス結合のため)
        $this->host_url    = rtrim($host_url, '/');

        // (オプション) 接続テストなどを行っても良い
        // $this->ping();
    }

    // --- ここに Upsert, Query などのメソッドを後で追加していく ---

    /**
     * ベクトルデータをPineconeにUpsert (挿入/更新) する
     *
     * @param string $vectorId ベクトルの一意なID (例: "post123-chunk0")
     * @param array $vector ベクトル値 (floatの配列)
     * @param array $metadata 保存したいメタデータ (例: ['source_post_id' => 123, 'text' => '...'])
     * @param string|null $namespace 名前空間 (オプション)
     * @return true|WP_Error 成功時は true, 失敗時は WP_Error オブジェクト
     */
    public function upsert(string $vectorId, array $vector, array $metadata = [], ?string $namespace = null) {
        $upsert_url = $this->host_url . '/vectors/upsert';

        // APIリクエストのボディを作成
        $payload = [
            'vectors' => [
                [
                    'id'       => $vectorId,
                    'values'   => $vector,
                    'metadata' => (object) $metadata, // メタデータはオブジェクト形式が推奨される場合あり
                ],
                // ここに複数のベクトルオブジェクトを追加して一括Upsertも可能
            ],
        ];
        // 名前空間が指定されていれば追加
        if ($namespace !== null) {
            $payload['namespace'] = $namespace;
        }

        // wp_remote_post の引数を準備
        $args = [
            'method'      => 'POST',
            'headers'     => [
                'Content-Type' => 'application/json',
                'Api-Key'      => $this->api_key, // ヘッダー名注意: 'Api-Key' or 'API-Key' ? ドキュメント確認
                'Accept'       => 'application/json',
            ],
            'body'        => json_encode($payload), // PHP配列をJSON文字列に変換
            'data_format' => 'body',
            'timeout'     => 15, // Upsertのタイムアウト (秒)
        ];

        // APIリクエスト実行
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Upserting vector to Pinecone: ID ' . $vectorId); // ログ追加
        $response = wp_remote_post($upsert_url, $args);

        // レスポンスチェック
        if (is_wp_error($response)) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone Upsert WP_Error: ' . $response->get_error_message());
            return new WP_Error('pinecone_request_failed', 'Pineconeへのリクエスト送信に失敗しました。', $response->get_error_data());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);

        // Pinecone の Upsert は成功時 200 OK で、body には upsertされた件数などが返る
        if ($response_code === 200 && isset($decoded_body['upsertedCount']) && $decoded_body['upsertedCount'] > 0) {
            // 成功
            return true;
        } else {
            // 失敗
            $error_message = 'Pineconeへのベクトル保存(Upsert)に失敗しました。';
            if ($decoded_body && isset($decoded_body['message'])) { // Pinecone v3 APIのエラー形式に合わせて調整が必要かも
                $error_message .= ' Pinecone Error: ' . $decoded_body['message'];
            } elseif ($response_body) {
                $error_message .= ' Response Body: ' . substr(esc_html($response_body), 0, 200) . '...';
            }
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone Upsert Error: Code ' . $response_code . ' Body: ' . $response_body);
            return new WP_Error('pinecone_upsert_failed', $error_message, ['status' => $response_code, 'body' => $decoded_body]);
        }
    } // end upsert()


} // End Class