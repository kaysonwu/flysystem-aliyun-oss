<?php

namespace League\Flysystem\Aliyun;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use OSS\Core\OssException;
use OSS\OssClient;

/**
 * @method array putObjectAcl(string $bucket, string $object, string $acl)
 * @method array copyObject(string $fromBucket, string $fromObject, string $toBucket, string $toObject, array $options = NULL)
 * @method string|false getObjectAcl(string $bucket, string $object)
 * @method \OSS\Model\ObjectListInfo|false listObjects($bucket, $options = NULL)
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
     * Create a new AliOSS adapter instance.
     *
     * @param  OssClient $client
     * @param  string $bucket
     * @param  string $prefix
     * @param  array $options
     * @return void
     */
    public function __construct(OssClient $client, $bucket, $prefix = null, array $options = [])
    {
        $this->client = $client;
        $this->options = $options;
        $this->setBucket($bucket)->setPathPrefix($prefix);
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

        $response = $this->putObject($this->bucket, $object, $contents, $options);

        if ($response !== false) {
            return ['path' => $path, 'size' => $size, 'type' => 'file'];
        }

        return false;
    }

    /**
     * Get options for OSS.
     *
     * @param  \League\Flysystem\Config $config
     * @return array
     */
    protected function getOptions(Config $config)
    {
        $headers = array_merge($this->options, $config->get(OssClient::OSS_HEADERS, []));

        if ($visibility = $config->get('visibility')) {
            $headers[OssClient::OSS_OBJECT_ACL] = static::visibilityToAcl($visibility);
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

        /**@var string|false $response */
        $response = $this->getObject($this->bucket, $object);

        if ($response !== false) {
            return [
                'contents' => $response,
                'path' => $path,
                'type' => 'file'
            ];
        }

        return $response;
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

        /**@var string|false $response */
        $response = $this->getObject($this->bucket, $object);

        if ($response !== false) {
            return [
                'stream' => $this->createStreamFromString($response),
                'path' => $path,
                'type' => 'file'
            ];
        }

        return $response;
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

        return (bool) $this->copyObject($this->bucket, $object, $this->bucket, $newObject);
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

        $response = $this->createObjectDir($this->bucket, $object, $options);

        if ($response !== false) {
            return ['path' => $dirname, 'type' => 'dir'];
        }

        return $response;
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

        $response = $this->getObjectMeta($this->bucket, $object);

        if ($response !== false) {

            $isDir = substr($path, -1) === '/';
            $results = [
                'path' => $path,
                'type' => ($isDir ? 'dir' : 'file'),
                'mimetype' => $response['content-type']
            ];

            if ( ! $isDir) {
                $results['size'] = $response['content-length'];
                $results['timestamp'] = strtotime($response['last-modified']);
            }

            return $results;
        }

        return $response;
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

        $response = $this->getObjectAcl($this->bucket, $object);

        if ($response !== false) {
            return [
                'path' => $path,
                'visibility' => $this->aclToVisibility($response)
            ];
        }

        return $response;
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

        $acl = $this->visibilityToAcl($visibility);

        $response = $this->putObjectAcl($this->bucket, $object, $acl);

        if ($response !== false) {
            return ['path' => $path, 'visibility' => $visibility];
        }

        return $response;
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
            $info = $this->listObjects($this->bucket, $options);

            if ($info === false) {
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
     * Dynamically pass methods to the oss client.
     *
     * @param  string $name
     * @param  array $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        try {
            return call_user_func_array([$this->client, $name], $arguments);
        } catch (OssException $e) {
            return false;
        }
    }
}
