# Systema-Sanscriticum

Laravel platform for samskrte.ru: study cabinet, courses and payments, homework, dictionary, SRS, landings, lectures, support, teacher finance.

## Language

**cabinet**:
Student self-service area where a learner sees course progress, lessons and homework.
_Avoid_: profile, account, dashboard

**lesson**:
A single study unit inside a course block, unlocked by access keys.
_Avoid_: lecture, module (for recorded lecture files use lecture-builder)

**payment**:
A webhook-confirmed movement of money accepted as paid.
_Avoid_: promise, checkout, order (checkout is initiation, not payment)

**promise**:
An initiated checkout commitment before money movement is confirmed.
_Avoid_: payment, invoice

**payout**:
A teacher-canon payment computed from period receipts.
_Avoid_: salary (reserved for contract terms), refund

**support rule**:
A scoped support instruction visible to helpdesk agents.
_Avoid_: prompt, pilot rule

**course**:
A teaching program that may have multiple intakes and student groups.
_Avoid_: intake when referring to the whole program

**intake**:
A new group entering an existing course at a stated start date and regular lesson time.
_Avoid_: course when referring only to a new start of the same program

**application**:
A recorded intention to participate in a specific intake. It does not establish payment, course access or a guaranteed place.
_Avoid_: paid enrollment, confirmed reservation

_Created: 09-11-2026 · Last updated: 10-11-2026_

_Dr. Mārcis Gasūns_
