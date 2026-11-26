<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools;

use Cboxdk\StatamicMcp\Authorization\InstallationProfile;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorResponse;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ResponseMeta;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\SuccessResponse;
use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Cboxdk\StatamicMcp\Mcp\Support\StacheRepair;
use Cboxdk\StatamicMcp\Mcp\Support\ToolLogger;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Support\InputBudget;
use Cboxdk\StatamicMcp\Support\McpConfig;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Statamic\Auth\PermissionCache;
use Statamic\Exceptions\BlueprintNotFoundException;
use Statamic\Exceptions\FieldtypeNotFoundException;
use Statamic\Facades\Blink;
use Statamic\Statamic;

abstract class BaseStatamicTool extends Tool
{
    use ClearsCaches;

    /**
     * Define the tool's input schema.
     *
     * @return array<string, mixed>
     */
    abstract protected function defineSchema(JsonSchemaContract $schema): array;

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    abstract protected function executeInternal(array $arguments): array;

    /**
     * Discard Statamic's per-request memo cache before each tool call.
     *
     * Blink is scoped to one request. Statamic fills it freely because a web
     * request throws it away at the end — but this server is long-lived, so
     * without this every tool call inherits whatever the last one memoized, and
     * reads answers that were true several calls ago.
     *
     * It is not a theoretical tidy-up. A structured collection's tree is
     * blinked, so the second `create` in a session saw a tree without the
     * first entry and Statamic indexed its URI as null: the entry existed,
     * `findByUri()` could not find it, and the page 404'd. Deleting an entry
     * left blinked term associations behind, so `entriesCount()` kept counting
     * it. The blueprint collision fixed in #52 was the same cache, one layer
     * down.
     *
     * Clearing the Stache also fixed these, which is why the problem stayed
     * hidden until the clear was removed for #53 — but that was a sledgehammer
     * that reset durable stores mid-request and emptied a live site's
     * collection tree. This resets nothing durable: Blink holds only what can
     * be read again, so the cost is a re-read and the gain is that each call
     * starts as a fresh request would.
     *
     * Every store, not only the default one: Statamic also memoizes in named
     * stores (entry-uris, structure-uris, collection-mounts, the blueprint
     * repository's), and Blink::flush() reaches only the default. Those kept a
     * long-lived server on blueprints and URIs from before another process
     * changed them, until it restarted.
     */
    private function startFromAFreshRequestState(): void
    {
        Blink::swap(new \Statamic\Support\Blink);
        app(PermissionCache::class)->clear();
        app(StacheRepair::class)->start();
    }

    /**
     * Define the tool's input schema (v0.6 convention).
     * Delegates to defineSchema() for backward compatibility with existing routers.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchemaContract $schema): array
    {
        return $this->defineSchema($schema);
    }

    /**
     * Define the output schema for this tool's results.
     *
     * Returns the standard MCP response envelope used by all tools.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchemaContract $schema): array
    {
        return [
            'success' => JsonSchema::boolean()->description('Whether the operation succeeded'),
            // A failure carries data: null, and a list result is a JSON array.
            // SDK clients validate failures too and discard what does not match.
            'data' => JsonSchema::union(['object', 'array', 'null'])->description('Operation result data; null on most failures'),
            'meta' => JsonSchema::object()->description('Response metadata (tool, timestamp, versions)'),
            'error' => JsonSchema::object([
                'code' => JsonSchema::string()->enum(array_column(ErrorCode::cases(), 'value'))->required(),
                'message' => JsonSchema::string()->required(),
                'field' => JsonSchema::string(),
                'valid_values' => JsonSchema::array()->items(JsonSchema::string()),
                'hint' => JsonSchema::string(),
            ])->withoutAdditionalProperties()->description('Failure and how to correct it; present when success is false'),
            'warnings' => JsonSchema::array()->description('Warning messages'),
        ];
    }

    /**
     * Handle the tool invocation (Laravel MCP v0.6).
     *
     * Called via container DI: Container::getInstance()->call([$tool, 'handle'])
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = $request->all();
        $result = $this->normalizeFailure($this->execute($arguments));

        // Internal callers and stored v3 results may still use the message list.
        // The protocol exposes one error contract, never two competing shapes.
        unset($result['errors']);
        if (($result['success'] ?? true) === false && is_array($result['data'] ?? null)) {
            unset($result['data']['code']);
        }

        // Any well-formed standard envelope (has success + meta) is returned as
        // structured content regardless of outcome, so failures keep their data
        // payload — a confirmation-required response carries its token in there.
        //
        // A failed envelope still has to set the protocol's isError flag, or
        // clients read the call as successful and only a model reading the JSON
        // body would notice. Response::structured() cannot do both, so failures
        // are assembled from an error Response plus the same structured content.
        if (isset($result['success'], $result['meta'])) {
            if ($result['success'] === false) {
                return Response::make([Response::error($this->encodeEnvelope($result))])
                    ->withStructuredContent($result);
            }

            return Response::structured($result);
        }

        return Response::structured($result);
    }

    /**
     * Old operation records and extensions can return the former error shape.
     * Normalize them without guessing a code from English message text.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    private function normalizeFailure(array $result): array
    {
        if (($result['success'] ?? true) !== false) {
            return $result;
        }

        $error = $result['error'] ?? null;
        if (is_array($error) && is_string($error['code'] ?? null) && ErrorCode::tryFrom($error['code']) !== null && is_string($error['message'] ?? null)) {
            return $result;
        }

        $errors = $result['errors'] ?? null;
        $messages = is_array($errors) ? array_values(array_filter($errors, 'is_string')) : [];
        $message = $messages !== [] ? implode('; ', $messages) : (is_string($error) ? $error : 'The operation failed.');
        $data = $result['data'] ?? null;
        $legacyCode = is_array($data) && is_string($data['code'] ?? null) ? ErrorCode::tryFrom($data['code']) : null;
        if (($legacyCode === null || $legacyCode === ErrorCode::Internal) && ! app()->environment('local', 'testing')) {
            // Old routers stored exception messages as ordinary failures. A
            // replay must not disclose diagnostics the live call now hides.
            $message = 'The operation failed. Check server logs for details.';
        }
        $normalized = $this->createErrorResponse($message, $data, code: $legacyCode ?? ErrorCode::Internal)->toArray();

        return array_replace($normalized, $result, ['error' => $normalized['error']]);
    }

    /**
     * Encode a response envelope the same way Response::structured() does, so the
     * text content of a failure matches the text content of a success.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function encodeEnvelope(array $envelope): string
    {
        try {
            return json_encode(
                $envelope,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (\JsonException) {
            // Unencodable payload — the structured content will carry whatever
            // survives serialization; the text content falls back to the message.
            $error = $envelope['error'] ?? null;
            $first = is_array($error) ? ($error['message'] ?? null) : null;

            return is_string($first) ? $first : 'Unknown error occurred';
        }
    }

    /**
     * Override execute method to add consistent error handling and MCP compliance.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    final public function execute(array $arguments): array
    {
        $this->startFromAFreshRequestState();
        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : 'get';
        $denial = app(InstallationProfile::class)->denial($this->getToolDomain(), $action, $arguments, $this->actionWrites($action));
        if ($denial !== null) {
            return $this->createErrorResponse($denial, code: ErrorCode::Forbidden)->toArray();
        }
        $this->writeEffects = null;
        $this->completedCacheClears = [];

        try {
            $result = $this->runToolCall($arguments);
        } finally {
            // Normally a no-op: the happy path already flushed inside the call.
            // This catches the throwing path, where a write may well have
            // landed before the throw and left its indexes stale.
            $this->flushPendingCacheClears();
            app(StacheRepair::class)->stop();
        }

        $writeMetadata = $this->writeMetadata(($result['success'] ?? false) === true);
        if ($writeMetadata !== []) {
            $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
            $result['meta'] = [...$meta, ...$writeMetadata];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function runToolCall(array $arguments): array
    {
        $toolName = $this->name();
        $startTime = hrtime(true);

        // Propagate correlation ID from middleware if available
        $requestCorrelationId = null;
        if (app()->bound('request')) {
            $raw = request()->attributes->get('mcp_correlation_id');
            $requestCorrelationId = is_string($raw) ? $raw : null;
        }
        $correlationId = $requestCorrelationId ?: Str::uuid()->toString();

        try {
            $arguments = $this->validateAndSanitizeArguments($arguments);

            $result = $this->executeInternal($arguments);
            $standardized = $this->wrapInStandardFormat($result);

            // The response is built, so nothing further reads Statamic and the
            // clear cannot pull the stores out from under this call — which is
            // the whole of #53. Doing it here rather than after the fact also
            // keeps it inside the timing, the audit record and the error
            // handling below: the caller waits for it, so it is part of the
            // call in every sense that is reported.
            $standardized['meta'] = is_array($standardized['meta'] ?? null) ? $standardized['meta'] : [];
            $cacheResults = $this->flushPendingCacheClears();
            if ($cacheResults !== []) {
                $standardized['meta']['cache'] = $cacheResults;
            }

            $duration = (hrtime(true) - $startTime) / 1_000_000_000;

            // Execution has finished: a slow successful write is still successful.
            // This is an observation, not a claim that PHP interrupted the handler.
            $timeout = (float) McpConfig::int('security.tool_timeout_seconds', 30);
            try {
                if ($duration > $timeout) {
                    $standardized['meta']['duration_budget_exceeded'] = true;
                    ToolLogger::performanceWarning($toolName, "Tool execution exceeded {$timeout}s budget", $duration);
                }

                // Routers report a refused write — blueprint validation, a missing
                // entry, a denied resource — as an error envelope rather than by
                // throwing, so the audit status has to come from the envelope. A
                // save rejected on "The Entry field must be an array" used to be
                // logged as success, with the refusal only in response_summary.
                $status = ($standardized['success'] ?? true) === false ? 'error' : 'success';

                ToolLogger::logToolCall(
                    $toolName, $arguments, $status, $duration * 1000,
                    action: $this->extractAction($arguments),
                    result: $standardized,
                    correlationId: $correlationId,
                );

                if ($duration > 5.0) {
                    ToolLogger::performanceWarning($toolName, 'Tool execution exceeded 5 seconds', $duration);
                }
            } catch (\Throwable) {
                // The handler has finished. A logging failure cannot undo its effect.
                $standardized['meta']['audit_status'] = 'unavailable';
            }

            return $standardized;
        } catch (\Throwable $e) {
            $duration = (hrtime(true) - $startTime) / 1_000_000_000;
            $status = ($e instanceof \InvalidArgumentException) ? 'validation_error' : 'error';

            try {
                ToolLogger::logToolCall(
                    $toolName, $arguments, $status, $duration * 1000,
                    action: $this->extractAction($arguments),
                    error: $e,
                    correlationId: $correlationId,
                );
            } catch (\Throwable) {
                // Preserve the original execution error if audit is unavailable.
            }

            $prefix = match (true) {
                $e instanceof \TypeError => 'Type error',
                $e instanceof \InvalidArgumentException => 'Invalid argument',
                $e instanceof \Error => 'Fatal error',
                default => 'Exception',
            };

            try {
                Log::error("MCP tool error in {$toolName}", [
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'correlation_id' => $correlationId,
                ]);
            } catch (\Throwable) {
                // A failing fallback logger must not replace the original outcome.
            }

            $rawMessage = $this->sanitizeErrorMessage($e->getMessage());
            $errorMessage = ($this->isClientSafeException($e) || app()->environment('local', 'testing'))
                ? "{$prefix} in {$toolName}: {$rawMessage}"
                : "{$prefix} in {$toolName}: An error occurred. Check server logs for details.";

            return $this->createSafeErrorResponse($errorMessage, $correlationId, $e);
        }
    }

    /**
     * Extract the action parameter from arguments (used by routers).
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function extractAction(array $arguments): ?string
    {
        $action = $arguments['action'] ?? null;

        return is_string($action) ? $action : null;
    }

    /**
     * Wrap result in standardized format if needed.
     *
     * Contract: If executeInternal() returns an array with 'success' and 'meta' keys,
     * it's assumed to be already wrapped (e.g., from createSuccessResponse()->toArray()).
     * Otherwise, the raw array is wrapped automatically in a SuccessResponse envelope.
     *
     * Also validates response size to prevent LLM token overflow.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    private function wrapInStandardFormat(array $result): array
    {
        $result = $this->normalizeFailure($result);
        if (isset($result['success']) && isset($result['meta'])) {
            $wrapped = $result;
        } else {
            $wrapped = $this->createSuccessResponse($result)->toArray();
        }

        // Guard against oversized responses that would overflow LLM token budgets.
        // Configurable because the ceiling that matters is the client's context
        // window, which differs per client and grows over time.
        $configuredMax = config('statamic.mcp.security.max_response_size', 100000);
        $maxBytes = is_numeric($configuredMax) ? (int) $configuredMax : 100000;

        $encoded = json_encode($wrapped);
        if ($encoded !== false && $maxBytes > 0 && strlen($encoded) > $maxBytes) {
            // A write that landed is a success, however large its echo. Calling
            // it an error made agents retry, and the retry wrote it twice.
            if ($this->writeEffectsSoFar() === 'committed' && ($wrapped['success'] ?? false) === true) {
                $wrapped['data'] = [
                    ...$this->identifyingFields(is_array($wrapped['data'] ?? null) ? $wrapped['data'] : []),
                    'response_truncated' => true,
                    'note' => 'The write was saved. Its full response (' . round(strlen($encoded) / 1024) . 'KB) is over the response limit; read the record back to see it.',
                ];

                return $wrapped;
            }

            return $this->createErrorResponse(
                'Response too large (' . round(strlen($encoded) / 1024) . 'KB). Use pagination or filters to reduce the result set.',
                ['response_size_bytes' => strlen($encoded)],
                code: ErrorCode::TooLarge,
            )->toArray();
        }

        return $wrapped;
    }

    /**
     * What names a written record, without its content: enough to read it
     * back or to pass its version to the next write.
     *
     * @param  array<mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function identifyingFields(array $data, bool $recordLevel = false): array
    {
        $keep = ['id', 'slug', 'handle', 'title', 'version', 'url', 'uri', 'permalink', 'collection', 'taxonomy',
            'container', 'path', 'site', 'locale', 'published', 'status', 'parent', 'created', 'updated', 'deleted'];
        $slim = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            // One level down is the record itself ({entry: {...}}); its field
            // values below that are content, which is what was too large.
            if (! $recordLevel && is_array($value) && ! array_is_list($value)) {
                $nested = $this->identifyingFields($value, true);
                if ($nested !== []) {
                    $slim[$key] = $nested;
                }
            } elseif (in_array($key, $keep, true) && (is_scalar($value) || $value === null)) {
                $slim[$key] = $value;
            }
        }

        return $slim;
    }

    /**
     * Get boolean argument with default value.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function getBooleanArgument(array $arguments, string $key, bool $default = false): bool
    {
        return (bool) ($arguments[$key] ?? $default);
    }

    /**
     * Get string argument with default value.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function getStringArgument(array $arguments, string $key, string $default = ''): string
    {
        $value = $arguments[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Get integer argument with validation.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function getIntegerArgument(array $arguments, string $key, int $default = 0, int $min = 0, ?int $max = null): int
    {
        $raw = $arguments[$key] ?? $default;
        $value = is_scalar($raw) ? (int) $raw : $default;

        if ($value < $min) {
            $value = $min;
        }

        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $value;
    }

    /** @var array{statamic: string, laravel: string}|null */
    private ?array $versionCache = null;

    /**
     * @return array{statamic: string, laravel: string}
     */
    private function getCachedVersions(): array
    {
        if ($this->versionCache === null) {
            $this->versionCache = [
                'statamic' => Statamic::version() ?? 'unknown',
                'laravel' => app()->version(),
            ];
        }

        return $this->versionCache;
    }

    /**
     * Determine if this tool should be registered.
     *
     * Checks the per-domain tool configuration. Tools whose domain
     * is disabled in config will not be exposed to MCP clients.
     */
    public function shouldRegister(): bool
    {
        $domain = $this->getToolDomain();

        if ($domain === null) {
            return true;
        }

        /** @var bool $enabled */
        $enabled = config("statamic.mcp.tools.{$domain}.enabled", true);

        return $enabled;
    }

    /**
     * Whether the action changes anything. Tools that only read override it;
     * routers answer from their declarations. Unknown counts as a write.
     */
    protected function actionWrites(string $action): bool
    {
        return true;
    }

    /**
     * Get the domain key for config lookup.
     *
     * Override in subclasses to map to config keys.
     * Returns null if tool has no domain toggle.
     */
    protected function getToolDomain(): ?string
    {
        return null;
    }

    /**
     * Create response metadata.
     */
    private function createResponseMeta(): ResponseMeta
    {
        $exposeVersions = (bool) config('statamic.mcp.security.expose_versions', false);

        if ($exposeVersions) {
            $versions = $this->getCachedVersions();

            return new ResponseMeta(
                tool: $this->name(),
                timestamp: now()->toISOString() ?? date('c'),
                statamic_version: $versions['statamic'],
                laravel_version: $versions['laravel'],
            );
        }

        return new ResponseMeta(
            tool: $this->name(),
            timestamp: now()->toISOString() ?? date('c'),
        );
    }

    /**
     * Get Statamic version.
     */
    protected function getStatamicVersion(): string
    {
        return $this->getCachedVersions()['statamic'];
    }

    /**
     * Create success response.
     *
     * @param  array<int, string>  $warnings
     */
    protected function createSuccessResponse(mixed $data, array $warnings = []): SuccessResponse
    {
        return new SuccessResponse(
            data: $data,
            meta: $this->createResponseMeta(),
            warnings: $warnings
        );
    }

    /**
     * Create error response.
     *
     * @param  string|array<int, string>  $errors
     * @param  array<int, string>  $warnings
     * @param  list<string>|null  $validValues
     */
    protected function createErrorResponse(string|array $errors, mixed $data = null, array $warnings = [], ?ErrorCode $code = null, ?string $field = null, ?array $validValues = null, ?string $hint = null): ErrorResponse
    {
        // The former data.code API remains usable by installed extensions;
        // arbitrary strings cannot become a new protocol error code.
        $legacyCode = is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : null;
        $code ??= $legacyCode === null ? ErrorCode::Validation : (ErrorCode::tryFrom($legacyCode) ?? ErrorCode::Internal);

        return new ErrorResponse(
            errors: $errors,
            meta: $this->createResponseMeta(),
            data: $data,
            warnings: $warnings,
            code: $code,
            field: $field,
            validValues: $validValues,
            hint: $hint,
        );
    }

    /**
     * Basic argument validation (permissive for Claude compatibility).
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function validateAndSanitizeArguments(array $arguments): array
    {
        app(InputBudget::class)->check($arguments);

        return $arguments;
    }

    /**
     * Determine if an exception's message is curated and safe to expose to clients
     * even outside local/testing environments.
     *
     * The default production handler genericises messages to avoid leaking internals.
     * For these specific exception classes the message content is authored by us
     * (or by trusted upstream code) with field paths / validation rules, so the
     * message is more valuable than the generic placeholder.
     */
    protected function isClientSafeException(\Throwable $e): bool
    {
        return $e instanceof FieldFormatException
            || $e instanceof ToolFailure
            || $e instanceof ValidationException
            || $e instanceof FieldtypeNotFoundException
            || $e instanceof BlueprintNotFoundException;
    }

    /**
     * Sanitize error messages (minimal sanitization for Claude compatibility).
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        $message = str_replace("\x00", '', $message);

        if (strlen($message) > 1000) {
            $message = substr($message, 0, 997) . '...';
        }

        return $message;
    }

    /**
     * Create safe error response that prevents information disclosure.
     *
     * @return array<string, mixed>
     */
    protected function createSafeErrorResponse(string $message, string $correlationId, ?\Throwable $exception = null): array
    {
        $field = $exception instanceof ToolFailure ? $exception->field : null;
        if ($exception instanceof ValidationException) {
            $field = array_key_first($exception->errors());
            $message = $this->sanitizeErrorMessage(implode('; ', array_merge(...array_values($exception->errors()))));
        }
        $code = match (true) {
            $exception instanceof ToolFailure => $exception->errorCode,
            $exception instanceof AuthorizationException => ErrorCode::Forbidden,
            $exception instanceof BlueprintNotFoundException, $exception instanceof FieldtypeNotFoundException => ErrorCode::NotFound,
            $exception instanceof ValidationException, $exception instanceof FieldFormatException, $exception instanceof \InvalidArgumentException => ErrorCode::Validation,
            default => ErrorCode::Internal,
        };
        $response = $this->createErrorResponse($message, code: $code, field: $field === null ? null : (string) $field)->toArray();
        $response['meta'] = [...$this->createResponseMeta()->jsonSerialize(), 'correlation_id' => $correlationId];

        if (app()->environment('local') && $exception) {
            $response['debug'] = [
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
                'type' => get_class($exception),
            ];
        }

        return $response;
    }
}
