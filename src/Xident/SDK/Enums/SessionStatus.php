<?php

declare(strict_types=1);

namespace Xident\SDK\Enums;

/** Verification session lifecycle states. */
enum SessionStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Claimed = 'claimed';

    /** Whether the session has reached a terminal state. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Canceled, self::Claimed => true,
            default => false,
        };
    }
}
