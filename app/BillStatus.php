<?php

namespace App;

/**
 * The statuses of a Tax Invoice compare line and of a bill, best to worst. A bill has the worst status of its lines.
 * Only a waiting PDF can be fixed by the fix button, and only a bill that matches can be printed.
 */
final class BillStatus
{
    public const MATCH = 'MATCH';
    public const PENDING_FIX = 'PENDING_FIX';
    public const PENDING_ASN = 'PENDING_ASN';
    public const MISMATCH = 'MISMATCH';

    /** code => what the user reads, rank (0 best), whether the fix button fixes it, whether it prints; best to worst */
    private const ALL = [
        self::MATCH => ['label' => 'สมบูรณ์', 'rank' => 0, 'can_fix' => false, 'can_print' => true],
        self::PENDING_FIX => ['label' => 'รอแก้ไข PDF', 'rank' => 1, 'can_fix' => true, 'can_print' => false],
        self::PENDING_ASN => ['label' => 'รอแก้ไข ASN', 'rank' => 2, 'can_fix' => false, 'can_print' => false],
        self::MISMATCH => ['label' => 'ผิดพลาด', 'rank' => 3, 'can_fix' => false, 'can_print' => false],
    ];

    /**
     * @return string[] best to worst
     */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(string $code): string
    {
        return self::ALL[$code]['label'];
    }

    public static function rank(string $code): int
    {
        return self::ALL[$code]['rank'];
    }

    public static function canFix(string $code): bool
    {
        return self::ALL[$code]['can_fix'];
    }

    public static function canPrint(string $code): bool
    {
        return self::ALL[$code]['can_print'];
    }

    /**
     * @param string[] $codes at least one
     */
    public static function worst(array $codes): string
    {
        $worst = self::MATCH;
        foreach ($codes as $code) {
            if (self::rank($code) > self::rank($worst)) {
                $worst = $code;
            }
        }
        return $worst;
    }
}
