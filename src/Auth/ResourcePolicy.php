<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

class ResourcePolicy
{
    /**
     * Check if a specific resource is accessible for the given mode.
     */
    public function canAccess(string $domain, string $resource, string $mode): bool
    {
        /** @var array<int, string>|null $patterns */
        $patterns = config("statamic.mcp.tools.{$domain}.resources.{$mode}");

        // Unconfigured domain/mode defaults to allow all
        if ($patterns === null) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $resource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the list of denied fields for a domain.
     *
     * @return array<int, string>
     */
    public function getDeniedFields(string $domain): array
    {
        /** @var array<int, string> $fields */
        $fields = config("statamic.mcp.tools.{$domain}.denied_fields", []);

        return $fields;
    }

    /**
     * Recursively strip denied fields from data.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    public function filterFields(string $domain, array $data): array
    {
        $deniedFields = $this->getDeniedFields($domain);

        if ($deniedFields === []) {
            return $data;
        }

        return $this->recursiveFilter($data, $deniedFields);
    }

    /**
     * Recursively remove denied keys from an array structure.
     *
     * @param  array<mixed>  $data
     * @param  array<int, string>  $deniedFields
     *
     * @return array<mixed>
     */
    private function recursiveFilter(array $data, array $deniedFields): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            // Strip denied string keys
            if (is_string($key) && in_array($key, $deniedFields, true)) {
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->recursiveFilter($value, $deniedFields);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
