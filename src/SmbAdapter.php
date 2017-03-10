<?php

namespace RobGridley\Flysystem\Smb;

use Icewind\SMB\IShare;
use Icewind\SMB\IFileInfo;
use Icewind\SMB\Exception\NotFoundException;
use Icewind\SMB\Exception\InvalidTypeException;
use Icewind\SMB\Exception\AlreadyExistsException;
use League\Flysystem\Util;
use League\Flysystem\Config;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;

class SmbAdapter extends AbstractAdapter
{
    use StreamedCopyTrait, NotSupportingVisibilityTrait;

    /**
     * The Icewind SMB Share instance.
     *
     * @var IShare
     */
    protected $share;

    /**
     * Create a new instance.
     *
     * @param IShare $share
     * @param string $prefix
     */
    function __construct(IShare $share, $prefix = null)
    {
        $this->share = $share;

        $this->setPathPrefix($prefix);
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @return array|false
     */
    public function write($path, $contents, Config $config)
    {
        $this->recursiveCreateDir(Util::dirname($path));

        $location = $this->applyPathPrefix($path);
        $stream = $this->share->write($location);

        fwrite($stream, $contents);

        if (!fclose($stream)) {
            return false;
        }

        return compact('path', 'contents');
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config
     * @return array|false
     */
    public function writeStream($path, $resource, Config $config)
    {
        $this->recursiveCreateDir(Util::dirname($path));

        $location = $this->applyPathPrefix($path);
        $stream = $this->share->write($location);

        stream_copy_to_stream($resource, $stream);

        if (!fclose($stream)) {
            return false;
        }

        return compact('path');
    }

    /**
     * Update an existing file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @return array|false
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Update an existing file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config
     * @return array|false
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * Rename a file or directory.
     *
     * @param string $path
     * @param string $newPath
     * @return bool
     */
    public function rename($path, $newPath)
    {
        $this->recursiveCreateDir(Util::dirname($newPath));

        $location = $this->applyPathPrefix($path);
        $destination = $this->applyPathPrefix($newPath);

        try {
            $this->share->rename($location, $destination);
        } catch (NotFoundException $e) {
            return false;
        } catch (AlreadyExistsException $e) {
            return false;
        }

        return true;
    }

    /**
     * Delete a file.
     *
     * @param string $path
     * @return bool
     */
    public function delete($path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->share->del($location);
        } catch (NotFoundException $e) {
            return false;
        } catch (InvalidTypeException $e) {
            return false;
        }

        return true;
    }

    /**
     * Delete a directory.
     *
     * @param string $path
     * @return bool
     */
    public function deleteDir($path)
    {
        $this->deleteContents($path);

        $location = $this->applyPathPrefix($path);

        try {
            $this->share->rmdir($location);
        } catch (NotFoundException $e) {
            return false;
        } catch (InvalidTypeException $e) {
            return false;
        }

        return true;
    }

    /**
     * Create a directory.
     *
     * @param string $path
     * @param Config $config
     * @return array|false
     */
    public function createDir($path, Config $config)
    {
        $this->recursiveCreateDir($path);

        return compact('path');
    }

    /**
     * Recursively create directories.
     *
     * @param $path
     */
    protected function recursiveCreateDir($path)
    {
        if ($this->isDirectory($path)) {
            return;
        }

        $directories = explode($this->pathSeparator, $path);
        if (count($directories) > 1) {
            $parentDirectories = array_splice($directories, 0, count($directories) - 1);
            $this->recursiveCreateDir(implode($this->pathSeparator, $parentDirectories));
        }

        $location = $this->applyPathPrefix($path);

        $this->share->mkdir($location);
    }

    /**
     * Determine if a file or directory exists.
     *
     * @param string $path
     * @return bool
     */
    public function has($path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->share->stat($location);
        } catch (NotFoundException $e) {
            return false;
        }

        return true;
    }

    /**
     * Read a file.
     *
     * @param string $path
     * @return array|false
     */
    public function read($path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $stream = $this->share->read($location);
        } catch (NotFoundException $e) {
            return false;
        }

        $contents = stream_get_contents($stream);

        if ($contents === false) {
            return false;
        }

        fclose($stream);

        return compact('path', 'contents');
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     * @return array|false
     */
    public function readStream($path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $stream = $this->share->read($location);
        } catch (NotFoundException $e) {
            return false;
        }

        return compact('path', 'stream');
    }

    /**
     * List the contents of a directory.
     *
     * @param string $path
     * @param bool $recursive
     * @return array|false
     */
    public function listContents($path = '', $recursive = false)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $files = $this->share->dir($location);
        } catch (InvalidTypeException $e) {
            return [];
        } catch (NotFoundException $e) {
            return [];
        }

        $result = [];

        foreach ($files as $file) {
            $result[] = $this->normalizeFileInfo($file);

            if ($file->isDirectory() && $recursive) {
                $result = array_merge($result, $this->listContents($this->getFilePath($file), true));
            }
        }

        return $result;
    }

    /**
     * Get all of the metadata for a file or directory.
     *
     * @param string $path
     * @return array|false
     */
    public function getMetadata($path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $file = $this->share->stat($location);
        } catch (NotFoundException $e) {
            return false;
        }

        return $this->normalizeFileInfo($file);
    }

    /**
     * Get the size of a file.
     *
     * @param string $path
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the MIME type of a file.
     *
     * @param string $path
     * @return array|false
     */
    public function getMimetype($path)
    {
        $metadata = $this->read($path);

        if ($metadata === false) {
            return false;
        }

        $metadata['mimetype'] = Util::guessMimeType($path, $metadata['contents']);

        return $metadata;
    }

    /**
     * Get the timestamp of a file or directory.
     *
     * @param string $path
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Normalize the file info.
     *
     * @param IFileInfo $file
     * @return array
     */
    protected function normalizeFileInfo(IFileInfo $file)
    {
        $normalized = [
            'type' => $file->isDirectory() ? 'dir' : 'file',
            'path' => $this->getFilePath($file),
            'timestamp' => $file->getMTime()
        ];

        if (!$file->isDirectory()) {
            $normalized['size'] = $file->getSize();
        }

        return $normalized;
    }

    /**
     * Get the normalized path from an IFileInfo object.
     *
     * @param IFileInfo $file
     * @return string
     */
    protected function getFilePath(IFileInfo $file)
    {
        $location = $file->getPath();

        return $this->removePathPrefix($location);
    }

    /**
     * Delete the contents of a directory.
     *
     * @param string $path
     */
    protected function deleteContents($path)
    {
        $contents = $this->listContents($path, true);

        foreach (array_reverse($contents) as $object) {
            $location = $this->applyPathPrefix($object['path']);

            if ($object['type'] === 'dir') {
                $this->share->rmdir($location);
            } else {
                $this->share->del($location);
            }
        }
    }

    /**
     * Determine if the specified path is a directory.
     *
     * @param string $path
     * @return bool
     */
    protected function isDirectory($path)
    {
        $location = $this->applyPathPrefix($path);

        if (empty($location)) {
            return true;
        }

        try {
            $file = $this->share->stat($location);
        } catch (NotFoundException $e) {
            return false;
        }

        return $file->isDirectory();
    }
}
