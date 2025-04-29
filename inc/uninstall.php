<?php

/**
 * Edel AI Chatbot Plus アンインストール処理
 *
 * プラグインがWordPress管理画面から「削除」されたときに実行される。
 *
 * @package Edel_Ai_Chatbot_Plus
 */

// 直接アクセス禁止
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// --- 設定値を取得 ---
// プラグインのプレフィックスやオプション名を直接記述（定数は使えないため）
$option_name = 'EDEL_AI_CHATBOT_PLUS_settings';
$options = get_option($option_name);

// 設定値が存在し、かつ「削除する」('1') が選択されている場合のみ実行
if (isset($options['EDEL_AI_CHATBOT_PLUS_delete_on_uninstall']) && $options['EDEL_AI_CHATBOT_PLUS_delete_on_uninstall'] === '1') {

    global $wpdb;

    // --- 1. カスタムテーブルを削除 ---
    $table_name = $wpdb->prefix . 'EDEL_AI_CHATBOT_PLUS_vectors'; // テーブル名を直接記述
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

    // --- 2. オプションを削除 ---
    delete_option($option_name);

    // --- 3. 他に削除したいデータがあればここに追加 ---
    // 例: delete_transient(...), delete_metadata(...) など

    // (念のため) エラーログに記録
    error_log('Edel AI Chatbot Plus: Data deleted upon uninstallation.');
} else {
    // (念のため) 削除しない場合もログに記録
    error_log('Edel AI Chatbot Plus: Data preserved upon uninstallation (option not set or disabled).');
}
