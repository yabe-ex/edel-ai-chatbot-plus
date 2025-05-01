<?php

/**
 * Plugin Name: Edel AI Chatbot Plus
 * Plugin URI: https://edel-hearts.com/edel-ai-chatbot-plus
 * Description: テキスト入力でサイト情報を簡単学習。OpenAI APIを利用したフローティングAIチャットボットをWordPressに追加します。
 * Version: 1.0.0
 * Author: yabea
 * Author URI: https://edel-hearts.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Tested up to: 6.5
 */

namespace Edel\AiChatbotPlus;

// Exit if accessed directly.
if (!defined('ABSPATH')) exit();

use Edel\AiChatbotPlus\Admin\EdelAiChatbotAdmin;
use Edel\AiChatbotPlus\Front\EdelAiChatbotFront;
use Edel\AiChatbotPlus\API\EdelAiChatbotOpenAIAPI;
use Edel\AiChatbotPlus\API\EdelAiChatbotPlusPineconeClient;
use Edel\AiChatbotPlus\API\EdelAiChatbotPlusGeminiAPI;
use Edel\AiChatbotPlus\API\EdelAiChatbotPlusClaudeAPI;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$info = get_file_data(__FILE__, array('plugin_name' => 'Plugin Name', 'version' => 'Version'));

define('EDEL_AI_CHATBOT_PLUS_URL', plugins_url('', __FILE__));  // http(s)://〜/wp-content/plugins/edel-ai-chatbot-plus（URL）
define('EDEL_AI_CHATBOT_PLUS_PATH', dirname(__FILE__));         // /home/〜/wp-content/plugins/edel-ai-chatbot-plus (パス)
define('EDEL_AI_CHATBOT_PLUS_BASENAME', plugin_basename(__FILE__));
define('EDEL_AI_CHATBOT_PLUS_NAME', $info['plugin_name']);
define('EDEL_AI_CHATBOT_PLUS_SLUG', 'edel-ai-chatbot-plus');
define('EDEL_AI_CHATBOT_PLUS_PREFIX', 'edel_ai_chatbot_plus_');
define('EDEL_AI_CHATBOT_PLUS_VERSION', $info['version']);
define('EDEL_AI_CHATBOT_PLUS_DEVELOP', true);

require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-admin.php';
require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-front.php';
require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-openai-api.php';
require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-pinecone-client.php';
require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-google-gemini-api.php';
require_once EDEL_AI_CHATBOT_PLUS_PATH . '/inc/class-anthropic-claude-api.php';

register_activation_hook(__FILE__, array(Admin\EdelAiChatbotAdmin::class, 'create_custom_table'));

class EdelAiChatbotPlus {
    private $admin_instance;
    private $front_instance;

    public function __construct() {
        $this->admin_instance = new Admin\EdelAiChatbotAdmin();
        $this->front_instance = new Front\EdelAiChatbotFront();
    }

    public function init() {
        add_action('admin_menu', array($this->admin_instance, 'admin_menu'));
        add_filter('plugin_action_links_' . EDEL_AI_CHATBOT_PLUS_BASENAME, array($this->admin_instance, 'plugin_action_links'));
        add_action('admin_enqueue_scripts', array($this->admin_instance, 'admin_enqueue'));

        $learning_action_hook = EDEL_AI_CHATBOT_PLUS_PREFIX . 'process_post_learning';
        add_action($learning_action_hook, array($this->admin_instance, 'process_single_post_learning_cron'), 10, 1); // ← _cron を指定

        // ★ Ajaxループ用アクションフック (変更なし、handle_batch_learning_ajax を呼ぶ) ★
        $batch_ajax_action = EDEL_AI_CHATBOT_PLUS_PREFIX . 'batch_learning';
        add_action('wp_ajax_' . $batch_ajax_action, array($this->admin_instance, 'handle_batch_learning_ajax'));

        // ★ admin_action フック (変更なし、handle_learn_single_post_action を呼ぶ) ★
        $learn_action_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'learn_post';
        add_action('admin_action_' . $learn_action_name, array($this->admin_instance, 'handle_learn_single_post_action'));

        add_action('wp_enqueue_scripts', array($this->front_instance, 'front_enqueue'));
        add_action('wp_footer', array($this->front_instance, 'output_floating_chatbot_ui'));

        $ajax_action = EDEL_AI_CHATBOT_PLUS_PREFIX . 'send_message';
        add_action('wp_ajax_' . $ajax_action, array($this->front_instance, 'handle_send_message'));
        add_action('wp_ajax_nopriv_' . $ajax_action, array($this->front_instance, 'handle_send_message'));

        $batch_ajax_action = EDEL_AI_CHATBOT_PLUS_PREFIX . 'batch_learning'; // JSで指定したaction名
        add_action('wp_ajax_' . $batch_ajax_action, array($this->admin_instance, 'handle_batch_learning_ajax'));

        $learn_action_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'learn_post';
        add_action('admin_action_' . $learn_action_name, array($this->admin_instance, 'handle_learn_single_post_action'));

        add_action('admin_notices', array($this->admin_instance, 'display_admin_notices'));

        if (is_admin()) {
            add_filter('post_row_actions', array($this->admin_instance, 'add_post_row_actions'), 10, 2); // 投稿用
            add_filter('page_row_actions', array($this->admin_instance, 'add_post_row_actions'), 10, 2); // 固定ページ用

            $option_name = EDEL_AI_CHATBOT_PLUS_PREFIX . 'settings';
            $options = get_option($option_name, []);
            $learning_post_types = $options[EDEL_AI_CHATBOT_PLUS_PREFIX . 'learning_post_types'] ?? ['post', 'page']; // デフォルト

            if (!empty($learning_post_types) && is_array($learning_post_types)) {
                foreach ($learning_post_types as $post_type) {
                    // 列を追加するフィルター
                    add_filter('manage_' . $post_type . '_posts_columns', array($this->admin_instance, 'add_ai_status_column'));
                    // 列の内容を表示するアクション
                    add_action('manage_' . $post_type . '_posts_custom_column', array($this->admin_instance, 'display_ai_status_column'), 10, 2);
                }
            }
        }
    }
}

$edelAiChatbotInstance = new EdelAiChatbotPlus();
$edelAiChatbotInstance->init();

function edel_ai_chatbot_plus_initialize_updater() {
    // ユーザー指定のファイルパスを使用
    $puc_file = EDEL_AI_CHATBOT_PLUS_PATH . '/plugin-update-checker/load-v5p5.php';

    if (! file_exists($puc_file)) {
        error_log('Edel AI Chatbot Plus: PUC file not found at ' . $puc_file); // エラーログは残すことを推奨
        return;
    }
    require_once $puc_file;

    try {
        PucFactory::buildUpdateChecker(
            'https://edel-hearts.com/wp-content/uploads/version/edel-ai-chatbot-plus.json',
            __FILE__,
            EDEL_AI_CHATBOT_PLUS_SLUG
        );
    } catch (\Exception $e) {
        error_log('Error initializing PUC for Edel AI Chatbot Plus: ' . $e->getMessage());
    }
}

add_action('plugins_loaded', __NAMESPACE__ . '\edel_ai_chatbot_plus_initialize_updater');
