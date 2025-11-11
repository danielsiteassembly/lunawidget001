<?php
/**
 * Plugin Name: Luna Chat — Widget (Client)
 * Description: Floating chat widget + shortcode with conversation logging. Pulls client facts from Visible Light Hub and blends them with AI answers. Includes chat history hydration and Hub-gated REST endpoints.
 * Version:     1.7.0
 * Author:      Visible Light
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

if (defined('LUNA_WIDGET_ONLY_BOOTSTRAPPED')) {
  return;
}
define('LUNA_WIDGET_ONLY_BOOTSTRAPPED', true);

/* ============================================================
 * CONSTANTS & OPTIONS
 * ============================================================ */
if (!defined('LUNA_WIDGET_PLUGIN_VERSION')) define('LUNA_WIDGET_PLUGIN_VERSION', '1.7.0');
if (!defined('LUNA_WIDGET_OPT_COMPOSER_ENABLED')) define('LUNA_WIDGET_OPT_COMPOSER_ENABLED', 'luna_composer_enabled');
if (!defined('LUNA_WIDGET_ASSET_URL')) define('LUNA_WIDGET_ASSET_URL', plugin_dir_url(__FILE__));

function luna_composer_default_prompts() {
  $defaults = array(
    array(
      'label'  => 'What can Luna help me with?',
      'prompt' => "Hey Luna! What can you help me with today?",
    ),
    array(
      'label'  => 'Site health overview',
      'prompt' => 'Can you give me a quick health check of my WordPress site?',
    ),
    array(
      'label'  => 'Pending updates',
      'prompt' => 'Do I have any plugin, theme, or WordPress core updates waiting?',
    ),
    array(
      'label'  => 'Security status',
      'prompt' => 'Is my SSL certificate active and are there any security concerns?',
    ),
    array(
      'label'  => 'Content inventory',
      'prompt' => 'How many pages and posts are on the site right now?',
    ),
    array(
      'label'  => 'Help contact info',
      'prompt' => 'Remind me how someone can contact our team for help.',
    ),
  );

  return apply_filters('luna_composer_default_prompts', $defaults);
}

define('LUNA_WIDGET_OPT_LICENSE',         'luna_widget_license');
define('LUNA_WIDGET_OPT_MODE',            'luna_widget_mode');           // 'shortcode' | 'widget'
define('LUNA_WIDGET_OPT_SETTINGS',        'luna_widget_ui_settings');    // array
define('LUNA_WIDGET_OPT_LICENSE_SERVER',  'luna_widget_license_server'); // hub base URL
define('LUNA_WIDGET_OPT_LAST_PING',       'luna_widget_last_ping');      // array {ts,url,code,err,body}
define('LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY', 'luna_widget_supercluster_only'); // '1' | '0'

/* Cache */
define('LUNA_CACHE_PROFILE_TTL',          300); // 5 min

/* Hub endpoints map (your Hub can alias to these) */
$GLOBALS['LUNA_HUB_ENDPOINTS'] = array(
  'profile'  => '/wp-json/vl-hub/v1/profile',   // preferred single profile
  'security' => '/wp-json/vl-hub/v1/security',  // fallback piece
  'content'  => '/wp-json/vl-hub/v1/content',   // fallback piece
  'users'    => '/wp-json/vl-hub/v1/users',     // fallback piece
);

/* ============================================================
 * ACTIVATION / DEACTIVATION
 * ============================================================ */
register_activation_hook(__FILE__, function () {
  if (!get_option(LUNA_WIDGET_OPT_MODE, null)) {
    update_option(LUNA_WIDGET_OPT_MODE, 'widget');
  }
  if (!get_option(LUNA_WIDGET_OPT_SETTINGS, null)) {
    update_option(LUNA_WIDGET_OPT_SETTINGS, array(
      'position'    => 'bottom-right',
      'title'       => 'Luna Chat',
      'avatar_url'  => '',
      'header_text' => "Hi, I'm Luna",
      'sub_text'    => 'How can I help today?',
    ));
  }
  if (!get_option(LUNA_WIDGET_OPT_LICENSE_SERVER, null)) {
    update_option(LUNA_WIDGET_OPT_LICENSE_SERVER, 'https://visiblelight.ai');
  }
  if (get_option(LUNA_WIDGET_OPT_COMPOSER_ENABLED, null) === null) {
    update_option(LUNA_WIDGET_OPT_COMPOSER_ENABLED, '1');
  }
  if (!wp_next_scheduled('luna_widget_heartbeat_event')) {
    wp_schedule_event(time() + 60, 'hourly', 'luna_widget_heartbeat_event');
  }
});

register_deactivation_hook(__FILE__, function () {
  $ts = wp_next_scheduled('luna_widget_heartbeat_event');
  if ($ts) wp_unschedule_event($ts, 'luna_widget_heartbeat_event');
});

/* ============================================================
 * ADMIN MENU (Top-level)
 * ============================================================ */
add_action('admin_menu', function () {
  add_menu_page(
    'Luna Widget',
    'Luna Widget',
    'manage_options',
    'luna-widget',
    'luna_widget_admin_page',
    'dashicons-format-chat',
    64
  );
  add_submenu_page(
    'luna-widget',
    'Compose',
    'Compose',
    'manage_options',
    'luna-widget-compose',
    'luna_widget_compose_admin_page'
  );
  add_submenu_page(
    'luna-widget',
    'Settings',
    'Settings',
    'manage_options',
    'luna-widget',
    'luna_widget_admin_page'
  );
  add_submenu_page(
    'luna-widget',
    'Chats',
    'Chats',
    'manage_options',
    'luna-widget-chats',
    'luna_widget_chats_admin_page'
  );
  add_submenu_page(
    'luna-widget',
    'Keywords',
    'Keywords',
    'manage_options',
    'luna-widget-keywords',
    'luna_widget_keywords_admin_page'
  );
  
  // Add JavaScript for keywords page
  add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'luna-widget_page_luna-widget-keywords') {
      add_action('admin_footer', 'luna_keywords_admin_scripts');
    }
  });
  add_submenu_page(
    'luna-widget',
    'Analytics',
    'Analytics',
    'manage_options',
    'luna-widget-analytics',
    'luna_widget_analytics_admin_page'
  );
});

/* ============================================================
 * SETTINGS
 * ============================================================ */
add_action('admin_init', function () {
  register_setting('luna_widget_settings', LUNA_WIDGET_OPT_LICENSE, array(
    'type' => 'string',
    'sanitize_callback' => function($v){ return preg_replace('/[^A-Za-z0-9\-\_]/','', (string)$v); },
    'default' => '',
  ));
  register_setting('luna_widget_settings', 'luna_openai_api_key', array(
    'type' => 'string',
    'sanitize_callback' => function($v){ return trim((string)$v); },
    'default' => '',
  ));
  register_setting('luna_widget_settings', LUNA_WIDGET_OPT_LICENSE_SERVER, array(
    'type' => 'string',
    'sanitize_callback' => function($v){
      $v = trim((string)$v);
      if ($v === '') return 'https://visiblelight.ai';
      $v = preg_replace('#/+$#','',$v);
      $v = preg_replace('#^http://#i','https://',$v);
      return esc_url_raw($v);
    },
    'default' => 'https://visiblelight.ai',
  ));
  register_setting('luna_widget_settings', LUNA_WIDGET_OPT_MODE, array(
    'type' => 'string',
    'sanitize_callback' => function($v){ return in_array($v, array('shortcode','widget'), true) ? $v : 'widget'; },
    'default' => 'widget',
  ));
  register_setting('luna_widget_settings', LUNA_WIDGET_OPT_SETTINGS, array(
    'type' => 'array',
    'sanitize_callback' => function($a){
      $a = is_array($a) ? $a : array();
      $pos = isset($a['position']) ? strtolower((string)$a['position']) : 'bottom-right';
      $valid_positions = array('top-left','top-center','top-right','bottom-left','bottom-center','bottom-right');
      if (!in_array($pos, $valid_positions, true)) $pos = 'bottom-right';
      return array(
        'position'    => $pos,
        'title'       => sanitize_text_field(isset($a['title']) ? $a['title'] : 'Luna Chat'),
        'avatar_url'  => esc_url_raw(isset($a['avatar_url']) ? $a['avatar_url'] : ''),
        'header_text' => sanitize_text_field(isset($a['header_text']) ? $a['header_text'] : "Hi, I'm Luna"),
        'sub_text'    => sanitize_text_field(isset($a['sub_text']) ? $a['sub_text'] : 'How can I help today?'),
        'button_desc_chat'    => sanitize_textarea_field(isset($a['button_desc_chat']) ? $a['button_desc_chat'] : 'Start a conversation with Luna to ask questions and get answers about your digital universe.'),
        'button_desc_report'  => sanitize_textarea_field(isset($a['button_desc_report']) ? $a['button_desc_report'] : 'Generate comprehensive reports about your site health, performance, and security.'),
        'button_desc_compose' => sanitize_textarea_field(isset($a['button_desc_compose']) ? $a['button_desc_compose'] : 'Access Luna Composer to use canned prompts and responses for quick interactions.'),
        'button_desc_automate' => sanitize_textarea_field(isset($a['button_desc_automate']) ? $a['button_desc_automate'] : 'Set up automated workflows and tasks with Luna to streamline your operations.'),
      );
    },
    'default' => array(),
  ));

  register_setting('luna_composer_settings', LUNA_WIDGET_OPT_COMPOSER_ENABLED, array(
    'type' => 'string',
    'sanitize_callback' => function($value) {
      return $value === '1' ? '1' : '0';
    },
    'default' => '1',
  ));

  register_setting('luna_widget_settings', LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY, array(
    'type' => 'string',
    'sanitize_callback' => function($value) {
      return $value === '1' ? '1' : '0';
    },
    'default' => '0',
  ));
});

/* Settings page */
function luna_widget_admin_page(){
  if (!current_user_can('manage_options')) return;
  $mode  = get_option(LUNA_WIDGET_OPT_MODE, 'widget');
  $ui    = get_option(LUNA_WIDGET_OPT_SETTINGS, array());
  $lic   = get_option(LUNA_WIDGET_OPT_LICENSE, '');
  $hub   = luna_widget_hub_base();
  $last  = get_option(LUNA_WIDGET_OPT_LAST_PING, array());
  ?>
  <div class="wrap">
    <h1>Luna Chat — Widget</h1>

    <div class="notice notice-info" style="padding:8px 12px;margin-top:10px;">
      <strong>Hub connection:</strong>
      <?php if (!empty($last['code'])): ?>
        Response <code><?php echo (int)$last['code']; ?></code> at <?php echo esc_html(isset($last['ts']) ? $last['ts'] : ''); ?>.
      <?php else: ?>
        No heartbeat recorded yet.
      <?php endif; ?>
      <div style="margin-top:6px;display:flex;gap:8px;align-items:center;">
        <button type="button" class="button" id="luna-test-activation">Test Activation</button>
        <button type="button" class="button" id="luna-test-heartbeat">Heartbeat Now</button>
        <button type="button" class="button button-primary" id="luna-sync-to-hub">Sync to Hub</button>
        <span style="opacity:.8;">Hub: <?php echo esc_html($hub); ?></span>
      </div>
    </div>

    <form method="post" action="options.php">
      <?php settings_fields('luna_widget_settings'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">Corporate License Code</th>
          <td>
            <input type="text" name="<?php echo esc_attr(LUNA_WIDGET_OPT_LICENSE); ?>" value="<?php echo esc_attr($lic); ?>" class="regular-text code" placeholder="VL-XXXX-XXXX-XXXX" />
            <p class="description">Required for secured Hub data.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">License Server (Hub)</th>
          <td>
            <input type="url" name="<?php echo esc_attr(LUNA_WIDGET_OPT_LICENSE_SERVER); ?>" value="<?php echo esc_url($hub); ?>" class="regular-text code" placeholder="https://visiblelight.ai" />
            <p class="description">HTTPS enforced; trailing slashes removed automatically.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Embedding mode</th>
          <td>
            <label style="display:block;margin-bottom:.4rem;">
              <input type="radio" name="<?php echo esc_attr(LUNA_WIDGET_OPT_MODE); ?>" value="shortcode" <?php checked($mode, 'shortcode'); ?>>
              Shortcode only (<code>[luna_chat]</code>)
            </label>
            <label>
              <input type="radio" name="<?php echo esc_attr(LUNA_WIDGET_OPT_MODE); ?>" value="widget" <?php checked($mode, 'widget'); ?>>
              Floating chat widget (site-wide)
            </label>
            <br>
            <label style="display:block;margin-top:.4rem;">
              <input type="checkbox" name="<?php echo esc_attr(LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY); ?>" value="1" <?php checked(get_option(LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY, '0'), '1'); ?>>
              Supercluster only
            </label>
            <p class="description" style="margin-top:.25rem;margin-left:1.5rem;">When enabled, the widget will only appear in Supercluster and not on the frontend site.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Widget UI</th>
          <td>
            <label style="display:block;margin:.25rem 0;">
              <span style="display:inline-block;width:140px;">Title</span>
              <input type="text" name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[title]" value="<?php echo esc_attr(isset($ui['title']) ? $ui['title'] : 'Luna Chat'); ?>" />
            </label>
            <label style="display:block;margin:.25rem 0;">
              <span style="display:inline-block;width:140px;">Avatar URL</span>
              <input type="url" name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[avatar_url]" value="<?php echo esc_url(isset($ui['avatar_url']) ? $ui['avatar_url'] : ''); ?>" class="regular-text code" placeholder="https://…/luna.png" />
            </label>
            <label style="display:block;margin:.25rem 0;">
              <span style="display:inline-block;width:140px;">Header text</span>
              <input type="text" name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[header_text]" value="<?php echo esc_attr(isset($ui['header_text']) ? $ui['header_text'] : "Hi, I'm Luna"); ?>" />
            </label>
            <label style="display:block;margin:.25rem 0;">
              <span style="display:inline-block;width:140px;">Sub text</span>
              <input type="text" name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[sub_text]" value="<?php echo esc_attr(isset($ui['sub_text']) ? $ui['sub_text'] : 'How can I help today?'); ?>" />
            </label>
            <label style="display:block;margin:.25rem 0;">
              <span style="display:inline-block;width:140px;">Position</span>
              <?php $pos = isset($ui['position']) ? $ui['position'] : 'bottom-right'; ?>
              <select name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[position]">
                <?php foreach (array('top-left','top-center','top-right','bottom-left','bottom-center','bottom-right') as $p): ?>
                  <option value="<?php echo esc_attr($p); ?>" <?php selected($p, $pos); ?>><?php echo esc_html($p); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </td>
        </tr>
        <tr>
          <th scope="row">Button Descriptions</th>
          <td>
            <p class="description" style="margin-bottom:1rem;">Customize the descriptions that appear when users hover over the "?" icon on each Luna greeting button.</p>
            <label style="display:block;margin:.75rem 0;">
              <span style="display:inline-block;width:140px;vertical-align:top;padding-top:4px;">Luna Chat</span>
              <textarea name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[button_desc_chat]" rows="2" style="width:400px;max-width:100%;"><?php echo esc_textarea(isset($ui['button_desc_chat']) ? $ui['button_desc_chat'] : 'Start a conversation with Luna to ask questions and get answers about your digital universe.'); ?></textarea>
            </label>
            <label style="display:block;margin:.75rem 0;">
              <span style="display:inline-block;width:140px;vertical-align:top;padding-top:4px;">Luna Report</span>
              <textarea name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[button_desc_report]" rows="2" style="width:400px;max-width:100%;"><?php echo esc_textarea(isset($ui['button_desc_report']) ? $ui['button_desc_report'] : 'Generate comprehensive reports about your site health, performance, and security.'); ?></textarea>
            </label>
            <label style="display:block;margin:.75rem 0;">
              <span style="display:inline-block;width:140px;vertical-align:top;padding-top:4px;">Luna Compose</span>
              <textarea name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[button_desc_compose]" rows="2" style="width:400px;max-width:100%;"><?php echo esc_textarea(isset($ui['button_desc_compose']) ? $ui['button_desc_compose'] : 'Access Luna Composer to use canned prompts and responses for quick interactions.'); ?></textarea>
            </label>
            <label style="display:block;margin:.75rem 0;">
              <span style="display:inline-block;width:140px;vertical-align:top;padding-top:4px;">Luna Automate</span>
              <textarea name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[button_desc_automate]" rows="2" style="width:400px;max-width:100%;"><?php echo esc_textarea(isset($ui['button_desc_automate']) ? $ui['button_desc_automate'] : 'Set up automated workflows and tasks with Luna to streamline your operations.'); ?></textarea>
            </label>
          </td>
        </tr>
        <tr>
          <th scope="row">OpenAI API key</th>
          <td>
            <input type="password" name="luna_openai_api_key"
                   value="<?php echo esc_attr( get_option('luna_openai_api_key','') ); ?>"
                   class="regular-text code" placeholder="sk-..." />
            <p class="description">If present, AI answers are blended with Hub facts. Otherwise, deterministic replies only.</p>
          </td>
        </tr>
      </table>
      <?php submit_button('Save changes'); ?>
    </form>
  </div>

  <script>
    (function(){
      const nonce = '<?php echo wp_create_nonce('wp_rest'); ?>';
      async function call(path){
        try{ await fetch(path, {method:'POST', headers:{'X-WP-Nonce': nonce}}); location.reload(); }
        catch(e){ alert('Request failed. See console.'); console.error(e); }
      }
      document.addEventListener('click', function(e){
        if(e.target && e.target.id==='luna-test-activation'){ e.preventDefault(); call('<?php echo esc_url_raw( rest_url('luna_widget/v1/ping-hub') ); ?>'); }
        if(e.target && e.target.id==='luna-test-heartbeat'){ e.preventDefault(); call('<?php echo esc_url_raw( rest_url('luna_widget/v1/heartbeat-now') ); ?>'); }
        if(e.target && e.target.id==='luna-sync-to-hub'){ e.preventDefault(); call('<?php echo esc_url_raw( rest_url('luna_widget/v1/sync-to-hub') ); ?>'); }
      });
    })();
  </script>
  <?php
}

function luna_widget_compose_admin_page() {
  if (!current_user_can('manage_options')) {
    return;
  }

  $enabled = get_option(LUNA_WIDGET_OPT_COMPOSER_ENABLED, '1') === '1';
  $history = luna_composer_recent_entries(10);
  $canned  = get_posts(array(
    'post_type'        => 'luna_canned_response',
    'post_status'      => 'publish',
    'numberposts'      => 10,
    'orderby'          => array('menu_order' => 'ASC', 'title' => 'ASC'),
    'order'            => 'ASC',
    'suppress_filters' => false,
  ));

  ?>
  <div class="wrap luna-composer-admin">
    <h1>Luna Composer</h1>
    <p class="description">Manage the Luna Composer experience alongside the floating widget without installing additional plugins.</p>

    <form method="post" action="options.php" style="margin-bottom:2rem;">
      <?php settings_fields('luna_composer_settings'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">Status</th>
          <td>
            <label>
              <input type="checkbox" name="<?php echo esc_attr(LUNA_WIDGET_OPT_COMPOSER_ENABLED); ?>" value="1" <?php checked($enabled); ?> />
              <?php esc_html_e('Activate Luna Composer front-end shortcode and REST handling', 'luna'); ?>
            </label>
            <p class="description">When disabled, the shortcode renders a notice and API requests return a friendly deactivation message.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Shortcode</th>
          <td>
            <code style="font-size:1.1em;">[luna_composer]</code>
            <p class="description">Place this shortcode on any page or post to embed the Composer interface. It automatically shares canned prompts and the same REST endpoint as the Luna widget.</p>
          </td>
        </tr>
      </table>
      <?php submit_button(__('Save Composer Settings', 'luna')); ?>
    </form>

    <h2>Recent Composer History</h2>
    <?php if (!empty($history)) : ?>
      <ol class="luna-composer-history" style="max-width:900px;">
        <?php foreach ($history as $entry) :
          $prompt = get_post_meta($entry->ID, 'prompt', true);
          $answer = get_post_meta($entry->ID, 'answer', true);
          $timestamp = (int) get_post_meta($entry->ID, 'timestamp', true);
          $meta = get_post_meta($entry->ID, 'meta', true);
          $source = is_array($meta) && !empty($meta['source']) ? $meta['source'] : 'unknown';
          $time_display = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : get_the_date('', $entry);
          ?>
          <li style="margin-bottom:1.5rem;padding:1rem;border:1px solid #dfe4ea;border-radius:8px;background:#fff;">
            <strong><?php echo esc_html($time_display); ?></strong>
            <?php
            // Get feedback status
            $feedback_meta = get_post_meta($entry->ID, 'feedback', true);
            $feedback_status = '';
            if ($feedback_meta === 'like') {
              $feedback_status = '<span style="display:inline-block;margin-left:12px;padding:4px 8px;background:#8D8C00;color:#fff;border-radius:4px;font-size:0.85em;font-weight:600;">Liked</span>';
            } elseif ($feedback_meta === 'dislike') {
              $feedback_status = '<span style="display:inline-block;margin-left:12px;padding:4px 8px;background:#d63638;color:#fff;border-radius:4px;font-size:0.85em;font-weight:600;">Disliked</span>';
            }
            echo $feedback_status;
            ?>
            <div style="margin-top:.5rem;">
              <span style="display:block;font-weight:600;">Prompt:</span>
              <div style="margin-top:.35rem;white-space:pre-wrap;"><?php echo esc_html(wp_trim_words($prompt, 50, '…')); ?></div>
            </div>
            <div style="margin-top:.75rem;">
              <span style="display:block;font-weight:600;">Response (<?php echo esc_html($source); ?>):</span>
              <div style="margin-top:.35rem;white-space:pre-wrap;"><?php echo esc_html(wp_trim_words($answer, 120, '…')); ?></div>
            </div>
            <div style="margin-top:.5rem;font-size:.9em;">
              <a href="<?php echo esc_url(get_edit_post_link($entry->ID)); ?>">View full entry</a>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php else : ?>
      <p>No composer history recorded yet.</p>
    <?php endif; ?>

    <h2>Canned Prompts &amp; Responses</h2>
    <?php if (!empty($canned)) : ?>
      <table class="widefat fixed striped" style="max-width:900px;">
        <thead>
          <tr>
            <th scope="col">Prompt</th>
            <th scope="col" style="width:35%;">Response preview</th>
            <th scope="col" style="width:120px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($canned as $post) :
            $content = luna_widget_prepare_canned_response_content($post->post_content);
            ?>
            <tr>
              <td><?php echo esc_html($post->post_title); ?></td>
              <td><?php echo esc_html(wp_trim_words($content, 30, '…')); ?></td>
              <td><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>">Edit</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin-top:1rem;"><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=luna_canned_response')); ?>">Manage canned responses</a></p>
    <?php else : ?>
      <p>No canned responses found. <a href="<?php echo esc_url(admin_url('post-new.php?post_type=luna_canned_response')); ?>">Create your first canned response</a> to provide offline answers when the Hub is unavailable.</p>
    <?php endif; ?>
  </div>
  <?php
}

function luna_widget_chats_admin_page() {
  if (!current_user_can('manage_options')) {
    return;
  }

  $conversations = luna_chat_recent_conversations(100);

  ?>
  <div class="wrap luna-chats-admin">
    <h1>Luna Chat Conversations</h1>
    <p class="description">View all chat conversations from the Luna Chat widget. Click on any conversation to view the full transcript.</p>

    <h2>Chat Sessions</h2>
    <?php if (!empty($conversations)) : ?>
      <table class="wp-list-table widefat fixed striped" style="margin-top:1rem;">
        <thead>
          <tr>
            <th style="width:200px;">Date & Time</th>
            <th style="width:80px;">Turns</th>
            <th style="width:150px;">Session ID</th>
            <th style="width:150px;">Status</th>
            <th style="width:100px;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($conversations as $conversation) :
          $transcript = get_post_meta($conversation->ID, 'transcript', true);
          $cid = get_post_meta($conversation->ID, 'luna_cid', true);
          $session_closed = get_post_meta($conversation->ID, 'session_closed', true);
          $session_closed_reason = get_post_meta($conversation->ID, 'session_closed_reason', true);
          
          if (!is_array($transcript)) {
            $transcript = array();
          }
          
          $total_turns = count($transcript);
          $time_display = get_the_date(get_option('date_format') . ' ' . get_option('time_format'), $conversation);
          $conversation_id = 'luna-chat-' . $conversation->ID;
            
            // Get first user message for preview
            $first_user_msg = '';
            $first_assistant_msg = '';
            foreach ($transcript as $turn) {
              if (!empty($turn['user']) && $first_user_msg === '') {
                $first_user_msg = wp_trim_words($turn['user'], 15);
              }
              if (!empty($turn['assistant']) && $first_assistant_msg === '') {
                $first_assistant_msg = wp_trim_words($turn['assistant'], 15);
              }
              if ($first_user_msg !== '' && $first_assistant_msg !== '') break;
            }
            ?>
            <tr>
              <td>
                <strong style="cursor:pointer;color:#2271b1;" onclick="toggleTranscript('<?php echo esc_js($conversation_id); ?>')">
                  <?php echo esc_html($time_display); ?>
                </strong>
              </td>
              <td><?php echo esc_html($total_turns); ?></td>
              <td style="font-size:.85em;color:#8c8f94;">
                <?php if ($cid) : ?>
                  <?php echo esc_html(substr($cid, 0, 16)); ?>…
                <?php else : ?>
                  —
                <?php endif; ?>
              </td>
              <td>
                <?php if ($session_closed) : ?>
                  <span style="color:#d63638;">Closed</span>
                  <?php if ($session_closed_reason) : ?>
                    <br><span style="font-size:.85em;color:#8c8f94;">(<?php echo esc_html($session_closed_reason); ?>)</span>
                  <?php endif; ?>
                <?php else : ?>
                  <span style="color:#00a32a;">Active</span>
                <?php endif; ?>
              </td>
              <td>
                <button type="button" class="button button-small" onclick="toggleTranscript('<?php echo esc_js($conversation_id); ?>')">
                  <span class="toggle-text-<?php echo esc_attr($conversation_id); ?>">View</span>
                </button>
              </td>
            </tr>
            <tr id="<?php echo esc_attr($conversation_id); ?>" style="display:none;">
              <td colspan="5" style="padding:1.5rem;background:#f6f7f7;border-top:2px solid #2271b1;">
              <h3 style="margin-top:0;margin-bottom:1rem;">Full Transcript</h3>
              <?php if (!empty($transcript)) : ?>
                  <div style="max-height:600px;overflow-y:auto;background:#fff;padding:1rem;border-radius:4px;border:1px solid #dcdcde;">
                  <?php foreach ($transcript as $index => $turn) :
                      $user_msg = isset($turn['user']) ? trim($turn['user']) : '';
                      $assistant_msg = isset($turn['assistant']) ? trim($turn['assistant']) : '';
                      ?>
                      <?php if ($user_msg) : ?>
                        <div style="margin-bottom:1rem;padding:.75rem;background:#e7f5fe;border-left:3px solid #2271b1;border-radius:4px;">
                          <div style="font-size:.85em;color:#2271b1;margin-bottom:.25rem;font-weight:600;">User</div>
                          <div style="white-space:pre-wrap;word-wrap:break-word;color:#1d2327;"><?php echo esc_html($user_msg); ?></div>
                        </div>
                      <?php endif; ?>
                      <?php if ($assistant_msg) : ?>
                        <div style="margin-bottom:1rem;padding:.75rem;background:#f0f6fc;border-left:3px solid #00a32a;border-radius:4px;">
                          <div style="font-size:.85em;color:#00a32a;margin-bottom:.25rem;font-weight:600;">Luna</div>
                          <div style="white-space:pre-wrap;word-wrap:break-word;color:#1d2327;"><?php echo esc_html($assistant_msg); ?></div>
                        </div>
                      <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              <?php else : ?>
                <p style="color:#646970;">No messages in this conversation.</p>
              <?php endif; ?>
              </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else : ?>
      <p>No chat conversations recorded yet.</p>
    <?php endif; ?>
  </div>
  
  <script>
    function toggleTranscript(conversationId) {
      const transcriptRow = document.getElementById(conversationId);
      const toggleTexts = document.querySelectorAll('.toggle-text-' + conversationId);
      
      if (transcriptRow && transcriptRow.style.display === 'none') {
        transcriptRow.style.display = 'table-row';
        toggleTexts.forEach(function(el) {
          el.textContent = 'Hide';
        });
      } else if (transcriptRow) {
        transcriptRow.style.display = 'none';
        toggleTexts.forEach(function(el) {
          el.textContent = 'View';
        });
      }
    }
  </script>
  
  <style>
    .luna-chat-history li {
      transition: box-shadow 0.2s;
    }
    .luna-chat-history li:hover {
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
  </style>
  <?php
}

/* ============================================================
 * HEARTBEAT / HUB HELPERS
 * ============================================================ */
function luna_widget_hub_base() {
  $base = (string) get_option(LUNA_WIDGET_OPT_LICENSE_SERVER, 'https://visiblelight.ai');
  $base = preg_replace('#/+$#','',$base);
  $base = preg_replace('#^http://#i','https://',$base);
  return $base ? $base : 'https://visiblelight.ai';
}
function luna_widget_hub_url($path = '') {
  $path = '/'.ltrim($path,'/');
  return luna_widget_hub_base() . $path;
}
function luna_widget_store_last_ping($url, $resp) {
  $log = array(
    'ts'   => gmdate('c'),
    'url'  => $url,
    'code' => is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp),
    'err'  => is_wp_error($resp) ? $resp->get_error_message() : '',
    'body' => is_wp_error($resp) ? '' : substr((string) wp_remote_retrieve_body($resp), 0, 500),
  );
  update_option(LUNA_WIDGET_OPT_LAST_PING, $log, false);
}
function luna_widget_try_activation() {
  $license = trim((string) get_option(LUNA_WIDGET_OPT_LICENSE, ''));
  if ($license === '') return;
  $body = array(
    'license'        => $license,
    'site_url'       => home_url('/'),
    'site_name'      => get_bloginfo('name'),
    'wp_version'     => get_bloginfo('version'),
    'plugin_version' => LUNA_WIDGET_PLUGIN_VERSION,
  );
  $url = luna_widget_hub_url('/wp-json/vl-license/v1/activate');
  $resp = wp_remote_post($url, array(
    'timeout' => 15,
    'headers' => array(
      'Content-Type'   => 'application/json',
      'X-Luna-License' => $license,
      'X-Luna-Site'    => home_url('/'),
    ),
    'body'    => wp_json_encode($body),
  ));
  luna_widget_store_last_ping($url, $resp);
}
function luna_widget_send_heartbeat() {
  $license = trim((string) get_option(LUNA_WIDGET_OPT_LICENSE, ''));
  if ($license === '') return;
  $body = array(
    'license'        => $license,
    'site_url'       => home_url('/'),
    'wp_version'     => get_bloginfo('version'),
    'plugin_version' => LUNA_WIDGET_PLUGIN_VERSION,
  );
  $url  = luna_widget_hub_url('/wp-json/vl-license/v1/heartbeat');
  $resp = wp_remote_post($url, array(
    'timeout' => 15,
    'headers' => array(
      'Content-Type'   => 'application/json',
      'X-Luna-License' => $license,
      'X-Luna-Site'    => home_url('/'),
    ),
    'body'    => wp_json_encode($body),
  ));
  luna_widget_store_last_ping($url, $resp);
}
add_action('luna_widget_heartbeat_event', function () {
  if (!wp_next_scheduled('luna_widget_heartbeat_event')) {
    wp_schedule_event(time() + 3600, 'hourly', 'luna_widget_heartbeat_event');
  }
  luna_widget_send_heartbeat();
});
add_action('update_option_' . LUNA_WIDGET_OPT_LICENSE, function($old, $new){
  if ($new && $new !== $old) { luna_widget_try_activation(); luna_widget_send_heartbeat(); luna_profile_cache_bust(true); }
}, 10, 2);
add_action('update_option_' . LUNA_WIDGET_OPT_LICENSE_SERVER, function($old, $new){
  if ($new && $new !== $old) { luna_widget_try_activation(); luna_widget_send_heartbeat(); luna_profile_cache_bust(true); }
}, 10, 2);

/* ============================================================
 * CONVERSATIONS: CPT + helpers
 * ============================================================ */
add_action('init', function () {
  register_post_type('luna_widget_convo', array(
    'label'        => 'Luna Conversations',
    'public'       => false,
    'show_ui'      => true,
    'show_in_menu' => false,
    'supports'     => array('title'),
    'map_meta_cap' => true,
  ));
});

/* ============================================================
 * COMPOSER ENTRIES CPT (history)
 * ============================================================ */
add_action('init', function () {
  $labels = array(
    'name'          => __('Compose', 'luna'),
    'singular_name' => __('Compose Entry', 'luna'),
  );

  register_post_type('luna_compose', array(
    'labels'              => $labels,
    'public'              => false,
    'show_ui'             => false,
    'show_in_menu'        => false,
    'capability_type'     => 'post',
    'map_meta_cap'        => true,
    'supports'            => array('title'),
  ));
});

/* ============================================================
 * CANNED RESPONSES FALLBACK
 * ============================================================ */
add_action('init', function () {
  $labels = array(
    'name'               => __('Canned Responses', 'luna'),
    'singular_name'      => __('Canned Response', 'luna'),
    'add_new'            => __('Add New', 'luna'),
    'add_new_item'       => __('Add New Canned Response', 'luna'),
    'edit_item'          => __('Edit Canned Response', 'luna'),
    'new_item'           => __('New Canned Response', 'luna'),
    'view_item'          => __('View Canned Response', 'luna'),
    'search_items'       => __('Search Canned Responses', 'luna'),
    'not_found'          => __('No canned responses found.', 'luna'),
    'not_found_in_trash' => __('No canned responses found in Trash.', 'luna'),
    'menu_name'          => __('Canned Responses', 'luna'),
  );

  register_post_type('luna_canned_response', array(
    'labels'              => $labels,
    'public'              => false,
    'show_ui'             => true,
    'show_in_menu'        => 'luna-widget',
    'show_in_rest'        => true,
    'capability_type'     => 'post',
    'map_meta_cap'        => true,
    'supports'            => array('title', 'editor', 'revisions'),
    'menu_icon'           => 'dashicons-text-page',
    'menu_position'       => 26,
  ));
});

function luna_widget_normalize_prompt_text($value) {
  $value = is_string($value) ? $value : '';
  $value = wp_strip_all_tags($value);
  $value = html_entity_decode($value, ENT_QUOTES, get_option('blog_charset', 'UTF-8'));
  $value = preg_replace('/\s+/u', ' ', $value);
  return trim($value);
}

function luna_widget_prepare_canned_response_content($content) {
  $content = (string) apply_filters('the_content', $content);
  $content = str_replace(array("\r\n", "\r"), "\n", $content);
  $content = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $content);
  $content = preg_replace('/<\/(p|div|li|h[1-6])\s*>/i', '</$1>\n\n', $content);
  $content = wp_strip_all_tags($content);
  $content = html_entity_decode($content, ENT_QUOTES, get_option('blog_charset', 'UTF-8'));
  $content = preg_replace("/\n{3,}/", "\n\n", $content);
  return trim($content);
}

function luna_widget_find_canned_response($prompt) {
  $normalized = luna_widget_normalize_prompt_text($prompt);
  if ($normalized === '') {
    return null;
  }

  $posts = get_posts(array(
    'post_type'        => 'luna_canned_response',
    'post_status'      => 'publish',
    'numberposts'      => -1,
    'orderby'          => array('menu_order' => 'ASC', 'title' => 'ASC'),
    'order'            => 'ASC',
    'suppress_filters' => false,
  ));

  if (empty($posts)) {
    return null;
  }

  $normalized_lc = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
  $best = null;
  $best_score = 0.0;

  foreach ($posts as $post) {
    $title_normalized = luna_widget_normalize_prompt_text($post->post_title);
    if ($title_normalized === '') {
      continue;
    }
    $title_lc = function_exists('mb_strtolower') ? mb_strtolower($title_normalized, 'UTF-8') : strtolower($title_normalized);

    if ($title_lc === $normalized_lc) {
      return array(
        'id'      => $post->ID,
        'title'   => $post->post_title,
        'content' => luna_widget_prepare_canned_response_content($post->post_content),
      );
    }

    $score = 0.0;
    if (function_exists('similar_text')) {
      similar_text($normalized_lc, $title_lc, $percent);
      $score = (float) $percent;
    } elseif (function_exists('levenshtein')) {
      $distance = levenshtein($normalized_lc, $title_lc);
      $max_len = max(strlen($normalized_lc), strlen($title_lc), 1);
      $score = 100.0 - (min($distance, $max_len) / $max_len * 100.0);
    } else {
      $score = strpos($normalized_lc, $title_lc) !== false || strpos($title_lc, $normalized_lc) !== false ? 100.0 : 0.0;
    }

    if ($score > $best_score) {
      $best_score = $score;
      $best = $post;
    }
  }

  if ($best && $best_score >= 55.0) {
    return array(
      'id'      => $best->ID,
      'title'   => $best->post_title,
      'content' => luna_widget_prepare_canned_response_content($best->post_content),
    );
  }

  return null;
}

function luna_widget_create_conversation_post($cid) {
  // First check if a conversation with this CID already exists
  $existing = get_posts(array(
    'post_type'   => 'luna_widget_convo',
    'meta_key'    => 'luna_cid',
    'meta_value'  => $cid,
    'fields'      => 'ids',
    'numberposts' => 1,
    'post_status' => 'any',
  ));
  if ($existing && !empty($existing[0])) {
    // Conversation already exists, return it
    return (int)$existing[0];
  }
  
  // Create new conversation
  $pid = wp_insert_post(array(
    'post_type'   => 'luna_widget_convo',
    'post_title'  => 'Conversation ' . substr($cid, 0, 8),
    'post_status' => 'publish',
  ));
  if ($pid && !is_wp_error($pid)) {
    update_post_meta($pid, 'luna_cid', $cid);
    // Only initialize transcript if it doesn't exist
    $existing_transcript = get_post_meta($pid, 'transcript', true);
    if (!is_array($existing_transcript)) {
    update_post_meta($pid, 'transcript', array());
    }
    return (int)$pid;
  }
  return 0;
}

function luna_widget_current_conversation_id() {
  $cookie_key = 'luna_widget_cid';
  if (empty($_COOKIE[$cookie_key])) {
    return 0;
  }
  $cid = sanitize_text_field(wp_unslash($_COOKIE[$cookie_key]));
  if ($cid === '' || !preg_match('/^lwc_/', $cid)) {
    return 0;
  }
  $existing = get_posts(array(
    'post_type'   => 'luna_widget_convo',
    'meta_key'    => 'luna_cid',
    'meta_value'  => $cid,
    'fields'      => 'ids',
    'numberposts' => 1,
    'post_status' => 'any',
    'orderby'     => 'date',
    'order'       => 'DESC',
  ));
  if ($existing && !empty($existing[0])) {
    return (int)$existing[0];
  }
  return 0;
}

function luna_conv_id($force_new = false) {
  $cookie_key = 'luna_widget_cid';
  $cid = isset($_COOKIE[$cookie_key]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_key])) : '';

  if (!$force_new) {
    // First, try to find existing conversation by CID
    if ($cid !== '' && preg_match('/^lwc_/', $cid)) {
    $pid = luna_widget_current_conversation_id();
      if ($pid) {
        // Ensure cookie is set for future requests
        @setcookie($cookie_key, $cid, time() + (86400 * 30), COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true);
        $_COOKIE[$cookie_key] = $cid;
        return $pid;
      }
      // CID exists but no conversation found - create one with this CID
      @setcookie($cookie_key, $cid, time() + (86400 * 30), COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true);
      $_COOKIE[$cookie_key] = $cid;
      return luna_widget_create_conversation_post($cid);
    }
  }

  // Create new conversation with new CID
  $cid = 'lwc_' . uniqid('', true);
  @setcookie($cookie_key, $cid, time() + (86400 * 30), COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true);
  $_COOKIE[$cookie_key] = $cid;
  return luna_widget_create_conversation_post($cid);
}

function luna_widget_close_conversation($pid, $reason = '') {
  if (!$pid) return;
  update_post_meta($pid, 'session_closed', time());
  if ($reason !== '') {
    update_post_meta($pid, 'session_closed_reason', sanitize_text_field($reason));
  }
}
function luna_log_turn($user, $assistant, $meta = array()) {
  $pid = luna_conv_id(); 
  if (!$pid) {
    error_log('[Luna Widget] luna_log_turn: No conversation ID available');
    return;
  }
  
  $t = get_post_meta($pid, 'transcript', true);
  if (!is_array($t)) {
    $t = array();
  }
  
  // Only add turn if there's actual content (user or assistant message)
  if (trim($user) !== '' || trim($assistant) !== '') {
    $t[] = array(
      'ts' => time(),
      'user' => trim((string)$user),
      'assistant' => trim((string)$assistant),
      'meta' => $meta
    );
  update_post_meta($pid, 'transcript', $t);

  // Also log to Hub
  luna_log_conversation_to_hub($t);
  } else {
    error_log('[Luna Widget] luna_log_turn: Skipping empty turn (user and assistant both empty)');
  }
}

function luna_composer_log_entry($prompt, $answer, $meta = array(), $conversation_id = 0) {
  $prompt = trim(wp_strip_all_tags((string) $prompt));
  $answer = trim((string) $answer);
  if ($prompt === '' && $answer === '') {
    return 0;
  }

  $title = $prompt !== '' ? wp_trim_words($prompt, 12, '…') : __('Composer Entry', 'luna');
  $post_id = wp_insert_post(array(
    'post_type'   => 'luna_compose',
    'post_title'  => $title,
    'post_status' => 'publish',
  ));

  if (!$post_id || is_wp_error($post_id)) {
    return 0;
  }

  update_post_meta($post_id, 'prompt', $prompt);
  update_post_meta($post_id, 'answer', $answer);
  update_post_meta($post_id, 'meta', is_array($meta) ? $meta : array());
  if ($conversation_id) {
    update_post_meta($post_id, 'conversation_post', (int) $conversation_id);
  }
  update_post_meta($post_id, 'timestamp', time());

  return (int) $post_id;
}

function luna_composer_recent_entries($limit = 10) {
  $query = new WP_Query(array(
    'post_type'      => 'luna_compose',
    'post_status'    => 'publish',
    'posts_per_page' => max(1, (int) $limit),
    'orderby'        => 'date',
    'order'          => 'DESC',
    'no_found_rows'  => true,
  ));

  $posts = $query->posts;
  wp_reset_postdata();
  return $posts;
}

function luna_chat_recent_conversations($limit = 50) {
  $query = new WP_Query(array(
    'post_type'      => 'luna_widget_convo',
    'post_status'    => 'publish',
    'posts_per_page' => max(1, (int) $limit),
    'orderby'        => 'date',
    'order'          => 'DESC',
    'no_found_rows'  => true,
  ));

  $posts = $query->posts;
  wp_reset_postdata();
  return $posts;
}

/* Log conversation to Hub */
function luna_log_conversation_to_hub($transcript) {
  $license = luna_get_license();
  if (!$license) {
    error_log('Luna Hub Log: No license found');
    return false;
  }
  
  $hub_url = luna_widget_hub_base();
  $conversation_data = array(
    'id' => 'conv_' . uniqid('', true),
    'started_at' => !empty($transcript[0]['ts']) ? gmdate('c', (int)$transcript[0]['ts']) : gmdate('c'),
    'transcript' => $transcript
  );
  
  error_log('Luna Hub Log: Sending conversation to Hub: ' . print_r($conversation_data, true));
  
  $response = wp_remote_post($hub_url . '/wp-json/luna_widget/v1/conversations/log', array(
    'headers' => array(
      'X-Luna-License' => $license,
      'Content-Type' => 'application/json'
    ),
    'body' => wp_json_encode($conversation_data),
    'timeout' => 10
  ));
  
  if (is_wp_error($response)) {
    error_log('Luna Hub Log: Error sending to Hub: ' . $response->get_error_message());
    return false;
  }
  
  $response_code = wp_remote_retrieve_response_code($response);
  $response_body = wp_remote_retrieve_body($response);
  
  error_log('Luna Hub Log: Hub response code: ' . $response_code);
  error_log('Luna Hub Log: Hub response body: ' . $response_body);
  
  return $response_code >= 200 && $response_code < 300;
}

/* ============================================================
 * HUB PROFILE FETCH (LICENSE-GATED) + FACTS
 * ============================================================ */
function luna_get_license() { return trim((string) get_option(LUNA_WIDGET_OPT_LICENSE, '')); }

function luna_profile_cache_key() {
  $license = luna_get_license();
  $hub     = luna_widget_hub_base();
  $site    = home_url('/');
  return 'luna_profile_' . md5($license . '|' . $hub . '|' . $site);
}
function luna_profile_cache_bust($all=false){
  // Single-site cache key; $all kept for API symmetry
  delete_transient( luna_profile_cache_key() );
  if ($all) {
    delete_transient( luna_hub_collections_cache_key() );
  }
}

function luna_hub_normalize_payload($payload) {
  if (!is_array($payload)) {
    return $payload;
  }

  if (isset($payload['data']) && is_array($payload['data'])) {
    $payload = $payload['data'];
  } elseif (isset($payload['profile']) && is_array($payload['profile'])) {
    $payload = $payload['profile'];
  } elseif (isset($payload['payload']) && is_array($payload['payload'])) {
    $payload = $payload['payload'];
  }

  return $payload;
}

function luna_hub_get_json($path) {
  $license = luna_get_license();
  if ($license === '') return null;
  
  // Add license parameter to URL if not already present
  $url = luna_widget_hub_url($path);
  if (strpos($url, '?') !== false) {
    $url .= '&license=' . rawurlencode($license);
  } else {
    $url .= '?license=' . rawurlencode($license);
  }
  
  $resp = wp_remote_get($url, array(
    'timeout' => 12,
    'headers' => array(
      'X-Luna-License' => $license,
      'X-Luna-Site'    => home_url('/'),
      'Accept'         => 'application/json'
    ),
    'sslverify' => true,
  ));
  if (is_wp_error($resp)) return null;
  $code = (int) wp_remote_retrieve_response_code($resp);
  if ($code >= 400) return null;
  $body = json_decode(wp_remote_retrieve_body($resp), true);
  if (!is_array($body)) {
    return null;
  }

  return luna_hub_normalize_payload($body);
}

function luna_hub_profile() {
  if (isset($_GET['luna_profile_nocache'])) luna_profile_cache_bust();
  $key = luna_profile_cache_key();
  $cached = get_transient($key);
  if (is_array($cached)) return $cached;

  $map = isset($GLOBALS['LUNA_HUB_ENDPOINTS']) ? $GLOBALS['LUNA_HUB_ENDPOINTS'] : array();
  $profile = luna_hub_get_json(isset($map['profile']) ? $map['profile'] : '/wp-json/vl-hub/v1/profile');
  if (is_array($profile)) {
    $profile = luna_hub_normalize_payload($profile);
  }

  if (!$profile) {
    // Fallback to local data only if Hub profile is not available
    $profile = array(
      'site'      => array('url' => home_url('/')),
      'wordpress' => array('version' => get_bloginfo('version')),
      'security'  => array(),
      'content'   => array(),
      'users'     => array(),
    );
  }

  set_transient($key, $profile, LUNA_CACHE_PROFILE_TTL);
  return $profile;
}

function luna_hub_collections_cache_key() {
  $license = luna_get_license();
  $hub     = luna_widget_hub_base();
  return 'luna_hub_collections_' . md5($license . '|' . $hub);
}

function luna_hub_fetch_first_json($paths) {
  if (!is_array($paths)) {
    $paths = array($paths);
  }

  foreach ($paths as $path) {
    $payload = luna_hub_get_json($path);
    if (is_array($payload)) {
      $normalized = luna_hub_normalize_payload($payload);
      if (is_array($normalized) && !empty($normalized)) {
        return $normalized;
      }
    }
  }

  return null;
}

function luna_hub_collect_collections($force_refresh = false, $prefetched = array()) {
  $license = luna_get_license();
  if ($license === '') {
    return array();
  }

  $key = luna_hub_collections_cache_key();
  if (!$force_refresh) {
    $cached = get_transient($key);
    if (is_array($cached)) {
      if (is_array($prefetched) && !empty($prefetched)) {
        $updated = false;
        foreach ($prefetched as $pref_key => $pref_value) {
          if (is_array($pref_value) && !empty($pref_value) && (!isset($cached[$pref_key]) || $cached[$pref_key] !== $pref_value)) {
            $cached[$pref_key] = $pref_value;
            $updated = true;
          }
        }
        if ($updated) {
          if (!isset($cached['_meta']) || !is_array($cached['_meta'])) {
            $cached['_meta'] = array();
          }
          $cached['_meta']['retrieved_at'] = gmdate('c');
          $cached['_meta']['categories'] = isset($cached['_meta']['categories']) ? $cached['_meta']['categories'] : array_keys(array_diff_key($cached, array('_meta' => true)));
          set_transient($key, $cached, LUNA_CACHE_PROFILE_TTL);
        }
      }
      return $cached;
    }
  }

  $categories = array(
    'profile'      => array('/wp-json/vl-hub/v1/profile', '/wp-json/luna_widget/v1/system/comprehensive'),
    'connections'  => array('/wp-json/vl-hub/v1/connections', '/wp-json/vl-hub/v1/all-connections', '/wp-json/vl-hub/v1/data-sources'),
    'cloudops'     => array('/wp-json/vl-hub/v1/cloudops', '/wp-json/vl-hub/v1/cloud-ops'),
    'content'      => array('/wp-json/vl-hub/v1/content'),
    'search'       => array('/wp-json/vl-hub/v1/search', '/wp-json/vl-hub/v1/search-console'),
    'analytics'    => array('/wp-json/vl-hub/v1/analytics', '/wp-json/vl-hub/v1/ga4'),
    'marketing'    => array('/wp-json/vl-hub/v1/marketing'),
    'ecommerce'    => array('/wp-json/vl-hub/v1/ecommerce', '/wp-json/vl-hub/v1/e-commerce'),
    'security'     => array('/wp-json/vl-hub/v1/security'),
    'web_infra'    => array('/wp-json/vl-hub/v1/web-infra', '/wp-json/vl-hub/v1/web-infrastructure', '/wp-json/vl-hub/v1/infra'),
    'identity'     => array('/wp-json/vl-hub/v1/identity'),
    'competitive'  => array('/wp-json/vl-hub/v1/competitive', '/wp-json/vl-hub/v1/competition', '/wp-json/vl-hub/v1/competitors'),
    'users'        => array('/wp-json/vl-hub/v1/users'),
    'plugins'      => array('/wp-json/vl-hub/v1/plugins'),
    'themes'       => array('/wp-json/vl-hub/v1/themes'),
    'updates'      => array('/wp-json/vl-hub/v1/updates'),
  );

  $collections = array();

  if (is_array($prefetched) && !empty($prefetched)) {
    foreach ($prefetched as $pref_key => $pref_value) {
      if (is_array($pref_value) && !empty($pref_value)) {
        $collections[$pref_key] = $pref_value;
      }
    }
  }

  foreach ($categories as $name => $paths) {
    if (isset($collections[$name]) && is_array($collections[$name])) {
      continue;
    }
    $data = luna_hub_fetch_first_json($paths);
    if ($data !== null) {
      $collections[$name] = $data;
    }
  }

  $streams = luna_fetch_hub_data_streams($license);
  if (is_array($streams) && !empty($streams)) {
    $collections['data_streams'] = $streams;
  }

  $collections['_meta'] = array(
    'retrieved_at' => gmdate('c'),
    'license'      => $license,
    'categories'   => array_keys(array_diff_key($collections, array('_meta' => true))),
  );

  set_transient($key, $collections, LUNA_CACHE_PROFILE_TTL);

  return $collections;
}

/* Helpers to normalize Hub data and provide local fallbacks */
function luna_is_list_array($value) {
  if (!is_array($value)) return false;
  if ($value === array()) return true;
  return array_keys($value) === range(0, count($value) - 1);
}

function luna_extract_hub_items($payload, $key) {
  if (!is_array($payload)) return null;

  $sources = array();
  if (isset($payload[$key])) {
    $sources[] = $payload[$key];
  }

  $underscored = '_' . $key;
  if (isset($payload[$underscored])) {
    $sources[] = $payload[$underscored];
  }

  if (isset($payload['content']) && is_array($payload['content']) && isset($payload['content'][$key])) {
    $sources[] = $payload['content'][$key];
  }

  foreach ($sources as $source) {
    if (!is_array($source)) {
      continue;
    }

    if (isset($source['items']) && is_array($source['items'])) {
      return $source['items'];
    }

    if (luna_is_list_array($source)) {
      return $source;
    }
  }

  return null;
}

function luna_collect_local_post_type_snapshot($post_type, $limit = 25) {
  $post_type = sanitize_key($post_type);
  if (!$post_type) return array();

  $ids = get_posts(array(
    'post_type'       => $post_type,
    'post_status'     => array('publish','draft','pending','private'),
    'numberposts'     => $limit,
    'orderby'         => 'date',
    'order'           => 'DESC',
    'fields'          => 'ids',
    'suppress_filters'=> true,
  ));

  if (!is_array($ids)) return array();

  $items = array();
  foreach ($ids as $pid) {
    $items[] = array(
      'id'        => (int) $pid,
      'title'     => get_the_title($pid),
      'slug'      => get_post_field('post_name', $pid),
      'status'    => get_post_status($pid),
      'date'      => get_post_time('c', true, $pid),
      'permalink' => get_permalink($pid),
    );
  }

  return $items;
}

/* Build compact facts, prioritizing Hub over local snapshot; no network/probe overrides */
function luna_profile_facts() {
  $hub     = luna_hub_profile();
  $local   = luna_snapshot_system(); // fallback only
  $license = luna_get_license();

  $site_url = isset($hub['site']['url']) ? (string)$hub['site']['url'] : home_url('/');

  // TLS from Hub (authoritative)
  $tls        = isset($hub['security']['tls']) ? $hub['security']['tls'] : array();
  $tls_valid  = (bool) ( isset($tls['valid']) ? $tls['valid'] : ( isset($hub['security']['tls_valid']) ? $hub['security']['tls_valid'] : false ) );
  $tls_issuer = isset($tls['issuer']) ? (string)$tls['issuer'] : '';
  $tls_expires= isset($tls['expires_at']) ? (string)$tls['expires_at'] : ( isset($tls['not_after']) ? (string)$tls['not_after'] : '' );
  $tls_checked= isset($tls['checked_at']) ? (string)$tls['checked_at'] : '';

  // Host/Infra from Hub
  $host  = '';
  if (isset($hub['infra']['host'])) $host = (string)$hub['infra']['host'];
  elseif (isset($hub['hosting']['provider'])) $host = (string)$hub['hosting']['provider'];

  // WordPress version from Hub then local
  $wpv   = isset($hub['wordpress']['version']) ? (string)$hub['wordpress']['version'] : ( isset($local['wordpress']['version']) ? (string)$local['wordpress']['version'] : '' );
  // Theme: prefer Hub if provided as object with name; else local
  $theme = (isset($hub['wordpress']['theme']) && is_array($hub['wordpress']['theme']) && isset($hub['wordpress']['theme']['name']))
    ? (string)$hub['wordpress']['theme']['name']
    : ( isset($local['wordpress']['theme']['name']) ? (string)$local['wordpress']['theme']['name'] : '' );

  // Content counts (Hub first) + fallback to local snapshots
  $pages = 0; $posts = 0;
  if (isset($hub['content']['pages_total'])) $pages = (int)$hub['content']['pages_total'];
  elseif (isset($hub['content']['pages']))   $pages = (int)$hub['content']['pages'];
  if (isset($hub['content']['posts_total'])) $posts = (int)$hub['content']['posts_total'];
  elseif (isset($hub['content']['posts']))   $posts = (int)$hub['content']['posts'];

  $pages_items = luna_extract_hub_items($hub, 'pages');
  if (!is_array($pages_items)) {
    $pages_items = luna_collect_local_post_type_snapshot('page');
  }
  if ($pages === 0 && is_array($pages_items)) {
    $pages = count($pages_items);
  }

  $posts_items = luna_extract_hub_items($hub, 'posts');
  if (!is_array($posts_items)) {
    $posts_items = luna_collect_local_post_type_snapshot('post');
  }
  if ($posts === 0 && is_array($posts_items)) {
    $posts = count($posts_items);
  }

  // Users
  $users_total = isset($hub['users']['total']) ? (int)$hub['users']['total'] : 0;
  if ($users_total === 0 && isset($hub['users']) && is_array($hub['users'])) {
    $users_total = count($hub['users']);
  }
  if ($users_total === 0) {
    $user_counts = count_users();
    if (isset($user_counts['total_users'])) {
      $users_total = (int) $user_counts['total_users'];
    }
  }

  $users_items = luna_extract_hub_items($hub, 'users');
  if (!is_array($users_items)) {
    $users_items = array();
  }

  $plugins_items = array();
  if (isset($hub['plugins']) && is_array($hub['plugins'])) {
    $plugins_items = $hub['plugins'];
  } elseif (isset($local['plugins']) && is_array($local['plugins'])) {
    $plugins_items = $local['plugins'];
  }

  $themes_items = array();
  if (isset($hub['themes']) && is_array($hub['themes'])) {
    $themes_items = $hub['themes'];
  } elseif (isset($local['themes']) && is_array($local['themes'])) {
    $themes_items = $local['themes'];
  }

  // Updates (Hub first; fallback to derived counts)
  $plugin_updates = isset($hub['updates']['plugins_pending']) ? (int)$hub['updates']['plugins_pending'] : 0;
  $theme_updates  = isset($hub['updates']['themes_pending'])  ? (int)$hub['updates']['themes_pending']  : 0;
  if ($plugin_updates === 0 && !empty($plugins_items)) {
    $c = 0; foreach ($plugins_items as $p) { if (!empty($p['update_available'])) $c++; } $plugin_updates = $c;
  }
  if ($theme_updates === 0 && !empty($themes_items)) {
    $c = 0; foreach ($themes_items as $t) { if (!empty($t['update_available'])) $c++; } $theme_updates = $c;
  }
  $core_updates = 0;
  if (isset($hub['updates']['core_pending'])) {
    $core_updates = (int) $hub['updates']['core_pending'];
  } elseif (!empty($local['wordpress']['core_update_available'])) {
    $core_updates = $local['wordpress']['core_update_available'] ? 1 : 0;
  }

  $facts = array(
    'site_url'   => $site_url,
    'tls'        => array(
      'valid'    => (bool)$tls_valid,
      'issuer'   => $tls_issuer,
      'expires'  => $tls_expires,
      'checked'  => $tls_checked,
    ),
    'host'       => $host,
    'wp_version' => $wpv,
    'theme'      => $theme,
    'counts'     => array(
      'pages'   => $pages,
      'posts'   => $posts,
      'users'   => $users_total,
      'plugins' => is_array($plugins_items) ? count($plugins_items) : 0,
    ),
    'updates'    => array(
      'plugins' => $plugin_updates,
      'themes'  => $theme_updates,
      'core'    => $core_updates,
    ),
    'generated'  => gmdate('c'),
    'comprehensive' => false,
  );

  if ($license) {
    $ga4_info = luna_fetch_ga4_metrics_from_hub($license);
    if ($ga4_info && isset($ga4_info['metrics'])) {
      $facts['ga4_metrics'] = $ga4_info['metrics'];
      if (!empty($ga4_info['last_synced'])) {
        $facts['ga4_last_synced'] = $ga4_info['last_synced'];
      }
      if (!empty($ga4_info['date_range'])) {
        $facts['ga4_date_range'] = $ga4_info['date_range'];
      }
      if (!empty($ga4_info['source_url'])) {
        $facts['ga4_source_url'] = $ga4_info['source_url'];
      }
      if (!empty($ga4_info['property_id'])) {
        $facts['ga4_property_id'] = $ga4_info['property_id'];
      }
      if (!empty($ga4_info['measurement_id'])) {
        $facts['ga4_measurement_id'] = $ga4_info['measurement_id'];
      }
    }
  }

  $facts['__source'] = 'basic';

  return $facts;
}

/* Enhanced facts with comprehensive Hub data */
function luna_get_active_theme_status($comprehensive) {
  // First try to get from themes array (more accurate)
  if (isset($comprehensive['themes']) && is_array($comprehensive['themes'])) {
    foreach ($comprehensive['themes'] as $theme) {
      if (isset($theme['is_active']) && $theme['is_active']) {
        return true;
      }
    }
  }
  
  // Fallback to basic theme info
  return isset($comprehensive['wordpress']['theme']['is_active']) ? (bool)$comprehensive['wordpress']['theme']['is_active'] : true;
}

/**
 * Comprehensive facts function that pulls ALL VL Hub data for Luna Chat Widget.
 * 
 * This function fetches the complete client profile from VL Hub which includes:
 * - WordPress Core Data (version, PHP, MySQL, memory, multisite status)
 * - Content Data (pages, posts with full details)
 * - Users Data (all users with details)
 * - Plugins & Themes (full lists with status and versions)
 * - Security Data (TLS, WAF, IDS, authentication, domain info)
 * - AWS S3 Cloud Storage (buckets, objects, settings, storage usage)
 * - Liquid Web Hosting (assets, account info, connection status)
 * - GA4 Analytics (all metrics, property ID, measurement ID, date ranges)
 * - All Data Streams (complete data stream details including categories, health scores, status)
 * - Competitor Analysis (full reports with Lighthouse scores, keywords, meta descriptions, timestamps)
 * - VLDR Metrics (domain ranking scores for all tracked domains - client and competitors)
 * - Performance Metrics (Lighthouse scores from PageSpeed Insights)
 * - SEO Data (Search Console data - clicks, impressions, CTR, top queries)
 * - Data Streams Summary (counts, categories, recent streams)
 * 
 * All this data is made available to Luna AI for intelligent, context-aware responses.
 */
function luna_profile_facts_comprehensive() {
  try {
  $license = luna_get_license();
  if (!$license) {
    error_log('[Luna] No license key found, falling back to basic facts');
    $fallback = luna_profile_facts(); // fallback to basic facts
    $fallback['__source'] = 'fallback-basic';
    return $fallback;
  }
  
    $hub_collections = luna_hub_collect_collections(false);
    if (empty($hub_collections) || !is_array($hub_collections)) {
      error_log('[Luna] Hub collections were empty or invalid, falling back to basic facts');
    $fallback = luna_profile_facts();
    $fallback['__source'] = 'fallback-basic';
      return $fallback;
    }

  $comprehensive = array();
  if (isset($hub_collections['profile']) && is_array($hub_collections['profile'])) {
    $comprehensive = luna_hub_normalize_payload($hub_collections['profile']);
  }
  
  // Normalize the payload if it's still not an array
  if (!is_array($comprehensive) && isset($hub_collections['profile'])) {
    $comprehensive = luna_hub_normalize_payload($hub_collections['profile']);
  }

  if (!is_array($comprehensive)) {
    error_log('[Luna] Could not locate a normalized Hub profile payload, falling back to basic facts');
    $fallback = luna_profile_facts();
    $fallback['__source'] = 'fallback-basic';
    return $fallback;
  }

  // Log available data keys in comprehensive profile for debugging
  error_log('[Luna Widget] Comprehensive profile keys: ' . implode(', ', array_keys($comprehensive)));
  error_log('[Luna Widget] Comprehensive profile has aws_s3: ' . (isset($comprehensive['aws_s3']) ? 'YES' : 'NO'));
  error_log('[Luna Widget] Comprehensive profile has liquidweb: ' . (isset($comprehensive['liquidweb']) ? 'YES' : 'NO'));
  error_log('[Luna Widget] Comprehensive profile has ga4_metrics: ' . (isset($comprehensive['ga4_metrics']) ? 'YES' : 'NO'));
  error_log('[Luna Widget] Comprehensive profile has data_streams: ' . (isset($comprehensive['data_streams']) ? 'YES (' . count($comprehensive['data_streams']) . ')' : 'NO'));
  error_log('[Luna Widget] Comprehensive profile has competitor_reports_full: ' . (isset($comprehensive['competitor_reports_full']) ? 'YES (' . count($comprehensive['competitor_reports_full']) . ')' : 'NO'));
  error_log('[Luna Widget] Comprehensive profile has vldr_metrics: ' . (isset($comprehensive['vldr_metrics']) ? 'YES (' . count($comprehensive['vldr_metrics']) . ')' : 'NO'));

  // Keep the original hub_collections for reference
  // Don't call luna_hub_collect_collections again - we already have the data we need
  
  // Build enhanced facts from comprehensive data with local fallbacks
  $local_snapshot = luna_snapshot_system();

  // Support multiple possible keys for site URL (from profile endpoint structure)
  $site_url = '';
  if (isset($comprehensive['home_url'])) {
    $site_url = (string) $comprehensive['home_url'];
  } elseif (isset($comprehensive['site_info']['site']) && !empty($comprehensive['site_info']['site'])) {
    $site_url = (string) $comprehensive['site_info']['site'];
  } elseif (isset($comprehensive['site']['url'])) {
    $site_url = (string) $comprehensive['site']['url'];
  } elseif (isset($local_snapshot['site']['home_url'])) {
    $site_url = (string) $local_snapshot['site']['home_url'];
  } else {
    $site_url = home_url('/');
  }
  $https    = isset($comprehensive['https']) ? (bool) $comprehensive['https'] : (isset($local_snapshot['site']['https']) ? (bool) $local_snapshot['site']['https'] : is_ssl());
  $wp_version = isset($comprehensive['wordpress']['version']) ? (string) $comprehensive['wordpress']['version'] : (isset($local_snapshot['wordpress']['version']) ? (string) $local_snapshot['wordpress']['version'] : '');

  $theme_data = array();
  if (isset($comprehensive['wordpress']['theme']) && is_array($comprehensive['wordpress']['theme'])) {
    $theme_data = $comprehensive['wordpress']['theme'];
  } elseif (isset($local_snapshot['wordpress']['theme']) && is_array($local_snapshot['wordpress']['theme'])) {
    $theme_data = $local_snapshot['wordpress']['theme'];
  }
  $theme_name    = isset($theme_data['name']) ? (string) $theme_data['name'] : '';
  $theme_version = isset($theme_data['version']) ? (string) $theme_data['version'] : '';
  $theme_active  = isset($theme_data['is_active']) ? (bool) $theme_data['is_active'] : luna_get_active_theme_status($comprehensive);

  // SSL/TLS data from VL Hub - check multiple possible locations
  // First check category-specific endpoints (security endpoint)
  $tls_data = array();
  if (!empty($hub_collections['security']) && is_array($hub_collections['security'])) {
    $security_data = luna_hub_normalize_payload($hub_collections['security']);
    if (isset($security_data['data']['ssl_tls']) && is_array($security_data['data']['ssl_tls'])) {
      $tls_data = $security_data['data']['ssl_tls'];
    } elseif (isset($security_data['ssl_tls']) && is_array($security_data['ssl_tls'])) {
      $tls_data = $security_data['ssl_tls'];
    }
  }
  
  // Fallback to comprehensive profile data
  if (empty($tls_data)) {
    if (isset($comprehensive['ssl_tls']) && is_array($comprehensive['ssl_tls'])) {
      $tls_data = $comprehensive['ssl_tls'];
    } elseif (isset($comprehensive['security']['tls']) && is_array($comprehensive['security']['tls'])) {
      $tls_data = $comprehensive['security']['tls'];
    } elseif (isset($comprehensive['tls']) && is_array($comprehensive['tls'])) {
      $tls_data = $comprehensive['tls'];
    }
  }
  $tls_valid   = isset($tls_data['connected']) ? (bool) $tls_data['connected'] : (isset($tls_data['valid']) ? (bool) $tls_data['valid'] : false);
  $tls_certificate = isset($tls_data['certificate']) ? (string) $tls_data['certificate'] : '';
  $tls_issuer  = isset($tls_data['issuer']) ? (string) $tls_data['issuer'] : '';
  $tls_expires = '';
  if (isset($tls_data['expires'])) {
    $tls_expires = (string) $tls_data['expires'];
  } elseif (isset($tls_data['expires_at'])) {
    $tls_expires = (string) $tls_data['expires_at'];
  } elseif (isset($tls_data['not_after'])) {
    $tls_expires = (string) $tls_data['not_after'];
  }
  $tls_days_until_expiry = isset($tls_data['days_until_expiry']) ? (int) $tls_data['days_until_expiry'] : null;
  $tls_status = isset($tls_data['status']) ? (string) $tls_data['status'] : '';
  $tls_checked = isset($tls_data['last_checked']) ? (string) $tls_data['last_checked'] : (isset($tls_data['checked_at']) ? (string) $tls_data['checked_at'] : '');

  $host = isset($comprehensive['host']) ? (string) $comprehensive['host'] : '';
  if ($host === '' && isset($comprehensive['hosting']['provider'])) {
    $host = (string) $comprehensive['hosting']['provider'];
  }

  $plugins_items = luna_extract_hub_items($comprehensive, 'plugins');
  if (!is_array($plugins_items)) {
    $plugins_items = isset($local_snapshot['plugins']) ? $local_snapshot['plugins'] : array();
  }

  $themes_items = luna_extract_hub_items($comprehensive, 'themes');
  if (!is_array($themes_items)) {
    $themes_items = isset($local_snapshot['themes']) ? $local_snapshot['themes'] : array();
  }

  $pages_items = luna_extract_hub_items($comprehensive, 'pages');
  if (!is_array($pages_items) && isset($comprehensive['content']['pages']) && is_array($comprehensive['content']['pages'])) {
    $pages_items = $comprehensive['content']['pages'];
  }
  if (!is_array($pages_items)) {
    $pages_items = luna_collect_local_post_type_snapshot('page');
  }

  $posts_items = luna_extract_hub_items($comprehensive, 'posts');
  if (!is_array($posts_items) && isset($comprehensive['content']['posts']) && is_array($comprehensive['content']['posts'])) {
    $posts_items = $comprehensive['content']['posts'];
  }
  if (!is_array($posts_items)) {
    $posts_items = luna_collect_local_post_type_snapshot('post');
  }

  $users_items = luna_extract_hub_items($comprehensive, 'users');
  if (!is_array($users_items) && isset($comprehensive['users']) && is_array($comprehensive['users'])) {
    $users_items = $comprehensive['users'];
  }
  if (!is_array($users_items)) {
    $users_items = array();
  }

  $pages_count = is_array($pages_items) ? count($pages_items) : 0;
  if ($pages_count === 0 && isset($comprehensive['counts']['pages'])) {
    $pages_count = (int) $comprehensive['counts']['pages'];
  } elseif ($pages_count === 0 && isset($comprehensive['content']['pages_total'])) {
    $pages_count = (int) $comprehensive['content']['pages_total'];
  }

  $posts_count = is_array($posts_items) ? count($posts_items) : 0;
  if ($posts_count === 0 && isset($comprehensive['counts']['posts'])) {
    $posts_count = (int) $comprehensive['counts']['posts'];
  } elseif ($posts_count === 0 && isset($comprehensive['content']['posts_total'])) {
    $posts_count = (int) $comprehensive['content']['posts_total'];
  }

  $users_count = is_array($users_items) ? count($users_items) : 0;
  if ($users_count === 0 && isset($comprehensive['users_total'])) {
    $users_count = (int) $comprehensive['users_total'];
  } elseif ($users_count === 0 && isset($comprehensive['users']) && is_array($comprehensive['users'])) {
    $users_count = count($comprehensive['users']);
  }

  $plugins_count = is_array($plugins_items) ? count($plugins_items) : 0;

  $plugin_updates = 0;
  if (is_array($plugins_items)) {
    foreach ($plugins_items as $plugin) {
      if (!empty($plugin['update_available'])) {
        $plugin_updates++;
      }
    }
  }

  $theme_updates = 0;
  if (is_array($themes_items)) {
    foreach ($themes_items as $theme_row) {
      if (!empty($theme_row['update_available'])) {
        $theme_updates++;
      }
    }
  }

  $core_updates = 0;
  if (isset($comprehensive['wordpress']['core_update_available'])) {
    $core_updates = $comprehensive['wordpress']['core_update_available'] ? 1 : 0;
  } elseif (!empty($local_snapshot['wordpress']['core_update_available'])) {
    $core_updates = $local_snapshot['wordpress']['core_update_available'] ? 1 : 0;
  }

  // Extract and merge category-specific data from hub_collections BEFORE building facts array
  // This ensures all VL Hub data is available in the facts array
  if (!empty($hub_collections)) {
    // Security endpoint data (SSL/TLS, Cloudflare)
    if (isset($hub_collections['security']) && is_array($hub_collections['security'])) {
      $security_data = luna_hub_normalize_payload($hub_collections['security']);
      if (isset($security_data['data']) && is_array($security_data['data'])) {
        $security_category_data = $security_data['data'];
        
        // Merge SSL/TLS data - update tls_data if it's empty or if security endpoint has more complete data
        if (isset($security_category_data['ssl_tls']) && is_array($security_category_data['ssl_tls'])) {
          if (empty($tls_data) || (isset($security_category_data['ssl_tls']['connected']) && $security_category_data['ssl_tls']['connected'])) {
            $tls_data = $security_category_data['ssl_tls'];
            // Update tls_* variables from merged data
            $tls_valid = isset($tls_data['connected']) ? (bool)$tls_data['connected'] : false;
            $tls_certificate = isset($tls_data['certificate']) ? (string)$tls_data['certificate'] : '';
            $tls_issuer = isset($tls_data['issuer']) ? (string)$tls_data['issuer'] : '';
            $tls_expires = isset($tls_data['expires']) ? (string)$tls_data['expires'] : '';
            $tls_days_until_expiry = isset($tls_data['days_until_expiry']) ? (int)$tls_data['days_until_expiry'] : null;
            $tls_status = isset($tls_data['status']) ? (string)$tls_data['status'] : '';
            $tls_checked = isset($tls_data['last_checked']) ? (string)$tls_data['last_checked'] : '';
          }
        }
      } elseif (isset($security_data['ssl_tls']) && is_array($security_data['ssl_tls'])) {
        if (empty($tls_data) || (isset($security_data['ssl_tls']['connected']) && $security_data['ssl_tls']['connected'])) {
          $tls_data = $security_data['ssl_tls'];
          $tls_valid = isset($tls_data['connected']) ? (bool)$tls_data['connected'] : false;
          $tls_certificate = isset($tls_data['certificate']) ? (string)$tls_data['certificate'] : '';
          $tls_issuer = isset($tls_data['issuer']) ? (string)$tls_data['issuer'] : '';
          $tls_expires = isset($tls_data['expires']) ? (string)$tls_data['expires'] : '';
          $tls_days_until_expiry = isset($tls_data['days_until_expiry']) ? (int)$tls_data['days_until_expiry'] : null;
          $tls_status = isset($security_data['ssl_tls']['status']) ? (string)$security_data['ssl_tls']['status'] : '';
          $tls_checked = isset($security_data['ssl_tls']['last_checked']) ? (string)$security_data['ssl_tls']['last_checked'] : '';
        }
      }
    }
  }

  $facts = array(
    'site_url'   => $site_url,
    'https'      => $https,
    'wp_version' => $wp_version,
    'theme'      => $theme_name,
    'theme_version' => $theme_version,
    'theme_active'  => $theme_active,
    'tls'        => array(
      'valid'   => $tls_valid,
      'connected' => $tls_valid,
      'certificate' => $tls_certificate,
      'issuer'  => $tls_issuer,
      'expires' => $tls_expires,
      'days_until_expiry' => $tls_days_until_expiry,
      'status' => $tls_status,
      'checked' => $tls_checked,
    ),
    'ssl_tls' => $tls_data, // Include full SSL/TLS data for comprehensive access
    'host'       => $host,
    'counts'     => array(
      'pages'   => $pages_count,
      'posts'   => $posts_count,
      'users'   => $users_count,
      'plugins' => $plugins_count,
    ),
    'updates'    => array(
      'plugins' => $plugin_updates,
      'themes'  => $theme_updates,
      'core'    => $core_updates,
    ),
    'generated'  => gmdate('c'),
    'comprehensive' => true, // Flag to indicate this is comprehensive data
    'plugins' => isset($comprehensive['plugins']) ? $comprehensive['plugins'] : array(),
    'users' => isset($comprehensive['users']) ? $comprehensive['users'] : array(),
    'themes' => isset($comprehensive['themes']) ? $comprehensive['themes'] : array(),
    'posts' => is_array($posts_items) ? $posts_items : (isset($comprehensive['_posts']['items']) ? $comprehensive['_posts']['items'] : (isset($comprehensive['content']['posts']) ? $comprehensive['content']['posts'] : array())),
    'pages' => is_array($pages_items) ? $pages_items : (isset($comprehensive['_pages']['items']) ? $comprehensive['_pages']['items'] : (isset($comprehensive['content']['pages']) ? $comprehensive['content']['pages'] : array())),
    'security' => isset($comprehensive['security']) ? $comprehensive['security'] : array(), // Add security data
  );
  
  $ga4_info = null;
  if (isset($comprehensive['ga4_metrics']) && is_array($comprehensive['ga4_metrics'])) {
    $ga4_info = array(
      'metrics'        => $comprehensive['ga4_metrics'],
      'last_synced'    => isset($comprehensive['ga4_last_synced']) ? $comprehensive['ga4_last_synced'] : (isset($comprehensive['last_synced']) ? $comprehensive['last_synced'] : null),
      'date_range'     => isset($comprehensive['ga4_date_range']) ? $comprehensive['ga4_date_range'] : null,
      'source_url'     => isset($comprehensive['ga4_source_url']) ? $comprehensive['ga4_source_url'] : (isset($comprehensive['source_url']) ? $comprehensive['source_url'] : null),
      'property_id'    => isset($comprehensive['ga4_property_id']) ? $comprehensive['ga4_property_id'] : null,
      'measurement_id' => isset($comprehensive['ga4_measurement_id']) ? $comprehensive['ga4_measurement_id'] : null,
    );
    error_log('[Luna] GA4 metrics present in comprehensive payload.');
  } else {
    error_log('[Luna] No GA4 metrics in comprehensive payload, attempting data streams fetch.');
    $ga4_info = luna_fetch_ga4_metrics_from_hub($license);
  }

  if ($ga4_info && isset($ga4_info['metrics'])) {
    $facts['ga4_metrics'] = $ga4_info['metrics'];
    if (!empty($ga4_info['last_synced'])) {
      $facts['ga4_last_synced'] = $ga4_info['last_synced'];
    }
    if (!empty($ga4_info['date_range'])) {
      $facts['ga4_date_range'] = $ga4_info['date_range'];
    }
    if (!empty($ga4_info['source_url'])) {
      $facts['ga4_source_url'] = $ga4_info['source_url'];
    }
    if (!empty($ga4_info['property_id'])) {
      $facts['ga4_property_id'] = $ga4_info['property_id'];
    }
    if (!empty($ga4_info['measurement_id'])) {
      $facts['ga4_measurement_id'] = $ga4_info['measurement_id'];
    }
    error_log('[Luna] GA4 metrics hydrated: ' . print_r($facts['ga4_metrics'], true));
  } else {
    error_log('[Luna] Unable to hydrate GA4 metrics from Hub.');
  }

  if (!empty($hub_collections)) {
    $facts['hub_collections'] = $hub_collections;

    $collection_map = array(
      'profile'      => 'hub_profile',
      'connections'  => 'hub_connections',
      'cloudops'     => 'hub_cloudops',
      'content'      => 'hub_content',
      'search'       => 'hub_search',
      'analytics'    => 'hub_analytics',
      'marketing'    => 'hub_marketing',
      'ecommerce'    => 'hub_ecommerce',
      'security'     => 'hub_security',
      'web_infra'    => 'hub_web_infra',
      'identity'     => 'hub_identity',
      'competitive'  => 'hub_competitive',
      'data_streams' => 'hub_data_streams',
      'users'        => 'hub_users',
      'plugins'      => 'hub_plugins',
      'themes'       => 'hub_themes',
      'updates'      => 'hub_updates',
    );

    foreach ($collection_map as $source_key => $dest_key) {
      if (isset($hub_collections[$source_key])) {
        $facts[$dest_key] = $hub_collections[$source_key];
      }
    }

    $facts['hub_sources_loaded'] = isset($hub_collections['_meta']['categories'])
      ? $hub_collections['_meta']['categories']
      : array_keys(array_diff_key($hub_collections, array('_meta' => true)));
    
    // Extract and merge category-specific data into main facts array
    // Security endpoint data (SSL/TLS, Cloudflare)
    if (isset($hub_collections['security']) && is_array($hub_collections['security'])) {
      $security_data = luna_hub_normalize_payload($hub_collections['security']);
      if (isset($security_data['data']) && is_array($security_data['data'])) {
        $security_category_data = $security_data['data'];
        
        // Merge SSL/TLS data
        if (isset($security_category_data['ssl_tls']) && is_array($security_category_data['ssl_tls'])) {
          $facts['ssl_tls'] = $security_category_data['ssl_tls'];
          // Also update tls_data if it's empty
          if (empty($tls_data)) {
            $tls_data = $security_category_data['ssl_tls'];
          }
        }
        
        // Merge Cloudflare data
        if (isset($security_category_data['cloudflare']) && is_array($security_category_data['cloudflare'])) {
          $facts['cloudflare'] = $security_category_data['cloudflare'];
        }
      } elseif (isset($security_data['ssl_tls']) && is_array($security_data['ssl_tls'])) {
        $facts['ssl_tls'] = $security_data['ssl_tls'];
        if (empty($tls_data)) {
          $tls_data = $security_data['ssl_tls'];
        }
      } elseif (isset($security_data['cloudflare']) && is_array($security_data['cloudflare'])) {
        $facts['cloudflare'] = $security_data['cloudflare'];
      }
    }
    
    // CloudOps endpoint data (AWS S3, Liquid Web)
    if (isset($hub_collections['cloudops']) && is_array($hub_collections['cloudops'])) {
      $cloudops_data = luna_hub_normalize_payload($hub_collections['cloudops']);
      if (isset($cloudops_data['data']) && is_array($cloudops_data['data'])) {
        $cloudops_category_data = $cloudops_data['data'];
        
        // Merge AWS S3 data
        if (isset($cloudops_category_data['aws_s3']) && is_array($cloudops_category_data['aws_s3'])) {
          $facts['aws_s3'] = $cloudops_category_data['aws_s3'];
        }
        
        // Merge Liquid Web data
        if (isset($cloudops_category_data['liquidweb']) && is_array($cloudops_category_data['liquidweb'])) {
          $facts['liquidweb'] = $cloudops_category_data['liquidweb'];
        }
      } elseif (isset($cloudops_data['aws_s3']) && is_array($cloudops_data['aws_s3'])) {
        $facts['aws_s3'] = $cloudops_data['aws_s3'];
      } elseif (isset($cloudops_data['liquidweb']) && is_array($cloudops_data['liquidweb'])) {
        $facts['liquidweb'] = $cloudops_data['liquidweb'];
      }
    }
    
    // Analytics endpoint data (GA4)
    if (isset($hub_collections['analytics']) && is_array($hub_collections['analytics'])) {
      $analytics_data = luna_hub_normalize_payload($hub_collections['analytics']);
      if (isset($analytics_data['data']) && is_array($analytics_data['data'])) {
        $analytics_category_data = $analytics_data['data'];
        if (isset($analytics_category_data['ga4']) && is_array($analytics_category_data['ga4'])) {
          $facts['ga4'] = $analytics_category_data['ga4'];
        }
      } elseif (isset($analytics_data['ga4']) && is_array($analytics_data['ga4'])) {
        $facts['ga4'] = $analytics_data['ga4'];
      }
    }
    
    // Search endpoint data (GSC)
    if (isset($hub_collections['search']) && is_array($hub_collections['search'])) {
      $search_data = luna_hub_normalize_payload($hub_collections['search']);
      if (isset($search_data['data']) && is_array($search_data['data'])) {
        $search_category_data = $search_data['data'];
        if (isset($search_category_data['gsc']) && is_array($search_category_data['gsc'])) {
          $facts['gsc'] = $search_category_data['gsc'];
        }
      } elseif (isset($search_data['gsc']) && is_array($search_data['gsc'])) {
        $facts['gsc'] = $search_data['gsc'];
      }
    }
    
    // Competitive endpoint data (Competitor Reports, VLDR)
    if (isset($hub_collections['competitive']) && is_array($hub_collections['competitive'])) {
      $competitive_data = luna_hub_normalize_payload($hub_collections['competitive']);
      if (isset($competitive_data['data']) && is_array($competitive_data['data'])) {
        $competitive_category_data = $competitive_data['data'];
        
        // Merge competitor reports
        if (isset($competitive_category_data['competitor_reports']) && is_array($competitive_category_data['competitor_reports'])) {
          $facts['competitor_reports'] = $competitive_category_data['competitor_reports'];
        }
        
        // Merge VLDR metrics
        if (isset($competitive_category_data['vldr_metrics']) && is_array($competitive_category_data['vldr_metrics'])) {
          $facts['vldr'] = $competitive_category_data['vldr_metrics'];
        }
      } elseif (isset($competitive_data['competitor_reports']) && is_array($competitive_data['competitor_reports'])) {
        $facts['competitor_reports'] = $competitive_data['competitor_reports'];
      } elseif (isset($competitive_data['vldr_metrics']) && is_array($competitive_data['vldr_metrics'])) {
        $facts['vldr'] = $competitive_data['vldr_metrics'];
      }
    }
  }

  // Fetch competitor analysis data - first try from comprehensive profile
  $competitor_urls = array();
  error_log('[Luna Widget] Checking comprehensive profile for competitors: ' . print_r(isset($comprehensive['competitors']) ? $comprehensive['competitors'] : 'NOT SET', true));
  
  if (isset($comprehensive['competitors']) && is_array($comprehensive['competitors']) && !empty($comprehensive['competitors'])) {
    // Extract competitor URLs from enriched profile
    foreach ($comprehensive['competitors'] as $competitor) {
      if (!empty($competitor['url'])) {
        $competitor_urls[] = $competitor['url'];
      } elseif (!empty($competitor['domain'])) {
        $competitor_urls[] = 'https://' . $competitor['domain'];
      }
    }
    $facts['competitors'] = $competitor_urls;
    error_log('[Luna Widget] Found competitors in comprehensive profile: ' . print_r($competitor_urls, true));
  } else {
    error_log('[Luna Widget] No competitors in comprehensive profile, falling back to direct fetch');
    // Fallback: fetch competitor data directly
    $competitor_data = luna_fetch_competitor_data($license);
    if ($competitor_data) {
      $facts['competitors'] = $competitor_data['competitors'] ?? array();
      $facts['competitor_reports'] = $competitor_data['reports'] ?? array();
      $competitor_urls = $facts['competitors'];
      error_log('[Luna Widget] Fetched competitors via direct call: ' . print_r($competitor_urls, true));
    } else {
      error_log('[Luna Widget] No competitor data found via direct fetch');
    }
  }
  
  // Fetch competitor reports if not already in comprehensive data
  // Always fetch reports when we have competitor URLs to ensure we have the latest data
  // Also check if comprehensive profile already has competitor_reports_full
  if (!empty($competitor_urls)) {
    if (isset($comprehensive['competitor_reports_full']) && is_array($comprehensive['competitor_reports_full']) && !empty($comprehensive['competitor_reports_full'])) {
      // Use reports from comprehensive profile
      $facts['competitor_reports'] = $comprehensive['competitor_reports_full'];
      error_log('[Luna Widget] Using competitor reports from comprehensive profile: ' . count($comprehensive['competitor_reports_full']) . ' reports');
    } else {
      // Fallback: fetch directly
      $competitor_data = luna_fetch_competitor_data($license);
      if ($competitor_data && !empty($competitor_data['reports'])) {
        $facts['competitor_reports'] = $competitor_data['reports'];
        error_log('[Luna Widget] Fetched competitor reports via direct call: ' . count($competitor_data['reports']) . ' reports');
      } elseif (!isset($facts['competitor_reports'])) {
        // Initialize empty array if no reports found
        $facts['competitor_reports'] = array();
        error_log('[Luna Widget] No competitor reports found for ' . count($competitor_urls) . ' competitors');
      }
    }
  }
  
  // Fetch VLDR data for each competitor and client domain
  // First check if comprehensive profile already has vldr_metrics
  if (isset($comprehensive['vldr_metrics']) && is_array($comprehensive['vldr_metrics']) && !empty($comprehensive['vldr_metrics'])) {
    // Use VLDR metrics from comprehensive profile
    $facts['vldr'] = $comprehensive['vldr_metrics'];
    error_log('[Luna Widget] Using VLDR metrics from comprehensive profile: ' . count($comprehensive['vldr_metrics']) . ' domains');
    
    // Mark client domain if available
    $client_domain = parse_url($site_url, PHP_URL_HOST);
    if ($client_domain && isset($facts['vldr'][$client_domain])) {
      $facts['vldr'][$client_domain]['is_client'] = true;
    }
  } elseif (!empty($competitor_urls) || !empty($site_url)) {
    // Fallback: fetch VLDR data directly
    $vldr_data = array();
    
    // Fetch VLDR for all competitors
    foreach ($competitor_urls as $competitor_url) {
      $domain = parse_url($competitor_url, PHP_URL_HOST);
      if ($domain) {
        $vldr = luna_fetch_vldr_data($domain, $license);
        if ($vldr) {
          $vldr_data[$domain] = $vldr;
        }
      }
    }
    
    // Also fetch VLDR for client's own domain
    $client_domain = parse_url($site_url, PHP_URL_HOST);
    if ($client_domain) {
      $client_vldr = luna_fetch_vldr_data($client_domain, $license);
      if ($client_vldr) {
        $vldr_data[$client_domain] = $client_vldr;
        $vldr_data[$client_domain]['is_client'] = true;
      }
    }
    
    if (!empty($vldr_data)) {
      $facts['vldr'] = $vldr_data;
      error_log('[Luna Widget] Fetched VLDR data via direct call: ' . count($vldr_data) . ' domains');
    }
  }
  
  // Add performance metrics if available
  if (isset($comprehensive['performance']) && is_array($comprehensive['performance'])) {
    $facts['performance'] = $comprehensive['performance'];
  }
  
  // Add Lighthouse Insights data (from VL Hub)
  if (isset($comprehensive['lighthouse_insights']) && is_array($comprehensive['lighthouse_insights'])) {
    $facts['lighthouse_insights'] = $comprehensive['lighthouse_insights'];
  } elseif (isset($comprehensive['lighthouse']) && is_array($comprehensive['lighthouse'])) {
    $facts['lighthouse_insights'] = $comprehensive['lighthouse'];
  } elseif (isset($comprehensive['pagespeed']) && is_array($comprehensive['pagespeed'])) {
    $facts['lighthouse_insights'] = $comprehensive['pagespeed'];
  } elseif (isset($comprehensive['performance']['lighthouse']) && is_array($comprehensive['performance']['lighthouse'])) {
    $facts['lighthouse_insights'] = $comprehensive['performance']['lighthouse'];
  }
  
  // Add SEO data if available
  if (isset($comprehensive['seo']) && is_array($comprehensive['seo'])) {
    $facts['seo'] = $comprehensive['seo'];
  }
  
  // Add data stream summary if available
  if (isset($comprehensive['data_streams_summary']) && is_array($comprehensive['data_streams_summary'])) {
    $facts['data_streams_summary'] = $comprehensive['data_streams_summary'];
  }
  
  // Add Google Ads data (when available)
  if (isset($comprehensive['google_ads']) && is_array($comprehensive['google_ads'])) {
    $facts['google_ads'] = $comprehensive['google_ads'];
  } elseif (isset($comprehensive['marketing']['google_ads']) && is_array($comprehensive['marketing']['google_ads'])) {
    $facts['google_ads'] = $comprehensive['marketing']['google_ads'];
  }
  
  // Add LinkedIn Ads data (when available)
  if (isset($comprehensive['linkedin_ads']) && is_array($comprehensive['linkedin_ads'])) {
    $facts['linkedin_ads'] = $comprehensive['linkedin_ads'];
  } elseif (isset($comprehensive['marketing']['linkedin_ads']) && is_array($comprehensive['marketing']['linkedin_ads'])) {
    $facts['linkedin_ads'] = $comprehensive['marketing']['linkedin_ads'];
  }
  
  // Add Meta Ads data (when available)
  if (isset($comprehensive['meta_ads']) && is_array($comprehensive['meta_ads'])) {
    $facts['meta_ads'] = $comprehensive['meta_ads'];
  } elseif (isset($comprehensive['marketing']['meta_ads']) && is_array($comprehensive['marketing']['meta_ads'])) {
    $facts['meta_ads'] = $comprehensive['marketing']['meta_ads'];
  } elseif (isset($comprehensive['marketing']['facebook_ads']) && is_array($comprehensive['marketing']['facebook_ads'])) {
    $facts['meta_ads'] = $comprehensive['marketing']['facebook_ads'];
  }
  
  // Add ALL VL Hub data sources
  // AWS S3 Data
  if (isset($comprehensive['aws_s3']) && is_array($comprehensive['aws_s3'])) {
    $facts['aws_s3'] = $comprehensive['aws_s3'];
  }
  
  // Liquid Web Assets
  if (isset($comprehensive['liquidweb']) && is_array($comprehensive['liquidweb'])) {
    $facts['liquidweb'] = $comprehensive['liquidweb'];
  }
  
  // SSL/TLS Status Data (from VL Hub SSL/TLS Status connector)
  if (isset($comprehensive['ssl_tls']) && is_array($comprehensive['ssl_tls'])) {
    $facts['ssl_tls'] = $comprehensive['ssl_tls'];
    error_log('[Luna Widget] Added SSL/TLS data to facts from comprehensive profile');
  }
  
  // Cloudflare Data (from VL Hub Cloudflare connector)
  if (isset($comprehensive['cloudflare']) && is_array($comprehensive['cloudflare'])) {
    $facts['cloudflare'] = $comprehensive['cloudflare'];
    error_log('[Luna Widget] Added Cloudflare data to facts from comprehensive profile');
  }
  
  // Security Data Streams (from VL Hub Security tab - SSL/TLS Status and Cloudflare)
  if (isset($comprehensive['data_streams']) && is_array($comprehensive['data_streams'])) {
    $security_streams = array();
    foreach ($comprehensive['data_streams'] as $stream_id => $stream_data) {
      if (isset($stream_data['categories']) && is_array($stream_data['categories']) && in_array('security', $stream_data['categories'])) {
        $security_streams[$stream_id] = $stream_data;
      }
    }
    if (!empty($security_streams)) {
      $facts['security_data_streams'] = $security_streams;
      error_log('[Luna Widget] Added ' . count($security_streams) . ' security data streams to facts');
    }
  }
  
  // GA4 Metrics (if not already added)
  if (isset($comprehensive['ga4_metrics']) && is_array($comprehensive['ga4_metrics']) && !isset($facts['ga4_metrics'])) {
    $facts['ga4_metrics'] = $comprehensive['ga4_metrics'];
    $facts['ga4_last_synced'] = $comprehensive['ga4_last_synced'] ?? null;
    $facts['ga4_date_range'] = $comprehensive['ga4_date_range'] ?? null;
    $facts['ga4_source_url'] = $comprehensive['ga4_source_url'] ?? null;
    $facts['ga4_property_id'] = $comprehensive['ga4_property_id'] ?? null;
    $facts['ga4_measurement_id'] = $comprehensive['ga4_measurement_id'] ?? null;
  }
  
  // All Data Streams (full data)
  if (isset($comprehensive['data_streams']) && is_array($comprehensive['data_streams'])) {
    $facts['data_streams'] = $comprehensive['data_streams'];
  }
  
  // Competitor Reports (full reports from database)
  if (isset($comprehensive['competitor_reports_full']) && is_array($comprehensive['competitor_reports_full'])) {
    // Always use full reports from database as they are the most complete
    $facts['competitor_reports'] = $comprehensive['competitor_reports_full'];
    $facts['competitor_reports_full'] = $comprehensive['competitor_reports_full']; // Keep both keys for compatibility
    error_log('[Luna Widget] Added competitor_reports_full to facts: ' . count($comprehensive['competitor_reports_full']) . ' reports');
  }
  
  // VLDR Metrics (from database - latest for all domains)
  if (isset($comprehensive['vldr_metrics']) && is_array($comprehensive['vldr_metrics'])) {
    // Merge with existing vldr data
    if (!isset($facts['vldr'])) {
      $facts['vldr'] = array();
    }
    foreach ($comprehensive['vldr_metrics'] as $domain => $vldr_data) {
      $facts['vldr'][$domain] = $vldr_data;
    }
  }

  // Ensure we always return a valid array
  if (!is_array($facts) || empty($facts)) {
    error_log('[Luna] Comprehensive facts array is empty or invalid, falling back to basic facts');
    $fallback = luna_profile_facts();
    $fallback['__source'] = 'error-fallback';
    return $fallback;
  }

  $facts['__source'] = 'comprehensive';

  return $facts;
  
  } catch (Exception $e) {
    error_log('[Luna] Exception in luna_profile_facts_comprehensive: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $fallback = luna_profile_facts();
    $fallback['__source'] = 'exception-fallback';
    return $fallback;
  } catch (Error $e) {
    error_log('[Luna] Fatal error in luna_profile_facts_comprehensive: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $fallback = luna_profile_facts();
    $fallback['__source'] = 'error-fallback';
    return $fallback;
  }
}

/* Local snapshot used ONLY as fallback when Hub fact missing */
function luna_snapshot_system() {
  global $wp_version; $theme = wp_get_theme();
  if (!function_exists('get_plugins')) { @require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
  $plugins = function_exists('get_plugins') ? (array)get_plugins() : array();
  $active  = (array) get_option('active_plugins', array());
  $up_pl   = get_site_transient('update_plugins');

  $plugins_out = array();
  foreach ($plugins as $slug => $info) {
    $update_available = isset($up_pl->response[$slug]);
    $plugins_out[] = array(
      'slug' => $slug,
      'name' => isset($info['Name']) ? $info['Name'] : $slug,
      'version' => isset($info['Version']) ? $info['Version'] : null,
      'active' => in_array($slug, $active, true),
      'update_available' => (bool)$update_available,
      'new_version' => $update_available ? (isset($up_pl->response[$slug]->new_version) ? $up_pl->response[$slug]->new_version : null) : null,
    );
  }
  $themes = wp_get_themes(); $up_th = get_site_transient('update_themes');
  $themes_out = array();
  foreach ($themes as $stylesheet => $th) {
    $update_available = isset($up_th->response[$stylesheet]);
    $themes_out[] = array(
      'stylesheet' => $stylesheet,
      'name' => $th->get('Name'),
      'version' => $th->get('Version'),
      'is_active' => (wp_get_theme()->get_stylesheet() === $stylesheet),
      'update_available' => (bool)$update_available,
      'new_version' => $update_available ? (isset($up_th->response[$stylesheet]['new_version']) ? $up_th->response[$stylesheet]['new_version'] : null) : null,
    );
  }

  // Check for WordPress core updates
  $core_updates = get_site_transient('update_core');
  $core_update_available = false;
  if (isset($core_updates->updates) && is_array($core_updates->updates)) {
    foreach ($core_updates->updates as $update) {
      if ($update->response === 'upgrade') {
        $core_update_available = true;
        break;
      }
    }
  }

  return array(
    'site' => array('home_url' => home_url('/'), 'https' => (wp_parse_url(home_url('/'), PHP_URL_SCHEME) === 'https')),
    'wordpress' => array(
      'version' => isset($wp_version) ? $wp_version : null,
      'core_update_available' => $core_update_available,
      'theme'   => array(
        'name'       => $theme->get('Name'),
        'version'    => $theme->get('Version'),
        'stylesheet' => $theme->get_stylesheet(),
        'template'   => $theme->get_template(),
      ),
    ),
    'plugins'     => $plugins_out,
    'themes'      => $themes_out,
    'generated_at'=> gmdate('c'),
  );
}

/* ============================================================
 * FRONT-END: Widget/Shortcode + JS (with history hydrate)
 * ============================================================ */
add_action('wp_enqueue_scripts', function () {
  wp_register_script(
    'luna-composer',
    LUNA_WIDGET_ASSET_URL . 'assets/js/luna-composer.js',
    array(),
    LUNA_WIDGET_PLUGIN_VERSION,
    true
  );
});

add_shortcode('luna_chat', function($atts = array(), $content = ''){
  // Parse shortcode attributes properly
  $atts = shortcode_atts(array(
    'vl_key' => '',
  ), $atts, 'luna_chat');
  
  // Check for vl_key parameter (for Supercluster embedding)
  $vl_key = !empty($atts['vl_key']) ? sanitize_text_field($atts['vl_key']) : '';
  
  // If vl_key is provided, validate it and allow shortcode even if widget mode is active
  if ($vl_key !== '') {
    // Validate the license key matches the stored license
    $stored_license = trim((string) get_option(LUNA_WIDGET_OPT_LICENSE, ''));
    if ($stored_license === '' || $stored_license !== $vl_key) {
      // License doesn't match - return empty or error message
      return '<!-- [luna_chat] License key validation failed -->';
    }
    // License matches - proceed with shortcode rendering (works even if widget mode is active)
  } else {
    // No vl_key provided - check widget mode as before
  if (get_option(LUNA_WIDGET_OPT_MODE, 'widget') !== 'shortcode') {
    return '<!-- [luna_chat] disabled: floating widget active -->';
  }
  }
  
  ob_start(); ?>
  <div class="luna-wrap">
    <div class="luna-thread"></div>
    <form class="luna-form" onsubmit="return false;">
      <input class="luna-input" autocomplete="off" placeholder="Ask Luna…" />
      <button class="luna-send" type="submit">Send</button>
    </form>
  </div>
  <?php return ob_get_clean();
});

add_shortcode('luna_composer', function($atts = array(), $content = '') {
  $enabled = get_option(LUNA_WIDGET_OPT_COMPOSER_ENABLED, '1') === '1';
  if (!$enabled) {
    return '<div class="luna-composer-disabled">' . esc_html__('Luna Composer is currently disabled.', 'luna') . '</div>';
  }

  wp_enqueue_script('luna-composer');

  static $composer_localized = false;
  if (!$composer_localized) {
    $prompts = array();
    foreach (luna_composer_default_prompts() as $prompt) {
      $label  = isset($prompt['label']) ? (string) $prompt['label'] : '';
      $prompt_text = isset($prompt['prompt']) ? (string) $prompt['prompt'] : '';
      if ($label === '' || $prompt_text === '') {
        continue;
      }
      $prompts[] = array(
        'label'  => sanitize_text_field($label),
        'prompt' => wp_strip_all_tags($prompt_text),
      );
    }

    wp_localize_script('luna-composer', 'lunaComposerSettings', array(
      'restUrlChat' => esc_url_raw(rest_url('luna_widget/v1/chat')),
      'nonce'       => is_user_logged_in() ? wp_create_nonce('wp_rest') : null,
      'integrated'  => true,
      'prompts'     => $prompts,
    ));
    $composer_localized = true;
  }

  $id = esc_attr(wp_unique_id('luna-composer-'));
  $placeholder = apply_filters('luna_composer_placeholder', __('Describe what you need from Luna…', 'luna'));
  $inner_content = trim($content) !== '' ? do_shortcode($content) : '';

  ob_start();
  ?>
  <div class="luna-composer" data-luna-composer data-luna-composer-id="<?php echo $id; ?>">
    <div class="luna-composer__card">
      <div data-luna-prompts>
        <?php echo $inner_content ? wp_kses_post($inner_content) : ''; ?>
      </div>
      <form class="luna-composer__form" action="#" method="post" novalidate>
        <div
          class="luna-composer__editor is-empty"
          data-luna-composer-editor
          contenteditable="true"
          role="textbox"
          aria-multiline="true"
          spellcheck="true"
          data-placeholder="<?php echo esc_attr($placeholder); ?>"
        ></div>
        <div class="luna-composer__actions">
          <button type="submit" class="luna-composer__submit" data-luna-composer-submit>
            <?php esc_html_e('', 'luna'); ?>
          </button>
        </div>
      </form>
      <div class="luna-composer__response" data-luna-composer-response></div>
    </div>
  </div>
  <?php
  return ob_get_clean();
});

add_action('wp_footer', function () {
  if (is_admin()) return;

  $mode = get_option(LUNA_WIDGET_OPT_MODE, 'widget');
  $supercluster_only = get_option(LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY, '0') === '1';
  
  // If Supercluster only is enabled, don't render on frontend
  if ($supercluster_only) {
    return;
  }

  $ui   = get_option(LUNA_WIDGET_OPT_SETTINGS, array());
  $pos  = isset($ui['position']) ? $ui['position'] : 'bottom-right';

  if ($mode === 'widget') {
    $pos_css = 'bottom:20px;right:20px;';
    if ($pos === 'top-left') { $pos_css = 'top:20px;left:20px;'; }
    elseif ($pos === 'top-center') { $pos_css = 'top:20px;left:50%;transform:translateX(-50%);'; }
    elseif ($pos === 'top-right') { $pos_css = 'top:20px;right:20px;'; }
    elseif ($pos === 'bottom-left') { $pos_css = 'bottom:20px;left:20px;'; }
    elseif ($pos === 'bottom-center') { $pos_css = 'bottom:20px;left:50%;transform:translateX(-50%);'; }

    $title = esc_html(isset($ui['title']) ? $ui['title'] : 'Luna Chat');
    $avatar= esc_url(isset($ui['avatar_url']) ? $ui['avatar_url'] : '');
    $hdr   = esc_html(isset($ui['header_text']) ? $ui['header_text'] : "Hi, I'm Luna");
    $sub   = esc_html(isset($ui['sub_text']) ? $ui['sub_text'] : 'How can I help today?');

    $panel_anchor = (strpos($pos,'bottom') !== false ? 'bottom:80px;' : 'top:80px;')
                  . (strpos($pos,'right') !== false ? 'right:20px;' : (strpos($pos,'left') !== false ? 'left:20px;' : 'left:50%;transform:translateX(-50%);'));
    ?>
    <style>
      .luna-fab { position:fixed !important; z-index:2147483647 !important; <?php echo $pos_css; ?> }
      .luna-launcher{display:flex;align-items:center;gap:10px;background:#111;color:#fff4e9;border:1px solid #5A5753;border-radius:999px;padding:5px 17px 5px 8px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.25)}
      .luna-launcher .ava{width:42px;height:42px;border-radius:50%;background:#222;overflow:hidden;display:inline-flex;align-items:center;justify-content:center}
      .luna-launcher .txt{line-height:1.2;display:flex;flex-direction:column;overflow:hidden;position:relative}
      .luna-launcher .txt span{max-width:222px !important;display:inline-block;white-space:nowrap;overflow:hidden}
      .luna-launcher .txt span.luna-scroll-wrapper{overflow:hidden;position:relative;max-width:130px !important;display:inline-block}
      .luna-launcher .txt span.luna-scroll-inner{display:inline-block;white-space:nowrap;animation:lunaInfiniteScroll 10s infinite linear;will-change:transform;vertical-align: -webkit-baseline-middle;}
      .luna-launcher .txt span.luna-scroll-inner span{display:inline-block;white-space:nowrap;margin:0;padding:0;max-width:none !important}
      @keyframes lunaInfiniteScroll{from{transform:translateX(0%)}to{transform:translateX(-50%)}}
      .luna-panel{position: fixed !important;z-index: 2147483647 !important; <?php echo $panel_anchor; ?> width: clamp(320px,92vw,420px);max-height: min(70vh,560px);display: none;flex-direction: column;border-radius: 12px;border: 1px solid #232120;background: #000;color: #fff4e9;overflow: hidden;}
      .luna-panel.show{display:flex !important;z-index: 2147483647 !important;}
      .luna-head{padding:10px 12px;font-weight:600;background:#000;border-bottom:1px solid #333;display:flex;align-items:center;justify-content:space-between}
      .luna-thread{padding:10px 12px;overflow:auto;flex:1 1 auto}
      .luna-form{display:flex;gap:8px;padding:10px 12px;border-top:1px solid #333}
      .luna-input{flex:1 1 auto;background:#111;color:#fff4e9;border:1px solid #333;border-radius:10px;padding:8px 10px}
      .luna-send{background:linear-gradient(270deg, #974C00 0%, #8D8C00 100%) !important;color:#000;border:none;border-radius:10px;padding:8px 12px;cursor:pointer;font-size: .88rem;font-weight: 600}
      .luna-thread .luna-msg{clear:both;margin:6px 0}
      .luna-thread .luna-user{float:right;background:#fff4e9;color:#000;display:inline-block;padding:8px 10px;border-radius:10px;max-width:85%;word-wrap:break-word}
      .luna-thread .luna-assistant{float:left;background:#000000;border:1px solid #2E2C2A;color:#fff4e9;display:inline-block;padding:10px;border-radius:10px;max-width:85%;word-wrap:break-word;line-height:1.25rem;}
      .luna-initial-greeting{display:flex;flex-direction:column;gap:12px}
      .luna-greeting-text{margin-bottom:10px;line-height: 1.25rem;}
      .luna-greeting-buttons{display:flex;flex-direction:column;gap:8px;width:100%}
      .luna-greeting-btn{width:100%;padding:10px 14px;background:#2E2C2A;border:none;border-radius:8px;color:#fff4e9;font-size:0.9rem;font-weight:600;cursor:pointer;transition:all 0.2s ease;text-align:left;display:flex;align-items:center;justify-content:space-between}
      .luna-greeting-btn:hover{background:#3A3836;transform:translateY(-1px)}
      .luna-greeting-btn:active{transform:translateY(0)}
      .luna-greeting-btn-chat{background:linear-gradient(270deg, #974C00 0%, #8D8C00 100%);color:#000;border-color:#5A5753}
      .luna-greeting-btn-chat:hover{background:linear-gradient(270deg, #B85C00 0%, #A5A000 100%)}
      .luna-greeting-btn-report{background:#2E2C2A}
      .luna-greeting-btn-compose{background:#2E2C2A}
      .luna-greeting-btn-automate{background:#2E2C2A}
      .luna-greeting-help{width:20px;height:20px;border-radius:50%;background-color:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:600;cursor:help;margin-left:8px;flex-shrink:0;transition:background-color 0.2s ease}
      .luna-greeting-help:hover{background-color:rgba(255,255,255,.25)}
      .luna-greeting-tooltip{position:absolute;background:#000;color:#fff4e9;padding:12px 16px;border-radius:8px;font-size:0.85rem;line-height:1.4;max-width:280px;z-index:999999;box-shadow:0 4px 12px rgba(0,0,0,0.4);pointer-events:none;border:1px solid #5A5753;word-wrap:break-word}
      .luna-thread .luna-session-closure{opacity:.85;font-style:italic}
      .luna-thread .luna-loading{float:left;background:#111;border:1px solid #333;color:#fff4e9;display:inline-block;padding:8px 10px;border-radius:10px;max-width:85%;word-wrap:break-word}
      .luna-loading-text{background:radial-gradient(circle at 0%,#fff4e9,#c2b8ad 50%,#978e86 75%,#fff4e9 75%);font-weight:600;background-size:200% auto;color:#000;background-clip:text;-webkit-text-fill-color:transparent;animation:animatedTextGradient 1.5s linear infinite;height:20px !important;}
      @keyframes animatedTextGradient{from{background-position:0% center}to{background-position:200% center}}
        .luna-session-ended{position:fixed;z-index:999999 !important;left:2rem !important;right:auto !important;width:clamp(320px,92vw,420px);display:none;align-items:center;justify-content:center}
      .luna-session-ended.show{display:flex}
      .luna-session-ended-card{background:#000;border:1px solid #5A5753;border-radius:12px;padding:24px 20px;display:flex;flex-direction:column;gap:12px;align-items:center;text-align:center;box-shadow:0 24px 48px rgba(0,0,0,.4);width:100%}
      .luna-session-ended-card h2{margin:0;font-size:1.25rem}
      .luna-session-ended-card p{margin:0;color:#ccc}
      .luna-session-ended-card .luna-session-restart{background:#2c74ff;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-weight:600;cursor:pointer}
      .luna-session-ended-card .luna-session-restart:hover{background:#4c8bff}
      .luna-session-ended-inline{margin-top:12px;background:#000;border:1px solid #5A5753;border-radius:12px;padding:24px 20px;text-align:center;display:flex;flex-direction:column;gap:12px;align-items:center}
      .luna-session-ended-inline button{background:#2c74ff;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-weight:600;cursor:pointer}
      .luna-session-ended-inline button:hover{background:#4c8bff}
    </style>
    <div class="luna-fab" aria-live="polite">
      <button class="luna-launcher" aria-expanded="false" aria-controls="luna-panel" title="<?php echo $title; ?>">
        <span class="ava">
          <?php if ($avatar): ?><img src="<?php echo $avatar; ?>" alt="" style="width:42px;height:42px;object-fit:cover"><?php else: ?>
            <svg width="24" height="24" viewBox="0 0 36 36" fill="none" aria-hidden="true"><circle cx="18" cy="18" r="18" fill="#222"/><path d="M18 18a6 6 0 100-12 6 6 0 000 12zm0 2c-6 0-10 3.2-10 6v2h20v-2c0-2.8-4-6-10-6z" fill="#666"/></svg>
          <?php endif; ?>
        </span>
        <span class="txt"><strong><?php echo $hdr; ?></strong><span><?php echo $sub; ?></span></span>
      </button>
      <div id="luna-panel" class="luna-panel" role="dialog" aria-label="<?php echo $title; ?>">
        <div class="luna-head"><span><?php echo $title; ?></span><button class="luna-close" style="background:transparent;border:0;color:#fff;cursor:pointer" aria-label="Close">✕</button></div>
        <div class="luna-thread"></div>
        <form class="luna-form"><input class="luna-input" placeholder="Ask Luna…" autocomplete="off"><button type="button" class="luna-send">Send</button></form>
      </div>
    </div>
    <div id="luna-session-ended" class="luna-session-ended" style="<?php echo $panel_anchor; ?> display:none;" role="dialog" aria-modal="true" aria-labelledby="luna-session-ended-title">
      <div class="luna-session-ended-card">
        <h2 id="luna-session-ended-title">Your session has ended</h2>
        <p>Start another one now.</p>
        <button type="button" class="luna-session-restart">Start New Session</button>
      </div>
    </div>
    <script>
      (function(){
        var fab=document.querySelector('.luna-launcher'), panel=document.querySelector('#luna-panel');
        
        // Setup scrolling text animation for launcher subtitle
        function setupLauncherTextScroll() {
          var txtSpan = fab ? fab.querySelector('.txt span:not(strong)') : null;
          if (txtSpan) {
            // Measure text width
            var tempSpan = document.createElement('span');
            tempSpan.style.visibility = 'hidden';
            tempSpan.style.position = 'absolute';
            tempSpan.style.whiteSpace = 'nowrap';
            tempSpan.textContent = txtSpan.textContent;
            document.body.appendChild(tempSpan);
            var textWidth = tempSpan.offsetWidth;
            document.body.removeChild(tempSpan);
            
            // If text is wider than 135px, add infinite scrolling animation
            if (textWidth > 135) {
              // Store original text
              var originalText = txtSpan.textContent;
              
              // Create wrapper and inner structure for infinite scroll
              var wrapper = document.createElement('span');
              wrapper.className = 'luna-scroll-wrapper';
              
              var inner = document.createElement('span');
              inner.className = 'luna-scroll-inner';
              
              // Duplicate text 4 times for seamless infinite loop
              for (var i = 0; i < 4; i++) {
                var textSpan = document.createElement('span');
                textSpan.textContent = originalText + ' ';
                inner.appendChild(textSpan);
              }
              
              wrapper.appendChild(inner);
              
              // Replace original span with new structure
              txtSpan.parentNode.replaceChild(wrapper, txtSpan);
              
              // Calculate animation duration based on text width
              // Scroll speed ~35px per second (30% slower than 50px/s)
              // We need to scroll by one full text width to reveal the entire text
              var scrollDistance = textWidth; // Scroll distance is the full text width
              var scrollTime = scrollDistance / 35; // Time to scroll one full text width
              var pauseTime = 2; // 2 second pause
              var totalDuration = scrollTime + pauseTime;
              var pausePercent = (pauseTime / totalDuration) * 100; // Percentage for pause
              var scrollStartPercent = pausePercent; // Start scrolling after pause
              
              // Calculate the pixel value to scroll (one full text width)
              var scrollPixels = -textWidth;
              
              // Create unique animation name
              var animName = 'lunaInfiniteScroll_' + Date.now();
              
              // Create dynamic keyframes: pause at start, then scroll by one full text width
              // Since we have 4 copies, scrolling by one width creates seamless loop
              var style = document.createElement('style');
              style.id = 'luna-scroll-animation-' + Date.now();
              style.textContent = '@keyframes ' + animName + '{0%{transform:translateX(0)}' + scrollStartPercent + '%{transform:translateX(0)}100%{transform:translateX(' + scrollPixels + 'px)}}';
              document.head.appendChild(style);
              
              // Apply animation with calculated duration
              inner.style.animation = animName + ' ' + totalDuration + 's infinite linear';
            }
          }
        }
        
        // Initialize text scroll after DOM is ready
        if (fab) {
          setTimeout(setupLauncherTextScroll, 100);
        }
        
        // Function to blur Supercluster elements
        function blurSuperclusterElements(blur) {
          // Blur canvas
          var canvas = document.querySelector('canvas');
          if (canvas) {
            if (blur) {
              canvas.style.setProperty('filter', 'blur(8px)', 'important');
              canvas.style.setProperty('pointer-events', 'none', 'important');
            } else {
              canvas.style.removeProperty('filter');
              canvas.style.setProperty('pointer-events', 'inherit', 'important');
            }
          }
          
          // Blur #vlSuperclusterRoot::after (using a style element)
          var root = document.querySelector('#vlSuperclusterRoot');
          if (root) {
            var styleId = 'luna-blur-root-after';
            var existingStyle = document.getElementById(styleId);
            if (blur) {
              if (!existingStyle) {
                var style = document.createElement('style');
                style.id = styleId;
                style.textContent = '#vlSuperclusterRoot::after { filter: blur(8px) !important; pointer-events: none !important; }';
                document.head.appendChild(style);
              }
            } else {
              if (existingStyle) {
                existingStyle.remove();
              }
            }
          }
          
          // Blur .vl-supercluster-labels
          var labels = document.querySelectorAll('.vl-supercluster-labels');
          if (labels && labels.length > 0) {
            labels.forEach(function(label) {
              if (blur) {
                label.style.setProperty('filter', 'blur(8px)', 'important');
                label.style.setProperty('pointer-events', 'none', 'important');
              } else {
                label.style.removeProperty('filter');
                label.style.setProperty('pointer-events', 'inherit', 'important');
              }
            });
          }
          
          // Blur .vl-header
          var header = document.querySelector('.vl-header');
          if (header) {
            if (blur) {
              header.style.setProperty('filter', 'blur(8px)', 'important');
              header.style.setProperty('pointer-events', 'none', 'important');
            } else {
              header.style.removeProperty('filter');
              header.style.setProperty('pointer-events', 'inherit', 'important');
            }
          }
          
          // Blur .vl-main-menu
          var mainMenu = document.querySelector('.vl-main-menu');
          if (mainMenu) {
            if (blur) {
              mainMenu.style.setProperty('filter', 'blur(8px)', 'important');
              mainMenu.style.setProperty('pointer-events', 'none', 'important');
            } else {
              mainMenu.style.removeProperty('filter');
              mainMenu.style.setProperty('pointer-events', 'inherit', 'important');
            }
          }
          
          // Blur .vl-right-sidebar
          var rightSidebar = document.querySelector('.vl-right-sidebar');
          if (rightSidebar) {
            if (blur) {
              rightSidebar.style.setProperty('filter', 'blur(8px)', 'important');
              rightSidebar.style.setProperty('pointer-events', 'none', 'important');
            } else {
              rightSidebar.style.removeProperty('filter');
              rightSidebar.style.setProperty('pointer-events', 'inherit', 'important');
            }
          }
        }
        
        // Ensure panel parent doesn't create stacking context
        if (panel && panel.parentNode && panel.parentNode !== document.body) {
          var panelParent = panel.parentNode;
          // Check computed styles to see if parent creates stacking context
          var computedStyle = window.getComputedStyle(panelParent);
          if (computedStyle.position !== 'static' || computedStyle.zIndex !== 'auto') {
            // Force parent to not create stacking context
            panelParent.style.setProperty('position', 'static', 'important');
            panelParent.style.setProperty('z-index', 'auto', 'important');
            panelParent.style.setProperty('isolation', 'auto', 'important');
          }
        }
        var closeBtn=document.querySelector('.luna-close');
        var ended=document.querySelector('#luna-session-ended');

        async function hydrate(thread){
          if (!thread || thread.__hydrated) return;
          try{
            const res = await fetch('<?php echo esc_url_raw( rest_url('luna_widget/v1/chat/history') ); ?>');
            const data = await res.json();
            var hasMessages = false;
            if (data && Array.isArray(data.items)) {
              data.items.forEach(function(turn){
                if (turn.user) { var u=document.createElement('div'); u.className='luna-msg luna-user'; u.textContent=turn.user; thread.appendChild(u); hasMessages = true; }
                if (turn.assistant) { var a=document.createElement('div'); a.className='luna-msg luna-assistant'; a.textContent=turn.assistant; thread.appendChild(a); hasMessages = true; }
              });
              thread.scrollTop = thread.scrollHeight;
            }
            thread.__hydrated = true;
            
            // Auto-send initial greeting if thread is empty
            if (!hasMessages) {
              setTimeout(function(){
                sendInitialGreeting(thread);
              }, 300);
            }
          }catch(e){ 
            console.warn('[Luna] hydrate failed', e);
            // If hydration fails, still try to send greeting if thread is empty
            if (!thread.__hydrated && thread.children.length === 0) {
              setTimeout(function(){
                sendInitialGreeting(thread);
              }, 300);
            }
          }
          finally { thread.__hydrated = true; }
        }
        
        function sendInitialGreeting(thread){
          if (!thread) return;
          // Check if thread already has messages
          if (thread.querySelectorAll('.luna-msg').length > 0) return;
          
          // Get license key from URL or stored option
          var licenseKey = '';
          var urlParams = new URLSearchParams(window.location.search);
          var urlLicense = urlParams.get('license');
            if (urlLicense) {
              // Extract license key from URL (may include path segments)
              licenseKey = urlLicense.split('/')[0];
          } else {
            // Try to get from stored option (for frontend sites)
            try {
              var storedLicense = '<?php echo esc_js( get_option(LUNA_WIDGET_OPT_LICENSE, '') ); ?>';
              if (storedLicense) {
                licenseKey = storedLicense;
              }
            } catch(e) {
              console.warn('[Luna] Could not get stored license key');
            }
          }
          
          // Create greeting message with buttons
          var greetingEl = document.createElement('div');
          greetingEl.className = 'luna-msg luna-assistant luna-initial-greeting';
          
          var greetingText = document.createElement('div');
          greetingText.className = 'luna-greeting-text';
          greetingText.textContent = 'Hi, there! I\'m Luna, your personal WebOps agent and AI companion. How would you like to continue?';
          greetingEl.appendChild(greetingText);
          
          // Create buttons container
          var buttonsContainer = document.createElement('div');
          buttonsContainer.className = 'luna-greeting-buttons';
          
          // Get button descriptions from settings
          var buttonDescs = {
            chat: '<?php echo esc_js( isset($ui['button_desc_chat']) ? $ui['button_desc_chat'] : 'Start a conversation with Luna to ask questions and get answers about your digital universe.' ); ?>',
            report: '<?php echo esc_js( isset($ui['button_desc_report']) ? $ui['button_desc_report'] : 'Generate comprehensive reports about your site health, performance, and security.' ); ?>',
            compose: '<?php echo esc_js( isset($ui['button_desc_compose']) ? $ui['button_desc_compose'] : 'Access Luna Composer to use canned prompts and responses for quick interactions.' ); ?>',
            automate: '<?php echo esc_js( isset($ui['button_desc_automate']) ? $ui['button_desc_automate'] : 'Set up automated workflows and tasks with Luna to streamline your operations.' ); ?>'
          };
          
          // Helper function to create button with question mark icon
          function createGreetingButton(text, className, description, clickHandler) {
            var btn = document.createElement('button');
            btn.className = 'luna-greeting-btn ' + className;
            btn.style.position = 'relative';
            btn.style.display = 'flex';
            btn.style.alignItems = 'center';
            btn.style.justifyContent = 'space-between';
            btn.style.padding = '10px 14px';
            
            var btnText = document.createElement('span');
            btnText.textContent = text;
            btnText.style.flex = '1';
            btn.appendChild(btnText);
            
            var questionMark = document.createElement('span');
            questionMark.className = 'luna-greeting-help';
            questionMark.textContent = '?';
            questionMark.style.cssText = 'width:20px;height:20px;border-radius:50%;background-color:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:600;cursor:help;margin-left:8px;flex-shrink:0;transition:background-color 0.2s ease;';
            questionMark.setAttribute('data-description', description);
            questionMark.setAttribute('aria-label', 'Help');
            questionMark.addEventListener('mouseenter', function(e){
              e.stopPropagation();
              showButtonTooltip(questionMark, description);
            });
            questionMark.addEventListener('mouseleave', function(e){
              e.stopPropagation();
              hideButtonTooltip();
            });
            btn.appendChild(questionMark);
            
            btn.addEventListener('click', clickHandler);
            return btn;
          }
          
          // Luna Chat button
          var chatBtn = createGreetingButton('Luna Chat', 'luna-greeting-btn-chat', buttonDescs.chat, function(e){
            e.preventDefault();
            e.stopPropagation();
            // Remove greeting buttons
            greetingEl.querySelector('.luna-greeting-buttons').remove();
            // Auto-reply with chat message
            var chatMsg = document.createElement('div');
            chatMsg.className = 'luna-msg luna-assistant';
            chatMsg.textContent = 'Let\'s do this! Ask me anything to begin exploring...';
            thread.appendChild(chatMsg);
            thread.scrollTop = thread.scrollHeight;
            // Mark that user has started a chat session - inactivity timer will now be active
            if (window.LunaChatSession) {
              window.LunaChatSession.chatStarted = true;
            }
            // Start inactivity timer when user sends their first message (via markActivity)
            // The timer will start automatically when user interacts with the chat input
          });
          buttonsContainer.appendChild(chatBtn);
          
          // Luna Report button
          var reportBtn = createGreetingButton('Luna Report', 'luna-greeting-btn-report', buttonDescs.report, function(e){
            e.preventDefault();
            e.stopPropagation();
            // Cancel inactivity timers and close widget before redirecting
            if (window.LunaChatSession && typeof window.LunaChatSession.cancelTimers === 'function') {
              window.LunaChatSession.cancelTimers();
            }
            var panel = document.getElementById('luna-panel');
            if (panel) {
              panel.classList.remove('show');
            }
            if (licenseKey) {
              window.location.href = 'https://supercluster.visiblelight.ai/?license=' + encodeURIComponent(licenseKey) + '/luna/report/';
            } else {
              console.warn('[Luna] License key not available for redirect');
            }
          });
          buttonsContainer.appendChild(reportBtn);
          
          // Luna Composer button
          var composeBtn = createGreetingButton('Luna Compose', 'luna-greeting-btn-compose', buttonDescs.compose, function(e){
            e.preventDefault();
            e.stopPropagation();
            // Cancel inactivity timers and close widget before redirecting
            if (window.LunaChatSession && typeof window.LunaChatSession.cancelTimers === 'function') {
              window.LunaChatSession.cancelTimers();
            }
            var panel = document.getElementById('luna-panel');
            if (panel) {
              panel.classList.remove('show');
            }
            if (licenseKey) {
              window.location.href = 'https://supercluster.visiblelight.ai/?license=' + encodeURIComponent(licenseKey) + '/luna/compose/';
            } else {
              console.warn('[Luna] License key not available for redirect');
            }
          });
          buttonsContainer.appendChild(composeBtn);
          
          // Luna Automate button
          var automateBtn = createGreetingButton('Luna Automate', 'luna-greeting-btn-automate', buttonDescs.automate, function(e){
            e.preventDefault();
            e.stopPropagation();
            // Cancel inactivity timers and close widget before redirecting
            if (window.LunaChatSession && typeof window.LunaChatSession.cancelTimers === 'function') {
              window.LunaChatSession.cancelTimers();
            }
            var panel = document.getElementById('luna-panel');
            if (panel) {
              panel.classList.remove('show');
            }
            if (licenseKey) {
              window.location.href = 'https://supercluster.visiblelight.ai/?license=' + encodeURIComponent(licenseKey) + '/luna/automate/';
            } else {
              console.warn('[Luna] License key not available for redirect');
            }
          });
          buttonsContainer.appendChild(automateBtn);
          
          // Tooltip functions
          var tooltip = null;
          function showButtonTooltip(element, description) {
            if (tooltip) {
              tooltip.remove();
            }
            tooltip = document.createElement('div');
            tooltip.className = 'luna-greeting-tooltip';
            tooltip.textContent = description;
            tooltip.style.cssText = 'position:absolute;background:#000;color:#fff4e9;padding:12px 16px;border-radius:8px;font-size:0.85rem;line-height:1.4;max-width:280px;z-index:999999;box-shadow:0 4px 12px rgba(0,0,0,0.4);pointer-events:none;border:1px solid #5A5753;';
            document.body.appendChild(tooltip);
            
            var rect = element.getBoundingClientRect();
            var tooltipRect = tooltip.getBoundingClientRect();
            var left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
            var top = rect.top - tooltipRect.height - 8;
            
            if (left < 10) left = 10;
            if (left + tooltipRect.width > window.innerWidth - 10) {
              left = window.innerWidth - tooltipRect.width - 10;
            }
            if (top < 10) {
              top = rect.bottom + 8;
            }
            
            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
          }
          
          function hideButtonTooltip() {
            if (tooltip) {
              tooltip.remove();
              tooltip = null;
            }
          }
          
          greetingEl.appendChild(buttonsContainer);
          thread.appendChild(greetingEl);
          thread.scrollTop = thread.scrollHeight;
          
          // Log the greeting to the conversation
          try {
            fetch('<?php echo esc_url_raw( rest_url('luna_widget/v1/chat') ); ?>', {
              method: 'POST',
              headers: {'Content-Type':'application/json'},
              body: JSON.stringify({ prompt: '', greeting: true })
            }).catch(function(err){
              console.warn('[Luna] greeting log failed', err);
            });
          } catch(err){
            console.warn('[Luna] greeting fetch error', err);
          }
        }
        function showEnded(){
          if (ended) ended.classList.add('show');
          if (panel) panel.classList.remove('show');
          blurSuperclusterElements(false);
          if (fab) fab.setAttribute('aria-expanded','false');
        }
        function hideEnded(){
          if (ended) ended.classList.remove('show');
        }
        window.__lunaShowSessionEnded = showEnded;
        window.__lunaHideSessionEnded = hideEnded;
        function toggle(open){
          if(!panel||!fab) return;
          var will=(typeof open==='boolean')?open:!panel.classList.contains('show');
          // Only show ended state if explicitly closing and session is actually closing
          if (will && window.LunaChatSession && window.LunaChatSession.closing === true) {
            showEnded();
            return;
          }
          if (will && ended) ended.classList.remove('show');
          
          // Use requestAnimationFrame to ensure smooth display
          requestAnimationFrame(function(){
            // Set z-index BEFORE toggling classes
            if (will) {
              // Ensure fab has highest z-index with !important
              var fabContainer = fab ? (fab.closest('.luna-fab') || fab.parentElement) : null;
              if (fabContainer) {
                fabContainer.style.setProperty('z-index', '2147483647', 'important');
                fabContainer.style.setProperty('position', 'fixed', 'important');
              }
              if (fab) {
                fab.style.setProperty('z-index', '2147483647', 'important');
              }
            }
            
          panel.classList.toggle('show',will);
            
            // Blur/unblur Supercluster elements
            blurSuperclusterElements(will);
          fab.setAttribute('aria-expanded',will?'true':'false');
            
            // Hide/show galaxy labels
            var labels = document.querySelectorAll('.vl-supercluster-labels');
            if (labels && labels.length > 0) {
              labels.forEach(function(label){
          if (will) {
                  label.style.display = 'none';
                  label.style.visibility = 'hidden';
                  label.style.opacity = '0';
                } else {
                  label.style.display = '';
                  label.style.visibility = '';
                  label.style.opacity = '';
                }
              });
            }
            
            if (will) {
              // Force panel to stay visible with highest z-index using !important
              panel.style.setProperty('z-index', '2147483647', 'important');
              panel.style.setProperty('position', 'fixed', 'important');
              panel.style.setProperty('display', 'flex', 'important');
              panel.style.setProperty('visibility', 'visible', 'important');
              panel.style.setProperty('opacity', '1', 'important');
              
              // Ensure fab has highest z-index and is positioned correctly with !important
              var fabContainer = fab ? (fab.closest('.luna-fab') || fab.parentElement) : null;
              if (fabContainer) {
                fabContainer.style.setProperty('z-index', '2147483647', 'important');
                fabContainer.style.setProperty('position', 'fixed', 'important');
              }
              if (fab) {
                fab.style.setProperty('z-index', '2147483647', 'important');
              }
              
              // Hydrate after a small delay to ensure panel is visible
              setTimeout(function(){
            hydrate(panel.querySelector('.luna-thread'));
            if (window.LunaChatSession && typeof window.LunaChatSession.onPanelToggle === 'function') {
              window.LunaChatSession.onPanelToggle(true);
            }
              }, 50);
          } else {
              // Explicitly hide panel when closing
              panel.style.setProperty('display', 'none', 'important');
              panel.style.setProperty('visibility', 'hidden', 'important');
              panel.style.setProperty('opacity', '0', 'important');
            if (window.LunaChatSession && typeof window.LunaChatSession.onPanelToggle === 'function') {
              window.LunaChatSession.onPanelToggle(false);
            }
            hideEnded();
          }
          });
        }
        if(fab) fab.addEventListener('click', function(e){ 
          e.stopPropagation(); 
          // If panel is already showing, close it; otherwise open it
          if (panel && panel.classList.contains('show')) {
            toggle(false);
          } else {
            toggle(true);
          }
        });
        // Use event delegation for close button to ensure it always works
        document.addEventListener('click', function(e){
          if (e.target && e.target.classList.contains('luna-close')) {
            e.preventDefault();
            e.stopPropagation();
            console.log('[Luna] Close button clicked');
            toggle(false);
          }
        });
        if(ended){
          var restartBtn = ended.querySelector('.luna-session-restart');
          if (restartBtn) restartBtn.addEventListener('click', function(){
            if (window.LunaChatSession && typeof window.LunaChatSession.restartSession === 'function') {
              window.LunaChatSession.restartSession();
            }
          });
        }
        document.addEventListener('keydown', function(e){
          if(e.key==='Escape'){
            toggle(false);
            hideEnded();
          }
        });
      })();
    </script>
    <?php
  }
  ?>
  <script>
    (function(){
      if (typeof window.chat_inactive_response !== 'function') {
        window.chat_inactive_response = function () {
          return "I haven't heard from you in a while, are you still there? If not, I'll close out this chat automatically in 3 minutes.";
        };
      }

      if (window.__lunaBoot) return;
      window.__lunaBoot = true;

      const defaultInactivityMessage = "I haven't heard from you in a while, are you still there? If not, I'll close out this chat automatically in 3 minutes.";
      const defaultSessionEndMessage = "This chat session has been closed due to inactivity.";

      const sessionState = {
        inactivityDelay: 120000,
        closureDelay: 180000,
        inactivityTimer: null,
        closureTimer: null,
        closing: false,
        restarting: false,
        _inlineEndedCard: null,
        chatStarted: false // Track if user has clicked "Luna Chat" and started a chat session
      };

      const chatEndpoint = '<?php echo esc_url_raw( rest_url('luna_widget/v1/chat') ); ?>';
      const chatHistoryEndpoint = '<?php echo esc_url_raw( rest_url('luna_widget/v1/chat/history') ); ?>';
      const chatInactiveEndpoint = '<?php echo esc_url_raw( rest_url('luna_widget/v1/chat/inactive') ); ?>';
      const chatSessionEndEndpoint = '<?php echo esc_url_raw( rest_url('luna_widget/v1/chat/session/end') ); ?>';
      const chatSessionResetEndpoint = '<?php echo esc_url_raw( rest_url('luna_widget/v1/chat/session/reset') ); ?>';

      function resolveInactivityMessage() {
        try {
          if (typeof window.chat_inactive_response === 'function') {
            var custom = window.chat_inactive_response();
            if (custom && typeof custom === 'string') {
              return custom;
            }
          }
        } catch (err) {
          console.warn('[Luna] inactive response error', err);
        }
        return defaultInactivityMessage;
      }

      function resolveSessionEndMessage() {
        return defaultSessionEndMessage;
      }

      function getPrimaryThread() {
        return document.querySelector('#luna-panel .luna-thread') || document.querySelector('.luna-thread');
      }

      function appendAssistantMessage(thread, message, extraClass) {
        if (!thread || !message) return null;
        var el = document.createElement('div');
        el.className = 'luna-msg luna-assistant' + (extraClass ? ' ' + extraClass : '');
        el.textContent = message;
        thread.appendChild(el);
        thread.scrollTop = thread.scrollHeight;
        return el;
      }

      function cancelTimers() {
        if (sessionState.inactivityTimer) {
          clearTimeout(sessionState.inactivityTimer);
          sessionState.inactivityTimer = null;
        }
        if (sessionState.closureTimer) {
          clearTimeout(sessionState.closureTimer);
          sessionState.closureTimer = null;
        }
      }

      function setFormsDisabled(disabled) {
        document.querySelectorAll('.luna-form .luna-input').forEach(function(input){
          input.disabled = disabled;
          if (disabled) input.blur();
        });
        document.querySelectorAll('.luna-form .luna-send').forEach(function(button){
          button.disabled = disabled;
        });
      }

      function showSessionEndedUI() {
        if (typeof window.__lunaShowSessionEnded === 'function') {
          window.__lunaShowSessionEnded();
        } else {
          var wrap = document.querySelector('.luna-wrap');
          if (wrap) {
            wrap.querySelectorAll('.luna-thread, .luna-form').forEach(function(el){ el.style.display = 'none'; });
            if (!sessionState._inlineEndedCard) {
              var card = document.createElement('div');
              card.className = 'luna-session-ended-inline';
              card.innerHTML = '<h2>Your session has ended</h2><p>Start another one now.</p><button type="button" class="luna-session-restart">Start New Session</button>';
              wrap.appendChild(card);
              var btn = card.querySelector('.luna-session-restart');
              if (btn) {
                btn.addEventListener('click', function(){ restartSession(); });
              }
              sessionState._inlineEndedCard = card;
            }
          }
        }
      }

      function hideSessionEndedUI() {
        if (typeof window.__lunaHideSessionEnded === 'function') {
          window.__lunaHideSessionEnded();
        }
        if (sessionState._inlineEndedCard) {
          var card = sessionState._inlineEndedCard;
          sessionState._inlineEndedCard = null;
          if (card.parentNode) card.parentNode.removeChild(card);
        }
        document.querySelectorAll('.luna-wrap .luna-thread').forEach(function(el){ el.style.display = ''; });
        document.querySelectorAll('.luna-wrap .luna-form').forEach(function(el){ el.style.display = ''; });
      }

      function markActivity() {
        if (sessionState.closing) return;
        // Only start inactivity timer if user has clicked "Luna Chat" and started a chat session
        if (!sessionState.chatStarted) return;
        cancelTimers();
        var thread = getPrimaryThread();
        if (thread) {
          thread.__inactiveWarned = false;
        }
        sessionState.inactivityTimer = window.setTimeout(handleInactivityWarning, sessionState.inactivityDelay);
      }

      function handleInactivityWarning() {
        sessionState.inactivityTimer = null;
        if (sessionState.closing) return;
        var message = resolveInactivityMessage();
        var thread = getPrimaryThread();
        if (thread && !thread.__inactiveWarned) {
          thread.__inactiveWarned = true;
          appendAssistantMessage(thread, message);
        }
        try {
          fetch(chatInactiveEndpoint, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ message: message })
          }).catch(function(err){
            console.warn('[Luna] inactive log failed', err);
          });
        } catch (err) {
          console.warn('[Luna] inactive fetch error', err);
        }
        if (sessionState.closureTimer) clearTimeout(sessionState.closureTimer);
        sessionState.closureTimer = window.setTimeout(handleSessionClosure, sessionState.closureDelay);
      }

      function handleSessionClosure() {
        sessionState.closureTimer = null;
        if (sessionState.closing) return;
        sessionState.closing = true;
        cancelTimers();
        var message = resolveSessionEndMessage();
        var thread = getPrimaryThread();
        if (thread && !thread.__sessionClosed) {
          thread.__sessionClosed = true;
          appendAssistantMessage(thread, message, 'luna-session-closure');
        }
        setFormsDisabled(true);
        showSessionEndedUI();
        try {
          fetch(chatSessionEndEndpoint, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ reason: 'inactivity', message: message })
          }).catch(function(err){
            console.warn('[Luna] session end failed', err);
          });
        } catch (err) {
          console.warn('[Luna] session end error', err);
        }
      }

      function clearThreads() {
        document.querySelectorAll('.luna-thread').forEach(function(thread){
          thread.innerHTML = '';
          thread.__hydrated = false;
          thread.__inactiveWarned = false;
          thread.__sessionClosed = false;
        });
      }

      function restartSession() {
        if (sessionState.restarting) return;
        sessionState.restarting = true;
        cancelTimers();
        // Reset chatStarted flag when session is restarted
        sessionState.chatStarted = false;
        document.querySelectorAll('.luna-session-restart').forEach(function(btn){
          btn.disabled = true;
        });
        try {
          fetch(chatSessionResetEndpoint, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ reason: 'user_restart' })
          })
          .then(function(){
            sessionState.closing = false;
            setFormsDisabled(false);
            hideSessionEndedUI();
            clearThreads();
            if (typeof window.__lunaHydrateAny === 'function') {
              window.__lunaHydrateAny(true);
            } else {
              hydrateAny(true);
            }
            var panel = document.getElementById('luna-panel');
            if (panel) panel.classList.add('show');
            var fab = document.querySelector('.luna-launcher');
            if (fab) fab.setAttribute('aria-expanded','true');
            var input = document.querySelector('#luna-panel .luna-input') || document.querySelector('.luna-input');
            if (input) input.focus();
          })
          .catch(function(err){
            console.error('[Luna] session reset failed', err);
          })
          .finally(function(){
            sessionState.restarting = false;
            document.querySelectorAll('.luna-session-restart').forEach(function(btn){
              btn.disabled = false;
            });
          });
        } catch (err) {
          console.error('[Luna] session reset error', err);
          sessionState.restarting = false;
          document.querySelectorAll('.luna-session-restart').forEach(function(btn){
            btn.disabled = false;
          });
        }
      }

      function onPanelToggle(open) {
        if (open) {
          if (sessionState.closing) {
            showSessionEndedUI();
            return;
          }
          markActivity();
        }
      }

      async function hydrateAny(forceAll){
        document.querySelectorAll('.luna-thread').forEach(async function(thread){
          if (!forceAll && thread.closest('#luna-panel')) return;
          if (!thread.__hydrated) {
            try{
              const r = await fetch(chatHistoryEndpoint);
              const d = await r.json();
              if (d && Array.isArray(d.items)) {
                d.items.forEach(function(turn){
                  if (turn.user) { var u=document.createElement('div'); u.className='luna-msg luna-user'; u.textContent=turn.user; thread.appendChild(u); }
                  if (turn.assistant) { var a=document.createElement('div'); a.className='luna-msg luna-assistant'; a.textContent=turn.assistant; thread.appendChild(a); }
                });
                thread.scrollTop = thread.scrollHeight;
              }
            }catch(e){ console.warn('[Luna] hydrate failed', e); }
            finally { thread.__hydrated = true; }
          }
        });
      }

      function submitFrom(form){
        try{
          if (sessionState.closing) {
            showSessionEndedUI();
            return;
          }
          var input = form.querySelector('.luna-input'); if(!input) return;
          var text = (input.value||'').trim(); if(!text) return;

          markActivity();

          var thread = form.parentElement.querySelector('.luna-thread') || document.querySelector('.luna-thread');
          if (!thread) { thread = document.createElement('div'); thread.className='luna-thread'; form.parentElement.insertBefore(thread, form); }

          var btn = form.querySelector('.luna-send, button[type="submit"]');
          input.disabled=true; if(btn) btn.disabled=true;

          // Clear input immediately to show message was sent
          input.value='';

          // Add user message to thread
          var u=document.createElement('div'); u.className='luna-msg luna-user'; u.textContent=text; thread.appendChild(u); thread.scrollTop=thread.scrollHeight;

          // Add loading message with gradient animation
          var loadingEl=document.createElement('div'); 
          loadingEl.className='luna-msg luna-loading';
          var loadingSpan=document.createElement('span');
          loadingSpan.className='luna-loading-text';
          loadingSpan.textContent='Luna is considering all possibilities...';
          loadingEl.appendChild(loadingSpan);
          thread.appendChild(loadingEl);
          thread.scrollTop=thread.scrollHeight;

          fetch(chatEndpoint, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ prompt: text })
          })
          .then(function(r){ return r.json().catch(function(){return {};}); })
          .then(function(d){
            // Remove loading message
            if (loadingEl && loadingEl.parentNode) {
              loadingEl.parentNode.removeChild(loadingEl);
            }
            var msg = (d && d.answer) ? d.answer : (d.error ? ('Error: '+d.error) : 'Sorry—no response.');
            appendAssistantMessage(thread, msg);
          })
          .catch(function(err){
            // Remove loading message
            if (loadingEl && loadingEl.parentNode) {
              loadingEl.parentNode.removeChild(loadingEl);
            }
            var e=document.createElement('div'); e.className='luna-msg luna-assistant'; e.textContent='Network error. Please try again.'; thread.appendChild(e);
            console.error('[Luna]', err);
          })
          .finally(function(){ input.disabled=false; if(btn) btn.disabled=false; input.focus(); markActivity(); });
        }catch(e){ console.error('[Luna unexpected]', e); }
      }

      function bind(form){
        if(!form || form.__bound) return; form.__bound = true;
        form.setAttribute('novalidate','novalidate');
        form.addEventListener('submit', function(e){ e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); submitFrom(form); }, true);
        var input=form.querySelector('.luna-input'), btn=form.querySelector('.luna-send');
        if (input) {
          input.addEventListener('keydown', function(e){
            if (sessionState.closing) { e.preventDefault(); showSessionEndedUI(); return; }
            if(e.key==='Enter' && !e.shiftKey && !e.isComposing){
              e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); submitFrom(form);
            }
            markActivity();
          }, true);
          input.addEventListener('focus', markActivity, true);
          input.addEventListener('input', markActivity, true);
        }
        if (btn) { try{btn.type='button';}catch(_){} btn.addEventListener('click', function(e){
          e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); submitFrom(form);
        }, true); }
        form.addEventListener('pointerdown', markActivity, true);
      }

      function scan(){ document.querySelectorAll('.luna-form').forEach(bind); }
      scan(); hydrateAny();
      window.__lunaHydrateAny = hydrateAny;
      sessionState.markActivity = markActivity;
      sessionState.cancelTimers = cancelTimers;
      sessionState.restartSession = restartSession;
      sessionState.showSessionEndedUI = showSessionEndedUI;
      sessionState.hideSessionEndedUI = hideSessionEndedUI;
      sessionState.onPanelToggle = onPanelToggle;
      window.LunaChatSession = sessionState;

      try{ new MutationObserver(function(){ if (scan.__t) cancelAnimationFrame(scan.__t); scan.__t=requestAnimationFrame(function(){ scan(); hydrateAny(); }); }).observe(document.documentElement,{childList:true,subtree:true}); }catch(_){}
      if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', function(){ scan(); hydrateAny(); }, {once:true});
    })();
  </script>
  <?php
});

/* ============================================================
 * OPENAI HELPERS
 * ============================================================ */
function luna_get_openai_key() {
  if (defined('LUNA_OPENAI_API_KEY') && LUNA_OPENAI_API_KEY) return (string)LUNA_OPENAI_API_KEY;
  $k = get_option('luna_openai_api_key', '');
  return is_string($k) ? trim($k) : '';
}

function luna_openai_messages_with_facts($pid, $user_text, $facts, $is_comprehensive_report = false, $is_composer = false) {
  $site_url = isset($facts['site_url']) ? (string)$facts['site_url'] : home_url('/');
  $https    = isset($facts['https']) ? ($facts['https'] ? 'yes' : 'no') : 'unknown';
  $tls      = isset($facts['tls']) && is_array($facts['tls']) ? $facts['tls'] : array();
  $host     = isset($facts['host']) && $facts['host'] !== '' ? (string)$facts['host'] : 'unknown';
  $wpv      = isset($facts['wp_version']) && $facts['wp_version'] !== '' ? (string)$facts['wp_version'] : 'unknown';
  $theme    = isset($facts['theme']) && $facts['theme'] !== '' ? (string)$facts['theme'] : 'unknown';
  $theme_version = isset($facts['theme_version']) && $facts['theme_version'] !== '' ? (string)$facts['theme_version'] : 'unknown';
  $theme_active  = isset($facts['theme_active']) ? ($facts['theme_active'] ? 'yes' : 'no') : 'unknown';
  $counts  = isset($facts['counts']) && is_array($facts['counts']) ? $facts['counts'] : array();
  $updates = isset($facts['updates']) && is_array($facts['updates']) ? $facts['updates'] : array();

  $count_pages  = isset($counts['pages']) ? (int)$counts['pages'] : 0;
  $count_posts  = isset($counts['posts']) ? (int)$counts['posts'] : 0;
  $count_users  = isset($counts['users']) ? (int)$counts['users'] : 0;
  $count_plugins= isset($counts['plugins']) ? (int)$counts['plugins'] : 0;

  $updates_plugins = isset($updates['plugins']) ? (int)$updates['plugins'] : 0;
  $updates_themes  = isset($updates['themes']) ? (int)$updates['themes'] : 0;
  $updates_core    = isset($updates['core']) ? (int)$updates['core'] : 0;

  $facts_text = "FACTS (from Visible Light Hub)\n"
    . "- Site URL: " . $site_url . "\n"
    . "- HTTPS: " . $https . "\n"
    . "- TLS valid: " . (isset($tls['valid']) ? ($tls['valid'] ? 'yes' : 'no') : 'unknown')
    . (!empty($tls['issuer']) ? " (issuer: " . $tls['issuer'] . ")" : '')
    . (!empty($tls['expires']) ? " (expires: " . $tls['expires'] . ")" : '') . "\n"
    . "- Host: " . $host . "\n"
    . "- WordPress: " . $wpv . "\n"
    . "- Theme: " . $theme . " (version: " . $theme_version . ")\n"
    . "- Theme active: " . $theme_active . "\n"
    . "- Counts: pages " . $count_pages . ", posts " . $count_posts . ", users " . $count_users . ", plugins " . $count_plugins . "\n"
    . "- Updates pending: plugins " . $updates_plugins . ", themes " . $updates_themes . ", WordPress Core " . $updates_core . "\n";
    
  // Add comprehensive data if available
  if (isset($facts['comprehensive']) && $facts['comprehensive']) {
    $facts_text .= "\nINSTALLED PLUGINS:\n";
    if (isset($facts['plugins']) && is_array($facts['plugins'])) {
      foreach ($facts['plugins'] as $plugin) {
        $status = !empty($plugin['active']) ? 'active' : 'inactive';
        $update = !empty($plugin['update_available']) ? ' (update available)' : '';
        $facts_text .= "- " . $plugin['name'] . " v" . $plugin['version'] . " (" . $status . ")" . $update . "\n";
      }
    }
    
    $facts_text .= "\nINSTALLED THEMES:\n";
    if (isset($facts['themes']) && is_array($facts['themes'])) {
      foreach ($facts['themes'] as $theme) {
        $status = !empty($theme['is_active']) ? 'active' : 'inactive';
        $update = !empty($theme['update_available']) ? ' (update available)' : '';
        $facts_text .= "- " . $theme['name'] . " v" . $theme['version'] . " (" . $status . ")" . $update . "\n";
      }
    }
    
    $facts_text .= "\nPUBLISHED POSTS:\n";
    if (isset($facts['posts']) && is_array($facts['posts'])) {
      foreach ($facts['posts'] as $post) {
        $facts_text .= "- " . $post['title'] . " (ID: " . $post['id'] . ")\n";
      }
    }
    
    $facts_text .= "\nPAGES:\n";
    if (isset($facts['pages']) && is_array($facts['pages'])) {
      foreach ($facts['pages'] as $page) {
        $status = isset($page['status']) ? $page['status'] : 'published';
        $facts_text .= "- " . $page['title'] . " (" . $status . ", ID: " . $page['id'] . ")\n";
      }
    }
    
    $facts_text .= "\nUSERS:\n";
    if (isset($facts['users']) && is_array($facts['users'])) {
      foreach ($facts['users'] as $user) {
        $facts_text .= "- " . $user['name'] . " (" . $user['username'] . ") - " . $user['email'] . "\n";
      }
    }
  }
  
  // Add SSL/TLS Status Data (from VL Hub SSL/TLS Status connector)
  if (isset($facts['ssl_tls']) && is_array($facts['ssl_tls']) && !empty($facts['ssl_tls'])) {
    $ssl_tls = $facts['ssl_tls'];
    $facts_text .= "\n\nSSL/TLS CERTIFICATE STATUS:\n";
    $certificate = isset($ssl_tls['certificate']) ? $ssl_tls['certificate'] : '';
    $issuer = isset($ssl_tls['issuer']) ? $ssl_tls['issuer'] : '';
    $expires = isset($ssl_tls['expires']) ? $ssl_tls['expires'] : '';
    $days_until_expiry = isset($ssl_tls['days_until_expiry']) ? (int)$ssl_tls['days_until_expiry'] : null;
    $connected = isset($ssl_tls['connected']) ? (bool)$ssl_tls['connected'] : false;
    
    if ($connected && !empty($certificate)) {
      $facts_text .= "  - Certificate: " . $certificate . "\n";
      if (!empty($issuer)) {
        $facts_text .= "  - Issuer: " . $issuer . "\n";
      }
      if (!empty($expires)) {
        $facts_text .= "  - Expires: " . $expires;
        if ($days_until_expiry !== null) {
          $facts_text .= " (" . $days_until_expiry . " days until expiry)";
        }
        $facts_text .= "\n";
      }
      $facts_text .= "  - Status: Connected\n";
    } else {
      $facts_text .= "  - Status: Not configured or not connected\n";
    }
  }
  
  // Add Cloudflare Data (from VL Hub Cloudflare connector)
  if (isset($facts['cloudflare']) && is_array($facts['cloudflare']) && !empty($facts['cloudflare'])) {
    $cloudflare = $facts['cloudflare'];
    $facts_text .= "\n\nCLOUDFLARE CONNECTION:\n";
    $connected = isset($cloudflare['connected']) ? (bool)$cloudflare['connected'] : false;
    $account_id = isset($cloudflare['account_id']) ? $cloudflare['account_id'] : '';
    $zones_count = isset($cloudflare['zones_count']) ? (int)$cloudflare['zones_count'] : 0;
    $last_sync = isset($cloudflare['last_sync']) ? $cloudflare['last_sync'] : '';
    $zones = isset($cloudflare['zones']) && is_array($cloudflare['zones']) ? $cloudflare['zones'] : array();
    
    $facts_text .= "  - Connection Status: " . ($connected ? 'Connected' : 'Not connected') . "\n";
    if ($account_id) {
      $facts_text .= "  - Account ID: " . $account_id . "\n";
    }
    if ($zones_count > 0) {
      $facts_text .= "  - Zones: " . $zones_count . " zone(s)\n";
    }
    if ($last_sync) {
      $facts_text .= "  - Last Sync: " . $last_sync . "\n";
    }
    if (!empty($zones)) {
      $facts_text .= "  - Configured Zones:\n";
      foreach ($zones as $zone) {
        $zone_name = isset($zone['name']) ? $zone['name'] : 'Unknown';
        $zone_status = isset($zone['status']) ? $zone['status'] : 'unknown';
        $zone_plan = isset($zone['plan']) ? $zone['plan'] : 'Free';
        $facts_text .= "    * " . $zone_name . " (" . ucfirst($zone_status) . " - " . $zone_plan . " Plan)\n";
      }
    }
    $facts_text .= "  - Features: DDoS protection, Web Application Firewall (WAF), CDN caching, DNS management\n";
  }
  
  // Add Security Data Streams (from VL Hub Security tab - SSL/TLS Status and Cloudflare)
  if (isset($facts['security_data_streams']) && is_array($facts['security_data_streams']) && !empty($facts['security_data_streams'])) {
    $facts_text .= "\n\nSECURITY DATA STREAMS:\n";
    foreach ($facts['security_data_streams'] as $stream_id => $stream_data) {
      $stream_name = isset($stream_data['name']) ? $stream_data['name'] : $stream_id;
      $stream_status = isset($stream_data['status']) ? $stream_data['status'] : 'unknown';
      $health_score = isset($stream_data['health_score']) ? floatval($stream_data['health_score']) : 0;
      $facts_text .= "  - " . $stream_name . "\n";
      $facts_text .= "    Status: " . ucfirst($stream_status) . "\n";
      $facts_text .= "    Health Score: " . number_format($health_score, 1) . "%\n";
      if (isset($stream_data['cloudflare_zone_name'])) {
        $facts_text .= "    Zone: " . $stream_data['cloudflare_zone_name'] . "\n";
      }
      if (isset($stream_data['cloudflare_plan'])) {
        $facts_text .= "    Plan: " . $stream_data['cloudflare_plan'] . "\n";
      }
      $facts_text .= "\n";
    }
  }
  
  // Add Connections Summary
  if (isset($facts['connections']) && is_array($facts['connections'])) {
    $connections = $facts['connections'];
    $facts_text .= "\n\nACTIVE CONNECTIONS:\n";
    $active_connections = array();
    if (!empty($connections['ssl_tls'])) {
      $active_connections[] = "SSL/TLS Certificate";
    }
    if (!empty($connections['cloudflare'])) {
      $active_connections[] = "Cloudflare";
    }
    if (!empty($connections['aws_s3'])) {
      $active_connections[] = "AWS S3";
    }
    if (!empty($connections['ga4'])) {
      $active_connections[] = "Google Analytics 4";
    }
    if (!empty($connections['liquidweb'])) {
      $active_connections[] = "Liquid Web";
    }
    if (!empty($connections['gsc'])) {
      $active_connections[] = "Google Search Console";
    }
    if (!empty($connections['pagespeed'])) {
      $active_connections[] = "Lighthouse/PageSpeed";
    }
    if (!empty($active_connections)) {
      $facts_text .= "  - Connected Services: " . implode(", ", $active_connections) . "\n";
      $facts_text .= "  - Total Connections: " . count($active_connections) . "\n";
    } else {
      $facts_text .= "  - No active connections configured\n";
    }
  }
  
  $facts_text .= "\n\nRULES FOR LUNA:\n";
  $facts_text .= "1. You are Luna, an intelligent WebOps assistant with access to ALL Visible Light Hub data.\n";
  $facts_text .= "2. ALWAYS use the FACTS provided above when answering questions. Do NOT make up data.\n";
  $facts_text .= "3. CRITICAL: NEVER use emoticons, emojis, unicode symbols, or special characters (like 🌐, 📊, 🔒, 📈, 📝, 🏗️, 🔄, 💡, 📋, ❌, ✅, etc.) in your responses unless the user specifically requests them. Use plain text only.\n";
  $facts_text .= "4. Write in a personable, professional, enterprise-grade tone suitable for leadership. Use full sentences, proper paragraph breaks, and narrative-style explanations rather than bullet points when appropriate.\n";
  $facts_text .= "5. When asked about SSL/TLS, certificates, HTTPS, or encryption, check the SSL/TLS CERTIFICATE STATUS section above.\n";
  $facts_text .= "6. When asked about Cloudflare, CDN, DDoS protection, or WAF, check the CLOUDFLARE CONNECTION section above.\n";
  $facts_text .= "7. When asked about security, check both SSL/TLS and Cloudflare sections, plus SECURITY DATA STREAMS.\n";
  $facts_text .= "8. When asked about competitors, competitive analysis, or domain ranking, check COMPETITOR ANALYSIS and DOMAIN RANKING (VL-DR) DATA sections.\n";
  $facts_text .= "9. When asked about analytics, traffic, or performance, check GOOGLE ANALYTICS 4 (GA4) and PERFORMANCE METRICS sections.\n";
  $facts_text .= "10. When asked about data streams, check DATA STREAMS SUMMARY and ALL DATA STREAMS sections.\n";
  $facts_text .= "11. If data is missing or uncertain, explicitly say so and suggest checking the Visible Light Hub profile.\n";
  $facts_text .= "12. For comprehensive reports, site health reports, or multi-sentence requests:\n";
  $facts_text .= "    - Use full sentences in human-readable, enterprise-grade format\n";
  $facts_text .= "    - Include proper paragraph breaks and formatting\n";
  $facts_text .= "    - Use professional, personable language suitable for leadership\n";
  $facts_text .= "    - Include intro headers, section headers, and official signatures when requested\n";
  $facts_text .= "    - Format data in a way that can be easily copied and pasted into emails\n";
  $facts_text .= "    - Use descriptive, narrative-style explanations rather than bullet points when appropriate\n";
  $facts_text .= "    - For reports, structure with: Title, Introduction, Detailed Findings (by section), Summary, Signature\n";
  $facts_text .= "    - Write in a thoughtful, full-length, professional manner that reads like a human executive report\n";
  $facts_text .= "13. For simple greetings (hi, hello, hey, howdy, hola, etc.), respond instantly with a friendly greeting and brief introduction.\n";
  $facts_text .= "14. Recognize these key terms and map them to the correct sections:\n";
  $facts_text .= "    - SSL/TLS/HTTPS/certificate/cert → SSL/TLS CERTIFICATE STATUS\n";
  $facts_text .= "    - Cloudflare/CDN/DDoS/WAF → CLOUDFLARE CONNECTION\n";
  $facts_text .= "    - Security/security measures → SSL/TLS + Cloudflare + SECURITY DATA STREAMS\n";
  $facts_text .= "    - Competitors/competitive/domain ranking/VLDR → COMPETITOR ANALYSIS + DOMAIN RANKING\n";
  $facts_text .= "    - Analytics/traffic/visitors → GOOGLE ANALYTICS 4 (GA4)\n";
  $facts_text .= "    - Performance/Lighthouse → PERFORMANCE METRICS\n";
  $facts_text .= "    - SEO/search console → SEO METRICS\n";
  $facts_text .= "    - Data streams/connections → DATA STREAMS SUMMARY + ACTIVE CONNECTIONS\n";

  // Get additional data from VL Hub
  $hub_data = luna_get_hub_data();
  if ($hub_data && isset($hub_data['summary'])) {
    $facts_text .= "\n\nHUB INSIGHTS:\n" . $hub_data['summary'];
    if (isset($hub_data['metrics'])) {
      $facts_text .= "\n\nHUB METRICS:\n";
      foreach ($hub_data['metrics'] as $key => $value) {
        $facts_text .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
      }
    }
  }
  
  // Add competitor analysis data
  $all_competitor_reports = array();
  if (isset($facts['competitor_reports']) && is_array($facts['competitor_reports'])) {
    $all_competitor_reports = array_merge($all_competitor_reports, $facts['competitor_reports']);
  }
  if (isset($facts['competitor_reports_full']) && is_array($facts['competitor_reports_full'])) {
    $all_competitor_reports = array_merge($all_competitor_reports, $facts['competitor_reports_full']);
  }
  
  $competitor_urls = array();
  if (isset($facts['competitors']) && is_array($facts['competitors'])) {
    $competitor_urls = $facts['competitors'];
  }
  
  // Extract competitor URLs from reports if available
  if (!empty($all_competitor_reports)) {
    foreach ($all_competitor_reports as $report_data) {
      $comp_url = $report_data['url'] ?? '';
      if (!empty($comp_url) && !in_array($comp_url, $competitor_urls)) {
        $competitor_urls[] = $comp_url;
      }
    }
  }
  
  if (!empty($competitor_urls) || !empty($all_competitor_reports)) {
    $facts_text .= "\n\nCOMPETITOR ANALYSIS:\n";
    if (!empty($competitor_urls)) {
      $facts_text .= "Tracked competitors: " . implode(', ', $competitor_urls) . "\n";
    }
    
    if (!empty($all_competitor_reports)) {
      $facts_text .= "\nCompetitor Analysis Reports (" . count($all_competitor_reports) . "):\n";
      foreach ($all_competitor_reports as $report_data) {
        $comp_url = $report_data['url'] ?? '';
        $comp_domain = $report_data['domain'] ?? parse_url($comp_url, PHP_URL_HOST);
        $last_scanned = $report_data['last_scanned'] ?? null;
        $report = $report_data['report'] ?? array();
        
        // Handle both direct report data and nested report_json structure
        $report_data_inner = $report;
        if (isset($report['report_json']) && is_array($report['report_json'])) {
          $report_data_inner = $report['report_json'];
        }
        
        if ($comp_domain) {
          $facts_text .= "\nCompetitor: " . $comp_domain . "\n";
          if ($last_scanned) {
            $facts_text .= "  - Last Scanned: " . $last_scanned . "\n";
          }
          
          // Public Pages
          if (!empty($report_data_inner['public_pages'])) {
            $facts_text .= "  - Public Pages Count: " . $report_data_inner['public_pages'] . "\n";
          }
          
          // Blog Status
          if (!empty($report_data_inner['blog']) && is_array($report_data_inner['blog'])) {
            $blog_status = isset($report_data_inner['blog']['status']) ? $report_data_inner['blog']['status'] : 'Unknown';
            $facts_text .= "  - Blog Status: " . ucfirst($blog_status) . "\n";
          }
          
          // Lighthouse Score
          if (!empty($report_data_inner['lighthouse_score'])) {
            $facts_text .= "  - Lighthouse Score: " . $report_data_inner['lighthouse_score'] . "\n";
          } elseif (isset($report_data_inner['lighthouse']) && is_array($report_data_inner['lighthouse'])) {
            $lh = $report_data_inner['lighthouse'];
            if (!empty($lh['performance'])) {
              $facts_text .= "  - Lighthouse Performance: " . $lh['performance'] . "\n";
            }
            if (!empty($lh['accessibility'])) {
              $facts_text .= "  - Lighthouse Accessibility: " . $lh['accessibility'] . "\n";
            }
            if (!empty($lh['seo'])) {
              $facts_text .= "  - Lighthouse SEO: " . $lh['seo'] . "\n";
            }
            if (!empty($lh['best_practices'])) {
              $facts_text .= "  - Lighthouse Best Practices: " . $lh['best_practices'] . "\n";
            }
          }
          
          // Domain Ranking (VLDR)
          if (isset($facts['vldr'][$comp_domain])) {
            $vldr = $facts['vldr'][$comp_domain];
            if (!empty($vldr['vldr_score'])) {
              $facts_text .= "  - Domain Ranking (VL-DR): " . number_format($vldr['vldr_score'], 2) . "/100\n";
            }
          }
          
          // Meta Description
          if (!empty($report_data_inner['meta_description'])) {
            $facts_text .= "  - Meta Description: " . substr($report_data_inner['meta_description'], 0, 150) . "...\n";
          }
          
          // Keywords
          if (!empty($report_data_inner['keywords']) && is_array($report_data_inner['keywords'])) {
            $top_keywords = array_slice($report_data_inner['keywords'], 0, 10);
            $facts_text .= "  - Top Keywords: " . implode(', ', $top_keywords) . "\n";
          }
          
          // Keyphrases
          if (!empty($report_data_inner['keyphrases']) && is_array($report_data_inner['keyphrases'])) {
            $top_keyphrases = array_slice($report_data_inner['keyphrases'], 0, 10);
            $facts_text .= "  - Top Keyphrases: " . implode(', ', $top_keyphrases) . "\n";
          }
          
          // Page Title
          if (!empty($report_data_inner['title'])) {
            $facts_text .= "  - Page Title: " . $report_data_inner['title'] . "\n";
          }
        }
      }
    }
  }
  
  // Add VLDR (Domain Ranking) data
  if (isset($facts['vldr']) && is_array($facts['vldr']) && !empty($facts['vldr'])) {
    $facts_text .= "\n\nDOMAIN RANKING (VL-DR) DATA:\n";
    foreach ($facts['vldr'] as $domain => $vldr_data) {
      $is_client = isset($vldr_data['is_client']) && $vldr_data['is_client'];
      $label = $is_client ? "Client Domain" : "Competitor";
      $facts_text .= $label . ": " . $domain . "\n";
      if (isset($vldr_data['vldr_score'])) {
        $facts_text .= "  - VL-DR Score: " . number_format($vldr_data['vldr_score'], 1) . " (0-100)\n";
      }
      if (isset($vldr_data['ref_domains'])) {
        $facts_text .= "  - Referring Domains: ~" . number_format($vldr_data['ref_domains'] / 1000, 1) . "k\n";
      }
      if (isset($vldr_data['indexed_pages'])) {
        $facts_text .= "  - Indexed Pages: ~" . number_format($vldr_data['indexed_pages'] / 1000, 1) . "k\n";
      }
      if (isset($vldr_data['lighthouse_avg'])) {
        $facts_text .= "  - Lighthouse Average: " . $vldr_data['lighthouse_avg'] . "\n";
      }
      if (isset($vldr_data['security_grade'])) {
        $facts_text .= "  - Security Grade: " . $vldr_data['security_grade'] . "\n";
      }
      if (isset($vldr_data['domain_age_years'])) {
        $facts_text .= "  - Domain Age: " . number_format($vldr_data['domain_age_years'], 1) . " years\n";
      }
      if (isset($vldr_data['uptime_percent'])) {
        $facts_text .= "  - Uptime: " . number_format($vldr_data['uptime_percent'], 2) . "%\n";
      }
      if (isset($vldr_data['metric_date'])) {
        $facts_text .= "  - Last Updated: " . $vldr_data['metric_date'] . "\n";
      }
      $facts_text .= "\n";
    }
    $facts_text .= "Note: VL-DR (Visible Light Domain Ranking) is computed from public indicators: Common Crawl/Index, Bing Web Search, SecurityHeaders.com, WHOIS, Visible Light Uptime monitoring, and Lighthouse performance scores.\n";
  }
  
  // Add performance metrics
  if (isset($facts['performance']) && is_array($facts['performance'])) {
    $facts_text .= "\n\nPERFORMANCE METRICS:\n";
    if (isset($facts['performance']['lighthouse']) && is_array($facts['performance']['lighthouse'])) {
      $lh = $facts['performance']['lighthouse'];
      $facts_text .= "Lighthouse Scores:\n";
      $facts_text .= "  - Performance: " . ($lh['performance'] ?? 'N/A') . "\n";
      $facts_text .= "  - Accessibility: " . ($lh['accessibility'] ?? 'N/A') . "\n";
      $facts_text .= "  - SEO: " . ($lh['seo'] ?? 'N/A') . "\n";
      $facts_text .= "  - Best Practices: " . ($lh['best_practices'] ?? 'N/A') . "\n";
      if (!empty($lh['last_updated'])) {
        $facts_text .= "  - Last Updated: " . $lh['last_updated'] . "\n";
      }
    }
  }
  
  // Add SEO data
  if (isset($facts['seo']) && is_array($facts['seo'])) {
    $facts_text .= "\n\nSEO METRICS:\n";
    $seo = $facts['seo'];
    $facts_text .= "  - Total Clicks: " . ($seo['total_clicks'] ?? 0) . "\n";
    $facts_text .= "  - Total Impressions: " . ($seo['total_impressions'] ?? 0) . "\n";
    $facts_text .= "  - Average CTR: " . number_format(($seo['avg_ctr'] ?? 0) * 100, 2) . "%\n";
    $facts_text .= "  - Average Position: " . number_format($seo['avg_position'] ?? 0, 1) . "\n";
    if (!empty($seo['top_queries']) && is_array($seo['top_queries'])) {
      $facts_text .= "  - Top Search Queries:\n";
      foreach (array_slice($seo['top_queries'], 0, 5) as $query) {
        $facts_text .= "    * " . ($query['query'] ?? '') . " - " . ($query['clicks'] ?? 0) . " clicks, " . number_format(($query['ctr'] ?? 0), 2) . "% CTR\n";
      }
    }
  }
  
  // Add data stream summary
  if (isset($facts['data_streams_summary']) && is_array($facts['data_streams_summary'])) {
    $streams_summary = $facts['data_streams_summary'];
    $facts_text .= "\n\nDATA STREAMS SUMMARY:\n";
    $facts_text .= "  - Total Streams: " . ($streams_summary['total'] ?? 0) . "\n";
    $facts_text .= "  - Active Streams: " . ($streams_summary['active'] ?? 0) . "\n";
    if (!empty($streams_summary['by_category']) && is_array($streams_summary['by_category'])) {
      $facts_text .= "  - Streams by Category:\n";
      foreach ($streams_summary['by_category'] as $category => $count) {
        $facts_text .= "    * " . ucfirst($category) . ": " . $count . "\n";
      }
    }
    if (!empty($streams_summary['recent']) && is_array($streams_summary['recent'])) {
      $facts_text .= "  - Recent Streams:\n";
      foreach ($streams_summary['recent'] as $stream) {
        $facts_text .= "    * " . ($stream['name'] ?? '') . " (" . ($stream['category'] ?? '') . ") - " . ($stream['last_updated'] ?? '') . "\n";
      }
    }
  }
  
  // Add ALL Data Streams (full data)
  if (isset($facts['data_streams']) && is_array($facts['data_streams']) && !empty($facts['data_streams'])) {
    $facts_text .= "\n\nALL DATA STREAMS (Full Details):\n";
    foreach ($facts['data_streams'] as $stream_id => $stream) {
      if (!is_array($stream)) continue;
      $facts_text .= "Stream: " . ($stream['name'] ?? $stream_id) . "\n";
      $facts_text .= "  - ID: " . $stream_id . "\n";
      $facts_text .= "  - Status: " . ($stream['status'] ?? 'unknown') . "\n";
      $facts_text .= "  - Category: " . (isset($stream['categories']) && is_array($stream['categories']) ? implode(', ', $stream['categories']) : 'N/A') . "\n";
      $facts_text .= "  - Health Score: " . ($stream['health_score'] ?? 'N/A') . "\n";
      $facts_text .= "  - Last Updated: " . ($stream['last_updated'] ?? 'N/A') . "\n";
      if (!empty($stream['description'])) {
        $facts_text .= "  - Description: " . substr($stream['description'], 0, 200) . "\n";
      }
      $facts_text .= "\n";
    }
  }
  
  // Add AWS S3 Data
  if (isset($facts['aws_s3']) && is_array($facts['aws_s3'])) {
    $facts_text .= "\n\nAWS S3 CLOUD STORAGE:\n";
    $aws_s3 = $facts['aws_s3'];
    if (!empty($aws_s3['settings'])) {
      $settings = $aws_s3['settings'];
      $facts_text .= "  - Bucket Count: " . ($settings['bucket_count'] ?? 0) . "\n";
      $facts_text .= "  - Object Count: " . ($settings['object_count'] ?? 0) . "\n";
      $facts_text .= "  - Storage Used: " . ($settings['storage_used'] ?? 'N/A') . "\n";
      $facts_text .= "  - Last Sync: " . ($settings['last_sync'] ?? 'Never') . "\n";
    }
    if (!empty($aws_s3['buckets']) && is_array($aws_s3['buckets'])) {
      $facts_text .= "  - Buckets:\n";
      foreach (array_slice($aws_s3['buckets'], 0, 10) as $bucket) {
        $facts_text .= "    * " . ($bucket['name'] ?? 'Unknown') . " - " . ($bucket['object_count'] ?? 0) . " objects, " . ($bucket['size'] ?? '0 B') . "\n";
      }
      if (count($aws_s3['buckets']) > 10) {
        $facts_text .= "    ... and " . (count($aws_s3['buckets']) - 10) . " more buckets\n";
      }
    }
  }
  
  // Add Liquid Web Assets
  if (isset($facts['liquidweb']) && is_array($facts['liquidweb'])) {
    $facts_text .= "\n\nLIQUID WEB HOSTING:\n";
    $liquidweb = $facts['liquidweb'];
    if (!empty($liquidweb['settings'])) {
      $settings = $liquidweb['settings'];
      $facts_text .= "  - Connected: " . (!empty($settings['api_key']) && !empty($settings['account_number']) ? 'Yes' : 'No') . "\n";
      $facts_text .= "  - Account Number: " . ($settings['account_number'] ?? 'N/A') . "\n";
      $facts_text .= "  - Last Sync: " . ($settings['last_sync'] ?? 'Never') . "\n";
    }
    if (!empty($liquidweb['assets']) && is_array($liquidweb['assets'])) {
      $facts_text .= "  - Asset Count: " . count($liquidweb['assets']) . "\n";
      foreach (array_slice($liquidweb['assets'], 0, 5) as $asset) {
        $facts_text .= "    * " . ($asset['name'] ?? 'Unknown') . " - " . ($asset['type'] ?? 'N/A') . "\n";
      }
      if (count($liquidweb['assets']) > 5) {
        $facts_text .= "    ... and " . (count($liquidweb['assets']) - 5) . " more assets\n";
      }
    }
  }
  
  // Add GA4 Metrics (comprehensive - ALL metrics synced to VL Hub)
  if (isset($facts['ga4_metrics']) && is_array($facts['ga4_metrics']) && !empty($facts['ga4_metrics'])) {
    $facts_text .= "\n\nGOOGLE ANALYTICS 4 (GA4) METRICS:\n";
    $ga4 = $facts['ga4_metrics'];
    $facts_text .= "  - Property ID: " . ($facts['ga4_property_id'] ?? ($ga4['property_id'] ?? 'N/A')) . "\n";
    $facts_text .= "  - Measurement ID: " . ($facts['ga4_measurement_id'] ?? ($ga4['measurement_id'] ?? 'N/A')) . "\n";
    $facts_text .= "  - Last Synced: " . ($facts['ga4_last_synced'] ?? ($ga4['last_synced'] ?? 'Never')) . "\n";
    $facts_text .= "  - Date Range: " . ($facts['ga4_date_range'] ?? ($ga4['date_range'] ?? 'N/A')) . "\n";
    $facts_text .= "  - User Metrics:\n";
    $facts_text .= "    * Total Users: " . number_format(isset($ga4['total_users']) ? (int)$ga4['total_users'] : (isset($ga4['users']) ? (int)$ga4['users'] : 0)) . "\n";
    $facts_text .= "    * New Users: " . number_format(isset($ga4['new_users']) ? (int)$ga4['new_users'] : 0) . "\n";
    $facts_text .= "    * Active Users: " . number_format(isset($ga4['active_users']) ? (int)$ga4['active_users'] : 0) . "\n";
    $facts_text .= "  - Session Metrics:\n";
    $facts_text .= "    * Sessions: " . number_format(isset($ga4['sessions']) ? (int)$ga4['sessions'] : 0) . "\n";
    $facts_text .= "    * Page Views: " . number_format(isset($ga4['page_views']) ? (int)$ga4['page_views'] : 0) . "\n";
    $facts_text .= "    * Bounce Rate: " . number_format(isset($ga4['bounce_rate']) ? (float)$ga4['bounce_rate'] * 100 : 0, 2) . "%\n";
    $facts_text .= "    * Avg Session Duration: " . number_format(isset($ga4['avg_session_duration']) ? (float)$ga4['avg_session_duration'] : 0, 0) . " seconds\n";
    $facts_text .= "  - Engagement Metrics:\n";
    $facts_text .= "    * Engagement Rate: " . number_format(isset($ga4['engagement_rate']) ? (float)$ga4['engagement_rate'] * 100 : 0, 2) . "%\n";
    $facts_text .= "    * Engaged Sessions: " . number_format(isset($ga4['engaged_sessions']) ? (int)$ga4['engaged_sessions'] : 0) . "\n";
    $facts_text .= "    * User Engagement Duration: " . number_format(isset($ga4['user_engagement_duration']) ? (float)$ga4['user_engagement_duration'] : 0, 0) . " seconds\n";
    $facts_text .= "  - Event Metrics:\n";
    $facts_text .= "    * Event Count: " . number_format(isset($ga4['event_count']) ? (int)$ga4['event_count'] : 0) . "\n";
    $facts_text .= "  - Conversion Metrics:\n";
    $facts_text .= "    * Conversions: " . number_format(isset($ga4['conversions']) ? (int)$ga4['conversions'] : 0) . "\n";
    $facts_text .= "    * Total Revenue: $" . number_format(isset($ga4['total_revenue']) ? (float)$ga4['total_revenue'] : 0, 2) . "\n";
    $facts_text .= "    * Purchase Revenue: $" . number_format(isset($ga4['purchase_revenue']) ? (float)$ga4['purchase_revenue'] : 0, 2) . "\n";
    $facts_text .= "    * Average Purchase Revenue: $" . number_format(isset($ga4['average_purchase_revenue']) ? (float)$ga4['average_purchase_revenue'] : 0, 2) . "\n";
    $facts_text .= "    * Transactions: " . number_format(isset($ga4['transactions']) ? (int)$ga4['transactions'] : 0) . "\n";
    $facts_text .= "    * Session Conversion Rate: " . number_format(isset($ga4['session_conversion_rate']) ? (float)$ga4['session_conversion_rate'] * 100 : 0, 2) . "%\n";
    $facts_text .= "    * Total Purchasers: " . number_format(isset($ga4['total_purchasers']) ? (int)$ga4['total_purchasers'] : 0) . "\n";
    // Include any additional GA4 metrics
    foreach ($ga4 as $metric_name => $metric_value) {
      if (!in_array($metric_name, array('property_id', 'measurement_id', 'last_synced', 'date_range', 'total_users', 'users', 'new_users', 'active_users', 'sessions', 'page_views', 'bounce_rate', 'avg_session_duration', 'engagement_rate', 'engaged_sessions', 'user_engagement_duration', 'event_count', 'conversions', 'total_revenue', 'purchase_revenue', 'average_purchase_revenue', 'transactions', 'session_conversion_rate', 'total_purchasers'))) {
      if (is_numeric($metric_value)) {
        $facts_text .= "    * " . ucwords(str_replace('_', ' ', $metric_name)) . ": " . number_format($metric_value) . "\n";
      } elseif (is_array($metric_value)) {
        $facts_text .= "    * " . ucwords(str_replace('_', ' ', $metric_name)) . ": " . json_encode($metric_value) . "\n";
      } else {
        $facts_text .= "    * " . ucwords(str_replace('_', ' ', $metric_name)) . ": " . $metric_value . "\n";
        }
      }
    }
  }
  
  // Add Lighthouse Insights Data (from VL Hub)
  if (isset($facts['lighthouse_insights']) && is_array($facts['lighthouse_insights']) && !empty($facts['lighthouse_insights'])) {
    $lighthouse = $facts['lighthouse_insights'];
    $facts_text .= "\n\nLIGHTHOUSE INSIGHTS REPORT:\n";
    $facts_text .= "  - Report Name: " . ($lighthouse['name'] ?? 'Lighthouse Insights Report') . "\n";
    $facts_text .= "  - URL: " . ($lighthouse['url'] ?? ($lighthouse['pagespeed_url'] ?? ($lighthouse['source_url'] ?? 'N/A'))) . "\n";
    $facts_text .= "  - Health Score: " . number_format(isset($lighthouse['health_score']) ? (float)$lighthouse['health_score'] : 0, 0) . "/100\n";
    $facts_text .= "  - Error Count: " . number_format(isset($lighthouse['error_count']) ? (int)$lighthouse['error_count'] : 0) . "\n";
    $facts_text .= "  - Warning Count: " . number_format(isset($lighthouse['warning_count']) ? (int)$lighthouse['warning_count'] : 0) . "\n";
    $facts_text .= "  - Status: " . ($lighthouse['status'] ?? 'N/A') . "\n";
    $facts_text .= "  - Last Updated: " . ($lighthouse['last_updated'] ?? ($lighthouse['created'] ?? 'N/A')) . "\n";
    if (isset($lighthouse['report_data']) && is_array($lighthouse['report_data'])) {
      $report_data = $lighthouse['report_data'];
      $facts_text .= "  - Report Data:\n";
      $facts_text .= "    * Performance Score: " . number_format(isset($report_data['performance_score']) ? (float)$report_data['performance_score'] : 0, 0) . "/100\n";
      $facts_text .= "    * Accessibility Score: " . number_format(isset($report_data['accessibility_score']) ? (float)$report_data['accessibility_score'] : 0, 0) . "/100\n";
      $facts_text .= "    * Best Practices Score: " . number_format(isset($report_data['best_practices_score']) ? (float)$report_data['best_practices_score'] : 0, 0) . "/100\n";
      $facts_text .= "    * SEO Score: " . number_format(isset($report_data['seo_score']) ? (float)$report_data['seo_score'] : 0, 0) . "/100\n";
      $facts_text .= "    * Overall Score: " . number_format(isset($report_data['overall_score']) ? (float)$report_data['overall_score'] : 0, 0) . "/100\n";
      $facts_text .= "    * Strategy: " . ($report_data['strategy'] ?? 'N/A') . "\n";
    }
    if (isset($lighthouse['categories']) && is_array($lighthouse['categories'])) {
      $facts_text .= "  - Categories: " . implode(', ', $lighthouse['categories']) . "\n";
    }
  }
  
  // Add Google Ads Data (when available)
  if (isset($facts['google_ads']) && is_array($facts['google_ads']) && !empty($facts['google_ads'])) {
    $google_ads = $facts['google_ads'];
    $facts_text .= "\n\nGOOGLE ADS:\n";
    $facts_text .= "  - Connection Status: " . (isset($google_ads['connected']) && $google_ads['connected'] ? 'Connected' : 'Not connected') . "\n";
    if (isset($google_ads['account_id'])) {
      $facts_text .= "  - Account ID: " . $google_ads['account_id'] . "\n";
    }
    if (isset($google_ads['campaigns_count'])) {
      $facts_text .= "  - Campaigns: " . number_format((int)$google_ads['campaigns_count']) . "\n";
    }
    if (isset($google_ads['last_sync'])) {
      $facts_text .= "  - Last Sync: " . $google_ads['last_sync'] . "\n";
    }
    if (isset($google_ads['metrics']) && is_array($google_ads['metrics'])) {
      $facts_text .= "  - Metrics:\n";
      foreach ($google_ads['metrics'] as $metric_name => $metric_value) {
        if (is_numeric($metric_value)) {
          $facts_text .= "    * " . ucwords(str_replace('_', ' ', $metric_name)) . ": " . number_format($metric_value) . "\n";
        } else {
          $facts_text .= "    * " . ucwords(str_replace('_', ' ', $metric_name)) . ": " . $metric_value . "\n";
        }
      }
    }
  }
  
  // Add LinkedIn Ads Data (when available)
  if (isset($facts['linkedin_ads']) && is_array($facts['linkedin_ads']) && !empty($facts['linkedin_ads'])) {
    $linkedin_ads = $facts['linkedin_ads'];
    $facts_text .= "\n\nLINKEDIN ADS:\n";
    $facts_text .= "  - Connection Status: " . (isset($linkedin_ads['connected']) && $linkedin_ads['connected'] ? 'Connected' : 'Not connected') . "\n";
    if (isset($linkedin_ads['account_id'])) {
      $facts_text .= "  - Account ID: " . $linkedin_ads['account_id'] . "\n";
    }
    if (isset($linkedin_ads['campaigns_count'])) {
      $facts_text .= "  - Campaigns: " . number_format((int)$linkedin_ads['campaigns_count']) . "\n";
    }
    if (isset($linkedin_ads['last_sync'])) {
      $facts_text .= "  - Last Sync: " . $linkedin_ads['last_sync'] . "\n";
    }
    if (isset($linkedin_ads['metrics']) && is_array($linkedin_ads['metrics'])) {
      $facts_text .= "  - Metrics:\n";
      foreach ($linkedin_ads['metrics'] as $metric_name => $metric_value) {
        if (is_numeric($metric_value)) {
          $facts_text .= "    * " . ucwords(str_replace('_', ' ', $metric_name)) . ": " . number_format($metric_value) . "\n";
        } else {
          $facts_text .= "    * " . ucwords(str_replace('_', ' ', $metric_name)) . ": " . $metric_value . "\n";
        }
      }
    }
  }
  
  // Add Meta Ads Data (when available)
  if (isset($facts['meta_ads']) && is_array($facts['meta_ads']) && !empty($facts['meta_ads'])) {
    $meta_ads = $facts['meta_ads'];
    $facts_text .= "\n\nMETA ADS:\n";
    $facts_text .= "  - Connection Status: " . (isset($meta_ads['connected']) && $meta_ads['connected'] ? 'Connected' : 'Not connected') . "\n";
    if (isset($meta_ads['account_id'])) {
      $facts_text .= "  - Account ID: " . $meta_ads['account_id'] . "\n";
    }
    if (isset($meta_ads['campaigns_count'])) {
      $facts_text .= "  - Campaigns: " . number_format((int)$meta_ads['campaigns_count']) . "\n";
    }
    if (isset($meta_ads['last_sync'])) {
      $facts_text .= "  - Last Sync: " . $meta_ads['last_sync'] . "\n";
    }
    if (isset($meta_ads['metrics']) && is_array($meta_ads['metrics'])) {
      $facts_text .= "  - Metrics:\n";
      foreach ($meta_ads['metrics'] as $metric_name => $metric_value) {
        if (is_numeric($metric_value)) {
          $facts_text .= "    * " . ucwords(str_replace('_', ' ', $metric_name)) . ": " . number_format($metric_value) . "\n";
        } else {
          $facts_text .= "    * " . ucwords(str_replace('_', ' ', $metric_name)) . ": " . $metric_value . "\n";
        }
      }
    }
  }

  // Check if this is a Luna Composer request
  $is_composer_request = $is_composer;
  
  $system_message = "You are Luna, an intelligent WebOps assistant with comprehensive access to ALL Visible Light Hub data. You have real-time visibility into SSL/TLS certificates, Cloudflare connections, AWS S3 storage, Google Analytics 4 (GA4) with ALL metrics (Total Users, New Users, Active Users, Sessions, Page Views, Bounce Rate, Avg Session Duration, Engagement Rate, Engaged Sessions, User Engagement Duration, Event Count, Conversions, Total Revenue, Purchase Revenue, Average Purchase Revenue, Transactions, Session Conversion Rate, Total Purchasers), Liquid Web hosting, Lighthouse Insights (Performance, Accessibility, Best Practices, SEO scores), Google Ads, LinkedIn Ads, Meta Ads, competitor analysis, domain rankings (VLDR), performance metrics, SEO data, and all data streams. Use the FACTS provided below to answer questions accurately and comprehensively. When asked about ANY VL Hub data, check the corresponding section in FACTS and provide detailed, accurate answers using that data.\n\n";
  $system_message .= "CRITICAL RULES:\n";
  $system_message .= "1. NEVER use emoticons, emojis, unicode symbols, or special characters (like 🌐, 📊, 🔒, 📈, 📝, 🏗️, 🔄, 💡, 📋, ❌, ✅, etc.) in your responses unless the user specifically requests them. Use plain text only.\n";
  $system_message .= "2. Write in a personable, professional, enterprise-grade tone suitable for leadership. Use full sentences, proper paragraph breaks, and narrative-style explanations rather than bullet points when appropriate.\n";
  
  if ($is_composer_request || $is_comprehensive_report) {
    if ($is_composer_request) {
      $system_message .= "3. You are Luna Composer, generating long-form, thoughtful, and hyper-personal data-driven content. The user is creating editable long-form content, so your response must be comprehensive, well-structured, and suitable for professional editing. Write in a thoughtful, engaging, and personable manner that feels human and authentic, not robotic. Use full sentences, proper paragraph breaks, and narrative-style explanations. CRITICAL: You MUST use the actual VL Hub data provided in the FACTS section below. Reference specific metrics, trends, and insights from the user's actual data (GA4 metrics, Lighthouse scores, competitor analysis, domain rankings, etc.). Weave this real data naturally into a cohesive narrative. Make the content feel personalized and tailored to the user's specific needs and their actual digital presence. Analyze the data, highlight trends, call out risks or opportunities, and provide strategic recommendations or next steps so the user receives meaningful guidance rather than just raw metrics. When data is missing or limited, acknowledge the gap and recommend how to close it. Generate a substantial, detailed response (minimum 300-500 words for simple queries, 800-1200+ words for complex queries) that provides real value and can be edited into polished content. NEVER use emoticons, emojis, unicode symbols, or special characters. Use plain text only.\n";
    } else {
      $system_message .= "3. You are generating a comprehensive, enterprise-grade, leadership-focused report. Write in a thoughtful, full-length, professional manner that reads like a human executive report. Use descriptive, narrative-style explanations with proper paragraph breaks. Include an intro header, detailed findings from ALL data sources (GA4 metrics, Lighthouse Insights, ad platform data, competitor analysis, performance metrics, etc.), and an official signature. Format with proper paragraph breaks and section headers. NEVER use emoticons, emojis, unicode symbols, or special characters. Use plain text only. Ensure the report is comprehensive and includes ALL available data from VL Hub.\n";
    }
  } else {
    $system_message .= "3. Always provide thoughtful, full-length responses that are personable and professional, not just facts-driven lists. When asked about analytics, metrics, or performance, include ALL relevant data from GA4, Lighthouse Insights, and other connected services.\n";
  }
  $system_message .= "4. For comprehensive reports, site health reports, or multi-sentence requests, write in a thoughtful, full-length, professional manner that reads like a human executive report. Use descriptive, narrative-style explanations with proper paragraph breaks. Include ALL available data from VL Hub, including GA4 metrics, Lighthouse Insights, ad platform data (Google Ads, LinkedIn Ads, Meta Ads when available), competitor analysis, performance metrics, and SEO data.\n";
  $system_message .= "5. If data is incomplete or missing from VL Hub for any category (GA4, Lighthouse, ad platforms, etc.), still generate a thoughtful, comprehensive response. Explicitly mention what data is missing and provide suggestions on what Visible Light needs to work efficiently in that category. For example, if Google Ads data is not available, mention that Google Ads integration is not currently connected and suggest connecting it in VL Hub for comprehensive ad performance analysis.\n";

  $messages = array(
    array('role'=>'system','content'=>$system_message),
    array('role'=>'system','content'=>$facts_text),
  );
  $t = get_post_meta($pid, 'transcript', true);
  if (!is_array($t)) $t = array();
  $slice = array_slice($t, max(0, count($t)-8));
  foreach ($slice as $row) {
    $u = trim(isset($row['user']) ? (string)$row['user'] : '');
    $a = trim(isset($row['assistant']) ? (string)$row['assistant'] : '');
    if ($u !== '') $messages[] = array('role'=>'user','content'=>$u);
    if ($a !== '') $messages[] = array('role'=>'assistant','content'=>$a);
  }
  if ($user_text !== '') $messages[] = array('role'=>'user','content'=>$user_text);
  return $messages;
}

function luna_generate_openai_answer($pid, $prompt, $facts, $is_comprehensive_report = false) {
  $api_key = luna_get_openai_key();
  if ($api_key === '') {
    return null;
  }

  $model    = apply_filters('luna_openai_model', 'gpt-4o');
  $is_composer_flag = isset($facts['__composer']) ? $facts['__composer'] : false;
  $messages = luna_openai_messages_with_facts($pid, $prompt, $facts, $is_comprehensive_report, $is_composer_flag);
  $payload  = array(
    'model'       => $model,
    'messages'    => $messages,
    'temperature' => 0.7,
    'max_tokens'  => ($is_comprehensive_report || $is_composer_flag) ? 4000 : 2000, // Increase tokens for comprehensive reports and composer
  );

  $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
    'timeout' => 30,
    'headers' => array(
      'Content-Type'  => 'application/json',
      'Authorization' => 'Bearer ' . $api_key,
    ),
    'body'    => wp_json_encode($payload),
  ));

  if (is_wp_error($response)) {
    error_log('[Luna Widget] OpenAI request failed: ' . $response->get_error_message());
    return null;
  }

  $status   = (int) wp_remote_retrieve_response_code($response);
  $raw_body = wp_remote_retrieve_body($response);
  if ($status >= 400) {
    error_log('[Luna Widget] OpenAI HTTP ' . $status . ': ' . substr($raw_body, 0, 500));
    return null;
  }

  $decoded = json_decode($raw_body, true);
  if (!is_array($decoded)) {
    error_log('[Luna Widget] OpenAI returned invalid JSON.');
    return null;
  }

  $content = '';
  if (!empty($decoded['choices'][0]['message']['content'])) {
    $content = (string) $decoded['choices'][0]['message']['content'];
  } elseif (!empty($decoded['choices'][0]['text'])) {
    $content = (string) $decoded['choices'][0]['text'];
  }

  $content = trim($content);
  if ($content === '') {
    return null;
  }

  $result = array(
    'answer' => $content,
    'model'  => $model,
  );

  if (!empty($decoded['usage']) && is_array($decoded['usage'])) {
    $result['usage'] = $decoded['usage'];
  }

  return $result;
}

/* ============================================================
 * REST: Chat + History + Hub-facing lists + Utilities
 * ============================================================ */

if (!function_exists('luna_request_value_signals_composer')) {
  function luna_request_value_signals_composer($value) {
    if (is_bool($value)) {
      return $value === true;
    }

    if (is_string($value)) {
      $normalized = strtolower(trim($value));
      if ($normalized === '') {
        return false;
      }

      $normalized = str_replace(array('\\', '/', '-'), ' ', $normalized);

      if (
        strpos($normalized, 'composer') !== false ||
        strpos($normalized, 'luna compose') !== false ||
        strpos($normalized, 'luna composer') !== false
      ) {
        return true;
      }
    }

    if (is_array($value)) {
      foreach ($value as $item) {
        if (luna_request_value_signals_composer($item)) {
          return true;
        }
      }
    }

    return false;
  }
}

if (!function_exists('luna_request_has_composer_signal')) {
  function luna_request_has_composer_signal(WP_REST_Request $req) {
    $params = $req->get_params();
    foreach ($params as $key => $value) {
      if ($key === 'prompt' || $key === 'message') {
        continue;
      }

      if (is_string($key) && luna_request_value_signals_composer($key)) {
        return true;
      }

      if (luna_request_value_signals_composer($value)) {
        return true;
      }
    }

    if (!empty($_SERVER['HTTP_X_LUNA_COMPOSER'])) {
      if (luna_request_value_signals_composer($_SERVER['HTTP_X_LUNA_COMPOSER'])) {
        return true;
      }
    }

    if (!empty($_SERVER['HTTP_REFERER'])) {
      if (luna_request_value_signals_composer($_SERVER['HTTP_REFERER'])) {
        return true;
      }
    }

    return false;
  }
}

function luna_widget_chat_handler( WP_REST_Request $req ) {
  // Add CORS headers for cross-origin requests
  if (!headers_sent()) {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    $allowed_origins = array(
      'https://supercluster.visiblelight.ai',
      'https://visiblelight.ai',
      'http://supercluster.visiblelight.ai',
      'http://visiblelight.ai'
    );
    
    $is_allowed_origin = false;
    if (!empty($origin)) {
      foreach ($allowed_origins as $allowed) {
        if ($origin === $allowed || strpos($origin, $allowed) !== false) {
          $is_allowed_origin = true;
          break;
        }
      }
    }
    
    if ($is_allowed_origin && !empty($origin)) {
      header('Access-Control-Allow-Origin: ' . $origin);
    } else {
      header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-WP-Nonce, x-wp-nonce');
    header('Access-Control-Allow-Credentials: true');
  }
  
  // Handle preflight OPTIONS request
  if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
  }
  
  try {
  $prompt = trim( (string) $req->get_param('prompt') );
  $is_greeting = (bool) $req->get_param('greeting');

  $raw_context = $req->get_param('context');
  $context     = is_string($raw_context) ? sanitize_key($raw_context) : '';

  $is_composer = false;
  $composer_markers = array();

  if (is_string($raw_context)) {
    $normalized = strtolower(trim($raw_context));
    if ($normalized !== '') {
      $composer_markers[] = $normalized;
      $composer_markers[] = str_replace(array('/', '\\', '-'), '_', $normalized);
    }
  }

  if ($context !== '') {
    $composer_markers[] = $context;
  }

  $mode_param = $req->get_param('mode');
  if (is_string($mode_param)) {
    $mode_normalized = strtolower(trim($mode_param));
    if ($mode_normalized !== '') {
      $composer_markers[] = $mode_normalized;
      $composer_markers[] = str_replace(array('/', '\\', '-'), '_', $mode_normalized);
    }
  }

  $composer_flag_param = $req->get_param('composer');
  if ($composer_flag_param === true || $composer_flag_param === '1' || $composer_flag_param === 1 || $composer_flag_param === 'true') {
    $is_composer = true;
  }

  if (!$is_composer) {
    foreach ($composer_markers as $marker) {
      if (in_array($marker, array('composer', 'compose', 'luna_composer', 'luna_compose', 'lunacomposer', 'lunacompose'), true)) {
        $is_composer = true;
        break;
      }
    }
  }

  if (!$is_composer && function_exists('luna_request_has_composer_signal') && luna_request_has_composer_signal($req)) {
    $is_composer = true;
  }

  if (!$is_composer && !empty($_SERVER['HTTP_X_LUNA_COMPOSER']) && function_exists('luna_request_value_signals_composer')) {
    if (luna_request_value_signals_composer($_SERVER['HTTP_X_LUNA_COMPOSER'])) {
      $is_composer = true;
    }
  }

  if ($is_composer && $prompt === '') {
    return new WP_REST_Response(array(
      'error' => __('Please provide content for Luna Composer to reimagine.', 'luna'),
    ), 400);
  }

  // Handle initial greeting for chat-only interactions
  if (!$is_composer && ($is_greeting || $prompt === '')) {
    $greeting = "Hi, there! I'm Luna, your personal WebOps agent and AI companion. How would you like to continue?";
    $pid = luna_conv_id();
    $meta = array('source' => 'system', 'event' => 'initial_greeting');
    if ($pid) {
      $meta['conversation_id'] = $pid;
      luna_log_turn('', $greeting, $meta);
    }
    return new WP_REST_Response(array('answer'=>$greeting, 'meta'=>$meta), 200);
  }

  // Check for simple greetings AFTER initial greeting (conversation already started)
  $pid = luna_conv_id();
  $has_existing_conversation = false;
  if ($pid) {
    $transcript = get_post_meta($pid, 'transcript', true);
    if (is_array($transcript) && count($transcript) > 0) {
      $has_existing_conversation = true;
    }
  }
  
  $lc = function_exists('mb_strtolower') ? mb_strtolower($prompt) : strtolower($prompt);
  $greeting_patterns = array('/\b(hi|hello|hey|howdy|hola|greetings|good morning|good afternoon|good evening)\b/i');
  $is_simple_greeting = false;
  $is_only_greeting = false;
  foreach ($greeting_patterns as $pattern) {
    if (preg_match($pattern, $lc)) {
      $is_simple_greeting = true;
      // Check if the prompt is ONLY a greeting (no other content)
      $greeting_only = preg_match('/^(hi|hello|hey|howdy|hola|greetings|good morning|good afternoon|good evening)[\s!.,?]*$/i', trim($prompt));
      if ($greeting_only) {
        $is_only_greeting = true;
      }
      break;
    }
  }
  
  // If it's a simple greeting AFTER initial greeting, respond with short friendly message
  if ($is_simple_greeting && $has_existing_conversation && $is_only_greeting) {
    $greeting_response = "Hi there! How can I assist you today?";
    $meta = array('source' => 'deterministic', 'intent' => 'followup_greeting');
    if ($pid) {
      $meta['conversation_id'] = $pid;
      luna_log_turn($prompt, $greeting_response, $meta);
    }
    return new WP_REST_Response(array('answer'=>$greeting_response, 'meta'=>$meta), 200);
  }
  
  // If greeting is part of a larger question, let it proceed to normal processing

  $composer_enabled = get_option(LUNA_WIDGET_OPT_COMPOSER_ENABLED, '1') === '1';
  if ($is_composer && !$composer_enabled) {
    return new WP_REST_Response(array(
      'answer' => __('Luna Composer is currently disabled by an administrator.', 'luna'),
      'meta'   => array('source' => 'system', 'composer' => false),
    ), 200);
  }

  $pid   = luna_conv_id();
  
  // Try to get comprehensive facts, with error handling
  $facts = null;
  try {
  $facts = luna_profile_facts_comprehensive(); // Use comprehensive Hub data
  } catch (Exception $e) {
    error_log('[Luna Widget] Exception in luna_profile_facts_comprehensive: ' . $e->getMessage());
    $facts = null;
  } catch (Error $e) {
    error_log('[Luna Widget] Fatal error in luna_profile_facts_comprehensive: ' . $e->getMessage());
    $facts = null;
  }
  
  // Ensure facts is an array - fallback to basic facts if needed
  if (!is_array($facts) || empty($facts)) {
    error_log('[Luna Widget] Facts invalid or empty, falling back to basic facts');
    try {
      $facts = luna_profile_facts();
    } catch (Exception $e) {
      error_log('[Luna Widget] Even basic facts failed: ' . $e->getMessage());
      // Last resort: minimal facts
      $facts = array(
        'site_url' => home_url('/'),
        'wp_version' => get_bloginfo('version'),
        '__source' => 'minimal-fallback'
      );
    }
    if (!isset($facts['__source'])) {
      $facts['__source'] = 'error-fallback';
    }
  }
  
  if ($is_composer) {
    $facts['__composer'] = true;
  }
  
  $site_url = isset($facts['site_url']) ? (string)$facts['site_url'] : home_url('/');
  $security = isset($facts['security']) && is_array($facts['security']) ? $facts['security'] : array();
  $lc    = function_exists('mb_strtolower') ? mb_strtolower($prompt) : strtolower($prompt);
  $answer = '';
  $meta   = array('source' => 'deterministic');
  if ($is_composer) {
    $meta['composer'] = true;
    if (is_array($matched_composer_prompt) && !empty($matched_composer_prompt['id'])) {
      $meta['composer_prompt_id'] = (int) $matched_composer_prompt['id'];
    }
    if (is_array($matched_composer_prompt) && !empty($matched_composer_prompt['source'])) {
      $meta['composer_prompt_source'] = $matched_composer_prompt['source'];
    }
  }
  
  // Check if this is a comprehensive report request (multi-sentence, paragraph format, etc.)
  // Also detect complex questions with multiple parts, split thoughts, or multiple commands
  $is_comprehensive_report = (
    preg_match('/\b(comprehensive|site health|health report|paragraph format|full sentences|human readable|leadership|email|copy.*paste|intro header|official signature)\b/i', $prompt) ||
    preg_match('/\b(generate.*report|create.*report|write.*report|prepare.*report)\b/i', $prompt) ||
    (substr_count($prompt, '.') >= 2 && strlen($prompt) > 100)
  );
  
  // Detect complex questions: multiple sentences, multiple questions, split thoughts, multiple commands
  $is_complex_question = (
    substr_count($prompt, '?') >= 2 || // Multiple questions
    substr_count($prompt, '.') >= 2 || // Multiple sentences
    substr_count($prompt, ' and ') >= 2 || // Multiple "and" clauses
    preg_match('/\b(and|also|plus|additionally|furthermore|moreover|in addition)\b/i', $prompt) && strlen($prompt) > 50 || // Multiple thoughts
    preg_match('/\b(tell me|show me|give me|provide me|explain|describe|list|compare|analyze)\b.*\b(and|also|plus|additionally)\b.*\b(tell me|show me|give me|provide me|explain|describe|list|compare|analyze)\b/i', $prompt) || // Multiple commands
    (strlen($prompt) > 150 && (substr_count($prompt, ',') >= 3 || substr_count($prompt, ' and ') >= 1)) // Long prompt with multiple clauses
  );

  if (!$is_composer) {
  // Deterministic intents using comprehensive Hub data
  // Enhanced keyword matching for SSL/TLS queries
  if (preg_match('/\b(tls|ssl|https|certificate|cert|encryption|encrypted|secure.*connection|ssl.*cert|tls.*cert|https.*cert)\b/i', $lc)) {
    // Check for SSL/TLS data from VL Hub first (most reliable source)
    $ssl_tls = isset($facts['ssl_tls']) && is_array($facts['ssl_tls']) ? $facts['ssl_tls'] : array();
    $tls = isset($facts['tls']) && is_array($facts['tls']) ? $facts['tls'] : array();
    $security_tls = isset($security['tls']) && is_array($security['tls']) ? $security['tls'] : array();
    
    // Check if we have SSL/TLS data from VL Hub
    if (!empty($ssl_tls)) {
      $certificate = isset($ssl_tls['certificate']) ? $ssl_tls['certificate'] : '';
      $issuer = isset($ssl_tls['issuer']) ? $ssl_tls['issuer'] : '';
      $expires = isset($ssl_tls['expires']) ? $ssl_tls['expires'] : '';
      $days_until_expiry = isset($ssl_tls['days_until_expiry']) ? $ssl_tls['days_until_expiry'] : null;
      $connected = isset($ssl_tls['connected']) ? (bool)$ssl_tls['connected'] : false;
      
      if ($connected && !empty($certificate)) {
        $answer = "**Yes, you have an SSL/TLS certificate configured:**\n\n";
        $answer .= "**Certificate:** " . $certificate . "\n";
        if (!empty($issuer)) {
          $answer .= "**Issuer:** " . $issuer . "\n";
        }
        if (!empty($expires)) {
          $answer .= "**Expires:** " . $expires;
          if ($days_until_expiry !== null) {
            $answer .= " (" . intval($days_until_expiry) . " days until expiry)";
          }
          $answer .= "\n";
        }
        $answer .= "**Status:** Connected\n";
      }
    }
    
    // Fallback to other TLS data sources
    $tls_valid = isset($tls['valid']) ? $tls['valid'] : false;
    $tls_status = isset($security_tls['status']) ? $security_tls['status'] : '';
    $tls_version = isset($security_tls['version']) ? $security_tls['version'] : '';
    $tls_issuer = isset($security_tls['issuer']) ? $security_tls['issuer'] : (isset($tls['issuer']) ? $tls['issuer'] : '');
    $tls_provider = isset($security_tls['provider_guess']) ? $security_tls['provider_guess'] : '';
    $tls_valid_from = isset($security_tls['valid_from']) ? $security_tls['valid_from'] : '';
    $tls_valid_to = isset($security_tls['valid_to']) ? $security_tls['valid_to'] : (isset($tls['expires']) ? $tls['expires'] : '');
    $tls_host = isset($security_tls['host']) ? $security_tls['host'] : '';

    if ($tls_valid || !empty($tls_issuer) || !empty($tls_valid_to)) {
      $details = array();
      if ($tls_status) $details[] = "Status: ".$tls_status;
      if ($tls_version) $details[] = "Version: ".$tls_version;
      if ($tls_issuer) $details[] = "Issuer: ".$tls_issuer;
      if ($tls_provider) $details[] = "Provider: ".$tls_provider;
      if ($tls_valid_from) $details[] = "Valid from: ".$tls_valid_from;
      if ($tls_valid_to) $details[] = "Valid to: ".$tls_valid_to;
      if ($tls_host) $details[] = "Host: ".$tls_host;

      $answer = "Yes—TLS/SSL is active for ".$site_url." (".implode(', ', $details).").";
    } else {
      $answer = "I don't see SSL/TLS certificate information configured in your Visible Light Hub. Please check the SSL/TLS Status connector in the All Connections tab to ensure your certificate is properly configured.";
    }
  }
  elseif (preg_match('/\bwordpress\b.*\bversion\b|\bwp\b.*\bversion\b/', $lc)) {
    $v = isset($facts['wp_version']) ? trim((string)$facts['wp_version']) : '';
    $answer = $v ? ("Your WordPress version is ".$v.".") : "I don't see a confirmed WordPress version in the Hub profile.";
  }
  elseif (preg_match('/\btheme\b.*\bactive\b|\bis.*theme.*active\b/', $lc)) {
    $theme_active = isset($facts['theme_active']) ? (bool)$facts['theme_active'] : true;
    $theme_name = isset($facts['theme']) ? (string)$facts['theme'] : '';
    if ($theme_name) {
      $answer = $theme_active ? ("Yes, the ".$theme_name." theme is currently active.") : ("No, the ".$theme_name." theme is not active.");
    } else {
      $answer = "I don't have confirmation on whether the current theme is active.";
    }
  }
  elseif (preg_match('/\bwhat.*theme|\btheme.*name|\bcurrent.*theme\b/', $lc)) {
    $theme_name = isset($facts['theme']) ? (string)$facts['theme'] : '';
    $answer = $theme_name ? ("You are using the ".$theme_name." theme.") : "I don't see a confirmed theme in the Hub profile.";
  }
  elseif (preg_match('/\bhello\b|\bhi\b|\bhey\b/', $lc)) {
    $answer = "Hello! I'm Luna, your friendly WebOps assistant. I have access to all your site data from Visible Light Hub. I can help you with WordPress version, themes, plugins, SSL status, and more. What would you like to know?";
  }
  elseif (preg_match('/\bup.*to.*date|\boutdated|\bupdate.*available\b/', $lc)) {
    $updates = isset($facts['updates']) && is_array($facts['updates']) ? $facts['updates'] : array();
    $core_updates = isset($updates['core']) ? (int)$updates['core'] : 0;
    $plugin_updates = isset($updates['plugins']) ? (int)$updates['plugins'] : 0;
    $theme_updates = isset($updates['themes']) ? (int)$updates['themes'] : 0;

    if ($core_updates > 0 || $plugin_updates > 0 || $theme_updates > 0) {
      $answer = "You have updates available: WordPress Core: ".$core_updates.", Plugins: ".$plugin_updates.", Themes: ".$theme_updates.". I recommend updating for security and performance.";
    } else {
      $answer = "Your WordPress installation appears to be up to date. No core, plugin, or theme updates are currently available.";
    }
  }
  elseif (preg_match('/\bthreat.*protection|\bsecurity.*scan|\bmalware.*protection|\bthreat.*detection\b/', $lc)) {
    $security_ids = isset($security['ids']) && is_array($security['ids']) ? $security['ids'] : array();
    $ids_provider = isset($security_ids['provider']) ? $security_ids['provider'] : '';
    $last_scan = isset($security_ids['last_scan']) ? $security_ids['last_scan'] : '';
    $last_result = isset($security_ids['result']) ? $security_ids['result'] : '';
    $scan_schedule = isset($security_ids['schedule']) ? $security_ids['schedule'] : '';

    if ($ids_provider) {
      $details = array();
      $details[] = "Provider: ".$ids_provider;
      if ($last_scan) $details[] = "Last scan: ".$last_scan;
      if ($last_result) $details[] = "Last result: ".$last_result;
      if ($scan_schedule) $details[] = "Schedule: ".$scan_schedule;

      $answer = "Yes, you have threat protection set up (".implode(', ', $details)."). This helps protect against malware and security threats.";
    } else {
      $answer = "I don't see specific threat protection details in your security profile. You may want to consider adding a security plugin like Wordfence or Sucuri for malware protection.";
    }
  }
  elseif (preg_match('/\bfirewall\b/', $lc)) {
    $security_waf = isset($security['waf']) && is_array($security['waf']) ? $security['waf'] : array();
    $waf_provider = isset($security_waf['provider']) ? $security_waf['provider'] : '';
    $last_audit = isset($security_waf['last_audit']) ? $security_waf['last_audit'] : '';
    if ($waf_provider) {
      $answer = "Yes, you have a firewall configured. Your WAF provider is ".$waf_provider." with the last audit on ".$last_audit.". This helps block malicious traffic before it reaches your site.";
    } else {
      $answer = "I don't see a specific firewall configuration in your security profile. Consider adding a Web Application Firewall (WAF) for additional protection.";
    }
  }
  // Enhanced keyword matching for AWS S3 queries
  elseif (preg_match('/\b(aws.*s3|s3.*bucket|s3.*storage|amazon.*s3|bucket.*count|object.*count|storage.*used)\b/i', $lc)) {
    $aws_s3 = isset($facts['aws_s3']) && is_array($facts['aws_s3']) ? $facts['aws_s3'] : array();
    $connected = isset($aws_s3['connected']) ? (bool)$aws_s3['connected'] : false;
    $bucket_count = isset($aws_s3['bucket_count']) ? (int)$aws_s3['bucket_count'] : 0;
    $object_count = isset($aws_s3['object_count']) ? (int)$aws_s3['object_count'] : 0;
    $storage_used = isset($aws_s3['storage_used']) ? $aws_s3['storage_used'] : 'N/A';
    $last_sync = isset($aws_s3['last_sync']) ? $aws_s3['last_sync'] : '';
    
    if ($connected) {
      $answer = "**Yes, you have AWS S3 configured!**\n\n";
      $answer .= "**Connection Status:** Connected\n";
      if ($bucket_count > 0) {
        $answer .= "**Buckets:** " . $bucket_count . " bucket(s)\n";
      }
      if ($object_count > 0) {
        $answer .= "**Objects:** " . number_format($object_count) . " objects\n";
      }
      if ($storage_used !== 'N/A') {
        $answer .= "**Storage Used:** " . $storage_used . "\n";
      }
      if ($last_sync) {
        $answer .= "**Last Sync:** " . date('M j, Y g:i A', strtotime($last_sync)) . "\n";
      }
      
      if (!empty($aws_s3['buckets']) && is_array($aws_s3['buckets'])) {
        $answer .= "\n**Buckets:**\n";
        foreach (array_slice($aws_s3['buckets'], 0, 10) as $bucket) {
          $bucket_name = isset($bucket['name']) ? $bucket['name'] : 'Unknown';
          $bucket_objects = isset($bucket['object_count']) ? (int)$bucket['object_count'] : 0;
          $bucket_size = isset($bucket['size']) ? $bucket['size'] : '0 B';
          $answer .= "• " . $bucket_name . " - " . number_format($bucket_objects) . " objects, " . $bucket_size . "\n";
        }
        if (count($aws_s3['buckets']) > 10) {
          $answer .= "... and " . (count($aws_s3['buckets']) - 10) . " more buckets\n";
        }
      }
    } else {
      $answer = "I don't see AWS S3 configured in your Visible Light Hub. AWS S3 is Amazon's cloud storage service that can be used for backups, media storage, and static asset hosting. You can connect AWS S3 in your Visible Light Hub profile.";
    }
  }
  // Enhanced keyword matching for Liquid Web queries
  elseif (preg_match('/\b(liquid.*web|liquidweb|hosting.*assets|server.*assets)\b/i', $lc)) {
    $liquidweb = isset($facts['liquidweb']) && is_array($facts['liquidweb']) ? $facts['liquidweb'] : array();
    $connected = isset($liquidweb['connected']) ? (bool)$liquidweb['connected'] : false;
    $assets_count = isset($liquidweb['assets_count']) ? (int)$liquidweb['assets_count'] : 0;
    $last_sync = isset($liquidweb['last_sync']) ? $liquidweb['last_sync'] : '';
    
    if ($connected) {
      $answer = "**Yes, you have Liquid Web hosting configured!**\n\n";
      $answer .= "**Connection Status:** Connected\n";
      if ($assets_count > 0) {
        $answer .= "**Assets:** " . $assets_count . " asset(s)\n";
      }
      if ($last_sync) {
        $answer .= "**Last Sync:** " . date('M j, Y g:i A', strtotime($last_sync)) . "\n";
      }
      
      if (!empty($liquidweb['assets']) && is_array($liquidweb['assets'])) {
        $answer .= "\n**Assets:**\n";
        foreach (array_slice($liquidweb['assets'], 0, 10) as $asset) {
          $asset_name = isset($asset['name']) ? $asset['name'] : 'Unknown';
          $asset_type = isset($asset['type']) ? $asset['type'] : 'N/A';
          $asset_status = isset($asset['status']) ? $asset['status'] : 'unknown';
          $answer .= "• " . $asset_name . " (" . $asset_type . ") - " . ucfirst($asset_status) . "\n";
        }
        if (count($liquidweb['assets']) > 10) {
          $answer .= "... and " . (count($liquidweb['assets']) - 10) . " more assets\n";
        }
      }
    } else {
      $answer = "I don't see Liquid Web hosting configured in your Visible Light Hub. Liquid Web is a managed hosting provider. You can connect Liquid Web in your Visible Light Hub profile.";
    }
  }
  elseif (preg_match('/\bcdn\b/', $lc)) {
    // Check if Cloudflare is connected (which provides CDN)
    $cloudflare_data = isset($facts['cloudflare']) && is_array($facts['cloudflare']) ? $facts['cloudflare'] : array();
    $cloudflare_connected = isset($cloudflare_data['connected']) ? (bool)$cloudflare_data['connected'] : false;
    
    if ($cloudflare_connected) {
      $answer = "Yes, you have a CDN configured through Cloudflare! Cloudflare provides a Content Delivery Network (CDN) that serves your content from locations closer to your visitors, improving performance and reducing load times.";
    } else {
      $answer = "I don't see a specific CDN configuration in your current profile. A CDN (Content Delivery Network) can improve your site's performance by serving content from locations closer to your visitors. Popular options include Cloudflare, MaxCDN, or KeyCDN.";
    }
  }
  elseif (preg_match('/\bauthentication|\bmfa|\bpassword.*policy|\bsession.*timeout|\bsso\b/', $lc)) {
    $security_auth = isset($security['auth']) && is_array($security['auth']) ? $security['auth'] : array();
    $mfa = isset($security_auth['mfa']) ? $security_auth['mfa'] : '';
    $password_policy = isset($security_auth['password_policy']) ? $security_auth['password_policy'] : '';
    $session_timeout = isset($security_auth['session_timeout']) ? $security_auth['session_timeout'] : '';
    $sso_providers = isset($security_auth['sso_providers']) ? $security_auth['sso_providers'] : '';

    $details = array();
    if ($mfa) $details[] = "MFA: ".$mfa;
    if ($password_policy) $details[] = "Password Policy: ".$password_policy;
    if ($session_timeout) $details[] = "Session Timeout: ".$session_timeout;
    if ($sso_providers) $details[] = "SSO Providers: ".$sso_providers;

    if (!empty($details)) {
      $answer = "Your authentication settings (".implode(', ', $details).").";
    } else {
      $answer = "I don't see specific authentication details in your security profile. Consider setting up MFA, strong password policies, and appropriate session timeouts for better security.";
    }
  }
  elseif (preg_match('/\bdomain.*registrar|\bwho.*registered|\bdomain.*registered.*with\b/', $lc)) {
    $security_domain = isset($security['domain']) && is_array($security['domain']) ? $security['domain'] : array();
    $domain_name = isset($security_domain['domain']) ? $security_domain['domain'] : '';
    $registrar = isset($security_domain['registrar']) ? $security_domain['registrar'] : '';
    $registered_on = isset($security_domain['registered_on']) ? $security_domain['registered_on'] : '';
    $renewal_date = isset($security_domain['renewal_date']) ? $security_domain['renewal_date'] : '';
    $auto_renew = isset($security_domain['auto_renew']) ? $security_domain['auto_renew'] : '';
    $dns_records = isset($security_domain['dns_records']) ? $security_domain['dns_records'] : '';

    if ($registrar) {
      $details = array();
      if ($domain_name) $details[] = "Domain: ".$domain_name;
      $details[] = "Registrar: ".$registrar;
      if ($registered_on) $details[] = "Registered: ".$registered_on;
      if ($renewal_date) $details[] = "Renewal: ".$renewal_date;
      if ($auto_renew) $details[] = "Auto-renew: ".$auto_renew;
      if ($dns_records) $details[] = "DNS Records: ".$dns_records;

      $answer = "Your domain information (".implode(', ', $details).").";
    } else {
      $answer = "I don't have the domain registrar information in your current profile. You can check this in your domain management panel.";
    }
  }
  elseif (preg_match('/\bblog.*title|\bcreate.*title|\bwrite.*title|\bcontent.*idea\b/', $lc)) {
    $site_name = isset($facts['site_url']) ? parse_url($facts['site_url'], PHP_URL_HOST) : 'your website';
    $theme_name = isset($facts['theme']) ? $facts['theme'] : 'your theme';
    $answer = "Here are some blog title ideas for your new website: 'Welcome to ".$site_name." - A Fresh Digital Experience', 'Introducing Our New ".$theme_name."-Powered Website', 'Behind the Scenes: Building ".$site_name."', or 'What's New at ".$site_name." - A Complete Redesign'. Would you like me to suggest more specific topics?";
  }
  elseif (preg_match('/\bwhat.*can.*you.*do|\bwhat.*do.*you.*do|\bhelp.*with\b/', $lc)) {
    $answer = "I can help you with information about your WordPress site, including themes, plugins, SSL status, pages, posts, users, security settings, domain information, analytics data (page views, users, sessions, bounce rate, engagement), and more. All data comes from your Visible Light Hub profile. What would you like to know?";
  }
  elseif (preg_match('/\b(web.*intelligence.*report|intelligence.*report|comprehensive.*report|full.*report|detailed.*report|complete.*analysis)\b/', $lc)) {
    $answer = luna_generate_web_intelligence_report($facts);
  }
  elseif (preg_match('/\b(page.*views|pageviews|analytics|traffic|visitors|users|sessions|bounce.*rate|engagement)\b/', $lc)) {
    $answer = luna_handle_analytics_request($prompt, $facts);
  }
  // Enhanced keyword matching for Cloudflare queries
  elseif (preg_match('/\b(cloudflare|cdn|ddos|waf|web.*application.*firewall|content.*delivery.*network|cloudflare.*zone|cloudflare.*plan)\b/i', $lc)) {
    // Check for Cloudflare data from comprehensive profile
    $cloudflare_data = isset($facts['cloudflare']) && is_array($facts['cloudflare']) ? $facts['cloudflare'] : array();
    $cloudflare_connected = isset($cloudflare_data['connected']) ? (bool)$cloudflare_data['connected'] : false;
    
    if ($cloudflare_connected) {
      $zones_count = isset($cloudflare_data['zones_count']) ? (int)$cloudflare_data['zones_count'] : 0;
      $account_id = isset($cloudflare_data['account_id']) ? $cloudflare_data['account_id'] : '';
      $last_sync = isset($cloudflare_data['last_sync']) ? $cloudflare_data['last_sync'] : null;
      $zones = isset($cloudflare_data['zones']) && is_array($cloudflare_data['zones']) ? $cloudflare_data['zones'] : array();
      
      $answer = "**Yes, you have Cloudflare configured!**\n\n";
      $answer .= "**Connection Status:** Connected\n";
      if ($account_id) {
        $answer .= "**Account ID:** " . $account_id . "\n";
      }
      if ($zones_count > 0) {
        $answer .= "**Zones:** " . $zones_count . " zone(s)\n";
      }
      if ($last_sync) {
        $answer .= "**Last Sync:** " . date('M j, Y g:i A', strtotime($last_sync)) . "\n";
      }
      
      if (!empty($zones)) {
        $answer .= "\n**Configured Zones:**\n";
        foreach ($zones as $zone) {
          $zone_name = isset($zone['name']) ? $zone['name'] : 'Unknown';
          $zone_status = isset($zone['status']) ? $zone['status'] : 'unknown';
          $zone_plan = isset($zone['plan']) ? $zone['plan'] : 'Free';
          $answer .= "• " . $zone_name . " (" . ucfirst($zone_status) . " - " . $zone_plan . " Plan)\n";
        }
      }
      
      $answer .= "\nCloudflare provides DDoS protection, Web Application Firewall (WAF), CDN caching, and DNS management for enhanced security and performance.";
    } else {
      // Check security data streams for Cloudflare
      $security_streams = isset($facts['security_data_streams']) && is_array($facts['security_data_streams']) ? $facts['security_data_streams'] : array();
      $cloudflare_streams = array();
      foreach ($security_streams as $stream_id => $stream_data) {
        if (strpos($stream_id, 'cloudflare') !== false || (isset($stream_data['name']) && strpos(strtolower($stream_data['name']), 'cloudflare') !== false)) {
          $cloudflare_streams[$stream_id] = $stream_data;
        }
      }
      
      if (!empty($cloudflare_streams)) {
        $answer = "**Cloudflare Data Streams Found:**\n\n";
        foreach ($cloudflare_streams as $stream_id => $stream_data) {
          $stream_name = isset($stream_data['name']) ? $stream_data['name'] : 'Cloudflare Zone';
          $stream_status = isset($stream_data['status']) ? $stream_data['status'] : 'unknown';
          $health_score = isset($stream_data['health_score']) ? floatval($stream_data['health_score']) : 0;
          $answer .= "• **" . $stream_name . "**\n";
          $answer .= "  Status: " . ucfirst($stream_status) . "\n";
          $answer .= "  Health Score: " . number_format($health_score, 1) . "%\n";
          if (isset($stream_data['cloudflare_zone_name'])) {
            $answer .= "  Zone: " . $stream_data['cloudflare_zone_name'] . "\n";
          }
          if (isset($stream_data['cloudflare_plan'])) {
            $answer .= "  Plan: " . $stream_data['cloudflare_plan'] . "\n";
          }
          $answer .= "\n";
        }
      } else {
    $answer = "Cloudflare is a popular CDN (Content Delivery Network) and security service that can improve your website's performance and protect it from threats. I don't see Cloudflare specifically configured in your current setup, but it's a great option to consider for faster loading times and enhanced security.";
      }
    }
  }
  elseif (preg_match('/\bdns.*records|\bdns\b/', $lc)) {
    $security_domain = isset($security['domain']) && is_array($security['domain']) ? $security['domain'] : array();
    $dns_records = isset($security_domain['dns_records']) ? $security_domain['dns_records'] : '';
    if ($dns_records) {
      $answer = "Here are your DNS records: ".$dns_records.". These control how your domain points to your hosting server and other services.";
    } else {
      $answer = "I don't have your DNS records in the current profile. You can check these in your domain registrar's control panel or hosting provider's DNS management section.";
    }
  }
  elseif (preg_match('/\blogin.*authenticator|\bauthenticator\b/', $lc)) {
    $security_auth = isset($security['auth']) && is_array($security['auth']) ? $security['auth'] : array();
    $mfa = isset($security_auth['mfa']) ? $security_auth['mfa'] : '';
    if ($mfa) {
      $answer = "Your login authentication is handled by ".$mfa.". This provides an extra layer of security beyond just passwords.";
    } else {
      $answer = "I don't see a specific authenticator configured in your security profile. Consider setting up two-factor authentication (2FA) for enhanced security.";
    }
  }
  elseif (preg_match('/\bquestion\b/', $lc)) {
    $answer = "Of course! I'm here to help. What would you like to know about your website?";
  }
  elseif (preg_match('/\bno\b/', $lc)) {
    $answer = "No problem! Is there anything else I can help you with regarding your website?";
  }
  elseif (preg_match('/\b(thank\s?you|thanks|great|awesome|excellent|perfect)\b/', $lc)) {
    $answer = "Glad I could help! Feel free to ask if you have any other questions about your site.";
  }
  elseif (preg_match('/\b(help|support|issue|problem|error|bug|broken|not working|trouble|stuck|confused|need assistance)\b/', $lc)) {
    $answer = luna_analyze_help_request($prompt, $facts);
  }
  elseif (preg_match('/\b(support email|send email|email support)\b/', $lc)) {
    $answer = luna_handle_help_option('support_email', $prompt, $facts);
  }
  elseif (preg_match('/\b(notify vl|notify visible light|alert vl|alert visible light)\b/', $lc)) {
    $answer = luna_handle_help_option('notify_vl', $prompt, $facts);
  }
  elseif (preg_match('/\b(report bug|bug report|report as bug)\b/', $lc)) {
    $answer = luna_handle_help_option('report_bug', $prompt, $facts);
  }
  elseif (preg_match('/\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b/', $prompt)) {
    // Email address detected - send support email
    $email = luna_extract_email($prompt);
    if ($email) {
      $success = luna_send_support_email($email, $prompt, $facts);
      if ($success) {
        $answer = "✅ Perfect! I've sent a detailed snapshot of our conversation and your site data to " . $email . ". You should receive it shortly. Is there anything else I can help you with?";
      } else {
        $answer = "I encountered an issue sending the email. Let me try notifying the Visible Light team instead - would you like me to do that?";
      }
    } else {
      $answer = "I couldn't find a valid email address in your message. Could you please provide the email address where you'd like me to send the support snapshot?";
    }
  }
  elseif (preg_match("/\bhow.*many.*inactive.*themes|\binactive.*themes\b/", $lc)) {
    $inactive_themes = array();
    if (isset($facts["themes"]) && is_array($facts["themes"])) {
      foreach ($facts["themes"] as $theme) {
        if (empty($theme["is_active"])) {
          $inactive_themes[] = $theme["name"] . " v" . $theme["version"];
        }
      }
    }
    if (!empty($inactive_themes)) {
      $answer = "You have " . count($inactive_themes) . " inactive themes: " . implode(", ", $inactive_themes) . ".";
    } else {
      $answer = "You have no inactive themes. All installed themes are currently active.";
    }
  }
  elseif (preg_match("/\bhow.*many.*plugins|\bplugin.*count\b/", $lc)) {
    $plugin_count = isset($facts["counts"]["plugins"]) ? (int)$facts["counts"]["plugins"] : 0;
    $answer = "You currently have " . $plugin_count . " plugins installed.";
  }
  elseif (preg_match("/\b(list|names|show).*(pages|posts|content)\b|\b(pages|posts).*(list|names|show)\b/", $lc)) {
    // List pages and/or posts with names
    $pages_list = array();
    $posts_list = array();
    
    if (isset($facts['pages']) && is_array($facts['pages']) && !empty($facts['pages'])) {
      foreach ($facts['pages'] as $page) {
        if (is_array($page)) {
          $title = isset($page['title']) ? $page['title'] : (isset($page['name']) ? $page['name'] : 'Untitled Page');
          $pages_list[] = $title;
        } elseif (is_string($page)) {
          $pages_list[] = $page;
        }
      }
    }
    
    if (isset($facts['posts']) && is_array($facts['posts']) && !empty($facts['posts'])) {
      foreach ($facts['posts'] as $post) {
        if (is_array($post)) {
          $title = isset($post['title']) ? $post['title'] : (isset($post['name']) ? $post['name'] : 'Untitled Post');
          $posts_list[] = $title;
        } elseif (is_string($post)) {
          $posts_list[] = $post;
        }
      }
    }
    
    $parts = array();
    if (!empty($pages_list)) {
      $pages_count = count($pages_list);
      $parts[] = "**Pages (" . $pages_count . "):**\n" . implode("\n", array_map(function($title) { return "  • " . $title; }, array_slice($pages_list, 0, 50))); // Limit to 50 for readability
      if ($pages_count > 50) {
        $parts[count($parts) - 1] .= "\n  ... and " . ($pages_count - 50) . " more pages";
      }
    }
    
    if (!empty($posts_list)) {
      $posts_count = count($posts_list);
      $parts[] = "**Posts (" . $posts_count . "):**\n" . implode("\n", array_map(function($title) { return "  • " . $title; }, array_slice($posts_list, 0, 50))); // Limit to 50 for readability
      if ($posts_count > 50) {
        $parts[count($parts) - 1] .= "\n  ... and " . ($posts_count - 50) . " more posts";
      }
    }
    
    if (!empty($parts)) {
      $answer = implode("\n\n", $parts);
    } else {
      $pages_count = isset($facts['counts']['pages']) ? (int)$facts['counts']['pages'] : 0;
      $posts_count = isset($facts['counts']['posts']) ? (int)$facts['counts']['posts'] : 0;
      $answer = "You have " . $pages_count . " pages and " . $posts_count . " posts on your site. However, I don't have the specific names of those pages and posts in the current data. If you need that information, you can check directly in your WordPress dashboard.";
    }
  }
  elseif (preg_match("/\bwhat.*plugins|\blist.*plugins\b/", $lc)) {
    if (isset($facts["plugins"]) && is_array($facts["plugins"]) && !empty($facts["plugins"])) {
      $plugin_list = array();
      foreach ($facts["plugins"] as $plugin) {
        $status = !empty($plugin["active"]) ? "active" : "inactive";
        $plugin_list[] = $plugin["name"] . " v" . $plugin["version"] . " (" . $status . ")";
      }
      $answer = "Your installed plugins are: " . implode(", ", $plugin_list) . ".";
    } else {
      $answer = "I don't see any plugins installed on your site.";
    }
  }
  elseif (preg_match('/\b(competitor|competitors|competitor.*analysis|competitor.*report|domain.*ranking|vldr|vl.*dr|dr.*score|competitive.*analysis)\b/', $lc)) {
    // Competitor analysis and domain ranking queries
    error_log('[Luna Widget] Competitor query detected: ' . $prompt);
    error_log('[Luna Widget] Checking facts for competitors: ' . print_r(isset($facts['competitors']) ? $facts['competitors'] : 'NOT SET', true));
    error_log('[Luna Widget] Checking facts for competitor_reports: ' . print_r(isset($facts['competitor_reports']) ? count($facts['competitor_reports']) . ' reports' : 'NOT SET', true));
    error_log('[Luna Widget] Checking facts for competitor_reports_full: ' . print_r(isset($facts['competitor_reports_full']) ? count($facts['competitor_reports_full']) . ' reports' : 'NOT SET', true));
    error_log('[Luna Widget] Full facts keys: ' . print_r(array_keys($facts), true));
    
    // Collect ALL competitor data sources
    $all_competitor_urls = array();
    $all_competitor_reports = array();
    
    // Get competitor URLs from multiple sources
    if (isset($facts['competitors']) && is_array($facts['competitors']) && !empty($facts['competitors'])) {
      $all_competitor_urls = array_merge($all_competitor_urls, $facts['competitors']);
    }
    
    // Get competitor reports from multiple sources
    if (isset($facts['competitor_reports']) && is_array($facts['competitor_reports'])) {
      $all_competitor_reports = array_merge($all_competitor_reports, $facts['competitor_reports']);
    }
    if (isset($facts['competitor_reports_full']) && is_array($facts['competitor_reports_full'])) {
      $all_competitor_reports = array_merge($all_competitor_reports, $facts['competitor_reports_full']);
    }
    
    // Extract competitor URLs from reports if available
    if (!empty($all_competitor_reports)) {
      foreach ($all_competitor_reports as $report_data) {
        $comp_url = $report_data['url'] ?? '';
        if (!empty($comp_url) && !in_array($comp_url, $all_competitor_urls)) {
          $all_competitor_urls[] = $comp_url;
        }
      }
    }
    
    // Try to extract a specific domain from the query
    $query_domain = null;
    if (preg_match('/\b([a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]*\.(?:[a-zA-Z]{2,}|[a-zA-Z]{2,}\.[a-zA-Z]{2,}))\b/i', $prompt, $matches)) {
      $query_domain = strtolower($matches[1]);
      // Remove www. prefix if present
      $query_domain = preg_replace('/^www\./', '', $query_domain);
    }
    
    // If a specific domain was mentioned, search for it
    if ($query_domain) {
      error_log('[Luna Widget] Searching for specific domain: ' . $query_domain);
      
      // Search in competitor reports (check all sources)
      $found_report = null;
      $reports_to_search = $all_competitor_reports;
      
      foreach ($reports_to_search as $report_data) {
        $comp_url = $report_data['url'] ?? '';
        $comp_domain = $report_data['domain'] ?? '';
        if (empty($comp_domain) && !empty($comp_url)) {
          $comp_domain = parse_url($comp_url, PHP_URL_HOST);
        }
        if ($comp_domain) {
          $comp_domain = strtolower(preg_replace('/^www\./', '', $comp_domain));
          if ($comp_domain === $query_domain) {
            $found_report = $report_data;
            break;
          }
        }
      }
      
      // Search in competitors list (check all sources)
      $found_competitor = false;
      foreach ($all_competitor_urls as $competitor_url) {
        $domain = parse_url($competitor_url, PHP_URL_HOST);
        if ($domain) {
          $domain = strtolower(preg_replace('/^www\./', '', $domain));
          if ($domain === $query_domain) {
            $found_competitor = true;
            break;
          }
        }
      }
      
      // If we found a report, return detailed data
      if ($found_report) {
        $comp_url = $found_report['url'] ?? '';
        $comp_domain = $found_report['domain'] ?? parse_url($comp_url, PHP_URL_HOST);
        $report = $found_report['report'] ?? array();
        $last_scanned = $found_report['last_scanned'] ?? null;
        
        $answer = "**Competitor Analysis Report for " . $comp_domain . ":**\n\n";
        
        if ($last_scanned) {
          $answer .= "**Last Scanned:** " . date('M j, Y g:i A', strtotime($last_scanned)) . "\n\n";
        }
        
        if (!empty($report)) {
          // Handle both direct report data and nested report_json structure
          $report_data = $report;
          if (isset($report['report_json']) && is_array($report['report_json'])) {
            $report_data = $report['report_json'];
          }
          
          // Extract all available data from the report
          if (!empty($report_data['lighthouse_score'])) {
            $answer .= "**Lighthouse Score:** " . $report_data['lighthouse_score'] . "\n";
          } elseif (isset($report_data['lighthouse']) && is_array($report_data['lighthouse'])) {
            $lh = $report_data['lighthouse'];
            if (!empty($lh['performance'])) {
              $answer .= "**Lighthouse Performance Score:** " . $lh['performance'] . "\n";
            }
            if (!empty($lh['accessibility'])) {
              $answer .= "**Lighthouse Accessibility Score:** " . $lh['accessibility'] . "\n";
            }
            if (!empty($lh['seo'])) {
              $answer .= "**Lighthouse SEO Score:** " . $lh['seo'] . "\n";
            }
            if (!empty($lh['best_practices'])) {
              $answer .= "**Lighthouse Best Practices Score:** " . $lh['best_practices'] . "\n";
            }
          }
          
          if (!empty($report_data['public_pages'])) {
            $answer .= "**Public Pages Count:** " . $report_data['public_pages'] . "\n";
          }
          
          if (!empty($report_data['blog']) && is_array($report_data['blog'])) {
            $blog_status = isset($report_data['blog']['status']) ? $report_data['blog']['status'] : 'Unknown';
            $answer .= "**Blog Status:** " . ucfirst($blog_status) . "\n";
          }
          
          if (!empty($report_data['title'])) {
            $answer .= "**Page Title:** " . $report_data['title'] . "\n";
          }
          
          if (!empty($report_data['meta_description'])) {
            $answer .= "**Meta Description:** " . substr($report_data['meta_description'], 0, 200) . "...\n";
          }
          
          if (!empty($report_data['keywords']) && is_array($report_data['keywords'])) {
            $top_keywords = array_slice($report_data['keywords'], 0, 10);
            $answer .= "\n**Top Keywords:** " . implode(", ", $top_keywords) . "\n";
          }
          
          if (!empty($report_data['keyphrases']) && is_array($report_data['keyphrases'])) {
            $top_keyphrases = array_slice($report_data['keyphrases'], 0, 10);
            $answer .= "**Top Keyphrases:** " . implode(", ", $top_keyphrases) . "\n";
          }
        }
        
        // Add VLDR data if available for this domain
        if (isset($facts['vldr'][$query_domain])) {
          $vldr_data = $facts['vldr'][$query_domain];
          $answer .= "\n**Domain Ranking (VL-DR) Metrics:**\n";
          if (isset($vldr_data['vldr_score'])) {
            $score = (float) $vldr_data['vldr_score'];
            $color = $score >= 80 ? "excellent" : ($score >= 60 ? "good" : ($score >= 40 ? "fair" : "needs improvement"));
            $answer .= "  - VL-DR Score: **" . number_format($score, 1) . "/100** (" . $color . ")\n";
          }
          if (isset($vldr_data['ref_domains'])) {
            $answer .= "  - Referring Domains: ~" . number_format($vldr_data['ref_domains'] / 1000, 1) . "k\n";
          }
          if (isset($vldr_data['indexed_pages'])) {
            $answer .= "  - Indexed Pages: ~" . number_format($vldr_data['indexed_pages'] / 1000, 1) . "k\n";
          }
          if (isset($vldr_data['lighthouse_avg'])) {
            $answer .= "  - Lighthouse Average: " . $vldr_data['lighthouse_avg'] . "\n";
          }
          if (isset($vldr_data['security_grade'])) {
            $answer .= "  - Security Grade: **" . $vldr_data['security_grade'] . "**\n";
          }
          if (isset($vldr_data['domain_age_years'])) {
            $answer .= "  - Domain Age: " . number_format($vldr_data['domain_age_years'], 1) . " years\n";
          }
          if (isset($vldr_data['uptime_percent'])) {
            $answer .= "  - Uptime: " . number_format($vldr_data['uptime_percent'], 2) . "%\n";
          }
          if (isset($vldr_data['metric_date'])) {
            $answer .= "  - Last Updated: " . date('M j, Y', strtotime($vldr_data['metric_date'])) . "\n";
          }
        }
      } elseif ($found_competitor) {
        // Domain is in competitors list but no report found - try to fetch VLDR data
        if (isset($facts['vldr'][$query_domain])) {
          $vldr_data = $facts['vldr'][$query_domain];
          $answer = "**Competitor Analysis for " . $query_domain . ":**\n\n";
          if (isset($vldr_data['vldr_score'])) {
            $score = (float) $vldr_data['vldr_score'];
            $color = $score >= 80 ? "excellent" : ($score >= 60 ? "good" : ($score >= 40 ? "fair" : "needs improvement"));
            $answer .= "**VL-DR Score: " . number_format($score, 1) . "/100** (" . $color . ")\n\n";
          }
          $answer .= "**Detailed Metrics:**\n";
          if (isset($vldr_data['ref_domains'])) {
            $answer .= "• Referring Domains: ~" . number_format($vldr_data['ref_domains'] / 1000, 1) . "k\n";
          }
          if (isset($vldr_data['indexed_pages'])) {
            $answer .= "• Indexed Pages: ~" . number_format($vldr_data['indexed_pages'] / 1000, 1) . "k\n";
          }
          if (isset($vldr_data['lighthouse_avg'])) {
            $answer .= "• Lighthouse Average: " . $vldr_data['lighthouse_avg'] . "\n";
          }
          if (isset($vldr_data['security_grade'])) {
            $answer .= "• Security Grade: **" . $vldr_data['security_grade'] . "**\n";
          }
          if (isset($vldr_data['domain_age_years'])) {
            $answer .= "• Domain Age: " . number_format($vldr_data['domain_age_years'], 1) . " years\n";
          }
          if (isset($vldr_data['uptime_percent'])) {
            $answer .= "• Uptime: " . number_format($vldr_data['uptime_percent'], 2) . "%\n";
          }
          if (isset($vldr_data['metric_date'])) {
            $answer .= "\n*Last Updated: " . date('M j, Y', strtotime($vldr_data['metric_date'])) . "*\n";
          }
          $answer .= "\n*Note: A detailed competitor analysis report may not be available. You can run a new analysis in your Visible Light Hub profile.*";
        } else {
          $answer = "I found " . $query_domain . " in your competitor list, but I don't have a detailed analysis report for it yet. You can run a new competitor analysis for this domain in your Visible Light Hub profile.";
        }
      }
    } else {
      // No specific domain mentioned - check if we have ANY competitor data
      // Check for competitor reports first (most reliable source)
      if (!empty($all_competitor_reports)) {
        $competitor_list = array();
        $competitor_details = array();
        
        foreach ($all_competitor_reports as $report_data) {
          $comp_url = $report_data['url'] ?? '';
          $comp_domain = $report_data['domain'] ?? '';
          if (empty($comp_domain) && !empty($comp_url)) {
            $comp_domain = parse_url($comp_url, PHP_URL_HOST);
          }
          if ($comp_domain && !in_array($comp_domain, $competitor_list)) {
            $competitor_list[] = $comp_domain;
            
            // Extract report details
            $report = $report_data['report'] ?? array();
            $report_data_inner = $report;
            if (isset($report['report_json']) && is_array($report['report_json'])) {
              $report_data_inner = $report['report_json'];
            }
            
            $details = array();
            if (!empty($report_data_inner['public_pages'])) {
              $details['public_pages'] = $report_data_inner['public_pages'];
            }
            if (!empty($report_data_inner['blog']) && is_array($report_data_inner['blog'])) {
              $blog_status = isset($report_data_inner['blog']['status']) ? $report_data_inner['blog']['status'] : 'Unknown';
              $details['blog_status'] = ucfirst($blog_status);
            }
            if (!empty($report_data_inner['lighthouse_score'])) {
              $details['lighthouse'] = $report_data_inner['lighthouse_score'];
            } elseif (isset($report_data_inner['lighthouse']) && is_array($report_data_inner['lighthouse'])) {
              $details['lighthouse'] = isset($report_data_inner['lighthouse']['performance']) ? $report_data_inner['lighthouse']['performance'] : 'N/A';
            }
            if (!empty($report_data['last_scanned'])) {
              $details['last_scanned'] = $report_data['last_scanned'];
            }
            
            // Get VLDR score if available
            if (isset($facts['vldr'][$comp_domain])) {
              $details['vldr_score'] = $facts['vldr'][$comp_domain]['vldr_score'] ?? null;
            }
            
            $competitor_details[$comp_domain] = $details;
          }
        }
        
        if (!empty($competitor_list)) {
          $answer = "**Yes, you have " . count($competitor_list) . " competitor(s) listed with analysis reports:**\n\n";
          
          foreach ($competitor_list as $domain) {
            $answer .= "**" . $domain . "**\n";
            $details = $competitor_details[$domain] ?? array();
            
            if (!empty($details['public_pages'])) {
              $answer .= "  • Public Pages: " . $details['public_pages'] . "\n";
            }
            if (!empty($details['blog_status'])) {
              $answer .= "  • Blog Status: " . $details['blog_status'] . "\n";
            }
            if (!empty($details['lighthouse'])) {
              $answer .= "  • Lighthouse Score: " . $details['lighthouse'] . "\n";
            }
            if (!empty($details['vldr_score'])) {
              $answer .= "  • Domain Ranking (VL-DR): " . number_format($details['vldr_score'], 2) . "/100\n";
            }
            if (!empty($details['last_scanned'])) {
              $answer .= "  • Last Scanned: " . date('M j, Y g:i A', strtotime($details['last_scanned'])) . "\n";
            }
            $answer .= "\n";
          }
          
          $first_competitor = !empty($competitor_list) ? $competitor_list[0] : 'your competitor';
          $answer .= "You can ask me about a specific competitor by mentioning their domain name, for example: \"What's the competitor analysis for " . $first_competitor . "?\"\n\n";
          
          // Add VLDR data if available
          if (isset($facts['vldr']) && is_array($facts['vldr']) && !empty($facts['vldr'])) {
            $answer .= "**Domain Ranking (VL-DR) Scores:**\n";
            foreach ($facts['vldr'] as $domain => $vldr_data) {
              $is_client = isset($vldr_data['is_client']) && $vldr_data['is_client'];
              $label = $is_client ? "Your Domain" : "Competitor";
              $answer .= "\n" . $label . ": **" . $domain . "**\n";
              if (isset($vldr_data['vldr_score'])) {
                $score = (float) $vldr_data['vldr_score'];
                $color = $score >= 80 ? "excellent" : ($score >= 60 ? "good" : ($score >= 40 ? "fair" : "needs improvement"));
                $answer .= "  - VL-DR Score: **" . number_format($score, 1) . "/100** (" . $color . ")\n";
              }
              if (isset($vldr_data['ref_domains'])) {
                $answer .= "  - Referring Domains: ~" . number_format($vldr_data['ref_domains'] / 1000, 1) . "k\n";
              }
              if (isset($vldr_data['indexed_pages'])) {
                $answer .= "  - Indexed Pages: ~" . number_format($vldr_data['indexed_pages'] / 1000, 1) . "k\n";
              }
              if (isset($vldr_data['lighthouse_avg'])) {
                $answer .= "  - Lighthouse Average: " . $vldr_data['lighthouse_avg'] . "\n";
              }
              if (isset($vldr_data['security_grade'])) {
                $answer .= "  - Security Grade: **" . $vldr_data['security_grade'] . "**\n";
              }
              if (isset($vldr_data['domain_age_years'])) {
                $answer .= "  - Domain Age: " . number_format($vldr_data['domain_age_years'], 1) . " years\n";
              }
              if (isset($vldr_data['uptime_percent'])) {
                $answer .= "  - Uptime: " . number_format($vldr_data['uptime_percent'], 2) . "%\n";
              }
              if (isset($vldr_data['metric_date'])) {
                $answer .= "  - Last Updated: " . date('M j, Y', strtotime($vldr_data['metric_date'])) . "\n";
              }
            }
            $answer .= "\n*VL-DR is computed from public indicators: Common Crawl/Index, Bing Web Search, SecurityHeaders.com, WHOIS, Visible Light Uptime monitoring, and Lighthouse performance scores.*\n";
          }
        } elseif (!empty($all_competitor_urls)) {
          // Have competitor URLs but no reports yet
          $competitor_list = array();
          foreach ($all_competitor_urls as $competitor_url) {
            $domain = parse_url($competitor_url, PHP_URL_HOST);
            if ($domain) {
              $competitor_list[] = $domain;
            }
          }
          
          if (!empty($competitor_list)) {
            $answer = "You have " . count($competitor_list) . " competitor(s) listed: " . implode(", ", $competitor_list) . ".\n\n";
            $answer .= "However, I don't have detailed analysis reports for them yet. You can run a competitor analysis in your Visible Light Hub profile to generate reports with Lighthouse scores, keywords, and domain rankings.\n\n";
            
            // Add VLDR data if available
            if (isset($facts['vldr']) && is_array($facts['vldr']) && !empty($facts['vldr'])) {
              $answer .= "**Domain Ranking (VL-DR) Scores Available:**\n";
              foreach ($facts['vldr'] as $domain => $vldr_data) {
                if (in_array($domain, $competitor_list)) {
                  $answer .= "\n**" . $domain . ":**\n";
                  if (isset($vldr_data['vldr_score'])) {
                    $score = (float) $vldr_data['vldr_score'];
                    $color = $score >= 80 ? "excellent" : ($score >= 60 ? "good" : ($score >= 40 ? "fair" : "needs improvement"));
                    $answer .= "  - VL-DR Score: **" . number_format($score, 1) . "/100** (" . $color . ")\n";
                  }
                  if (isset($vldr_data['ref_domains'])) {
                    $answer .= "  - Referring Domains: ~" . number_format($vldr_data['ref_domains'] / 1000, 1) . "k\n";
                  }
                  if (isset($vldr_data['indexed_pages'])) {
                    $answer .= "  - Indexed Pages: ~" . number_format($vldr_data['indexed_pages'] / 1000, 1) . "k\n";
                  }
                  if (isset($vldr_data['lighthouse_avg'])) {
                    $answer .= "  - Lighthouse Average: " . $vldr_data['lighthouse_avg'] . "\n";
                  }
                  if (isset($vldr_data['security_grade'])) {
                    $answer .= "  - Security Grade: **" . $vldr_data['security_grade'] . "**\n";
                  }
                }
              }
            }
          } else {
            $answer = "I don't see any competitor analysis data configured. You can set up competitor analysis in your Visible Light Hub profile to track competitor domains and their performance metrics.";
          }
        } else {
          $answer = "I don't see any competitor analysis data configured. You can set up competitor analysis in your Visible Light Hub profile to track competitor domains and their performance metrics.";
        }
      }
    }
  }
  elseif (preg_match('/\b(domain.*rank|vldr|vl.*dr|dr.*score|ranking.*score)\b/', $lc) && preg_match('/\b(astronomer|siteassembly|nvidia|competitor|competitors)\b/i', $prompt)) {
    // Specific domain ranking query
    $domain_match = null;
    if (preg_match('/\b(astronomer\.io|siteassembly\.com|nvidia\.com)\b/i', $prompt, $matches)) {
      $domain_match = strtolower($matches[1]);
    } elseif (preg_match('/\b(astronomer|siteassembly|nvidia)\b/i', $prompt, $matches)) {
      $domain_lookup = array(
        'astronomer' => 'astronomer.io',
        'siteassembly' => 'siteassembly.com',
        'nvidia' => 'nvidia.com',
      );
      $key = strtolower($matches[1]);
      if (isset($domain_lookup[$key])) {
        $domain_match = $domain_lookup[$key];
      }
    }
    
    if ($domain_match && isset($facts['vldr'][$domain_match])) {
      $vldr_data = $facts['vldr'][$domain_match];
      $answer = "**Domain Ranking for " . $domain_match . ":**\n\n";
      if (isset($vldr_data['vldr_score'])) {
        $score = (float) $vldr_data['vldr_score'];
        $color = $score >= 80 ? "excellent" : ($score >= 60 ? "good" : ($score >= 40 ? "fair" : "needs improvement"));
        $answer .= "**VL-DR Score: " . number_format($score, 1) . "/100** (" . $color . ")\n\n";
      }
      $answer .= "**Detailed Metrics:**\n";
      if (isset($vldr_data['ref_domains'])) {
        $answer .= "• Referring Domains: ~" . number_format($vldr_data['ref_domains'] / 1000, 1) . "k\n";
      }
      if (isset($vldr_data['indexed_pages'])) {
        $answer .= "• Indexed Pages: ~" . number_format($vldr_data['indexed_pages'] / 1000, 1) . "k\n";
      }
      if (isset($vldr_data['lighthouse_avg'])) {
        $answer .= "• Lighthouse Average: " . $vldr_data['lighthouse_avg'] . "\n";
      }
      if (isset($vldr_data['security_grade'])) {
        $answer .= "• Security Grade: **" . $vldr_data['security_grade'] . "**\n";
      }
      if (isset($vldr_data['domain_age_years'])) {
        $answer .= "• Domain Age: " . number_format($vldr_data['domain_age_years'], 1) . " years\n";
      }
      if (isset($vldr_data['uptime_percent'])) {
        $answer .= "• Uptime: " . number_format($vldr_data['uptime_percent'], 2) . "%\n";
      }
      if (isset($vldr_data['metric_date'])) {
        $answer .= "\n*Last Updated: " . date('M j, Y', strtotime($vldr_data['metric_date'])) . "*\n";
      }
      $answer .= "\n*VL-DR is computed from public indicators: Common Crawl/Index, Bing Web Search, SecurityHeaders.com, WHOIS, Visible Light Uptime monitoring, and Lighthouse performance scores.*";
    } else {
      $answer = "I don't have domain ranking data for that domain. Make sure competitor analysis is set up in your Visible Light Hub profile for the domain you're asking about.";
    }
  }

  }

  if ($answer === '' && !$is_composer) {
    $facts_source = isset($facts['__source']) ? $facts['__source'] : ((isset($facts['comprehensive']) && $facts['comprehensive']) ? 'comprehensive' : 'basic');
    if ($facts_source !== 'comprehensive') {
      $canned = luna_widget_find_canned_response($prompt);
      if (is_array($canned) && !empty($canned['content'])) {
        $answer = $canned['content'];
        $meta['source'] = 'canned_response';
        $meta['canned_id'] = $canned['id'];
        if (!empty($canned['title'])) {
          $meta['canned_title'] = $canned['title'];
        }
        if (isset($vldr_data['lighthouse_avg'])) {
          $answer .= "• Lighthouse Average: " . $vldr_data['lighthouse_avg'] . "\n";
        }
        if (isset($vldr_data['security_grade'])) {
          $answer .= "• Security Grade: **" . $vldr_data['security_grade'] . "**\n";
        }
        if (isset($vldr_data['domain_age_years'])) {
          $answer .= "• Domain Age: " . number_format($vldr_data['domain_age_years'], 1) . " years\n";
        }
        if (isset($vldr_data['uptime_percent'])) {
          $answer .= "• Uptime: " . number_format($vldr_data['uptime_percent'], 2) . "%\n";
        }
        if (isset($vldr_data['metric_date'])) {
          $answer .= "\n*Last Updated: " . date('M j, Y', strtotime($vldr_data['metric_date'])) . "*\n";
        }
        $answer .= "\n*VL-DR is computed from public indicators: Common Crawl/Index, Bing Web Search, SecurityHeaders.com, WHOIS, Visible Light Uptime monitoring, and Lighthouse performance scores.*";
      } else {
        $answer = "I don't have domain ranking data for that domain. Make sure competitor analysis is set up in your Visible Light Hub profile for the domain you're asking about.";
      }
    }

  }

  // For Luna Composer, ALWAYS use OpenAI to generate long-form, thoughtful, hyper-personal responses
  // Skip deterministic answers AND canned responses for composer requests to ensure all responses are AI-generated
  // Even if a deterministic answer was found, override it for composer requests
  if ($is_composer) {
    // Reset answer to empty to force OpenAI generation
    $answer = '';
    // For Luna Composer, ALWAYS generate long-form, thoughtful, hyper-personal responses
    // CRITICAL: Use actual VL Hub data from the FACTS section - reference specific metrics, trends, and insights
    $enhanced_prompt = "You are Luna Composer, a sophisticated AI writing assistant that generates long-form, thoughtful, and hyper-personal data-driven content. The user is using Luna Composer to create editable long-form content, so your response must be comprehensive, well-structured, and suitable for professional editing. Write in a thoughtful, engaging, and personable manner that feels human and authentic, not robotic. Use full sentences, proper paragraph breaks, and narrative-style explanations.\n\nCRITICAL INSTRUCTIONS:\n1. You MUST use the actual VL Hub data provided in the FACTS section below. Reference specific metrics, trends, and insights from the user's actual data (GA4 metrics, Lighthouse scores, competitor analysis, domain rankings, SSL/TLS status, Cloudflare connections, AWS S3 storage, ad platform data, etc.).\n2. Weave this real data naturally into a cohesive narrative. Make the content feel personalized and tailored to the user's specific needs and their actual digital presence.\n3. Analyze the data, highlight trends, call out risks or opportunities, and provide strategic recommendations or next steps so the user receives meaningful guidance rather than just raw metrics.\n4. When data is missing or limited, acknowledge the gap and recommend how to close it.\n5. Generate a substantial, detailed response (minimum 300-500 words for simple queries, 800-1200+ words for complex queries) that provides real value and can be edited into polished content.\n6. NEVER use emoticons, emojis, unicode symbols, or special characters. Use plain text only.\n\nUser request: " . $prompt;
    
    // Mark this as a composer request in facts for the OpenAI function
    $facts['__composer'] = true;
    $openai = luna_generate_openai_answer($pid, $enhanced_prompt, $facts, true); // Force comprehensive mode for composer
    if (is_array($openai) && !empty($openai['answer'])) {
      $answer = $openai['answer'];
      $meta['source'] = 'openai';
      $meta['composer'] = true;
      if (!empty($openai['model'])) {
        $meta['model'] = $openai['model'];
      }
      if (!empty($openai['usage']) && is_array($openai['usage'])) {
        $meta['usage'] = $openai['usage'];
      }
    } else {
      // Last resort: generic helpful message
      $answer = "I can help you with information about your WordPress site, including themes, plugins, SSL/TLS certificates, Cloudflare connections, AWS S3 storage, Google Analytics 4, Liquid Web hosting, competitor analysis, domain rankings (VLDR), performance metrics, SEO data, data streams, and more. All data comes from your Visible Light Hub profile. What would you like to know?";
      $meta['source'] = 'default';
    }
  } elseif ($answer === '') {
    // If no deterministic answer was found, use OpenAI with comprehensive facts
    // The facts text already includes ALL VL Hub data, so GPT-4o can intelligently answer
    // For comprehensive reports or complex questions, add additional context to the prompt
    $enhanced_prompt = $prompt;
    if ($is_comprehensive_report) {
      $enhanced_prompt = "Generate a comprehensive, enterprise-grade site health report in paragraph format with full sentences that can be easily copied and pasted into an email. Write in a thoughtful, full-length, professional manner that reads like a human executive report. Use personable, professional language suitable for leadership. Format with proper paragraph breaks and section headers. Use descriptive, narrative-style explanations rather than bullet points. Include an intro header, detailed findings from all data sources, and an official signature. NEVER use emoticons, emojis, unicode symbols, or special characters. Use plain text only.\n\nUser request: " . $prompt;
    } elseif ($is_complex_question) {
      $enhanced_prompt = "The user has asked a complex question with multiple parts, split thoughts, or multiple commands. Please address ALL parts of the question comprehensively. Break down the response into clear sections if needed, but ensure every aspect of the question is answered thoroughly. Use full sentences and proper paragraph breaks. NEVER use emoticons, emojis, unicode symbols, or special characters. Use plain text only.\n\nUser request: " . $prompt;
    }
    $openai = luna_generate_openai_answer($pid, $enhanced_prompt, $facts, $is_comprehensive_report || $is_complex_question);
    if (is_array($openai) && !empty($openai['answer'])) {
      $answer = $openai['answer'];
      $meta['source'] = 'openai';
      if ($is_comprehensive_report) {
        $meta['report_type'] = 'comprehensive';
      }
      if (!empty($openai['model'])) {
        $meta['model'] = $openai['model'];
      }
      if (!empty($openai['usage']) && is_array($openai['usage'])) {
        $meta['usage'] = $openai['usage'];
      }
    } else {
      // Last resort: generic helpful message
      $answer = "I can help you with information about your WordPress site, including themes, plugins, SSL/TLS certificates, Cloudflare connections, AWS S3 storage, Google Analytics 4, Liquid Web hosting, competitor analysis, domain rankings (VLDR), performance metrics, SEO data, data streams, and more. All data comes from your Visible Light Hub profile. What would you like to know?";
      $meta['source'] = 'default';
    }
  }

  if ($pid) {
    $meta['conversation_id'] = $pid;
  }
  luna_log_turn($prompt, $answer, $meta);

  if ($is_composer) {
    luna_composer_log_entry($prompt, $answer, $meta, $pid);
  }

  return new WP_REST_Response(array('answer'=>$answer, 'meta'=>$meta), 200);
  
  } catch (Exception $e) {
    error_log('[Luna Widget Chat Handler] Exception: ' . $e->getMessage());
    error_log('[Luna Widget Chat Handler] Stack trace: ' . $e->getTraceAsString());
    return new WP_REST_Response(array(
      'answer' => 'I encountered an error processing your request. Please try again.',
      'meta' => array('source' => 'error', 'error' => $e->getMessage())
    ), 500);
  } catch (Error $e) {
    error_log('[Luna Widget Chat Handler] Fatal error: ' . $e->getMessage());
    error_log('[Luna Widget Chat Handler] Stack trace: ' . $e->getTraceAsString());
    return new WP_REST_Response(array(
      'answer' => 'I encountered an error processing your request. Please try again.',
      'meta' => array('source' => 'error', 'error' => $e->getMessage())
    ), 500);
  }
}

function luna_widget_rest_chat_inactive( WP_REST_Request $req ) {
  $default_message = "I haven't heard from you in a while, are you still there? If not, I'll close out this chat automatically in 3 minutes.";
  $message = $req->get_param('message');
  if (!is_string($message) || trim($message) === '') {
    $message = $default_message;
  } else {
    $message = sanitize_text_field($message);
  }

  $pid = luna_conv_id();
  $meta = array('source' => 'system', 'event' => 'inactive_warning');
  if ($pid) {
    $meta['conversation_id'] = $pid;
    update_post_meta($pid, 'last_inactive_warning', time());
  }

  luna_log_turn('', $message, $meta);

  return new WP_REST_Response(array('message' => $message), 200);
}

function luna_widget_rest_chat_end_session( WP_REST_Request $req ) {
  $pid = luna_conv_id();
  $default_message = 'This chat session has been closed due to inactivity.';
  $message = $req->get_param('message');
  if (!is_string($message) || trim($message) === '') {
    $message = $default_message;
  } else {
    $message = sanitize_text_field($message);
  }

  $reason = $req->get_param('reason');
  if (!is_string($reason) || trim($reason) === '') {
    $reason = 'manual';
  } else {
    $reason = sanitize_text_field($reason);
  }

  $already_closed = $pid ? (bool) get_post_meta($pid, 'session_closed', true) : false;
  if ($pid && !$already_closed) {
    luna_widget_close_conversation($pid, $reason);
    $meta = array(
      'source' => 'system',
      'event'  => 'session_end',
      'reason' => $reason,
      'conversation_id' => $pid,
    );
    luna_log_turn('', $message, $meta);
  }

  return new WP_REST_Response(array(
    'closed' => (bool) $pid,
    'already_closed' => $already_closed,
    'message' => $message,
  ), 200);
}

function luna_widget_rest_chat_reset_session( WP_REST_Request $req ) {
  $reason = $req->get_param('reason');
  if (!is_string($reason) || trim($reason) === '') {
    $reason = 'reset';
  } else {
    $reason = sanitize_text_field($reason);
  }

  $current = luna_widget_current_conversation_id();
  if ($current) {
    luna_widget_close_conversation($current, $reason);
  }

  $pid = luna_conv_id(true);
  if ($pid) {
    return new WP_REST_Response(array('reset' => true, 'conversation_id' => $pid), 200);
  }

  return new WP_REST_Response(array('reset' => false), 500);
}
add_action('rest_api_init', function () {

  /* --- CHAT --- */
  register_rest_route('luna_widget/v1', '/chat', array(
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => 'luna_widget_chat_handler',
  ));

  /* --- TEST CHAT --- */
  register_rest_route('luna_widget/v1', '/test-chat', array(
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function( WP_REST_Request $req ){
      return new WP_REST_Response(array('answer'=>'Test successful!'), 200);
    },
  ));

  register_rest_route('luna_widget/v1', '/chat/inactive', array(
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => 'luna_widget_rest_chat_inactive',
  ));

  register_rest_route('luna_widget/v1', '/chat/session/end', array(
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => 'luna_widget_rest_chat_end_session',
  ));

  register_rest_route('luna_widget/v1', '/chat/session/reset', array(
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => 'luna_widget_rest_chat_reset_session',
  ));

  /* --- COMPOSER DOCUMENT FETCH (for 30 days) --- */
  register_rest_route('luna_widget/v1', '/composer/fetch', array(
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function( WP_REST_Request $req ){
      $license = trim((string) $req->get_param('license'));
      $document_id = trim((string) $req->get_param('document_id'));
      
      if (!$license) {
        return new WP_REST_Response(array('error' => 'Missing license parameter'), 400);
      }
      
      // Fetch documents from last 30 days
      $thirty_days_ago = time() - (30 * 24 * 60 * 60);
      
      $args = array(
        'post_type' => 'luna_compose',
        'post_status' => 'publish',
        'meta_query' => array(
          'relation' => 'AND',
          array(
            'key' => 'license',
            'value' => $license,
            'compare' => '='
          ),
          array(
            'key' => 'timestamp',
            'value' => $thirty_days_ago,
            'compare' => '>='
          )
        ),
        'posts_per_page' => 50,
        'orderby' => 'meta_value_num',
        'meta_key' => 'timestamp',
        'order' => 'DESC'
      );
      
      // If document_id is provided, fetch specific document
      if ($document_id) {
        $args['meta_query'][] = array(
          'key' => 'document_id',
          'value' => $document_id,
          'compare' => '='
        );
        $args['posts_per_page'] = 1;
      }
      
      $posts = get_posts($args);
      
      if (empty($posts)) {
        return new WP_REST_Response(array('documents' => array()), 200);
      }
      
      $documents = array();
      foreach ($posts as $post) {
        $doc_id = get_post_meta($post->ID, 'document_id', true);
        $prompt = get_post_meta($post->ID, 'prompt', true);
        $content = get_post_meta($post->ID, 'answer', true);
        $timestamp = (int) get_post_meta($post->ID, 'timestamp', true);
        $feedback = get_post_meta($post->ID, 'feedback', true); // Get current feedback state
        
        $documents[] = array(
          'id' => $doc_id,
          'prompt' => $prompt,
          'content' => $content,
          'timestamp' => $timestamp * 1000, // Convert to milliseconds for JavaScript
          'post_id' => $post->ID,
          'feedback' => $feedback ? $feedback : 'dislike' // Default to 'dislike' if no feedback set
        );
      }
      
      return new WP_REST_Response(array('documents' => $documents), 200);
    },
  ));
  
  /* --- COMPOSER DOCUMENT SHARE (CREATE SHARE LINK) --- */
  register_rest_route('luna_widget/v1', '/composer/share', array(
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function( WP_REST_Request $req ){
      $license = trim((string) $req->get_param('license'));
      $document_id = trim((string) $req->get_param('document_id'));
      $share_id = trim((string) $req->get_param('share_id'));
      $content = trim((string) $req->get_param('content'));
      $prompt = trim((string) $req->get_param('prompt'));
      
      if (!$license || !$document_id || !$share_id || !$content) {
        return new WP_REST_Response(array('error' => 'Missing required parameters'), 400);
      }
      
      // Find document by document_id
      $existing = get_posts(array(
        'post_type' => 'luna_compose',
        'post_status' => 'publish',
        'meta_query' => array(
          array(
            'key' => 'document_id',
            'value' => $document_id,
            'compare' => '='
          ),
          array(
            'key' => 'license',
            'value' => $license,
            'compare' => '='
          )
        ),
        'posts_per_page' => 1,
        'fields' => 'ids'
      ));
      
      if (empty($existing)) {
        return new WP_REST_Response(array('error' => 'Document not found'), 404);
      }
      
      $post_id = $existing[0];
      
      // Store share ID and make document shareable
      update_post_meta($post_id, 'share_id', $share_id);
      update_post_meta($post_id, 'share_content', $content); // Store content snapshot for sharing
      update_post_meta($post_id, 'share_prompt', $prompt);
      update_post_meta($post_id, 'share_timestamp', time());
      update_post_meta($post_id, 'share_enabled', true);
      
      // Track share creation in VL Hub
      $hub_url = 'https://visiblelight.ai/wp-json/vl-hub/v1/composer-share';
      $hub_response = wp_remote_post($hub_url, array(
        'method' => 'POST',
        'timeout' => 10,
        'headers' => array(
          'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode(array(
          'license' => $license,
          'document_id' => $document_id,
          'share_id' => $share_id,
          'timestamp' => time()
        )),
        'sslverify' => true
      ));
      
      if (is_wp_error($hub_response)) {
        error_log('[Luna Widget] VL Hub share sync error: ' . $hub_response->get_error_message());
      } else {
        $hub_code = wp_remote_retrieve_response_code($hub_response);
        if ($hub_code !== 200) {
          error_log('[Luna Widget] VL Hub share sync failed with HTTP ' . $hub_code);
        }
      }
      
      return new WP_REST_Response(array(
        'success' => true,
        'post_id' => $post_id,
        'share_id' => $share_id
      ), 200);
    },
  ));
  
  /* --- COMPOSER SHARED DOCUMENT FETCH (PUBLIC ACCESS) --- */
  register_rest_route('luna_widget/v1', '/composer/shared', array(
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function( WP_REST_Request $req ){
      $share_id = trim((string) $req->get_param('share_id'));
      
      if (!$share_id) {
        return new WP_REST_Response(array('error' => 'Missing share_id parameter'), 400);
      }
      
      // Find document by share_id
      $posts = get_posts(array(
        'post_type' => 'luna_compose',
        'post_status' => 'publish',
        'meta_query' => array(
          array(
            'key' => 'share_id',
            'value' => $share_id,
            'compare' => '='
          ),
          array(
            'key' => 'share_enabled',
            'value' => '1',
            'compare' => '='
          )
        ),
        'posts_per_page' => 1
      ));
      
      if (empty($posts)) {
        return new WP_REST_Response(array('error' => 'Shared document not found or access disabled'), 404);
      }
      
      $post = $posts[0];
      $license = get_post_meta($post->ID, 'license', true);
      $document_id = get_post_meta($post->ID, 'document_id', true);
      $content = get_post_meta($post->ID, 'share_content', true); // Use shared content snapshot
      $prompt = get_post_meta($post->ID, 'share_prompt', true);
      $timestamp = (int) get_post_meta($post->ID, 'share_timestamp', true);
      
      // Track view in WordPress
      $view_count = (int) get_post_meta($post->ID, 'share_view_count', true);
      $view_count++;
      update_post_meta($post->ID, 'share_view_count', $view_count);
      
      // Track view in VL Hub
      $hub_url = 'https://visiblelight.ai/wp-json/vl-hub/v1/composer-share-view';
      $hub_response = wp_remote_post($hub_url, array(
        'method' => 'POST',
        'timeout' => 10,
        'headers' => array(
          'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode(array(
          'license' => $license,
          'document_id' => $document_id,
          'share_id' => $share_id,
          'view_count' => $view_count,
          'timestamp' => time(),
          'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
          'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        )),
        'sslverify' => true
      ));
      
      if (is_wp_error($hub_response)) {
        error_log('[Luna Widget] VL Hub view tracking error: ' . $hub_response->get_error_message());
      } else {
        $hub_code = wp_remote_retrieve_response_code($hub_response);
        if ($hub_code !== 200) {
          error_log('[Luna Widget] VL Hub view tracking failed with HTTP ' . $hub_code);
        }
      }
      
      return new WP_REST_Response(array(
        'document' => array(
          'id' => $document_id,
          'share_id' => $share_id,
          'prompt' => $prompt,
          'content' => $content,
          'timestamp' => $timestamp * 1000, // Convert to milliseconds
          'view_count' => $view_count
        )
      ), 200);
    },
  ));
  
  /* --- COMPOSER DOCUMENT DELETE --- */
  register_rest_route('luna_widget/v1', '/composer/delete', array(
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function( WP_REST_Request $req ){
      $license = trim((string) $req->get_param('license'));
      $document_id = trim((string) $req->get_param('document_id'));
      
      if (!$license || !$document_id) {
        return new WP_REST_Response(array('error' => 'Missing required parameters'), 400);
      }
      
      // Find document by document_id
      $existing = get_posts(array(
        'post_type' => 'luna_compose',
        'post_status' => 'publish',
        'meta_query' => array(
          array(
            'key' => 'document_id',
            'value' => $document_id,
            'compare' => '='
          ),
          array(
            'key' => 'license',
            'value' => $license,
            'compare' => '='
          )
        ),
        'posts_per_page' => 1,
        'fields' => 'ids'
      ));
      
      if (!empty($existing)) {
        // Delete the post (trash it)
        $post_id = $existing[0];
        wp_delete_post($post_id, true); // true = force delete (bypass trash)
        
        return new WP_REST_Response(array('success' => true, 'post_id' => $post_id, 'deleted' => true), 200);
      } else {
        return new WP_REST_Response(array('error' => 'Document not found'), 404);
      }
    },
  ));
  
  /* --- COMPOSER FEEDBACK (LIKE/DISLIKE) --- */
  register_rest_route('luna_widget/v1', '/composer/feedback', array(
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function( WP_REST_Request $req ){
      $license = trim((string) $req->get_param('license'));
      $document_id = trim((string) $req->get_param('document_id'));
      $feedback_type = trim((string) $req->get_param('feedback_type')); // 'like' or 'dislike'
      $prompt = trim((string) $req->get_param('prompt'));
      $content = trim((string) $req->get_param('content'));
      
      if (!$license || !$document_id || !$feedback_type) {
        return new WP_REST_Response(array('error' => 'Missing required parameters'), 400);
      }
      
      if (!in_array($feedback_type, array('like', 'dislike'))) {
        return new WP_REST_Response(array('error' => 'Invalid feedback type'), 400);
      }
      
      // Find document by document_id
      $existing = get_posts(array(
        'post_type' => 'luna_compose',
        'post_status' => 'publish',
        'meta_query' => array(
          array(
            'key' => 'document_id',
            'value' => $document_id,
            'compare' => '='
          ),
          array(
            'key' => 'license',
            'value' => $license,
            'compare' => '='
          )
        ),
        'posts_per_page' => 1,
        'fields' => 'ids'
      ));
      
      $post_id = null;
      if (!empty($existing)) {
        $post_id = $existing[0];
      } else {
        // Create a new post if document doesn't exist
        $title = $prompt ? wp_trim_words($prompt, 12, '…') : __('Composer Entry', 'luna');
        $post_id = wp_insert_post(array(
          'post_type'   => 'luna_compose',
          'post_title'  => $title,
          'post_status' => 'publish',
        ));
        
        if (!$post_id || is_wp_error($post_id)) {
          return new WP_REST_Response(array('error' => 'Failed to create document'), 500);
        }
        
        update_post_meta($post_id, 'document_id', $document_id);
        update_post_meta($post_id, 'license', $license);
        update_post_meta($post_id, 'prompt', $prompt);
        update_post_meta($post_id, 'answer', $content);
        update_post_meta($post_id, 'timestamp', time());
      }
      
      // Store feedback counts
      $feedback_key = 'feedback_' . $feedback_type;
      $existing_feedback = get_post_meta($post_id, $feedback_key, true);
      $feedback_count = is_numeric($existing_feedback) ? (int) $existing_feedback : 0;
      $feedback_count++;
      update_post_meta($post_id, $feedback_key, $feedback_count);
      
      // Store feedback timestamp
      update_post_meta($post_id, $feedback_key . '_last', time());
      
      // Store current feedback state (most recent feedback type)
      // This is the critical piece that persists the state
      update_post_meta($post_id, 'feedback', $feedback_type);
      
      // Store feedback data for VL Hub sync
      $feedback_data = array(
        'document_id' => $document_id,
        'license' => $license,
        'feedback_type' => $feedback_type,
        'prompt' => $prompt,
        'content_preview' => $content,
        'timestamp' => time()
      );
      
      // Add to feedback log
      $feedback_log = get_post_meta($post_id, 'feedback_log', true);
      if (!is_array($feedback_log)) {
        $feedback_log = array();
      }
      $feedback_log[] = $feedback_data;
      // Keep only last 100 feedback entries
      if (count($feedback_log) > 100) {
        $feedback_log = array_slice($feedback_log, -100);
      }
      update_post_meta($post_id, 'feedback_log', $feedback_log);
      
      // Sync to VL Hub
      $hub_url = 'https://visiblelight.ai/wp-json/vl-hub/v1/composer-feedback';
      $hub_response = wp_remote_post($hub_url, array(
        'method' => 'POST',
        'timeout' => 10,
        'headers' => array(
          'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode($feedback_data),
        'sslverify' => true
      ));
      
      if (is_wp_error($hub_response)) {
        error_log('[Luna Widget] VL Hub sync error: ' . $hub_response->get_error_message());
      } else {
        $hub_code = wp_remote_retrieve_response_code($hub_response);
        if ($hub_code !== 200) {
          error_log('[Luna Widget] VL Hub sync failed with HTTP ' . $hub_code);
        }
      }
      
      return new WP_REST_Response(array(
        'success' => true,
        'post_id' => $post_id,
        'feedback_type' => $feedback_type,
        'feedback_count' => $feedback_count
      ), 200);
    },
  ));
  
  /* --- COMPOSER DOCUMENT SAVE --- */
  register_rest_route('luna_widget/v1', '/composer/save', array(
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function( WP_REST_Request $req ){
      $license = trim((string) $req->get_param('license'));
      $document_id = trim((string) $req->get_param('document_id'));
      $prompt = trim((string) $req->get_param('prompt'));
      $content = trim((string) $req->get_param('content'));
      
      if (!$license || !$document_id || !$content) {
        return new WP_REST_Response(array('error' => 'Missing required parameters'), 400);
      }
      
      // Check if document already exists (by document_id in meta)
      $existing = get_posts(array(
        'post_type' => 'luna_compose',
        'post_status' => 'publish',
        'meta_query' => array(
          array(
            'key' => 'document_id',
            'value' => $document_id,
            'compare' => '='
          )
        ),
        'posts_per_page' => 1,
        'fields' => 'ids'
      ));
      
      if (!empty($existing)) {
        // Update existing document
        $post_id = $existing[0];
        wp_update_post(array(
          'ID' => $post_id,
          'post_title' => $prompt ? wp_trim_words($prompt, 12, '…') : __('Composer Entry', 'luna'),
        ));
        update_post_meta($post_id, 'prompt', $prompt);
        update_post_meta($post_id, 'answer', $content);
        update_post_meta($post_id, 'document_id', $document_id);
        update_post_meta($post_id, 'license', $license);
        update_post_meta($post_id, 'timestamp', time());
        
        return new WP_REST_Response(array('success' => true, 'post_id' => $post_id, 'updated' => true), 200);
      } else {
        // Create new document
        $title = $prompt ? wp_trim_words($prompt, 12, '…') : __('Composer Entry', 'luna');
        $post_id = wp_insert_post(array(
          'post_type'   => 'luna_compose',
          'post_title'  => $title,
          'post_status' => 'publish',
        ));
        
        if (!$post_id || is_wp_error($post_id)) {
          return new WP_REST_Response(array('error' => 'Failed to save document'), 500);
        }
        
        update_post_meta($post_id, 'prompt', $prompt);
        update_post_meta($post_id, 'answer', $content);
        update_post_meta($post_id, 'document_id', $document_id);
        update_post_meta($post_id, 'license', $license);
        update_post_meta($post_id, 'timestamp', time());
        
        return new WP_REST_Response(array('success' => true, 'post_id' => $post_id, 'updated' => false), 200);
      }
    },
  ));

  /* --- HISTORY (hydrate UI after reloads) --- */
  /* --- WIDGET HTML FOR EMBEDDING --- */
  register_rest_route('luna_widget/v1', '/widget/html', array(
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function( WP_REST_Request $req ){
      // Add CORS headers for Supercluster embedding
      header('Access-Control-Allow-Origin: *');
      header('Access-Control-Allow-Methods: GET, OPTIONS');
      header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
      
      // Handle preflight OPTIONS request
      if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        status_header(200);
        exit;
      }
      
      // Check for vl_key parameter (for Supercluster embedding with shortcode)
      $vl_key = $req->get_param('vl_key');
      $vl_key = $vl_key ? sanitize_text_field($vl_key) : '';
      
      // Check if plugin is active and has license
      $license = trim((string) get_option(LUNA_WIDGET_OPT_LICENSE, ''));
      
      // If vl_key is provided, validate it matches the stored license
      if ($vl_key !== '') {
        if ($license === '' || $license !== $vl_key) {
          return new WP_REST_Response(array('ok' => false, 'error' => 'License key validation failed'), 403);
        }
        // License matches - proceed even if widget mode is not active
      } else {
        // No vl_key provided - check license and widget mode as before
        if ($license === '') {
          return new WP_REST_Response(array('ok' => false, 'error' => 'No license configured'), 403);
        }
        
        $mode = get_option(LUNA_WIDGET_OPT_MODE, 'widget');
        if ($mode !== 'widget') {
          return new WP_REST_Response(array('ok' => false, 'error' => 'Widget mode not enabled'), 403);
        }
      }
      
      // Get widget settings
      $ui = get_option(LUNA_WIDGET_OPT_SETTINGS, array());
      $pos = isset($ui['position']) ? $ui['position'] : 'bottom-right';
      
      // Generate widget HTML (same as wp_footer)
      $pos_css = 'bottom:20px;right:20px;';
      if ($pos === 'top-left') { $pos_css = 'top:20px;left:20px;'; }
      elseif ($pos === 'top-center') { $pos_css = 'top:20px;left:50%;transform:translateX(-50%);'; }
      elseif ($pos === 'top-right') { $pos_css = 'top:20px;right:20px;'; }
      elseif ($pos === 'bottom-left') { $pos_css = 'bottom:20px;left:20px;'; }
      elseif ($pos === 'bottom-center') { $pos_css = 'bottom:20px;left:50%;transform:translateX(-50%);'; }
      
      $title = esc_html(isset($ui['title']) ? $ui['title'] : 'Luna Chat');
      $avatar = esc_url(isset($ui['avatar_url']) ? $ui['avatar_url'] : '');
      $hdr = esc_html(isset($ui['header_text']) ? $ui['header_text'] : "Hi, I'm Luna");
      $sub = esc_html(isset($ui['sub_text']) ? $ui['sub_text'] : 'How can I help today?');
      
      // For Supercluster embedding, always position panel on the left
      $panel_anchor = (strpos($pos,'bottom') !== false ? 'bottom:80px;' : 'top:80px;')
                    . 'left:2rem;right:auto;';
      
      // Generate CSS
      $css = "
        .luna-fab { position:relative !important; z-index:2147483646 !important; {$pos_css} }
        .luna-launcher{display:flex;align-items:center;gap:10px;background:#111;color:#fff4e9;border:1px solid #5A5753;border-radius:999px;padding:5px 17px 5px 8px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.25);width:100%;max-width:215px;}
        .luna-launcher .ava{width:42px;height:42px;border-radius:50%;background:#222;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;}
        .luna-launcher .txt{line-height:1.2;display:flex;flex-direction:column;flex:1;min-width:0;overflow:hidden;position:relative}
        .luna-launcher .txt span{max-width:222px !important;display:inline-block;white-space:nowrap;overflow:hidden}
        .luna-launcher .txt span.luna-scroll-wrapper{overflow:hidden;position:relative;max-width:130px !important;display:inline-block}
        .luna-launcher .txt span.luna-scroll-inner{display:inline-block;white-space:nowrap;animation:lunaInfiniteScroll 10s infinite linear;will-change:transform;vertical-align: -webkit-baseline-middle;}
        .luna-launcher .txt span.luna-scroll-inner span{display:inline-block;white-space:nowrap;margin:0;padding:0;max-width:none !important}
        @keyframes lunaInfiniteScroll{from{transform:translateX(0%)}to{transform:translateX(-50%)}}
      .luna-overlay{position:fixed !important;top:0 !important;left:0 !important;right:0 !important;bottom:0 !important;background:rgba(0,0,0,0.6) !important;backdrop-filter:blur(8px) !important;-webkit-backdrop-filter:blur(8px) !important;z-index:2147483645 !important;display:none !important;visibility:hidden !important;opacity:0 !important;pointer-events:auto !important;}
      .luna-overlay.show{display:block !important;visibility:visible !important;opacity:1 !important;}
      .luna-panel{position: fixed !important;z-index: 2147483647 !important; {$panel_anchor} width: clamp(320px,92vw,420px);max-height: min(70vh,560px);display: none;flex-direction: column;border-radius: 12px;border: 1px solid #232120;background: #000;color: #fff4e9;overflow: hidden;}
      .luna-panel.show{display:flex !important;z-index: 2147483647 !important;}
        .luna-head{padding:10px 12px;font-weight:600;background:#000;border-bottom:1px solid #333;display:flex;align-items:center;justify-content:space-between}
        .luna-thread{padding:10px 12px;overflow:auto;flex:1 1 auto}
        .luna-form{display:flex;gap:8px;padding:10px 12px;border-top:1px solid #333}
        .luna-input{flex:1 1 auto;background:#111;color:#fff4e9;border:1px solid #333;border-radius:10px;padding:8px 10px}
        .luna-send{background:linear-gradient(270deg, #974C00 0%, #8D8C00 100%) !important;color:#000;border:none;border-radius:10px;padding:8px 12px;cursor:pointer;font-size: .88rem;font-weight: 600}
        .luna-thread .luna-msg{clear:both;margin:6px 0}
        .luna-thread .luna-user{float:right;background:#fff4e9;color:#000;display:inline-block;padding:8px 10px;border-radius:10px;max-width:85%;word-wrap:break-word}
        .luna-thread .luna-assistant{float:left;background:#000000;border:1px solid #2E2C2A;color:#fff4e9;display:inline-block;padding:10px;border-radius:10px;max-width:85%;word-wrap:break-word;line-height:1.25rem;}
        .luna-initial-greeting{display:flex;flex-direction:column;gap:12px}
        .luna-greeting-text{margin-bottom:10px;line-height: 1.25rem;}
        .luna-greeting-buttons{display:flex;flex-direction:column;gap:8px;width:100%}
        .luna-greeting-btn{width:100%;padding:10px 14px;background:#2E2C2A;border:none;border-radius:8px;color:#fff4e9;font-size:0.9rem;font-weight:600;cursor:pointer;transition:all 0.2s ease;text-align:left;display:flex;align-items:center;justify-content:space-between}
        .luna-greeting-btn:hover{background:#3A3836;transform:translateY(-1px)}
        .luna-greeting-btn:active{transform:translateY(0)}
        .luna-greeting-btn-chat{background:linear-gradient(270deg, #974C00 0%, #8D8C00 100%);color:#000;border-color:#5A5753}
        .luna-greeting-btn-chat:hover{background:linear-gradient(270deg, #B85C00 0%, #A5A000 100%)}
        .luna-greeting-btn-report{background:#2E2C2A}
        .luna-greeting-btn-compose{background:#2E2C2A}
        .luna-greeting-btn-automate{background:#2E2C2A}
        .luna-greeting-help{width:20px;height:20px;border-radius:50%;background-color:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:600;cursor:help;margin-left:8px;flex-shrink:0;transition:background-color 0.2s ease}
        .luna-greeting-help:hover{background-color:rgba(255,255,255,.25)}
        .luna-greeting-tooltip{position:absolute;background:#000;color:#fff4e9;padding:12px 16px;border-radius:8px;font-size:0.85rem;line-height:1.4;max-width:280px;z-index:999999;box-shadow:0 4px 12px rgba(0,0,0,0.4);pointer-events:none;border:1px solid #5A5753;word-wrap:break-word}
        .luna-thread .luna-session-closure{opacity:.85;font-style:italic}
        .luna-thread .luna-loading{float:left;background:#111;border:1px solid #333;color:#fff4e9;display:inline-block;padding:8px 10px;border-radius:10px;max-width:85%;word-wrap:break-word}
        .luna-loading-text{background:radial-gradient(circle at 0%,#fff4e9,#c2b8ad 50%,#978e86 75%,#fff4e9 75%);font-weight:600;background-size:200% auto;color:#000;background-clip:text;-webkit-text-fill-color:transparent;animation:animatedTextGradient 1.5s linear infinite;height:20px !important;}
        @keyframes animatedTextGradient{from{background-position:0% center}to{background-position:200% center}}
        .luna-session-ended{position:fixed;z-index:999999 !important;left:2rem !important;right:auto !important;width:clamp(320px,92vw,420px);display:none;align-items:center;justify-content:center}
        .luna-session-ended.show{display:flex}
        .luna-session-ended-card{background:#000;border:1px solid #5A5753;border-radius:12px;padding:24px 20px;display:flex;flex-direction:column;gap:12px;align-items:center;text-align:center;box-shadow:0 24px 48px rgba(0,0,0,.4);width:100%}
        .luna-session-ended-card h2{margin:0;font-size:1.25rem}
        .luna-session-ended-card p{margin:0;color:#ccc}
        .luna-session-ended-card .luna-session-restart{background:#2c74ff;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-weight:600;cursor:pointer}
        .luna-session-ended-card .luna-session-restart:hover{background:#4c8bff}
      ";
      
      // Generate HTML
      $html = '
        <div class="luna-fab" aria-live="polite">
          <button class="luna-launcher" aria-expanded="false" aria-controls="luna-panel" title="' . $title . '">
            <span class="ava">
              ' . ($avatar ? '<img src="' . $avatar . '" alt="" style="width:42px;height:42px;object-fit:cover">' : '
                <svg width="24" height="24" viewBox="0 0 36 36" fill="none" aria-hidden="true"><circle cx="18" cy="18" r="18" fill="#222"/><path d="M18 18a6 6 0 100-12 6 6 0 000 12zm0 2c-6 0-10 3.2-10 6v2h20v-2c0-2.8-4-6-10-6z" fill="#666"/></svg>
              ') . '
            </span>
            <span class="txt"><strong>' . $hdr . '</strong><span>' . $sub . '</span></span>
          </button>
          <div id="luna-panel" class="luna-panel" role="dialog" aria-label="' . $title . '">
            <div class="luna-head"><span>' . $title . '</span><button class="luna-close" style="background:transparent;border:0;color:#fff;cursor:pointer" aria-label="Close">✕</button></div>
            <div class="luna-thread"></div>
            <form class="luna-form"><input class="luna-input" placeholder="Ask Luna…" autocomplete="off"><button type="button" class="luna-send">Send</button></form>
          </div>
        </div>
        <div id="luna-session-ended" class="luna-session-ended" style="' . $panel_anchor . ' display:none;" role="dialog" aria-modal="true" aria-labelledby="luna-session-ended-title">
          <div class="luna-session-ended-card">
            <h2 id="luna-session-ended-title">Your session has ended</h2>
            <p>Start another one now.</p>
            <button type="button" class="luna-session-restart">Start New Session</button>
          </div>
        </div>
      ';
      
      // Generate JavaScript - full widget functionality
      $chat_endpoint = rest_url('luna_widget/v1/chat');
      $history_endpoint = rest_url('luna_widget/v1/chat/history');
      $inactive_endpoint = rest_url('luna_widget/v1/chat/inactive');
      $session_end_endpoint = rest_url('luna_widget/v1/chat/session/end');
      $session_reset_endpoint = rest_url('luna_widget/v1/chat/session/reset');
      $nonce = wp_create_nonce('wp_rest');
      $stored_license_key = esc_js( get_option(LUNA_WIDGET_OPT_LICENSE, '') );
      
      // Get button descriptions
      $button_desc_chat = esc_js( isset($ui['button_desc_chat']) ? $ui['button_desc_chat'] : 'Start a conversation with Luna to ask questions and get answers about your digital universe.' );
      $button_desc_report = esc_js( isset($ui['button_desc_report']) ? $ui['button_desc_report'] : 'Generate comprehensive reports about your site health, performance, and security.' );
      $button_desc_compose = esc_js( isset($ui['button_desc_compose']) ? $ui['button_desc_compose'] : 'Access Luna Composer to use canned prompts and responses for quick interactions.' );
      $button_desc_automate = esc_js( isset($ui['button_desc_automate']) ? $ui['button_desc_automate'] : 'Set up automated workflows and tasks with Luna to streamline your operations.' );
      
      $widget_js = "
        (function(){
          var fab=document.querySelector('.luna-launcher');
          var panel=document.querySelector('#luna-panel');
          
          // Setup scrolling text animation for launcher subtitle
          function setupLauncherTextScroll() {
            var txtSpan = fab ? fab.querySelector('.txt span:not(strong)') : null;
            if (txtSpan) {
              // Measure text width
              var tempSpan = document.createElement('span');
              tempSpan.style.visibility = 'hidden';
              tempSpan.style.position = 'absolute';
              tempSpan.style.whiteSpace = 'nowrap';
              tempSpan.textContent = txtSpan.textContent;
              document.body.appendChild(tempSpan);
              var textWidth = tempSpan.offsetWidth;
              document.body.removeChild(tempSpan);
              
              // If text is wider than 135px, add infinite scrolling animation
              if (textWidth > 135) {
                // Store original text
                var originalText = txtSpan.textContent;
                
                // Create wrapper and inner structure for infinite scroll
                var wrapper = document.createElement('span');
                wrapper.className = 'luna-scroll-wrapper';
                
                var inner = document.createElement('span');
                inner.className = 'luna-scroll-inner';
                
                // Duplicate text 4 times for seamless infinite loop
                for (var i = 0; i < 4; i++) {
                  var textSpan = document.createElement('span');
                  textSpan.textContent = originalText + ' ';
                  inner.appendChild(textSpan);
                }
                
                wrapper.appendChild(inner);
                
                // Replace original span with new structure
                txtSpan.parentNode.replaceChild(wrapper, txtSpan);
                
                // Calculate animation duration based on text width
                // Scroll speed ~35px per second (30% slower than 50px/s)
                // We need to scroll by one full text width to reveal the entire text
                var scrollDistance = textWidth; // Scroll distance is the full text width
                var scrollTime = scrollDistance / 35; // Time to scroll one full text width
                var pauseTime = 2; // 2 second pause
                var totalDuration = scrollTime + pauseTime;
                var pausePercent = (pauseTime / totalDuration) * 100; // Percentage for pause
                var scrollStartPercent = pausePercent; // Start scrolling after pause
                
                // Calculate the pixel value to scroll (one full text width)
                var scrollPixels = -textWidth;
                
                // Create unique animation name
                var animName = 'lunaInfiniteScroll_' + Date.now();
                
                // Create dynamic keyframes: pause at start, then scroll by one full text width
                // Since we have 4 copies, scrolling by one width creates seamless loop
                var style = document.createElement('style');
                style.id = 'luna-scroll-animation-' + Date.now();
                style.textContent = '@keyframes ' + animName + '{0%{transform:translateX(0)}' + scrollStartPercent + '%{transform:translateX(0)}100%{transform:translateX(' + scrollPixels + 'px)}}';
                document.head.appendChild(style);
                
                // Apply animation with calculated duration
                inner.style.animation = animName + ' ' + totalDuration + 's infinite linear';
              }
            }
          }
          
          // Initialize text scroll after DOM is ready
          if (fab) {
            setTimeout(setupLauncherTextScroll, 100);
          }
          
          // Function to blur Supercluster elements
          function blurSuperclusterElements(blur) {
            // Blur canvas
            var canvas = document.querySelector('canvas');
            if (canvas) {
              if (blur) {
                canvas.style.setProperty('filter', 'blur(8px)', 'important');
                canvas.style.setProperty('pointer-events', 'none', 'important');
              } else {
                canvas.style.removeProperty('filter');
                canvas.style.setProperty('pointer-events', 'inherit', 'important');
              }
            }
            
            // Blur #vlSuperclusterRoot::after (using a style element)
            var root = document.querySelector('#vlSuperclusterRoot');
            if (root) {
              var styleId = 'luna-blur-root-after';
              var existingStyle = document.getElementById(styleId);
              if (blur) {
                if (!existingStyle) {
                  var style = document.createElement('style');
                  style.id = styleId;
                  style.textContent = '#vlSuperclusterRoot::after { filter: blur(8px) !important; pointer-events: none !important; }';
                  document.head.appendChild(style);
                }
              } else {
                if (existingStyle) {
                  existingStyle.remove();
                }
              }
            }
            
            // Blur .vl-supercluster-labels
            var labels = document.querySelectorAll('.vl-supercluster-labels');
            if (labels && labels.length > 0) {
              labels.forEach(function(label) {
                if (blur) {
                  label.style.setProperty('filter', 'blur(8px)', 'important');
                  label.style.setProperty('pointer-events', 'none', 'important');
                } else {
                  label.style.removeProperty('filter');
                  label.style.setProperty('pointer-events', 'inherit', 'important');
                }
              });
            }
            
            // Blur .vl-header
            var header = document.querySelector('.vl-header');
            if (header) {
              if (blur) {
                header.style.setProperty('filter', 'blur(8px)', 'important');
                header.style.setProperty('pointer-events', 'none', 'important');
              } else {
                header.style.removeProperty('filter');
                header.style.setProperty('pointer-events', 'inherit', 'important');
              }
            }
            
            // Blur .vl-main-menu
            var mainMenu = document.querySelector('.vl-main-menu');
            if (mainMenu) {
              if (blur) {
                mainMenu.style.setProperty('filter', 'blur(8px)', 'important');
                mainMenu.style.setProperty('pointer-events', 'none', 'important');
              } else {
                mainMenu.style.removeProperty('filter');
                mainMenu.style.setProperty('pointer-events', 'inherit', 'important');
              }
            }
            
            // Blur .vl-right-sidebar
            var rightSidebar = document.querySelector('.vl-right-sidebar');
            if (rightSidebar) {
              if (blur) {
                rightSidebar.style.setProperty('filter', 'blur(8px)', 'important');
                rightSidebar.style.setProperty('pointer-events', 'none', 'important');
              } else {
                rightSidebar.style.removeProperty('filter');
                rightSidebar.style.setProperty('pointer-events', 'inherit', 'important');
              }
            }
          }
          
          // Ensure panel parent doesn't create stacking context
          if (panel && panel.parentNode && panel.parentNode !== document.body) {
            var panelParent = panel.parentNode;
            // Check computed styles to see if parent creates stacking context
            var computedStyle = window.getComputedStyle(panelParent);
            if (computedStyle.position !== 'static' || computedStyle.zIndex !== 'auto') {
              // Force parent to not create stacking context
              panelParent.style.setProperty('position', 'static', 'important');
              panelParent.style.setProperty('z-index', 'auto', 'important');
              panelParent.style.setProperty('isolation', 'auto', 'important');
            }
          }
          var closeBtn=document.querySelector('.luna-close');
          var ended=document.querySelector('#luna-session-ended');
          var thread=panel ? panel.querySelector('.luna-thread') : null;
          var form=panel ? panel.querySelector('.luna-form') : null;
          var input=form ? form.querySelector('.luna-input') : null;
          var sendBtn=form ? form.querySelector('.luna-send') : null;

          async function hydrate(thread){
            if (!thread || thread.__hydrated) return;
            try{
              const res = await fetch('{$history_endpoint}');
              const data = await res.json();
              var hasMessages = false;
              if (data && Array.isArray(data.items)) {
                data.items.forEach(function(turn){
                  if (turn.user) { var u=document.createElement('div'); u.className='luna-msg luna-user'; u.textContent=turn.user; thread.appendChild(u); hasMessages = true; }
                  if (turn.assistant) { var a=document.createElement('div'); a.className='luna-msg luna-assistant'; a.textContent=turn.assistant; thread.appendChild(a); hasMessages = true; }
                });
                thread.scrollTop = thread.scrollHeight;
              }
              thread.__hydrated = true;
              
              // Auto-send initial greeting if thread is empty
              if (!hasMessages) {
                setTimeout(function(){
                  sendInitialGreeting(thread);
                }, 300);
              }
            }catch(e){ 
              console.warn('[Luna] hydrate failed', e);
              // If hydration fails, still try to send greeting if thread is empty
              if (!thread.__hydrated && thread.children.length === 0) {
                setTimeout(function(){
                  sendInitialGreeting(thread);
                }, 300);
              }
            }
            finally { thread.__hydrated = true; }
          }
          
          function sendInitialGreeting(thread){
            if (!thread) return;
            // Check if thread already has messages
            if (thread.querySelectorAll('.luna-msg').length > 0) return;
            
            // Get license key from URL or stored option
            var licenseKey = '';
            var urlParams = new URLSearchParams(window.location.search);
            var urlLicense = urlParams.get('license');
            if (urlLicense) {
              // Extract license key from URL (may include path segments)
              licenseKey = urlLicense.split('/')[0];
            } else {
              // Try to get from stored option (for frontend sites)
              try {
                var storedLicense = '{$stored_license_key}';
                if (storedLicense && storedLicense !== '') {
                  licenseKey = storedLicense;
                }
              } catch(e) {
                console.warn('[Luna] Could not get stored license key');
              }
            }
            
            // Create greeting message with buttons
            var greetingEl = document.createElement('div');
            greetingEl.className = 'luna-msg luna-assistant luna-initial-greeting';
            
            var greetingText = document.createElement('div');
            greetingText.className = 'luna-greeting-text';
            greetingText.textContent = 'Hi, there! I\'m Luna, your personal WebOps agent and AI companion. How would you like to continue?';
            greetingEl.appendChild(greetingText);
            
            // Create buttons container
            var buttonsContainer = document.createElement('div');
            buttonsContainer.className = 'luna-greeting-buttons';
            
            // Get button descriptions from settings
            var buttonDescs = {
              chat: '{$button_desc_chat}',
              report: '{$button_desc_report}',
              compose: '{$button_desc_compose}',
              automate: '{$button_desc_automate}'
            };
            
            // Helper function to create button with question mark icon
            function createGreetingButton(text, className, description, clickHandler) {
              var btn = document.createElement('button');
              btn.className = 'luna-greeting-btn ' + className;
              btn.style.position = 'relative';
              btn.style.display = 'flex';
              btn.style.alignItems = 'center';
              btn.style.justifyContent = 'space-between';
              btn.style.padding = '10px 14px';
              
              var btnText = document.createElement('span');
              btnText.textContent = text;
              btnText.style.flex = '1';
              btn.appendChild(btnText);
              
              var questionMark = document.createElement('span');
              questionMark.className = 'luna-greeting-help';
              questionMark.textContent = '?';
              questionMark.style.cssText = 'width:20px;height:20px;border-radius:50%;background-color:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:600;cursor:help;margin-left:8px;flex-shrink:0;transition:background-color 0.2s ease;';
              questionMark.setAttribute('data-description', description);
              questionMark.setAttribute('aria-label', 'Help');
              questionMark.addEventListener('mouseenter', function(e){
                e.stopPropagation();
                showButtonTooltip(questionMark, description);
              });
              questionMark.addEventListener('mouseleave', function(e){
                e.stopPropagation();
                hideButtonTooltip();
              });
              btn.appendChild(questionMark);
              
              btn.addEventListener('click', clickHandler);
              return btn;
            }
            
            // Luna Chat button
            var chatBtn = createGreetingButton('Luna Chat', 'luna-greeting-btn-chat', buttonDescs.chat, function(e){
              e.preventDefault();
              e.stopPropagation();
              // Remove greeting buttons
              greetingEl.querySelector('.luna-greeting-buttons').remove();
              // Auto-reply with chat message
              var chatMsg = document.createElement('div');
              chatMsg.className = 'luna-msg luna-assistant';
              chatMsg.textContent = 'Let\'s do this! Ask me anything to begin exploring...';
              thread.appendChild(chatMsg);
              thread.scrollTop = thread.scrollHeight;
            });
            buttonsContainer.appendChild(chatBtn);
            
            // Luna Report button
            var reportBtn = createGreetingButton('Luna Report', 'luna-greeting-btn-report', buttonDescs.report, function(e){
              e.preventDefault();
              e.stopPropagation();
              if (licenseKey) {
                window.location.href = 'https://supercluster.visiblelight.ai/?license=' + encodeURIComponent(licenseKey) + '/luna/report/';
              } else {
                console.warn('[Luna] License key not available for redirect');
              }
            });
            buttonsContainer.appendChild(reportBtn);
            
            // Luna Composer button
            var composeBtn = createGreetingButton('Luna Compose', 'luna-greeting-btn-compose', buttonDescs.compose, function(e){
              e.preventDefault();
              e.stopPropagation();
              if (licenseKey) {
                window.location.href = 'https://supercluster.visiblelight.ai/?license=' + encodeURIComponent(licenseKey) + '/luna/compose/';
              } else {
                console.warn('[Luna] License key not available for redirect');
              }
            });
            buttonsContainer.appendChild(composeBtn);
            
            // Luna Automate button
            var automateBtn = createGreetingButton('Luna Automate', 'luna-greeting-btn-automate', buttonDescs.automate, function(e){
              e.preventDefault();
              e.stopPropagation();
              if (licenseKey) {
                window.location.href = 'https://supercluster.visiblelight.ai/?license=' + encodeURIComponent(licenseKey) + '/luna/automate/';
              } else {
                console.warn('[Luna] License key not available for redirect');
              }
            });
            buttonsContainer.appendChild(automateBtn);
            
            // Tooltip functions
            var tooltip = null;
            function showButtonTooltip(element, description) {
              if (tooltip) {
                tooltip.remove();
              }
              tooltip = document.createElement('div');
              tooltip.className = 'luna-greeting-tooltip';
              tooltip.textContent = description;
              tooltip.style.cssText = 'position:absolute;background:#000;color:#fff4e9;padding:12px 16px;border-radius:8px;font-size:0.85rem;line-height:1.4;max-width:280px;z-index:999999;box-shadow:0 4px 12px rgba(0,0,0,0.4);pointer-events:none;border:1px solid #5A5753;';
              document.body.appendChild(tooltip);
              
              var rect = element.getBoundingClientRect();
              var tooltipRect = tooltip.getBoundingClientRect();
              var left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
              var top = rect.top - tooltipRect.height - 8;
              
              if (left < 10) left = 10;
              if (left + tooltipRect.width > window.innerWidth - 10) {
                left = window.innerWidth - tooltipRect.width - 10;
              }
              if (top < 10) {
                top = rect.bottom + 8;
              }
              
              tooltip.style.left = left + 'px';
              tooltip.style.top = top + 'px';
            }
            
            function hideButtonTooltip() {
              if (tooltip) {
                tooltip.remove();
                tooltip = null;
              }
            }
            
            greetingEl.appendChild(buttonsContainer);
            thread.appendChild(greetingEl);
            thread.scrollTop = thread.scrollHeight;
            
            // Log the greeting to the conversation
            try {
              fetch('{$chat_endpoint}', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ prompt: '', greeting: true })
              }).catch(function(err){
                console.warn('[Luna] greeting log failed', err);
              });
            } catch(err){
              console.warn('[Luna] greeting fetch error', err);
            }
          }
          function showEnded(){
            if (ended) ended.classList.add('show');
            if (panel) panel.classList.remove('show');
            blurSuperclusterElements(false);
            if (fab) fab.setAttribute('aria-expanded','false');
          }
          function hideEnded(){
            if (ended) ended.classList.remove('show');
          }
          window.__lunaShowSessionEnded = showEnded;
          window.__lunaHideSessionEnded = hideEnded;
          function toggle(open){
            if(!panel||!fab) return;
            var will=(typeof open==='boolean')?open:!panel.classList.contains('show');
            // Only show ended state if explicitly closing and session is actually closing
            if (will && window.LunaChatSession && window.LunaChatSession.closing === true) {
              showEnded();
              return;
            }
            if (will && ended) ended.classList.remove('show');
            
            // Use requestAnimationFrame to ensure smooth display
            requestAnimationFrame(function(){
              // Set z-index BEFORE toggling classes
              if (will) {
                // Ensure fab has highest z-index
                var fabContainer = fab ? (fab.closest('.luna-fab') || fab.parentElement) : null;
                if (fabContainer) {
                  fabContainer.style.setProperty('z-index', '2147483647', 'important');
                  fabContainer.style.setProperty('position', 'fixed', 'important');
                }
                if (fab) {
                  fab.style.setProperty('z-index', '2147483647', 'important');
                }
              }
              
              panel.classList.toggle('show',will);
              
              // Blur/unblur Supercluster elements
              blurSuperclusterElements(will);
              fab.setAttribute('aria-expanded',will?'true':'false');
              
              // Hide/show galaxy labels
              var labels = document.querySelectorAll('.vl-supercluster-labels');
              if (labels && labels.length > 0) {
                labels.forEach(function(label){
                  if (will) {
                    label.style.display = 'none';
                    label.style.visibility = 'hidden';
                    label.style.opacity = '0';
                  } else {
                    label.style.display = '';
                    label.style.visibility = '';
                    label.style.opacity = '';
                  }
                });
              }
              
              if (will) {
                // Force panel to stay visible with highest z-index using !important
                panel.style.setProperty('z-index', '2147483647', 'important');
                panel.style.setProperty('position', 'fixed', 'important');
                panel.style.setProperty('display', 'flex', 'important');
                panel.style.setProperty('visibility', 'visible', 'important');
                panel.style.setProperty('opacity', '1', 'important');
                
                // Ensure fab has highest z-index and is positioned correctly with !important
                var fabContainer = fab ? (fab.closest('.luna-fab') || fab.parentElement) : null;
                if (fabContainer) {
                  fabContainer.style.setProperty('z-index', '2147483647', 'important');
                  fabContainer.style.setProperty('position', 'fixed', 'important');
                }
                if (fab) {
                  fab.style.setProperty('z-index', '2147483647', 'important');
                }
                
                // Hydrate after a small delay to ensure panel is visible
                setTimeout(function(){
                  hydrate(panel.querySelector('.luna-thread'));
                  if (window.LunaChatSession && typeof window.LunaChatSession.onPanelToggle === 'function') {
                    window.LunaChatSession.onPanelToggle(true);
                  }
                }, 50);
              } else {
                // Explicitly hide panel when closing
                panel.style.setProperty('display', 'none', 'important');
                panel.style.setProperty('visibility', 'hidden', 'important');
                panel.style.setProperty('opacity', '0', 'important');
                if (window.LunaChatSession && typeof window.LunaChatSession.onPanelToggle === 'function') {
                  window.LunaChatSession.onPanelToggle(false);
                }
                hideEnded();
              }
            });
          }
          // Prevent panel clicks from bubbling to overlay (but allow close button)
          if(panel) panel.addEventListener('click', function(e){
            // Don't stop propagation if clicking the close button
            if (!e.target.closest('.luna-close')) {
              e.stopPropagation();
            }
          });
          
          if(fab) fab.addEventListener('click', function(e){ 
            e.stopPropagation(); 
            // If panel is already showing, close it; otherwise open it
            if (panel && panel.classList.contains('show')) {
              toggle(false);
            } else {
              toggle(true);
            }
          });
          // Use event delegation for close button to ensure it always works
          document.addEventListener('click', function(e){
            if (e.target && e.target.classList.contains('luna-close')) {
              e.preventDefault();
              e.stopPropagation();
              console.log('[Luna] Close button clicked');
              toggle(false);
            }
          });
          if(ended){
            var restartBtn = ended.querySelector('.luna-session-restart');
            if (restartBtn) restartBtn.addEventListener('click', function(){
              if (window.LunaChatSession && typeof window.LunaChatSession.restartSession === 'function') {
                window.LunaChatSession.restartSession();
              }
            });
          }
          document.addEventListener('keydown', function(e){
            if(e.key==='Escape'){
              toggle(false);
              hideEnded();
            }
          });
          
          // Full chat functionality with session management
          var sessionState = {
            inactivityDelay: 120000,
            closureDelay: 180000,
            inactivityTimer: null,
            closureTimer: null,
            closing: false,
            restarting: false
          };
          
          function markActivity() {
            if (sessionState.closing) return;
            if (sessionState.inactivityTimer) clearTimeout(sessionState.inactivityTimer);
            if (sessionState.closureTimer) clearTimeout(sessionState.closureTimer);
            if (thread) thread.__inactiveWarned = false;
            sessionState.inactivityTimer = setTimeout(handleInactivityWarning, sessionState.inactivityDelay);
          }
          
          function handleInactivityWarning() {
            sessionState.inactivityTimer = null;
            if (sessionState.closing) return;
            var message = 'I haven\\'t heard from you in a while, are you still there? If not, I\\'ll close out this chat automatically in 3 minutes.';
            if (thread && !thread.__inactiveWarned) {
              thread.__inactiveWarned = true;
              var warning = document.createElement('div');
              warning.className = 'luna-msg luna-assistant';
              warning.textContent = message;
              thread.appendChild(warning);
              thread.scrollTop = thread.scrollHeight;
            }
            try {
              fetch('{$inactive_endpoint}', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ message: message })
              }).catch(function(err){ console.warn('[Luna] inactive log failed', err); });
            } catch (err) { console.warn('[Luna] inactive fetch error', err); }
            sessionState.closureTimer = setTimeout(handleSessionClosure, sessionState.closureDelay);
          }
          
          function handleSessionClosure() {
            sessionState.closureTimer = null;
            if (sessionState.closing) return;
            sessionState.closing = true;
            if (sessionState.inactivityTimer) clearTimeout(sessionState.inactivityTimer);
            var message = 'This chat session has been closed due to inactivity.';
            if (thread && !thread.__sessionClosed) {
              thread.__sessionClosed = true;
              var closure = document.createElement('div');
              closure.className = 'luna-msg luna-assistant luna-session-closure';
              closure.textContent = message;
              thread.appendChild(closure);
              thread.scrollTop = thread.scrollHeight;
            }
            if (input) input.disabled = true;
            if (sendBtn) sendBtn.disabled = true;
            showEnded();
            try {
              fetch('{$session_end_endpoint}', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ reason: 'inactivity', message: message })
              }).catch(function(err){ console.warn('[Luna] session end failed', err); });
            } catch (err) { console.warn('[Luna] session end error', err); }
          }
          
          function restartSession() {
            if (sessionState.restarting) return;
            sessionState.restarting = true;
            if (sessionState.inactivityTimer) clearTimeout(sessionState.inactivityTimer);
            if (sessionState.closureTimer) clearTimeout(sessionState.closureTimer);
            var restartBtn = ended ? ended.querySelector('.luna-session-restart') : null;
            if (restartBtn) restartBtn.disabled = true;
            try {
              fetch('{$session_reset_endpoint}', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ reason: 'user_restart' })
              })
              .then(function(){
                sessionState.closing = false;
                if (input) input.disabled = false;
                if (sendBtn) sendBtn.disabled = false;
                hideEnded();
                if (thread) {
                  thread.innerHTML = '';
                  thread.__hydrated = false;
                  thread.__inactiveWarned = false;
                  thread.__sessionClosed = false;
                }
                if (panel) panel.classList.add('show');
                if (fab) fab.setAttribute('aria-expanded','true');
                if (input) input.focus();
                markActivity();
              })
              .catch(function(err){ console.error('[Luna] session reset failed', err); })
              .finally(function(){
                sessionState.restarting = false;
                if (restartBtn) restartBtn.disabled = false;
              });
            } catch (err) {
              console.error('[Luna] session reset error', err);
              sessionState.restarting = false;
              if (restartBtn) restartBtn.disabled = false;
            }
          }
          
          function submitFrom(input, thread){
            if (!input || !thread) return;
            if (sessionState.closing) {
              showEnded();
              return;
            }
            var msg = (input.value || '').trim();
            if (!msg) return;
            
            markActivity();
            
            // Clear input and display user message
            input.value = '';
            input.disabled = true;
            if (sendBtn) sendBtn.disabled = true;
            
            var userMsg = document.createElement('div');
            userMsg.className = 'luna-msg luna-user';
            userMsg.textContent = msg;
            thread.appendChild(userMsg);
            thread.scrollTop = thread.scrollHeight;
            
            // Show loading animation
            var loading = document.createElement('div');
            loading.className = 'luna-msg luna-loading';
            var loadingText = document.createElement('span');
            loadingText.className = 'luna-loading-text';
            loadingText.textContent = 'Luna is considering all possibilities...';
            loading.appendChild(loadingText);
            thread.appendChild(loading);
            thread.scrollTop = thread.scrollHeight;
            
            // Send to API
            fetch('{$chat_endpoint}', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '{$nonce}'
              },
              credentials: 'same-origin',
              body: JSON.stringify({ prompt: msg })
            })
            .then(function(r){ return r.json().catch(function(){return {};}); })
            .then(function(data){
              loading.remove();
              var msg = (data && data.answer) ? data.answer : (data.error ? ('Error: '+data.error) : 'Sorry—no response.');
              var assistantMsg = document.createElement('div');
              assistantMsg.className = 'luna-msg luna-assistant';
              assistantMsg.textContent = msg;
              thread.appendChild(assistantMsg);
              thread.scrollTop = thread.scrollHeight;
            })
            .catch(function(err){
              loading.remove();
              var errorMsg = document.createElement('div');
              errorMsg.className = 'luna-msg luna-assistant';
              errorMsg.textContent = 'Network error. Please try again.';
              thread.appendChild(errorMsg);
              thread.scrollTop = thread.scrollHeight;
              console.error('[Luna]', err);
            })
            .finally(function(){
              input.disabled = false;
              if (sendBtn) sendBtn.disabled = false;
              input.focus();
              markActivity();
            });
          }
          
          if (form && input && sendBtn) {
            form.addEventListener('submit', function(e){
              e.preventDefault();
              e.stopPropagation();
              submitFrom(input, thread);
            });
            sendBtn.addEventListener('click', function(e){
              e.preventDefault();
              e.stopPropagation();
              submitFrom(input, thread);
            });
            input.addEventListener('keydown', function(e){
              if (sessionState.closing) {
                e.preventDefault();
                showEnded();
                return;
              }
              if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                e.stopPropagation();
                submitFrom(input, thread);
              }
              markActivity();
            });
            input.addEventListener('focus', markActivity);
            input.addEventListener('input', markActivity);
            form.addEventListener('pointerdown', markActivity);
          }
          
          // Initialize activity tracking
          markActivity();
          
          // Make session management available globally
          window.LunaChatSession = sessionState;
          window.LunaChatSession.restartSession = restartSession;
          window.LunaChatSession.onPanelToggle = function(open) {
            if (open && !sessionState.closing) {
              markActivity();
            }
          };
        })();
      ";
      
      return new WP_REST_Response(array(
        'ok' => true,
        'html' => $html,
        'css' => $css,
        'js' => $widget_js,
        'rest_url' => rest_url('luna_widget/v1/'),
        'nonce' => wp_create_nonce('wp_rest')
      ), 200);
    },
  ));

  register_rest_route('luna_widget/v1', '/chat/history', array(
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function( WP_REST_Request $req ){
      $pid = luna_conv_id();
      if (!$pid) return new WP_REST_Response(array('items'=>array()), 200);
      $t = get_post_meta($pid, 'transcript', true); if (!is_array($t)) $t = array();
      $limit = max(1, min(50, (int)$req->get_param('limit') ? (int)$req->get_param('limit') : 20));
      $slice = array_slice($t, -$limit);
      $items = array();
      foreach ($slice as $row) {
        $items[] = array(
          'ts'        => isset($row['ts']) ? (int)$row['ts'] : 0,
          'ts_iso'    => !empty($row['ts']) ? gmdate('c', (int)$row['ts']) : null,
          'user'      => isset($row['user']) ? wp_strip_all_tags((string)$row['user']) : '',
          'assistant' => isset($row['assistant']) ? wp_strip_all_tags((string)$row['assistant']) : '',
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  /* --- Hub-facing list endpoints (license-gated) --- */
  $secure_cb = function(){ return true; };

  // System snapshot (plugins/themes summary here)
  register_rest_route('luna_widget/v1', '/system/site', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      return new WP_REST_Response( luna_snapshot_system(), 200 );
    },
  ));
  // Aliases some hubs expect
  register_rest_route('vl-hub/v1', '/system/site', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      return new WP_REST_Response( luna_snapshot_system(), 200 );
    },
  ));

  // Enhanced Posts endpoint with SEO scores, meta data, and detailed author info
  $posts_cb = function( WP_REST_Request $req ){
    if (!luna_license_ok($req)) return luna_forbidden();
    $per  = max(1, min(200, (int)$req->get_param('per_page') ?: 50));
    $page = max(1, (int)$req->get_param('page') ?: 1);
    $q = new WP_Query(array(
      'post_type'      => 'post',
      'post_status'    => 'publish',
      'posts_per_page' => $per,
      'paged'          => $page,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
    ));
    $items = array();
    foreach ($q->posts as $pid) {
      $cats = wp_get_post_terms($pid, 'category', array('fields'=>'names'));
      $tags = wp_get_post_terms($pid, 'post_tag', array('fields'=>'names'));
      $author_id = get_post_field('post_author', $pid);
      $author = get_user_by('id', $author_id);
      
      // Get meta data
      $meta_data = get_post_meta($pid);
      
      // Calculate SEO score (basic implementation)
      $seo_score = luna_calculate_seo_score($pid);
      
      $items[] = array(
        'id'        => $pid,
        'title'     => get_the_title($pid),
        'slug'      => get_post_field('post_name', $pid),
        'date'      => get_post_time('c', true, $pid),
        'author'    => array(
          'id' => $author_id,
          'username' => $author ? $author->user_login : 'Unknown',
          'email' => $author ? $author->user_email : '',
          'display_name' => $author ? $author->display_name : 'Unknown'
        ),
        'categories'=> array_values($cats ?: array()),
        'tags'      => array_values($tags ?: array()),
        'permalink' => get_permalink($pid),
        'meta_data' => $meta_data,
        'seo_score' => $seo_score,
        'status'    => get_post_status($pid),
        'comment_count' => get_comments_number($pid),
        'featured_image' => get_the_post_thumbnail_url($pid, 'full')
      );
    }
    return new WP_REST_Response(array('total'=>(int)$q->found_posts,'page'=>$page,'per_page'=>$per,'items'=>$items), 200);
  };
  register_rest_route('luna_widget/v1', '/content/posts', array('methods'=>'GET','permission_callback'=>$secure_cb,'callback'=>$posts_cb));
  register_rest_route('vl-hub/v1',      '/posts',         array('methods'=>'GET','permission_callback'=>$secure_cb,'callback'=>$posts_cb));

  // Enhanced Pages endpoint with SEO scores, meta data, and detailed author info
  $pages_cb = function( WP_REST_Request $req ){
    if (!luna_license_ok($req)) return luna_forbidden();
    $per  = max(1, min(200, (int)$req->get_param('per_page') ?: 50));
    $page = max(1, (int)$req->get_param('page') ?: 1);
    $q = new WP_Query(array(
      'post_type'      => 'page',
      'post_status'    => array('publish', 'draft', 'private', 'pending'),
      'posts_per_page' => $per,
      'paged'          => $page,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
    ));
    $items = array();
    foreach ($q->posts as $pid) {
      $author_id = get_post_field('post_author', $pid);
      $author = get_user_by('id', $author_id);
      
      // Get meta data
      $meta_data = get_post_meta($pid);
      
      // Calculate SEO score (basic implementation)
      $seo_score = luna_calculate_seo_score($pid);
      
      $items[] = array(
        'id'        => $pid,
        'title'     => get_the_title($pid),
        'slug'      => get_post_field('post_name', $pid),
        'status'    => get_post_status($pid),
        'date'      => get_post_time('c', true, $pid),
        'author'    => array(
          'id' => $author_id,
          'username' => $author ? $author->user_login : 'Unknown',
          'email' => $author ? $author->user_email : '',
          'display_name' => $author ? $author->display_name : 'Unknown'
        ),
        'permalink' => get_permalink($pid),
        'meta_data' => $meta_data,
        'seo_score' => $seo_score,
        'comment_count' => get_comments_number($pid),
        'featured_image' => get_the_post_thumbnail_url($pid, 'full'),
        'parent' => get_post_field('post_parent', $pid)
      );
    }
    return new WP_REST_Response(array('total'=>(int)$q->found_posts,'page'=>$page,'per_page'=>$per,'items'=>$items), 200);
  };
  register_rest_route('luna_widget/v1', '/content/pages', array('methods'=>'GET','permission_callback'=>$secure_cb,'callback'=>$pages_cb));
  register_rest_route('vl-hub/v1',      '/pages',         array('methods'=>'GET','permission_callback'=>$secure_cb,'callback'=>$pages_cb));

  // Enhanced Users endpoint with detailed user information
  $users_cb = function( WP_REST_Request $req ){
    if (!luna_license_ok($req)) return luna_forbidden();
    $per    = max(1, min(200, (int)$req->get_param('per_page') ?: 100));
    $page   = max(1, (int)$req->get_param('page') ?: 1);
    $offset = ($page-1)*$per;
    $u = get_users(array(
      'number' => $per,
      'offset' => $offset,
      'fields' => array('user_login','user_email','display_name','ID','user_registered','user_url'),
      'orderby'=> 'ID',
      'order'  => 'ASC',
    ));
    $items = array();
    foreach ($u as $row) {
      $user_meta = get_user_meta($row->ID);
      $items[] = array(
        'id'       => (int)$row->ID,
        'username' => $row->user_login,
        'email'    => $row->user_email,
        'name'     => $row->display_name,
        'url'      => $row->user_url,
        'registered' => $row->user_registered,
        'roles'    => get_userdata($row->ID)->roles,
        'last_login' => get_user_meta($row->ID, 'last_login', true),
        'post_count' => count_user_posts($row->ID),
        'meta_data' => $user_meta
      );
    }
    $counts = count_users();
    $total  = isset($counts['total_users']) ? (int)$counts['total_users'] : (int)($offset + count($items));
    return new WP_REST_Response(array('total'=>$total,'page'=>$page,'per_page'=>$per,'items'=>$items), 200);
  };
  register_rest_route('luna_widget/v1', '/users', array('methods'=>'GET','permission_callback'=>$secure_cb,'callback'=>$users_cb));
  register_rest_route('vl-hub/v1',      '/users', array('methods'=>'GET','permission_callback'=>$secure_cb,'callback'=>$users_cb));

  // Plugins
  register_rest_route('luna_widget/v1', '/plugins', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      if (!function_exists('get_plugins')) { @require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
      $plugins = function_exists('get_plugins') ? (array)get_plugins() : array();
      $active  = (array) get_option('active_plugins', array());
      $up_pl   = get_site_transient('update_plugins');
      $items = array();
      foreach ($plugins as $slug => $info) {
        $update_available = isset($up_pl->response[$slug]);
        $items[] = array(
          'slug'            => $slug,
          'name'            => isset($info['Name']) ? $info['Name'] : $slug,
          'version'         => isset($info['Version']) ? $info['Version'] : null,
          'active'          => in_array($slug, $active, true),
          'update_available'=> (bool)$update_available,
          'new_version'     => $update_available ? (isset($up_pl->response[$slug]->new_version) ? $up_pl->response[$slug]->new_version : null) : null,
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  // Themes
  register_rest_route('luna_widget/v1', '/themes', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      $themes = wp_get_themes();
      $up_th  = get_site_transient('update_themes');
      $active_stylesheet = wp_get_theme()->get_stylesheet();
      $items = array();
      foreach ($themes as $stylesheet => $th) {
        $update_available = isset($up_th->response[$stylesheet]);
        $items[] = array(
          'stylesheet'      => $stylesheet,
          'name'            => $th->get('Name'),
          'version'         => $th->get('Version'),
          'is_active'       => ($active_stylesheet === $stylesheet),
          'update_available'=> (bool)$update_available,
          'new_version'     => $update_available ? (isset($up_th->response[$stylesheet]['new_version']) ? $up_th->response[$stylesheet]['new_version'] : null) : null,
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  /* Utilities: manual pings */
  register_rest_route('luna_widget/v1', '/ping-hub', array(
    'methods'  => 'POST',
    'permission_callback' => function(){ return current_user_can('manage_options'); },
    'callback' => function(){
      luna_widget_try_activation();
      $last = get_option(LUNA_WIDGET_OPT_LAST_PING, array());
      return new WP_REST_Response(array('ok'=>true,'last'=>$last), 200);
    },
  ));
  register_rest_route('luna_widget/v1', '/heartbeat-now', array(
    'methods'  => 'POST',
    'permission_callback' => function(){ return current_user_can('manage_options'); },
    'callback' => function(){
      luna_widget_send_heartbeat();
      $last = get_option(LUNA_WIDGET_OPT_LAST_PING, array());
      return new WP_REST_Response(array('ok'=>true,'last'=>$last), 200);
    },
  ));

  /* --- Purge profile cache (Hub → client after Security edits) --- */
  register_rest_route('luna_widget/v1', '/purge-profile-cache', array(
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      if (!luna_license_ok($req)) return new WP_REST_Response(array('ok'=>false,'error'=>'forbidden'), 403);
      luna_profile_cache_bust(true);
      return new WP_REST_Response(array('ok'=>true,'message'=>'Profile cache purged'), 200);
    },
  ));

  /* --- Debug endpoint to test Hub connection --- */
  register_rest_route('luna_widget/v1', '/debug-hub', array(
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $license = luna_get_license();
      $hub_url = luna_widget_hub_base();
      $endpoint = $hub_url . '/wp-json/luna_widget/v1/system/comprehensive';
      
      $response = wp_remote_get($endpoint, array(
        'headers' => array('X-Luna-License' => $license),
        'timeout' => 10
      ));
      
      $debug_info = array(
        'license' => $license ? substr($license, 0, 8) . '...' : 'NOT SET',
        'hub_url' => $hub_url,
        'endpoint' => $endpoint,
        'is_error' => is_wp_error($response),
        'error_message' => is_wp_error($response) ? $response->get_error_message() : null,
        'response_code' => is_wp_error($response) ? null : wp_remote_retrieve_response_code($response),
        'response_body' => is_wp_error($response) ? null : wp_remote_retrieve_body($response),
      );
      
      return new WP_REST_Response($debug_info, 200);
    },
  ));

  /* --- Debug endpoint to see comprehensive facts --- */
  register_rest_route('luna_widget/v1', '/debug-facts', array(
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $facts = luna_profile_facts_comprehensive();
      
      $debug_info = array(
        'comprehensive_facts' => $facts,
        'has_pages' => isset($facts['pages']) && is_array($facts['pages']) ? count($facts['pages']) : 0,
        'has_themes' => isset($facts['themes']) && is_array($facts['themes']) ? count($facts['themes']) : 0,
        'updates' => $facts['updates'] ?? array(),
        'counts' => $facts['counts'] ?? array(),
      );
      
      return new WP_REST_Response($debug_info, 200);
    },
  ));

  /* --- Debug endpoint to test regex patterns --- */
  register_rest_route('luna_widget/v1', '/debug-regex', array(
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $test_phrases = array(
        'What are the names of the pages?',
        'What are the names of the posts?',
        'Do I have any inactive pages?',
        'What themes do I have?'
      );
      
      $results = array();
      foreach ($test_phrases as $phrase) {
        $lc = strtolower($phrase);
        $results[$phrase] = array(
          'lowercase' => $lc,
          'page_names_match' => preg_match('/\bnames.*of.*pages|page.*names|what.*are.*the.*names.*of.*pages\b/', $lc),
          'post_names_match' => preg_match('/\bnames.*of.*posts|post.*names|what.*are.*the.*names.*of.*posts\b/', $lc),
          'inactive_pages_match' => preg_match('/\binactive.*page|page.*inactive|draft.*page|page.*draft\b/', $lc),
          'theme_list_match' => preg_match('/\binactive.*theme|theme.*inactive|what.*themes|list.*themes|all.*themes\b/', $lc)
        );
      }
      
      return new WP_REST_Response($results, 200);
    },
  ));

  /* --- Debug endpoint for keyword mappings --- */
  register_rest_route('luna_widget/v1', '/debug-keywords', array(
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $test_input = $req->get_param('input') ?: 'hey Lu';
      $mappings = luna_get_keyword_mappings();
      $keyword_match = luna_check_keyword_mappings($test_input);
      
      return new WP_REST_Response(array(
        'test_input' => $test_input,
        'mappings' => $mappings,
        'keyword_match' => $keyword_match,
        'mapping_count' => count($mappings)
      ), 200);
    },
  ));

  /* --- Canned Responses Endpoint for Luna Composer --- */
  register_rest_route('luna_widget/v1', '/canned-responses', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      
      $posts = get_posts(array(
        'post_type'        => 'luna_canned_response',
        'post_status'      => 'publish',
        'numberposts'      => -1,
        'orderby'          => array('menu_order' => 'ASC', 'title' => 'ASC'),
        'order'            => 'ASC',
        'suppress_filters' => false,
      ));
      
      $items = array();
      foreach ($posts as $post) {
        $content = luna_widget_prepare_canned_response_content($post->post_content);
        $items[] = array(
          'id'      => $post->ID,
          'title'   => $post->post_title,
          'prompt'  => $post->post_title, // Prompt is the title
          'content' => $content,
          'excerpt' => wp_trim_words($content, 30, '…'),
        );
      }
      
      return new WP_REST_Response(array('items' => $items), 200);
    },
  ));

  /* --- Comprehensive WordPress Data Collection Endpoints --- */
  
  // Comments endpoint
  register_rest_route('luna_widget/v1', '/comments', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      $per_page = max(1, min(200, (int)$req->get_param('per_page') ?: 50));
      $page = max(1, (int)$req->get_param('page') ?: 1);
      $comments = get_comments(array(
        'number' => $per_page,
        'offset' => ($page - 1) * $per_page,
        'status' => 'approve'
      ));
      $items = array();
      foreach ($comments as $comment) {
        $items[] = array(
          'id' => $comment->comment_ID,
          'post_id' => $comment->comment_post_ID,
          'author' => $comment->comment_author,
          'author_email' => $comment->comment_author_email,
          'author_url' => $comment->comment_author_url,
          'content' => $comment->comment_content,
          'date' => $comment->comment_date,
          'approved' => $comment->comment_approved,
          'type' => $comment->comment_type,
          'parent' => $comment->comment_parent
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  // Media endpoint
  register_rest_route('luna_widget/v1', '/media', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      $per_page = max(1, min(200, (int)$req->get_param('per_page') ?: 50));
      $page = max(1, (int)$req->get_param('page') ?: 1);
      $query = new WP_Query(array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'date',
        'order' => 'DESC'
      ));
      $items = array();
      foreach ($query->posts as $attachment) {
        $file_path = get_attached_file($attachment->ID);
        $items[] = array(
          'id' => $attachment->ID,
          'title' => $attachment->post_title,
          'filename' => basename($file_path),
          'mime_type' => $attachment->post_mime_type,
          'url' => wp_get_attachment_url($attachment->ID),
          'date' => $attachment->post_date,
          'author' => get_the_author_meta('user_login', $attachment->post_author),
          'file_size' => $file_path && file_exists($file_path) ? filesize($file_path) : 0
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  // Categories endpoint
  register_rest_route('luna_widget/v1', '/categories', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      $categories = get_categories(array('hide_empty' => false));
      $items = array();
      foreach ($categories as $category) {
        $items[] = array(
          'id' => $category->term_id,
          'name' => $category->name,
          'slug' => $category->slug,
          'description' => $category->description,
          'count' => $category->count,
          'parent' => $category->parent
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  // Tags endpoint
  register_rest_route('luna_widget/v1', '/tags', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      $tags = get_tags(array('hide_empty' => false));
      $items = array();
      foreach ($tags as $tag) {
        $items[] = array(
          'id' => $tag->term_id,
          'name' => $tag->name,
          'slug' => $tag->slug,
          'description' => $tag->description,
          'count' => $tag->count
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  // Custom post types endpoint
  register_rest_route('luna_widget/v1', '/custom-post-types', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      $post_types = get_post_types(array('public' => true), 'objects');
      $items = array();
      foreach ($post_types as $post_type) {
        if ($post_type->name === 'attachment') continue;
        $count = wp_count_posts($post_type->name);
        $items[] = array(
          'name' => $post_type->name,
          'label' => $post_type->label,
          'description' => $post_type->description,
          'public' => $post_type->public,
          'hierarchical' => $post_type->hierarchical,
          'count' => array(
            'publish' => $count->publish,
            'draft' => $count->draft,
            'private' => $count->private,
            'trash' => $count->trash
          )
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  // Menus endpoint
  register_rest_route('luna_widget/v1', '/menus', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      $menus = wp_get_nav_menus();
      $items = array();
      foreach ($menus as $menu) {
        $items[] = array(
          'id' => $menu->term_id,
          'name' => $menu->name,
          'slug' => $menu->slug,
          'description' => $menu->description,
          'count' => $menu->count
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  // Widgets endpoint
  register_rest_route('luna_widget/v1', '/widgets', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      global $wp_registered_widgets;
      $items = array();
      foreach ($wp_registered_widgets as $widget_id => $widget) {
        $items[] = array(
          'id' => $widget_id,
          'name' => $widget['name'],
          'class' => $widget['classname'],
          'description' => $widget['description']
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  // Sidebars endpoint
  register_rest_route('luna_widget/v1', '/sidebars', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      global $wp_registered_sidebars;
      $items = array();
      foreach ($wp_registered_sidebars as $sidebar_id => $sidebar) {
        $items[] = array(
          'id' => $sidebar_id,
          'name' => $sidebar['name'],
          'description' => $sidebar['description'],
          'class' => $sidebar['class'],
          'before_widget' => $sidebar['before_widget'],
          'after_widget' => $sidebar['after_widget'],
          'before_title' => $sidebar['before_title'],
          'after_title' => $sidebar['after_title']
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  // Options endpoint
  register_rest_route('luna_widget/v1', '/options', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      $options = array(
        'site_name' => get_option('blogname'),
        'site_description' => get_option('blogdescription'),
        'admin_email' => get_option('admin_email'),
        'timezone' => get_option('timezone_string'),
        'date_format' => get_option('date_format'),
        'time_format' => get_option('time_format'),
        'start_of_week' => get_option('start_of_week'),
        'language' => get_option('WPLANG'),
        'permalink_structure' => get_option('permalink_structure'),
        'default_category' => get_option('default_category'),
        'default_post_format' => get_option('default_post_format'),
        'users_can_register' => get_option('users_can_register'),
        'default_role' => get_option('default_role'),
        'comment_moderation' => get_option('comment_moderation'),
        'comment_registration' => get_option('comment_registration'),
        'close_comments_for_old_posts' => get_option('close_comments_for_old_posts'),
        'close_comments_days_old' => get_option('close_comments_days_old'),
        'thread_comments' => get_option('thread_comments'),
        'thread_comments_depth' => get_option('thread_comments_depth'),
        'page_comments' => get_option('page_comments'),
        'comments_per_page' => get_option('comments_per_page'),
        'default_comments_page' => get_option('default_comments_page'),
        'comment_order' => get_option('comment_order')
      );
      return new WP_REST_Response(array('options'=>$options), 200);
    },
  ));

  // Database tables endpoint
  register_rest_route('luna_widget/v1', '/database-tables', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      global $wpdb;
      $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
      $items = array();
      foreach ($tables as $table) {
        $table_name = $table[0];
        $count = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");
        $items[] = array(
          'name' => $table_name,
          'count' => (int)$count
        );
      }
      return new WP_REST_Response(array('items'=>$items), 200);
    },
  ));

  // WordPress Core Status endpoint
  register_rest_route('luna_widget/v1', '/wp-core-status', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      
      global $wp_version;
      $core_updates = get_site_transient('update_core');
      $is_update_available = !empty($core_updates->updates) && $core_updates->updates[0]->response === 'upgrade';
      
      $status = array(
        'version' => $wp_version,
        'update_available' => $is_update_available,
        'latest_version' => $is_update_available ? $core_updates->updates[0]->version : $wp_version,
        'php_version' => PHP_VERSION,
        'mysql_version' => $GLOBALS['wpdb']->db_version(),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_input_vars' => ini_get('max_input_vars'),
        'is_multisite' => is_multisite(),
        'site_url' => get_site_url(),
        'home_url' => get_home_url(),
        'admin_email' => get_option('admin_email'),
        'timezone' => get_option('timezone_string'),
        'date_format' => get_option('date_format'),
        'time_format' => get_option('time_format'),
        'start_of_week' => get_option('start_of_week'),
        'language' => get_option('WPLANG'),
        'permalink_structure' => get_option('permalink_structure'),
        'users_can_register' => get_option('users_can_register'),
        'default_role' => get_option('default_role'),
        'comment_moderation' => get_option('comment_moderation'),
        'comment_registration' => get_option('comment_registration'),
        'close_comments_for_old_posts' => get_option('close_comments_for_old_posts'),
        'close_comments_days_old' => get_option('close_comments_days_old'),
        'thread_comments' => get_option('thread_comments'),
        'thread_comments_depth' => get_option('thread_comments_depth'),
        'page_comments' => get_option('page_comments'),
        'comments_per_page' => get_option('comments_per_page'),
        'default_comments_page' => get_option('default_comments_page'),
        'comment_order' => get_option('comment_order')
      );
      
      return new WP_REST_Response($status, 200);
    },
  ));

  // Comments count endpoint
  register_rest_route('luna_widget/v1', '/comments-count', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      
      $counts = wp_count_comments();
      $total = $counts->total_comments;
      $approved = $counts->approved;
      $pending = $counts->moderated;
      $spam = $counts->spam;
      $trash = $counts->trash;
      
      return new WP_REST_Response(array(
        'total' => $total,
        'approved' => $approved,
        'pending' => $pending,
        'spam' => $spam,
        'trash' => $trash
      ), 200);
    },
  ));

  // All WordPress data endpoint (comprehensive collection)
  register_rest_route('luna_widget/v1', '/all-wp-data', array(
    'methods'  => 'GET',
    'permission_callback' => $secure_cb,
    'callback' => function( WP_REST_Request $req ){
      if (!luna_license_ok($req)) return luna_forbidden();
      
      $data = array();
      
      // System info
      $data['system'] = luna_snapshot_system();
      
      // Posts (limited to 100 most recent)
      $posts_query = new WP_Query(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 100,
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids'
      ));
      $data['posts'] = array();
      foreach ($posts_query->posts as $pid) {
        $data['posts'][] = array(
          'id' => $pid,
          'title' => get_the_title($pid),
          'excerpt' => get_the_excerpt($pid),
          'date' => get_the_date('c', $pid),
          'author' => get_the_author_meta('user_login', get_post_field('post_author', $pid)),
          'categories' => wp_get_post_terms($pid, 'category', array('fields'=>'names')),
          'tags' => wp_get_post_terms($pid, 'post_tag', array('fields'=>'names'))
        );
      }
      
      // Pages (limited to 100 most recent)
      $pages_query = new WP_Query(array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 100,
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids'
      ));
      $data['pages'] = array();
      foreach ($pages_query->posts as $pid) {
        $data['pages'][] = array(
          'id' => $pid,
          'title' => get_the_title($pid),
          'excerpt' => get_the_excerpt($pid),
          'date' => get_the_date('c', $pid),
          'author' => get_the_author_meta('user_login', get_post_field('post_author', $pid)),
          'parent' => get_post_field('post_parent', $pid)
        );
      }
      
      // Users (limited to 50 most recent)
      $users = get_users(array('number' => 50, 'orderby' => 'registered', 'order' => 'DESC'));
      $data['users'] = array();
      foreach ($users as $user) {
        $data['users'][] = array(
          'id' => $user->ID,
          'login' => $user->user_login,
          'email' => $user->user_email,
          'display_name' => $user->display_name,
          'roles' => $user->roles,
          'registered' => $user->user_registered,
          'last_login' => get_user_meta($user->ID, 'last_login', true)
        );
      }
      
      // Comments (limited to 100 most recent)
      $comments = get_comments(array('number' => 100, 'status' => 'approve'));
      $data['comments'] = array();
      foreach ($comments as $comment) {
        $data['comments'][] = array(
          'id' => $comment->comment_ID,
          'post_id' => $comment->comment_post_ID,
          'author' => $comment->comment_author,
          'content' => $comment->comment_content,
          'date' => $comment->comment_date,
          'approved' => $comment->comment_approved
        );
      }
      
      // Media (limited to 100 most recent)
      $media_query = new WP_Query(array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 100,
        'orderby' => 'date',
        'order' => 'DESC'
      ));
      $data['media'] = array();
      foreach ($media_query->posts as $attachment) {
        $data['media'][] = array(
          'id' => $attachment->ID,
          'title' => $attachment->post_title,
          'filename' => basename(get_attached_file($attachment->ID)),
          'mime_type' => $attachment->post_mime_type,
          'url' => wp_get_attachment_url($attachment->ID),
          'date' => $attachment->post_date
        );
      }
      
      // Categories
      $categories = get_categories(array('hide_empty' => false));
      $data['categories'] = array();
      foreach ($categories as $category) {
        $data['categories'][] = array(
          'id' => $category->term_id,
          'name' => $category->name,
          'slug' => $category->slug,
          'count' => $category->count
        );
      }
      
      // Tags
      $tags = get_tags(array('hide_empty' => false));
      $data['tags'] = array();
      foreach ($tags as $tag) {
        $data['tags'][] = array(
          'id' => $tag->term_id,
          'name' => $tag->name,
          'slug' => $tag->slug,
          'count' => $tag->count
        );
      }
      
      // Custom post types
      $post_types = get_post_types(array('public' => true), 'objects');
      $data['custom_post_types'] = array();
      foreach ($post_types as $post_type) {
        if ($post_type->name === 'attachment') continue;
        $count = wp_count_posts($post_type->name);
        $data['custom_post_types'][] = array(
          'name' => $post_type->name,
          'label' => $post_type->label,
          'count' => array(
            'publish' => $count->publish,
            'draft' => $count->draft,
            'private' => $count->private
          )
        );
      }
      
      // Menus
      $menus = wp_get_nav_menus();
      $data['menus'] = array();
      foreach ($menus as $menu) {
        $data['menus'][] = array(
          'id' => $menu->term_id,
          'name' => $menu->name,
          'count' => $menu->count
        );
      }
      
      // Site options
      $data['options'] = array(
        'site_name' => get_option('blogname'),
        'site_description' => get_option('blogdescription'),
        'admin_email' => get_option('admin_email'),
        'timezone' => get_option('timezone_string'),
        'language' => get_option('WPLANG'),
        'permalink_structure' => get_option('permalink_structure'),
        'users_can_register' => get_option('users_can_register'),
        'default_role' => get_option('default_role')
      );
      
      return new WP_REST_Response($data, 200);
    },
  ));

  /* --- Sync to Hub endpoint --- */
  register_rest_route('luna_widget/v1', '/sync-to-hub', array(
    'methods'  => 'POST',
    'permission_callback' => function(){ return current_user_can('manage_options'); },
    'callback' => function(){
      $license = luna_get_license();
      if (!$license) {
        return new WP_REST_Response(array('ok'=>false,'error'=>'No license found'), 400);
      }
      
      // Sync all data to Hub
      $settings_data = array(
        'license' => $license,
        'hub_url' => luna_widget_hub_base(),
        'mode' => get_option(LUNA_WIDGET_OPT_MODE, 'widget'),
        'ui_settings' => get_option(LUNA_WIDGET_OPT_SETTINGS, array()),
        'wp_version' => get_bloginfo('version'),
        'plugin_version' => LUNA_WIDGET_PLUGIN_VERSION,
        'site_url' => home_url('/'),
        'last_sync' => current_time('mysql')
      );
      
      luna_sync_settings_to_hub($settings_data);
      
      // Sync keywords
      $keywords = luna_get_keyword_mappings();
      luna_sync_keywords_to_hub($keywords);
      
      // Sync analytics settings
      $analytics = get_option('luna_ga4_settings', array());
      if (!empty($analytics)) {
        luna_sync_analytics_to_hub($analytics);
      }
      
      return new WP_REST_Response(array('ok'=>true,'message'=>'All data synced to Hub'), 200);
    },
  ));
});

// AJAX handler for Luna Widget chat transcript
add_action('wp_ajax_luna_get_chat_transcript', function() {
  check_ajax_referer('luna_chat_transcript_nonce', 'nonce');
  
  $license_key = sanitize_text_field($_POST['license_key'] ?? '');
  if (empty($license_key)) {
    wp_send_json_error('License key required');
    return;
  }
  
  $transcript = luna_get_chat_transcript($license_key);
  wp_send_json_success(array('transcript' => $transcript));
});

/* ============================================================
 * KEYWORD MAPPING SYSTEM
 * ============================================================ */

// Default keyword mappings with response templates
function luna_get_default_keywords() {
  return [
    'business' => [
      'appointment' => [
        'enabled' => 'on',
        'keywords' => ['booking', 'schedule', 'visit', 'consultation'],
        'template' => 'To schedule an appointment, please call our office or use our online booking system. You can find our contact information on our website.',
        'data_source' => 'custom'
      ],
      'contact' => [
        'enabled' => 'on',
        'keywords' => ['phone', 'email', 'reach', 'get in touch'],
        'template' => 'You can reach us through our contact page or by calling our main office number. Our contact information is available on our website.',
        'data_source' => 'custom'
      ],
      'hours' => [
        'enabled' => 'on',
        'keywords' => ['open', 'closed', 'business hours', 'availability'],
        'template' => 'Our business hours are typically Monday through Friday, 9 AM to 5 PM. Please check our website for the most current hours and holiday schedules.',
        'data_source' => 'custom'
      ],
      'location' => [
        'enabled' => 'on',
        'keywords' => ['address', 'where', 'directions', 'find us'],
        'template' => 'You can find our address and directions on our website\'s contact page. We\'re located in a convenient area with parking available.',
        'data_source' => 'custom'
      ],
      'services' => [
        'enabled' => 'on',
        'keywords' => ['what we do', 'offerings', 'treatments', 'care'],
        'template' => 'We offer a comprehensive range of services. Please visit our services page on our website for detailed information about what we provide.',
        'data_source' => 'custom'
      ],
      'providers' => [
        'enabled' => 'on',
        'keywords' => ['doctors', 'staff', 'team', 'physicians'],
        'template' => 'Our team of experienced providers is dedicated to your care. You can learn more about our staff on our website\'s team page.',
        'data_source' => 'custom'
      ],
      'insurance' => [
        'enabled' => 'on',
        'keywords' => ['coverage', 'accepted', 'billing', 'payment'],
        'template' => 'We accept most major insurance plans. Please contact our billing department to verify your coverage and discuss payment options.',
        'data_source' => 'custom'
      ],
      'forms' => [
        'enabled' => 'on',
        'keywords' => ['paperwork', 'documents', 'download', 'patient forms'],
        'template' => 'You can download patient forms from our website or pick them up at our office. Please complete them before your visit to save time.',
        'data_source' => 'custom'
      ]
    ],
    'wp_rest' => [
      'pages' => [
        'enabled' => 'on',
        'keywords' => ['page names', 'what pages', 'list pages', 'site pages'],
        'template' => 'Your pages are: {pages_list}.',
        'data_source' => 'wp_rest'
      ],
      'posts' => [
        'enabled' => 'on',
        'keywords' => ['blog posts', 'articles', 'news', 'content'],
        'template' => 'Your posts are: {posts_list}.',
        'data_source' => 'wp_rest'
      ],
      'themes' => [
        'enabled' => 'on',
        'keywords' => ['theme info', 'design', 'appearance', 'look'],
        'template' => 'Your themes are: {themes_list}.',
        'data_source' => 'wp_rest'
      ],
      'plugins' => [
        'enabled' => 'on',
        'keywords' => ['add-ons', 'extensions', 'tools', 'features'],
        'template' => 'Your plugins are: {plugins_list}.',
        'data_source' => 'wp_rest'
      ],
      'users' => [
        'enabled' => 'on',
        'keywords' => ['admin', 'administrators', 'who can login'],
        'template' => 'You have {user_count} user{user_plural} with access to your site.',
        'data_source' => 'wp_rest'
      ],
      'updates' => [
        'enabled' => 'on',
        'keywords' => ['outdated', 'new version', 'upgrade', 'patches'],
        'template' => 'Updates pending — plugins: {plugin_updates}, themes: {theme_updates}, WordPress Core: {core_updates}.',
        'data_source' => 'wp_rest'
      ],
      'media' => [
        'enabled' => 'on',
        'keywords' => ['images', 'files', 'uploads', 'gallery'],
        'template' => 'Media information is available in your WordPress dashboard under Media.',
        'data_source' => 'custom'
      ]
    ],
    'security' => [
      'ssl' => [
        'enabled' => 'on',
        'keywords' => ['certificate', 'https', 'secure', 'encrypted'],
        'template' => '{ssl_status}',
        'data_source' => 'security'
      ],
      'firewall' => [
        'enabled' => 'on',
        'keywords' => ['protection', 'security', 'blocking', 'defense'],
        'template' => 'Firewall protection status is available in your security settings. Please check the Security tab in Visible Light for detailed firewall information.',
        'data_source' => 'security'
      ],
      'backup' => [
        'enabled' => 'on',
        'keywords' => ['backup', 'restore', 'recovery', 'safety'],
        'template' => 'Backup information is available in your security profile. Please check the Security tab in Visible Light for backup status and schedules.',
        'data_source' => 'security'
      ],
      'monitoring' => [
        'enabled' => 'on',
        'keywords' => ['scan', 'threats', 'vulnerabilities', 'alerts'],
        'template' => 'Security monitoring details are available in your security profile. Please check the Security tab in Visible Light for scan results and alerts.',
        'data_source' => 'security'
      ],
      'access' => [
        'enabled' => 'on',
        'keywords' => ['login', 'authentication', 'permissions', 'users'],
        'template' => 'You have {user_count} user{user_plural} with access to your site.',
        'data_source' => 'wp_rest'
      ],
      'compliance' => [
        'enabled' => 'on',
        'keywords' => ['hipaa', 'gdpr', 'standards', 'regulations'],
        'template' => 'Compliance information is available in your security profile. Please check the Security tab in Visible Light for compliance status and requirements.',
        'data_source' => 'security'
      ]
    ]
  ];
}

// Get current keyword mappings
function luna_get_keyword_mappings() {
  $custom = get_option('luna_keyword_mappings', []);
  
  // If we have custom data, return it directly
  if (!empty($custom)) {
    return $custom;
  }
  
  // Otherwise, return defaults
  return luna_get_default_keywords();
}

// Save keyword mappings
function luna_save_keyword_mappings($mappings) {
  // Debug: Log what's being processed
  error_log('Luna Keywords: Processing mappings: ' . print_r($mappings, true));
  
  // Process the new data structure
  $processed_mappings = array();
  
  foreach ($mappings as $category => $actions) {
    foreach ($actions as $action => $config) {
      // Skip if no keywords or empty config
      if (empty($config['keywords']) || !is_array($config['keywords'])) {
        continue;
      }
      
      $processed_config = array(
        'enabled' => $config['enabled'] ?? 'on',
        'keywords' => $config['keywords'] ?? array(),
        'data_source' => $config['data_source'] ?? 'custom',
        'response_type' => $config['response_type'] ?? 'simple'
      );
      
      // Only process active keywords for template processing
      if ($processed_config['enabled'] === 'on') {
        // Handle different data sources
        switch ($config['data_source']) {
          case 'wp_rest':
            $processed_config['wp_template'] = $config['wp_template'] ?? '';
            break;
          case 'security':
            $processed_config['security_template'] = $config['security_template'] ?? '';
            break;
          case 'custom':
          default:
            if ($config['response_type'] === 'advanced') {
              $processed_config['initial_response'] = $config['initial_response'] ?? '';
              $processed_config['branches'] = $config['branches'] ?? array();
            } else {
              $processed_config['template'] = $config['template'] ?? '';
            }
            break;
        }
      } else {
        // For disabled keywords, just store basic info without templates
        error_log("Luna Keywords: Storing disabled keyword - {$category}.{$action}");
      }
      
      $processed_mappings[$category][$action] = $processed_config;
    }
  }
  
  // Debug: Log what's being stored
  error_log('Luna Keywords: Final processed mappings: ' . print_r($processed_mappings, true));
  
  // Visual debug - show what's being processed
  echo '<div class="notice notice-info"><p><strong>DEBUG:</strong> Final processed mappings: ' . esc_html(print_r($processed_mappings, true)) . '</p></div>';
  
  update_option('luna_keyword_mappings', $processed_mappings);
  
  // Debug: Verify what was stored
  $stored = get_option('luna_keyword_mappings', array());
  error_log('Luna Keywords: Verified stored data: ' . print_r($stored, true));
  
  // Visual debug - show what was stored
  echo '<div class="notice notice-info"><p><strong>DEBUG:</strong> Verified stored data: ' . esc_html(print_r($stored, true)) . '</p></div>';
  
  // Send to Hub
  luna_sync_keywords_to_hub($processed_mappings);
}

// Sync keywords to Hub
function luna_sync_keywords_to_hub($mappings) {
  $license = luna_get_license();
  if (!$license) return;
  
  $response = wp_remote_post('https://visiblelight.ai/wp-json/luna_widget/v1/keywords/sync', [
    'timeout' => 10,
    'headers' => ['X-Luna-License' => $license, 'Content-Type' => 'application/json'],
    'body' => json_encode(['keywords' => $mappings])
  ]);
  
  if (is_wp_error($response)) {
    error_log('[Luna] Failed to sync keywords to Hub: ' . $response->get_error_message());
  }
}

// Sync analytics data to Hub
function luna_sync_analytics_to_hub($analytics_data) {
  $license = luna_get_license();
  if (!$license) return;

  $endpoint = luna_widget_hub_base() . '/wp-json/vl-hub/v1/sync-client-data';
  delete_transient('luna_ga4_metrics_' . md5($license));

  $response = wp_remote_post($endpoint, [
    'timeout' => 10,
    'headers' => ['X-Luna-License' => $license, 'Content-Type' => 'application/json'],
    'body' => json_encode([
      'license' => $license,
      'category' => 'analytics',
      'analytics_data' => $analytics_data
    ])
  ]);

  if (is_wp_error($response)) {
    error_log('[Luna] Failed to sync analytics to Hub: ' . $response->get_error_message());
  }
}

// Fetch data streams from Hub and extract GA4 metrics
function luna_fetch_hub_data_streams($license = null) {
  if (!$license) {
    $license = luna_get_license();
  }
  if (!$license) return null;

  $base = luna_widget_hub_base();
  $url  = add_query_arg(array('license' => $license), $base . '/wp-json/vl-hub/v1/data-streams');

  $response = wp_remote_get($url, array(
    'timeout' => 12,
    'headers' => array(
      'X-Luna-License' => $license,
      'X-Luna-Site'    => home_url('/'),
      'Accept'         => 'application/json',
    ),
    'sslverify' => true,
  ));

  if (is_wp_error($response)) {
    error_log('[Luna] Error fetching Hub data streams: ' . $response->get_error_message());
    return null;
  }

  $code = (int) wp_remote_retrieve_response_code($response);
  if ($code < 200 || $code >= 300) {
    error_log('[Luna] Hub data streams responded with HTTP ' . $code);
    return null;
  }

  $body = json_decode(wp_remote_retrieve_body($response), true);
  if (!is_array($body)) {
    error_log('[Luna] Hub data streams response was not valid JSON.');
    return null;
  }

  $body = luna_hub_normalize_payload($body);
  if (!is_array($body)) {
    return null;
  }

  $streams_raw = array();
  if (isset($body['streams']) && is_array($body['streams'])) {
    $streams_raw = $body['streams'];
  } else {
    $streams_raw = $body;
  }

  $streams = array();
  foreach ($streams_raw as $stream_id => $stream_data) {
    if (is_array($stream_data)) {
      if (!isset($stream_data['_id'])) {
        $stream_data['_id'] = is_string($stream_id) ? $stream_id : null;
      }
      $streams[] = $stream_data;
    }
  }

  return $streams;
}

function luna_extract_ga4_metrics_from_streams($streams) {
  if (!is_array($streams)) return null;

  foreach ($streams as $stream) {
    if (!is_array($stream)) continue;

    if (!empty($stream['ga4_metrics']) && is_array($stream['ga4_metrics'])) {
      return array(
        'metrics'        => $stream['ga4_metrics'],
        'last_synced'    => isset($stream['ga4_last_synced']) ? $stream['ga4_last_synced'] : (isset($stream['last_updated']) ? $stream['last_updated'] : null),
        'date_range'     => isset($stream['ga4_date_range']) ? $stream['ga4_date_range'] : null,
        'source_url'     => isset($stream['source_url']) ? $stream['source_url'] : null,
        'property_id'    => isset($stream['ga4_property_id']) ? $stream['ga4_property_id'] : null,
        'measurement_id' => isset($stream['ga4_measurement_id']) ? $stream['ga4_measurement_id'] : null,
      );
    }
  }

  return null;
}

function luna_fetch_ga4_metrics_from_hub($license = null) {
  if (!$license) {
    $license = luna_get_license();
  }
  if (!$license) return null;

  $cache_key = 'luna_ga4_metrics_' . md5($license);
  $cached    = get_transient($cache_key);
  if (is_array($cached)) {
    return $cached;
  }

  $streams = luna_fetch_hub_data_streams($license);
  if (!$streams) {
    return null;
  }

  $ga4_info = luna_extract_ga4_metrics_from_streams($streams);
  if ($ga4_info) {
    set_transient($cache_key, $ga4_info, 5 * MINUTE_IN_SECONDS);
  }

  return $ga4_info;
}

// Sync security data to Hub
function luna_sync_security_to_hub($security_data) {
  $license = luna_get_license();
  if (!$license) return;

  $endpoint = luna_widget_hub_base() . '/wp-json/vl-hub/v1/sync-client-data';
  $response = wp_remote_post($endpoint, [
    'timeout' => 10,
    'headers' => ['X-Luna-License' => $license, 'Content-Type' => 'application/json'],
    'body' => json_encode([
      'license' => $license,
      'category' => 'security',
      'security_data' => $security_data
    ])
  ]);
  
  if (is_wp_error($response)) {
    error_log('[Luna] Failed to sync security to Hub: ' . $response->get_error_message());
  }
}

// Sync settings data to Hub
function luna_sync_settings_to_hub($settings_data) {
  $license = luna_get_license();
  if (!$license) return;

  $endpoint = luna_widget_hub_base() . '/wp-json/vl-hub/v1/sync-client-data';
  $response = wp_remote_post($endpoint, [
    'timeout' => 10,
    'headers' => ['X-Luna-License' => $license, 'Content-Type' => 'application/json'],
    'body' => json_encode([
      'license' => $license,
      'category' => 'infrastructure',
      'settings_data' => $settings_data
    ])
  ]);
  
  if (is_wp_error($response)) {
    error_log('[Luna] Failed to sync settings to Hub: ' . $response->get_error_message());
  }
}

// Get data from Hub for Luna Chat Widget
function luna_get_hub_data($category = null) {
  $license = luna_get_license();
  if (!$license) return null;
  
  // Get profile data from VL Hub which includes GA4 analytics
  $url = luna_widget_hub_base() . '/wp-json/vl-hub/v1/profile';
  $args = ['license' => $license];
  if ($category) {
    $args['category'] = $category;
  }
  
  $response = wp_remote_get(add_query_arg($args, $url), [
    'timeout' => 10,
    'headers' => ['X-Luna-License' => $license]
  ]);
  
  if (is_wp_error($response)) {
    error_log('[Luna] Failed to get data from Hub: ' . $response->get_error_message());
    return null;
  }
  
  $body = wp_remote_retrieve_body($response);
  $data = json_decode($body, true);
  
  // Extract GA4 metrics if available
  if (isset($data['ga4_metrics'])) {
    $data['analytics'] = $data['ga4_metrics'];
  }
  
  return $data;
}

/**
 * Fetch competitor analysis data from Hub.
 * 
 * @param string|null $license License key
 * @return array|null Competitor data or null on failure
 */
function luna_fetch_competitor_data($license = null) {
  if (!$license) {
    $license = luna_get_license();
  }
  if (!$license) return null;

  $hub_url = luna_widget_hub_base();
  $url = $hub_url . '/wp-json/vl-hub/v1/competitor-report';
  
  // First, get competitor URLs from settings
  $competitor_urls = array();
  $response = wp_remote_get($hub_url . '/wp-json/vl-hub/v1/profile?license=' . rawurlencode($license), array(
    'timeout' => 10,
    'headers' => array('X-Luna-License' => $license),
  ));

  if (!is_wp_error($response)) {
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
      $body = wp_remote_retrieve_body($response);
      $profile = json_decode($body, true);
      if (is_array($profile)) {
        $profile = luna_hub_normalize_payload($profile);
      } else {
        $profile = null;
      }

      // Extract competitor URLs from enriched profile
      if (is_array($profile) && isset($profile['competitors']) && is_array($profile['competitors'])) {
        foreach ($profile['competitors'] as $competitor) {
          if (!empty($competitor['url'])) {
            $competitor_urls[] = $competitor['url'];
          } elseif (!empty($competitor['domain'])) {
            $competitor_urls[] = 'https://' . $competitor['domain'];
          }
        }
      }
    }
  }

  if (!empty($competitor_urls)) {
    $competitor_urls = array_values(array_unique(array_filter($competitor_urls)));
  }

  // Fetch reports for each competitor
  $competitor_reports = array();
  foreach ($competitor_urls as $competitor_url) {
    $report_url = add_query_arg(array(
      'license' => $license,
      'competitor_url' => $competitor_url,
    ), $url);

    $report_response = wp_remote_get($report_url, array(
      'timeout' => 10,
      'headers' => array('X-Luna-License' => $license),
    ));
    
    if (!is_wp_error($report_response)) {
      $report_code = wp_remote_retrieve_response_code($report_response);
      if ($report_code === 200) {
        $report_body = wp_remote_retrieve_body($report_response);
        $report_data = json_decode($report_body, true);
        
        if (!is_array($report_data)) {
          continue;
        }

        $report_payload = null;
        if (isset($report_data['success']) && $report_data['success'] && isset($report_data['report']) && is_array($report_data['report'])) {
          $report_payload = $report_data['report'];
        } elseif (isset($report_data['ok']) && $report_data['ok'] && isset($report_data['data']) && is_array($report_data['data'])) {
          $report_payload = $report_data['data'];
        }

        if ($report_payload === null) {
          continue;
        }

        $domain = parse_url($competitor_url, PHP_URL_HOST);
        if (!$domain) {
          $domain = $competitor_url;
        }

        $competitor_reports[] = array(
          'url' => $competitor_url,
          'domain' => $domain,
          'report' => $report_payload,
          'last_scanned' => $report_data['last_scanned'] ?? null,
          'status' => $report_data['status'] ?? null,
        );
      }
    }
  }
  
  return !empty($competitor_reports) ? array(
    'competitors' => $competitor_urls,
    'reports' => $competitor_reports,
  ) : null;
}

/**
 * Fetch VLDR (Domain Ranking) data from Hub with caching.
 * 
 * @param string $domain Domain to check
 * @param string|null $license License key
 * @return array|null VLDR data or null on failure
 */
function luna_fetch_vldr_data($domain, $license = null) {
  if (!$license) {
    $license = luna_get_license();
  }
  if (!$license || empty($domain)) return null;

  // Clean domain
  $domain = preg_replace('/^https?:\/\//', '', $domain);
  $domain = preg_replace('/^www\./', '', $domain);
  $domain = rtrim($domain, '/');
  $domain = strtolower($domain);

  // Cache key with 30-minute TTL
  $cache_key = 'luna_vldr_' . md5($license . '|' . $domain);
  $cached = get_transient($cache_key);
  if ($cached !== false && is_array($cached)) {
    return $cached;
  }

  // Fetch from Hub REST API
  $hub_url = luna_widget_hub_base();
  $url = $hub_url . '/wp-json/vl-hub/v1/vldr?license=' . rawurlencode($license) . '&domain=' . rawurlencode($domain);

  $response = wp_remote_get($url, array(
    'timeout' => 15,
    'sslverify' => true,
    'headers' => array(
      'Accept' => 'application/json',
      'X-Luna-License' => $license,
    ),
  ));

  if (is_wp_error($response)) {
    error_log('[Luna VLDR] Error fetching from Hub: ' . $response->get_error_message());
    return null;
  }

  $code = wp_remote_retrieve_response_code($response);
  if ($code !== 200) {
    error_log('[Luna VLDR] HTTP ' . $code . ' from Hub for domain: ' . $domain);
    return null;
  }

  $body = wp_remote_retrieve_body($response);
  $data = json_decode($body, true);

  if (!is_array($data)) {
    error_log('[Luna VLDR] Invalid JSON response from Hub');
    return null;
  }

  // Check for success response
  if (!empty($data['ok']) && !empty($data['data']) && is_array($data['data'])) {
    $vldr_data = $data['data'];
    
    // Cache for 30 minutes
    set_transient($cache_key, $vldr_data, 30 * MINUTE_IN_SECONDS);
    
    return $vldr_data;
  }

  // Check for direct data structure (if no wrapper)
  if (!empty($data['domain']) && isset($data['vldr_score'])) {
    set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);
    return $data;
  }

  return null;
}

// Get interactions count for Luna Widget
function luna_get_interactions_count() {
  $license = luna_get_license();
  if (!$license) return 0;
  
  // Get interactions count from stored data
  $interactions_data = get_option('luna_interactions_' . $license, array());
  return isset($interactions_data['total_interactions']) ? (int)$interactions_data['total_interactions'] : 0;
}

function luna_get_ai_chat_metrics() {
  // Get all conversations
  $all_conversations = get_posts(array(
    'post_type'      => 'luna_widget_convo',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'fields'         => 'ids',
  ));
  
  $metrics = array(
    'total_conversations' => 0,
    'total_messages' => 0,
    'active_conversations' => 0,
    'closed_conversations' => 0,
    'conversations_today' => 0,
    'conversations_this_week' => 0,
    'conversations_this_month' => 0,
    'average_messages_per_conversation' => 0,
    'total_user_messages' => 0,
    'total_assistant_messages' => 0,
  );
  
  if (empty($all_conversations)) {
    return $metrics;
  }
  
  $today = strtotime('today');
  $week_ago = strtotime('-7 days');
  $month_ago = strtotime('-30 days');
  
  $total_messages = 0;
  $total_user = 0;
  $total_assistant = 0;
  
  foreach ($all_conversations as $post_id) {
    $post_date = get_post_time('U', false, $post_id);
    $transcript = get_post_meta($post_id, 'transcript', true);
    $session_closed = get_post_meta($post_id, 'session_closed', true);
    
    if (!is_array($transcript)) {
      $transcript = array();
    }
    
    $message_count = count($transcript);
    $total_messages += $message_count;
    
    // Count user and assistant messages
    foreach ($transcript as $turn) {
      if (!empty($turn['user'])) {
        $total_user++;
      }
      if (!empty($turn['assistant'])) {
        $total_assistant++;
      }
    }
    
    // Count conversations by time period
    if ($post_date >= $today) {
      $metrics['conversations_today']++;
    }
    if ($post_date >= $week_ago) {
      $metrics['conversations_this_week']++;
    }
    if ($post_date >= $month_ago) {
      $metrics['conversations_this_month']++;
    }
    
    // Count active vs closed
    if ($session_closed) {
      $metrics['closed_conversations']++;
    } else {
      $metrics['active_conversations']++;
    }
  }
  
  $metrics['total_conversations'] = count($all_conversations);
  $metrics['total_messages'] = $total_messages;
  $metrics['total_user_messages'] = $total_user;
  $metrics['total_assistant_messages'] = $total_assistant;
  
  if ($metrics['total_conversations'] > 0) {
    $metrics['average_messages_per_conversation'] = round($total_messages / $metrics['total_conversations'], 1);
  }
  
  wp_reset_postdata();
  
  return $metrics;
}

// Get chat transcript for Luna Widget
function luna_get_chat_transcript($license_key) {
  if (empty($license_key)) return array();
  
  // Get chat transcript from stored data
  $transcript_data = get_option('luna_chat_transcript_' . $license_key, array());
  return $transcript_data;
}

// Calculate SEO score for posts and pages
function luna_calculate_seo_score($post_id) {
  $score = 0;
  $max_score = 100;
  
  // Title (20 points)
  $title = get_the_title($post_id);
  if (!empty($title)) {
    $score += 20;
    if (strlen($title) >= 30 && strlen($title) <= 60) {
      $score += 5; // Bonus for optimal length
    }
  }
  
  // Content (20 points)
  $content = get_post_field('post_content', $post_id);
  if (!empty($content)) {
    $score += 20;
    if (str_word_count($content) >= 300) {
      $score += 10; // Bonus for substantial content
    }
  }
  
  // Excerpt (10 points)
  $excerpt = get_the_excerpt($post_id);
  if (!empty($excerpt)) {
    $score += 10;
  }
  
  // Featured image (10 points)
  if (has_post_thumbnail($post_id)) {
    $score += 10;
  }
  
  // Categories/Tags (10 points)
  $categories = wp_get_post_terms($post_id, 'category');
  $tags = wp_get_post_terms($post_id, 'post_tag');
  if (!empty($categories) || !empty($tags)) {
    $score += 10;
  }
  
  // Meta description (10 points)
  $meta_description = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
  if (empty($meta_description)) {
    $meta_description = get_post_meta($post_id, '_aioseo_description', true);
  }
  if (!empty($meta_description)) {
    $score += 10;
  }
  
  // Focus keyword (10 points)
  $focus_keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
  if (empty($focus_keyword)) {
    $focus_keyword = get_post_meta($post_id, '_aioseo_keywords', true);
  }
  if (!empty($focus_keyword)) {
    $score += 10;
  }
  
  // Internal links (5 points)
  if (strpos($content, '<a href="' . home_url()) !== false) {
    $score += 5;
  }
  
  // External links (5 points)
  if (preg_match('/<a href="(?!' . preg_quote(home_url(), '/') . ')/', $content)) {
    $score += 5;
  }
  
  return min($score, $max_score);
}

// Track keyword usage and performance
function luna_track_keyword_usage($keyword_match, $response_success = true) {
  $usage_stats = get_option('luna_keyword_usage', []);
  
  $key = $keyword_match['category'] . '.' . $keyword_match['action'];
  
  if (!isset($usage_stats[$key])) {
    $usage_stats[$key] = [
      'total_uses' => 0,
      'successful_uses' => 0,
      'failed_uses' => 0,
      'last_used' => current_time('mysql'),
      'keywords' => $keyword_match['matched_term']
    ];
  }
  
  $usage_stats[$key]['total_uses']++;
  $usage_stats[$key]['last_used'] = current_time('mysql');
  
  if ($response_success) {
    $usage_stats[$key]['successful_uses']++;
  } else {
    $usage_stats[$key]['failed_uses']++;
  }
  
  update_option('luna_keyword_usage', $usage_stats);
}

// Get keyword performance statistics
function luna_get_keyword_performance() {
  $usage_stats = get_option('luna_keyword_usage', []);
  $performance = [];
  
  foreach ($usage_stats as $key => $stats) {
    $success_rate = $stats['total_uses'] > 0 ? ($stats['successful_uses'] / $stats['total_uses']) * 100 : 0;
    
    $performance[$key] = [
      'total_uses' => $stats['total_uses'],
      'success_rate' => round($success_rate, 1),
      'last_used' => $stats['last_used'],
      'keywords' => $stats['keywords']
    ];
  }
  
  // Sort by total uses (most popular first)
  uasort($performance, function($a, $b) {
    return $b['total_uses'] - $a['total_uses'];
  });
  
  return $performance;
}

// Check if user input matches any keywords
function luna_check_keyword_mappings($user_input) {
  $mappings = luna_get_keyword_mappings();
  $lc_input = strtolower(trim($user_input));
  
  // Debug: Log what we're checking
  error_log('Luna Keywords: Checking input: "' . $lc_input . '"');
  
  foreach ($mappings as $category => $keywords) {
    foreach ($keywords as $action => $config) {
      // Skip disabled keywords
      if (isset($config['enabled']) && $config['enabled'] !== 'on') {
        continue;
      }
      
      // Handle both old format (array of terms) and new format (config object)
      $terms = is_array($config) && isset($config['keywords']) ? $config['keywords'] : $config;
      
      if (!is_array($terms)) {
        continue;
      }
      
      foreach ($terms as $term) {
        $lc_term = strtolower(trim($term));
        if (empty($lc_term)) continue;
        
        // Use word boundary matching for more precise matching
        if (preg_match('/\b' . preg_quote($lc_term, '/') . '\b/', $lc_input)) {
          error_log('Luna Keywords: Matched term "' . $lc_term . '" for ' . $category . '.' . $action);
          return [
            'category' => $category,
            'action' => $action,
            'matched_term' => $term,
            'config' => is_array($config) && isset($config['template']) ? $config : null
          ];
        }
      }
    }
  }
  
  error_log('Luna Keywords: No keyword matches found');
  return null;
}

// Handle keyword-based responses using templates
function luna_handle_keyword_response($keyword_match, $facts) {
  $category = $keyword_match['category'];
  $action = $keyword_match['action'];
  $matched_term = $keyword_match['matched_term'];
  $config = $keyword_match['config'];
  
  // If we have a template config, use it
  if ($config) {
    $data_source = $config['data_source'] ?? 'custom';
    $response_type = $config['response_type'] ?? 'simple';
    
    switch ($data_source) {
      case 'wp_rest':
        return luna_process_response_template($config['wp_template'] ?? '', 'wp_rest', $facts);
      case 'security':
        return luna_process_response_template($config['security_template'] ?? '', 'security', $facts);
      case 'custom':
      default:
        if ($response_type === 'advanced') {
          // For advanced responses, we'll return the initial response
          // The branching logic would be handled in a more complex conversation flow
          return luna_process_response_template($config['initial_response'] ?? '', 'custom', $facts);
        } else {
          return luna_process_response_template($config['template'] ?? '', 'custom', $facts);
        }
    }
  }
  
  // Fallback to old system for backward compatibility
  switch ($category) {
    case 'business':
      return luna_handle_business_keyword($action, $facts);
      
    case 'wp_rest':
      return luna_handle_wp_rest_keyword($action, $facts);
      
    case 'security':
      return luna_handle_security_keyword($action, $facts);
      
    default:
      return null;
  }
}

// Process response templates with dynamic data
function luna_process_response_template($template, $data_source, $facts) {
  $response = $template;
  
  // Replace template variables based on data source
  switch ($data_source) {
    case 'wp_rest':
      $response = luna_replace_wp_rest_variables($response, $facts);
      break;
      
    case 'security':
      $response = luna_replace_security_variables($response, $facts);
      break;
      
    case 'custom':
      $response = luna_replace_custom_shortcodes($response, $facts);
      break;
  }
  
  return $response;
}

// Replace WP REST API variables in templates
function luna_replace_wp_rest_variables($template, $facts) {
  $replacements = [];
  
  // Pages list
  if (strpos($template, '{pages_list}') !== false) {
    if (isset($facts['pages']) && is_array($facts['pages']) && !empty($facts['pages'])) {
      $page_names = array();
      foreach ($facts['pages'] as $page) {
        $status = isset($page['status']) ? $page['status'] : 'published';
        $page_names[] = $page['title'] . " (" . $status . ")";
      }
      $replacements['{pages_list}'] = implode(", ", $page_names);
    } else {
      $replacements['{pages_list}'] = "No pages found";
    }
  }
  
  // Posts list
  if (strpos($template, '{posts_list}') !== false) {
    if (isset($facts['posts']) && is_array($facts['posts']) && !empty($facts['posts'])) {
      $post_names = array();
      foreach ($facts['posts'] as $post) {
        $status = isset($post['status']) ? $post['status'] : 'published';
        $post_names[] = $post['title'] . " (" . $status . ")";
      }
      $replacements['{posts_list}'] = implode(", ", $post_names);
    } else {
      $replacements['{posts_list}'] = "No posts found";
    }
  }
  
  // Themes list
  if (strpos($template, '{themes_list}') !== false) {
    if (isset($facts['themes']) && is_array($facts['themes']) && !empty($facts['themes'])) {
      $active_themes = array();
      $inactive_themes = array();
      foreach ($facts['themes'] as $theme) {
        if (isset($theme['is_active']) && $theme['is_active']) {
          $active_themes[] = $theme['name'] . " (Active)";
        } else {
          $inactive_themes[] = $theme['name'] . " (Inactive)";
        }
      }
      $all_themes = array_merge($active_themes, $inactive_themes);
      $replacements['{themes_list}'] = implode(", ", $all_themes);
    } else {
      $replacements['{themes_list}'] = "No themes found";
    }
  }
  
  // Plugins list
  if (strpos($template, '{plugins_list}') !== false) {
    if (isset($facts['plugins']) && is_array($facts['plugins']) && !empty($facts['plugins'])) {
      $plugin_names = array();
      foreach ($facts['plugins'] as $plugin) {
        $status = isset($plugin['active']) && $plugin['active'] ? 'Active' : 'Inactive';
        $plugin_names[] = $plugin['name'] . " (" . $status . ")";
      }
      $replacements['{plugins_list}'] = implode(", ", $plugin_names);
    } else {
      $replacements['{plugins_list}'] = "No plugins found";
    }
  }
  
  // User count
  if (strpos($template, '{user_count}') !== false) {
    $user_count = isset($facts['users']) && is_array($facts['users']) ? count($facts['users']) : 0;
    $replacements['{user_count}'] = $user_count;
    $replacements['{user_plural}'] = $user_count === 1 ? '' : 's';
  }
  
  // Update counts
  if (strpos($template, '{plugin_updates}') !== false) {
    $replacements['{plugin_updates}'] = (int)($facts['updates']['plugins'] ?? 0);
  }
  if (strpos($template, '{theme_updates}') !== false) {
    $replacements['{theme_updates}'] = (int)($facts['updates']['themes'] ?? 0);
  }
  if (strpos($template, '{core_updates}') !== false) {
    $replacements['{core_updates}'] = (int)($facts['updates']['core'] ?? 0);
  }
  
  // Apply all replacements
  foreach ($replacements as $placeholder => $value) {
    $template = str_replace($placeholder, $value, $template);
  }
  
  return $template;
}

// Replace security variables in templates
function luna_replace_security_variables($template, $facts) {
  if (strpos($template, '{ssl_status}') !== false) {
    if (!empty($facts['tls']['valid'])) {
      $extras = array();
      if (!empty($facts['tls']['issuer'])) $extras[] = "issuer: " . $facts['tls']['issuer'];
      if (!empty($facts['tls']['expires'])) $extras[] = "expires: " . $facts['tls']['expires'];
      $ssl_status = "Yes—TLS/SSL is active for " . $facts['site_url'] . ($extras ? " (" . implode(', ', $extras) . ")." : ".");
    } else {
      $ssl_status = "Hub shows TLS/SSL is not confirmed active for " . $facts['site_url'] . ". Please review the Security tab in Visible Light.";
    }
    $template = str_replace('{ssl_status}', $ssl_status, $template);
  }
  
  return $template;
}

// Replace custom shortcodes in templates
function luna_replace_custom_shortcodes($template, $facts) {
  $replacements = [];
  
  // Contact page link
  if (strpos($template, '[contact_page]') !== false) {
    $contact_url = get_permalink(get_page_by_path('contact'));
    if (!$contact_url) {
      $contact_url = home_url('/contact/');
    }
    $replacements['[contact_page]'] = '<a href="' . esc_url($contact_url) . '" target="_blank">Contact Page</a>';
  }
  
  // Booking link
  if (strpos($template, '[booking_link]') !== false) {
    $booking_url = get_permalink(get_page_by_path('book'));
    if (!$booking_url) {
      $booking_url = home_url('/book/');
    }
    $replacements['[booking_link]'] = '<a href="' . esc_url($booking_url) . '" target="_blank">Book Appointment</a>';
  }
  
  // Phone number
  if (strpos($template, '[phone_number]') !== false) {
    $phone = get_option('luna_business_phone', '(555) 123-4567');
    $replacements['[phone_number]'] = '<a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>';
  }
  
  // Email link
  if (strpos($template, '[email_link]') !== false) {
    $email = get_option('luna_business_email', 'info@example.com');
    $replacements['[email_link]'] = '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
  }
  
  // Site URL
  if (strpos($template, '[site_url]') !== false) {
    $replacements['[site_url]'] = '<a href="' . esc_url(home_url()) . '" target="_blank">' . esc_html(get_bloginfo('name')) . '</a>';
  }
  
  // Business name
  if (strpos($template, '[business_name]') !== false) {
    $business_name = get_option('luna_business_name', get_bloginfo('name'));
    $replacements['[business_name]'] = esc_html($business_name);
  }
  
  return str_replace(array_keys($replacements), array_values($replacements), $template);
}

// Handle business-specific keywords
function luna_handle_business_keyword($action, $facts) {
  switch ($action) {
    case 'appointment':
      return "To schedule an appointment, please call our office or use our online booking system. You can find our contact information on our website.";
      
    case 'contact':
      return "You can reach us through our contact page or by calling our main office number. Our contact information is available on our website.";
      
    case 'hours':
      return "Our business hours are typically Monday through Friday, 9 AM to 5 PM. Please check our website for the most current hours and holiday schedules.";
      
    case 'location':
      return "You can find our address and directions on our website's contact page. We're located in a convenient area with parking available.";
      
    case 'services':
      return "We offer a comprehensive range of services. Please visit our services page on our website for detailed information about what we provide.";
      
    case 'providers':
      return "Our team of experienced providers is dedicated to your care. You can learn more about our staff on our website's team page.";
      
    case 'insurance':
      return "We accept most major insurance plans. Please contact our billing department to verify your coverage and discuss payment options.";
      
    case 'forms':
      return "You can download patient forms from our website or pick them up at our office. Please complete them before your visit to save time.";
      
    default:
      return null;
  }
}

// Handle WP REST API keywords
function luna_handle_wp_rest_keyword($action, $facts) {
  switch ($action) {
    case 'pages':
      if (isset($facts['pages']) && is_array($facts['pages']) && !empty($facts['pages'])) {
        $page_names = array();
        foreach ($facts['pages'] as $page) {
          $status = isset($page['status']) ? $page['status'] : 'published';
          $page_names[] = $page['title'] . " (" . $status . ")";
        }
        return "Your pages are: " . implode(", ", $page_names) . ".";
      }
      return "I don't see any pages in your site data.";
      
    case 'posts':
      if (isset($facts['posts']) && is_array($facts['posts']) && !empty($facts['posts'])) {
        $post_names = array();
        foreach ($facts['posts'] as $post) {
          $status = isset($post['status']) ? $post['status'] : 'published';
          $post_names[] = $post['title'] . " (" . $status . ")";
        }
        return "Your posts are: " . implode(", ", $post_names) . ".";
      }
      return "I don't see any posts in your site data.";
      
    case 'themes':
      if (isset($facts['themes']) && is_array($facts['themes']) && !empty($facts['themes'])) {
        $active_themes = array();
        $inactive_themes = array();
        foreach ($facts['themes'] as $theme) {
          if (isset($theme['is_active']) && $theme['is_active']) {
            $active_themes[] = $theme['name'] . " (Active)";
          } else {
            $inactive_themes[] = $theme['name'] . " (Inactive)";
          }
        }
        $all_themes = array_merge($active_themes, $inactive_themes);
        return "Your themes are: " . implode(", ", $all_themes) . ".";
      }
      return "I don't see any themes in your site data.";
      
    case 'plugins':
      if (isset($facts['plugins']) && is_array($facts['plugins']) && !empty($facts['plugins'])) {
        $plugin_names = array();
        foreach ($facts['plugins'] as $plugin) {
          $status = isset($plugin['active']) && $plugin['active'] ? 'Active' : 'Inactive';
          $plugin_names[] = $plugin['name'] . " (" . $status . ")";
        }
        return "Your plugins are: " . implode(", ", $plugin_names) . ".";
      }
      return "I don't see any plugins in your site data.";
      
    case 'updates':
      $pu = (int)($facts['updates']['plugins'] ?? 0);
      $tu = (int)($facts['updates']['themes'] ?? 0);
      $cu = (int)($facts['updates']['core'] ?? 0);
      return "Updates pending — plugins: " . $pu . ", themes: " . $tu . ", WordPress Core: " . $cu . ".";
      
    default:
      return null;
  }
}

// Handle security keywords
function luna_handle_security_keyword($action, $facts) {
  switch ($action) {
    case 'ssl':
      if (!empty($facts['tls']['valid'])) {
        $extras = array();
        if (!empty($facts['tls']['issuer'])) $extras[] = "issuer: " . $facts['tls']['issuer'];
        if (!empty($facts['tls']['expires'])) $extras[] = "expires: " . $facts['tls']['expires'];
        return "Yes—TLS/SSL is active for " . $facts['site_url'] . ($extras ? " (" . implode(', ', $extras) . ")." : ".");
      }
      return "Hub shows TLS/SSL is not confirmed active for " . $facts['site_url'] . ". Please review the Security tab in Visible Light.";
      
    case 'firewall':
      return "Firewall protection status is available in your security settings. Please check the Security tab in Visible Light for detailed firewall information.";
      
    case 'backup':
      return "Backup information is available in your security profile. Please check the Security tab in Visible Light for backup status and schedules.";
      
    case 'monitoring':
      return "Security monitoring details are available in your security profile. Please check the Security tab in Visible Light for scan results and alerts.";
      
    case 'access':
      if (isset($facts['users']) && is_array($facts['users']) && !empty($facts['users'])) {
        $user_count = count($facts['users']);
        return "You have " . $user_count . " user" . ($user_count === 1 ? '' : 's') . " with access to your site.";
      }
      return "User access information is available in your security profile.";
      
    case 'compliance':
      return "Compliance information is available in your security profile. Please check the Security tab in Visible Light for compliance status and requirements.";
      
    default:
      return null;
  }
}

// Keywords admin page with enhanced template system
function luna_widget_keywords_admin_page() {
  if (isset($_POST['save_keywords'])) {
    check_admin_referer('luna_keywords_nonce');
    
    // Debug: Show what's being submitted (temporarily disabled)
    // echo '<div style="background: #e7f3ff; padding: 10px; margin: 10px 0; border: 1px solid #0073aa;">';
    // echo '<h4>Debug: POST Data Received</h4>';
    // echo '<pre>' . print_r($_POST, true) . '</pre>';
    // echo '</div>';
    
    // Process the form data properly
    if (isset($_POST['keywords'])) {
      $processed_keywords = array();
      
      foreach ($_POST['keywords'] as $category => $actions) {
        $processed_keywords[$category] = array();
        
        foreach ($actions as $action => $config) {
          // Skip if no keywords provided
          if (empty($config['keywords'])) {
            continue;
          }
          
          // Process keywords - split by comma and trim
          $keywords_array = array_map('trim', explode(',', $config['keywords']));
          $keywords_array = array_filter($keywords_array); // Remove empty values
          
          if (empty($keywords_array)) {
            continue;
          }
          
          $processed_config = array(
            'enabled' => isset($config['enabled']) ? 'on' : 'off',
            'keywords' => $keywords_array,
            'template' => sanitize_textarea_field($config['template'] ?? ''),
            'data_source' => sanitize_text_field($config['data_source'] ?? 'custom'),
            'response_type' => sanitize_text_field($config['response_type'] ?? 'simple')
          );
          
          // Add additional fields if they exist
          if (isset($config['wp_template'])) {
            $processed_config['wp_template'] = sanitize_textarea_field($config['wp_template']);
          }
          if (isset($config['security_template'])) {
            $processed_config['security_template'] = sanitize_textarea_field($config['security_template']);
          }
          if (isset($config['initial_response'])) {
            $processed_config['initial_response'] = sanitize_textarea_field($config['initial_response']);
          }
          if (isset($config['branches'])) {
            $processed_config['branches'] = $config['branches'];
          }
          
          $processed_keywords[$category][$action] = $processed_config;
        }
      }
      
      // Save the processed keywords
      update_option('luna_keyword_mappings', $processed_keywords);
      
      // Debug: Show what was saved (temporarily disabled)
      // echo '<div style="background: #d4edda; padding: 10px; margin: 10px 0; border: 1px solid #c3e6cb;">';
      // echo '<h4>Debug: Processed and Saved Keywords</h4>';
      // echo '<pre>' . print_r($processed_keywords, true) . '</pre>';
      // echo '</div>';
      
      // Sync to Hub
      luna_sync_keywords_to_hub();
      
      echo '<div class="notice notice-success"><p>Keywords saved and synced to Hub!</p></div>';
    }
  }
  
  // Load mappings for display - merge with defaults to show all keywords
  $saved_mappings = get_option('luna_keyword_mappings', []);
  $default_mappings = luna_get_default_keywords();
  $mappings = [];
  
  // Start with defaults
  foreach ($default_mappings as $category => $keywords) {
    $mappings[$category] = [];
    foreach ($keywords as $action => $default_config) {
      // Use saved data if it exists, otherwise use default
      if (isset($saved_mappings[$category][$action])) {
        $mappings[$category][$action] = $saved_mappings[$category][$action];
      } else {
        $mappings[$category][$action] = $default_config;
      }
    }
  }
  
  // Add any custom keywords that aren't in defaults
  foreach ($saved_mappings as $category => $keywords) {
    if (!isset($mappings[$category])) {
      $mappings[$category] = [];
    }
    foreach ($keywords as $action => $config) {
      if (!isset($mappings[$category][$action])) {
        $mappings[$category][$action] = $config;
      }
    }
  }
  
  // Debug: Show what we're working with (temporarily disabled)
  // echo '<div style="background: #f0f0f0; padding: 10px; margin: 10px 0; border: 1px solid #ccc;">';
  // echo '<h4>Debug: Current Mappings</h4>';
  // echo '<pre>' . print_r($mappings, true) . '</pre>';
  // echo '</div>';
  ?>
    <div class="wrap">
      <h1>Luna Chat Keywords & Templates</h1>
      <p>Configure keyword mappings and response templates to help Luna understand your business terminology and respond more accurately.</p>
      
      <div style="margin: 20px 0;">
        <button type="button" id="add-new-keyword" class="button button-primary">+ Add New Keyword</button>
        <button type="button" id="add-new-category" class="button">+ Add New Category</button>
        <button type="button" id="manage-keywords" class="button">Manage Existing Keywords</button>
      </div>
      
      <!-- Modal for adding new keyword -->
      <div id="keyword-modal" class="luna-modal" style="display: none;">
        <div class="luna-modal-content">
          <div class="luna-modal-header">
            <h2>Add New Keyword</h2>
            <span class="luna-modal-close">&times;</span>
          </div>
          <div class="luna-modal-body">
            <table class="form-table">
              <tr>
                <th scope="row">Category</th>
                <td>
                  <select id="new-keyword-category" class="regular-text">
                    <option value="business">Business</option>
                    <option value="wp_rest">WordPress Data</option>
                    <option value="security">Security</option>
                    <option value="custom">Custom</option>
                  </select>
                  <p class="description">Select the category for this keyword</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Keyword Name</th>
                <td>
                  <input type="text" id="new-keyword-name" class="regular-text" placeholder="e.g., pricing, hours, support">
                  <p class="description">Enter a unique name for this keyword</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Keywords</th>
                <td>
                  <input type="text" id="new-keyword-terms" class="regular-text" placeholder="Enter keywords separated by commas">
                  <p class="description">Words or phrases that will trigger this response</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Data Source</th>
                <td>
                  <select id="new-keyword-data-source" class="regular-text">
                    <option value="custom">Custom Response</option>
                    <option value="wp_rest">WordPress Data</option>
                    <option value="security">Security Data</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row">Response Template</th>
                <td>
                  <textarea id="new-keyword-template" class="large-text" rows="3" placeholder="Enter your response template..."></textarea>
                </td>
              </tr>
            </table>
          </div>
          <div class="luna-modal-footer">
            <button type="button" id="save-new-keyword" class="button button-primary">Add Keyword</button>
            <button type="button" id="cancel-new-keyword" class="button">Cancel</button>
          </div>
        </div>
      </div>
      
      <!-- Modal for adding new category -->
      <div id="category-modal" class="luna-modal" style="display: none;">
        <div class="luna-modal-content">
          <div class="luna-modal-header">
            <h2>Add New Category</h2>
            <span class="luna-modal-close">&times;</span>
          </div>
          <div class="luna-modal-body">
            <table class="form-table">
              <tr>
                <th scope="row">Category Name</th>
                <td>
                  <input type="text" id="new-category-name" class="regular-text" placeholder="e.g., products, services, support">
                  <p class="description">Enter a name for the new category</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Description</th>
                <td>
                  <input type="text" id="new-category-description" class="regular-text" placeholder="Brief description of this category">
                  <p class="description">Optional description for this category</p>
                </td>
              </tr>
            </table>
          </div>
          <div class="luna-modal-footer">
            <button type="button" id="save-new-category" class="button button-primary">Add Category</button>
            <button type="button" id="cancel-new-category" class="button">Cancel</button>
          </div>
        </div>
      </div>
      
      <!-- Modal for managing existing keywords -->
      <div id="manage-modal" class="luna-modal" style="display: none;">
        <div class="luna-modal-content" style="width: 80%; max-width: 800px;">
          <div class="luna-modal-header">
            <h2>Manage Existing Keywords</h2>
            <span class="luna-modal-close">&times;</span>
          </div>
          <div class="luna-modal-body">
            <p>Move existing keywords to different categories:</p>
            <div id="keyword-management-list"></div>
          </div>
          <div class="luna-modal-footer">
            <button type="button" id="save-keyword-changes" class="button button-primary">Save Changes</button>
            <button type="button" id="cancel-keyword-changes" class="button">Cancel</button>
          </div>
        </div>
      </div>
    
    <div class="luna-keywords-help">
      <h3>Template Variables</h3>
      <p>Use these variables in your response templates:</p>
      <ul>
        <li><code>{pages_list}</code> - List of pages with status</li>
        <li><code>{posts_list}</code> - List of posts with status</li>
        <li><code>{themes_list}</code> - List of themes with active status</li>
        <li><code>{plugins_list}</code> - List of plugins with active status</li>
        <li><code>{user_count}</code> - Number of users</li>
        <li><code>{user_plural}</code> - "s" if multiple users, "" if single</li>
        <li><code>{plugin_updates}</code> - Number of plugin updates available</li>
        <li><code>{theme_updates}</code> - Number of theme updates available</li>
        <li><code>{core_updates}</code> - Number of WordPress core updates available</li>
        <li><code>{ssl_status}</code> - SSL certificate status</li>
      </ul>
    </div>
    
    <form method="post">
      <?php wp_nonce_field('luna_keywords_nonce'); ?>
      
      <div class="luna-keywords-container">
        <?php foreach ($mappings as $category => $keywords): ?>
          <div class="luna-keyword-category">
            <h3><?php echo ucfirst($category); ?> Keywords</h3>
            <table class="form-table">
              <?php foreach ($keywords as $action => $config): ?>
                <?php 
                // Handle both old format (array of terms) and new format (config object)
                $terms = is_array($config) && isset($config['keywords']) ? $config['keywords'] : $config;
                $template = is_array($config) && isset($config['template']) ? $config['template'] : '';
                $data_source = is_array($config) && isset($config['data_source']) ? $config['data_source'] : 'custom';
                $enabled = is_array($config) && isset($config['enabled']) ? $config['enabled'] : 'off';
                
                // Debug: Show enabled state for this keyword (only in debug mode)
                if (WP_DEBUG) {
                  echo "<!-- DEBUG: {$category}.{$action} - enabled: {$enabled} -->";
                }
                ?>
                <tr>
                  <th scope="row"><?php echo ucfirst($action); ?></th>
                  <td>
                    <div class="luna-keyword-config">
                      <div class="luna-keyword-field">
                        <label>
                          <input type="checkbox" name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][enabled]" 
                                 value="on" <?php checked('on', $enabled); ?> 
                                 onchange="luna_toggle_keyword('<?php echo $category; ?>', '<?php echo $action; ?>')">
                          Enable this keyword
                        </label>
                      </div>
                      
                      <div class="luna-keyword-field">
                        <label>Keywords:</label>
                        <input type="text" name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][terms]" 
                               value="<?php echo esc_attr(is_array($terms) ? implode(', ', $terms) : $terms); ?>" 
                               class="regular-text" 
                               placeholder="Enter keywords separated by commas">
                      </div>
                      
                      <div class="luna-keyword-field">
                        <label>Data Source:</label>
                        <select name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][data_source]" 
                                onchange="luna_toggle_data_source_options(this, '<?php echo $category; ?>', '<?php echo $action; ?>')">
                          <option value="custom" <?php selected($data_source, 'custom'); ?>>Custom Response</option>
                          <option value="wp_rest" <?php selected($data_source, 'wp_rest'); ?>>WordPress Data</option>
                          <option value="security" <?php selected($data_source, 'security'); ?>>Security Data</option>
                        </select>
                      </div>
                      
                      <!-- WordPress Data Options -->
                      <div class="luna-data-source-options luna-wp-rest-options" 
                           style="display: <?php echo $data_source === 'wp_rest' ? 'block' : 'none'; ?>;">
                        <div class="luna-keyword-field">
                          <label>WordPress Data Response:</label>
                          <textarea name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][wp_template]" 
                                    class="large-text" rows="3" 
                                    placeholder="Use variables: {pages_list}, {posts_list}, {themes_list}, {plugins_list}, {user_count}, {user_plural}, {plugin_updates}, {theme_updates}, {core_updates}"><?php echo esc_textarea($config['wp_template'] ?? ''); ?></textarea>
                          <p class="description">
                            <strong>Available Variables:</strong><br>
                            <code>{pages_list}</code> - List of pages with status<br>
                            <code>{posts_list}</code> - List of posts with status<br>
                            <code>{themes_list}</code> - List of themes with active status<br>
                            <code>{plugins_list}</code> - List of plugins with active status<br>
                            <code>{user_count}</code> - Number of users<br>
                            <code>{user_plural}</code> - "s" if multiple users, "" if single<br>
                            <code>{plugin_updates}</code> - Number of plugin updates available<br>
                            <code>{theme_updates}</code> - Number of theme updates available<br>
                            <code>{core_updates}</code> - Number of WordPress core updates available
                          </p>
                        </div>
                        <div class="luna-keyword-field">
                          <label>Shortcode Generator:</label>
                          <select onchange="luna_insert_shortcode(this.value, 'keywords[<?php echo $category; ?>][<?php echo $action; ?>][wp_template]')">
                            <option value="">Select a shortcode to insert...</option>
                            <option value="{pages_list}">Pages List</option>
                            <option value="{posts_list}">Posts List</option>
                            <option value="{themes_list}">Themes List</option>
                            <option value="{plugins_list}">Plugins List</option>
                            <option value="{user_count}">User Count</option>
                            <option value="{plugin_updates}">Plugin Updates</option>
                            <option value="{theme_updates}">Theme Updates</option>
                            <option value="{core_updates}">Core Updates</option>
                          </select>
                        </div>
                      </div>
                      
                      <!-- Security Data Options -->
                      <div class="luna-data-source-options luna-security-options" 
                           style="display: <?php echo $data_source === 'security' ? 'block' : 'none'; ?>;">
                        <div class="luna-keyword-field">
                          <label>Security Data Response:</label>
                          <textarea name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][security_template]" 
                                    class="large-text" rows="3" 
                                    placeholder="Use variables: {ssl_status}, {firewall_status}, {backup_status}, {monitoring_status}"><?php echo esc_textarea($config['security_template'] ?? ''); ?></textarea>
                          <p class="description">
                            <strong>Available Variables:</strong><br>
                            <code>{ssl_status}</code> - SSL certificate status<br>
                            <code>{firewall_status}</code> - Firewall protection status<br>
                            <code>{backup_status}</code> - Backup information<br>
                            <code>{monitoring_status}</code> - Security monitoring details
                          </p>
                        </div>
                        <div class="luna-keyword-field">
                          <label>Shortcode Generator:</label>
                          <select onchange="luna_insert_shortcode(this.value, 'keywords[<?php echo $category; ?>][<?php echo $action; ?>][security_template]')">
                            <option value="">Select a shortcode to insert...</option>
                            <option value="{ssl_status}">SSL Status</option>
                            <option value="{firewall_status}">Firewall Status</option>
                            <option value="{backup_status}">Backup Status</option>
                            <option value="{monitoring_status}">Monitoring Status</option>
                          </select>
                        </div>
                      </div>
                      
                      <!-- Custom Response Options -->
                      <div class="luna-data-source-options luna-custom-options" 
                           style="display: <?php echo $data_source === 'custom' ? 'block' : 'none'; ?>;">
                        <div class="luna-response-type">
                          <label>
                            <input type="radio" name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][response_type]" 
                                   value="simple" <?php checked($config['response_type'] ?? 'simple', 'simple'); ?> 
                                   onchange="luna_toggle_response_type('<?php echo $category; ?>', '<?php echo $action; ?>', 'simple')">
                            Simple Text Response
                          </label>
                          <label>
                            <input type="radio" name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][response_type]" 
                                   value="advanced" <?php checked($config['response_type'] ?? 'simple', 'advanced'); ?> 
                                   onchange="luna_toggle_response_type('<?php echo $category; ?>', '<?php echo $action; ?>', 'advanced')">
                            Advanced Conversation Flows
                          </label>
                        </div>
                        
                        <!-- Simple Text Response -->
                        <div class="luna-simple-response" 
                             style="display: <?php echo ($config['response_type'] ?? 'simple') === 'simple' ? 'block' : 'none'; ?>;">
                          <div class="luna-keyword-field">
                            <label>Response Template:</label>
                            <textarea name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][template]" 
                                      class="large-text" rows="3" 
                                      placeholder="Enter your response template..."><?php echo esc_textarea($template); ?></textarea>
                            <p class="description">
                              <strong>Available Shortcodes:</strong><br>
                              <code>[contact_page]</code> - Link to contact page<br>
                              <code>[booking_link]</code> - Link to booking page<br>
                              <code>[phone_number]</code> - Phone number link<br>
                              <code>[email_link]</code> - Email link<br>
                              <code>[site_url]</code> - Site URL<br>
                              <code>[business_name]</code> - Business name
                            </p>
                          </div>
                          <div class="luna-keyword-field">
                            <label>Shortcode Generator:</label>
                            <select onchange="luna_insert_shortcode(this.value, 'keywords[<?php echo $category; ?>][<?php echo $action; ?>][template]')">
                              <option value="">Select a shortcode to insert...</option>
                              <option value="[contact_page]">Contact Page Link</option>
                              <option value="[booking_link]">Booking Link</option>
                              <option value="[phone_number]">Phone Number</option>
                              <option value="[email_link]">Email Link</option>
                              <option value="[site_url]">Site URL</option>
                              <option value="[business_name]">Business Name</option>
                            </select>
                          </div>
                        </div>
                        
                        <!-- Advanced Conversation Flows -->
                        <div class="luna-advanced-response" 
                             style="display: <?php echo ($config['response_type'] ?? 'simple') === 'advanced' ? 'block' : 'none'; ?>;">
                          <div class="luna-keyword-field">
                            <label>Initial Response:</label>
                            <textarea name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][initial_response]" 
                                      class="large-text" rows="2" 
                                      placeholder="What should Luna say first?"><?php echo esc_textarea($config['initial_response'] ?? ''); ?></textarea>
                          </div>
                          <div class="luna-keyword-field">
                            <label>Follow-up Responses:</label>
                            <div class="luna-branch-responses">
                              <div class="luna-branch-item">
                                <input type="text" name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][branches][yes][trigger]" 
                                       placeholder="User says (e.g., 'yes', 'sure', 'okay')" 
                                       value="<?php echo esc_attr($config['branches']['yes']['trigger'] ?? 'yes'); ?>">
                                <textarea name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][branches][yes][response]" 
                                          placeholder="Luna responds..." 
                                          rows="2"><?php echo esc_textarea($config['branches']['yes']['response'] ?? ''); ?></textarea>
                              </div>
                              <div class="luna-branch-item">
                                <input type="text" name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][branches][no][trigger]" 
                                       placeholder="User says (e.g., 'no', 'not now', 'maybe later')" 
                                       value="<?php echo esc_attr($config['branches']['no']['trigger'] ?? 'no'); ?>">
                                <textarea name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][branches][no][response]" 
                                          placeholder="Luna responds..." 
                                          rows="2"><?php echo esc_textarea($config['branches']['no']['response'] ?? ''); ?></textarea>
                              </div>
                              <div class="luna-branch-item">
                                <input type="text" name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][branches][maybe][trigger]" 
                                       placeholder="User says (e.g., 'maybe', 'not sure', 'tell me more')" 
                                       value="<?php echo esc_attr($config['branches']['maybe']['trigger'] ?? 'maybe'); ?>">
                                <textarea name="keywords[<?php echo $category; ?>][<?php echo $action; ?>][branches][maybe][response]" 
                                          placeholder="Luna responds..." 
                                          rows="2"><?php echo esc_textarea($config['branches']['maybe']['response'] ?? ''); ?></textarea>
                              </div>
                            </div>
                            <p class="description">Define how Luna should respond based on different user inputs.</p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php endforeach; ?>
      </div>
      
      <p class="submit">
        <input type="submit" name="save_keywords" class="button-primary" value="Save Keywords & Templates">
        <a href="#" class="button" onclick="luna_export_keywords(); return false;">Export Keywords</a>
        <a href="#" class="button" onclick="luna_import_keywords(); return false;">Import Keywords</a>
      </p>
    </form>
  </div>
  
  <style>
    .luna-keywords-container {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
      gap: 20px;
      margin: 20px 0;
    }
    .luna-keyword-category {
      border: 1px solid #ddd;
      padding: 15px;
      border-radius: 5px;
      background: #f9f9f9;
    }
    .luna-keyword-category h3 {
      margin-top: 0;
      color: #23282d;
    }
    .luna-keyword-config {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .luna-keyword-field {
      display: flex;
      flex-direction: column;
    }
    .luna-keyword-field label {
      font-weight: bold;
      margin-bottom: 5px;
    }
    .luna-keywords-help {
      background: #e7f3ff;
      border: 1px solid #0073aa;
      border-radius: 5px;
      padding: 15px;
      margin: 20px 0;
    }
    .luna-keywords-help h3 {
      margin-top: 0;
      color: #0073aa;
    }
    .luna-keywords-help code {
      background: #fff;
      padding: 2px 4px;
      border-radius: 3px;
      font-family: monospace;
    }
    
    /* Modal Styles */
    .luna-modal {
      position: fixed;
      z-index: 100000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .luna-modal-content {
      background-color: #fff;
      border-radius: 4px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      width: 90%;
      max-width: 600px;
      max-height: 90vh;
      overflow-y: auto;
    }
    
    .luna-modal-header {
      padding: 20px;
      border-bottom: 1px solid #ddd;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #f1f1f1;
    }
    
    .luna-modal-header h2 {
      margin: 0;
      font-size: 18px;
    }
    
    .luna-modal-close {
      font-size: 24px;
      font-weight: bold;
      cursor: pointer;
      color: #666;
    }
    
    .luna-modal-close:hover {
      color: #000;
    }
    
    .luna-modal-body {
      padding: 20px;
    }
    
    .luna-modal-footer {
      padding: 20px;
      border-top: 1px solid #ddd;
      text-align: right;
      background: #f9f9f9;
    }
    
    .luna-modal-footer .button {
      margin-left: 10px;
    }
    
    .keyword-management-item {
      display: flex;
      align-items: center;
      padding: 10px;
      border: 1px solid #ddd;
      border-radius: 4px;
      margin-bottom: 10px;
      background: #fff;
    }
    
    .keyword-management-item select {
      margin-left: 10px;
      min-width: 150px;
    }
    
    .keyword-management-item .keyword-info {
      flex: 1;
    }
    
    .keyword-management-item .keyword-name {
      font-weight: 600;
    }
    
    .keyword-management-item .keyword-terms {
      color: #666;
      font-size: 12px;
    }
  </style>
  
  <script>
    function luna_export_keywords() {
      // TODO: Implement keyword export functionality
      alert('Export functionality coming soon!');
    }
    
  function luna_import_keywords() {
    // TODO: Implement keyword import functionality
    alert('Import functionality coming soon!');
  }
  
  // Chat transcript functionality
  function showLunaChatTranscript(licenseKey) {
    // Create modal if it doesn't exist
    if (!document.getElementById('luna-chat-transcript-modal')) {
      var modal = document.createElement('div');
      modal.id = 'luna-chat-transcript-modal';
      modal.className = 'luna-modal';
      modal.innerHTML = `
        <div class="luna-modal-content">
          <div class="luna-modal-header">
            <h3>Luna Chat Transcript - License: ${licenseKey}</h3>
            <span class="luna-modal-close" onclick="closeLunaChatTranscript()">&times;</span>
          </div>
          <div class="luna-modal-body" id="luna-chat-transcript-content">
            <p>Loading chat transcript...</p>
          </div>
          <div class="luna-modal-footer" style="margin-top: 20px; text-align: right;">
            <button type="button" class="button" onclick="closeLunaChatTranscript()">Close</button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
    }
    
    // Show modal
    document.getElementById('luna-chat-transcript-modal').style.display = 'block';
    
    // Load transcript data
    loadLunaChatTranscript(licenseKey);
  }
  
  function closeLunaChatTranscript() {
    document.getElementById('luna-chat-transcript-modal').style.display = 'none';
  }
  
  function loadLunaChatTranscript(licenseKey) {
    // Make AJAX request to get chat transcript
    jQuery.ajax({
      url: '<?php echo admin_url('admin-ajax.php'); ?>',
      type: 'POST',
      data: {
        action: 'luna_get_chat_transcript',
        license_key: licenseKey,
        nonce: '<?php echo wp_create_nonce('luna_chat_transcript_nonce'); ?>'
      },
      success: function(response) {
        if (response.success) {
          var content = document.getElementById('luna-chat-transcript-content');
          if (response.data.transcript && response.data.transcript.length > 0) {
            var html = '<div class="luna-chat-transcript">';
            response.data.transcript.forEach(function(entry) {
              html += '<div class="luna-chat-entry ' + entry.type + '">';
              html += '<div style="font-weight: bold; color: #333; margin-bottom: 5px;">';
              html += (entry.type === 'user' ? '👤 User' : '🤖 Luna') + ' - ' + entry.timestamp;
              html += '</div>';
              html += '<div style="color: #555;">' + entry.message + '</div>';
              html += '</div>';
            });
            html += '</div>';
            content.innerHTML = html;
          } else {
            content.innerHTML = '<p>No chat transcript available for this license.</p>';
          }
        } else {
          document.getElementById('luna-chat-transcript-content').innerHTML = '<p>Error loading chat transcript: ' + response.data + '</p>';
        }
      },
      error: function() {
        document.getElementById('luna-chat-transcript-content').innerHTML = '<p>Error loading chat transcript. Please try again.</p>';
      }
    });
  }
  
  // Close modal when clicking outside
  window.onclick = function(event) {
    var modal = document.getElementById('luna-chat-transcript-modal');
    if (event.target === modal) {
      closeLunaChatTranscript();
    }
  }
  </script>
  <?php
}

// Analytics admin page
function luna_widget_analytics_admin_page() {
  $performance = luna_get_keyword_performance();
  ?>
  <div class="wrap">
    <h1>Luna Chat Analytics</h1>
    <p>Track keyword performance and usage statistics to optimize your Luna Chat experience.</p>
    
    <div class="notice notice-info">
      <p><strong>Note:</strong> GA4 Analytics integration has been moved to the <a href="https://visiblelight.ai/wp-admin/admin.php?page=vl-hub-profile" target="_blank">VL Client Hub Profile</a> for centralized management.</p>
    </div>
    
    <!-- Interactions Metric -->
    <div class="postbox" style="margin-top: 20px;">
      <h2 class="hndle">Chat Interactions</h2>
      <div class="inside">
        <?php
        $interactions_count = luna_get_interactions_count();
        $license = luna_get_license();
        ?>
        <div class="luna-interactions-metric" style="text-align: center; padding: 20px; background: #f9f9f9; border-radius: 5px; cursor: pointer;" onclick="showLunaChatTranscript('<?php echo esc_js($license); ?>')">
          <div style="font-size: 3em; font-weight: bold; color: #0073aa; margin-bottom: 10px;"><?php echo $interactions_count; ?></div>
          <div style="font-size: 1.2em; color: #666;">Total Interactions</div>
          <div style="font-size: 0.9em; color: #999; margin-top: 5px;">Click to view chat transcript</div>
        </div>
      </div>
    </div>
    
    <!-- AI Chat Metrics -->
    <div class="postbox" style="margin-top: 20px;">
      <h2 class="hndle">AI Chat Metrics</h2>
      <div class="inside">
        <?php
        $chat_metrics = luna_get_ai_chat_metrics();
        ?>
        <div class="luna-ai-chat-metrics">
          <div class="luna-metrics-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            <div class="luna-metric-box" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; text-align: center;">
              <div style="font-size: 2.5em; font-weight: bold; color: #0073aa; margin-bottom: 10px;"><?php echo number_format($chat_metrics['total_conversations']); ?></div>
              <div style="font-size: 1.1em; color: #666; font-weight: 600;">Total Conversations</div>
            </div>
            <div class="luna-metric-box" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; text-align: center;">
              <div style="font-size: 2.5em; font-weight: bold; color: #0073aa; margin-bottom: 10px;"><?php echo number_format($chat_metrics['total_messages']); ?></div>
              <div style="font-size: 1.1em; color: #666; font-weight: 600;">Total Messages</div>
            </div>
            <div class="luna-metric-box" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; text-align: center;">
              <div style="font-size: 2.5em; font-weight: bold; color: #00a32a; margin-bottom: 10px;"><?php echo number_format($chat_metrics['active_conversations']); ?></div>
              <div style="font-size: 1.1em; color: #666; font-weight: 600;">Active Conversations</div>
            </div>
            <div class="luna-metric-box" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; text-align: center;">
              <div style="font-size: 2.5em; font-weight: bold; color: #0073aa; margin-bottom: 10px;"><?php echo $chat_metrics['average_messages_per_conversation']; ?></div>
              <div style="font-size: 1.1em; color: #666; font-weight: 600;">Avg Messages/Conversation</div>
            </div>
          </div>
          
          <div class="luna-metrics-details" style="margin-top: 30px;">
            <h3 style="margin-bottom: 15px;">Activity Breakdown</h3>
            <div class="luna-metrics-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
              <div class="luna-metric-item" style="padding: 15px; background: #fff; border: 1px solid #e9ecef; border-radius: 6px;">
                <div style="font-size: 1.8em; font-weight: bold; color: #2271b1; margin-bottom: 5px;"><?php echo number_format($chat_metrics['conversations_today']); ?></div>
                <div style="font-size: 0.95em; color: #646970;">Today</div>
              </div>
              <div class="luna-metric-item" style="padding: 15px; background: #fff; border: 1px solid #e9ecef; border-radius: 6px;">
                <div style="font-size: 1.8em; font-weight: bold; color: #2271b1; margin-bottom: 5px;"><?php echo number_format($chat_metrics['conversations_this_week']); ?></div>
                <div style="font-size: 0.95em; color: #646970;">This Week</div>
              </div>
              <div class="luna-metric-item" style="padding: 15px; background: #fff; border: 1px solid #e9ecef; border-radius: 6px;">
                <div style="font-size: 1.8em; font-weight: bold; color: #2271b1; margin-bottom: 5px;"><?php echo number_format($chat_metrics['conversations_this_month']); ?></div>
                <div style="font-size: 0.95em; color: #646970;">This Month</div>
              </div>
              <div class="luna-metric-item" style="padding: 15px; background: #fff; border: 1px solid #e9ecef; border-radius: 6px;">
                <div style="font-size: 1.8em; font-weight: bold; color: #d63638; margin-bottom: 5px;"><?php echo number_format($chat_metrics['closed_conversations']); ?></div>
                <div style="font-size: 0.95em; color: #646970;">Closed</div>
              </div>
            </div>
          </div>
          
          <div class="luna-message-breakdown" style="margin-top: 30px;">
            <h3 style="margin-bottom: 15px;">Message Breakdown</h3>
            <div class="luna-metrics-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
              <div class="luna-metric-item" style="padding: 15px; background: #e3f2fd; border: 1px solid #90caf9; border-radius: 6px;">
                <div style="font-size: 1.8em; font-weight: bold; color: #1976d2; margin-bottom: 5px;"><?php echo number_format($chat_metrics['total_user_messages']); ?></div>
                <div style="font-size: 0.95em; color: #646970;">User Messages</div>
              </div>
              <div class="luna-metric-item" style="padding: 15px; background: #e8f5e9; border: 1px solid #81c784; border-radius: 6px;">
                <div style="font-size: 1.8em; font-weight: bold; color: #388e3c; margin-bottom: 5px;"><?php echo number_format($chat_metrics['total_assistant_messages']); ?></div>
                <div style="font-size: 0.95em; color: #646970;">Assistant Messages</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <?php if (empty($performance)): ?>
      <div class="notice notice-info">
        <p>No keyword usage data available yet. Start using Luna Chat to see analytics!</p>
      </div>
    <?php else: ?>
      <div class="luna-analytics-container">
        <div class="luna-analytics-summary">
          <h3>Summary</h3>
          <div class="luna-stats-grid">
            <div class="luna-stat-box">
              <h4>Total Keywords Used</h4>
              <span class="luna-stat-number"><?php echo count($performance); ?></span>
            </div>
            <div class="luna-stat-box">
              <h4>Total Interactions</h4>
              <span class="luna-stat-number"><?php echo array_sum(array_column($performance, 'total_uses')); ?></span>
            </div>
            <div class="luna-stat-box">
              <h4>Average Success Rate</h4>
              <span class="luna-stat-number"><?php 
                $avg_success = array_sum(array_column($performance, 'success_rate')) / count($performance);
                echo round($avg_success, 1) . '%';
              ?></span>
            </div>
          </div>
        </div>
        
        <div class="luna-analytics-details">
          <h3>Keyword Performance</h3>
          <table class="wp-list-table widefat fixed striped">
            <thead>
              <tr>
                <th>Keyword</th>
                <th>Category</th>
                <th>Total Uses</th>
                <th>Success Rate</th>
                <th>Last Used</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($performance as $key => $stats): ?>
                <?php 
                list($category, $action) = explode('.', $key, 2);
                $success_class = $stats['success_rate'] >= 80 ? 'success' : ($stats['success_rate'] >= 60 ? 'warning' : 'error');
                ?>
                <tr>
                  <td><strong><?php echo esc_html(ucfirst($action)); ?></strong></td>
                  <td><?php echo esc_html(ucfirst($category)); ?></td>
                  <td><?php echo $stats['total_uses']; ?></td>
                  <td>
                    <span class="luna-success-rate luna-<?php echo $success_class; ?>">
                      <?php echo $stats['success_rate']; ?>%
                    </span>
                  </td>
                  <td><?php echo esc_html($stats['last_used']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        
        <div class="luna-analytics-insights">
          <h3>Insights & Recommendations</h3>
          <div class="luna-insights">
            <?php
            $low_performing = array_filter($performance, function($stats) {
              return $stats['success_rate'] < 60 && $stats['total_uses'] > 2;
            });
            
            $unused = array_filter($performance, function($stats) {
              return $stats['total_uses'] == 0;
            });
            
            $high_performing = array_filter($performance, function($stats) {
              return $stats['success_rate'] >= 90 && $stats['total_uses'] > 5;
            });
            ?>
            
            <?php if (!empty($low_performing)): ?>
              <div class="luna-insight warning">
                <h4>⚠️ Low Performing Keywords</h4>
                <p>These keywords have low success rates and may need attention:</p>
                <ul>
                  <?php foreach ($low_performing as $key => $stats): ?>
                    <li><strong><?php echo esc_html(ucfirst(explode('.', $key)[1])); ?></strong> - <?php echo $stats['success_rate']; ?>% success rate</li>
                  <?php endforeach; ?>
                </ul>
                <p><em>Consider reviewing the response templates or adding more specific keywords.</em></p>
              </div>
            <?php endif; ?>
            
            <?php if (!empty($high_performing)): ?>
              <div class="luna-insight success">
                <h4>✅ High Performing Keywords</h4>
                <p>These keywords are working well:</p>
                <ul>
                  <?php foreach ($high_performing as $key => $stats): ?>
                    <li><strong><?php echo esc_html(ucfirst(explode('.', $key)[1])); ?></strong> - <?php echo $stats['success_rate']; ?>% success rate</li>
                  <?php endforeach; ?>
                </ul>
                <p><em>Great job! These responses are working effectively.</em></p>
              </div>
            <?php endif; ?>
            
            <?php if (empty($low_performing) && empty($high_performing)): ?>
              <div class="luna-insight info">
                <h4>📊 Keep Using Luna Chat</h4>
                <p>Continue using Luna Chat to build up more performance data. The more interactions you have, the better insights we can provide!</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
  
  <style>
    .luna-analytics-container {
      display: flex;
      flex-direction: column;
      gap: 30px;
    }
    .luna-stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin: 20px 0;
    }
    .luna-stat-box {
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 20px;
      text-align: center;
    }
    .luna-stat-box h4 {
      margin: 0 0 10px 0;
      color: #6c757d;
      font-size: 14px;
      font-weight: 600;
    }
    .luna-stat-number {
      font-size: 32px;
      font-weight: bold;
      color: #0073aa;
    }
    .luna-success-rate {
      padding: 4px 8px;
      border-radius: 4px;
      font-weight: bold;
    }
    .luna-success-rate.luna-success {
      background: #d4edda;
      color: #155724;
    }
    .luna-success-rate.luna-warning {
      background: #fff3cd;
      color: #856404;
    }
    .luna-success-rate.luna-error {
      background: #f8d7da;
      color: #721c24;
    }
    .luna-insights {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .luna-insight {
      padding: 20px;
      border-radius: 8px;
      border-left: 4px solid;
    }
    .luna-insight.success {
      background: #d4edda;
      border-left-color: #28a745;
    }
    .luna-insight.warning {
      background: #fff3cd;
      border-left-color: #ffc107;
    }
    .luna-composer__response, [data-luna-composer] .luna-composer__response{display:none !important;}
    [data-luna-composer] .luna-composer__response[data-loading="true"], [data-luna-composer] .luna-composer__response[data-loading="false"] {
        display: inline !important;
    }
    .luna-insight.info {
      background: #d1ecf1;
      border-left-color: #17a2b8;
    }
    .luna-insight h4 {
      margin-top: 0;
    }
    .luna-insight ul {
      margin: 10px 0;
    }
    
    /* Chat Transcript Modal Styles */
    .luna-modal {
      position: fixed;
      z-index: 100000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
      display: none;
    }
    
    .luna-modal-content {
      background-color: #fff;
      margin: 5% auto;
      padding: 20px;
      border-radius: 8px;
      width: 80%;
      max-width: 800px;
      max-height: 80vh;
      overflow-y: auto;
    }
    
    .luna-modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 10px;
      border-bottom: 1px solid #ddd;
    }
    
    .luna-modal-close {
      font-size: 24px;
      font-weight: bold;
      cursor: pointer;
      color: #666;
    }
    
    .luna-chat-transcript {
      max-height: 400px;
      overflow-y: auto;
      border: 1px solid #ddd;
      padding: 15px;
      background: #f9f9f9;
    }
    
    .luna-chat-entry {
      margin-bottom: 15px;
      padding: 10px;
      border-radius: 5px;
    }
    
    .luna-chat-entry.user {
      background: #e3f2fd;
    }
    
    .luna-chat-entry.assistant {
      background: #f5f5f5;
    }
    
    /* Keyword Interface Styles */
    .luna-data-source-options {
      margin-top: 15px;
      padding: 15px;
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 6px;
    }
    
    .luna-response-type {
      margin-bottom: 15px;
    }
    
    .luna-response-type label {
      display: inline-block;
      margin-right: 20px;
      font-weight: 600;
    }
    
    .luna-response-type input[type="radio"] {
      margin-right: 8px;
    }
    
    .luna-branch-responses {
      display: flex;
      flex-direction: column;
      gap: 15px;
    }
    
    .luna-branch-item {
      display: flex;
      flex-direction: column;
      gap: 8px;
      padding: 12px;
      background: #ffffff;
      border: 1px solid #e9ecef;
      border-radius: 4px;
    }
    
    .luna-branch-item input[type="text"] {
      padding: 8px 12px;
      border: 1px solid #ced4da;
      border-radius: 4px;
      font-size: 14px;
    }
    
    .luna-branch-item textarea {
      padding: 8px 12px;
      border: 1px solid #ced4da;
      border-radius: 4px;
      font-size: 14px;
      resize: vertical;
    }
    
    .luna-keyword-field .description {
      margin-top: 8px;
      font-size: 13px;
      color: #6c757d;
      line-height: 1.4;
    }
    
    .luna-keyword-field .description code {
      background: #e9ecef;
      padding: 2px 4px;
      border-radius: 3px;
      font-family: 'Courier New', monospace;
      font-size: 12px;
    }
    
    .luna-keyword-field select {
      padding: 6px 10px;
      border: 1px solid #ced4da;
      border-radius: 4px;
      font-size: 14px;
    }
  </style>
  <?php
}

// Separate function for JavaScript
function luna_keywords_admin_scripts() {
  ?>
  <script>
  function luna_toggle_keyword(category, action) {
    const checkbox = document.querySelector(`input[name="keywords[${category}][${action}][enabled]"]`);
    const row = checkbox.closest('tr');
    const inputs = row.querySelectorAll('input, textarea, select');
    
    inputs.forEach(input => {
      if (input !== checkbox) {
        input.disabled = !checkbox.checked;
      }
    });
  }
  
  function luna_toggle_data_source_options(select, category, action) {
    const dataSource = select.value;
    const row = select.closest('tr');
    
    console.log('Luna Keywords: Toggling data source to', dataSource, 'for', category, action);
    
    // Hide all data source options
    row.querySelectorAll('.luna-data-source-options').forEach(div => {
      div.style.display = 'none';
    });
    
    // Show the selected data source options
    const targetDiv = row.querySelector(`.luna-${dataSource}-options`);
    if (targetDiv) {
      targetDiv.style.display = 'block';
      console.log('Luna Keywords: Showing', dataSource, 'options');
      
      // If it's custom response, also initialize the response type
      if (dataSource === 'custom') {
        const checkedRadio = targetDiv.querySelector('input[name*="[response_type]"]:checked');
        if (checkedRadio) {
          console.log('Luna Keywords: Found checked radio, initializing response type');
          luna_toggle_response_type(category, action, checkedRadio.value);
        }
      }
    } else {
      console.log('Luna Keywords: Target div not found for', dataSource);
    }
  }
  
  function luna_toggle_response_type(category, action, type) {
    const radio = document.querySelector(`input[name="keywords[${category}][${action}][response_type]"][value="${type}"]`);
    if (!radio) {
      console.log('Luna Keywords: Radio not found for', category, action, type);
      return;
    }
    
    const row = radio.closest('tr');
    
    console.log('Luna Keywords: Toggling response type to', type, 'for', category, action);
    
    // Hide both response types
    row.querySelectorAll('.luna-simple-response, .luna-advanced-response').forEach(div => {
      div.style.display = 'none';
    });
    
    // Show the selected response type
    const targetDiv = row.querySelector(`.luna-${type}-response`);
    if (targetDiv) {
      targetDiv.style.display = 'block';
      console.log('Luna Keywords: Showing', type, 'response');
    } else {
      console.log('Luna Keywords: Target div not found for', type, 'response');
    }
  }
  
  function luna_insert_shortcode(shortcode, targetFieldName) {
    if (!shortcode) return;
    
    const textarea = document.querySelector(`textarea[name="${targetFieldName}"]`);
    if (textarea) {
      const start = textarea.selectionStart;
      const end = textarea.selectionEnd;
      const text = textarea.value;
      const before = text.substring(0, start);
      const after = text.substring(end, text.length);
      
      textarea.value = before + shortcode + after;
      textarea.focus();
      textarea.setSelectionRange(start + shortcode.length, start + shortcode.length);
    }
  }
  
  // Modal functionality
  function openModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
  }
  
  function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
  }
  
  // Add new keyword functionality
  function addNewKeyword() {
    openModal('keyword-modal');
  }
  
  function saveNewKeyword() {
    const category = document.getElementById('new-keyword-category').value;
    const action = document.getElementById('new-keyword-name').value;
    const terms = document.getElementById('new-keyword-terms').value;
    const dataSource = document.getElementById('new-keyword-data-source').value;
    const template = document.getElementById('new-keyword-template').value;
    
    if (!action || !terms) {
      alert('Please fill in the keyword name and terms.');
      return;
    }
    
    // Create new keyword row
    const container = document.querySelector('.luna-keywords-container');
    const newRow = document.createElement('div');
    newRow.className = 'luna-keyword-category';
    newRow.innerHTML = `
      <h3>${category.charAt(0).toUpperCase() + category.slice(1)} Keywords</h3>
      <table class="form-table">
        <tr>
          <th scope="row">${action.charAt(0).toUpperCase() + action.slice(1)}</th>
          <td>
            <div class="luna-keyword-config">
              <div class="luna-keyword-field">
                <label>
                  <input type="checkbox" name="keywords[${category}][${action}][enabled]" value="on" checked onchange="luna_toggle_keyword('${category}', '${action}')">
                  Enable this keyword
                </label>
              </div>
              <div class="luna-keyword-field">
                <label>Keywords:</label>
                <input type="text" name="keywords[${category}][${action}][terms]" class="regular-text" value="${terms}">
              </div>
              <div class="luna-keyword-field">
                <label>Data Source:</label>
                <select name="keywords[${category}][${action}][data_source]" onchange="luna_toggle_data_source_options(this, '${category}', '${action}')">
                  <option value="custom" ${dataSource === 'custom' ? 'selected' : ''}>Custom Response</option>
                  <option value="wp_rest" ${dataSource === 'wp_rest' ? 'selected' : ''}>WordPress Data</option>
                  <option value="security" ${dataSource === 'security' ? 'selected' : ''}>Security Data</option>
                </select>
              </div>
              <div class="luna-keyword-field">
                <label>Response Template:</label>
                <textarea name="keywords[${category}][${action}][template]" class="large-text" rows="3">${template}</textarea>
              </div>
            </div>
          </td>
        </tr>
      </table>
    `;
    
    // Add to container
    container.appendChild(newRow);
    
    // Initialize the new keyword
    luna_toggle_keyword(category, action);
    
    // Clear form and close modal
    document.getElementById('new-keyword-name').value = '';
    document.getElementById('new-keyword-terms').value = '';
    document.getElementById('new-keyword-template').value = '';
    closeModal('keyword-modal');
  }
  
  // Add new category functionality
  function addNewCategory() {
    openModal('category-modal');
  }
  
  function saveNewCategory() {
    const categoryName = document.getElementById('new-category-name').value;
    const description = document.getElementById('new-category-description').value;
    
    if (!categoryName) {
      alert('Please enter a category name.');
      return;
    }
    
    // Add to category dropdown
    const categorySelect = document.getElementById('new-keyword-category');
    const newOption = document.createElement('option');
    newOption.value = categoryName.toLowerCase().replace(/\s+/g, '_');
    newOption.textContent = categoryName.charAt(0).toUpperCase() + categoryName.slice(1);
    categorySelect.appendChild(newOption);
    
    // Clear form and close modal
    document.getElementById('new-category-name').value = '';
    document.getElementById('new-category-description').value = '';
    closeModal('category-modal');
    
    alert(`Category "${categoryName}" added successfully! You can now use it when adding new keywords.`);
  }
  
  // Manage existing keywords functionality
  function manageKeywords() {
    const container = document.getElementById('keyword-management-list');
    container.innerHTML = '';
    
    // Get all existing keywords
    const keywords = [];
    document.querySelectorAll('.luna-keyword-category').forEach(categoryDiv => {
      const categoryName = categoryDiv.querySelector('h3').textContent.replace(' Keywords', '').toLowerCase();
      categoryDiv.querySelectorAll('tr').forEach(row => {
        const th = row.querySelector('th');
        if (th && th.textContent.trim()) {
          const actionName = th.textContent.trim();
          const termsInput = row.querySelector('input[name*="[terms]"]');
          const terms = termsInput ? termsInput.value : '';
          
          keywords.push({
            category: categoryName,
            action: actionName,
            terms: terms,
            element: row
          });
        }
      });
    });
    
    // Create management interface
    keywords.forEach(keyword => {
      const item = document.createElement('div');
      item.className = 'keyword-management-item';
      item.innerHTML = `
        <div class="keyword-info">
          <div class="keyword-name">${keyword.action}</div>
          <div class="keyword-terms">${keyword.terms}</div>
        </div>
        <select data-category="${keyword.category}" data-action="${keyword.action}">
          <option value="business" ${keyword.category === 'business' ? 'selected' : ''}>Business</option>
          <option value="wp_rest" ${keyword.category === 'wp_rest' ? 'selected' : ''}>WordPress Data</option>
          <option value="security" ${keyword.category === 'security' ? 'selected' : ''}>Security</option>
          <option value="custom" ${keyword.category === 'custom' ? 'selected' : ''}>Custom</option>
        </select>
      `;
      container.appendChild(item);
    });
    
    openModal('manage-modal');
  }
  
  function saveKeywordChanges() {
    const changes = [];
    document.querySelectorAll('#keyword-management-list select').forEach(select => {
      const category = select.dataset.category;
      const action = select.dataset.action;
      const newCategory = select.value;
      
      if (category !== newCategory) {
        changes.push({ category, action, newCategory });
      }
    });
    
    if (changes.length === 0) {
      closeModal('manage-modal');
      return;
    }
    
    // Apply changes
    changes.forEach(change => {
      // Find the row and move it to the new category
      const row = document.querySelector(`input[name*="[${change.action}][enabled]"]`).closest('tr');
      const categoryDiv = row.closest('.luna-keyword-category');
      
      // Update the category name in the row
      const categorySelect = row.querySelector('select[name*="[data_source]"]');
      if (categorySelect) {
        const name = categorySelect.name;
        const newName = name.replace(`[${change.category}]`, `[${change.newCategory}]`);
        categorySelect.name = newName;
      }
      
      // Update all form elements in the row
      row.querySelectorAll('input, select, textarea').forEach(input => {
        if (input.name && input.name.includes(`[${change.category}]`)) {
          input.name = input.name.replace(`[${change.category}]`, `[${change.newCategory}]`);
        }
      });
    });
    
    closeModal('manage-modal');
    alert(`Moved ${changes.length} keyword(s) to new categories. Don't forget to save the form!`);
  }
  
  // Initialize the interface on page load
  document.addEventListener('DOMContentLoaded', function() {
    console.log('Luna Keywords: Initializing interface...');
    
    // Button event listeners
    document.getElementById('add-new-keyword').addEventListener('click', addNewKeyword);
    document.getElementById('add-new-category').addEventListener('click', addNewCategory);
    document.getElementById('manage-keywords').addEventListener('click', manageKeywords);
    
    // Modal event listeners
    document.getElementById('save-new-keyword').addEventListener('click', saveNewKeyword);
    document.getElementById('cancel-new-keyword').addEventListener('click', () => closeModal('keyword-modal'));
    document.getElementById('save-new-category').addEventListener('click', saveNewCategory);
    document.getElementById('cancel-new-category').addEventListener('click', () => closeModal('category-modal'));
    document.getElementById('save-keyword-changes').addEventListener('click', saveKeywordChanges);
    document.getElementById('cancel-keyword-changes').addEventListener('click', () => closeModal('manage-modal'));
    
    // Close modal when clicking X
    document.querySelectorAll('.luna-modal-close').forEach(closeBtn => {
      closeBtn.addEventListener('click', function() {
        const modal = this.closest('.luna-modal');
        modal.style.display = 'none';
      });
    });
    
    // Close modal when clicking outside
    document.querySelectorAll('.luna-modal').forEach(modal => {
      modal.addEventListener('click', function(e) {
        if (e.target === this) {
          this.style.display = 'none';
        }
      });
    });
    
    // Initialize all data source options
    document.querySelectorAll('select[name*="[data_source]"]').forEach(select => {
      const categoryMatch = select.name.match(/keywords\[([^\]]+)\]/);
      const actionMatch = select.name.match(/\[([^\]]+)\]\[data_source\]/);
      
      if (categoryMatch && actionMatch) {
        const category = categoryMatch[1];
        const action = actionMatch[1];
        console.log('Luna Keywords: Initializing data source for', category, action, '=', select.value);
        luna_toggle_data_source_options(select, category, action);
      }
    });
    
    // Initialize all response types for custom responses
    document.querySelectorAll('input[name*="[response_type]"]:checked').forEach(radio => {
      const categoryMatch = radio.name.match(/keywords\[([^\]]+)\]/);
      const actionMatch = radio.name.match(/\[([^\]]+)\]\[response_type\]/);
      
      if (categoryMatch && actionMatch) {
        const category = categoryMatch[1];
        const action = actionMatch[1];
        const type = radio.value;
        console.log('Luna Keywords: Initializing response type for', category, action, '=', type);
        luna_toggle_response_type(category, action, type);
      }
    });
  });
  </script>
  <?php
}

/* ============================================================
 * SECURITY HELPERS
 * ============================================================ */
function luna_license_ok( WP_REST_Request $req ) {
  $saved = (string) get_option(LUNA_WIDGET_OPT_LICENSE, '');
  if ($saved === '') return false;
  $hdr = trim((string) ($req->get_header('X-Luna-License') ? $req->get_header('X-Luna-License') : ''));
  $qp  = trim((string) $req->get_param('license'));
  $provided = $hdr ? $hdr : $qp;
  if (!$provided) return false;
  if (!is_ssl() && $qp) return false; // only allow license in query over https
  return hash_equals($saved, $provided);
}
function luna_forbidden() {
  return new WP_REST_Response(array('ok'=>false,'error'=>'forbidden'), 403);
}

/**
 * Analyzes help requests and offers contextual assistance options
 */
function luna_analyze_help_request($prompt, $facts) {
  $help_type = luna_detect_help_type($prompt);
  $site_url = isset($facts['site_url']) ? $facts['site_url'] : home_url('/');
  $site_name = parse_url($site_url, PHP_URL_HOST);
  
  $response = "I understand you're experiencing an issue. Let me help you get this resolved quickly! ";
  
  switch ($help_type) {
    case 'technical':
      $response .= "This sounds like a technical issue. I can help you in a few ways:\n\n";
      $response .= "🔧 **Option 1: Send Support Email**\n";
      $response .= "I can send a detailed snapshot of our conversation and your site data to your email for technical review.\n\n";
      $response .= "📧 **Option 2: Notify Visible Light Team**\n";
      $response .= "I can alert the Visible Light support team about this issue.\n\n";
      $response .= "🐛 **Option 3: Report as Bug**\n";
      $response .= "If this seems like a bug, I can report it directly to the development team.\n\n";
      $response .= "Which option would you prefer? Just say 'support email', 'notify VL', or 'report bug'.";
      break;
      
    case 'content':
      $response .= "This seems like a content or website management issue. I can help you by:\n\n";
      $response .= "📝 **Option 1: Content Support**\n";
      $response .= "Send your content team a detailed report of what you're trying to accomplish.\n\n";
      $response .= "📧 **Option 2: Notify Visible Light**\n";
      $response .= "Alert the Visible Light team about this content issue.\n\n";
      $response .= "Which would you prefer? Say 'support email' or 'notify VL'.";
      break;
      
    case 'urgent':
      $response .= "This sounds urgent! I can help you immediately by:\n\n";
      $response .= "🚨 **Option 1: Emergency Support**\n";
      $response .= "Send an urgent support request with full context to your team.\n\n";
      $response .= "📞 **Option 2: Notify Visible Light**\n";
      $response .= "Alert the Visible Light team immediately about this urgent issue.\n\n";
      $response .= "🐛 **Option 3: Report Critical Bug**\n";
      $response .= "If this is a critical bug, report it directly to development.\n\n";
      $response .= "Which option would you like? Say 'support email', 'notify VL', or 'report bug'.";
      break;
      
    default:
      $response .= "I can help you get this resolved. Here are your options:\n\n";
      $response .= "📧 **Option 1: Send Support Email**\n";
      $response .= "I'll send a detailed snapshot of our conversation to your email.\n\n";
      $response .= "📞 **Option 2: Notify Visible Light**\n";
      $response .= "I'll alert the Visible Light team about this issue.\n\n";
      $response .= "🐛 **Option 3: Report Bug**\n";
      $response .= "If this seems like a bug, I'll report it to the development team.\n\n";
      $response .= "Which option would you prefer? Just say 'support email', 'notify VL', or 'report bug'.";
  }
  
  return $response;
}

/**
 * Detects the type of help request based on keywords and context
 */
function luna_detect_help_type($prompt) {
  $lc = strtolower($prompt);
  
  // Urgent keywords
  if (preg_match('/\b(urgent|critical|emergency|down|crash|fatal|broken|not working|error|bug)\b/', $lc)) {
    return 'urgent';
  }
  
  // Technical keywords
  if (preg_match('/\b(technical|server|database|plugin|theme|code|php|mysql|error|bug|fix|repair)\b/', $lc)) {
    return 'technical';
  }
  
  // Content keywords
  if (preg_match('/\b(content|page|post|edit|update|publish|media|image|text|format)\b/', $lc)) {
    return 'content';
  }
  
  return 'general';
}

/**
 * Handles help option responses
 */
function luna_handle_help_option($option, $prompt, $facts) {
  switch ($option) {
    case 'support_email':
      return luna_handle_support_email_request($prompt, $facts);
    case 'notify_vl':
      return luna_handle_notify_vl_request($prompt, $facts);
    case 'report_bug':
      return luna_handle_bug_report_request($prompt, $facts);
    default:
      return "I'm not sure which option you meant. Please say 'support email', 'notify VL', or 'report bug'.";
  }
}

/**
 * Handles support email requests
 */
function luna_handle_support_email_request($prompt, $facts) {
  return "Great! I'd be happy to send you a detailed snapshot of our conversation and your site data. Which email address would you like me to send this to?";
}

/**
 * Handles Visible Light notification requests
 */
function luna_handle_notify_vl_request($prompt, $facts) {
  $site_url = isset($facts['site_url']) ? $facts['site_url'] : home_url('/');
  $site_name = parse_url($site_url, PHP_URL_HOST);
  
  // Send notification to Visible Light
  $success = luna_send_vl_notification($prompt, $facts);
  
  if ($success) {
    return "✅ I've notified the Visible Light team about your issue. They'll review the details and get back to you soon. Is there anything else I can help you with?";
  } else {
    return "I encountered an issue sending the notification. Let me try the support email option instead - which email address would you like me to send the snapshot to?";
  }
}

/**
 * Handles bug report requests
 */
function luna_handle_bug_report_request($prompt, $facts) {
  $site_url = isset($facts['site_url']) ? $facts['site_url'] : home_url('/');
  $site_name = parse_url($site_url, PHP_URL_HOST);
  
  // Send bug report to Visible Light
  $success = luna_send_bug_report($prompt, $facts);
  
  if ($success) {
    return "🐛 I've reported this as a bug to the Visible Light development team. They'll investigate and work on a fix. You should hear back soon. Is there anything else I can help you with?";
  } else {
    return "I encountered an issue sending the bug report. Let me try the support email option instead - which email address would you like me to send the snapshot to?";
  }
}

/**
 * Sends notification to Visible Light team
 */
function luna_send_vl_notification($prompt, $facts) {
  $site_url = isset($facts['site_url']) ? $facts['site_url'] : home_url('/');
  $site_name = parse_url($site_url, PHP_URL_HOST);
  $license = luna_get_license();
  
  $subject = "Luna Chat Support Request - " . $site_name;
  $message = "
  <html>
  <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
    <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
      <h2 style='color: #2B6AFF;'>Luna Chat Support Request</h2>
      <p><strong>Site:</strong> " . esc_html($site_name) . "</p>
      <p><strong>URL:</strong> " . esc_html($site_url) . "</p>
      <p><strong>License:</strong> " . esc_html($license) . "</p>
      <p><strong>Issue:</strong> " . esc_html($prompt) . "</p>
      
      <h3>Site Information:</h3>
      <ul>
        <li>WordPress Version: " . esc_html($facts['wp_version'] ?? 'Unknown') . "</li>
        <li>PHP Version: " . esc_html($facts['php_version'] ?? 'Unknown') . "</li>
        <li>Theme: " . esc_html($facts['theme'] ?? 'Unknown') . "</li>
        <li>Health Score: " . esc_html($facts['health_score'] ?? 'Unknown') . "%</li>
      </ul>
      
      <p>This request was generated automatically by Luna Chat AI.</p>
    </div>
  </body>
  </html>
  ";
  
  $headers = array('Content-Type: text/html; charset=UTF-8');
  return wp_mail('support@visiblelight.ai', $subject, $message, $headers);
}

/**
 * Sends bug report to Visible Light team
 */
function luna_send_bug_report($prompt, $facts) {
  $site_url = isset($facts['site_url']) ? $facts['site_url'] : home_url('/');
  $site_name = parse_url($site_url, PHP_URL_HOST);
  $license = luna_get_license();
  
  $subject = "🐛 Bug Report - " . $site_name . " - Luna Chat";
  $message = "
  <html>
  <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
    <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
      <h2 style='color: #d63638;'>🐛 Bug Report</h2>
      <p><strong>Site:</strong> " . esc_html($site_name) . "</p>
      <p><strong>URL:</strong> " . esc_html($site_url) . "</p>
      <p><strong>License:</strong> " . esc_html($license) . "</p>
      <p><strong>Bug Description:</strong> " . esc_html($prompt) . "</p>
      
      <h3>System Information:</h3>
      <ul>
        <li>WordPress Version: " . esc_html($facts['wp_version'] ?? 'Unknown') . "</li>
        <li>PHP Version: " . esc_html($facts['php_version'] ?? 'Unknown') . "</li>
        <li>Theme: " . esc_html($facts['theme'] ?? 'Unknown') . "</li>
        <li>Health Score: " . esc_html($facts['health_score'] ?? 'Unknown') . "%</li>
        <li>SSL Status: " . (isset($facts['tls_valid']) && $facts['tls_valid'] ? 'Active' : 'Issues') . "</li>
      </ul>
      
      <p>This bug report was generated automatically by Luna Chat AI.</p>
    </div>
  </body>
  </html>
  ";
  
  $headers = array('Content-Type: text/html; charset=UTF-8');
  return wp_mail('bugs@visiblelight.ai', $subject, $message, $headers);
}

/**
 * Extracts email address from text
 */
function luna_extract_email($text) {
  if (preg_match('/\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b/', $text, $matches)) {
    return $matches[0];
  }
  return false;
}

/**
 * Sends support email with chat snapshot
 */
function luna_send_support_email($email, $prompt, $facts) {
  $site_url = isset($facts['site_url']) ? $facts['site_url'] : home_url('/');
  $site_name = parse_url($site_url, PHP_URL_HOST);
  $license = luna_get_license();
  
  $subject = "Luna Chat Support Snapshot - " . $site_name;
  $message = "
  <html>
  <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
    <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
      <h2 style='color: #2B6AFF;'>Luna Chat Support Snapshot</h2>
      <p>This email contains a detailed snapshot of your Luna Chat conversation and site data.</p>
      
      <h3>Site Information:</h3>
      <ul>
        <li><strong>Site:</strong> " . esc_html($site_name) . "</li>
        <li><strong>URL:</strong> " . esc_html($site_url) . "</li>
        <li><strong>License:</strong> " . esc_html($license) . "</li>
        <li><strong>WordPress Version:</strong> " . esc_html($facts['wp_version'] ?? 'Unknown') . "</li>
        <li><strong>PHP Version:</strong> " . esc_html($facts['php_version'] ?? 'Unknown') . "</li>
        <li><strong>Theme:</strong> " . esc_html($facts['theme'] ?? 'Unknown') . "</li>
        <li><strong>Health Score:</strong> " . esc_html($facts['health_score'] ?? 'Unknown') . "%</li>
        <li><strong>SSL Status:</strong> " . (isset($facts['tls_valid']) && $facts['tls_valid'] ? 'Active' : 'Issues') . "</li>
      </ul>
      
      <h3>Issue Description:</h3>
      <p>" . esc_html($prompt) . "</p>
      
      <h3>System Health Details:</h3>
      <ul>
        <li>Memory Usage: " . esc_html($facts['memory_usage'] ?? 'Unknown') . "</li>
        <li>Active Plugins: " . (isset($facts['active_plugins']) ? count($facts['active_plugins']) : 'Unknown') . "</li>
        <li>Pages: " . esc_html($facts['pages_count'] ?? 'Unknown') . "</li>
        <li>Posts: " . esc_html($facts['posts_count'] ?? 'Unknown') . "</li>
      </ul>
      
      <h3>Analytics Data:</h3>";
  
  if (isset($facts['ga4_metrics'])) {
    $ga4 = $facts['ga4_metrics'];
    $message .= "<ul>";
    $message .= "<li>Total Users: " . esc_html($ga4['totalUsers'] ?? 'N/A') . "</li>";
    $message .= "<li>New Users: " . esc_html($ga4['newUsers'] ?? 'N/A') . "</li>";
    $message .= "<li>Sessions: " . esc_html($ga4['sessions'] ?? 'N/A') . "</li>";
    $message .= "<li>Page Views: " . esc_html($ga4['screenPageViews'] ?? 'N/A') . "</li>";
    $message .= "<li>Bounce Rate: " . esc_html($ga4['bounceRate'] ?? 'N/A') . "%</li>";
    $message .= "<li>Engagement Rate: " . esc_html($ga4['engagementRate'] ?? 'N/A') . "%</li>";
    $message .= "</ul>";
  } else {
    $message .= "<p>No analytics data available</p>";
  }
  
  $message .= "
      <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
      <p style='font-size: 12px; color: #666;'>This support snapshot was generated automatically by Luna Chat AI on " . date('Y-m-d H:i:s T') . "</p>
    </div>
  </body>
  </html>
  ";
  
  $headers = array('Content-Type: text/html; charset=UTF-8');
  return wp_mail($email, $subject, $message, $headers);
}

/**
 * Handles analytics requests and provides GA4 data
 */
function luna_handle_analytics_request($prompt, $facts) {
  $site_url = isset($facts['site_url']) ? $facts['site_url'] : home_url('/');
  $site_name = parse_url($site_url, PHP_URL_HOST);

  // Get GA4 data from facts array (same as intelligence report)
  $ga4_metrics = null;
  $ga4_meta = array(
    'last_synced'    => isset($facts['ga4_last_synced']) ? $facts['ga4_last_synced'] : null,
    'date_range'     => isset($facts['ga4_date_range']) ? $facts['ga4_date_range'] : null,
    'source_url'     => isset($facts['ga4_source_url']) ? $facts['ga4_source_url'] : null,
    'property_id'    => isset($facts['ga4_property_id']) ? $facts['ga4_property_id'] : null,
    'measurement_id' => isset($facts['ga4_measurement_id']) ? $facts['ga4_measurement_id'] : null,
  );

  if (isset($facts['ga4_metrics'])) {
    $ga4_metrics = $facts['ga4_metrics'];
  }

  // Debug logging
  error_log('[Luna Analytics] Facts keys: ' . implode(', ', array_keys($facts)));
  error_log('[Luna Analytics] GA4 metrics found: ' . ($ga4_metrics ? 'YES' : 'NO'));
  if ($ga4_metrics) {
    error_log('[Luna Analytics] GA4 data: ' . print_r($ga4_metrics, true));
  }

  if (!$ga4_metrics) {
    error_log('[Luna Analytics] Attempting to fetch GA4 metrics directly from Hub data streams.');
    $ga4_info = luna_fetch_ga4_metrics_from_hub();
    if ($ga4_info && isset($ga4_info['metrics'])) {
      $ga4_metrics = $ga4_info['metrics'];
      foreach (array('last_synced','date_range','source_url','property_id','measurement_id') as $meta_key) {
        if (isset($ga4_info[$meta_key]) && empty($ga4_meta[$meta_key])) {
          $ga4_meta[$meta_key] = $ga4_info[$meta_key];
        }
      }
      error_log('[Luna Analytics] GA4 metrics hydrated from data streams: ' . print_r($ga4_metrics, true));
    }
  }

  if (!$ga4_metrics) {
    return "I don't have access to your analytics data right now. Your GA4 integration may need to be refreshed. You can check your analytics settings in the Visible Light Hub profile, or I can help you set up Google Analytics if it's not configured yet.";
  }

  $lc = strtolower($prompt);

  // Handle specific analytics questions
  if (preg_match('/\b(page.*views|pageviews)\b/', $lc)) {
    $page_views = isset($ga4_metrics['screenPageViews']) ? $ga4_metrics['screenPageViews'] : 'N/A';
    return "Your page views for the current period are: **" . $page_views . "** views. This data comes from your Google Analytics 4 integration.";
  }

  if (preg_match('/\b(users|visitors)\b/', $lc)) {
    $total_users = isset($ga4_metrics['totalUsers']) ? $ga4_metrics['totalUsers'] : 'N/A';
    $new_users = isset($ga4_metrics['newUsers']) ? $ga4_metrics['newUsers'] : 'N/A';
    return "Your user analytics show:\n• **Total Users**: " . $total_users . "\n• **New Users**: " . $new_users . "\nThis data comes from your Google Analytics 4 integration.";
  }

  if (preg_match('/\b(sessions)\b/', $lc)) {
    $sessions = isset($ga4_metrics['sessions']) ? $ga4_metrics['sessions'] : 'N/A';
    return "Your sessions for the current period are: **" . $sessions . "** sessions. This data comes from your Google Analytics 4 integration.";
  }

  if (preg_match('/\b(bounce.*rate)\b/', $lc)) {
    $bounce_rate = isset($ga4_metrics['bounceRate']) ? $ga4_metrics['bounceRate'] : 'N/A';
    return "Your bounce rate is: **" . $bounce_rate . "%**. This data comes from your Google Analytics 4 integration.";
  }

  if (preg_match('/\b(engagement.*rate|engagement)\b/', $lc)) {
    $engagement_rate = isset($ga4_metrics['engagementRate']) ? $ga4_metrics['engagementRate'] : 'N/A';
    return "Your engagement rate is: **" . $engagement_rate . "%**. This data comes from your Google Analytics 4 integration.";
  }

  if (preg_match('/\b(property\s*id|ga4\s*property)\b/', $lc) && strpos($lc, 'measurement') === false) {
    if (!empty($ga4_meta['property_id'])) {
      return "Your Google Analytics 4 property ID is **" . $ga4_meta['property_id'] . "**.";
    }
    return "I couldn't find a GA4 property ID in your Hub profile. Double-check the Visible Light Hub analytics settings to confirm it's saved.";
  }

  if (preg_match('/measurement\s*id/', $lc)) {
    if (!empty($ga4_meta['measurement_id'])) {
      return "Your GA4 measurement ID is **" . $ga4_meta['measurement_id'] . "**.";
    }
    return "I don't see a GA4 measurement ID recorded yet. Make sure it's configured in your Visible Light Hub analytics settings.";
  }

  if (preg_match('/(last|recent).*(sync|synced|update|updated|refresh)/', $lc)) {
    if (!empty($ga4_meta['last_synced'])) {
      $range_text = '';
      if (!empty($ga4_meta['date_range']) && is_array($ga4_meta['date_range'])) {
        $start = isset($ga4_meta['date_range']['startDate']) ? $ga4_meta['date_range']['startDate'] : '';
        $end   = isset($ga4_meta['date_range']['endDate']) ? $ga4_meta['date_range']['endDate'] : '';
        if ($start || $end) {
          $range_text = ' covering ' . trim($start . ' to ' . $end);
        }
      }
      return "Your GA4 metrics were last synced on **" . $ga4_meta['last_synced'] . "**" . $range_text . ".";
    }
    return "I wasn't able to confirm the last sync time from the Hub profile. Try refreshing the GA4 connection in Visible Light Hub to capture a new sync timestamp.";
  }

  if (preg_match('/(date\s*range|time\s*range|timeframe|time\s*frame|reporting\s*period)/', $lc)) {
    if (!empty($ga4_meta['date_range']) && is_array($ga4_meta['date_range'])) {
      $start = isset($ga4_meta['date_range']['startDate']) ? $ga4_meta['date_range']['startDate'] : 'unknown start';
      $end   = isset($ga4_meta['date_range']['endDate']) ? $ga4_meta['date_range']['endDate'] : 'unknown end';
      return "The current GA4 report covers **" . $start . "** through **" . $end . "**.";
    }
    return "I couldn't determine the reporting range. Try re-syncing GA4 from the Visible Light Hub profile to capture a date window.";
  }

  // General analytics summary
  $summary = "Here's your current analytics data from Google Analytics 4:\n\n";
  $summary .= "📊 **Traffic Overview:**\n";
  $summary .= "• **Total Users**: " . (isset($ga4_metrics['totalUsers']) ? $ga4_metrics['totalUsers'] : 'N/A') . "\n";
  $summary .= "• **New Users**: " . (isset($ga4_metrics['newUsers']) ? $ga4_metrics['newUsers'] : 'N/A') . "\n";
  $summary .= "• **Sessions**: " . (isset($ga4_metrics['sessions']) ? $ga4_metrics['sessions'] : 'N/A') . "\n";
  $summary .= "• **Page Views**: " . (isset($ga4_metrics['screenPageViews']) ? $ga4_metrics['screenPageViews'] : 'N/A') . "\n\n";
  $summary .= "📈 **Engagement Metrics:**\n";
  $summary .= "• **Bounce Rate**: " . (isset($ga4_metrics['bounceRate']) ? $ga4_metrics['bounceRate'] . '%' : 'N/A') . "\n";
  $summary .= "• **Engagement Rate**: " . (isset($ga4_metrics['engagementRate']) ? $ga4_metrics['engagementRate'] . '%' : 'N/A') . "\n";
  $summary .= "• **Avg Session Duration**: " . (isset($ga4_metrics['averageSessionDuration']) ? $ga4_metrics['averageSessionDuration'] : 'N/A') . "\n";

  if (isset($ga4_metrics['totalRevenue']) && $ga4_metrics['totalRevenue'] > 0) {
    $summary .= "• **Revenue**: $" . $ga4_metrics['totalRevenue'] . "\n";
  }

  if (!empty($ga4_meta['property_id'])) {
    $summary .= "• **GA4 Property ID**: " . $ga4_meta['property_id'] . "\n";
  }

  if (!empty($ga4_meta['measurement_id'])) {
    $summary .= "• **Measurement ID**: " . $ga4_meta['measurement_id'] . "\n";
  }

  if (!empty($ga4_meta['last_synced'])) {
    $summary .= "• **Last Synced**: " . $ga4_meta['last_synced'] . "\n";
  }

  if (!empty($ga4_meta['date_range']) && is_array($ga4_meta['date_range'])) {
    $start = isset($ga4_meta['date_range']['startDate']) ? $ga4_meta['date_range']['startDate'] : 'unknown start';
    $end   = isset($ga4_meta['date_range']['endDate']) ? $ga4_meta['date_range']['endDate'] : 'unknown end';
    $summary .= "• **Reporting Range**: " . $start . " → " . $end . "\n";
  }

  $summary .= "\nThis data is pulled from your Google Analytics 4 integration and updated regularly.";

  if (!empty($ga4_meta['source_url'])) {
    $summary .= "\nView more in Google Analytics: " . $ga4_meta['source_url'];
  }

  return $summary;
}

/**
 * Generates a comprehensive web intelligence report using Visible Light Hub data
 */
function luna_generate_web_intelligence_report($facts) {
  $report = array();
  
  // Site Overview
  $site_url = isset($facts['site_url']) ? $facts['site_url'] : home_url('/');
  $site_name = parse_url($site_url, PHP_URL_HOST);
  
  $report[] = "🌐 **WEB INTELLIGENCE REPORT** for " . $site_name;
  $report[] = "Generated: " . date('Y-m-d H:i:s T');
  $report[] = "";
  
  // System Health & Performance
  $report[] = "📊 **SYSTEM HEALTH & PERFORMANCE**";
  $health_score = isset($facts['health_score']) ? $facts['health_score'] : 'N/A';
  $wp_version = isset($facts['wp_version']) ? $facts['wp_version'] : 'Unknown';
  $php_version = isset($facts['php_version']) ? $facts['php_version'] : 'Unknown';
  $memory_usage = isset($facts['memory_usage']) ? $facts['memory_usage'] : 'Unknown';
  
  $report[] = "• Overall Health Score: " . $health_score . "%";
  $report[] = "• WordPress Version: " . $wp_version;
  $report[] = "• PHP Version: " . $php_version;
  $report[] = "• Memory Usage: " . $memory_usage;
  $report[] = "";
  
  // Security Analysis
  $report[] = "🔒 **SECURITY ANALYSIS**";
  $tls_valid = isset($facts['tls_valid']) ? $facts['tls_valid'] : false;
  $tls_issuer = isset($facts['tls_issuer']) ? $facts['tls_issuer'] : 'Unknown';
  $tls_expires = isset($facts['tls_expires']) ? $facts['tls_expires'] : 'Unknown';
  $mfa_status = isset($facts['mfa']) ? $facts['mfa'] : 'Not configured';
  
  $report[] = "• SSL/TLS Status: " . ($tls_valid ? "✅ Active" : "❌ Issues detected");
  $report[] = "• Certificate Issuer: " . $tls_issuer;
  $report[] = "• Certificate Expires: " . $tls_expires;
  $report[] = "• Multi-Factor Auth: " . $mfa_status;
  $report[] = "";
  
  // Analytics & Traffic Intelligence
  $report[] = "📈 **ANALYTICS & TRAFFIC INTELLIGENCE**";
  
  // Check if GA4 data is available
  if (isset($facts['ga4_metrics'])) {
    $ga4 = $facts['ga4_metrics'];
    $report[] = "• Total Users: " . (isset($ga4['totalUsers']) ? $ga4['totalUsers'] : 'N/A');
    $report[] = "• New Users: " . (isset($ga4['newUsers']) ? $ga4['newUsers'] : 'N/A');
    $report[] = "• Sessions: " . (isset($ga4['sessions']) ? $ga4['sessions'] : 'N/A');
    $report[] = "• Page Views: " . (isset($ga4['screenPageViews']) ? $ga4['screenPageViews'] : 'N/A');
    $report[] = "• Bounce Rate: " . (isset($ga4['bounceRate']) ? $ga4['bounceRate'] . '%' : 'N/A');
    $report[] = "• Engagement Rate: " . (isset($ga4['engagementRate']) ? $ga4['engagementRate'] . '%' : 'N/A');
    $report[] = "• Avg Session Duration: " . (isset($ga4['averageSessionDuration']) ? $ga4['averageSessionDuration'] : 'N/A');
    $report[] = "• Total Revenue: " . (isset($ga4['totalRevenue']) ? '$' . $ga4['totalRevenue'] : 'N/A');
  } else {
    $report[] = "• Analytics: GA4 integration not configured or no recent data";
  }
  $report[] = "";
  
  // Content & SEO Intelligence
  $report[] = "📝 **CONTENT & SEO INTELLIGENCE**";
  $theme = isset($facts['theme']) ? $facts['theme'] : 'Unknown';
  $active_plugins = isset($facts['active_plugins']) ? count($facts['active_plugins']) : 0;
  $pages_count = isset($facts['pages_count']) ? $facts['pages_count'] : 'Unknown';
  $posts_count = isset($facts['posts_count']) ? $facts['posts_count'] : 'Unknown';
  
  $report[] = "• Active Theme: " . $theme;
  $report[] = "• Active Plugins: " . $active_plugins;
  $report[] = "• Pages: " . $pages_count;
  $report[] = "• Posts: " . $posts_count;
  $report[] = "";
  
  // Infrastructure Intelligence
  $report[] = "🏗️ **INFRASTRUCTURE INTELLIGENCE**";
  $hosting_provider = isset($facts['hosting_provider']) ? $facts['hosting_provider'] : 'Unknown';
  $server_info = isset($facts['server_info']) ? $facts['server_info'] : 'Unknown';
  $cdn_status = isset($facts['cdn_status']) ? $facts['cdn_status'] : 'Not detected';
  
  $report[] = "• Hosting Provider: " . $hosting_provider;
  $report[] = "• Server Info: " . $server_info;
  $report[] = "• CDN Status: " . $cdn_status;
  $report[] = "";
  
  // Data Streams Intelligence
  $report[] = "🔄 **DATA STREAMS INTELLIGENCE**";
  $streams_count = isset($facts['data_streams_count']) ? $facts['data_streams_count'] : 0;
  $active_streams = isset($facts['active_streams']) ? $facts['active_streams'] : 0;
  $last_sync = isset($facts['last_sync']) ? $facts['last_sync'] : 'Unknown';
  
  $report[] = "• Total Data Streams: " . $streams_count;
  $report[] = "• Active Streams: " . $active_streams;
  $report[] = "• Last Sync: " . $last_sync;
  $report[] = "";
  
  // Recommendations & Insights
  $report[] = "💡 **RECOMMENDATIONS & INSIGHTS**";
  
  // Health-based recommendations
  if (is_numeric($health_score)) {
    if ($health_score >= 90) {
      $report[] = "• ✅ Excellent system health - maintain current practices";
    } elseif ($health_score >= 70) {
      $report[] = "• ⚠️ Good health with room for improvement - consider optimization";
    } else {
      $report[] = "• 🚨 Health score needs attention - review system performance";
    }
  }
  
  // Security recommendations
  if (!$tls_valid) {
    $report[] = "• 🔒 SSL/TLS certificate needs attention";
  }
  
  if ($mfa_status === 'Not configured') {
    $report[] = "• 🔐 Consider implementing Multi-Factor Authentication";
  }
  
  // Analytics recommendations
  if (!isset($facts['ga4_metrics'])) {
    $report[] = "• 📊 Set up Google Analytics 4 for detailed traffic insights";
  }
  
  $report[] = "";
  $report[] = "📋 **REPORT SUMMARY**";
  $report[] = "This intelligence report is generated from your Visible Light Hub data and provides a comprehensive overview of your website's performance, security, and analytics. Use this information to make informed decisions about optimizations and improvements.";
  $report[] = "";
  $report[] = "For detailed analysis of any specific area, ask me about particular aspects like 'security status', 'analytics data', or 'system performance'.";
  
  return implode("\n", $report);
}