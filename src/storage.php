
<?php
// S3 storage for capsule attachments.

function storage_s3(): \Aws\S3\S3Client
{
    static $client = null;

    if ($client === null) {
        $client = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => config('AWS_REGION', 'eu-central-1'),
        ]);
    }

    return $client;
}

function storage_bucket(): string
{
    $bucket = config('S3_BUCKET', '');
    if ($bucket === '') {
        throw new RuntimeException('S3_BUCKET is not configured.');
    }
    return $bucket;
}

function storage_save(array $uploadedFile, string $extension): string
{
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

    $mime = mime_content_type($uploadedFile['tmp_name'])
        ?: 'application/octet-stream';

    storage_s3()->putObject([
        'Bucket' => storage_bucket(),
        'Key' => 'uploads/' . $storedName,
        'SourceFile' => $uploadedFile['tmp_name'],
        'ContentType' => $mime,
    ]);

    return $storedName;
}

function storage_delete(?string $storedName): void
{
    if ($storedName === null || $storedName === '') {
        return;
    }

    storage_s3()->deleteObject([
        'Bucket' => storage_bucket(),
        'Key' => 'uploads/' . basename($storedName),
    ]);
}

function storage_stream(
    string $storedName,
    string $mime,
    string $downloadName,
    string $disposition
): void {
    $disposition = $disposition === 'attachment'
        ? 'attachment'
        : 'inline';

    $downloadName = str_replace(
        ["\r", "\n", '/', '\\'],
        '_',
        $downloadName
    );

    $asciiName = preg_replace(
        '/[^A-Za-z0-9._ -]/',
        '_',
        $downloadName
    );

    $asciiName = trim($asciiName, '. ');
    if ($asciiName === '') {
        $asciiName = 'download';
    }

    $contentDisposition = sprintf(
        '%s; filename="%s"; filename*=UTF-8\'\'%s',
        $disposition,
        $asciiName,
        rawurlencode($downloadName)
    );

    $s3 = storage_s3();

    $command = $s3->getCommand('GetObject', [
        'Bucket' => storage_bucket(),
        'Key' => 'uploads/' . basename($storedName),
        'ResponseContentType' => $mime,
        'ResponseContentDisposition' => $contentDisposition,
    ]);

    $request = $s3->createPresignedRequest(
        $command,
        '+5 minutes'
    );

    header('Location: ' . (string) $request->getUri(), true, 302);
    exit;
}
