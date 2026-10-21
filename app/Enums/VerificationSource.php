<?php

namespace App\Enums;

enum VerificationSource: string
{
    case Customer = 'customer';
    case ResponsibleDepartment = 'responsible_department';
}
