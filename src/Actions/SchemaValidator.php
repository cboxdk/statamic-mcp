<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

use Cboxdk\StatamicMcp\Support\InputBudget;
use Opis\JsonSchema\Validator;

/** Local schemas only. No resolver protocols, files, filters or PHP callbacks. */
final class SchemaValidator
{
    public function __construct(
        private readonly InputBudget $inputBudget = new InputBudget,
        private readonly int $maxInputBytes = 16777216,
    ) {
        if ($maxInputBytes < 1) {
            throw new \InvalidArgumentException('Action input budget must be positive.');
        }
    }

    /** @param array<string, mixed> $schema */
    public static function checkSchema(array $schema): void
    {
        if (($schema['type'] ?? null) !== 'object') {
            throw new \InvalidArgumentException('Action schemas must describe an object.');
        }
        $encoded = json_encode($schema, JSON_THROW_ON_ERROR, 32);
        if (strlen($encoded) > 65536) {
            throw new \InvalidArgumentException('Action schema exceeds its size budget.');
        }
        self::checkKeywords($schema);
        $object = json_decode($encoded, false, 32, JSON_THROW_ON_ERROR);
        if (! $object instanceof \stdClass) {
            throw new \InvalidArgumentException('Invalid schema.');
        }
        (new Validator)->loader()->loadObjectSchema($object);
    }

    /** @param array<array-key, mixed> $schema */
    private static function checkKeywords(array $schema): void
    {
        foreach ($schema as $key => $value) {
            if (in_array($key, ['$ref', '$dynamicRef', '$recursiveRef'], true)
                && (! is_string($value) || ! str_starts_with($value, '#'))) {
                throw new \InvalidArgumentException('External schema references are forbidden.');
            }
            if (in_array($key, ['$filters', '$data', '$vars', '$slots', '$id'], true)) {
                throw new \InvalidArgumentException('Schema extensions and identifiers are forbidden.');
            }
            if (is_array($value)) {
                self::checkKeywords($value);
            } elseif (! is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Schema must contain JSON values only.');
            }
        }
    }

    /** @param array<string, mixed> $value
     * @param  array<string, mixed>  $schema
     */
    public function validate(array $value, array $schema): void
    {
        (new InputBudget(1048576, 20000, 256))->check($value);
        $this->validateJson($value, $schema, 1048576);
    }

    /** @param array<string, mixed> $value
     * @param  array<string, mixed>  $schema
     */
    public function validateInput(array $value, array $schema): void
    {
        $this->inputBudget->check($value);
        $this->validateJson($value, $schema, $this->maxInputBytes);
    }

    /** @param array<string, mixed> $value
     * @param  array<string, mixed>  $schema
     */
    private function validateJson(array $value, array $schema, int $maxBytes): void
    {
        $data = json_encode((object) $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 258);
        if (strlen($data) > $maxBytes) {
            throw new \InvalidArgumentException('Action payload exceeds its size budget.');
        }
        $schemaObject = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        if (! $schemaObject instanceof \stdClass) {
            throw new \InvalidArgumentException('Invalid schema.');
        }
        $result = (new Validator)->validate(json_decode($data, false, 258, JSON_THROW_ON_ERROR), $schemaObject);
        if (! $result->isValid()) {
            // Never echo content, field values or credentials in validation errors.
            throw new \InvalidArgumentException('Action payload does not satisfy its schema.');
        }
    }
}
