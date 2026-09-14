# CENSUS_STUDENT_LOGGED_IN_MANUALS_22-08-2026.meta.md

_Created: 22-08-2026 · Last updated: 28-08-2026_

Companion to [CENSUS_STUDENT_LOGGED_IN_MANUALS_22-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CENSUS_STUDENT_LOGGED_IN_MANUALS_22-08-2026.md).

- **Why:** Chat asked which student manuals exist after login; the 28-08 fill of H3281 required guest HTTP plus catalog row ids against the H3243 `/admin/documentation` seeder.
- **Refresh:** After adding a student `product_docs` row, changing a destination `auth` group, or a new `/help/*` route inside the `auth` group in [routes/web.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php). Re-GET the URLs; do not assume HEAD equals GET.
- **Not:** Staff maps (`student-manual.md`), AdminDocument, or rewriting the manuals.

_Dr. Mārcis Gasūns_
