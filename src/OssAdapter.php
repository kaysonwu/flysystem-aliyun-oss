<?php

namespace Kaysonwu\Flysystem\Aliyun;

use DateTimeInterface;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use OSS\Core\OssException;
use OSS\OssClient;

/**
 * @mixin OssClient
 */
class OssAdapter extends AbstractAdapter
{
    /**
     * @const VISIBILITY_DEFAULT
     */
    const VISIBILITY_DEFAULT = 'default';

    /**
     * @const VISIBILITY_PUBLIC_READ_WRITE public read/write visibility
     */
    const VISIBILITY_PUBLIC_READ_WRITE = OssClient::OSS_ACL_TYPE_PUBLIC_READ_WRITE;

    /**
     * The Oss client instance.
     *
     * @var \OSS\OssClient
     */
    protected $client;

    /**
     * The bucket name for the oss.
     *
     * @var string
     */
    protected $bucket;

    /**
     * The OSS related options.
     *
     * Example: x-oss-server-side-encryption | x-oss-*
     *
     * @var array
     */
    protected $options;

    /**
     * The external domain name for OSS bucket.
     *
     * @var string
     */
    protected $domain;

    /**
     * Create a new AliOSS adapter instance.
     *
     * @param  OssClient $client
     * @param  string $bucket
     * @param  string $domain
     * @param  string $prefix
     * @param  array $options
     * @return void
     */
    public function __construct(OssClient $client, $bucket, $domain = null, $prefix = null, array $options = [])
    {
        $this->client = $client;
        $this->options = $options;
        $this->setBucket($bucket)->setDomain($domain)->setPathPrefix($prefix);
    }

    /**
     * Get the current bucket name for the oss.
     *
     * @return string
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * Set the bucket associated with the oss.
     *
     * @param  string $bucket
     * @return $this
     */
    public function setBucket($bucket)
    {
        $this->bucket = $bucket;

        return $this;
    }

    /**
     * Get a oss client instance.
     *
     * @return \OSS\OssClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        $object = $this->applyPathPrefix($path);

        return $this->client->doesObjectExist($this->bucket, $object);
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        $object = $this->applyPathPrefix($path);

        $options = $this->getOptions($config);

        if (($size = $config->get(OssClient::OSS_LENGTH)) === null) {
            $options[OssClient::OSS_LENGTH] = $size = strlen($contents);
        }

        try {
            $this->client->putObject($this->bucket, $object, $contents, $options);
        } catch (OssException $e) {
            return false;
        }

        return ['path' => $path, 'size' => $size, 'type' => 'file'];
    }

    /**
     * Get options for OSS.
     *
     * @param  \League\Flysystem\Config $config
     * @return array
     */
    protected function getOptions(Config $config = null)
    {
        $headers = $this->options;

        if ($config !== null) {

            $headers = array_merge($headers, $config->get(OssClient::OSS_HEADERS, []));

            if ($visibility = $config->get('visibility')) {
                $headers[OssClient::OSS_OBJECT_ACL] = static::visibilityToAcl($visibility);
            }
        }

        if (!empty($headers)) {
            return [OssClient::OSS_HEADERS => $headers];
        }

        return [];
    }

    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->write($path, stream_get_contents($resource), $config);
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        $object = $this->applyPathPrefix($path);

        try {
            $content = $this->client->getObject($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }

        return [
            'contents' => $content,
            'path' => $path,
            'type' => 'file'
        ];
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $object = $this->applyPathPrefix($path);

        try {
            $content = $this->client->getObject($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }

        if (($stream = $this->createStreamFromString($content)) === false) {
            return false;
        }

        return [
            'stream' => $stream,
            'path' => $path,
            'type' => 'file'
        ];
    }

    /**
     * Create a temporary file stream from a string.
     *
     * @param  string $string
     * @return resource|false
     */
    protected function createStreamFromString($string)
    {
        $stream = tmpfile();

        if (fwrite($stream, $string) === false) {
            fclose($stream);
            return false;
        }

        rewind($stream);

        return $stream;
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        if ( ! $this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * Copy a file.
     *
     * @param  string $path
     * @param  string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $object = $this->applyPathPrefix($path);
        $newObject = $this->applyPathPrefix($newpath);

        try {
            $this->client->copyObject($this->bucket, $object, $this->bucket, $newObject, $this->getOptions());
        } catch (OssException $e) {
            return false;
        }

        return true;
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $object = $this->applyPathPrefix($path);

        $this->client->deleteObject($this->bucket, $object);

        return true;
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $dirname = $this->applyPathPrefix($dirname) . '/';

        return $this->getObjects($dirname, true, function ($objects) {

            if (!empty($objects)) {

                $keys = [];

                /**@var \OSS\Model\ObjectInfo[] $objects */
                foreach ($objects as $object) {
                    $keys[] = $object->getKey();
                }

                $this->client->deleteObjects($this->bucket, $keys);
            }
        });
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        $object = $this->applyPathPrefix($dirname);
        $options = $this->getOptions($config);

        try {
            $this->client->createObjectDir($this->bucket, $object, $options);
        } catch (OssException $e) {
            return false;
        }

        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        $object = $this->applyPathPrefix($path);

        try {
            $response = $this->client->getObjectMeta($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }

        return [
            'path' => $path,
            'size' => $response['content-length'],
            'timestamp' => strtotime($response['last-modified']),
            'type' => (substr($path, -1) === '/' ? 'dir' : 'file'),
            'mimetype' => $response['content-type']
        ];
    }

    /**
     * Get the size of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the last modified time of a file as a timestamp.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getVisibility($path)
    {
        $object = $this->applyPathPrefix($path);

        try {
            $acl = $this->client->getObjectAcl($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }

        return [
            'path' => $path,
            'visibility' => static::aclToVisibility($acl)
        ];
    }

    /**
     * Convert ACL type to ACL visibility.
     *
     * @param  string $acl
     * @return string
     */
    protected static function aclToVisibility($acl)
    {
        if ($acl === OssClient::OSS_ACL_TYPE_PUBLIC_READ) {
            return static::VISIBILITY_PUBLIC;
        }

        return $acl;
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        $object = $this->applyPathPrefix($path);
        $acl = static::visibilityToAcl($visibility);

        try {
            $this->client->putObjectAcl($this->bucket, $object, $acl);
        } catch (OssException $e) {
            return false;
        }

        return ['path' => $path, 'visibility' => $visibility];
    }

    /**
     * Convert visibility type to ACL type.
     *
     * @param  string $visibility
     * @return string
     */
    protected static function visibilityToAcl($visibility)
    {
        if ($visibility === static::VISIBILITY_PUBLIC) {
            return OssClient::OSS_ACL_TYPE_PUBLIC_READ;
        }

        return $visibility;
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $dirname = $this->applyPathPrefix($directory) . '/';

        $contents = [];

        $this->getObjects($dirname, $recursive, function ($objects, $directories, $dirname) use (&$contents) {

            /**@var \OSS\Model\PrefixInfo[] $directories */
            foreach ($directories as $directory) {
                $contents[] = [
                    'path' => $directory->getPrefix(),
                    'type' => 'dir'
                ];
            }

            /**@var \OSS\Model\ObjectInfo[] $objects */
            foreach ($objects as $object) {
                if (($path = $object->getKey()) !== $dirname) {
                    $contents[] = [
                        'path' => $path,
                        'size' => $object->getSize(),
                        'type' => 'file',
                        'timestamp' => strtotime($object->getLastModified())
                    ];
                }
            }

        });

        return $contents;
    }

    /**
     * List objects of a directory.
     *
     * @param  string $dirname
     * @param  bool $recursive
     * @param  callable $handle
     * @return bool
     */
    protected function getObjects($dirname, $recursive, $handle)
    {
        $options = [
            'delimiter' =>'/',
            'prefix' => $dirname,
            'max-keys' => 1000,
            'marker' => ''
        ];

        do {

            try {
                $info = $this->listObjects($this->bucket, $options);
            } catch (OssException $e) {
                return false;
            }

            $directories = $info->getPrefixList();

            if ($recursive) {
                foreach ($directories as $directory) {
                    $this->getObjects($directory->getPrefix(), true, $handle);
                }
            }

            $handle($info->getObjectList(), $directories, $dirname);

        } while(($options['marker'] = $info->getNextMarker()) !== '');

        return true;
    }

    /**
     * Set the external domain name for OSS bucket.
     *
     * @param  string $domain
     * @return $this
     */
    public function setDomain($domain)
    {
        $this->domain = rtrim($domain, '/') . '/';

        return $this;
    }

    /**
     * Get the URL for the file at the given path.
     *
     * @param  string $path
     * @return string
     */
    public function getUrl($path)
    {
        return $this->domain . $this->applyPathPrefix($path);
    }

    /**
     * Get a temporary URL for the file at the given path.
     *
     * @param  string $path
     * @param  \DateTimeInterface|int $expiration
     * @param  array $options
     * @return string|false
     */
    public function temporaryUrl($path, $expiration, array $options = [])
    {
        $object = $this->applyPathPrefix($path);
        $timeout = $this->normalizeTimeout($expiration);

        try {
            $url = $this->client->signUrl($this->bucket, $object, $timeout, OssClient::OSS_HTTP_GET, $options);
        } catch (OssException $e) {
            return false;
        }

        return $url;
    }

    /**
     * Normalize a timeout from expiration.
     *
     * @param  \DateTimeInterface|int $expiration
     * @return int
     */
    protected function normalizeTimeout($expiration)
    {
        if ($expiration instanceof DateTimeInterface) {
            return $expiration->getTimestamp() - time();
        }

        return $expiration;
    }

    /**
     * Dynamically pass methods to the oss client.
     *
     * @param  string $name
     * @param  array $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->client, $name], $arguments);
    }
}
