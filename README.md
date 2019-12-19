## Kaysonwu\Flysystem\Aliyun-OSS
[![Author](http://img.shields.io/badge/author-@kaysonWu-blue.svg?style=flat-square)](https://github.com/kaysonwu)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/kaysonwu/flysystem-aliyun-oss.svg?style=flat-square)](https://packagist.org/packages/kaysonwu/flysystem-aliyun-oss)
[![Total Downloads](https://img.shields.io/packagist/dt/kaysonwu/flysystem-aliyun-oss.svg?style=flat-square)](https://packagist.org/packages/kaysonwu/flysystem-aliyun-oss)

[使用中文阅读](https://github.com/kaysonwu/aliyun-oss/blob/master/README-CN.md)

## Advantages

1. Support Laravel & Lumen
2. Compared with [xxtime/flysystem-aliyun-oss](https://github.com/xxtime/flysystem-aliyun-oss), it is more in line with the [flysystem](https://flysystem.thephpleague.com/docs/architecture/) interface specification. Because the flysystem interface suggests that the return value is array or bool, but [xxtime/flysystem-aliyun-oss](https://github.com/xxtime/flysystem-aliyun-oss) is not very strict about exception handling.
3. Compared to [apollopy/flysystem-aliyun-oss <= 1.2.0](https://github.com/apollopy/flysystem-aliyun-oss) supports visibility get/set.
4. Support Dynamically call OSS SDK methods.

**ps:** The comparison of similar projects is only to highlight the differences. In fact, they are all very good.

## Installation

#### Install via composer

Run the following command to pull in the latest version:

```bash
composer require kaysonwu/flysystem-aliyun-oss
```

#### Laravel Install

If your laravel version `<=5.4`, Add the service provider to the `providers` array in the `config/app.php` config file as follows:

```php
'providers' => [

    ...

    Kaysonwu\Flysystem\Aliyun\OsserviceProvider::class,
]
```

##### Lumen Install

Add the following snippet to the `bootstrap/app.php` file under the providers section as follows:

```php
...

// Add this line
$app->register(Kaysonwu\Flysystem\Aliyun\OsserviceProvider::class);
```

##### Config for Laravel/Lumen

Add the adapter config to the `disks` array in the `config/filesystems.php` config file as follows:

```php

'disks' => [
    ...

    'aliyun-oss' => [

        'driver' => 'aliyun-oss',

        /**
         * The AccessKeyId from OSS or STS.
         */
        'key' => '<your AccessKeyId>',

        /**
         * The AccessKeySecret from OSS or STS
         */
        'secret' => '<your AccessKeySecret>',

        /**
         * The domain name of the datacenter.
         *
         * @example: oss-cn-hangzhou.aliyuncs.com
         */
        'endpoint' => '<endpoint address>',

        /**
         * The bucket name for the OSS.
         */
        'bucket' => '<bucket name>',

        /**
         * The security token from STS.
         */
        'token' => null,

        /**
         * If this is the CName and binded in the bucket.
         *
         * Values: true or false
         */
        'cname' => false,
        
        /**
         * Path prefix
         */
        'prefix' => '',
        
        /**
         *  Request header options.
         * 
         *  @example [x-oss-server-side-encryption => 'KMS']
         */
        'options' => []
    ]
]
```

## Usage

##### Basic

Please refer to [filesystem-api](https://flysystem.thephpleague.com/docs/usage/filesystem-api/).

```php
use Kaysonwu\Flysystem\Aliyun\OssAdapter;
use League\Flysystem\Filesystem;
use OSS\OssClient;

$client = new OssClient(
    '<your AccessKeyId>',
    '<your AccessKeySecret>',
    '<endpoint address>'
);

$adapter = new OssAdapter($client, '<bucket name>', 'optional-prefix', 'optional-options');
$filesystem = new Filesystem($adapter);

$filesystem->has('file.txt');

// Dynamic call SDK method.
$adapter->setTimeout(30);

```

##### Laravel/Lumen

Please refer to [filesystem](https://laravel.com/docs/6.x/filesystem)

```php
use Illuminate\Support\Facades\Storage;

Storage::disk('aliyun-oss')->get('path');

// Dynamic call SDK method.
Storage::disk('aliyun-oss')->setTimeout(30);
```
