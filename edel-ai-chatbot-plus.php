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

        add_action('wp_enqueue_scripts', array($this->front_instance, 'front_enqueue'));
        add_action('wp_footer', array($this->front_instance, 'output_floating_chatbot_ui'));

        $ajax_action = EDEL_AI_CHATBOT_PLUS_PREFIX . 'send_message';
        add_action('wp_ajax_' . $ajax_action, array($this->front_instance, 'handle_send_message'));
        add_action('wp_ajax_nopriv_' . $ajax_action, array($this->front_instance, 'handle_send_message'));
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
