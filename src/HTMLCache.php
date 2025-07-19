<?php
declare(strict_types=1);
namespace Simbiat;

use Simbiat\http20\Common;

class HTMLCache
{
    #Settings initialized on construction
    private string $version;
    private bool $apcu = false;
    private string $files = '';
    private bool $pool_ready = false;
    private int $max_random;
    
    public function __construct(string $files_pool = '', bool $apcu = false, int $max_random = 1)
    {
        #Sanitize random value
        if ($max_random < 0) {
            $max_random = 60;
        } else {
            $max_random *= 60;
        }
        $this->max_random = $max_random;
        #Get the version of the scripts based on all files called so far
        $used_files = \get_included_files();
        $this->version = \count($used_files).'.'.\getlastmod();
        #Check if APCU is available
        if ($apcu && \extension_loaded('apcu') && \ini_get('apc.enabled')) {
            $this->apcu = true;
        }
        #Check if file-based pool exists
        if (\preg_match('/^\s*$/u', $files_pool) !== 1) {
            if (\is_dir($files_pool)) {
                $this->files = mb_rtrim(mb_rtrim($files_pool, '\\', 'UTF-8'), '/', 'UTF-8').'/';
                #If it does not exist, attempt to create it
            } elseif (\mkdir($files_pool, recursive: true)) {
                $this->files = mb_rtrim(mb_rtrim($files_pool, '\\', 'UTF-8'), '/', 'UTF-8').'/';
            }
        }
        #If either APCU or files pool is available - set the flag to true
        if ($this->files !== '' || $this->apcu) {
            $this->pool_ready = true;
        }
    }
    
    #Function to store HTML page
    public function set(string $string, string $key ='', int $ttl = 60, int $grace = 1, bool $zip = true, bool $direct = true, string $cache_strat = ''): bool
    {
        if ($this->pool_ready) {
            #Sanitize integers
            if ($ttl < 1) {
                $ttl = 3600;
            } else {
                $ttl *= 60;
            }
            if ($grace < 1) {
                $grace = 60;
            } else {
                $grace *= 60;
            }
            #Set key based on REQUEST_URI
            if (empty($key)) {
                $key = \hash('sha3-256', (empty($_SERVER['REQUEST_URI']) ? 'index.php' : $_SERVER['REQUEST_URI']).\http_build_query($_GET ?? []));
            } else {
                $key = \hash('sha3-256', $key);
            }
            #GZip data
            if ($zip && \extension_loaded('zlib')) {
                $body = \gzcompress($string, 9, \FORCE_GZIP);
                $headers = \gzcompress(\serialize(\headers_list()), 9, \FORCE_GZIP);
            } else {
                $body = $string;
                $headers = \headers_list();
                $zip = false;
            }
            #Organize HTTP data
            $data = [
                'body' => $body,
                'headers' => $headers,
            ];
            #Organize data for storage
            $data = [
                'key' => $key,
                'zip' => $zip,
                'version' => $this->version,
                'cache_strategy' => $cache_strat,
                'uri' => $_SERVER['REQUEST_URI'],
                'data' => $data,
            ];
            #Hash the data
            $data['hash'] = \hash('sha3-256', \serialize($data));
            #Set timings
            $data['ttl'] = $ttl;
            $data['expires'] = \time() + $ttl;
            $data['grace'] = $grace;
            #Write cache
            $result = $this->writeToCache($key, $data);
        } else {
            $result = false;
        }
        #Send header indicating that response was cached
        @\header('X-Server-Cached: true');
        #Echo the data if we chose to do it
        if ($direct) {
            #Send header indicating that live data was sent
            @\header('X-Server-Cache-Hit: false');
            Common::zEcho($string, $cache_strat);
        } else {
            return $result;
        }
        return false;
    }
    
    #Function to get HTML page from cache
    public function get(string $key = '', bool $script_version = true, bool $direct = true, bool $stale_return = false): bool|array
    {
        if ($this->pool_ready) {
            #Set key based on REQUEST_URI
            if (empty($key)) {
                $key = \hash('sha3-256', (empty($_SERVER['REQUEST_URI']) ? 'index.php' : $_SERVER['REQUEST_URI']).\http_build_query($_GET ?? []));
            } else {
                $key = \hash('sha3-256', $key);
            }
            #Check APCU
            if ($this->apcu && \apcu_exists('SimbiatHTMLCache_'.$key) === true) {
                #Get data from cache
                $data = \apcu_fetch('SimbiatHTMLCache_'.$key, $result);
                #Check that data was retrieved. If not, we will fall through to file.
                if ($result === false) {
                    $data = NULL;
                }
            }
            #Get final path based on hash
            $final_path = $this->files.\substr($key, 0, 2).'/'.\substr($key, 2, 2).'/';
            #Check the file
            if (empty($data) && $this->files !== '' && \is_file($final_path.$key) && \is_readable($final_path.$key)) {
                $data = \unserialize(\file_get_contents($final_path.$key), ['allowed_classes' => []]);
            }
            #Validate data
            if (empty($data)) {
                #Indicate that there is no cached version of the data
                @\header('X-Server-Cached: false');
                return false;
            }
            if ($this->cacheValidate($key, $data, $script_version)) {
                #Output data
                if ($direct) {
                    $this->cacheOutput($data);
                } else {
                    #Indicate that there is a cached version of the data
                    @\header('X-Server-Cached: true');
                    if ($stale_return) {
                        $data['stale'] = false;
                    }
                    return $data;
                }
            } elseif (!$direct && $stale_return) {
                @\header('X-Server-Cached: stale');
                $data['stale'] = true;
                return $data;
            }
        }
        return false;
    }

    #Function to remove from cache
    public function delete(string $key = ''): bool
    {
        #Sanitize key
        if (empty($key)) {
            $key = \hash('sha3-256', (empty($_SERVER['REQUEST_URI']) ? 'index.php' : $_SERVER['REQUEST_URI']).\http_build_query($_GET ?? []));
        } else {
            $key = \hash('sha3-256', $key);
        }
        #Remove from APCU
        if ($this->apcu && \apcu_exists('SimbiatHTMLCache_'.$key) === true) {
            $result = \apcu_delete('SimbiatHTMLCache_'.$key);
            if (!$result) {
                return false;
            }
        }
        #Get the final path based on hash
        $final_path = $this->files.\substr($key, 0, 2).'/'.\substr($key, 2, 2).'/';
        #Remove the file
        if ($this->files !== '' && \is_file($final_path.$key)) {
            $result = \unlink($final_path.$key);
            if (!$result) {
                return false;
            }
        }
        return true;
    }
    
    #Helper function to write cache data
    private function writeToCache(string $key, array $data): bool
    {
        #Cache data to APCU
        if ($this->apcu) {
            $result = \apcu_store('SimbiatHTMLCache_'.$key, $data, $data['ttl'] ?? 0);
            if (!$result) {
                return false;
            }
        }
        #Get the final path based on hash
        $final_path = $this->files.mb_substr($key, 0, 2, 'UTF-8').'/'.mb_substr($key, 2, 2, 'UTF-8').'/';
        #Cache data to file
        if ($this->files !== '') {
            #Create folder if missing
            if (!\is_dir($final_path) && !\mkdir($final_path, recursive: true) && !\is_dir($final_path)) {
                throw new \RuntimeException(\sprintf('Directory "%s" was not created', $final_path));
            }
            $result = (bool)\file_put_contents($final_path.$key, \serialize($data), \LOCK_EX);
            if (!$result) {
                return false;
            }
        }
        return true;
    }
    
    #Helper function to validate cache
    private function cacheValidate(string $key, array $data, bool $script_version = true): bool
    {
        #Check key
        if ($key !== $data['key']) {
            return false;
        }
        #Check if stale. We use a random seed to allow earlier expiration, which can help with cache slamming
        if (empty($data['expires']) || $data['expires'] < \time() - \random_int(0, $this->max_random)) {
            #Check grace period
            if (empty($data['grace'])) {
                return false;
            } else {
                #Prepare new set of data
                $new_data = $data;
                $new_data['expires'] = \time() + $new_data['grace'];
                $new_data['grace'] = 0;
            }
        }
        #Check script version. This may help avoid situations, when you have updated PHP files, that are responsible for page generation, but there is also a cache version, which uses older revisions, that may provide inappropriate results
        if ($script_version && $this->version !== $data['version']) {
            return false;
        }
        #Check hash to reduce chances of serving corrupted data
        $hash = $data['hash'];
        unset($data['ttl'], $data['expires'], $data['grace'], $data['hash'], $data['stale']);
        if (!\hash_equals($hash, \hash('sha3-256', \serialize($data)))) {
            return false;
        }
        if (isset($new_data)) {
            #Update expiration date in cache to prevent cache slamming
            $this->writeToCache($key, $new_data);
            #Return false to get up-to-date data
            return false;
        }
        return true;
    }
    
    #Function to output cached data
    public function cacheOutput(array $data, bool $exit = true): void
    {
        #Unzip data
        if ($data['zip'] === true) {
            $data['data']['body'] = \gzdecode($data['data']['body']);
            $data['data']['headers'] = \unserialize(\gzdecode($data['data']['headers']), ['allowed_classes' => false]);
        }
        #Send headers
        \array_map('\header', $data['data']['headers']);
        #Send header indicating that cached response was sent
        @\header('X-Server-Cached: true');
        @\header('X-Server-Cache-Hit: true');
        Common::zEcho($data['data']['body'], (empty($data['cache_strategy']) ? '' : $data['cache_strategy']), exit: $exit);
    }
    
    #Garbage collector
    public function gc(int $max_age = 60, int $max_size = 1024): void
    {
        #Sanitize values
        if ($max_age < 0) {
            #Reset to default 1 hour cache
            $max_age = 60 * 60;
        } else {
            #Otherwise, convert into minutes (seconds do not make sense here at all)
            $max_age *= 60;
        }
        if ($max_size < 0) {
            #Consider that the size limit was removed
            $max_size = 0;
        } else {
            #Otherwise, convert to megabytes (lower than 1 MB does not make sense)
            $max_size *= 1024 * 1024;
        }
        #Set list of empty folders (removing within iteration seems to cause fatal error)
        $empty_dirs = [];
        if ($max_age > 0 || ($max_size > 0 && $this->files !== '')) {
            #Get the oldest allowed time
            $oldest = \time() - $max_age;
            #Garbage collector for old files, if files pool is used
            if ($this->files !== '') {
                $size_to_remove = 0;
                #Initiate iterator
                $file_iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->files, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
                #List of files to remove
                $to_delete = [];
                #List of fresh files with their sizes
                $fresh = [];
                #Iterate the files to get size and date first
                #Using catch to handle potential race condition, when file gets removed by a different process before the check gets called
                try {
                    foreach ($file_iterator as $file) {
                        if (\is_dir($file)) {
                            #Check if empty
                            if (!new \RecursiveDirectoryIterator($file, \FilesystemIterator::SKIP_DOTS)->valid()) {
                                #Remove directory
                                $empty_dirs[] = $file;
                            }
                        } else {
                            #Check if file
                            if (\is_file($file)) {
                                #If we have age restriction, check if the age
                                $time = \filemtime($file);
                                if ($max_size > 0) {
                                    $size = \filesize($file);
                                } else {
                                    $size = 0;
                                }
                                if ($max_age > 0 && $time <= $oldest) {
                                    #Add to list of files to delete
                                    $to_delete[] = $file;
                                    if ($max_size > 0) {
                                        $size_to_remove += $size;
                                    }
                                } else {
                                    #Get date of files to list of fresh cache
                                    if ($max_size > 0) {
                                        $fresh[] = ['path' => $file, 'time' => $time, 'size' => $size];
                                    }
                                }
                            }
                        }
                    }
                #Catching Throwable, instead of \Error or \Exception, since we can't predict what exactly will happen here
                } catch (\Throwable) {
                    #Do nothing
                }
                #If we have size limitation and list of fresh items is not empty
                if ($max_size > 0 && !empty($fresh)) {
                    #Calclate total size
                    $total_size = \array_sum(\array_column($fresh,'size')) + $size_to_remove;
                    #Check if we are already removing enough. If so - skip further checks
                    if ($total_size - $size_to_remove >= $max_size) {
                        #Sort files by time from oldest to newest
                        \usort($fresh, static function ($a, $b) {
                            return $a['time'] <=> $b['time'];
                        });
                        #Iterrate list
                        foreach ($fresh as $file) {
                            $to_delete[] = $file['path'];
                            $size_to_remove += $file['size'];
                            #Check if removing this file will be enough and break cycle if it is
                            if ($total_size - $size_to_remove < $max_size) {
                                break;
                            }
                        }
                    }
                }
                foreach ($to_delete as $file) {
                    #Using catch to handle potential race condition, when file gets removed by a different process before the check gets called
                    try {
                        #Check if file and is old enough
                        if (\is_file($file)) {
                            #Remove the file
                            \unlink($file);
                            #Remove parent directory if empty
                            if (!(new \RecursiveDirectoryIterator(\dirname($file), \FilesystemIterator::SKIP_DOTS))->valid()) {
                                $empty_dirs[] = $file;
                            }
                        }
                    } catch (\Throwable) {
                        #Do nothing
                    }
                }
            }
            #Garbage collector for APCu if it's enabled.
            #While APCu is expected to remove old entries itself, it seems like its behavior is inconsistent somewhat. This allows enforcing garbage collection.
            if ($this->apcu) {
                $cache_info = \apcu_cache_info();
                if (\is_array($cache_info)) {
                    /** @noinspection OffsetOperationsInspection https://github.com/kalessil/phpinspectionsea/issues/1941 */
                    foreach ($cache_info['cache_list'] as $item) {
                        if ($item['mtime'] <= $oldest && str_starts_with($item['info'], 'SimbiatHTMLCache_')) {
                            \apcu_delete($item['info']);
                        }
                    }
                }
            }
        }
        #Garbage collector for empty directories
        foreach ($empty_dirs as $dir) {
            #Using catch to handle potential race condition, when directory gets removed by a different process before the check gets called
            try {
                @\rmdir($dir);
                #Remove parent directory if empty
                if (!new \RecursiveDirectoryIterator(\dirname($dir), \FilesystemIterator::SKIP_DOTS)->valid()) {
                    @\rmdir(\dirname($dir));
                }
            } catch (\Throwable) {
                #Do nothing
            }
        }
    }
}
