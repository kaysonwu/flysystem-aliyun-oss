<?php

namespace Tests;

use League\Flysystem\Aliyun\OssAdapter as Adapter;
use League\Flysystem\Config;
use Mockery;
use OSS\Core\OssException;
use OSS\Model\ObjectInfo;
use OSS\Model\ObjectListInfo;
use OSS\OssClient;
use PHPUnit\Framework\TestCase;

class OssAdapterTest extends TestCase
{
    /**
     * Get a Oss Client instance.
     *
     * @return \Mockery\MockInterface|\OSS\OssClient
     */
    protected function getClient()
    {
        return Mockery::mock(OssClient::class, ['AccessKeyId', 'AccessKeySecret', 'endpoint']);
    }


    public function testBucket()
    {
        $adapter = new Adapter($this->getClient(), 'bucket');

        $this->assertEquals('bucket', $adapter->getBucket());

        $adapter->setBucket('newBucket');

        $this->assertEquals('newBucket', $adapter->getBucket());
    }

    public function testGetClient()
    {
        $client = $this->getClient();

        $adapter = new Adapter($client, 'bucket');

        $this->assertSame($client, $adapter->getClient());
    }

    public function testHas()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('doesObjectExist')->once()->andReturn(true);
        $this->assertTrue($adapter->has('file.txt'));
    }

    public function testWrite()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('putObject')->once()->andReturn(['Etag' => 'Etag']);
        $this->assertInternalType('array', $adapter->write('file.txt', 'content', new Config()));

        $client->shouldReceive('putObject')->once()->andThrow(OssException::class);
        $this->assertFalse($adapter->write('file.txt', 'content', new Config([
                'headers' => [
                    'x-oss-forbid-overwrite' => true,
                ]
            ])));
    }

    public function testWriteStream()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $stream = tmpfile();
        fwrite($stream, 'content');
        rewind($stream);

        $client->shouldReceive('putObject')->andReturn(['Etag' => 'Etag']);
        $this->assertInternalType('array', $adapter->writeStream('file.txt', $stream, new Config()));

        fclose($stream);
    }

    public function testRead()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('getObject')->andReturn('content');
        $response = $adapter->read('file.txt');

        $this->assertInternalType('array', $response);
        $this->assertArrayHasKey('contents', $response);
        $this->assertEquals('content', $response['contents']);

        $response = $adapter->readStream('file.txt');

        $this->assertInternalType('array', $response);
        $this->assertArrayHasKey('stream', $response);

        $this->assertEquals('content', stream_get_contents($stream = $response['stream']));
        fclose($stream);
    }

    public function testCopy()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('copyObject')->once()->andThrow(OssException::class);
        $this->assertFalse($adapter->copy('missing.txt', 'newMissing.txt'));

        $client->shouldReceive('copyObject')->once()->andReturn([
            '2019-12-18T12:21:21.000Z',
            '9A0364B9E99BB480DD25E1F0284C8555'
        ]);
        $this->assertTrue($adapter->copy('file.txt', 'newFile.txt'));
    }

    public function testDelete()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('deleteObject');
        $this->assertTrue($adapter->delete('file.txt'));
    }

    public function testDeleteDir()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('deleteObjects');

        $client->shouldReceive('listObjects')->once()->andReturn($this->getObjectListInfo());
        $this->assertTrue($adapter->deleteDir('images'));

        $client->shouldReceive('listObjects')->once()->andThrow(OssException::class);
        $this->assertFalse($adapter->deleteDir('files'));
    }

    protected function getObjectListInfo()
    {
        $objects = [
            new ObjectInfo('images/1.jpg', '', 'etag', 'image/jpeg', 1024, ''),
            new ObjectInfo('images/2.jpg', '', 'etag', 'image/jpeg', 1024, ''),
        ];
        $directories = [];

        return new ObjectListInfo(
            'bucket',
            'prefix',
            '',
            '',
            1000,
            '/',
            '',
            $objects,
            $directories
        );
    }

    public function testCreateDir()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('createObjectDir')->once()->andReturn(['type' => 'dir']);
        $response = $adapter->createDir('images', new Config());

        $this->assertInternalType('array', $response);

        $client->shouldReceive('createObjectDir')->once()->andThrow(OssException::class);
        $response = $adapter->createDir('images', new Config([
            'headers' => [
                'x-oss-forbid-overwrite' => true
            ],
        ]));

        $this->assertFalse($response);
    }

    public function testMetadata()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('getObjectMeta')->with('bucket', 'file.txt')->andReturn([
            'content-type' => 'text/plain',
            'content-length' => 7,
            'last-modified' => 'Tue, 17 Dec 2019 15:37:50 GMT',
        ]);

        $response = $adapter->getMetadata('file.txt');

        $this->assertInternalType('array', $response);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('path', $response);

        $response = $adapter->getSize('file.txt');

        $this->assertInternalType('array', $response);
        $this->assertArrayHasKey('size', $response);
        $this->assertEquals(7, $response['size']);

        $response = $adapter->getMimetype('file.txt');

        $this->assertInternalType('array', $response);
        $this->assertArrayHasKey('mimetype', $response);
        $this->assertEquals('text/plain', $response['mimetype']);

        $response = $adapter->getTimestamp('file.txt');

        $this->assertInternalType('array', $response);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertEquals(1576597070, $response['timestamp']);

        $client->shouldReceive('getObjectMeta')->with('bucket', 'images/')->andReturn([
            'content-type' => 'application/octet-stream',
            'content-length' => 0,
            'last-modified' => 'Tue, 17 Dec 2019 15:37:51 GMT',
        ]);

        $response = $adapter->getMetadata('images/');

        $this->assertInternalType('array', $response);
        $this->assertArrayHasKey('type', $response);
        $this->assertEquals('dir', $response['type']);

        $client->shouldReceive('getObjectMeta')->with('bucket', 'missing.txt')->andThrow(OssException::class);
        $this->assertFalse($adapter->getMetadata('missing.txt'));
    }

    public function testGetVisibility()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('getObjectAcl')->once()->andReturn('default');
        $response = $adapter->getVisibility('file.txt');

        $this->assertInternalType('array', $response);
        $this->assertArrayHasKey('visibility', $response);
        $this->assertEquals('default', $response['visibility']);

        $client->shouldReceive('getObjectAcl')->once()->andThrow(OssException::class);
        $this->assertFalse($adapter->getVisibility('missing.txt'));
    }

    public function testSetVisibility()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('putObjectAcl')->once()->andReturn([]);
        $response = $adapter->setVisibility('file.txt', Adapter::VISIBILITY_DEFAULT);

        $this->assertInternalType('array', $response);
        $this->assertArrayHasKey('visibility', $response);
        $this->assertEquals(Adapter::VISIBILITY_DEFAULT, $response['visibility']);

        $client->shouldReceive('putObjectAcl')->once()->andThrow(OssException::class);
        $this->assertFalse($adapter->setVisibility('file.txt', 'Unexpected'));
    }

    public function testListContents()
    {
        $client = $this->getClient();
        $adapter = new Adapter($client, 'bucket');

        $client->shouldReceive('listObjects')->once()->andReturn($this->getObjectListInfo());

        $response = $adapter->listContents('images');

        $this->assertInternalType('array', $response);
        $this->assertInternalType('array', $response[0]);
        $this->assertArrayHasKey('path', $response[0]);

        $client->shouldReceive('listObjects')->once()->andThrow(OssException::class);
        $response = $adapter->listContents('files');

        $this->assertInternalType('array', $response);
        $this->assertCount(0, $response);
    }
}
