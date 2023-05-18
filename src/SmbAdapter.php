<?php

namespace RobGridley\Flysystem\Smb;

use Icewind\SMB\Exception\NotFoundException;
use Icewind\SMB\IFileInfo;
use Icewind\SMB\IShare;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Throwable;

class SmbAdapter implements FilesystemAdapter
{
    /**
     * The path prefixer.
     *
     * @var PathPrefixer
     */
    private PathPrefixer $prefixer;

    /**
     * Create an SMB adapter instance.
     *
     * @param IShare $share
     * @param string $root
     * @param MimeTypeDetector $mimeTypeDetector
     */
    function __construct(private IShare $share, string $root = '', private MimeTypeDetector $mimeTypeDetector = new FinfoMimeTypeDetector())
    {
        $this->prefixer = new PathPrefixer($root);
    }

    /**
     * Determine if the specified file exists.
     *
     * @param string $path
     * @return bool
     */
    public function fileExists(string $path): bool
    {
        try {
            $fileInfo = $this->share->stat($this->prefixer->prefixPath($path));
        } catch (NotFoundException $exception) {
            return false;
        } catch (Throwable $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }

        return !$fileInfo->isDirectory();
    }

    /**
     * Determine if the specified directory exists.
     *
     * @param string $path
     * @return bool
     */
    public function directoryExists(string $path): bool
    {
        try {
            $fileInfo = $this->share->stat($this->prefixer->prefixPath($path));
        } catch (NotFoundException $exception) {
            return false;
        } catch (Throwable $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }

        return $fileInfo->isDirectory();
    }

    /**
     * Write a string.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @return void
     */
    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->ensureParentDirectoryExists($path, $config);
            $stream = $this->share->write($this->prefixer->prefixPath($path));
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }

        if (false === @fwrite($stream, $contents)) {
            throw UnableToWriteFile::atLocation($path, 'Unable to write to SMB stream.');
        }

        if (!fclose($stream)) {
            throw UnableToWriteFile::atLocation($path, 'Unable to close SMB stream.');
        }
    }

    /**
     * Write a stream.
     *
     * @param string $path
     * @param $contents
     * @param Config $config
     * @return void
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->ensureParentDirectoryExists($path, $config);
            $stream = $this->share->write($this->prefixer->prefixPath($path));
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }

        if (false === stream_copy_to_stream($contents, $stream)) {
            throw UnableToWriteFile::atLocation($path, 'Unable to write to SMB stream.');
        }

        if (!fclose($stream)) {
            throw UnableToWriteFile::atLocation($path, 'Unable to close SMB stream.');
        }
    }

    /**
     * Read a file.
     *
     * @param string $path
     * @return string
     */
    public function read(string $path): string
    {
        $contents = stream_get_contents($this->readStream($path));

        if ($contents === false) {
            throw UnableToReadFile::fromLocation($path, 'Unable to read stream.');
        }

        return $contents;
    }

    /**
     * Open a stream for a file.
     *
     * @param string $path
     * @return resource
     */
    public function readStream(string $path)
    {
        try {
            return $this->share->read($this->prefixer->prefixPath($path));
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Delete a file.
     *
     * @param string $path
     * @return void
     */
    public function delete(string $path): void
    {
        try {
            $this->share->del($this->prefixer->prefixPath($path));
        } catch (NotFoundException $exception) {
            // Do nothing.
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $path
     * @return void
     */
    public function deleteDirectory(string $path): void
    {
        $directories = [$path];

        try {
            foreach ($this->listContents($path, true) as $item) {
                if ($item->isDir()) {
                    $directories[] = $item->path();
                    continue;
                }
                $this->delete($item->path());
            }
            foreach (array_reverse($directories) as $directory) {
                $this->share->rmdir($this->prefixer->prefixPath($directory));
            }
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Create a directory.
     *
     * @param string $path
     * @param Config $config
     * @return void
     */
    public function createDirectory(string $path, Config $config): void
    {
        if ($this->directoryExists($path)) {
            return;
        }

        $parentDirectory = dirname($path);

        if ($parentDirectory !== '' && $parentDirectory !== '.') {
            $this->createDirectory($parentDirectory, $config);
        }

        try {
            $this->share->mkdir($this->prefixer->prefixPath($path));
        } catch (Throwable $exception) {
            throw UnableToCreateDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Set file visibility.
     *
     * @param string $path
     * @param string $visibility
     * @return void
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'SMB does not support this operation.');
    }

    /**
     * Get file visibility.
     *
     * @param string $path
     * @return FileAttributes
     */
    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'SMB does not support this operation.');
    }

    /**
     * Determine the MIME-type of a file.
     *
     * @param string $path
     * @return FileAttributes
     */
    public function mimeType(string $path): FileAttributes
    {
        try {
            $contents = stream_get_contents($this->readStream($path), 65535);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Unable to read file.');
        }

        $mimeType = $this->mimeTypeDetector->detectMimeType($path, $contents);

        if (is_null($mimeType)) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Unable to detect MIME type.');
        }

        return new FileAttributes($path, null, null, null, $mimeType);
    }

    /**
     * Get the modification date of a file.
     *
     * @param string $path
     * @return FileAttributes
     */
    public function lastModified(string $path): FileAttributes
    {
        try {
            $fileInfo = $this->share->stat($this->prefixer->prefixPath($path));
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::lastModified($path, $exception->getMessage(), $exception);
        }

        return new FileAttributes($path, null, null, $fileInfo->getMTime());
    }

    /**
     * Get the size of a file.
     *
     * @param string $path
     * @return FileAttributes
     */
    public function fileSize(string $path): FileAttributes
    {
        try {
            $fileInfo = $this->share->stat($this->prefixer->prefixPath($path));
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::fileSize($path, $exception->getMessage(), $exception);
        }

        if ($fileInfo->isDirectory()) {
            throw UnableToRetrieveMetadata::fileSize($path, 'Path is a directory.');
        }

        return new FileAttributes($path, $fileInfo->getSize());
    }

    /**
     * List the contents of a directory.
     *
     * @param string $path
     * @param bool $deep
     * @return iterable
     */
    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $listing = $this->share->dir($this->prefixer->prefixPath($path));
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($path, $deep, $exception);
        }

        foreach ($listing as $fileInfo) {
            $attributes = $this->fileInfoToAttributes($fileInfo);
            yield $attributes;

            if ($deep && $attributes->isDir()) {
                foreach ($this->listContents($attributes->path(), true) as $child) {
                    yield $child;
                }
            }
        }
    }

    /**
     * Move a file.
     *
     * @param string $source
     * @param string $destination
     * @param Config $config
     * @return void
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $sourceLocation = $this->prefixer->prefixPath($source);
        $destinationLocation = $this->prefixer->prefixPath($destination);

        try {
            $this->ensureParentDirectoryExists($destinationLocation, $config);
            $this->share->rename($sourceLocation, $destinationLocation);
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * Copy a file.
     *
     * @param string $source
     * @param string $destination
     * @param Config $config
     * @return void
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $sourceStream = $this->readStream($source);
            $this->writeStream($destination, $sourceStream, $config);
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }

        fclose($sourceStream);
    }

    /**
     * Create the parent directories if they do not exist.
     *
     * @param string $path
     * @param Config $config
     * @return void
     * @throws UnableToCreateDirectory
     */
    private function ensureParentDirectoryExists(string $path, Config $config): void
    {
        $parentDirectory = dirname($path);

        if ($parentDirectory === '' || $parentDirectory === '.') {
            return;
        }

        $this->createDirectory($parentDirectory, $config);
    }

    /**
     * Convert an SMB file info instance to a file or directory attributes instance.
     *
     * @param IFileInfo $fileInfo
     * @return StorageAttributes
     */
    private function fileInfoToAttributes(IFileInfo $fileInfo): StorageAttributes
    {
        if ($fileInfo->isDirectory()) {
            return new DirectoryAttributes(
                $this->prefixer->stripPrefix($fileInfo->getPath()),
                null,
                $fileInfo->getMTime(),
            );
        }

        return new FileAttributes(
            $this->prefixer->stripPrefix($fileInfo->getPath()),
            $fileInfo->getSize(),
            null,
            $fileInfo->getMTime(),
        );
    }
}
