<?php

namespace Rider\Core;

final class Request
{
    public string $method;
    public string $path;
    /** @var array<string,mixed> */
    public array $query;
    /** @var array<string,mixed> */
    public array $body;
    /** @var array<string,mixed> */
    public array $params = [];
    /** @var array<string,mixed>|null */
    public ?array $user = null;

    public function __construct(string $method, string $path, array $query, array $body)
    {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $rawBody = file_get_contents('php://input') ?: '';
            $decoded = json_decode($rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        } else {
            // Native HTML form submissions (application/x-www-form-urlencoded
            // or multipart/form-data, e.g. the signup/login pages) — PHP
            // already parses these into $_POST; php://input is empty/unusable
            // for multipart bodies once $_POST has consumed the stream.
            $body = $_POST;
        }

        return new self($method, $path, $_GET, $body);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }
}
