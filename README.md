# Flysystem Adapter for Icewind SMB

## Usage
```php
use Icewind\SMB\BasicAuth;
use Icewind\SMB\ServerFactory;
use League\Flysystem\Filesystem;
use RobGridley\Flysystem\Smb\SmbAdapter;

$factory = new ServerFactory;
$auth = new BasicAuth('username', 'domain/workgroup', 'password');
$server = $factory->createServer('host', $auth);
$share = $server->getShare('name');

$filesystem = new Filesystem(new SmbAdapter($share));
```

## Installation
```
$ composer require robgridley/flysystem-smb
```
