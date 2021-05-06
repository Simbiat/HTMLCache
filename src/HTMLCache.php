<?php
declare(strict_types=1);
namespace Simbiat;

class HTMLCache
{
    #Settings initialized on construction
    private string $version = '';
    private bool $apcu = false;
    private string $files = '';
    private bool $poolReady = false;
    private bool $zEcho = false;
    private int $maxRandom = 60;
    
    public function __construct(string $filesPool = '', int $maxRandom = 60)
    {
        #Sanitize random value
        if ($maxRandom < 0) {
            $maxRandom = 60;
        }
        $this->maxRandom = $maxRandom;
        #Get version of the scripts based on all files called so far
        $usedFiles = get_included_files();
        $this->version = count($usedFiles).'.'.max(max(array_map('filemtime', array_filter($usedFiles, 'is_file'))), getlastmod());
        #Check if APCU is available
        if (extension_loaded('apcu') && ini_get('apc.enabled')) {
            $this->apcu = true;
        }
        #Check if filebased pool exists
        if (!empty($filesPool)) {
            if (is_dir($filesPool)) {
                $this->files = rtrim(rtrim($filesPool, '\\'), '/').'/';
            } else {
                #If it does not exist, attempt to create it
                if (mkdir($filesPool, recursive: true)) {
                    $this->files = rtrim(rtrim($filesPool, '\\'), '/').'/';
                }
            }
        }
        #If either APCU or files pooling are available - set the flag to true
        if ($this->files !== '' || $this->apcu === true) {
            $this->poolReady = true;
        }
        #Check if zEcho is available
        if (method_exists('\Simbiat\http20\Common', 'zEcho')) {
            $this->zEcho = true;
        }
    }
    
    #Function to store HTML page
    public function set(string $string, string $key ='', int $ttl = 600, int $grace = 600, bool $zip = true, bool $direct = true, string $cacheStrat = ''): bool
    {
        if ($this->poolReady) {
            #Sanitize integers
            if ($ttl < 1) {
                $ttl = 600;
            }
            if ($grace < 1) {
                $grace = 600;
            }
            #Set key based on REQUEST_URI
            if (empty($key)) {
                $key = hash('sha3-256', (empty($_SERVER['REQUEST_URI']) ? 'index.php' : $_SERVER['REQUEST_URI']));
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
            $data['expires'] = time()+$ttl;
            $data['grace'] = $grace;
            #Write cache
            $result = $this->writeToCache($key, $data);
        } else {
            $result = false;
        }
        #Echo the data if we chosed to do it
        if ($direct) {
            #Send header indicating that response was cached, but live data was sent
            header('X-Server-Cached: true');
            header('X-Server-Cache-Hit: false');
            if ($this->zEcho) {
                (new \Simbiat\http20\Common)->zEcho($string, $cacheStrat);
            } else {
                echo $string;
                exit;
            }
        } else {
            header('X-Server-Cached: true');
            return $result;
        }
    }
    
    #Function to get HTML page from cache
    public function get(string $key = '', bool $scriptVersion = true, bool $direct = true): bool|array
    {
        if ($this->poolReady) {
            #Set key based on REQUEST_URI
            if (empty($key)) {
                $key = hash('sha3-256', (empty($_SERVER['REQUEST_URI']) ? 'index.php' : $_SERVER['REQUEST_URI']));
            } else {
                $key = hash('sha3-256', $key);
            }
            #Check APCU
            if ($this->apcu && apcu_exists($key) === true) {
                #Get data from cache
                $data = apcu_fetch($key, $result);
                #Check that data was retrieved. If not we will fall through to file.
                if ($result === false) {
                    $data = NULL;
                }
            }
            #Check file
            if (empty($data) && $this->files !== '' && is_file($this->files.$key) && is_readable($this->files.$key)) {
                $data = unserialize(file_get_contents($this->files.$key));
            }
            #Validate data
            if (empty($data)) {
                #Indicate, that there is no cached version of the data
                header('X-Server-Cached: false');
                return false;
            } else {
                if ($this->cacheValidate($key, $data, $scriptVersion) === true) {
                    #Output data
                    if ($direct) {
                        $this->cacheOutput($data);
                    } else {
                        #Indicate, that there is a cached version of the data
                        header('X-Server-Cached: true');
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
            $key = hash('sha3-256', (empty($_SERVER['REQUEST_URI']) ? 'index.php' : $_SERVER['REQUEST_URI']));
        } else {
            $key = hash('sha3-256', $key);
        }
        #Remove from APCU
        if ($this->apcu && apcu_exists($key) === true) {
            $result = apcu_delete($key);
            if (!$result) {
                return false;
            }
        }
        #Remove file
        if ($this->files !== '' && is_file($this->files.$key)) {
            $result = unlink($this->files.$key);
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
            $result = apcu_store($key, $data);
            if (!$result) {
                return false;
            }
        }
        #Cache data to file
        if ($this->files !== '') {
            $result = boolval(file_put_contents($this->files.$key, serialize($data), LOCK_EX));
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
                $newdata = $data;
                $newdata['expires'] = time()+$newdata['grace'];
                $newdata['grace'] = 0;
            }
        }
        #Check script version. This may help avoid situations, when you have updated PHP files, that are responsible for page generation, but there is also a cache version, which uses older revisions, that may provide inappropriate results
        if ($scriptVersion && $this->version !== $data['version']) {
            return false;
        }
        #Check hash to reduce chances of serving corrupted data
        $hash = $data['hash'];
        unset($data['expires'], $data['grace'], $data['hash']);
        if ($hash !== hash('sha3-256', serialize($data))) {
            return false;
        } else {
            if (isset($newdata)) {
                #Update expiration date in cache to prevent cache slamming
                $this->writeToCache($key, $newdata);
                #Return false in order to get up-to-date data
                return false;
            } else {
                return true;
            }
        }
    }
    
    #Function to output cached data
    public function cacheOutput(array $data): void
    {
        #Unzip data
        if ($data['zip'] === true) {
            $data['data']['body'] = gzdecode($data['data']['body']);
            $data['data']['headers'] = unserialize(gzdecode($data['data']['headers']));
        }
        #Send headers
        array_map('header', $data['data']['headers']);
        #Send header indicating that cached response was sent
        header('X-Server-Cached: true');
        header('X-Server-Cache-Hit: true');
        if ($this->zEcho) {
            (new \Simbiat\http20\Common)->zEcho($data['data']['body'], (empty($data['cacheStrat']) ? '' : $data['cacheStrat']));
        } else {
            echo $string;
            exit;
        }
    }
}
?>