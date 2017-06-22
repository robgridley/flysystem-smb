# Flysystem Adapter for Icewind SMB

## Usage
```php
use Icewind\SMB\Server;
use Icewind\SMB\NativeServer;
use League\Flysystem\Filesystem;
use RobGridley\Flysystem\Smb\SmbAdapter;

if (Server::nativeAvailable()) {
    $server = new NativeServer('host', 'username', 'password');
} else {
    $server = new Server('host', 'username', 'password');
}

$share = $server->getShare('name');

$filesystem = new Filesystem(new SmbAdapter($share));
```

## Installation
```
$ composer require robgridley/flysystem-smb
```
