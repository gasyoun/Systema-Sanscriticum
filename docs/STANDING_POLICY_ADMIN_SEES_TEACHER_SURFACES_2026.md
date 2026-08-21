# Standing policy — admins see teacher surfaces (H3219)

_Created: 21-08-2026 · Last updated: 21-08-2026_

## Rule

Admin-like staff (`admin`, `super_admin`) see every teacher surface. There are
two ways, and they are not the same:

1. **Overlay** — `RoleGate::seesTeacherSurfaces()`. The session stays the
   admin. Hindi playlist preview, draft drills, teacher guide, load, homework
   review: open without impersonation. New teacher-only pages use this gate
   (or `isAdminLike()`), not a bare `isTeacher()`.
2. **View as that teacher** — impersonation `MODE_TEACHER` (H1947). Flag
   `STAFF_IMPERSONATION`, **super_admin only**. Session becomes that teacher
   (Kostina, or any `role=teacher`). Super-admin bypass is gone for the
   duration. Money writes stay blocked.

Ordinary `admin` cannot start impersonation. Promote the owner to
`super_admin` when they need «войти как».

## Salary

A teacher with a linked `teachers` card sees **their own** calculation
(`RoleGate::seesOwnSalary()`, navigation **«Моя зарплата»**). They cannot
record a payout, issue an advance, close a month, or export the school sheet.

School-wide salary / payout / mutual-settlement tables stay
`RoleGate::accounting()` (accountant + super_admin). Impersonating a teacher
shows that teacher's own row, not the ledger.

## Do not

- Grant overlay by flipping `users.role` to `teacher`.
- Let ordinary `admin` impersonate.
- Screenshot live salary amounts into the public teacher guide.

Code: [`app/Support/RoleGate.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/RoleGate.php)
(`seesTeacherSurfaces`, `seesOwnSalary`, `ownTeacherId`).
Impersonation: [`app/Support/Impersonation.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/Impersonation.php).

_Dr. Mārcis Gasūns_
