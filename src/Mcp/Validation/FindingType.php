<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Validation;

/**
 * The kinds of drift a content validation sweep can report.
 *
 * Each case carries its own severity, so a finding cannot be constructed with a
 * severity that contradicts its type.
 */
enum FindingType: string
{
    /** A blueprint validation rule fails against the stored value. */
    case RuleViolation = 'rule_violation';

    /** A stored value made a fieldtype's rule builder throw. */
    case RuleEngineError = 'rule_engine_error';

    /** A replicator/bard block names a set that is no longer defined. */
    case UnknownSetType = 'unknown_set_type';

    /** A set or grid row stores a key that is no longer a field in its blueprint. */
    case UnknownField = 'unknown_field';

    /** An options-based field stores a value outside the declared options. */
    case InvalidOption = 'invalid_option';

    /** An assets field references a file that no longer exists. */
    case MissingAsset = 'missing_asset';

    /** A navigation item links to an entry that was deleted. */
    case DanglingReference = 'dangling_reference';

    public function severity(): Severity
    {
        return match ($this) {
            // These break at render time or fail the blueprint's own rules.
            self::RuleViolation,
            self::UnknownSetType,
            self::InvalidOption,
            self::MissingAsset,
            self::DanglingReference => Severity::Error,

            // These leave the content renderable but out of step with its schema.
            self::RuleEngineError,
            self::UnknownField => Severity::Warning,
        };
    }
}
