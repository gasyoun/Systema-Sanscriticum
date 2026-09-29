# Student calendar loads with attendance notices enabled

_Created: 23-09-2026 · Last updated: 23-09-2026_

- Restore the calendar's attendance notice model and service imports after the student controller was split into traits. The missing imports caused HTTP 500 when an upcoming lesson existed and attendance notices were enabled.
- Exercise that production configuration in the calendar feature test so the page must render successfully.

_Гасунс_
