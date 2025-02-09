<?php

declare(strict_types=1);

namespace Zing\Flysystem\Obs;

use GuzzleHttp\Psr7\Uri;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use Obs\ObsClient;
use Obs\ObsException;

class ObsAdapter extends AbstractAdapter
{
    public const PUBLIC_GRANT_URI = 'http://acs.amazonaws.com/groups/global/AllUsers';

    /**
     * @var array
     */
    protected static $metaOptions = ['ACL', 'Expires', 'StorageClass'];

    /**
     * @var string
     */
    protected $endpoint;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var \Obs\ObsClient
     */
    protected $client;

    /**
     * @param \Obs\ObsClient $client
     * @param string $endpoint
     * @param string $bucket
     * @param string $prefix
     * @param array $options
     */
    public function __construct(ObsClient $client, string $endpoint, string $bucket, $prefix = '', array $options = [])
    {
        $this->client = $client;
        $this->endpoint = $endpoint;
        $this->bucket = $bucket;
        $this->options = $options;
        $this->setPathPrefix($prefix);
    }

    /**
     * Get the S3Client bucket.
     *
     * @return string
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * Set the S3Client bucket.
     *
     * @param mixed $bucket
     */
    public function setBucket($bucket): void
    {
        $this->bucket = $bucket;
    }

    /**
     * Get the S3Client instance.
     *
     * @return \Obs\ObsClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * write a file.
     *
     * @param string $path
     * @param string $contents
     *
     * @return array|false
     */
    public function write($path, $contents, Config $config)
    {
        $path = $this->applyPathPrefix($path);

        $options = $this->getOptionsFromConfig($config);

        try {
            $this->client->putObject(array_merge($options, [
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Body' => $contents,
            ]));
        } catch (ObsException $obsException) {
            return false;
        }

        return true;
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $resource
     *
     * @return array|false
     */
    public function writeStream($path, $resource, Config $config)
    {
        $contents = stream_get_contents($resource);

        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     *
     * @return array|false
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string $path
     * @param resource $resource
     *
     * @return array|false
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @throws \Obs\ObsException
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        if (! $this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        try {
            $this->client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $newpath,
                'CopySource' => $this->bucket . '/' . $path,
                'MetadataDirective' => ObsClient::CopyMetadata,
            ]);
        } catch (ObsException $exception) {
            return false;
        }

        return true;
    }

    /**
     * delete a file.
     *
     * @param string $path
     *
     * @throws \Obs\ObsException
     *
     * @return bool
     */
    public function delete($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);
        } catch (ObsException $obsException) {
            return false;
        }

        return ! $this->has($path);
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @throws \Obs\ObsException
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $files = $this->listContents($dirname, true);
        foreach ($files as $file) {
            $this->delete($file['path']);
        }

        return ! $this->has($dirname);
    }

    /**
     * create a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function createDir($dirname, Config $config)
    {
        $defaultFile = trim($dirname, '/') . '/';

        return $this->write($defaultFile, null, $config);
    }

    /**
     * visibility.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false
     */
    public function setVisibility($path, $visibility)
    {
        $acl = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? ObsClient::AclPublicRead : ObsClient::AclPrivate;

        try {
            $this->client->setObjectAcl([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
                'ACL' => $acl,
            ]);
        } catch (ObsException $exception) {
            return false;
        }

        return [
            'visibility' => $visibility,
            'path' => $path,
        ];
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
        try {
            $visibility = $this->getRawVisibility($path);
        } catch (ObsException $obsException) {
            return false;
        }

        return [
            'visibility' => $visibility,
        ];
    }

    /**
     * Get the object acl presented as a visibility.
     *
     * @param string $path
     *
     * @return string
     */
    protected function getRawVisibility($path)
    {
        $model = $this->client->getObjectAcl(
            [
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]
        );

        foreach ($model['Grants'] as $grant) {
            if (! isset($grant['Grantee']['URI'])) {
                continue;
            }
            if ($grant['Grantee']['URI'] !== self::PUBLIC_GRANT_URI) {
                continue;
            }
            if ($grant['Permission'] !== 'READ') {
                continue;
            }

            return AdapterInterface::VISIBILITY_PUBLIC;
        }

        return AdapterInterface::VISIBILITY_PRIVATE;
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
        return $this->getMetadata($path);
    }

    /**
     * read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        try {
            $contents = $this->getObject($path)
                ->getContents();
        } catch (ObsException $exception) {
            return false;
        }

        return [
            'contents' => $contents,
            'path' => $path,
        ];
    }

    /**
     * read a file stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        try {
            $stream = $this->getObject($path)
                ->detach();
        } catch (ObsException $exception) {
            return false;
        }

        return [
            'stream' => $stream,
            'path' => $path,
        ];
    }

    /**
     * Lists all files in the directory.
     *
     * @param string $directory
     * @param bool $recursive
     *
     * @throws \Obs\ObsException
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $list = [];
        $directory = substr($directory, -1) === '/' ? $directory : $directory . '/';
        $result = $this->listDirObjects($directory, $recursive);

        foreach ($result['objects'] as $files) {
            $metadata = $this->getMetadata($files['Key']);
            if ($metadata === false) {
                continue;
            }
            $list[] = $metadata;
        }

        foreach ($result['prefix'] as $dir) {
            $list[] = [
                'type' => 'dir',
                'path' => $dir,
            ];
        }

        return $list;
    }

    /**
     * get meta data.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $metadata = $this->client->getObjectMetadata([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);
        } catch (ObsException $exception) {
            return false;
        }
        if ($this->isOnlyDir($this->removePathPrefix($path))) {
            return [
                'type' => 'dir',
                'path' => rtrim($this->removePathPrefix($path), '/'),
            ];
        }

        return [
            'type' => 'file',
            'mimetype' => $metadata['ContentType'],
            'path' => $this->removePathPrefix($path),
            'timestamp' => strtotime($metadata['LastModified']),
            'size' => $metadata['ContentLength'],
        ];
    }

    /**
     * get the size of file.
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
     * get mime type.
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
     * get timestamp.
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
     * Check if the path contains only directories.
     *
     * @param string $path
     *
     * @return bool
     */
    private function isOnlyDir($path)
    {
        return substr($path, -1) === '/';
    }

    /**
     * Get resource url.
     *
     * @param string $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        $path = $this->applyPathPrefix($path);

        if (isset($this->options['url'])) {
            return $this->concatPathToUrl($this->options['url'], $path);
        }

        return $this->normalizeHost() . ltrim($path, '/');
    }

    protected function concatPathToUrl($url, $path)
    {
        return rtrim($url, '/') . '/' . ltrim($path, '/');
    }

    protected function replaceBaseUrl($uri, $url)
    {
        $parsed = parse_url($url);

        return $uri
            ->withScheme($parsed['scheme'])
            ->withHost($parsed['host'])
            ->withPort($parsed['port'] ?? null);
    }

    /**
     * normalize Host.
     *
     * @return string
     */
    protected function normalizeHost()
    {
        $endpoint = $this->endpoint;
        if (strpos($endpoint, 'http') !== 0) {
            $endpoint = 'https://' . $endpoint;
        }
        $url = parse_url($endpoint);
        $domain = $url['host'];
        if (! ($this->options['bucket_endpoint'] ?? false)) {
            $domain = $this->bucket . '.' . $domain;
        }

        $domain = "{$url['scheme']}://{$domain}";

        return rtrim($domain, '/') . '/';
    }

    /**
     * Read an object from the ObsClient.
     *
     * @param $path
     *
     * @return \Obs\Internal\Common\CheckoutStream
     */
    protected function getObject($path)
    {
        $path = $this->applyPathPrefix($path);

        $model = $this->client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $path,
        ]);

        return $model['Body'];
    }

    /**
     * File list core method.
     *
     * @param string $dirname
     * @param bool $recursive
     *
     * @throws \Obs\ObsException
     *
     * @return array
     */
    public function listDirObjects($dirname = '', $recursive = false)
    {
        $delimiter = '/';
        $nextMarker = '';
        $maxKeys = 1000;

        $result = [];

        while (true) {
            $options = [
                'Bucket' => $this->bucket,
                'Delimiter' => $delimiter,
                'Prefix' => $dirname,
                'MaxKeys' => $maxKeys,
                'Marker' => $nextMarker,
            ];

            $model = $this->client->listObjects($options);

            $nextMarker = $model['NextMarker'];
            $objects = $model['Contents'];
            $prefixes = $model['CommonPrefixes'];
            if (! empty($objects)) {
                foreach ($objects as $object) {
                    $result['objects'][] = array_merge($object, [
                        'Prefix' => $dirname,
                    ]);
                }
            } else {
                $result['objects'] = [];
            }

            if (! empty($prefixes)) {
                foreach ($prefixes as $prefix) {
                    $result['prefix'][] = $prefix['Prefix'];
                }
            } else {
                $result['prefix'] = [];
            }

            // Recursive directory
            if ($recursive) {
                foreach ($result['prefix'] as $prefix) {
                    $next = $this->listDirObjects($prefix, $recursive);
                    $result['objects'] = array_merge($result['objects'], $next['objects']);
                }
            }

            if ($nextMarker === '') {
                break;
            }
        }//end while

        return $result;
    }

    /**
     * sign url.
     *
     * @param $path
     * @param \DateTimeInterface|int $expiration
     * @param array $options
     * @param mixed $method
     *
     * @return bool|string
     */
    public function signUrl($path, $expiration, array $options = [], $method = 'GET')
    {
        $expires = $expiration instanceof \DateTimeInterface ? $expiration->getTimestamp() - time() : $expiration;
        $path = $this->applyPathPrefix($path);

        try {
            $model = $this->client->createSignedUrl(array_merge([
                'Method' => $method,
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Expires' => $expires,
            ], $options));
        } catch (ObsException $exception) {
            return false;
        }

        return $model['SignedUrl'];
    }

    /**
     * temporary file url.
     *
     * @param string $path
     * @param \DateTimeInterface|int $expiration
     * @param array $options
     * @param mixed $method
     *
     * @return bool|string
     */
    public function getTemporaryUrl($path, $expiration, array $options = [], $method = 'GET')
    {
        $url = $this->signUrl($path, $expiration, $options, $method);
        if ($url === false) {
            return false;
        }
        $uri = new Uri($url);
        $url = $this->options['temporary_url'] ?? null;
        if ($url !== null) {
            $uri = $this->replaceBaseUrl($uri, $url);
        }

        return (string) $uri;
    }

    /**
     * Get options from the config.
     *
     * @param \League\Flysystem\Config $config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = $this->options;
        $visibility = $config->get('visibility');
        if ($visibility) {
            // For local reference
            $options['visibility'] = $visibility;
            // For external reference
            $options['ACL'] = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? ObsClient::AclPublicRead : ObsClient::AclPrivate;
        }

        $mimetype = $config->get('mimetype');
        if ($mimetype) {
            // For local reference
            $options['mimetype'] = $mimetype;
            // For external reference
            $options['ContentType'] = $mimetype;
        }

        foreach (static::$metaOptions as $option) {
            if (! $config->has($option)) {
                continue;
            }
            $options[$option] = $config->get($option);
        }

        return $options;
    }
}
