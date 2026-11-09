<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http\Validation;

use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use JsonException;

/** Strict bounded JSON object parsing and field validation for future state-changing endpoints. */
final class JsonInput
{
    /**
     * Validation schema: ['required'=>['name'], 'properties'=>['name'=>['type'=>'string', 'minLength'=>1]], 'additionalProperties'=>false].
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    public static function parseAndValidate(HttpRequest $request, array $schema): array
    {
        if ($request->bodyTruncated || strlen($request->body) > HttpRequest::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Request body too large.', 'payload_too_large');
        }
        $contentType = trim(explode(';', strtolower($request->header('Content-Type', '')), 2)[0]);
        if ($contentType !== 'application/json' && !preg_match('~^application/[a-z0-9.!#$&^_+-]+\+json$~D', $contentType)) {
            throw new HttpException(415, 'Content-Type must be application/json.', 'unsupported_media_type');
        }
        if (trim($request->body) === '') {
            throw new HttpException(400, 'Missing JSON request body.', 'invalid_json');
        }
        try {
            $decoded = json_decode($request->body, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Malformed JSON request body.', 'invalid_json');
        }
        if (!$decoded instanceof \stdClass) {
            throw new HttpException(422, 'JSON root must be an object.', 'validation_failed', errors: [['field' => '$', 'message' => 'Expected object.']]);
        }
        /** @var array<string,mixed> $data */
        $data = json_decode($request->body, true, 64, JSON_THROW_ON_ERROR);
        $errors = [];
        $properties = $schema['properties'] ?? [];
        foreach (($schema['required'] ?? []) as $field) {
            if (!array_key_exists($field, $data)) $errors[] = ['field' => $field, 'message' => 'Required field.'];
        }
        foreach ($data as $field => $value) {
            if (!array_key_exists($field, $properties)) {
                if (($schema['additionalProperties'] ?? false) === false) $errors[] = ['field' => $field, 'message' => 'Unknown field.'];
                continue;
            }
            $rule = $properties[$field];
            $type = $rule['type'] ?? null;
            $valid = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && !array_is_list($value),
                'null' => $value === null,
                default => true,
            };
            if (!$valid) {
                $errors[] = ['field' => $field, 'message' => 'Invalid type.'];
                continue;
            }
            if (is_string($value)) {
                $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value);
                if (isset($rule['minLength']) && $length < $rule['minLength']) $errors[] = ['field' => $field, 'message' => 'Value too short.'];
                if (isset($rule['maxLength']) && $length > $rule['maxLength']) $errors[] = ['field' => $field, 'message' => 'Value too long.'];
                if (($rule['format'] ?? '') === 'uuid' && !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $value)) $errors[] = ['field' => $field, 'message' => 'Invalid UUID.'];
            }
            if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) $errors[] = ['field' => $field, 'message' => 'Value not permitted.'];
        }
        if ($errors) throw new HttpException(422, 'Request validation failed.', 'validation_failed', errors: $errors);
        return $data;
    }
}
