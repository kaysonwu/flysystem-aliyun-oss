<?php

namespace Kaysonwu\Flysystem\Aliyun;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use OSS\OssClient;

class OsserviceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Storage::extend('aliyun-oss', function ($app, $config) {

            $client = @new OssClient(
                $config['key'],
                $config['secret'],
                $config['endpoint'],
                $config['cname'],
                $config['token']
            );

            $prefix = isset($config['prefix']) ? $config['prefix'] : '';
            $options = isset($config['options']) ? $config['options'] : [];

            return new Filesystem(new OssAdapter($client, $config['bucket'], $prefix, $options));
        });
    }
}
