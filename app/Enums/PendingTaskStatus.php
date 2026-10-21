<?php

namespace App\Enums;

enum PendingTaskStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
}
