<?php

namespace spec\RobGridley\Flysystem\Smb;

use Prophecy\Argument;
use Icewind\SMB\IShare;
use Icewind\SMB\IFileInfo;
use PhpSpec\ObjectBehavior;
use League\Flysystem\Config;
use League\Flysystem\AdapterInterface;
use RobGridley\Flysystem\Smb\SmbAdapter;
use Icewind\SMB\Exception\NotFoundException;
use Icewind\SMB\Exception\InvalidTypeException;
use Icewind\SMB\Exception\AlreadyExistsException;

class SmbAdapterSpec extends ObjectBehavior
{
    public function let(IShare $share)
    {
        $this->beConstructedWith($share, 'prefix');
    }

    public function it_is_initializable()
    {
        $this->shouldHaveType(SmbAdapter::class);
        $this->shouldHaveType(AdapterInterface::class);
    }

    public function it_should_rename_files(IShare $share, IFileInfo $file)
    {
        $share->stat('prefix/')->shouldBeCalled()->willReturn($file);
        $file->isDirectory()->shouldBeCalled()->willReturn(true);
        $share->rename('prefix/foo', 'prefix/bar')->shouldBeCalled()->willReturn(true);
        $this->rename('foo', 'bar')->shouldReturn(true);
    }

    public function it_should_return_false_during_rename_when_source_does_not_exist(IShare $share, IFileInfo $file)
    {
        $share->stat('prefix/')->shouldBeCalled()->willReturn($file);
        $file->isDirectory()->shouldBeCalled()->willReturn(true);
        $share->rename('prefix/foo', 'prefix/bar')->shouldBeCalled()->willThrow(NotFoundException::class);
        $this->rename('foo', 'bar')->shouldReturn(false);
    }

    public function it_should_return_false_during_rename_when_destination_already_exists(IShare $share, IFileInfo $file)
    {
        $share->stat('prefix/')->shouldBeCalled()->willReturn($file);
        $file->isDirectory()->shouldBeCalled()->willReturn(true);
        $share->rename('prefix/foo', 'prefix/bar')->shouldBeCalled()->willThrow(AlreadyExistsException::class);
        $this->rename('foo', 'bar')->shouldReturn(false);
    }

    public function it_should_delete_files(IShare $share)
    {
        $share->del('prefix/foo')->shouldBeCalled()->willReturn(true);
        $this->delete('foo')->shouldReturn(true);
    }

    public function it_should_return_false_during_delete_when_path_does_not_exist(IShare $share)
    {
        $share->del('prefix/foo')->shouldBeCalled()->willThrow(NotFoundException::class);
        $this->delete('foo')->shouldReturn(false);
    }

    public function it_should_return_false_during_delete_when_path_is_a_directory(IShare $share)
    {
        $share->del('prefix/foo')->shouldBeCalled()->willThrow(InvalidTypeException::class);
        $this->delete('foo')->shouldReturn(false);
    }

    public function it_should_return_true_when_an_object_exists(IShare $share, IFileInfo $file)
    {
        $share->stat('prefix/foo')->shouldBeCalled()->willReturn($file);
        $this->has('foo')->shouldReturn(true);
    }

    public function it_should_return_false_when_an_object_does_not_exist(IShare $share)
    {
        $share->stat('prefix/foo')->shouldBeCalled()->willThrow(NotFoundException::class);
        $this->has('foo')->shouldReturn(false);
    }

    public function it_should_read_files(IShare $share)
    {
        $temp = tmpfile();
        fwrite($temp, 'string');
        fseek($temp, 0);
        $share->read('prefix/foo')->shouldBeCalled()->willReturn($temp);
        $this->read('foo')->shouldReturn(['path' => 'foo', 'contents' => 'string']);
    }

    public function it_should_return_false_when_trying_to_read_a_non_existing_file(IShare $share)
    {
        $share->read('prefix/foo')->shouldBeCalled()->willThrow(NotFoundException::class);
        $this->read('foo')->shouldReturn(false);
    }

    public function it_should_read_streams(IShare $share)
    {
        $temp = tmpfile();
        $share->read('prefix/foo')->shouldBeCalled()->willReturn($temp);
        $this->readStream('foo')->shouldReturn(['path' => 'foo', 'stream' => $temp]);
    }

    public function it_should_return_false_when_trying_to_stream_a_non_existing_file(IShare $share)
    {
        $share->read('prefix/foo')->shouldBeCalled()->willThrow(NotFoundException::class);
        $this->readStream('foo')->shouldReturn(false);
    }

    public function it_should_retrieve_metadata_for_files(IShare $share, IFileInfo $file)
    {
        $this->make_it_retrieve_metadata_using($share, $file, 'getMetadata');
    }

    public function it_should_retrieve_the_size_of_files(IShare $share, IFileInfo $file)
    {
        $this->make_it_retrieve_metadata_using($share, $file, 'getSize');
    }

    public function it_should_retrieve_timestamp_of_files(IShare $share, IFileInfo $file)
    {
        $this->make_it_retrieve_metadata_using($share, $file, 'getTimestamp');
    }

    public function it_should_retrieve_metadata_for_directories(IShare $share, IFileInfo $file)
    {
        $time = time();
        $file->isDirectory()->shouldBeCalled()->willReturn(true);
        $file->getPath()->shouldBeCalled()->willReturn('prefix/foo');
        $file->getMTime()->shouldBeCalled()->willReturn($time);
        $share->stat('prefix/foo')->shouldBeCalled()->willReturn($file);
        $this->getMetadata('foo')->shouldReturn([
            'type' => 'dir',
            'path' => 'foo',
            'timestamp' => $time
        ]);
    }

    public function it_should_retrieve_the_mimetype_of_files(IShare $share)
    {
        $temp = tmpfile();
        fwrite($temp, 'string');
        fseek($temp, 0);
        $share->read('prefix/foo.txt')->shouldBeCalled()->willReturn($temp);
        $this->getMimetype('foo.txt')->shouldReturn([
            'path' => 'foo.txt',
            'contents' => 'string',
            'mimetype' => 'text/plain'
        ]);
    }

    public function it_should_write_files(IShare $share)
    {
        $this->make_it_write_using($share, 'write', 'string');
    }

    public function it_should_update_files(IShare $share)
    {
        $this->make_it_write_using($share, 'update', 'string');
    }

    public function it_should_write_files_streamed(IShare $share)
    {
        $this->make_it_write_using($share, 'writeStream', tmpfile());
    }

    public function it_should_update_files_streamed(IShare $share)
    {
        $this->make_it_write_using($share, 'updateStream', tmpfile());
    }

    public function it_should_list_contents(IShare $share, IFileInfo $file)
    {
        $time = time();
        $file->isDirectory()->shouldBeCalled()->willReturn(false);
        $file->getPath()->shouldBeCalled()->willReturn('prefix/foo/bar');
        $file->getMTime()->shouldBeCalled()->willReturn($time);
        $file->getSize()->shouldBeCalled()->willReturn(100);
        $share->dir('prefix/foo')->shouldBeCalled()->willReturn([$file]);
        $this->listContents('foo')->shouldReturn([[
            'type' => 'file',
            'path' => 'foo/bar',
            'timestamp' => $time,
            'size' => 100
        ]]);
    }

    public function it_should_create_directories(IShare $share)
    {
        $share->stat('prefix/foo/bar')->shouldBeCalled()->willThrow(NotFoundException::class);
        $share->stat('prefix/foo')->shouldBeCalled()->willThrow(NotFoundException::class);
        $share->mkdir('prefix/foo')->shouldBeCalled();
        $share->mkdir('prefix/foo/bar')->shouldBeCalled();
        $this->createDir('foo/bar', new Config)->shouldBeArray();
    }

    public function it_should_delete_directories(IShare $share)
    {
        $share->dir('prefix/foo')->shouldBeCalled()->willReturn([]);
        $share->rmdir('prefix/foo')->shouldBeCalled();
        $this->deleteDir('foo');
    }

    private function make_it_write_using(IShare $share, $method, $contents)
    {
        $temp = tmpfile();
        $share->stat('prefix/foo')->shouldBeCalled()->willThrow(NotFoundException::class);
        $share->mkdir('prefix/foo')->shouldBeCalled();
        $share->write('prefix/foo/bar')->shouldBeCalled()->willReturn($temp);
        $this->{$method}('foo/bar', $contents, new Config)->shouldBeArray();
    }

    private function make_it_retrieve_metadata_using(IShare $share, IFileInfo $file, $method)
    {
        $time = time();
        $file->isDirectory()->shouldBeCalled()->willReturn(false);
        $file->getPath()->shouldBeCalled()->willReturn('prefix/foo');
        $file->getMTime()->shouldBeCalled()->willReturn($time);
        $file->getSize()->shouldBeCalled()->willReturn(100);
        $share->stat('prefix/foo')->shouldBeCalled()->willReturn($file);
        $this->{$method}('foo')->shouldReturn([
            'type' => 'file',
            'path' => 'foo',
            'timestamp' => $time,
            'size' => 100
        ]);
    }
}
