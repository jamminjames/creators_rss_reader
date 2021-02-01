# Creators RSS Reader
A Wordpress plugin that integrates syndicated content from [Creators](http://www.creators.com) into a Wordpress site.

## Installation
To install, extract the [release version](http://get.creators.com/docs/wiki#wp_plugin) of the plugin to `wp-content/plugins`. You can also clone this repository to get bleeding-edge features not included in the release version. If you go the clone route, make sure to also clone [creators_php](https://github.com/creatorssyn/creators_php) to the plugin directory.

Once the plugin is installed, activate it in the Wordpress dashboard. After activation, go to Creators RSS Reader  under settings and configure the plugin with your API key. 

Once your API key is set up, the settings page will populate with the features you have access to. Check the box next to a feature to enable it on your site. 

## Notes
Enabling features in this plugin will create user accounts and post content to your site. If this is not how you want to post content, consider using a generic RSS feed reader plugin instead. 

## Support
Please use the [tech support portal](http://get.creators.com/contact/tech) on GET for support and feature requests for this plugin.

## CHANGES
I made several changes, including filtering for duplicate post titles instead of post names (which are slugs), because the slug can be changed by some themes via permalink settings. Also changed filter_post_like() function to check titles.

I added to settings the ability to choose categories and tags for each author, and/or default categories and tags that will be used if not set for individual authors. With these changes, categories and tags are created along with the post. Categories, especially can be very useful in determining where and how the posts will be displayed on the website. 

In order to make the above work, changes were made to these functions: create_post(), filter_post_like(), register_settings(); and new functions were created: display_setting_cats_text($args), display_setting_tags_text($args), display_setting_default_cats_text(), display_setting_default_tags_text(). Additions were made to the $default_options array as well.
