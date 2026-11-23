<?php

namespace App\Enums;

/** The `type` of a credit_transactions row. */
enum LedgerEntryType: string
{
    case Purchase = 'purchase';
    case Welcome = 'welcome';
    case Hold = 'hold';
    case HoldRelease = 'hold_release';
    case Charge = 'charge';
}
