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

**availability window**:
One interval in which a student may be offered a NEW course or elective. Either a constrained weekly RRULE (`FREQ=WEEKLY;BYDAY=…`) or a cycle (`repeat_mode=cycle`: N days on / M days off from an anchor date), plus start/end time and an IANA TZID. Absence of a window on a day means "not available" only when the student has at least one window at all.
_Avoid_: schedule, slot preference, availability (too vague)

**curator override**:
A curator-owned availability window that outranks student-owned ones: `deny` refuses, `allow` permits. Students cannot create or edit curator rows.
_Avoid_: admin note, preference

**offer gate**:
The decision point that hides a new-course offer whose known weekly slot falls outside the student's availability windows. It never touches already-purchased or ongoing study.

**membership tier**:
An entitlement level with an internal key (`free`, `basic`, `club`, `top`). Internal keys are stable across renames; payments and entitlements refer to them, never to display names.
_Avoid_: tariff, plan name

**membership label**:
The public display name of a membership tier. Since 01-12-2026: free = Базовый, basic = Оптимальный, club = Максимальный, top = Премиум (не продаётся). A label change never rewrites a tier key or a payment.
_Avoid_: tier key

**capability gate**:
The check that makes a membership label meaningful by enforcing a capability (library, standard exercises, recordings) against the active tier. Copy alone is not a gate.

**book club**:
A one-off payment for shipping physical books already bought in the planeta.ru campaign (storefront name «Книжный клуб»); covers shipment only — no course access, no membership.
_Avoid_: club (the subscription club), product sale
**campaign pickup**:
Free in-person handout of campaign books in Moscow by prior arrangement, the no-cost alternative to paid shipment.
_Avoid_: delivery, courier
**delivery tariff**:
The price of shipping a book parcel, set by destination (RF, abroad) and weight band; independent of the book price already paid in the campaign.
_Avoid_: book price, carrier cost
**campaign order**:
A row of the planeta.ru campaign export linking a backer to the books they bought; the key for matching a delivery payment to the books to send.
_Avoid_: shop order, payment

_Created: 09-11-2026 · Last updated: 27-11-2026_

_Dr. Mārcis Gasūns_
