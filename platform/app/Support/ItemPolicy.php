<?php

namespace App\Support;

/**
 * Server-side permission rules for every kind of TeamSayo record.
 *
 * Roles: owner, admin (everything) · director (their company, approvals, settings) · manager (their company;
 * sensitive data only for their reports) · employee (reads their company, writes their own records) ·
 * auditor (reads everything, writes nothing).
 */
class ItemPolicy
{
    /** Collections the app may store, and the person each record is about. */
    public const COLLECTIONS = [
        'config', 'orgs', 'people', 'recordTypes', 'rules', 'records', 'meetings', 'actions', 'goals', 'kudos', 'checkins',
        'reviews', 'feedback', 'feedbackRequests', 'att', 'capg', 'ment', 'jweek', 'cer', 'xmeet', 'msess', 'mentees',
        'japp', 'prep', 'media', 'projs',
    ];

    /** Settings-like collections only admins and directors may change. */
    private const SETTINGS = ['config', 'recordTypes', 'rules'];

    /** Collections any member (not auditors) may create and edit within their company. */
    private const OPEN = ['meetings', 'xmeet', 'prep'];

    /** Collections where an employee may create and edit records about themselves. */
    private const SELF = ['records', 'actions', 'kudos', 'att', 'capg', 'ment', 'jweek', 'msess', 'mentees', 'japp', 'media', 'feedback', 'feedbackRequests', 'reviews'];

    public static function subjectOf(string $c, string $key, array $d): ?string
    {
        $f = $d['fields'] ?? [];
        $v = match ($c) {
            'people' => $key,
            'records' => $f['person'] ?? $f['owner'] ?? $f['mentee'] ?? $f['lead'] ?? null,
            'att', 'capg', 'ment', 'jweek' => explode('|', $key, 2)[1] ?? null,
            'actions' => $d['owner'] ?? null,
            'japp', 'mentees' => $d['member'] ?? null,
            'msess' => $d['mentee'] ?? null,
            'kudos' => $d['to'] ?? null,
            'checkins', 'reviews' => $d['person'] ?? null,
            'feedback' => $d['about'] ?? null,
            'feedbackRequests' => $d['to'] ?? null,
            'media' => $d['by'] ?? null,
            'prep' => $d['fac'] ?? null,
            default => null,
        };

        return is_string($v) && $v !== '' ? mb_substr($v, 0, 64) : null;
    }

    public static function companyOf(ItemContext $ctx, string $c, string $key, array $d, ?string $subject): ?int
    {
        if (in_array($c, ['config', 'recordTypes', 'rules'], true)) {
            return null;
        }
        if ($c === 'orgs') {
            return $ctx->companyIdFromKey($key);
        }
        if ($c === 'cer') {
            return $ctx->companyIdFromKey(explode('|', $key, 2)[0]);
        }
        $org = $d['org'] ?? null;
        if (is_string($org) && $org !== '' && ($id = $ctx->companyIdFromKey($org))) {
            return $id;
        }

        return $subject !== null ? ($ctx->personCompany[$subject] ?? null) : null;
    }

    public static function isSensitive(ItemContext $ctx, string $c, array $d): bool
    {
        if (in_array($c, ['att', 'capg'], true)) {
            return true;
        }

        return $c === 'records' && in_array((string) ($d['type'] ?? ''), $ctx->sensitiveTypes, true);
    }

    private static function inScope(ItemContext $ctx, ?int $companyId): bool
    {
        return $ctx->seesAll() || $companyId === null || $companyId === $ctx->membership->company_id;
    }

    public static function canRead(ItemContext $ctx, string $c, array $d, ?int $companyId, ?string $subject): bool
    {
        if (! self::inScope($ctx, $companyId)) {
            return false;
        }
        $leaders = in_array($ctx->role, ['owner', 'admin', 'director', 'auditor'], true);
        if (self::isSensitive($ctx, $c, $d)) {
            return $leaders || $subject === $ctx->me || $ctx->reportsToMe($subject);
        }

        return match ($c) {
            'reviews' => $leaders || $subject === $ctx->me || ($d['manager'] ?? null) === $ctx->me,
            'checkins' => $leaders || $subject === $ctx->me || ($d['manager'] ?? null) === $ctx->me,
            'feedback' => $leaders || ($d['from'] ?? null) === $ctx->me
                || ($subject === $ctx->me && ($ctx->feedbackCount[$ctx->me] ?? 0) >= 3),
            'feedbackRequests' => $leaders || $subject === $ctx->me || ($d['by'] ?? null) === $ctx->me,
            default => true,
        };
    }

    /**
     * May the member create, change or delete this record? Checked against both the stored record ($old) and
     * the proposed one ($new) so a record cannot be moved out of someone's reach.
     */
    public static function canWrite(ItemContext $ctx, string $c, ?array $old, ?array $new, ?int $oldCompany, ?int $newCompany, ?string $oldSubject, ?string $newSubject): bool
    {
        if ($ctx->role === 'auditor') {
            return false;
        }
        foreach ([[$old, $oldCompany], [$new, $newCompany]] as [$d, $co]) {
            if ($d !== null && ! self::inScope($ctx, $co)) {
                return false;
            }
        }
        if ($c === 'orgs') {
            return $ctx->isAdmin();
        }
        if (in_array($c, self::SETTINGS, true)) {
            return in_array($ctx->role, ['owner', 'admin', 'director'], true);
        }
        if ($c === 'people') {
            if ($ctx->isEditor()) {
                return true;
            }

            return $old !== null && $new !== null && $oldSubject === $ctx->me && $newSubject === $ctx->me; // own profile only
        }

        $sensitive = ($old !== null && self::isSensitive($ctx, $c, $old)) || ($new !== null && self::isSensitive($ctx, $c, $new));
        if (in_array($ctx->role, ['owner', 'admin', 'director'], true)) {
            return true;
        }
        if ($ctx->role === 'manager') {
            if (! $sensitive) {
                return true;
            }
            foreach ([$oldSubject, $newSubject] as $s) {
                if ($s !== null && $s !== $ctx->me && ! $ctx->reportsToMe($s)) {
                    return false;
                }
            }

            return true;
        }

        // employee
        if (in_array($c, self::OPEN, true)) {
            return true;
        }
        if (! in_array($c, self::SELF, true)) {
            return false;
        }
        $mine = fn (?array $d, ?string $s) => $d === null || $s === $ctx->me
            || ($c === 'kudos' && ($d['from'] ?? null) === $ctx->me)
            || ($c === 'feedback' && ($d['from'] ?? null) === $ctx->me);

        return $mine($old, $oldSubject) && $mine($new, $newSubject);
    }

    /**
     * Fields an employee may change on records that belong to someone else's process:
     * their own person record (contact details only) and their own review (self assessment only).
     */
    public static function restrictFields(ItemContext $ctx, string $c, ?array $old, array $new): array
    {
        if ($old === null || $ctx->isEditor()) {
            return $new;
        }
        if ($c === 'people') {
            foreach (['email', 'photo'] as $k) {
                if (array_key_exists($k, $new)) {
                    $old[$k] = $new[$k];
                }
            }

            return $old;
        }
        if ($c === 'reviews') {
            $old['self'] = $new['self'] ?? ($old['self'] ?? '');
            if (($old['status'] ?? '') === 'Self review' && ($new['status'] ?? '') === 'Manager review') {
                $old['status'] = 'Manager review';
            }

            return $old;
        }
        if ($c === 'feedbackRequests') {
            $old['done'] = (bool) ($new['done'] ?? ($old['done'] ?? false));

            return $old;
        }

        return $new;
    }
}
