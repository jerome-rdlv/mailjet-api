<?php

namespace Rdlv\WordPress\MailjetApi;

use PHPMailer\PHPMailer\PHPMailer;

class Setup
{
    private const ENV_KEY_PUBLIC = 'MAILJET_API_PUBLIC_KEY';
    private const ENV_KEY_PRIVATE = 'MAILJET_API_PRIVATE_KEY';

    private $testOutput;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'admin_menu']);

        add_action('admin_action_mailjet_api_test', [$this, 'api_test']);

        add_action('plugins_loaded', [$this, 'load_text_domain']);

        add_filter('pre_wp_mail', [$this, 'replace_phpmailer']);
    }

    public function admin_menu()
    {
        add_submenu_page(
            'tools.php',
            __('MailJet API', 'mailjet-api'),
            __('MailJet API', 'mailjet-api'),
            'manage_options',
            'mailjet-api',
            [$this, 'settings_page']
        );
    }

    private function obfuscate_pass(string $pass)
    {
        return str_pad('', strlen($pass) * 3, '•');
    }

    private function obfuscate_config(array &$config)
    {
        if (empty($config[self::ENV_KEY_PRIVATE])) {
            return;
        }
        $config[self::ENV_KEY_PRIVATE] = $this->obfuscate_pass($config[self::ENV_KEY_PRIVATE]);
    }

    public function settings_page()
    {
        global $title;

        echo '<div class="wrap">';
        echo '<h1>' . $title . '</h1>';

        $test_output = get_site_transient('mailjet_api_test_output');
        delete_site_transient('mailjet_api_test_output');
        if (!empty($test_output)) {
            echo $test_output;
        }

        $envs = [
            'MAILJET_API_PUBLIC_KEY',
            'MAILJET_API_PRIVATE_KEY',
        ];

        $config = array_combine($envs, array_map('getenv', $envs));

        $this->obfuscate_config($config);

        $missing_envs = array_keys(array_filter($config, function ($value) {
            return empty($value);
        }));

        if ($missing_envs) {
            echo '<p>';
            printf(
                esc_html__('MailJet API is disabled. Following env vars are missing: %s', 'mailjet-api'),
                implode(', ', array_map(function ($env) {
                    return sprintf('<code>%s</code>', $env);
                }, $missing_envs))
            );
            echo '</p>';
        } else {
            echo '<p>' . esc_html_e('Current configuration:', 'mailjet-api') . '</p>';

            printf(
                '<table class="widefat striped">%s<tbody>%s</tbody></table>',
                sprintf(
                    '<thead><tr>%s</tr></thead>',
                    implode('', array_map(function ($header) {
                        return sprintf('<th scope="col">%s</th>', $header);
                    }, [esc_html__('Env var', 'mailjet-api'), esc_html__('Value', 'mailjet-api')]))
                ),
                implode('', array_map(function ($env, $value) {
                    return sprintf('<tr><th scope="row"><code>%s</code></th><td>%s</td></tr>', $env, $value);
                }, array_keys($config), $config))
            );
        }

        echo '<form method="POST" action="' . admin_url('admin.php') . '">';
        echo '<p>' . esc_html__('Send a test message:', 'mailjet-api') . '</p>';
        echo '<p>';
        echo '<input type="hidden" name="action" value="mailjet_api_test">';
        wp_nonce_field('mailjet_api_test');
        echo '<label for="mailjet_api_to">' . esc_html__('Recipient:', 'mailjet-api') . '</label>&nbsp;';
        printf(
            '<input type="email" class="regular-text" name="to" id="mailjet_api_to" value="%s">&nbsp;',
            isset($_REQUEST['to']) ? esc_attr($_REQUEST['to']) : get_option('admin_email')
        );
        echo '</p>';
        echo '<p>';
        echo sprintf(
            '<input type="submit" class="button button-primary" value="%s">',
            esc_html__('Send', 'mailjet-api')
        );
        echo '</p>';
        echo '</form>';

        echo '</div>';
    }

    public function api_test()
    {
        if (!empty($_REQUEST['to']) && check_admin_referer('mailjet_api_test')) {
            // enable error display
            $display_errors = ini_get('display_errors');
            ini_set('display_errors', 1);
            $error_reporting = error_reporting();
            error_reporting(E_ALL);

            $this->testOutput = '';

            add_action('wp_mail_failed', function (\WP_Error $e) {
                $this->testOutput .= $e->get_error_message();
            });

            ob_start();

            add_action('phpmailer_init', function (PHPMailer $phpmailer) {
                $phpmailer->SMTPDebug = 4;
                $phpmailer->Timeout = 3;
            });

            $wp_mail_output = wp_mail(
                $_REQUEST['to'],
                __('MailJet API test message.', 'mailjet-api'),
                sprintf(
                    '<p>%s</p>',
                    sprintf(
                    /* translators: Placeholder is the website URL */
                        __('Email sending from website %s is working.', 'mailjet-api'),
                        sprintf(
                            '<a href="%s">%s</a>',
                            admin_url(sprintf('%s?page=%s', 'tools.php', 'mailjet-api')),
                            get_home_url()
                        )
                    )
                ),
                [
                    'Content-Type: text/html',
                ],
//                [
//                    __DIR__ . '/../attachment.png',
//                    __DIR__ . '/../attachment.pdf',
//                ]
            );

            $this->testOutput .= ob_get_clean();

            // disable error display
            ini_set('display_errors', $display_errors);
            error_reporting($error_reporting);

            // Authentication obfuscation
            $this->testOutput = preg_replace_callback(
                '/(334 .*?\n.*?: )([^\n:]+)/i',
                function ($m) {
                    return $m[1] . $this->obfuscate_pass($m[2]);
                },
                $this->testOutput
            );

            set_site_transient('mailjet_api_test_output', sprintf(
                '<div class="notice notice-%s"><p>%s <code>%s</code></p>%s</div>',
                $wp_mail_output ? 'success' : 'error',
                __('Test result:', 'mailjet-api'),
                $wp_mail_output ? 'TRUE' : 'FALSE',
                $this->testOutput ? '<pre style="font-size:12px;white-space:pre-wrap;">' . esc_html($this->testOutput) . '</pre>' : ''
            ));
        }

        wp_redirect(
            admin_url(
                sprintf('tools.php?page=mailjet-api&to=%s', urlencode($_REQUEST['to']))
            )
        );
        exit;
    }

    public function load_text_domain()
    {
        /** This filter is documented in wp-includes/l10n.php */
        $locale = apply_filters('plugin_locale', determine_locale(), 'mailjet-api');
        $mofile = 'mailjet-api' . '-' . $locale . '.mo';

        // Try to load from the languages directory first.
        if (load_textdomain('mailjet-api', WP_LANG_DIR . '/plugins/' . $mofile)) {
            return true;
        }

        // Load from plugin languages folder.
        return load_textdomain('mailjet-api', __DIR__ . '/languages/' . $mofile);
    }

    public function replace_phpmailer()
    {
        $publicKey = getenv(self::ENV_KEY_PUBLIC);
        $privateKey = getenv(self::ENV_KEY_PRIVATE);

        if (!$publicKey || !$privateKey) {
            return null;
        }

        global $phpmailer;

        // assert that $phpmail is set and instance of Message
        if (!($phpmailer instanceof Message)) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
            $phpmailer = Message::create($publicKey, $privateKey);

            $phpmailer::$validator = static function ($email) {
                return (bool)is_email($email);
            };
        }
        return null;
    }
}