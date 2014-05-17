<?php
/*
Plugin Name: NUWire Paper Boy
Plugin URI: http://www.nuwire.ca
Description: Allows posts to be automatically sent to the National University Wire.
Version: 0.1.2
Author: Peter Siemens
Author URI: http://www.petersiemens.com
 */

define('NUWIRE_PATH', 'http://nuwire.ca');

class NUWMetaBox
{
    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'nuw_add_custom_box'));
        add_action('save_post', array($this, 'nuw_save_postdata'));
        add_action('trash_post', array($this, 'nuw_delete_post'));
        add_filter('manage_posts_columns', array($this, 'nuw_table_header'));
        add_action('manage_posts_custom_column', array($this, 'nuw_custom_column'), 10, 2);
    }

    public function nuw_custom_column($column_name, $post_id)
    {
        if ($column_name == 'nuwire') {
            $submitted = get_post_meta($post_id, '_nuw_submit', true);
            if ($submitted) {
                echo 'Submitted';
            } else {
                echo 'Not Submitted';
            }
        }
    }

    public function nuw_table_header($defaults)
    {
        $defaults['nuwire']  = 'NUWire';

        return $defaults;
    }

    public function nuw_add_custom_box()
    {
        $screens = get_post_types('', 'names');

        foreach ($screens as $screen) {
            add_meta_box(
                'nuw_sectionid',
                __('National University Wire', 'nuw_textdomain'),
                array($this, 'nuw_inner_custom_box'),
                $screen
            );
        }
    }

    public function nuw_delete_post($post_id)
    {
        $on_wire = get_post_meta($post_id, '_nuw_submit', true);

        if ($on_wire) {
            $response = wp_remote_post(NUWIRE_PATH . '/core/delete_post.php', array(
                'method' => 'POST',
                'body' => array('uid' => $post_id)));
        }
    }

    public function nuw_inner_custom_box($post)
    {
        wp_nonce_field(array($this, 'nuw_inner_custom_box'), 'nuw_inner_custom_box_nonce');

        $section = get_post_meta($post->ID, '_nuw_section', true);
        $checked = get_post_meta($post->ID, '_nuw_submit', true);
        $image = get_post_meta($post->ID, '_nuw_image', true);
        $desc = get_post_meta($post->ID, '_nuw_desc', true);

        $categories_json = file_get_contents(NUWIRE_PATH . '/api/categories');
        $categories = json_decode($categories_json);

        $nuw_options = get_option('nuw_options');

        $validated = isset($nuw_options['nuw_validated']) ? $nuw_options['nuw_validated'] : false;

        echo '<div style="padding: 5px 0px;">';

        if ($validated) {
            echo '<div style="line-height: 200%;">';
            echo '<label for="nuw_submit">';

            if ($checked) {
                echo '<input type="checkbox" id="nuw_submit" name="nuw_submit" checked />';
            } else {
                echo '<input type="checkbox" id="nuw_submit" name="nuw_submit" />';
            }

            _e(" Submit to NUW", 'nuw_textdomain');
            echo '</label> <br/>';
            echo '<hr/>';
            echo '<label for="nuw_section"><strong>Category:</strong> ';

            echo '<select id="nuw_section" name="nuw_section">';

            foreach ($categories as $category) {
                if ($category->id == $section) {
                    echo '<option value="' . $category->id .'" selected>' . ucfirst($category->name) . '</option>';
                } else {
                    echo '<option value="' . $category->id .'">' . ucfirst($category->name) . '</option>';
                }
            }

            echo '</select>';
            echo '</label><br/>';
            echo '<strong>Description: </strong><br/>';
            echo '<textarea id="nuw_desc" name="nuw_desc" style="width: 100%; height: 5em;">';
            echo $desc;
            echo '</textarea>';
            echo '</div>';
        } else {
            echo '<span style="color: red;">Your NUW account has not yet been validated, or the information you entered is incorrect.</span><br/><br/>';
            echo '<a class="button" href="options-general.php?page=nuw-account">Configure NUW Account</a>';
        }

        echo '</div>';
    }

    public function nuw_save_postdata($post_id)
    {
        // Check if our nonce is set.
        if (! isset($_POST['nuw_inner_custom_box_nonce']))
            return $post_id;

        $nonce = $_POST['nuw_inner_custom_box_nonce'];

        // Verify that the nonce is valid.
        if (! wp_verify_nonce($nonce, array($this, 'nuw_inner_custom_box')))
            return $post_id;

        // If this is an autosave, our form has not been submitted, so we don't want to do anything.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return $post_id;

        // Check the user's permissions.
        if ('page' == $_POST['post_type']) {
            if (! current_user_can('edit_page', $post_id))
                return $post_id;
        } else {
            if (! current_user_can('edit_post', $post_id))
                return $post_id;
        }

        $nuw_options = get_option('nuw_options');

        // Sanitize user input.
        $nuw_section = sanitize_text_field($_POST['nuw_section']);
        $nuw_submit = sanitize_text_field($_POST['nuw_submit']);

        $nuw_desc = sanitize_text_field($_POST['nuw_desc']);

        // Update the meta field in the database.
        update_post_meta($post_id, '_nuw_section', $nuw_section);
        update_post_meta($post_id, '_nuw_submit', $nuw_submit);
        update_post_meta($post_id, '_nuw_desc', $nuw_desc);

        $validated = isset($nuw_options['nuw_validated']) ? $nuw_options['nuw_validated'] : false;

        $status = get_post_status($post_id);

        if ($validated && isset($nuw_options['nuw_source_id']) && $status == 'publish') {
            if ($nuw_submit) {
                $post = get_post($post_id);

                $title = $post->post_title;
                $content = apply_filters('the_content', $post->post_content);

                if (wp_is_post_revision($post_id)) {
                    $action = 'update';
                    $parent = wp_is_post_revision($post_id);
                } else {
                    $action = 'submit';
                    $parent = null;
                }

                if (has_post_thumbnail($post_id)) {
                    $image_src = wp_get_attachment_image_src(get_post_thumbnail_id($post_id), 'single-post-thumbnail');
                    $image = $image_src[0];
                } else {
                    $image = "";
                }

                $response = wp_remote_post(NUWIRE_PATH . '/core/'.$action.'_post.php', array(
                    'method' => 'POST',
                    'body' => array(
                        'uid' => $post->ID,
                        'parent' => $parent,
                        'source_id' => $nuw_options['nuw_source_id'],
                        'title' => $title,
                        'date' => $post->post_date,
                        'content' => $content,
                        'description' => $nuw_desc,
                        'image' => $image,
                        'category' => $nuw_section,
                        'link' => get_permalink($post)
                    )
                ));
            }
        }
    }

}

class NUWSettings
{
    private $options;

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'page_init'));
    }

    public function add_plugin_page()
    {
        // This page will be under "Settings"
        add_options_page(
            'Settings Admin',
            'NUW Account',
            'manage_options',
            'nuw-account',
            array($this, 'create_admin_page')
        );
    }

    public function create_admin_page()
    {
        // Set class property
        $this->options = get_option('nuw_options');
?>
        <div class="wrap">
            <?php screen_icon(); ?>
            <h2>NUW Account Information</h2>
            <form method="post" action="options.php">
<?php
        // This prints out all hidden setting fields
        settings_fields('my_option_group');
        do_settings_sections('my-setting-admin');
        submit_button();
?>
            </form>
        </div>
<?php
    }

    public function page_init()
    {
        register_setting(
            'my_option_group', // Option group
            'nuw_options', // Option name
            array($this, 'sanitize') // Sanitize
        );

        add_settings_section(
            'setting_section_id', // ID
            'Account Settings', // Title
            array($this, 'print_section_info'), // Callback
            'my-setting-admin' // Page
        );

        add_settings_field(
            'nuw_username', // ID
            'Username', // Title
            array($this, 'nuw_username_callback'), // Callback
            'my-setting-admin', // Page
            'setting_section_id' // Section
        );

        add_settings_field(
            'nuw_password',
            'Password',
            array($this, 'nuw_password_callback'),
            'my-setting-admin',
            'setting_section_id'
        );
    }

    public function sanitize($input)
    {
        $new_input = array();
        if (isset($input['nuw_username']))
            $new_input['nuw_username'] = sanitize_text_field($input['nuw_username']);

        if (isset($input['nuw_password']))
            $new_input['nuw_password'] = sanitize_text_field($input['nuw_password']);

        $response = wp_remote_post(NUWIRE_PATH . '/core/check_user.php', array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => array('username' => $new_input['nuw_username'], 'password' => $new_input['nuw_password']),
            'cookies' => array()
        ));

        $user = json_decode($response['body']);

        $new_input['nuw_validated'] = $user->validated;
        $new_input['nuw_source_id'] = $user->source_id;

        if ($user->validated) {
            return $new_input;
        } else {
            $message = "The login information is incorrect.";
            $type = "error";

            add_settings_error(
                'nuw_username',
                esc_attr('username'),
                $message,
                $type
            );
        }
    }

    public function print_section_info()
    {
        print 'Enter your NUW account information below:';

        if (isset($this->options['nuw_validated'])) {
            if (!$this->options['nuw_validated']) {
                print '<br/><br/><span style="color: #a00;">The login information below is incorrect.</span>';
            }
        }
    }

    public function nuw_username_callback()
    {
        printf(
            '<input type="text" id="nuw_username" name="nuw_options[nuw_username]" value="%s" />',
            isset($this->options['nuw_username']) ? esc_attr($this->options['nuw_username']) : ''
        );
    }

    public function nuw_password_callback()
    {
        printf(
            '<input type="password" id="nuw_password" name="nuw_options[nuw_password]" value="%s" />',
            isset($this->options['nuw_password']) ? esc_attr($this->options['nuw_password']) : ''
        );
    }

}

if (is_admin())
    $nuw_meta_box = new NUWMetaBox();
$nuw_settings = new NUWSettings();
