<?php

namespace Edel\AiChatbotPlus\Admin;

use Edel\AiChatbotPlus\API\EdelAiChatbotOpenAIAPI;
use Edel\AiChatbotPlus\API\EdelAiChatbotPlusPineconeClient;
use \WP_Error;
use \Exception;
use \Throwable;

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

        $main_page_hook = 'toplevel_page_' . EDEL_AI_CHATBOT_PLUS_SLUG;
        $setting_page_hook = EDEL_AI_CHATBOT_PLUS_SLUG . '_page_' . 'edel-ai-chatbot-setting'; // 親スラッグ_page_サブスラッグ

        // ★★★ 現在のページが「設定」サブメニューページかどうかを完全一致で判定 ★★★
        if ($hook === $main_page_hook || $hook === $setting_page_hook) {

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

    /**
     * 管理画面に「学習済みサイトコンテンツ」の一覧を表示する
     * （投稿メタデータを参照）
     */
    private function display_auto_learned_content_list() {
        // 設定値を取得
        $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
        $options = get_option($option_name, []);
        $learning_post_types = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_post_types'] ?? ['post', 'page'];
        $learning_categories = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_categories'] ?? [];
        $exclude_ids_str     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'exclude_ids'] ?? '';
        $exclude_ids         = !empty($exclude_ids_str) ? array_map('intval', explode(',', $exclude_ids_str)) : [];

        // ページネーション用 現在のページ番号を取得
        $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        // 1ページあたりの表示件数
        $posts_per_page = 20; // 適宜調整

        // WP_Query の引数を準備
        $args = [
            'post_type'      => get_post_types(['public' => true, 'show_ui' => true], 'names'), // 管理画面UIを持つ公開投稿タイプを全て取得
            'post_status'    => 'publish',
            'has_password'   => false,
            'posts_per_page' => $posts_per_page,
            'paged'          => $paged,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_query'     => array( // 学習済み条件は使う
                array(
                    'key'     => '_edel_ai_vector_count',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'     => '_edel_ai_vector_count',
                    'value'   => 0,
                    'compare' => '>', // ★ 0より大きい条件は戻す ★
                    'type'    => 'NUMERIC',
                ),
            )
        ];

        if (!empty($exclude_ids)) {
            $args['post__not_in'] = $exclude_ids;
        }

        // WP_Query を実行
        $query = new \WP_Query($args); // use \WP_Query; が必要か確認

        if (!$query->have_posts()) {
            echo '<p>自動学習で登録されたデータはありません。（または指定条件に合う投稿がありません）</p>';
            return; // 投稿がなければここで終了
        }
?>
        <p>サイトの投稿や固定ページから自動的に学習されたデータの一覧です。（学習状態はWordPressのメタデータに基づきます）</p>
        <table class="wp-list-table widefat striped fixed">
            <thead>
                <tr>
                    <th scope="col" style="width: 40%;">タイトル</th>
                    <th scope="col" style="width: 10%;">タイプ</th>
                    <th scope="col" style="width: 10%;">ベクトル数</th>
                    <th scope="col" style="width: 20%;">AI学習状態 (最終処理日時)</th>
                    <th scope="col" style="width: 20%;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php
                while ($query->have_posts()) : $query->the_post();
                    global $post; // ループ内で $post 変数を使えるように
                    error_log("--- Checking post ID: {$post->ID} for list ---");
                    // メタデータを取得
                    $last_learned_gmt = get_post_meta($post->ID, '_edel_ai_last_learned_gmt', true);
                    $vector_count     = get_post_meta($post->ID, '_edel_ai_vector_count', true);
                    $vector_count     = !empty($vector_count) ? (int) $vector_count : 0;
                    $processed_gmt    = get_post_meta($post->ID, '_edel_ai_processed_gmt', true);
                    $current_modified_gmt = $post->post_modified_gmt;

                    // ステータス判定
                    $status_text = '';
                    $status_color = '#777';
                    $display_time = '';

                    if ($vector_count === 0) {
                        $status_text = '未学習';
                    } else {
                        if (!empty($processed_gmt) && $processed_gmt !== '0000-00-00 00:00:00') {
                            $timestamp = strtotime($processed_gmt . ' GMT');
                            if ($timestamp !== false) {
                                $timezone = wp_timezone();
                                $format = get_option('date_format') . ' ' . get_option('time_format');
                                $display_time = wp_date($format, $timestamp, $timezone);
                            } else {
                                $display_time = '(日時エラー)';
                            }
                        }
                        if (empty($last_learned_gmt) || strtotime($current_modified_gmt) > strtotime($last_learned_gmt)) {
                            $status_text = '要再学習';
                            $status_color = '#ffa500';
                        } else {
                            $status_text = '学習済み';
                            $status_color = '#228b22';
                        }
                        if (!empty($display_time)) {
                            $status_text .= ' (' . esc_html($display_time) . ')';
                        }
                    }
                ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>" target="_blank">
                                <?php echo esc_html(get_the_title($post->ID)); ?>
                            </a>
                            (ID: <?php echo esc_html($post->ID); ?>)
                        </td>
                        <td><?php echo esc_html(get_post_type_object($post->post_type)->label ?? $post->post_type); ?></td>
                        <td style="text-align: center;"><?php echo esc_html($vector_count); ?></td>
                        <td><span style="color: <?php echo esc_attr($status_color); ?>;"><?php echo esc_html($status_text); ?></span></td>
                        <td>
                            <?php // 削除ボタン用フォーム
                            ?>
                            <form method="POST" style="display: inline;">
                                <?php wp_nonce_field(EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_auto_entry_nonce'); ?>
                                <input type="hidden" name="source_post_id" value="<?php echo esc_attr($post->ID); ?>">
                                <input type="submit" name="delete_auto_entry" value="学習データ削除" class="button button-link-delete" onclick="return confirm('投稿「<?php echo esc_js(get_the_title($post->ID)); ?>」の学習データを削除しますか？\n関連するベクトルデータがPineconeから削除されます。');">
                            </form>
                        </td>
                    </tr>
                <?php
                endwhile;
                wp_reset_postdata(); // クエリをリセット
                ?>
            </tbody>
        </table>

        <?php // ページネーションリンクの表示
        $big = 999999999; // need an unlikely integer
        $pagination_args = array(
            'base' => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
            'format' => '?paged=%#%',
            'current' => max(1, $paged),
            'total' => $query->max_num_pages, // WP_Queryオブジェクトから総ページ数を取得
            'prev_text' => __('&laquo; 前へ'),
            'next_text' => __('次へ &raquo;'),
        );
        $paginate_links = paginate_links($pagination_args);
        if ($paginate_links) {
            echo '<div class="tablenav"><div class="tablenav-pages" style="margin: 1em 0;">' . $paginate_links . '</div></div>';
        }
        ?>
    <?php
    } // end display_auto_learned_content_list()

    /**
     * 指定された投稿タイプの一覧画面に「AI学習状態」列を追加する
     *
     * @param array $columns 既存の列定義配列
     * @return array 列を追加した配列
     */
    public function add_ai_status_column(array $columns): array {
        // 例えば 'date' (日付) 列の前に新しい列を追加
        $new_columns = [];
        foreach ($columns as $key => $title) {
            if ($key === 'date') {
                $new_columns[EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_status'] = 'AI学習状態'; // 新しい列
            }
            $new_columns[$key] = $title;
        }
        // もし date 列がなかった場合のために最後に追加
        if (!isset($new_columns[EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_status'])) {
            $new_columns[EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_status'] = 'AI学習状態';
        }
        return $new_columns;
    }

    /**
     * 「AI学習状態」列の内容を表示する
     *
     * @param string $column_name 現在処理中の列名
     * @param int $post_id 現在処理中の投稿ID
     */
    public function display_ai_status_column(string $column_name, int $post_id) {
        // 対象のカラムでなければ何もしない
        if ($column_name !== EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_status') {
            return;
        }

        error_log("--- display_ai_status_column called for post ID: {$post_id} ---");


        // 保存されているメタデータを取得
        // _edel_ai_last_learned_gmt: 差分比較用の、学習した時点での投稿更新日時(GMT)
        $last_learned_gmt = get_post_meta($post_id, '_edel_ai_last_learned_gmt', true);
        $vector_count     = get_post_meta($post_id, '_edel_ai_vector_count', true);
        $vector_count     = !empty($vector_count) ? (int) $vector_count : 0;
        $processed_gmt    = get_post_meta($post_id, '_edel_ai_processed_gmt', true);

        error_log("    _edel_ai_last_learned_gmt: " . print_r($last_learned_gmt, true));
        error_log("    _edel_ai_vector_count: " . print_r($vector_count, true));
        error_log("    _edel_ai_processed_gmt: " . print_r($processed_gmt, true));


        // 現在の投稿データを取得
        $post = get_post($post_id);
        if (!$post) {
            echo '---'; // 投稿が見つからない場合
            return;
        }
        $current_modified_gmt = $post->post_modified_gmt; // 現在の投稿更新日時(GMT)

        // ステータスと表示日時を決定
        $status_text = '';
        $status_color = '#777'; // デフォルト色（グレー）
        $display_time = '';    // 表示用日時文字列

        if ($vector_count === 0) {
            $status_text = '未学習';
        } else {
            // ★★★ wp_date() を使って処理実行日時を表示用にフォーマット ★★★
            if (!empty($processed_gmt) && $processed_gmt !== '0000-00-00 00:00:00') {
                // 1. DBから取得したGMT時刻文字列をUnixタイムスタンプに変換
                //    ' GMT' を付けてGMTであることを明示
                $timestamp = strtotime($processed_gmt . ' GMT');

                if ($timestamp !== false) {
                    // 2. WordPressサイト設定のタイムゾーンを取得
                    $timezone = wp_timezone(); // DateTimeZone オブジェクトを返す

                    // 3. サイト設定の日付・時刻フォーマットを取得
                    $format = get_option('date_format') . ' ' . get_option('time_format');

                    // 4. wp_date() でタイムゾーン変換とフォーマットを行う
                    //    第1引数: フォーマット
                    //    第2引数: Unixタイムスタンプ (GMT/UTC)
                    //    第3引数: 変換先のタイムゾーンオブジェクト
                    $display_time = wp_date($format, $timestamp, $timezone);
                } else {
                    // strtotimeが失敗した場合
                    $display_time = '(日時変換エラー)';
                    error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' strtotime failed for processed_gmt: ' . $processed_gmt);
                }
            }

            // 状態判定 (比較には $last_learned_gmt を使う)
            if (empty($last_learned_gmt) || strtotime($current_modified_gmt) > strtotime($last_learned_gmt)) {
                $status_text = '要再学習';
                $status_color = '#ffa500'; // オレンジ色
            } else {
                $status_text = '学習済み';
                $status_color = '#228b22'; // 緑色
            }

            // ステータス文字列に日時を追加（日時があれば）
            if (!empty($display_time)) {
                $status_text .= ' (' . esc_html($display_time) . ')';
            }
        } // end if $vector_count

        // 最終的なHTMLを出力
        echo '<span style="color: ' . esc_attr($status_color) . ';">' . esc_html($status_text) . '</span>';
    } // end display_ai_status_column()

    /**
     * [Admin Action Handler] 単一投稿の学習ジョブを WP-Cron でスケジュールする
     */
    public function handle_learn_single_post_action() {
        // 1. Nonce 検証
        $nonce_action = EDEL_AI_CHATBOT_PLUS_PREFIX . 'learn_single_post_nonce';
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], $nonce_action)) {
            wp_die('不正なリクエストです。(Nonce)');
        }

        // 2. 投稿IDを取得・検証
        if (!isset($_GET['post_id'])) {
            wp_die('投稿IDが指定されていません。');
        }
        $post_id = absint($_GET['post_id']);
        if ($post_id === 0 || !get_post($post_id)) {
            wp_die('無効な投稿IDです。');
        }

        // 3. ★★★ WP-Cron で単発イベントをスケジュール ★★★
        $hook_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'process_post_learning'; // ★ 実行するフック名
        $args_to_schedule = [json_encode(['post_id' => $post_id])];
        $timestamp = time() + 1;

        // 同じ引数で既にスケジュールされていないかチェック (オプションだが推奨)
        // wp_next_scheduled は同じフックで最初の予定時刻を返す
        $next_schedule = wp_next_scheduled($hook_name, $args_to_schedule);
        if ($next_schedule === false) { // falseならスケジュールされていない
            // 単発イベントをスケジュール
            // $schedule_result = wp_schedule_single_event($timestamp, $hook_name, $args_to_schedule);
            $schedule_result = wp_schedule_single_event($timestamp, $hook_name, $args_to_schedule);

            if ($schedule_result !== false) {
                $message_text = sprintf('投稿ID %d の学習処理をスケジュールしました（約1秒後に実行予定）。', $post_id);
                $message_type = 'success';
            } else {
                $message_text = sprintf('投稿ID %d の学習処理のスケジュールに失敗しました。', $post_id);
                $message_type = 'error';
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Failed to schedule WP-Cron event for post ID: ' . $post_id);
            }
        } else {
            // 既にスケジュールされている場合
            $message_text = sprintf('投稿ID %d の学習処理は既にスケジュールされています（実行待ち）。', $post_id);
            $message_type = 'info';
        }

        // 4. リダイレクト処理と通知メッセージの保存 (変更なし)
        $transient_key = EDEL_AI_CHATBOT_PLUS_PREFIX . 'admin_notice_' . get_current_user_id();
        set_transient($transient_key, ['type' => $message_type, 'message' => $message_text], 30);
        $redirect_url = wp_get_referer() ?: admin_url('edit.php?post_type=' . get_post_type($post_id));
        wp_safe_redirect($redirect_url);
        exit;
    } // end handle_learn_single_post_action()

    /**
     * (オプション) 管理画面に一時的な通知メッセージを表示する
     */
    public function display_admin_notices() {
        $transient_key = EDEL_AI_CHATBOT_PLUS_PREFIX . 'admin_notice_' . get_current_user_id();
        $notice = get_transient($transient_key);

        if ($notice && isset($notice['type']) && isset($notice['message'])) {
            // メッセージがあれば表示
            $class = 'notice notice-' . $notice['type'] . ' is-dismissible';
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($notice['message']));
            // 一度表示したら削除
            delete_transient($transient_key);
        }
    } // end display_admin_notices()

    public function add_post_row_actions(array $actions, \WP_Post $post): array {
        if ($post->post_status === 'publish' && empty($post->post_password)) { // ← 投稿タイプチェックを削除
            error_log('Edel AI Chatbot Plus: Adding action link for Post ID: ' . $post->ID);
            $nonce_action = EDEL_AI_CHATBOT_PLUS_PREFIX . 'learn_single_post_nonce';
            $nonce = wp_create_nonce($nonce_action);
            $learn_url = admin_url('admin.php?action=' . EDEL_AI_CHATBOT_PLUS_PREFIX . 'learn_post&post_id=' . $post->ID . '&_wpnonce=' . $nonce);
            // ★ リンクを追加 ★
            $actions['edel_ai_learn'] = '<a href="' . esc_url($learn_url) . '" aria-label="' . esc_attr(sprintf(__('%s をAIに学習させる', 'edel-ai-chatbot-plus'), $post->post_title)) . '">AI学習</a>';
        } else {
            // スキップ理由ログ (投稿タイプ部分は不要に)
            error_log(
                'Edel AI Chatbot Plus: Skipping action link for Post ID: ' . $post->ID . ' - Reason: ' .
                    // 'Type Match? Yes' . // タイプチェックは削除
                    ' Is Published? ' . ($post->post_status === 'publish' ? 'Yes' : 'No (' . $post->post_status . ')') .
                    ', No Password? ' . (empty($post->post_password) ? 'Yes' : 'No')
            );
        }
        return $actions;
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
                    $result = $this->process_single_post_learning_ajax(['post_id' => $processed_id]);
                    $next_offset = $offset + $limit;
                    $is_complete = ($next_offset >= $total_items);
                    if ($is_complete) {
                        delete_transient($transient_key); // 完了したらTransient削除
                    }

                    // フロントエンドに応答を返す
                    wp_send_json_success([
                        'status'          => $is_complete ? 'complete' : 'processing',
                        'offset'          => $next_offset,
                        'processed_count' => 1,
                        'item_status'     => $result['status'] ?? 'unknown',
                        'log_message'     => '投稿ID ' . $processed_id . ': ' . ($result['message'] ?? '不明な結果')
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
     * ★ WP-Cron用コールバックラッパー ★
     * edel_ai_chatbot_plus_process_post_learning フックから呼び出される
     *
     * @param string $json_args スケジュール時に渡されたJSON文字列 '{"post_id":ID}'
     * @return void
     */
    public function process_single_post_learning_cron(string $json_args) { // 戻り値 void
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' WP-Cron job started with JSON args: ' . $json_args);
        $args = json_decode($json_args, true);

        if (!$args || !isset($args['post_id']) || !is_numeric($args['post_id'])) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Invalid JSON args received via WP-Cron: ' . $json_args);
            return; // エラーログだけ残して終了
        }
        $post_id = (int) $args['post_id'];

        // 共通処理メソッドを呼び出す (戻り値はここでは使わない)
        $result = $this->_process_post_content($post_id);

        // (オプション) 処理結果 $result を使って何かログを追加しても良い
        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' WP-Cron job finished for post ID: ' . $post_id . ' with status: ' . ($result['status'] ?? 'unknown'));
    }


    /**
     * ★ Ajaxループ用コールバックラッパー ★
     * handle_batch_learning_ajax から呼び出される想定
     *
     * @param array $args ['post_id' => ID]
     * @return array 処理結果
     */
    public function process_single_post_learning_ajax(array $args): array {
        if (!isset($args['post_id']) || !is_numeric($args['post_id'])) {
            return ['status' => 'error', 'message' => 'Invalid arguments for AJAX processing'];
        }
        $post_id = (int) $args['post_id'];
        // 共通処理メソッドを呼び出す
        return $this->_process_post_content($post_id);
    }

    /**
     * ★ 新規追加：個別の投稿処理を行うコアロジック (private or protected) ★
     *
     * @param int $post_id 処理対象の投稿ID
     * @return array 処理結果 ['status' => 'processed'|'skipped'|'error', 'message' => '詳細']
     */
    private function _process_post_content(int $post_id): array {
        $status = 'error'; // デフォルトステータス
        $message = '不明なエラーが発生しました。'; // デフォルトメッセージ

        try {
            // --- 1. 必要な設定値を取得 ---
            $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
            $options = get_option($option_name, []);
            $openai_api_key       = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] ?? '';
            $pinecone_api_key     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'] ?? '';
            $pinecone_environment = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'] ?? '';
            $pinecone_index_name  = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'] ?? '';
            $pinecone_host        = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'] ?? '';

            if (empty($openai_api_key) || empty($pinecone_api_key) || empty($pinecone_environment) || empty($pinecone_index_name) || empty($pinecone_host)) {
                throw new \Exception('OpenAI APIキーまたはPinecone設定が不足しています。設定ページを確認してください。');
            }

            // --- 2. APIクライアント準備 ---
            // (use宣言がクラスファイル先頭にある想定)
            $openai_api = new \Edel\AiChatbotPlus\API\EdelAiChatbotOpenAIAPI($openai_api_key);
            $pinecone_client = new \Edel\AiChatbotPlus\API\EdelAiChatbotPlusPineconeClient(
                $pinecone_api_key,
                $pinecone_environment,
                $pinecone_index_name,
                $pinecone_host
            );

            // --- 3. 投稿オブジェクト取得と現在の更新日時取得 ---
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish' || !empty($post->post_password)) {
                return ['status' => 'skipped', 'message' => '対象外 (非公開/パスワード保護など)'];
            }
            $current_modified_gmt = $post->post_modified_gmt;

            // --- 4. Pineconeから前回の更新日時を取得 ---
            $db_modified_gmt = null;
            $filter = ['source_post_id' => ['$eq' => $post_id]];
            $existing_vector_info = $pinecone_client->query(array_fill(0, 1536, 0.0), 1, $filter, null, true, false);

            if (is_wp_error($existing_vector_info)) {
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Failed to query Pinecone for existing modified time for post ID ' . $post_id . ': ' . $existing_vector_info->get_error_message());
                // エラーでも処理を続行し、新規として扱う（あるいはエラーを返す）
            } elseif (isset($existing_vector_info['matches'][0]['metadata']['modified_gmt'])) {
                $db_modified_gmt = $existing_vector_info['matches'][0]['metadata']['modified_gmt'];
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Found existing modified_gmt in Pinecone: ' . $db_modified_gmt . ' for post ID ' . $post_id);
            } else {
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' No existing data found in Pinecone for post ID: ' . $post_id);
            }

            // --- 5. 更新日時の比較と処理実行 ---
            if ($db_modified_gmt === null || strtotime($current_modified_gmt) > strtotime($db_modified_gmt)) {

                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Processing post ID: ' . $post_id . ' (Needs update or first time)');

                // ★ 古いデータの削除 ★
                // PineconeのUpsertはIDが同じなら上書きするので、明示的な削除は必須ではない場合が多い。
                // ただし、チャンク数が変わった場合などに古いチャンクが残るのを防ぐには削除が確実。
                // 実装が複雑になるため、ここでは一旦コメントアウトし、Upsertによる上書きを期待。
                // 必要ならPineconeClientに deleteVectorsByFilter(['source_post_id' => ['$eq' => $post_id]]) のようなメソッドを実装して呼び出す。
                /*
                if ($db_modified_gmt !== null) {
                    error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Deleting old vectors from Pinecone for post ID: ' . $post_id);
                    // $delete_filter = ['source_post_id' => ['$eq' => $post_id]];
                    // $delete_result = $pinecone_client->deleteByFilter($delete_filter); // 要実装
                    // if (is_wp_error($delete_result)) { error_log(...); }
                }
                */

                // コンテンツ取得・前処理
                $content = apply_filters('the_content', $post->post_content);
                $clean_content = trim(wp_strip_all_tags(strip_shortcodes($content)));
                if (empty($clean_content)) {
                    return ['status' => 'skipped', 'message' => 'コンテンツ空'];
                }

                // チャンキング (chunk_text_fixed_length は同クラスの protected/public メソッドと仮定)
                $chunk_size = 500;
                $chunk_overlap = 50;
                $text_chunks = $this->chunk_text_fixed_length($clean_content, $chunk_size, $chunk_overlap);
                if (empty($text_chunks)) {
                    return ['status' => 'skipped', 'message' => 'チャンク生成なし'];
                }

                // ループしてベクトル化＆Pinecone保存
                $saved_chunks = 0;
                $errors = 0;
                foreach ($text_chunks as $chunk_index => $chunk) {
                    try {
                        $vector = $openai_api->get_embedding($chunk);
                        if (is_wp_error($vector)) {
                            throw new \Exception('ベクトル化失敗: ' . $vector->get_error_message());
                        }

                        $vector_id = $post_id . '-' . $chunk_index; // ID生成
                        $metadata = [
                            'source_type'      => $post->post_type,
                            'source_post_id'   => $post_id,
                            'source_title'     => $post->post_title,
                            'text_preview'     => mb_substr(preg_replace('/\s+/', ' ', $chunk), 0, 100),
                            'modified_gmt'     => $current_modified_gmt // ★ 現在の更新日時
                        ];

                        $upsert_result = $pinecone_client->upsert($vector_id, $vector, $metadata);
                        if (is_wp_error($upsert_result)) {
                            throw new \Exception('Pinecone保存失敗: ' . $upsert_result->get_error_message());
                        }
                        $saved_chunks++;
                        // usleep(200000); // レートリミット対策が必要なら入れる
                    } catch (\Exception $e_chunk) {
                        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Chunk processing error for post ID ' . $post_id . ' - chunk ' . $chunk_index . ': ' . $e_chunk->getMessage());
                        $errors++;
                    }
                } // end foreach chunk

                $status = ($errors === 0) ? 'processed' : 'processed_with_errors';
                $message = sprintf('%d チャンク保存完了', $saved_chunks) . ($errors > 0 ? sprintf(' (%d エラー)', $errors) : '');
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Finished processing updated post ID: ' . $post_id . '. ' . $message);
                if ($saved_chunks > 0) {
                    update_post_meta($post_id, '_edel_ai_last_learned_gmt', $current_modified_gmt);
                    update_post_meta($post_id, '_edel_ai_vector_count', $saved_chunks); // 保存したチャンク数を記録
                    update_post_meta($post_id, '_edel_ai_processed_gmt', current_time('mysql', 1)); // 現在のGMT時刻を保存
                    error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Post meta updated for post ID: ' . $post_id);
                } else {
                    // 保存されたチャンクが0の場合 (エラーがあったか、チャンクがなかったか)
                    error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Post meta NOT updated for post ID: ' . $post_id . ' because saved_chunks is 0 (Errors: ' . $errors . ')');
                    // 必要であれば、ここでベクトル数を0にするメタデータを保存しても良い
                    // update_post_meta($post_id, '_edel_ai_vector_count', 0);
                    // delete_post_meta($post_id, '_edel_ai_last_learned_gmt'); // 古い学習日時も消す？
                    // delete_post_meta($post_id, '_edel_ai_processed_gmt');
                }
                return ['status' => $status, 'message' => $message];
            } else {
                // --- 更新不要 ---
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Skipping post ID: ' . $post_id . ' (Not modified)');
                return ['status' => 'skipped', 'message' => '更新なし (スキップ)'];
            }
        } catch (\Throwable $t) { // 広範なエラーを捕捉
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Error in _process_post_content for post ID ' . $post_id . ': ' . $t->getMessage() . ' in ' . $t->getFile() . ' on line ' . $t->getLine());
            return ['status' => 'error', 'message' => 'エラー: ' . $t->getMessage()];
        }
    } // end _process_post_content()

    /**
     * [Action Scheduler Callback / Batch Process Unit] 指定された投稿IDの学習処理を実行する
     * (最終更新日時をチェックし、更新がある場合のみ処理)
     *
     * @param array $args Action Schedulerから渡される引数 (['post_id' => ID])
     */
    public function process_single_post_learning(array $args) {
        // ★ 配列から post_id を取り出す ★
        if (!isset($args['post_id']) || !is_numeric($args['post_id'])) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Invalid arguments passed to process_single_post_learning: ' . print_r($args, true));
            return ['status' => 'error', 'message' => 'Invalid arguments']; // 配列で返すのが望ましい
        }
        $post_id = (int) $args['post_id'];

        global $wpdb;
        $vector_table_name = $wpdb->prefix . EDEL_AI_CHATBOT_PLUS_PREFIX . 'vectors';
        $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
        $options = get_option($option_name, []);
        $api_key = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] ?? '';

        $pinecone_api_key     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'] ?? '';
        $pinecone_environment = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'] ?? '';
        $pinecone_index_name  = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'] ?? '';
        $pinecone_host        = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'] ?? '';

        if (empty($api_key) || empty($pinecone_api_key) || empty($pinecone_environment) || empty($pinecone_index_name) || empty($pinecone_host)) {
            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Error: OpenAI API Key or Pinecone settings are missing for post ID: ' . $post_id);
            return ['status' => 'error', 'message' => 'APIキーまたはPinecone設定が不足しています。'];
        }

        try {
            $openai_api = new \Edel\AiChatbotPlus\API\EdelAiChatbotOpenAIAPI($api_key);
            $pinecone_client = new \Edel\AiChatbotPlus\API\EdelAiChatbotPlusPineconeClient(
                $pinecone_api_key,
                $pinecone_environment,
                $pinecone_index_name,
                $pinecone_host
            );

            // 1. 投稿オブジェクト取得と現在の更新日時取得
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish' || !empty($post->post_password)) {
                return ['status' => 'skipped', 'message' => '対象外'];
            }
            $current_modified_gmt = $post->post_modified_gmt;

            // 2. Pineconeから前回の更新日時を取得 ★★★
            $db_modified_gmt = null; // 初期化
            $filter = ['source_post_id' => ['$eq' => $post_id]]; // post_id でフィルタ
            // topK=1, includeMetadata=true でクエリ実行 (ベクトル値は不要)
            $existing_vector_info = $pinecone_client->query(array_fill(0, 1536, 0.0), 1, $filter, null, true, false);

            if (is_wp_error($existing_vector_info)) {
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Failed to query Pinecone for existing modified time for post ID ' . $post_id . ': ' . $existing_vector_info->get_error_message());
                // エラーの場合はスキップするか、処理を続けるか（今回はスキップしてエラーを返す方が安全か）
                // return ['status' => 'error', 'message' => '既存データ確認エラー'];
                // または、エラーでも処理を続行し、常に新規として扱うか (↓のif条件で $db_modified_gmt は null のままになる)
            } elseif (isset($existing_vector_info['matches'][0]['metadata']['modified_gmt'])) {
                // データが見つかり、メタデータに更新日時があれば取得
                $db_modified_gmt = $existing_vector_info['matches'][0]['metadata']['modified_gmt'];
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Found existing modified_gmt in Pinecone: ' . $db_modified_gmt . ' for post ID ' . $post_id);
            } else {
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' No existing data found in Pinecone for post ID: ' . $post_id);
                // データがない場合は $db_modified_gmt は null のまま
            }

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

                require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-pinecone-client.php'; // ファイル読み込み
                $pinecone_client = new \Edel\AiChatbotPlus\API\EdelAiChatbotPlusPineconeClient( // use宣言があれば \Namespace\ は不要
                    $pinecone_api_key,
                    $pinecone_environment,
                    $pinecone_index_name,
                    $pinecone_host
                );

                // 8. チャンクループ: ベクトル化とDB保存
                $post_chunks_saved = 0;
                $post_errors = 0;
                foreach ($text_chunks as $chunk_index => $chunk) {
                    try {
                        $vector = $openai_api->get_embedding($chunk);
                        if (is_wp_error($vector)) {
                            throw new \Exception($vector->get_error_message());
                        }

                        // $vector_json = json_encode($vector);

                        $vector_id = $post_id . '-' . $chunk_index;
                        // 保存するメタデータ
                        $metadata = [
                            'source_type'      => $post->post_type,   // ★ 投稿タイプ (post, page など)
                            'source_post_id'   => $post_id,           // 投稿ID
                            'source_title'     => $post->post_title,    // ★ 投稿タイトル
                            'text_preview'     => mb_substr(preg_replace('/\s+/', ' ', $chunk), 0, 100),
                            'modified_gmt'     => $current_modified_gmt // 更新日時
                        ];

                        // Pineconeクライアントの upsert メソッド呼び出し
                        $upsert_result = $pinecone_client->upsert($vector_id, $vector, $metadata);

                        if (is_wp_error($upsert_result)) {
                            // Pineconeへの保存失敗
                            throw new \Exception('Pinecone保存失敗: ' . $upsert_result->get_error_message());
                        }


                        $post_chunks_saved++;
                        // usleep(200000);

                    } catch (\Exception $chunk_error) {
                        error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Chunk processing error for post ID ' . $post_id . ': ' . $chunk_error->getMessage());
                        $post_errors++;
                    }
                } // end foreach chunk

                $log_message = sprintf('%d チャンクをPineconeに保存完了', $post_chunks_saved);

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
        global $wpdb; // delete処理で $wpdb を使う可能性があるので念のため残す
        $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
        $options = get_option($option_name, []); // 設定を最初に読み込む

        // Pinecone接続情報を取得
        $pinecone_api_key     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'] ?? '';
        $pinecone_environment = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'] ?? '';
        $pinecone_index_name  = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'] ?? '';
        $pinecone_host        = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'] ?? '';

        // Pineconeクライアントの準備 (エラーチェックも含む)
        $pinecone_client = null;
        $pinecone_error_message = ''; // Pinecone接続エラー用
        if (!empty($pinecone_api_key) && !empty($pinecone_environment) && !empty($pinecone_index_name) && !empty($pinecone_host)) {
            try {
                // クラスファイル読み込みとインスタンス化 (use宣言をクラス先頭で行う推奨)
                // require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-pinecone-client.php'; // 既に読み込まれてるはず？
                $pinecone_client = new \Edel\AiChatbotPlus\API\EdelAiChatbotPlusPineconeClient(
                    $pinecone_api_key,
                    $pinecone_environment,
                    $pinecone_index_name,
                    $pinecone_host
                );
            } catch (\Throwable $th) { // 修正: InvalidArgumentException ではなく Throwable
                $pinecone_error_message = 'Pineconeクライアントの初期化に失敗しました: ' . $th->getMessage();
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . $pinecone_error_message); // エラーログにも記録
            }
        } else {
            $pinecone_error_message = 'Pineconeの設定が完了していません。学習データの登録・管理機能は利用できません。';
        }

        // Pineconeエラーがあればメッセージ表示
        if (!empty($pinecone_error_message)) {
            echo '<div class="notice notice-error"><p>' . esc_html($pinecone_error_message) . '</p></div>';
        }

        // ★★★ 手動学習データの削除処理 (ここに追加) ★★★
        $nonce_action_delete_manual = EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_manual_entry_nonce';
        // 削除ボタンが押され、かつ Pinecone クライアントが有効な場合に処理
        if ($pinecone_client && isset($_POST['delete_manual_entry']) && isset($_POST['entry_title']) && check_admin_referer($nonce_action_delete_manual)) {
            $title_to_delete = sanitize_text_field(wp_unslash($_POST['entry_title']));

            echo '<div id="message" class="notice notice-info is-dismissible"><p>手動学習データ「' . esc_html($title_to_delete) . '」の削除処理を開始します...</p></div>';
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();

            try {
                // 削除対象のベクトルIDリストを取得 (Pineconeにタイトルで問い合わせる)
                // listManualEntries はタイトル毎にIDをまとめて返すので、直接は使えない
                // query を直接使うか、タイトルでIDリストを返すメソッドを Client に追加する必要がある
                // ここでは query を使う例 (要 PineconeClient 側修正 or ここで直接 wp_remote_post)

                // ダミーベクトルを用意
                $dummy_vector = array_fill(0, 1536, 0.0); // 次元数は合わせる
                // フィルタ条件
                $filter = [
                    'source_type' => ['$eq' => 'manual'],
                    'source_title' => ['$eq' => $title_to_delete]
                ];
                // query メソッド呼び出し (IDのみ取得できれば良い)
                $query_result = $pinecone_client->query($dummy_vector, 1000, $filter, null, false, false); // メタデータも値も不要

                if (is_wp_error($query_result)) {
                    throw new \Exception('削除対象IDの取得に失敗: ' . $query_result->get_error_message());
                }

                $vector_ids_to_delete = [];
                if (isset($query_result['matches']) && !empty($query_result['matches'])) {
                    $vector_ids_to_delete = array_map(fn($match) => $match['id'], $query_result['matches']);
                }

                if (!empty($vector_ids_to_delete)) {
                    // Pineconeからベクトルを削除
                    $delete_result = $pinecone_client->deleteVectors($vector_ids_to_delete);
                    if (is_wp_error($delete_result)) {
                        throw new \Exception('Pineconeからのデータ削除に失敗: ' . $delete_result->get_error_message());
                    }
                    echo '<div class="notice notice-success is-dismissible"><p>手動学習データ「' . esc_html($title_to_delete) . '」(' . count($vector_ids_to_delete) . 'ベクトル) を削除しました。</p></div>';
                } else {
                    echo '<div class="notice notice-warning is-dismissible"><p>タイトル「' . esc_html($title_to_delete) . '」に紐づくベクトルデータが見つかりませんでした（削除処理スキップ）。</p></div>';
                }
            } catch (\Throwable $t) { // Throwable で捕捉
                echo '<div class="notice notice-error is-dismissible"><p>削除処理中にエラーが発生しました: ' . esc_html($t->getMessage()) . '</p></div>';
                error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Manual delete error: ' . $t->getMessage());
            }
        } // --- 手動削除処理ここまで ---



        $nonce_action_delete_auto = EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_auto_entry_nonce'; // HTMLのフォームと合わせる
        $submit_name_delete_auto = 'delete_auto_entry'; // 削除ボタンの name 属性
        // 削除ボタンが押され、投稿IDが送られ、Nonceが有効で、Pineconeクライアントが有効な場合
        if ($pinecone_client && isset($_POST[$submit_name_delete_auto]) && isset($_POST['source_post_id']) && check_admin_referer($nonce_action_delete_auto)) {

            $post_id_to_delete = absint($_POST['source_post_id']); // 念のため整数値に

            if ($post_id_to_delete > 0) {
                // 処理開始メッセージ
                echo '<div id="message" class="notice notice-info is-dismissible"><p>投稿ID ' . esc_html($post_id_to_delete) . ' の学習データの削除処理を開始します...</p></div>';
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();

                try {
                    // 1. Pineconeから削除対象のベクトルIDリストを取得
                    //    source_post_id でフィルタリングする
                    $dummy_vector = array_fill(0, 1536, 0.0); // 次元数
                    $filter = [
                        // 'source_type' => ['$in' => ['post', 'page', ...]], // 必要ならタイプも指定
                        'source_post_id' => ['$eq' => $post_id_to_delete] // ★ 投稿IDで絞り込み
                    ];
                    $limit = 10000; // 該当するIDを全て取得できる十分大きな数を指定 (Pineconeの上限も考慮)
                    $query_result = $pinecone_client->query($dummy_vector, $limit, $filter, null, false, false); // IDのみ取得

                    if (is_wp_error($query_result)) {
                        throw new \Exception('削除対象IDの取得に失敗(Pinecone Query): ' . $query_result->get_error_message());
                    }

                    $vector_ids_to_delete = [];
                    if (isset($query_result['matches']) && !empty($query_result['matches'])) {
                        $vector_ids_to_delete = array_map(fn($match) => $match['id'], $query_result['matches']);
                    }

                    // 2. 削除対象IDがあれば削除APIを呼び出す
                    if (!empty($vector_ids_to_delete)) {
                        $delete_result = $pinecone_client->deleteVectors($vector_ids_to_delete);

                        if (is_wp_error($delete_result)) {
                            throw new \Exception('Pineconeからのデータ削除に失敗: ' . $delete_result->get_error_message());
                        }
                        // 成功メッセージ
                        if (isset($post_id_to_delete) && $post_id_to_delete > 0) {
                            delete_post_meta($post_id_to_delete, '_edel_ai_last_learned_gmt');
                            delete_post_meta($post_id_to_delete, '_edel_ai_vector_count');
                            delete_post_meta($post_id_to_delete, '_edel_ai_processed_gmt');
                            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Post meta deleted for post ID: ' . $post_id_to_delete);
                        }
                        echo '<div class="notice notice-success is-dismissible"><p>投稿ID ' . esc_html($post_id_to_delete) . ' の学習データ (' . count($vector_ids_to_delete) . 'ベクトル) を削除しました。</p></div>';
                    } else {
                        // 対象データが見つからなかった場合
                        echo '<div class="notice notice-warning is-dismissible"><p>投稿ID ' . esc_html($post_id_to_delete) . ' に紐づく学習データが見つかりませんでした（削除処理スキップ）。</p></div>';
                    }
                } catch (\Throwable $t) {
                    // エラー発生時
                    echo '<div class="notice notice-error is-dismissible"><p>削除処理中にエラーが発生しました: ' . esc_html($t->getMessage()) . '</p></div>';
                    error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Auto delete error for post ID ' . $post_id_to_delete . ': ' . $t->getMessage());
                }
            } else {
                // 不正な投稿IDの場合
                echo '<div class="notice notice-error is-dismissible"><p>削除対象の投稿IDが無効です。</p></div>';
            }
        } // --- 自動学習データ削除処理 ここまで ---

        // 手動データ登録フォーム用のNonceアクション名とSubmitボタン名
        $nonce_action_manual = EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_learn_nonce';
        $submit_name_manual  = EDEL_AI_CHATBOT_PLUS_PREFIX . 'submit_manual_learn';

        // --- 手動データ登録フォームが送信された場合の処理 ---
        if (isset($_POST[$submit_name_manual]) && check_admin_referer($nonce_action_manual)) {

            // 処理開始メッセージを表示
            echo '<div id="message" class="notice notice-info is-dismissible"><p>手動学習データの処理を開始します...</p></div>';
            // 出力を強制フラッシュ（長時間の処理中にメッセージを見せるため）
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();

            // 送信されたタイトルと本文を取得・サニタイズ
            $manual_title = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_title']) ? sanitize_text_field(wp_unslash($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_title'])) : '';
            $manual_text  = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_text']) ? sanitize_textarea_field(wp_unslash($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_text'])) : '';

            // タイトルと本文が両方入力されているかチェック
            if (!empty($manual_title) && !empty($manual_text)) {

                // try...catch でエラー処理
                try {
                    // --- 必要な設定値を取得 ---
                    $options = get_option($option_name, []);
                    $openai_api_key       = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'] ?? '';
                    $pinecone_api_key     = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_api_key'] ?? '';
                    $pinecone_environment = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_environment'] ?? '';
                    $pinecone_index_name  = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_index_name'] ?? '';
                    $pinecone_host        = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'pinecone_host'] ?? '';

                    // 必須設定のチェック
                    if (empty($openai_api_key) || empty($pinecone_api_key) || empty($pinecone_environment) || empty($pinecone_index_name) || empty($pinecone_host)) {
                        throw new \Exception('OpenAI APIキーまたはPinecone設定が不足しています。設定ページを確認してください。');
                    }

                    // --- APIクライアント準備 ---
                    // (class-admin.php の先頭に use 宣言が必要)
                    // use Edel\AiChatbotPlus\API\EdelAiChatbotOpenAIAPI;
                    // use Edel\AiChatbotPlus\API\EdelAiChatbotPlusPineconeClient;
                    $openai_api      = new \Edel\AiChatbotPlus\API\EdelAiChatbotOpenAIAPI($openai_api_key);

                    // --- 処理実行 ---
                    // 1. 前処理
                    $clean_text = trim(wp_strip_all_tags($manual_text));

                    // 2. チャンキング
                    $chunk_size = 500;
                    $chunk_overlap = 50; // パラメータ
                    // (chunk_text_fixed_lengthメソッドがこのクラスにあり、public/protectedである必要あり)
                    $text_chunks = $this->chunk_text_fixed_length($clean_text, $chunk_size, $chunk_overlap);

                    if (empty($text_chunks)) {
                        throw new \Exception('テキストから有効なチャンクを生成できませんでした。');
                    }

                    // 3. ベクトル化とPineconeへの保存ループ
                    $saved_chunks = 0;
                    $errors = 0;
                    $current_time_gmt = current_time('mysql', 1); // GMT時刻

                    foreach ($text_chunks as $chunk_index => $chunk) {
                        try {
                            // ベクトル化
                            $vector = $openai_api->get_embedding($chunk);
                            if (is_wp_error($vector)) {
                                throw new \Exception('ベクトル化失敗: ' . $vector->get_error_message());
                            }

                            // Pinecone用データ準備
                            $vector_id = 'manual-' . time() . '-' . uniqid() . '-' . $chunk_index; // ユニークID
                            $metadata = [
                                'source_type'      => 'manual',         // ソースタイプ
                                'source_post_id'   => 0,                // 投稿IDは 0
                                'source_title'     => $manual_title,    // 入力タイトル
                                'text_preview'     => mb_substr(preg_replace('/\s+/', ' ', $chunk), 0, 100), // プレビュー
                                'modified_gmt'     => $current_time_gmt // 更新日時(GMT)
                            ];

                            // Pinecone へ Upsert
                            $upsert_result = $pinecone_client->upsert($vector_id, $vector, $metadata);
                            if (is_wp_error($upsert_result)) {
                                throw new \Exception('Pinecone保存失敗: ' . $upsert_result->get_error_message());
                            }
                            $saved_chunks++;
                            // usleep(200000); // レートリミット対策

                        } catch (\Exception $e_chunk) { // ループ内の個別エラー
                            error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Manual learn chunk error for title [' . $manual_title . ']: ' . $e_chunk->getMessage());
                            $errors++;
                            // ループは継続させる
                        }
                    } // end foreach chunk

                    // 完了メッセージを表示
                    echo '<div class="notice notice-success is-dismissible"><p>手動学習データ「' . esc_html($manual_title) . '」の処理が完了しました。' . esc_html($saved_chunks) . '個のチャンクをPineconeに保存しました。' . ($errors > 0 ? ' (' . esc_html($errors) . ' エラー)' : '') . '</p></div>';
                } catch (\Throwable $t) { // 処理全体のエラー (Throwableで捕捉)
                    error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Manual learn processing error: ' . $t->getMessage() . ' in ' . $t->getFile() . ' on line ' . $t->getLine());
                    echo '<div class="notice notice-error is-dismissible"><p>処理中にエラーが発生しました: ' . esc_html($t->getMessage()) . '</p></div>';
                }
            } else {
                // タイトルまたは本文が空の場合のメッセージ
                echo '<div class="notice notice-warning is-dismissible"><p>タイトルと本文の両方を入力してください。</p></div>';
            }
        } // --- 手動データ登録処理 ここまで ---

        // --- フォームHTML表示 ---
    ?>
        <div class="wrap">
            <h1><?php echo esc_html(EDEL_AI_CHATBOT_PLUS_NAME); ?> - 手動学習データ登録</h1>
            <p>特定の情報（例：短いFAQ、定型文、サイトコンテンツ以外の知識など）を直接チャットボットに学習させたい場合は、以下のフォームから登録できます。</p>

            <form method="POST">
                <?php wp_nonce_field($nonce_action_manual); // 新しいNonceアクション名
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_title'; ?>">タイトル</label></th>
                        <td>
                            <input type="text"
                                id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_title'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_title'; ?>"
                                class="regular-text"
                                required>
                            <p class="description">この学習データのタイトルを入力してください（後で管理しやすくなります）。</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_text'; ?>">本文（学習内容）</label></th>
                        <td>
                            <textarea id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_text'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'manual_text'; ?>"
                                rows="10"
                                class="large-text"
                                required></textarea>
                            <p class="description">チャットボットに学習させたい内容を入力してください。</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit"
                        name="<?php echo $submit_name_manual; ?>"
                        class="button button-primary"
                        value="手動でデータを登録・ベクトル化">
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

            <h2>登録済み手動データ</h2>
            <?php
            // Pineconeクライアントが準備できている場合のみリスト表示を試みる
            if ($pinecone_client) {
                // 手動登録データを取得 (タイトルと関連ID) - listManualEntriesはメタデータが必要
                $manual_entries_result = $pinecone_client->listManualEntries(); // 取得上限に注意

                if (is_wp_error($manual_entries_result)) {
                    // データ取得失敗
                    echo '<div class="notice notice-warning is-dismissible"><p>登録済みデータの取得に失敗しました: ' . esc_html($manual_entries_result->get_error_message()) . '</p></div>';
                } elseif (empty($manual_entries_result)) {
                    // データがまだない場合
                    echo '<p>登録されている手動学習データはありません。</p>';
                } else {
                    // データがある場合はテーブル表示
            ?>
                    <table class="wp-list-table widefat striped fixed">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 60%;">タイトル</th> <?php // 列幅の目安
                                                                                ?>
                                <th scope="col" style="width: 15%;">ベクトル数</th>
                                <th scope="col" style="width: 25%;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manual_entries_result as $entry): ?>
                                <tr>
                                    <td><?php echo esc_html($entry['title']); ?></td>
                                    <td><?php echo count($entry['vector_ids']); ?></td>
                                    <td>
                                        <?php // 各行に削除用フォームを設置
                                        ?>
                                        <form method="POST" style="display: inline;">
                                            <?php wp_nonce_field($nonce_action_delete_manual); ?>
                                            <input type="hidden" name="entry_title" value="<?php echo esc_attr($entry['title']); ?>">
                                            <input type="submit" name="delete_manual_entry" value="削除" class="button button-link-delete" onclick="return confirm('手動学習データ「<?php echo esc_js($entry['title']); ?>」を削除しますか？\nこの操作は元に戻せません。');">
                                        </form>
                                        <?php // (オプション) 編集ボタンなどをここに追加可能
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
            <?php
                } // end if empty/error check
            } else {
                // Pineconeクライアントが準備できていない場合のメッセージ
                // (冒頭のエラーメッセージと重複する可能性あり)
                // echo '<p>Pineconeの設定が完了していないため、登録済みデータを表示できません。</p>';
            } // end if $pinecone_client
            ?>

            <hr>

            <h2>学習済みサイトコンテンツ</h2>
            <?php
            if ($pinecone_client) { // Pineconeクライアントが有効な場合

                $this->display_auto_learned_content_list();

                // Pineconeから自動学習データを取得 (source_type が 'post' または 'page')
                // ※ 自動学習対象のカスタム投稿タイプも増やす場合は、$in_types を動的に生成
                $in_types = ['post', 'page'];
                $filter = [
                    'source_type' => ['$in' => $in_types] // type が post または page のもの
                ];
                // ダミーベクトルでフィルタ検索 (IDとメタデータを取得)
                $dummy_vector = array_fill(0, 1536, 0.0);
                $limit = 1000; // ★ 取得上限。全件取得できない可能性がある点に注意
                $auto_data_result = $pinecone_client->query($dummy_vector, $limit, $filter, null, true, false); // メタデータ取得
                // error_log(EDEL_AI_CHATBOT_PLUS_PREFIX . ' Pinecone Query Result (for auto-data list): ' . print_r($auto_data_result, true));

                $auto_entries = []; // 投稿IDごとに集計するための配列
                if (is_wp_error($auto_data_result)) {
                    echo '<div class="notice notice-warning is-dismissible"><p>学習済みコンテンツデータの取得に失敗しました: ' . esc_html($auto_data_result->get_error_message()) . '</p></div>';
                } elseif (isset($auto_data_result['matches']) && !empty($auto_data_result['matches'])) {
                    // 取得したチャンクデータを投稿IDごとに集計
                    foreach ($auto_data_result['matches'] as $match) {
                        if (isset($match['metadata']['source_post_id']) && $match['metadata']['source_post_id'] > 0) {
                            $post_id = (int) $match['metadata']['source_post_id'];
                            if (!isset($auto_entries[$post_id])) {
                                // 初めてのIDなら基本情報を初期化
                                $auto_entries[$post_id] = [
                                    'post_id'    => $post_id,
                                    'title'      => $match['metadata']['source_title'] ?? get_the_title($post_id), // メタデータ優先、なければ取得
                                    'type'       => $match['metadata']['source_type'] ?? get_post_type($post_id),
                                    'modified'   => $match['metadata']['modified_gmt'] ?? '', // GMT想定
                                    'chunk_count' => 0,
                                    'vector_ids' => [] // (オプション) 削除用にIDを集める場合
                                ];
                            }
                            $auto_entries[$post_id]['chunk_count']++;
                            $auto_entries[$post_id]['vector_ids'][] = $match['id'];
                        }
                    }
                }

                // 集計結果を表示
                if (empty($auto_entries)) {
                    echo '<p>自動学習で登録されたデータはありません。</p>';
                } else {
            ?>
                    <p>サイトの投稿や固定ページから自動的に学習されたデータの一覧です。</p>
                    <!-- <table class="wp-list-table widefat striped fixed">
                        <thead>
                            <tr>
                                <th scope="col">タイトル</th>
                                <th scope="col">タイプ</th>
                                <th scope="col">ベクトル数</th>
                                <th scope="col">最終学習日時(GMT)</th>
                                <th scope="col">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auto_entries as $entry): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url(get_edit_post_link($entry['post_id'])); ?>" target="_blank">
                                            <?php echo esc_html($entry['title']); ?>
                                        </a>
                                        (ID: <?php echo esc_html($entry['post_id']); ?>)
                                    </td>
                                    <td><?php echo esc_html($entry['type']); ?></td>
                                    <td><?php echo esc_html($entry['chunk_count']); ?></td>
                                    <td><?php echo esc_html($entry['modified']); ?></td>
                                    <td>
                                        <?php // ★ 削除ボタン用フォーム ★
                                        ?>
                                        <form method="POST" style="display: inline;">
                                            <?php // ★ 削除用Nonce (手動削除とは別のアクション名にする) ★
                                            ?>
                                            <?php wp_nonce_field(EDEL_AI_CHATBOT_PLUS_PREFIX . 'delete_auto_entry_nonce'); ?>
                                            <?php // ★ 削除対象の投稿IDを隠しフィールドで渡す ★
                                            ?>
                                            <input type="hidden" name="source_post_id" value="<?php echo esc_attr($entry['post_id']); ?>">
                                            <?php // ★ 削除実行を示す name 属性 ★
                                            ?>
                                            <input type="submit" name="delete_auto_entry" value="学習データ削除" class="button button-link-delete" onclick="return confirm('投稿「<?php echo esc_js($entry['title']); ?>」の学習データを削除しますか？\nこの操作は元に戻せません。');">
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table> -->
            <?php
                } // end if empty($auto_entries)
            } // end if $pinecone_client
            ?>

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

            // ★★★ AIサービス選択の保存 ★★★
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service'])
                ? sanitize_key($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service']) // 'openai' or 'gemini'
                : 'openai'; // デフォルトはOpenAI

            // ★★★ Google APIキーの保存 ★★★
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'google_api_key'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'google_api_key'])
                ? sanitize_text_field(trim($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'google_api_key']))
                : '';

            // ★★★ Geminiモデル選択の保存 (例) ★★★
            // (今回は 'gemini-1.5-flash' 固定とするか、選択肢を作るか)
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'gemini_model'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'gemini_model'])
                ? sanitize_text_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'gemini_model'])
                : 'gemini-1.5-flash'; // デフォルト

            if ($options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service'] === 'openai') {
                // OpenAIモデル保存処理 ...
            } else {
                // OpenAIモデル設定をクリアまたは保持 (どうするか決める)
                // unset($options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model']);
            }

            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_api_key'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_api_key'])
                ? sanitize_text_field(trim($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_api_key']))
                : '';

            // ★★★ Claude モデル選択の保存 ★★★
            $options_to_save[EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_model'] = isset($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_model'])
                ? sanitize_text_field($_POST[EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_model']) // モデル名はテキストとして保存
                : 'claude-3-haiku-20240307'; // デフォルト Haiku

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

        $ai_service       = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service'] ?? 'openai'; // デフォルト OpenAI
        $google_api_key   = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'google_api_key'] ?? '';
        $selected_openai_model = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'] ?? 'gpt-3.5-turbo'; // OpenAI用
        $selected_gemini_model = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'gemini_model'] ?? 'gemini-1.5-flash'; // Gemini用
        $claude_api_key = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_api_key'] ?? '';
        $selected_claude_model = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_model'] ?? 'claude-3-haiku-20240307';
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

                    <tr id="ai-service-row">
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service'; ?>">利用するAIサービス</label></th>
                        <td>
                            <select id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service'; ?>" name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service'; ?>">
                                <option value="openai" <?php selected($ai_service, 'openai'); ?>>OpenAI</option>
                                <option value="gemini" <?php selected($ai_service, 'gemini'); ?>>Google Gemini</option>
                                <option value="claude" <?php selected($ai_service, 'claude'); ?>>Anthropic Claude</option>
                            </select>
                            <p class="description">チャットの応答に使用するAIサービスを選択します。</p>
                        </td>
                    </tr>

                    <tr class="openai-settings service-settings" style="<?php echo $ai_service !== 'openai' ? 'display: none;' : ''; ?>">
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'; ?>">OpenAI APIキー</label></th>
                        <td>
                            <?php // ★ value で使う変数名が $api_key で正しいか確認 (読み込み部分と合わせる)
                            ?>
                            <input type="password" id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'; ?>" name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'api_key'; ?>" value="<?php echo esc_attr($api_key); ?>" class="regular-text" autocomplete="new-password">
                            <p class="description">OpenAI Platform で取得したAPIキー。</p>
                        </td>
                    </tr>
                    <tr class="openai-settings service-settings" style="<?php echo $ai_service !== 'openai' ? 'display: none;' : ''; ?>">
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'; ?>">OpenAI モデル</label></th>
                        <td>
                            <select id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'; ?>" name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'model'; ?>">
                                <?php $openai_models = ['gpt-4' => 'GPT-4', 'gpt-4-turbo' => 'GPT-4 Turbo', 'gpt-3.5-turbo' => 'GPT-3.5 Turbo']; ?>
                                <?php foreach ($openai_models as $value => $label) : ?>
                                    <option value='<?php echo esc_attr($value); ?>' <?php selected($selected_openai_model, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr class="gemini-settings service-settings" style="<?php echo $ai_service !== 'gemini' ? 'display: none;' : ''; ?>">
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'google_api_key'; ?>">Google APIキー</label></th>
                        <td><input type="password"
                                id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'google_api_key'; ?>"
                                name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'google_api_key'; ?>"
                                value="<?php echo esc_attr($google_api_key); ?>"
                                class="regular-text"
                                autocomplete="new-password">
                            <p class="description">Google AI Studio で取得したAPIキー。</p>
                        </td>
                    </tr>
                    <tr class="gemini-settings service-settings" style="<?php echo $ai_service !== 'gemini' ? 'display: none;' : ''; ?>">
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'gemini_model'; ?>">Gemini モデル</label></th>
                        <td>
                            <?php $gemini_models = ['gemini-1.5-flash' => 'Gemini 1.5 Flash']; // 他にも選択肢があれば追加
                            ?>
                            <select id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'gemini_model'; ?>" name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'gemini_model'; ?>">
                                <?php foreach ($gemini_models as $value => $label) : ?>
                                    <option value='<?php echo esc_attr($value); ?>' <?php selected($selected_gemini_model, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr class="claude-settings service-settings" style="<?php echo $ai_service !== 'claude' ? 'display: none;' : ''; ?>">
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_api_key'; ?>">Anthropic APIキー</label></th>
                        <td>
                            <input type="password" id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_api_key'; ?>" name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_api_key'; ?>" value="<?php echo esc_attr($claude_api_key); ?>" class="regular-text" autocomplete="new-password">
                            <p class="description">Anthropicのコンソールで取得したAPIキー。</p>
                        </td>
                    </tr>
                    <tr class="claude-settings service-settings" style="<?php echo $ai_service !== 'claude' ? 'display: none;' : ''; ?>">
                        <th><label for="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_model'; ?>">Claude モデル</label></th>
                        <td>
                            <?php
                            // 利用可能なClaudeモデルの例 (実際のモデルIDはAnthropicドキュメントで確認)
                            $claude_models = [
                                'claude-3-haiku-20240307' => 'Claude 3 Haiku',
                                'claude-3-sonnet-20240229' => 'Claude 3 Sonnet',
                                'claude-3-opus-20240229' => 'Claude 3 Opus'
                            ];
                            ?>
                            <select id="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_model'; ?>" name="<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'claude_model'; ?>">
                                <?php foreach ($claude_models as $value => $label) : ?>
                                    <option value='<?php echo esc_attr($value); ?>' <?php selected($selected_claude_model, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">利用するClaudeモデルを選択してください。</p>
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
                <script>
                    jQuery(document).ready(function($) {
                        function toggleSettingsVisibility() {
                            const selectedService = $('#<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service'; ?>').val();
                            // まず全てのサービス設定行を隠す
                            $('.service-settings').hide();
                            // 選択されたサービスの設定行だけを表示
                            if (selectedService === 'openai') {
                                $('.openai-settings').show();
                            } else if (selectedService === 'gemini') {
                                $('.gemini-settings').show();
                            } else if (selectedService === 'claude') {
                                $('.claude-settings').show();
                            }
                        }
                        toggleSettingsVisibility(); // 初期表示
                        $('#<?php echo EDEL_AI_CHATBOT_PLUS_PREFIX . 'ai_service'; ?>').on('change', toggleSettingsVisibility); // 変更時
                    });
                </script>
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
            source_post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0, /* 投稿ID (手動なら0) */
            source_type varchar(20) NOT NULL DEFAULT '', /* ★追加: ソース種別 (post, page, manual など) */
            source_title text NULL, /* ★追加: ソースタイトル (手動入力用など) */
            source_post_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00', /* 投稿更新日時 */
            source_text longtext NOT NULL, /* チャンクテキスト */
            vector_data longtext NOT NULL, /* ベクトルデータ (JSON) */
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, /* データ作成日時 */
            PRIMARY KEY  (id),
            INDEX idx_source_post_id (source_post_id), /* 既存インデックス */
            INDEX idx_source_post_modified (source_post_modified), /* 既存インデックス */
            INDEX idx_source_type (source_type) /* ★追加: タイプでの検索用インデックス */
        ) {$charset_collate};";

        // MySQLi のエラー例外を無効化
        if (isset($wpdb->dbh) && $wpdb->dbh instanceof mysqli) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        // dbDelta 関数でテーブルを作成・更新
        $result = dbDelta($sql);
    }
}
