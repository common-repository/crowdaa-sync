=== Crowdaa Sync ===
Contributors: crowdaa
Donate link: https://www.crowdaa.com
Tags: crowdaa, app, application, mobile, sync, synchronization, api, cloud
Requires at least: 5.5.5
Requires PHP: 7.3
Tested up to: 5.9
Stable tag: 1.10.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Plugin to sync posts and categories from the Crowdaa API (https://www.crowdaa.com/) to WordPress and vice versa.

== Description ==

Plugin to sync posts and categories from the Crowdaa API (https://www.crowdaa.com/) to WordPress and vice versa.
The plugin syncs posts and categories, pictures and videos of these posts. The plugin has cron for data sync and checks for updates each minute.

== Installation ==

If a custom version was provided by our team, upload it to your wordpress instance using the normal manual way. If not, download it from the Wordpress plugins index at https://wordpress.org/plugins/ .

After installing it, don't forget to enable it in the plugins list!

After installation, the Crowdaa Sync link will appear in the admin panel where all the plugin settings are located.

After successful login with the credentials accessible on our platform, you will see additional settings and options, allowing you to enable or not automatic synchronization, or just disable one side of it, as well as retrieving the last logs and the pending synchronization queue.


When synchronizing manually or fetching the pending operation queue, tou can see all of the informations about articles that will be synchronized.

On Wordpress, the required fields in every post are Title, Content, images or video in the Gallery, and Category. Without a category, the post will not be synchronized. A warning about this is displayed at the top of the single page.
You can add one item to the gallery or several by holding Ctrl. To display the gallery use the [display_gallery] shortcode.

Technical details :
The plugin logs informations in a file called logs.txt at the root directory of the plugin. It is automatically cleaned by a cron task every 7 days.
