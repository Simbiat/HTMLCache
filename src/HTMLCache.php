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
    private bool $poolReady = false;
    private bool $zEcho = false;
    private int $maxRandom;

    public function __construct(string $filesPool = '', bool $apcu = false, int $maxRandom = 1)
    {
        #Sanitize random value
        if ($maxRandom < 0) {
            $maxRandom = 60;
        } else {
            $maxRandom = $maxRandom * 60;
        }
        $this->maxRandom = $maxRandom;
        #Get version of the scripts based on all files called so far
        $usedFiles = get_included_files();
        $this->version = count($usedFiles).'.'.getlastmod();
        #Check if APCU is available
        if ($apcu && extension_loaded('apcu') && ini_get('apc.enabled')) {
            $this->apcu = true;
        }
        #Check if file-based pool exists
        if (!empty($filesPool)) {
            if (is_dir($filesPool)) {
                $this->files = mb_rtrim(mb_rtrim($filesPool, '\\', 'UTF-8'), '/', 'UTF-8').'/';
            } else {
                #If it does not exist, attempt to create it
                if (@mkdir($filesPool, recursive: true)) {
                    $this->files = mb_rtrim(mb_rtrim($filesPool, '\\', 'UTF-8'), '/', 'UTF-8').'/';
                }
            }
        }
        #If either APCU or files pool is available - set the flag to true
        if ($this->files !== '' || $this->apcu === true) {
            $this->poolReady = true;
        }
        #Check if zEcho is available
        if (method_exists('\Simbiat\http20\Common', 'zEcho')) {
            $this->zEcho = true;
        }
    }

    #Function to store HTML page
    public function set(string $string, string $key ='', int $ttl = 60, int $grace = 1, bool $zip = true, bool $direct = true, string $cacheStrat = ''): bool
    {
        if ($this->poolReady) {
            #Sanitize integers
            if ($ttl < 1) {
                $ttl = 3600;
            } else {
                $ttl = $ttl * 60;
            }
            if ($grace < 1) {
                $grace = 60;
            } else {
                $grace = $grace * 60;
            }
            #Set key based on REQUEST_URI
            if (empty($key)) {
                $key = hash('sha3-256', (empty($_SERVER['REQUEST_URI']) ? 'index.php' : $_SERVER['REQUEST_URI']).http_build_query($_GET ?? []));
            } else {
                $key = hash('sha3-256', $key);
            }
            #GZip data
            if ($zip && extension_loaded('zlib')) {
                $body = gzcompress($string, 9, FORCE_GZIP);
                $headers = gzcompress(serialize(headers_list()), 9, FORCE_GZIP);
            } else {
                $body = $string;
                $headers = headers_list();
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
                'cacheStrat' => $cacheStrat,
                'uri' => $_SERVER['REQUEST_URI'],
                'data' => $data,
            ];
            #Hash the data
            $data['hash'] = hash('sha3-256', serialize($data));
            #Set timings
            $data['ttl'] = $ttl;
            $data['expires'] = time()+$ttl;
            $data['grace'] = $grace;
            #Write cache
            $result = $this->writeToCache($key, $data);
        } else {
            $result = false;
        }
        #Send header indicating that response was cached
        @header('X-Server-Cached: true');
        #Echo the data if we chose to do it
        if ($direct) {
            #Send header indicating that live data was sent
            @header('X-Server-Cache-Hit: false');
            if ($this->zEcho) {
                (new Common)->zEcho($string, $cacheStrat);
            } else {
                echo $string;
                exit(0);
            }
        } else {
            return $result;
        }
        return false;
    }

    #Function to get HTML page from cache
    public function get(string $key = '', bool $scriptVersion = true, bool $direct = true, bool $staleReturn = false): bool|array
    {
        if ($this->poolReady) {
            #Set key based on REQUEST_URI
            if (empty($key)) {
                $key = hash('sha3-256', (empty($_SERVER['REQUEST_URI']) ? 'index.php' : $_SERVER['REQUEST_URI']).http_build_query($_GET ?? []));
            } else {
                $key = hash('sha3-256', $key);
            }
            #Check APCU
            if ($this->apcu && apcu_exists('SimbiatHTMLCache_'.$key) === true) {
                #Get data from cache
                $data = apcu_fetch('SimbiatHTMLCache_'.$key, $result);
                #Check that data was retrieved. If not we will fall through to file.
                if ($result === false) {
                    $data = NULL;
                }
            }
            #Get final path based on hash
            $finalPath = $this->files.substr($key, 0, 2).'/'.substr($key, 2, 2).'/';
            #Check file
            if (empty($data) && $this->files !== '' && is_file($finalPath.$key) && is_readable($finalPath.$key)) {
                $data = unserialize(file_get_contents($finalPath.$key));
            }
            #Validate data
            if (empty($data)) {
                #Indicate, that there is no cached version of the data
                @header('X-Server-Cached: false');
                return false;
            } else {
                if ($this->cacheValidate($key, $data, $scriptVersion) === true) {
                    #Output data
                    if ($direct) {
                        $this->cacheOutput($data);
                    } else {
                        #Indicate, that there is a cached version of the data
                        @header('X-Server-Cached: true');
                        if ($staleReturn) {
                            $data['stale'] = false;
                        }
                        return $data;
                    }
                } else {
                    if (!$direct && $staleReturn) {
                        @header('X-Server-Cached: stale');
                        $data['stale'] = true;
                        return $data;
                    }
                }
            }
        }
        return false;
    }

    #Function to remove from cache
    public function delete(string $key = ''): bool
    {
        #Sanitize key
        if (empty($key)) {
            $key = hash('sha3-256', (empty($_SERVER['REQUEST_URI']) ? 'index.php' : $_SERVER['REQUEST_URI']).http_build_query($_GET ?? []));
        } else {
            $key = hash('sha3-256', $key);
        }
        #Remove from APCU
        if ($this->apcu && apcu_exists('SimbiatHTMLCache_'.$key) === true) {
            $result = apcu_delete('SimbiatHTMLCache_'.$key);
            if (!$result) {
                return false;
            }
        }
        #Get final path based on hash
        $finalPath = $this->files.substr($key, 0, 2).'/'.substr($key, 2, 2).'/';
        #Remove file
        if ($this->files !== '' && is_file($finalPath.$key)) {
            $result = unlink($finalPath.$key);
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
            $result = apcu_store('SimbiatHTMLCache_'.$key, $data, $data['ttl'] ?? 0);
            if (!$result) {
                return false;
            }
        }
        #Get final path based on hash
        $finalPath = $this->files.substr($key, 0, 2).'/'.substr($key, 2, 2).'/';
        #Cache data to file
        if ($this->files !== '') {
            #Create folder if missing
            if (!is_dir($finalPath)) {
                @mkdir($finalPath, recursive: true);
            }
            $result = boolval(file_put_contents($finalPath.$key, serialize($data), LOCK_EX));
            if (!$result) {
                return false;
            }
        }
        return true;
    }

    #Helper function to validate cache
    private function cacheValidate(string $key, array $data, bool $scriptVersion = true): bool
    {
        #Check key
        if ($key !== $data['key']) {
            return false;
        }
        #Check if stale. We use a random seed to allow earlier expiration, which can help with cache slamming
        if (empty($data['expires']) || $data['expires'] < time() - rand(0, $this->maxRandom)) {
            #Check grace period
            if (empty($data['grace'])) {
                return false;
            } else {
                #Prepare new set of data
                $newData = $data;
                $newData['expires'] = time()+$newData['grace'];
                $newData['grace'] = 0;
            }
        }
        #Check script version. This may help avoid situations, when you have updated PHP files, that are responsible for page generation, but there is also a cache version, which uses older revisions, that may provide inappropriate results
        if ($scriptVersion && $this->version !== $data['version']) {
            return false;
        }
        #Check hash to reduce chances of serving corrupted data
        $hash = $data['hash'];
        unset($data['ttl'], $data['expires'], $data['grace'], $data['hash'], $data['stale']);
        if ($hash !== hash('sha3-256', serialize($data))) {
            return false;
        } else {
            if (isset($newData)) {
                #Update expiration date in cache to prevent cache slamming
                $this->writeToCache($key, $newData);
                #Return false in order to get up-to-date data
                return false;
            } else {
                return true;
            }
        }
    }

    #Function to output cached data
    public function cacheOutput(array $data, bool $exit = true): void
    {
        #Unzip data
        if ($data['zip'] === true) {
            $data['data']['body'] = gzdecode($data['data']['body']);
            $data['data']['headers'] = unserialize(gzdecode($data['data']['headers']));
        }
        #Send headers
        array_map('header', $data['data']['headers']);
        #Send header indicating that cached response was sent
        @header('X-Server-Cached: true');
        @header('X-Server-Cache-Hit: true');
        if ($this->zEcho) {
            (new Common)->zEcho($data['data']['body'], (empty($data['cacheStrat']) ? '' : $data['cacheStrat']), exit: $exit);
        } else {
            #Close session right after if it opened
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            echo $data['data']['body'];
            if ($exit) {
                exit(0);
            } else {
                @ob_end_clean();
            }
        }
    }

    #Garbage collector
    public function gc(int $maxAge = 60, int $maxSize = 1024): void
    {
        #Sanitize values
        if ($maxAge < 0) {
            #Reset to default 1 hour cache
            $maxAge = 60 * 60;
        } else {
            #Otherwise, convert into minutes (seconds do not make sense here at all)
            $maxAge = $maxAge * 60;
        }
        if ($maxSize < 0) {
            #Consider that the size limit was removed
            $maxSize = 0;
        } else {
            #Otherwise, convert to megabytes (lower than 1 MB does not make sense)
            $maxSize = $maxSize * 1024 * 1024;
        }
        #Set list of empty folders (removing within iteration seems to cause fatal error)
        $emptyDirs = [];
        if ($maxAge > 0 || ($maxSize > 0 && $this->files !== '')) {
            #Get the oldest allowed time
            $oldest = time() - $maxAge;
            #Garbage collector for old files, if files pool is used
            if ($this->files !== '') {
                $sizeToRemove = 0;
                #Initiate iterator
                $fileSI = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->files, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
                #List of files to remove
                $toDelete = [];
                #List of fresh files with their sizes
                $fresh = [];
                #Iterate the files to get size and date first
                #Using catch to handle potential race condition, when file gets removed by a different process before the check gets called
                try {
                    foreach ($fileSI as $file) {
                        if (is_dir($file)) {
                            #Check if empty
                            if (!(new \RecursiveDirectoryIterator($file, \FilesystemIterator::SKIP_DOTS))->valid()) {
                                #Remove directory
                                $emptyDirs[] = $file;
                            }
                        } else {
                            #Check if file
                            if (is_file($file)) {
                                #If we have age restriction, check if the age
                                $time = filemtime($file);
                                if ($maxSize > 0) {
                                    $size = filesize($file);
                                } else {
                                    $size = 0;
                                }
                                if ($maxAge > 0 && $time <= $oldest) {
                                    #Add to list of files to delete
                                    $toDelete[] = $file;
                                    if ($maxSize > 0) {
                                        $sizeToRemove = $sizeToRemove + $size;
                                    }
                                } else {
                                    #Get date of files to list of fresh cache
                                    if ($maxSize > 0) {
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
                if ($maxSize > 0 && !empty($fresh)) {
                    #Calclate total size
                    $totalSize = array_sum(array_column($fresh,'size')) + $sizeToRemove;
                    #Check if we are already removing enough. If so - skip further checks
                    if ($totalSize - $sizeToRemove >= $maxSize) {
                        #Sort files by time from oldest to newest
                        usort($fresh, function ($a, $b) {
                            return $a['time'] <=> $b['time'];
                        });
                        #Iterrate list
                        foreach ($fresh as $file) {
                            $toDelete[] = $file['path'];
                            $sizeToRemove = $sizeToRemove + $file['size'];
                            #Check if removing this file will be enough and break cycle if it is
                            if ($totalSize - $sizeToRemove < $maxSize) {
                                break;
                            }
                        }
                    }
                }
                foreach ($toDelete as $file) {
                    #Using catch to handle potential race condition, when file gets removed by a different process before the check gets called
                    try {
                        #Check if file and is old enough
                        if (is_file($file)) {
                            #Remove the file
                            unlink($file);
                            #Remove parent directory if empty
                            if (!(new \RecursiveDirectoryIterator(dirname($file), \FilesystemIterator::SKIP_DOTS))->valid()) {
                                $emptyDirs[] = $file;
                            }
                        }
                    #Catching Throwable, instead of \Error or \Exception, since we can't predict what exactly will happen here
                    } catch (\Throwable) {
                        #Do nothing
                    }
                }
            }
            #Garbage collector for APCu if it's enabled.
            #While APCu is expected to remove old entries itself, it seems like its behavior is inconsistent somewhat. This allows to enforce garbage collection.
            if ($this->apcu) {
                foreach (apcu_cache_info()['cache_list'] as $item) {
                    if ($item['mtime'] <= $oldest && str_starts_with($item['info'], 'SimbiatHTMLCache_')) {
                        apcu_delete($item['info']);
                    }
                }
            }
        }
        #Garbage collector for empty directories
        foreach ($emptyDirs as $dir) {
            #Using catch to handle potential race condition, when directory gets removed by a different process before the check gets called
            try {
                @rmdir($dir);
                #Remove parent directory if empty
                if (!(new \RecursiveDirectoryIterator(dirname($dir), \FilesystemIterator::SKIP_DOTS))->valid()) {
                    @rmdir(dirname($dir));
                }
            } catch (\Throwable) {
                #Do nothing
            }
        }
    }
}
