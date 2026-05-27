-- =============================================================================
-- TacticalRMM – Migration: Related Tab auf Accounts-Self-Relation umstellen
--
-- Hintergrund:
--   vtiger rendert mode=showRelatedList nur mit vollem Account-Rahmen wenn
--   related_tabid eine von vtiger intern bekannte Modul-ID ist.
--   RMMDevices ist ein Stub-Modul und wird nicht erkannt.
--   Fix: related_tabid = Accounts-tabid (Self-Relation), Dispatch via
--        module=Accounts&view=RMMTab (neuer Endpoint in Accounts/views/).
--
-- Voraussetzung:
--   rmm_setup.sql wurde bereits ausgeführt (vtiger_tab-Eintrag vorhanden).
--
-- Ausführen in phpMyAdmin oder via MySQL-CLI gegen die berliCRM-Datenbank.
-- =============================================================================

UPDATE `vtiger_relatedlists`
SET
    `tabid`         = (SELECT `tabid` FROM `vtiger_tab` WHERE `name` = 'Accounts' LIMIT 1),
    `related_tabid` = (SELECT `tabid` FROM `vtiger_tab` WHERE `name` = 'Accounts' LIMIT 1),
    `name`          = 'getRMMDevicesTab',
    `actions`       = 'RMMTab',
    `presence`      = 0
WHERE `label` = 'RMM Geräte';

-- Prüfen ob das Update wirksam war (sollte 1 Zeile zeigen):
-- SELECT * FROM vtiger_relatedlists WHERE label = 'RMM Geräte';

-- =============================================================================
-- NACH dem SQL zwingend erforderlich:
--   Admin → Einstellungen → Reparieren → „Quick Repair and Rebuild"
--   → „Rebuild Relationships"
-- =============================================================================
