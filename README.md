# HTMLCache
This is a caching library designed specifically for caching of web-pages. It is not compliant with [PSR-6](https://github.com/php-fig/cache), in terms of format, although some functionality may be similar. It's not meant as a replacement for other caching solutions like Symfony's [Cache](https://github.com/symfony/cache), as well, since it's a niche solution, rather a more generic one.

## Why?
While caching seems to be long-resolved thing with lots of good solutions, I've found 2 points, that did not sit right with my desire to server consistent web-pages:
1. If codebase serving a page changed, it did not invalidate the cached version of a page automatically, meaning a chance to serve stale content.
2. If I am to serve identical headers, I would need to cache them separately and link them somehow to the cached content through extra logic.
Some may say that these are minor issues, and he may be right, that they are unlikely to cause an actual issue, but for me both mean that unless I add extra logic accompanying Symfony\Cache (used previously), I will serve stale content with new headers, that can worsen user experience. I do not want that.

## What?
That's why I've decided to write my own solution, designed specifically for web pages.
First problem is solved by calculating the version of the code base like this:
```php
$used_files = get_included_files();
$this->version = count($used_files).'.'.max(max(array_map('filemtime', array_filter($used_files, 'is_file'))),
```
Then this version is stored in the cache for each designated key. When getting the data out of cache it will be matched with current one and if they do not match, it will be treated as a cache miss. This is not a 100% guarantee, but since I have a main "gateway" file that handles all the page generation, it works for me just fine.
Second problem is solved by caching headers sent along with the data. Once the data is retrieved the same headers will be sent once again.
That's not all: it has other features, that may be useful:
1. Optional zipping of the data in cache to reduce footprint
2. Direct output using `zEcho` function from [HTTP20](https://github.com/Simbiat/HTTP20/blob/main/doc/Common.md#zecho) library, if available (otherwise regular `echo` will be used)
3. Validation of cache using hash to reduce chances of serving corrupted cache
4. Techniques to negate cache-slamming (at least, to an extent)
5. Automatically tries to use either APCU or file storage. Memcache and the like are not supported, since I do not ahve appropriate infrastructure to test them.
6. Transient in case of failures so that your webpage will still be displayed (generally)

## How?
Here's a simple example of how I'm using it. Scroll further for more details on the functions.
```php
#Create HTMLCache object
$html_cache = (new \Simbiat\HTMLCache($siteconfig['cachedir'].'html/'));
#Attempt to use cache
$html_cache->get('', true, true, true);
#Do some processing in case cache was not hit, to get $output
#Save to cache and output directly
if ($uri[1] === 'statistics') {
    $html_cache->set($output, '', 604800, 600, true, true);
} elseif ($uri[1] === 'achievement') {
    $html_cache->set($output, '', 259200, 600, true, true);
}
#Output page if not required to cache
(new \Simbiat\http20\Common)->zEcho($output);
```

## Details on usage
### Construct
```php
__construct(string $files_pool = '', bool $apcu = false, int $max_random = 1)
```
When creating the object you can specify path where files of the cache will be stored using `$files_pool`. If empty, this will let the class know, that you do not want to use file storage for caching. In that case you need to explicitly enable aPCU caching with setting `$apcu` to `true`. It is set to `false` by default due to potential limitations in resources you may have.
To negate cache slamming, class reduces expiration date during validation by a random amount from 0 to `$max_random`, which is defaulted to 1 minute. You can adjust this number or use 0 to, essentially, disable this feature (not advisable).

### Set
```php
set(string $string, string $key ='', int $ttl = 60, int $grace = 100, bool $zip = true, bool $direct = true, string $cache_strategy = '')
```
Use `set` to write to cache. `$string` is the only mandatory value. Since the class is designed for HTML pages, we are restricting the type of the value to `string` only.
`$key` is an optional value for ID with which the value will be stored. If empty current `REQUEST_URI` will be used (if it's empty `index.php` will be used). Regardless, the value will be hashed for consistency.
`$ttl` is `time to live` for the cached value. After it expires, the value will be considered `stale`. Defaults to 60, that is 1 hour.
`$grace` is an optional grace period to help with cache slamming. When cache hit is successful, but it has expired, class updates the expiration value to `time()+$grace` and sets `$grace = 0`. This helps with concurrent requests, so that they will still receive the stale data for extra seconds after its expiration, while initial hit updates the cache. Default is 1 minute.
`$zip` will GZIP the body and headers of the page to save some space. With current processing power and average size of HTML pages, this is a very fast operation, which can help you cache more stuff both in memory and on disk. You can disable it, if you want, by setting it to `false`.
`$direct` if set to `true` will output the webpage right after cache is written. Since we are dealing with webpages, there is not much reason to do something after we have a generated page, but you can disable this behaviour and, instead, receive a boolean value representing the result of the function.
`$cache_strategy` is used for setup of `Cache-Control` header (using appropriate [function](https://github.com/Simbiat/HTTP20/blob/main/doc/Headers.md#cachecontrol)) if you are using `zEcho`. This value will also be cached.

### Get
```php
get(string $key = '', bool $script_version = true, bool $direct = true, bool $stale_return = false)
```
Use `get` to get the cached data.
`$key` is an optional value for ID with which the value will be stored. If empty current `REQUEST_URI` will be used (if it's empty `index.php` will be used). Regardless, the value will be hashed for consistency.
`$script_version` if set to `true`, will force validation of codebase version, as described above. Since this is more of a personal preference, you can disable this feature.
`$direct` if set to `true` will output the webpage right getting the page. Since we are dealing with webpages, there is not much reason to disable this, but you can do this and, instead, receive an array of representing all the cached data. I doubt it will be useful outside the class, though.
`$stale_return` if set to `true` will allow to manually serve stale content, while generating new content. In a way, similar to how `stale-while-revalidate` in `Cache-Control` header works.

### cacheOutput
```php
cacheOutput(array $data, bool $exit = true)
```
Use to manually output the data returned by `get`.
`$data` is the array returned by `get`.
`$exit` flag allows canceling automatic exit after output (default), in case you plan to do something after it (invaluable when using `get` with `$stale_return = true`).

### Delete
```php
delete(string $key = '')
```
Use `delete` to remove cached item.
`$key` is an optional value for ID with which the value will be stored. If empty current `REQUEST_URI` will be used (if it's empty `index.php` will be used). Regardless, the value will be hashed for consistency.

### Garbage collection
```php
gc(int $max_age = 60, int $max_size = 1024)
```
This garbage collection function explicitly removes old entries older than `$max_age` minutes (60 by default) or all the oldest entries until the total size it less than `$max_size` megabytes.
Modification time is checked for this, meaning, that only cache that was not used for the amount of days will be affected. You should adjust this value based on the longest cache time you have in your project. Alternatively, you can disable the feature by setting the value to 0.
Will also remove empty directories, when file storage is used.
