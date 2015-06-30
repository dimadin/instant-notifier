Using
--------
Install and activate this plugin, then place the following code with list of feeds you want to be checked to your custom code:

```
function md_in_feeds( $feeds ) {
	return array( 'http://blog.milandinic.com/feed/', 'http://www.milandinic.com/feed/' );
}
add_filter( 'instant_notifier_feeds', 'md_in_feeds' );
```
