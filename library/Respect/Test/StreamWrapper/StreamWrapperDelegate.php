<?php
namespace Respect\Test\StreamWrapper;

use Respect\Test\StreamWrapper\StreamEntity\DirectoryStreamEntity;
use Respect\Test\StreamWrapper\StreamEntity\FileStreamEntity;

class StreamWrapperDelegate implements StreamWrapperInterface
{

    private $resource = null,
            $stream_entity = null,
            $stream_overrides = array(),
            $registered_cLass;
    private static  $quiet = false,
                    $error_handler = null;


    public function __construct($overrides, $class)
    {
        $this->stream_overrides = $overrides;
        $this->registered_cLass = $class;
        $this->register();
        if (is_null(static::$error_handler))
            if (print __CLASS__. ', errorHandler')
            static::$error_handler = set_error_handler(array(__CLASS__, 'errorHandler'));
        clearstatcache(true);
    }

    public function __destruct()
    {
        $this->restore();
        if (static::$error_handler)
            set_error_handler(static::$error_handler);
        static::$error_handler = null;
        foreach ($this->stream_overrides as $e)
            if ($e->isOpen())
                $e->closeResource();
    }

    public static function errorHandler()
    {
        if (STREAM_URL_STAT_QUIET !== static::$quiet)
            return false;
        static::$quiet = false;
        return true;
    }

    private function register($return = true)
    {
        if (in_array("file", stream_get_wrappers()))
            stream_wrapper_unregister('file');
        stream_wrapper_register('file', $this->registered_cLass);
        return $return;
    }

    private function restore()
    {
        stream_wrapper_restore('file');
    }

    private function fullyQualified(&$path)
    {
        if (false !== $rp = realpath($path))
            return $path = $rp;
        if (false !== strpos($path, '~') && isset($_SERVER['HOME']))
            $path = str_replace('~', $_SERVER['HOME'], $path);
        if ($path = rtrim($path, DIRECTORY_SEPARATOR))
            if (false === strpos($path, getcwd()))
                $path = getcwd().DIRECTORY_SEPARATOR.$path;
        return $path;
    }

    private function isOverride(&$path = false)
    {
        if (!$path && is_object($this->stream_entity)) {
            $path = $this->stream_entity->getPath();
            return array_key_exists(
                $this->fullyQualified($path),
                $this->stream_overrides
            );
        }
        return false;
    }

    private function getResource(&$path)
    {
        if ($this->isOverride($path)) {
            $this->stream_entity = $this->stream_overrides[$path];
            return $this->resource = $this->stream_entity->getResource();
        }
        $this->resource = $this->stream_entity = null;
        return false;
    }

    private function openNewFile($path, $mode, $options, &$opened_path) {
        $e = new FileStreamEntity();
        $e->setPath($path);
        $e->setData('');
        $e->openResource();
        $this->stream_overrides[$path = $opened_path = $e->getPath()] = $e;
        $this->stepBackRebuildDirectories($path);
        $this->stream_entity = $e;
        return $this->resource = $e->getResource();
    }

    private function stepBackRebuildDirectories($path) {
        do
            $last_chunk = strrchr($path, DIRECTORY_SEPARATOR);
        while ($path && !is_dir($path = preg_replace("#$last_chunk$#", '', $path)));
        unlink($path);
        mkdir($path);
    }

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        if (!file_exists($path))
            return $this->openNewFile($path, $mode, $options, $opened_path);

        if (false !== ($res = $this->getResource($path))) {
            $this->stream_seek(0);
            return $res;
        }
        $this->restore();
        $this->resource = fopen($opened_path = $path, $mode, $options) ?: null;
        return $this->register($this->resource ?: false);
    }

    public function stream_close()
    {
        $return = !$this->isOverride() ? fclose($this->resource) : true;
        return $this->resource = $this->stream_entity = null ?: $return;
    }

    public function stream_flush()
    {
        return fflush($this->resource);
    }

    public function stream_stat()
    {
        return $this->statOut(fstat($this->resource));
    }

    public function stream_read($length)
    {
        return fread($this->resource, $length);
    }

    public function stream_eof()
    {
        return feof($this->resource);
    }

    public function statOut($stat)
    {
        if ($this->isOverride($path) && $this->stream_entity)
            return $this->stream_entity->getStat($stat);
        return $stat;
    }

    public function url_stat($path, $flags)
    {
        if ($this->getResource($path))
            return $this->stream_stat();
        $this->restore();
        if (STREAM_URL_STAT_LINK == $flags)
            return $this->register(lstat($path));
        static::$quiet = $flags;
        return $this->register(stat($path));
    }

    public function dir_closedir()
    {
        if (!$this->isOverride() && is_resource($this->resource))
            closedir($this->resource);
        return $this->resource = $this->stream_entity = null ?: true;
    }

    public function dir_opendir($path, $options)
    {
        if (!is_null($this->resource))
            $this->dir_closedir();
        if (false !== $res = $this->getResource($path)) {
            $this->dir_rewinddir();
            return $res;
        }
        $this->restore();
        $this->resource = opendir($path) ?: null;
        return $this->register($this->resource);
    }

    public function dir_readdir()
    {
        if ($this->isOverride())
            if (is_resource($this->resource))
                return rtrim(fgets($this->resource, 1024)) ?: false;
            else
                return false;
        return is_resource($this->resource)
            ? readdir($this->resource)
            : $this->resource;
    }

    public function dir_rewinddir()
    {
        if ($this->isOverride())
            $this->stream_seek(0);
        elseif (is_resource($this->resource))
            rewinddir($this->resource);
    }

    private function getDirList($path)
    {
        $dlist = array('.'=>'.', '..'=>'..');
        if (file_exists($path)) {
            $res = opendir($path);
            while(false !== $ls = readdir($res))
                $dlist[$ls] = $ls;
            closedir($res);
        }
        return $dlist;
    }

    private function getOverridesInDir($path)
    {
        $dlist = array();
        foreach(array_keys($this->stream_overrides) as $v)
            if ($v != $t = str_replace($path.DIRECTORY_SEPARATOR, '', $v))
                $dlist[$v = str_replace(strchr($t, DIRECTORY_SEPARATOR), '', $t)] = $v;
        return $dlist;
    }

    private function dirsToStr($real, $virt)
    {
        return implode(PHP_EOL, array_keys($real+$virt));
    }

    public function mkdir($path, $mode, $options)
    {
        if ($this->isOverride($path))
            return false;
        $dlist = $this->getDirList($path);
        $overrides = $this->getOverridesInDir($path);
        $dlist = $this->dirsToStr($dlist, $overrides);
        $dir_entity = new DirectoryStreamEntity();
        $dir_entity->setPath($path);
        $dir_entity->setData($dlist);
        $dir_entity->openResource();
        $this->stream_overrides[$dir_entity->getPath()] = $dir_entity;
        foreach ($overrides as $v)
            if (false === strpos($v, '.'))
                mkdir($path.DIRECTORY_SEPARATOR.$v);
        return true;
    }

    public function rename($path_from, $path_to)
    {
        if ($this->isOverride($path_from)) {
            $this->stream_overrides[$path_to] = $this->stream_overrides[$path_from];
            $this->stream_overrides[$path_to]->setPath($path_to);
            unset($this->stream_overrides[$path_from]);
            return true;
        }
        $this->restore();
        return $this->register(rename($path_from, $path_to));
    }

    public function rmdir($path, $options)
    {
        if ($this->isOverride($path)) {
            unset($this->stream_overrides[$path]);
            return true;
        }
        $this->restore();
        return $this->register(rmdir($path, $options));
    }

    public function stream_cast($cast_as)
    {
        return $this->resource;
    }

    public function stream_lock($operation)
    {
        return stream_set_blocking($this->resource, $operation);
    }

    public function stream_seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->resource, $offset, $whence);
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        return true;
    }

    public function stream_tell()
    {
        return ftell($this->resource);
    }

    public function stream_write($data)
    {
        return fwrite($this->resource, $data);
    }

    public function unlink($path)
    {
        if ($this->isOverride($path)) {
            $this->stream_overrides[$path]->closeResource();
            $this->resource = $this->stream_entity = null;
            unset($this->stream_overrides[$path]);
            clearstatcache(true, $path);
            return true;
        }
        $this->restore();
        return $this->register(unlink($path));
    }
}