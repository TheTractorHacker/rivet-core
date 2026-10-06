<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/** @api */
enum Status: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case NotApplicable = 'na';
    case Error = 'error';

    /** Score contribution; null means "leave out of the score". */
    public function weight(): ?float
    {
        return match ($this) {
            self::Pass => 1.0,
            self::Warn => 0.5,
            self::Fail, self::Error => 0.0,
            self::NotApplicable => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::Warn => 'Needs attention',
            self::Fail => 'Fail',
            self::NotApplicable => 'Not applicable',
            self::Error => 'Could not check',
        };
    }
}
