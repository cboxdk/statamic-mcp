<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

/** A local operator's report; never an automatic proof of a CMS outcome. */
final readonly class OperationReconciliation
{
    public function __construct(
        public string $operationId,
        public string $bindingDigest,
        public int $previousVersion,
        public string $reportedBy,
        public string $outcome,
        public string $evidence,
        public int $recordedAt,
        public int $retainUntil,
    ) {
        if (! preg_match('/^[a-f0-9]{64}$/D', $operationId) || ! preg_match('/^[a-f0-9]{64}$/D', $bindingDigest)
            || $previousVersion < 1 || trim($reportedBy) === '' || strlen($reportedBy) > 255
            || ! in_array($outcome, ['no_effect', 'committed', 'partial'], true)
            || trim($evidence) === '' || strlen($evidence) > 12000 || $recordedAt < 1 || $retainUntil <= $recordedAt) {
            throw new \InvalidArgumentException('Invalid reconciliation report.');
        }
    }

    public function toJson(): string
    {
        $json = json_encode(['schema' => 1, 'operation_id' => $this->operationId, 'binding' => $this->bindingDigest,
            'previous_version' => $this->previousVersion, 'reported_by' => $this->reportedBy,
            'outcome' => $this->outcome, 'evidence' => $this->evidence, 'recorded_at' => $this->recordedAt, 'retain_until' => $this->retainUntil],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 16000) {
            throw new \UnexpectedValueException('Reconciliation exceeds its storage budget.');
        }

        return $json;
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 16000) {
            throw new \UnexpectedValueException('Reconciliation exceeds its storage budget.');
        }
        $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['schema'] ?? null) !== 1
            || ! is_int($data['previous_version'] ?? null) || ! is_int($data['recorded_at'] ?? null) || ! is_int($data['retain_until'] ?? null)) {
            throw new \UnexpectedValueException('Invalid reconciliation report.');
        }
        $strings = [];
        foreach (['operation_id', 'binding', 'reported_by', 'outcome', 'evidence'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                throw new \UnexpectedValueException('Invalid reconciliation report.');
            }
            $strings[$key] = $data[$key];
        }

        return new self($strings['operation_id'], $strings['binding'], $data['previous_version'],
            $strings['reported_by'], $strings['outcome'], $strings['evidence'], $data['recorded_at'], $data['retain_until']);
    }
}
