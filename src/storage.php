<?php
require_once ROOT_DIR . "/vendor/autoload.php";
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

function s3_client(): S3Client
{
    static $client = null;
    if ($client === null) {
        $client = new S3Client([
            "version" => "latest",
            "region" => config("AWS_REGION", "eu-central-1"),
        ]);
    }
    return $client;
}

function s3_bucket(): string
{
    $bucket = config("S3_BUCKET", "");
    if ($bucket === "") {
        throw new RuntimeException("S3_BUCKET is not configured.");
    }
    return $bucket;
}

// Save an uploaded file ($_FILES['...'] entry) to S3 and return its stored name, e.g. "3f9a...e1.png".
function storage_save(array $uploadedFile, string $extension): string
{
    $storedName = bin2hex(random_bytes(16)) . "." . $extension;
    $key = "uploads/" . $storedName;

    $mime =
        mime_content_type($uploadedFile["tmp_name"]) ?:
        "application/octet-stream";

    try {
        s3_client()->putObject([
            "Bucket" => s3_bucket(),
            "Key" => $key,
            "SourceFile" => $uploadedFile["tmp_name"],
            "ContentType" => $mime,
        ]);
    } catch (AwsException $e) {
        throw new RuntimeException(
            "Could not upload file to S3: " . $e->getMessage(),
            0,
            $e,
        );
    }

    return $storedName;
}

function storage_delete(?string $storedName): void
{
    if ($storedName === null || $storedName === "") {
        return;
    }

    // basename() защищает от path traversal
    $key = "uploads/" . basename($storedName);

    try {
        s3_client()->deleteObject([
            "Bucket" => s3_bucket(),
            "Key" => $key,
        ]);
    } catch (AwsException $e) {
        // Игнорируем ошибку, если объекта уже нет или логируем при необходимости
    }
}

// Redirect browser (302) to a 5-minute presigned S3 URL.
function storage_stream(
    string $storedName,
    string $mime,
    string $downloadName,
    string $disposition,
): void {
    $key = "uploads/" . basename($storedName);
    $asciiName = preg_replace("/[^A-Za-z0-9._ -]/", "_", $downloadName);

    $contentDisposition = sprintf(
        '%s; filename="%s"; filename*=UTF-8\'\'%s',
        $disposition,
        $asciiName,
        rawurlencode($downloadName),
    );

    $client = s3_client();
    $cmd = $client->getCommand("GetObject", [
        "Bucket" => s3_bucket(),
        "Key" => $key,
        "ResponseContentType" => $mime,
        "ResponseContentDisposition" => $contentDisposition,
    ]);

    $request = $client->createPresignedRequest($cmd, "+5 minutes");
    $presignedUrl = (string) $request->getUri();

    header("Location: " . $presignedUrl, true, 302);
    exit();
}
