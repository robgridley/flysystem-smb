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
        $fullPath = $this->applyPathPrefix($path);
        $stream = $this->share->write($fullPath);

        return fwrite($stream, $contents) === false || !fclose($stream) ? false : compact('path', 'contents');
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
        $fullPath = $this->applyPathPrefix($path);
        $stream = $this->share->write($fullPath);

        stream_copy_to_stream($resource, $stream);

        return fclose($stream) ? compact('path') : false;
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
        $from = $this->applyPathPrefix($path);
        $to = $this->applyPathPrefix($newPath);

        $this->share->rename($from, $to);

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
        $fullPath = $this->applyPathPrefix($path);

        try {
            $this->share->del($fullPath);
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
        
        $fullPath = $this->applyPathPrefix($path);

        try {
            $this->share->rmdir($fullPath);
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
        $fullPath = $this->applyPathPrefix($path);

        try {
            $this->share->mkdir($fullPath);
        } catch (AlreadyExistsException $e) {
            // That's okay.
        } catch (NotFoundException $e) {
            return false;
        }

        return ['path' => $path];
    }

    /**
     * Determine if a file or directory exists.
     *
     * @param string $path
     * @return bool
     */
    public function has($path)
    {
        return (bool) $this->getMetadata($path);
    }

    /**
     * Read a file.
     *
     * @param string $path
     * @return array|false
     */
    public function read($path)
    {
        $fullPath = $this->applyPathPrefix($path);

        $stream = $this->share->read($fullPath);

        if (($contents = stream_get_contents($stream)) === false) {
            return false;
        }

        fclose($stream);

        return compact('contents');
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     * @return array
     */
    public function readStream($path)
    {
        $fullPath = $this->applyPathPrefix($path);

        return ['stream' => $this->share->read($fullPath)];
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
        $fullPath = $this->applyPathPrefix($path);

        try {
            $files = $this->share->dir($this->stripTrailingSeparator($fullPath));
        } catch (InvalidTypeException $e) {
            return array();
        } catch (NotFoundException $e) {
            return false;
        }

        $contents = array();

        foreach ($files as $file) {

            $contents[] = $this->normalizeFileInfo($file);

            if ($file->isDirectory() && $recursive) {
                $contents = array_merge($contents, $this->listContents($this->getFilePath($file), true));
            }
        }

        return $contents;
    }

    /**
     * Get all of the metadata for a file or directory.
     *
     * @param string $path
     * @return array|false
     */
    public function getMetadata($path)
    {
        $fullPath = $this->applyPathPrefix($path);

        try {
            $file = $this->share->stat($fullPath);
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
        if (!$metadata = $this->read($path)) {
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

        if ($normalized['type'] === 'file') {
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
        return $this->removePathPrefix(ltrim($file->getPath(), $this->pathSeparator));
    }

    /**
     * Strip any trailing separators from a path.
     *
     * @param string $path
     * @return string
     */
    protected function stripTrailingSeparator($path)
    {
        return rtrim($path, $this->pathSeparator);
    }

    /**
     * Delete the contents of a directory.
     *
     * @param string $path
     */
    protected function deleteContents($path)
    {
        $contents = $this->listContents($path, true) ?: array();

        foreach (array_reverse($contents) as $object) {

            $fullPath = $this->applyPathPrefix($object['path']);

            if ($object['type'] === 'dir') {
                $this->share->rmdir($fullPath);
            } else {
                $this->share->del($fullPath);
            }
        }
    }

}
