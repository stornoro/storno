<?php

namespace App\Enum;

enum OrganizationRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case ACCOUNTANT = 'accountant';
    case EMPLOYEE = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner',
            self::ADMIN => 'Administrator',
            self::ACCOUNTANT => 'Accountant',
            self::EMPLOYEE => 'Employee',
        };
    }
}
