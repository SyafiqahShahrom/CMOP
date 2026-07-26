<?php

namespace App\Domains\Administration\Enums;

enum CmopRole: string
{
    case Analyst = 'analyst';
    case TeamLead = 'team_lead';
    case OpsManager = 'ops_manager';
    case Compliance = 'compliance';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Analyst => 'Operations Analyst',
            self::TeamLead => 'Trade Support Team Lead',
            self::OpsManager => 'Operations Manager',
            self::Compliance => 'Compliance Officer',
            self::Admin => 'Platform Administrator',
        };
    }
}
