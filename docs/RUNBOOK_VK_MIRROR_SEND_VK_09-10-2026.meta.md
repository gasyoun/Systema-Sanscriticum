# meta — RUNBOOK_VK_MIRROR_SEND_VK_09-10-2026.md

_Created: 09-10-2026 · Last updated: 09-10-2026_

- **Что это:** ранбук VK-зеркала [vk.com/samskrtamru](https://vk.com/samskrtamru) (warm-up ключа, классификация err 214/15/27/9, ручной send-vk) из волны H6312; до него в Systema были только setup-инструкции старого n8n-воркфлоу, к python-зеркалу отношения не имеющие.
- **Заказ:** [H6312](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6312-OxAlpha_Systema-Sanscriticum_playbooks-five-new-surfaces_09.10.26.md) (Uprava), исполнен GLM (GLM-5.3-Flash), 09-10-2026.
- **Источники фактов:** [tools/vk_mirror.py](https://github.com/gasyoun/Uprava/blob/main/tools/vk_mirror.py) и [tools/content_saturday_runner.py](https://github.com/gasyoun/Uprava/blob/main/tools/content_saturday_runner.py) (Uprava origin/main 09-10, деплой на .92), хендафф [H6153](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6153-GLM_Uprava_vk-mirror-content-lane_05.10.26.md), живые пробы .92 09-10 (`groups.getById` → ок; `users.get` user-токеном → err 9 Flood control; `VK_USER_TOKEN` в env не пуст).
- **Не является:** документом n8n-воркфлоу (легаси-setup); спекой контент-лейна (исполнение зеркала описывает content_saturday_runner).

_Dr. Mārcis Gasūns_
