<?php
/**
 * Plugin Name: Creators RSS Reader-HT2
 * Plugin URI: https://github.com/creatorssyn/creators_rss_reader
 * Description: A plugin to interface Creators content directly into your WordPress CMS
 * Version: 1.0.0
 * Author: Creators Syndicate <btelle@creators.com>
 * Author URI: http://www.creators.com
 * License: MIT
 */

defined( 'ABSPATH' ) OR exit;

if(!file_exists(dirname(__FILE__).'/creators_php/creators_php.php'))
{
    exit('Please install the Creators PHP library to continue');
}
else
{
    require_once(dirname(__FILE__).'/creators_php/creators_php.php');
}

class CreatorsRSSReader
{
    protected static $default_options = array(
        'creators_feed_reader_auto_publish'=>TRUE, 
        'creators_feed_reader_user_id'=>1,
        'creators_feed_reader_api_key'=>'',
        'creators_feed_reader_post_name_pattern'=>'%t', 
        'creators_feed_reader_user_ids'=>array(),
        'creators_feed_reader_last_run'=>0,
        'creators_feed_reader_features'=>array(),
	'creators_feed_reader_tags'=>array(),
	'creators_feed_reader_cats'=>array(),
	'creators_feed_reader_default_cats'=>'',
	'creators_feed_reader_default_tags'=>''
    );
    
    protected static $instance;
    
   public static function init() 
    {
        is_null(self::$instance) AND self::$instance = new self;

        // Actions
        add_action('parse_rss_feed', array('CreatorsRSSReader', 'parse_rss_feed'));
        add_action('admin_menu', array('CreatorsRSSReader', 'add_menu_option'));
        add_action('admin_init', array('CreatorsRSSReader', 'register_settings'));
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), array('CreatorsRSSReader', 'plugin_action_links'));
        
        return self::$instance;
    }
    
    /**
     * Activate the plugin
     */
   public static function activate()
    {
        if (!current_user_can('activate_plugins'))
            return;
        
        // Set up default options
        foreach(self::$default_options as $k=>$v)
        {
            add_option($k, $v);
        }
        
        // Schedule hourly RSS checks
        wp_schedule_event(time(), 'hourly', 'parse_rss_feed');
    }
    
    /**
     * Deactivate the plugin
     */
   public static function deactivate()
    {
        if (!current_user_can('activate_plugins'))
            return;
        
        // Remove scheduled hook
        wp_clear_scheduled_hook('parse_rss_feed');
    }
    
    /**
     * Uninstall the plugin
     * Should this delete all users and posts? 
     */
   public static function uninstall()
    {
        if (!current_user_can('activate_plugins'))
            return;
        
        if ( __FILE__ != WP_UNINSTALL_PLUGIN )
            return;
        
        // Remove options
        foreach(self::$default_options as $k=>$v)
        {
            delete_option($k);
        }
    }
    
    /**
     * Read the Creators RSS Feed and post content to the site
     * @return boolean TRUE if feed was read, FALSE if feed cannot be read or hasn't been updated
     */
   public static function parse_rss_feed()
    {
        if(!function_exists('simplexml_load_file'))
        {
            return FALSE;
        }
        
        if(get_option('creators_feed_reader_api_key') === '')
            return;
        
        $url = 'http://get.creators.com/feed/'.get_option('creators_feed_reader_api_key').'.rss';
        $xml = simplexml_load_file($url);
        
        var_dump($xml->channel);
        /* TESTING: Comment out to let it run */
        if(strtotime($xml->channel->lastBuildDate) <= get_option('creators_feed_reader_last_run'))
        {
            echo "Feed is stale, bye!";
            return FALSE;
        }
        
        update_option('creators_feed_reader_last_run', time());
        
        foreach($xml->channel->item as $item)
        {
            preg_match('/([a-z0-9]{2,4})@/', $item->author, $m);
            if(isset($m[1]) && self::$instance->should_post_feature($m[1]))
            {
		self::$instance->create_post($item);
            }
        }
        
        return TRUE;
    }
    
    /**
     * Check if a feature should get posted
     * 
     * @param string $filecode Creators feature file code
     * @return boolean 
     */ 
    private static function should_post_feature($filecode)
    {
        $users = get_option('creators_feed_reader_features');
        return isset($users[$filecode]) && $users[$filecode] == 'on';
    }
    
    /**
     * Create a post from a feature release
     * @param object $item SimpleXML item object
     * @return mixed ID of new post, or WP_Error on error
     * HT: added category, tags, and changed 'cr_search_name' 
     */
    private static function create_post($item)
    {
		// get author code, comes before '@'
		$author_code = $item->author;
		$arrauth = explode("@", $author_code, 2);
		$file_code = $arrauth[0];
		// get categories and tags
		$mydefault_cat = get_option('creators_feed_reader_default_cats');
		$mydefault_tag = get_option('creators_feed_reader_default_tags');
		$postcat = get_option('creators_feed_reader_cats');
		$posttag = get_option('creators_feed_reader_tags');

		// if nothing in an Author's category or tag, use mydefault
		// in either case, explode by commas to create arrays
		if (empty($postcat[$file_code])) {
			$postcats = explode(',', $mydefault_cat);
		} else {
			$postcats = explode(',', $postcat[$file_code]);
		}
		if (empty($posttag[$file_code])) {
			$posttags = explode(',', $mydefault_tag);
		} else {
			$posttags = explode(',', $posttag[$file_code]);
		}

		$post = array();
		$post['post_content'] = (string)$item->description;
		$post['post_name'] = self::$instance->parse_post_name($item);
		$post['post_title'] = (string)substr($item->title[0], 0, strrpos($item->title[0], ' by '));
		$post['post_status'] = get_option('creators_feed_reader_auto_publish')? 'publish': 'draft';
		$post['post_author'] = self::$instance->get_user_id($item->author);
		$post['post_date'] = date('Y-m-d H:i:s', strtotime($item->pubDate));
		$post['post_category'] = $postcats;
		$post['tags_input'] = $posttags;
        
        var_dump($post);

        add_filter( 'posts_where', array('CreatorsRSSReader', 'filter_post_like'), 10, 2 );
        
	// using post_title instead of post_name (slug), as slug is changed by some themes via permalink settings, also changed filter_post_like()
        $args = array(
		'cr_search_name' => $post['post_title']
        );
        
        $wp_query = new WP_Query($args);

        remove_filter( 'posts_where', array('CreatorsRSSReader', 'filter_post_like'), 10, 2 );

		if(!$wp_query->have_posts()) {
			return wp_insert_post($post);
		} else {
			return FALSE;
		}

	}
    
    /**
     * Create a user account for a Creators feature
     * 
     * @param string $filecode Creators feature file code
     * @return boolean
     */
    private static function create_user($filecode)
    {
        try
        {
            $cr = new Creators_API(get_option('creators_feed_reader_api_key'));
            $feature = $cr->get_feature_details($filecode);
            
            $user = array();
            $user['user_login'] = self::$instance->make_username($feature['title']);
            $user['user_pass'] = wp_generate_password();
            $user['user_url'] = 'http://www.creators.com/read/'.$feature['file_code'];
            $user['display_name'] = $feature['title'];
            $user['user_email'] = $feature['file_code'].'@get.creators.com';
            $user['role'] = 'author';
            
            if(isset($feature['authors']) && count($feature['authors']) == 1)
            {
                list($user['first_name'], $user['last_name']) = explode(' ', $feature['authors'][0]['name'], 2);
                $user['description'] = strip_tags(str_replace('</p>', "\r\n\r\n", $feature['authors'][0]['bio']));
            }
            
            //var_dump($user);
            $exists = email_exists($user['user_email']);
            
            if($exists === FALSE)
            {
                $id = wp_insert_user($user);
                
                if(is_int($id))
                {
                    $exists = $id;
                }
                else
                {
                    return $id;
                }
            }
            
            if(is_int($exists))
            {
                $users = get_option('creators_feed_reader_user_ids');
                $users[$filecode] = $exists;
                
                update_option('creators_feed_reader_user_ids', $users);
                
                return TRUE;
            }
        }
        catch(APIException $e)
        {
            return FALSE;
        }
    }
    
    /**
     * Get a user's ID from their Creators file code
     * @param string $author_string The author attribute of an RSS item
     * @return mixed User ID, or NULL if a user doesn't exist
     */
    private static function get_user_id($author_string)
    {
        preg_match('/([a-z0-9]+)@/', $author_string, $m);

        if(isset($m[1]))
        {
            $users = get_option('creators_feed_reader_user_ids');
            if(isset($users[$m[1]]))
                return $users[$m[1]];
        }
        
        return NULL;
    }
    
    /**
     * Create a post URL from a release title and url pattern
     * 
     * @param object $item SimpleXML item object
     * @return string Post URL
     */
    private static function parse_post_name($item)
    {
        $slug = get_option('creators_feed_reader_post_name_pattern');
        preg_match('|/([0-9]+)|', $item->guid, $matches);
        $post_id = $matches[1];
        $post_title = sanitize_title(trim(substr($item->title, 0, strrpos($item->title, ' by '))));
        $post_author = sanitize_title(trim(substr($item->title, strrpos($item->title, ' by ')-1)));
        
        return $post_id.'-'.str_replace(array('%t', '%a'), array($post_title, $post_author), $slug);
    }
    
    /**
     * Make a Wordpress-safe username.
     * 
     * @param string $title Feature title
     * @return string Username
     */
    private static function make_username($title)
    {
        return sanitize_user(strtolower(str_replace(' ', '', $title)), TRUE);
    }
    
    /**
     * Settings functions: added tags, categories, and defaults for both
     */
   public static function register_settings()
    {
        register_setting('creators_rss', 'creators_feed_reader_api_key');
        register_setting('creators_rss', 'creators_feed_reader_auto_publish');
        register_setting('creators_rss', 'creators_feed_reader_post_name_pattern');
        register_setting('creators_rss', 'creators_feed_reader_features');
	register_setting('creators_rss', 'creators_feed_reader_tags');
	register_setting('creators_rss', 'creators_feed_reader_cats');
	register_setting('creators_rss', 'creators_feed_reader_default_cats');
	register_setting('creators_rss', 'creators_feed_reader_default_tags');
        
        add_settings_section('creators_rss_main', 'Main Settings', array('CreatorsRSSReader', 'display_main_text'), 'creators_rss');
        add_settings_field('creators_feed_reader_api_key', 'Your API Key', array('CreatorsRSSReader', 'display_setting_api_key'), 'creators_rss', 'creators_rss_main');
        add_settings_field('creators_feed_reader_auto_publish', 'Publish Automatically', array('CreatorsRSSReader', 'display_setting_auto_publish'), 'creators_rss', 'creators_rss_main');
        add_settings_field('creators_feed_reader_post_name_pattern', 'Post URL Pattern', array('CreatorsRSSReader', 'display_setting_name_pattern'), 'creators_rss', 'creators_rss_main');
        
        try 
        {
            $cr = new Creators_API(get_option('creators_feed_reader_api_key'));
            $features = $cr->get_features();
        } 
        catch(APIException $e) 
        {
            $features = NULL;
        }

        if($features !== NULL && count($features) > 0)
        {
            add_settings_section('creators_rss_features', 'Your Features', array('CreatorsRSSReader', 'display_features_text'), 'creators_rss');
		add_settings_field('creators_feed_reader_default_cats', 'Default categories for all features (ID #s, separated by commas)', array('CreatorsRSSReader', 'display_setting_default_cats_text'), 'creators_rss', 'creators_rss_features');
		add_settings_field('creators_feed_reader_default_tags', 'Default tags for all features (separated by commas)', array('CreatorsRSSReader', 'display_setting_default_tags_text'), 'creators_rss', 'creators_rss_features');
            foreach($features as $f)
            {
                add_settings_field('creators_feed_reader_features['.$f['file_code'].']', $f['title'], array('CreatorsRSSReader', 'display_setting_author_checkbox'), 'creators_rss', 'creators_rss_features', array($f['file_code']));
		add_settings_field('creators_feed_reader_cats['.$f['file_code'].']', '<div style="margin: -20px 0 0 15px;">Categories (ID #s, separated by commas)</div>', array('CreatorsRSSReader', 'display_setting_cats_text'), 'creators_rss', 'creators_rss_features', array($f['file_code']));
		add_settings_field('creators_feed_reader_tags['.$f['file_code'].']', '<div style="margin: -20px 0 0 15px;">Tags (Separated by commas)</div>', array('CreatorsRSSReader', 'display_setting_tags_text'), 'creators_rss', 'creators_rss_features', array($f['file_code']));
            }
        }
    }
    
   public static function add_menu_option()
    {
        $page_hook = add_submenu_page('options-general.php', 'Creators RSS Feed', 'Creators RSS Feed', 'manage_options', 'creators-rss-feeds-options', array('CreatorsRSSReader', 'display_options_page'));
        add_action('load-'.$page_hook, array('CreatorsRSSReader', 'settings_load_hook'));
    }
    
   public static function display_main_text()
    {
        echo '<p>General feed reader settings</p>';
    }
    
   public static function display_features_text()
    {
        echo '<p>Enable features you want to post to your site below, along with any tags and/or category IDs you want to assign to posts by these artists, separated by commas.</p>';
    }
    
   public static function display_setting_cats_text($args)
    {
        $file_code = $args[0];
	$authorcats = get_option('creators_feed_reader_cats');
        echo "<div style='margin-top: -20px'><input id='creators_feed_reader_cats[{$file_code}]' name='creators_feed_reader_cats[{$file_code}]' size='40' type='text' value=".json_encode($authorcats[$file_code])." /></div>";
    }

   public static function display_setting_tags_text($args)
    {
        $file_code = $args[0];
	$authortags = get_option('creators_feed_reader_tags');
        echo "<div style='margin-top: -20px'><input id='creators_feed_reader_tags[{$file_code}]' name='creators_feed_reader_tags[{$file_code}]' size='40' type='text' value=".json_encode($authortags[$file_code])." /></div>";
    }

   public static function display_setting_default_cats_text()
    {
       $option = get_option('creators_feed_reader_default_cats');
        echo "<input id='creators_feed_reader_default_cats' name='creators_feed_reader_default_cats' size='40' type='text' value='{$option}' />";
    }

   public static function display_setting_default_tags_text()
    {
       $option = get_option('creators_feed_reader_default_tags');
        echo "<input id='creators_feed_reader_default_tags' name='creators_feed_reader_default_tags' size='40' type='text' value='{$option}' />";
    }

   public static function display_setting_name_pattern()
    {
        $option = get_option('creators_feed_reader_post_name_pattern');
        echo "<input id='creators_feed_reader_post_name_pattern' name='creators_feed_reader_post_name_pattern' size='40' type='text' value='{$option}' />";
    }
    
   public static function display_setting_auto_publish()
    {
        $option = get_option('creators_feed_reader_auto_publish');
        echo "<input id='creators_feed_reader_auto_publish' type='checkbox' name='creators_feed_reader_auto_publish' ".($option?"checked='checked'":"")." />";
    }
    
   public static function display_setting_api_key()
    {
        $option = get_option('creators_feed_reader_api_key');
        echo "<input id='creators_feed_reader_api_key' name='creators_feed_reader_api_key' size='40' type='text' value='{$option}' />";
    }
    
   public static function display_setting_author_checkbox($args)
    {
        $file_code = $args[0];
	$authors = get_option('creators_feed_reader_features');
        $checked = FALSE;
        if(isset($authors[$file_code]) && $authors[$file_code] == 'on')
            $checked = TRUE;
        echo "<input id='creators_feed_reader_features[{$file_code}]' type='checkbox' name='creators_feed_reader_features[{$file_code}]' ".($checked?"checked='checked'":"")." />";
    }
    
   public static function display_options_page()
    {
        if ( !current_user_can( 'manage_options' ) )  {
            wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
        
        echo '<div class="wrap">';
        echo '<h2>Creators RSS Feed Options</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('creators_rss');
        do_settings_sections('creators_rss');
        echo '<p class="submit"><input type="submit" value="Save Changes" class="button button-primary" id="submit" name="submit"></p>';
        echo '</form>';
        echo '</div>';
    }
    
   public static function settings_load_hook()
    {
        if(isset($_GET['settings-updated']) && $_GET['settings-updated'])
        {
            $features = get_option('creators_feed_reader_features');
            $ids = get_option('creators_feed_reader_user_ids');
            
            foreach($features as $file_code=>$v)
            {
                if($v == 'on' && !isset($ids[$file_code]))
                {
                    self::$instance->create_user($file_code);
                }
            }
        }
    }
    
   public static function plugin_action_links($links)
    {
        $links[] = '<a href="'. esc_url( get_admin_url(null, 'options-general.php?page=creators-rss-feeds-options') ) .'">Settings</a>';
        return $links;
    }
    
	// removed & from before $wp_query, was giving PHP 'reference' warning
   public static function filter_post_like($where, $wp_query)
    {
        global $wpdb;

        if($search_term = $wp_query->get('cr_search_name'))
        {
            // add single quotes to search term
            $search_term = ' \'' . $search_term . '\'';
            var_dump($search_term);
			// changed from post_name (slug) to post_title and 'LIKE' to '='
            $where .= ' AND ' . $wpdb->posts . '.post_title = '.$search_term;
        }
        
        return $where;
    }

}

/* Set hooks */

add_action('plugins_loaded', array('CreatorsRSSReader', 'init'));

// Activation hook
register_activation_hook( __FILE__, array('CreatorsRSSReader', 'activate'));

// Deactivation hook
register_deactivation_hook( __FILE__, array('CreatorsRSSReader', 'deactivate'));

// Uninstall hook
register_uninstall_hook( __FILE__, array('CreatorsRSSReader', 'uninstall'));

/* End of file */
