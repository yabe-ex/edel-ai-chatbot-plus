<?php

namespace Edel\AiChatbotPlus\API;

use \WP_Error;
use \Exception;
use \Throwable;
use \InvalidArgumentException;

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

    /**
     * 手動登録された学習データのエントリ（タイトルと関連ID）を取得する (簡易版)
     * 注意: Pineconeのqueryはベクトル類似度検索が主目的のため、全件リスト取得には工夫が必要な場合がある。
     * ここではフィルタを使ってメタデータのみ取得を試みる。
     *
     * @param string|null $namespace 名前空間 (オプション)
     * @param int $limit 取得する最大件数（多めに指定）
     * @return array|WP_Error 成功時は ['title' => '...', 'vector_ids' => [...], 'first_id' => ...] の配列, 失敗時は WP_Error
     */
    public function listManualEntries(?string $namespace = null, int $limit = 1000) {
        $query_url = $this->host_url . '/query';

        // ダミーのゼロベクトル（フィルタのみで検索するため）
        // Pineconeの次元数に合わせてゼロ配列を生成する必要がある。コンストラクタなどで次元数を保持すると良いかも。
        // ここでは仮に1536次元とする
        $dummy_vector = array_fill(0, 1536, 0.0);

        // APIリクエストのボディを作成
        $payload = [
            'vector'          => $dummy_vector, // ダミーベクトル
            'topK'            => $limit,       // 取得件数（上限）
            'filter'          => [              // ★ メタデータフィルタ ★
                'source_type' => ['$eq' => 'manual'] // source_type が 'manual' のもの
            ],
            'includeMetadata' => true,         // メタデータは必須
            'includeValues'   => false,        // ベクトル値は不要
        ];
        if ($namespace !== null) {
            $payload['namespace'] = $namespace;
        }

        $args = [
            'method'      => 'POST',
            'headers'     => ['Content-Type' => 'application/json', 'Api-Key' => $this->api_key, 'Accept' => 'application/json'],
            'body'        => json_encode($payload),
            'data_format' => 'body',
            'timeout'     => 30, // リスト取得なので少し長めでも
        ];

        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Listing manual entries from Pinecone...');
        $response = wp_remote_post($query_url, $args);

        // レスポンスチェック
        if (is_wp_error($response)) {
            // ★★★ WP_Error の具体的な処理 ★★★
            $error_code = 'pinecone_list_request_failed'; // 独自のエラーコード
            // 元のエラーメッセージを含めてログと戻り値のメッセージを作成
            $error_message = 'Pineconeへのリスト取得リクエスト送信に失敗しました。詳細: ' . $response->get_error_message();
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone listManualEntries WP_Error: ' . $error_message);
            // 戻り値のWP_Errorにも元のエラーデータを格納
            return new \WP_Error($error_code, $error_message, $response->get_error_data());
            // ★★★ ここまで修正 ★★★
        }
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);

        if ($response_code === 200 && isset($decoded_body['matches'])) {
            // 取得成功、タイトルごとにIDをまとめる
            $entries = [];
            foreach ($decoded_body['matches'] as $match) {
                if (isset($match['metadata']['source_title']) && !empty($match['metadata']['source_title'])) {
                    $title = $match['metadata']['source_title'];
                    $vector_id = $match['id'] ?? null;
                    if ($vector_id) {
                        if (!isset($entries[$title])) {
                            $entries[$title] = ['title' => $title, 'vector_ids' => [], 'first_id' => $vector_id]; // 最初のIDも保持
                        }
                        $entries[$title]['vector_ids'][] = $vector_id;
                    }
                }
            }
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Found ' . count($entries) . ' unique manual entry titles.');
            // キーをリセットして通常の配列にする
            return array_values($entries);
        } else {
            // 失敗処理
            $error_message = 'Pineconeからのリスト取得(Query)に失敗しました。';
            if (isset($decoded_body['error']['message'])) { // Pinecone v3 APIエラー形式?
                $error_message .= ' Pinecone Error: (' . ($decoded_body['error']['code'] ?? 'unknown') . ') ' . $decoded_body['error']['message'];
            } elseif ($response_body) {
                $error_message .= ' Response Body: ' . substr(esc_html($response_body), 0, 200) . '...';
            }

            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone listManualEntries Error: Code ' . $response_code . ' Body: ' . $response_body);
            return new \WP_Error('pinecone_list_failed', $error_message, ['status' => $response_code, 'body' => $decoded_body]);
        }
    } // end listManualEntries()


    /**
     * 指定されたIDのベクトルをPineconeから削除する
     *
     * @param array $vectorIds 削除するベクトルIDの配列
     * @param string|null $namespace 名前空間 (オプション)
     * @return true|WP_Error 成功時は true, 失敗時は WP_Error
     */
    public function deleteVectors(array $vectorIds, ?string $namespace = null) {
        if (empty($vectorIds)) {
            return new \WP_Error('invalid_argument', '削除するベクトルIDが指定されていません。');
        }
        $delete_url = $this->host_url . '/vectors/delete';

        // APIリクエストのボディを作成
        $payload = [
            'ids' => $vectorIds,
        ];
        if ($namespace !== null) {
            $payload['namespace'] = $namespace;
        }
        // ★ メタデータフィルタでの削除も可能: $payload['filter'] = ['source_title' => ['$eq' => '削除したいタイトル']];

        $args = [ /* ... (headers, timeout など upsert/query と同様) ... */
            'method'      => 'POST', // ★ Delete も POST の場合がある (ドキュメント確認) または 'DELETE'
            'headers'     => ['Content-Type' => 'application/json', 'Api-Key' => $this->api_key, 'Accept' => 'application/json'],
            'body'        => json_encode($payload),
            'data_format' => 'body',
            'timeout'     => 30,
        ];

        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Deleting vectors from Pinecone: ' . implode(', ', $vectorIds));
        $response = wp_remote_post($delete_url, $args); // または wp_remote_request($delete_url, ['method' => 'DELETE', ...])

        // レスポンスチェック
        if (is_wp_error($response)) {
            $error_code = 'pinecone_delete_request_failed'; // 独自のエラーコード
            // 元のエラーメッセージを含めてログと戻り値のメッセージを作成
            $error_message = 'Pineconeへの削除リクエスト送信に失敗しました。詳細: ' . $response->get_error_message();
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone Delete WP_Error: ' . $error_message);
            // 戻り値のWP_Errorにも元のエラーデータを格納
            return new \WP_Error($error_code, $error_message, $response->get_error_data());
        }
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);

        // Delete API は成功時 200 OK で、ボディは空 {} のことが多い
        if ($response_code === 200) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone Delete Success.');
            return true;
        } else {
            $error_message = 'Pineconeからのベクトル削除(Delete)に失敗しました。';
            if (isset($decoded_body['error']['message'])) { // Pinecone v3 APIエラー形式?
                $error_message .= ' Pinecone Error: (' . ($decoded_body['error']['code'] ?? 'unknown') . ') ' . $decoded_body['error']['message'];
            } elseif ($response_body) {
                $error_message .= ' Response Body: ' . substr(esc_html($response_body), 0, 200) . '...';
            }

            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone Delete Error: Code ' . $response_code . ' Body: ' . $response_body . ' for IDs: ' . implode(', ', $vectorIds));
            return new \WP_Error('pinecone_delete_failed', $error_message, ['status' => $response_code, 'body' => $decoded_body]);
        }
    } // end deleteVectors()
} // End Class