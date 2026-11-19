# meta — RUNBOOK_GASUNS_PAY_SELF_INTAKE_09-10-2026.md

_Created: 09-10-2026 · Last updated: 09-10-2026_

- **Что это:** операторский ранбук рублёвого самоприёма `/gasuns-pay/{тариф}` из волны пяти поверхностей H6312 (после аудита плейбуков [AUDIT_PLAYBOOK_RUNBOOK_GAPS_04-10-2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/AUDIT_PLAYBOOK_RUNBOOK_GAPS_04-10-2026.md); «gasuns-pay» в docs/ отсутствовал — проба grep 09-10).
- **Заказ:** [H6312](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6312-OxAlpha_Systema-Sanscriticum_playbooks-five-new-surfaces_09.10.26.md) (Uprava), исполнен GLM (GLM-5.3-Flash), 09-10-2026.
- **Источники фактов:** живые пробы .92 09-10 (tinker: `enabled=true trust=true`; GET `/gasuns-pay/5134` → 200), [GasunsPayClaimController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/GasunsPayClaimController.php) (H6198), [config/services.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/services.php), [routes/web/05-payments-and-money.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web/05-payments-and-money.php), кейс Хадиджи H6141.
- **Не является:** спекой платёжного движка (ссылки на код в тексте); документом про `/teacher-pay` (гонорары преподавателей — своя поверхность H4627); заменой [RUNBOOK_FEATURE_FLAG_FLIP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_FEATURE_FLAG_FLIP.md) (флаговые шаги ссылаются на него).

_Dr. Mārcis Gasūns_
