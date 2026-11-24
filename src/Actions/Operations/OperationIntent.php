<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;

/** Encrypted recovery metadata. No credentials, raw input or result content. */
final readonly class OperationIntent
{
    public function __construct(
        public string $operationId,
        public string $bindingDigest,
        public string $actorId,
        public string $grantId,
        public string $actionId,
        public int $actionVersion,
        public string $actionFingerprint,
        public ActionEffect $effect,
        public string $site,
        public string $profile,
        public string $transport,
        public string $inputDigest,
        public int $preparedAt,
    ) {
        foreach ([$operationId, $bindingDigest, $actionFingerprint, $inputDigest] as $digest) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                throw new \InvalidArgumentException('Invalid operation intent digest.');
            }
        }
        foreach ([$actorId, $grantId, $site] as $identity) {
            if ($identity === '' || strlen($identity) > 255) {
                throw new \InvalidArgumentException('Invalid operation intent identity.');
            }
        }
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*\/[a-z][a-z0-9_-]{0,63}$/D', $actionId)
            || strlen($actionId) > 255 || $actionVersion < 1 || $preparedAt < 1
            || $effect === ActionEffect::Read
            || ! in_array($profile, ['editor', 'operator'], true)
            || ! in_array($transport, ['http', 'stdio'], true)) {
            throw new \InvalidArgumentException('Invalid operation intent.');
        }
    }

    public static function capture(string $id, string $binding, string $inputDigest, ActionDefinition $action, ExecutionContext $context): self
    {
        return new self($id, $binding, $context->actorId, $context->grantId, $action->id(),
            $action->version, $action->fingerprint(), $action->effect, $context->site,
            $context->profile, $context->transport, $inputDigest, now()->getTimestamp());
    }

    /**
     * The intent of one built-in tool call. The binding covers actor, grant,
     * action, site, profile, transport and the canonical input, so the same
     * call by the same actor through the same grant binds the same way.
     *
     * @param  array<string, mixed>  $input
     */
    public static function forToolCall(string $operationId, string $actorId, string $grantId, string $domain, string $action,
        ActionEffect $effect, string $site, string $profile, string $transport, array $input): self
    {
        $canonical = json_encode(self::canonical($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $domain = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($domain)), '-') ?: 'tool';
        $actionId = 'statamic-mcp/' . $domain . '/' . $action;

        return new self($operationId,
            hash('sha256', implode("\0", [$actorId, $grantId, $actionId, $site, $profile, $transport, $canonical])),
            $actorId, $grantId, $actionId, 1, hash('sha256', $actionId), $effect, $site, $profile, $transport,
            hash('sha256', $canonical), now()->getTimestamp());
    }

    /**
     * @param  array<array-key, mixed>  $value
     *
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::canonical($child);
            }
        }

        return $value;
    }

    public function toJson(): string
    {
        return json_encode([
            'schema' => 1, 'operation_id' => $this->operationId, 'binding' => $this->bindingDigest,
            'actor_id' => $this->actorId, 'grant_id' => $this->grantId, 'action_id' => $this->actionId,
            'action_version' => $this->actionVersion, 'action_fingerprint' => $this->actionFingerprint,
            'effect' => $this->effect->value, 'site' => $this->site, 'profile' => $this->profile,
            'transport' => $this->transport, 'input_digest' => $this->inputDigest, 'prepared_at' => $this->preparedAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 8192) {
            throw new \UnexpectedValueException('Operation intent exceeds its storage budget.');
        }
        $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['schema'] ?? null) !== 1
            || ! is_int($data['action_version'] ?? null) || ! is_int($data['prepared_at'] ?? null)) {
            throw new \UnexpectedValueException('Invalid operation intent.');
        }
        $strings = [];
        foreach (['operation_id', 'binding', 'actor_id', 'grant_id', 'action_id', 'action_fingerprint',
            'effect', 'site', 'profile', 'transport', 'input_digest'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                throw new \UnexpectedValueException('Invalid operation intent.');
            }
            $strings[$key] = $data[$key];
        }

        return new self($strings['operation_id'], $strings['binding'], $strings['actor_id'], $strings['grant_id'],
            $strings['action_id'], $data['action_version'], $strings['action_fingerprint'], ActionEffect::from($strings['effect']),
            $strings['site'], $strings['profile'], $strings['transport'], $strings['input_digest'], $data['prepared_at']);
    }
}
