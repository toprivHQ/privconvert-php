<?php

declare(strict_types=1);

namespace PrivConvert\Tests;

use PHPUnit\Framework\TestCase;
use PrivConvert\Exception\StorageException;
use PrivConvert\PrivConvert;
use PrivConvert\Storage\AzureBlobClient;
use PrivConvert\Storage\Location;

final class FakeAwsException extends \RuntimeException
{
    public function __construct(private readonly string $awsCode, private readonly int $status = 400)
    {
        parent::__construct($awsCode);
    }

    public function getAwsErrorCode(): string
    {
        return $this->awsCode;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }
}

final class FakeBody
{
    private int $pos = 0;

    public function __construct(private readonly string $data)
    {
    }

    public function eof(): bool
    {
        return $this->pos >= strlen($this->data);
    }

    public function read(int $n): string
    {
        $chunk = substr($this->data, $this->pos, $n);
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function close(): void
    {
    }
}

final class FakeS3
{
    /** @var array<string, string> */
    public array $objects = [];
    /** @var list<array<string, mixed>> */
    public array $puts = [];
    public bool $rejectCondition = false;
    public ?int $claimedSize = null;

    public function headObject(array $a): array
    {
        if (!isset($this->objects[$a['Bucket'] . '/' . $a['Key']])) {
            throw new FakeAwsException('NotFound', 404);
        }
        return [];
    }

    public function getObject(array $a): array
    {
        if ($a['Bucket'] === 'denied') {
            throw new FakeAwsException('AccessDenied', 403);
        }
        $k = $a['Bucket'] . '/' . $a['Key'];
        if (!isset($this->objects[$k])) {
            throw new FakeAwsException('NoSuchKey', 404);
        }
        return ['Body' => new FakeBody($this->objects[$k]), 'ContentLength' => $this->claimedSize ?? strlen($this->objects[$k])];
    }

    public function putObject(array $a): array
    {
        $k = $a['Bucket'] . '/' . $a['Key'];
        if (isset($a['IfNoneMatch'])) {
            if ($this->rejectCondition) {
                throw new \RuntimeException('connection closed');
            }
            if (isset($this->objects[$k])) {
                throw new FakeAwsException('PreconditionFailed', 412);
            }
        }
        $this->puts[] = $a;
        $this->objects[$k] = $a['Body'];
        return [];
    }
}

final class FakeGcsObject
{
    public function __construct(private readonly FakeGcs $gcs, private readonly string $key)
    {
    }

    public function exists(): bool
    {
        return isset($this->gcs->objects[$this->key]);
    }

    public function info(): array
    {
        if (!$this->exists()) {
            throw new \RuntimeException('not found', 404);
        }
        return ['size' => (string) strlen($this->gcs->objects[$this->key]), 'generation' => '7'];
    }

    public function downloadAsStream(array $options): FakeBody
    {
        $this->gcs->downloadOptions = $options;
        return new FakeBody($this->gcs->objects[$this->key]);
    }
}

final class FakeGcsBucket
{
    public function __construct(private readonly FakeGcs $gcs, private readonly string $name)
    {
    }

    public function object(string $key): FakeGcsObject
    {
        return new FakeGcsObject($this->gcs, $this->name . '/' . $key);
    }

    public function upload(string $data, array $options): void
    {
        $k = $this->name . '/' . $options['name'];
        if (($options['ifGenerationMatch'] ?? null) === 0 && isset($this->gcs->objects[$k])) {
            throw new \RuntimeException('precondition', 412);
        }
        $this->gcs->uploads[] = $options;
        $this->gcs->objects[$k] = $data;
    }
}

final class FakeGcs
{
    /** @var array<string, string> */
    public array $objects = [];
    /** @var list<array<string, mixed>> */
    public array $uploads = [];
    public array $downloadOptions = [];

    public function bucket(string $name): FakeGcsBucket
    {
        return new FakeGcsBucket($this, $name);
    }
}

final class StorageTest extends TestCase
{
    private PrivConvert $pc;
    private FakeS3 $s3;
    private FakeGcs $gcs;

    protected function setUp(): void
    {
        $this->pc = new PrivConvert('pk_live_test', baseUrl: FakeServer::start());
        $this->s3 = new FakeS3();
        $this->s3->objects['in-bucket/docs/a.docx'] = 'hello';
        $this->gcs = new FakeGcs();
    }

    public function testParse(): void
    {
        $l = Location::parse('s3://my-bucket/path/to/file.docx');
        $this->assertSame(['s3', 'my-bucket', 'path/to/file.docx'], [$l->provider, $l->bucket, $l->key]);
        $this->assertSame('gcs', Location::parse('gs://b-1/x.pdf')->provider);
        $az = Location::parse('az://myacct/my-container/dir/x.pdf');
        $this->assertSame(['azure', 'my-container', 'dir/x.pdf', 'myacct'], [$az->provider, $az->bucket, $az->key, $az->account]);
        $https = Location::parse('https://myacct.blob.core.windows.net/my-container/dir/x.pdf');
        $this->assertSame((string) $az, (string) $https);
    }

    public function testParseRejects(): void
    {
        foreach ([
            'http://bucket/key', 's3://bucket/', 's3://bucket', 's3://Bucket/key', 's3://b/key',
            's3://bucket/dir/', 's3://bucket/a?b', 's3://bucket/a#b', "s3://bucket/a\nb",
            'az://ab/cc1/blob', 'https://acct.blob.core.windows.net.evil.com/cc/x', '',
        ] as $url) {
            try {
                Location::parse($url);
                $this->fail("accepted {$url}");
            } catch (StorageException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRoundTrip(): void
    {
        $r = $this->pc->convertStorage('word-to-pdf', 's3://in-bucket/docs/a.docx', 's3://out-bucket/a.pdf', ['s3Client' => $this->s3, 'params' => ['quality' => 5]]);
        $this->assertSame('%PDF-1.4', $this->s3->objects['out-bucket/a.pdf']);
        $this->assertSame('application/pdf', $this->s3->puts[0]['ContentType']);
        $this->assertSame('a.pdf', $r->filename);
        $this->assertSame(['a.docx'], FakeServer::lastCall()['files']['file']);
        $this->assertSame('5', FakeServer::lastCall()['post']['quality']);
    }

    public function testUploadArgs(): void
    {
        $this->pc->convertStorage('word-to-pdf', 's3://in-bucket/docs/a.docx', 's3://out-bucket/a.pdf', [
            's3Client' => $this->s3,
            'uploadArgs' => ['ServerSideEncryption' => 'aws:kms', 'Bucket' => 'ignored'],
        ]);
        $this->assertSame('aws:kms', $this->s3->puts[0]['ServerSideEncryption']);
        $this->assertSame('out-bucket', $this->s3->puts[0]['Bucket']);
    }

    public function testNoOverwriteChecksBeforeConverting(): void
    {
        $this->s3->objects['out-bucket/a.pdf'] = 'old';
        $before = count(FakeServer::calls());
        try {
            $this->pc->convertStorage('word-to-pdf', 's3://in-bucket/docs/a.docx', 's3://out-bucket/a.pdf', ['s3Client' => $this->s3, 'overwrite' => false]);
            $this->fail('overwrote');
        } catch (StorageException $e) {
            $this->assertSame('DestinationExists', $e->errorCode);
        }
        $this->assertSame('old', $this->s3->objects['out-bucket/a.pdf']);
        $this->assertCount($before, FakeServer::calls());
    }

    public function testNoOverwriteSendsCondition(): void
    {
        $this->pc->convertStorage('word-to-pdf', 's3://in-bucket/docs/a.docx', 's3://out-bucket/a.pdf', ['s3Client' => $this->s3, 'overwrite' => false]);
        $this->assertSame('*', $this->s3->puts[0]['IfNoneMatch']);
    }

    public function testNoOverwriteFallbackWhenConditionRejected(): void
    {
        $this->s3->rejectCondition = true;
        $this->pc->convertStorage('word-to-pdf', 's3://in-bucket/docs/a.docx', 's3://out-bucket/a.pdf', ['s3Client' => $this->s3, 'overwrite' => false]);
        $this->assertSame('%PDF-1.4', $this->s3->objects['out-bucket/a.pdf']);
        $this->assertArrayNotHasKey('IfNoneMatch', $this->s3->puts[0]);
    }

    public function testMissingAndDenied(): void
    {
        foreach (['s3://in-bucket/nope.docx' => 'NoSuchKey', 's3://denied/a.docx' => 'AccessDenied'] as $src => $code) {
            try {
                $this->pc->convertStorage('word-to-pdf', $src, 's3://out-bucket/a.pdf', ['s3Client' => $this->s3]);
                $this->fail('no error');
            } catch (StorageException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertStringContainsString($src, $e->getMessage());
            }
        }
    }

    public function testSizeCap(): void
    {
        $this->s3->claimedSize = 600 * 1024 * 1024;
        $this->expectExceptionMessage('larger than 500 MB');
        $this->pc->convertStorage('word-to-pdf', 's3://in-bucket/docs/a.docx', 's3://out-bucket/a.pdf', ['s3Client' => $this->s3]);
    }

    public function testBadDestinationFailsBeforeDownload(): void
    {
        $this->expectException(StorageException::class);
        $this->pc->convertStorage('word-to-pdf', 's3://in-bucket/docs/a.docx', 's3://out-bucket/dir/', ['s3Client' => $this->s3]);
    }

    public function testCrossCloudS3ToGcs(): void
    {
        $this->pc->convertStorage('word-to-pdf', 's3://in-bucket/docs/a.docx', 'gs://gcs-out/a.pdf', ['s3Client' => $this->s3, 'gcsClient' => $this->gcs]);
        $this->assertSame('%PDF-1.4', $this->gcs->objects['gcs-out/a.pdf']);
        $this->assertSame('application/pdf', $this->gcs->uploads[0]['metadata']['contentType']);
        $this->assertSame([], $this->s3->puts);
    }

    public function testGcsReadPinsGenerationAndNoOverwrite(): void
    {
        $this->gcs->objects['gcs-in/a.docx'] = 'hi';
        $this->gcs->objects['gcs-in/a.pdf'] = 'old';
        try {
            $this->pc->convertStorage('word-to-pdf', 'gs://gcs-in/a.docx', 'gs://gcs-in/a.pdf', ['gcsClient' => $this->gcs, 'overwrite' => false]);
            $this->fail('overwrote');
        } catch (StorageException $e) {
            $this->assertSame('DestinationExists', $e->errorCode);
        }
        $this->pc->convertStorage('word-to-pdf', 'gs://gcs-in/a.docx', 'gs://gcs-in/b.pdf', ['gcsClient' => $this->gcs, 'overwrite' => false]);
        $this->assertSame('7', $this->gcs->downloadOptions['generation']);
        $this->assertSame(0, $this->gcs->uploads[0]['ifGenerationMatch']);
        try {
            $this->pc->convertStorage('word-to-pdf', 'gs://gcs-in/missing.docx', 'gs://gcs-in/c.pdf', ['gcsClient' => $this->gcs]);
            $this->fail('no error');
        } catch (StorageException $e) {
            $this->assertSame('NotFound', $e->errorCode);
        }
    }

    public function testMissingLibraryMessage(): void
    {
        if (class_exists(\Aws\S3\S3Client::class)) {
            $this->markTestSkipped('aws/aws-sdk-php is installed');
        }
        $this->expectExceptionMessage('composer require aws/aws-sdk-php');
        $this->pc->convertStorage('word-to-pdf', 's3://in-bucket/a.docx', 's3://in-bucket/a.pdf');
    }

    public function testAzureNeedsConfiguration(): void
    {
        putenv('AZURE_STORAGE_CONNECTION_STRING');
        $this->expectExceptionMessage('AZURE_STORAGE_CONNECTION_STRING');
        $this->pc->convertStorage('word-to-pdf', 'az://acct1/cc1/a.docx', 'az://acct1/cc1/a.pdf');
    }

    public function testAzureAccountMismatch(): void
    {
        $client = AzureBlobClient::withAccountKey('otheracct', base64_encode('k'));
        $this->expectExceptionMessage("is for account 'otheracct'");
        $this->pc->convertStorage('word-to-pdf', 'az://acct1/cc1/a.docx', 'az://acct1/cc1/a.pdf', ['azureClient' => $client]);
    }

    public function testAzureConnectionString(): void
    {
        $c = AzureBlobClient::fromConnectionString('DefaultEndpointsProtocol=https;AccountName=acct1;AccountKey=' . base64_encode('k') . ';EndpointSuffix=core.windows.net');
        $this->assertSame('acct1', $c->accountName);
        $this->assertStringNotContainsString(base64_encode('k'), print_r($c, true));
        $this->expectException(StorageException::class);
        AzureBlobClient::fromConnectionString('AccountName=acct1');
    }
}
