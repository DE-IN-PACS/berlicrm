-- =============================================================================
-- ACHTUNG: Diese Datei war fehlerhaft und wurde korrigiert.
--
-- Die ursprüngliche Version setzte name='getRMMDevicesTab' was vtiger beim
-- Laden des Account-Detail-Views zum Crash brachte, weil
-- $accountsObj->getRMMDevicesTab() nicht existiert.
--
-- REVERT (falls die alte Version ausgeführt wurde):
-- =============================================================================

UPDATE `vtiger_relatedlists`
SET
    `related_tabid` = (SELECT `tabid` FROM `vtiger_tab`
                       WHERE `name` = 'RMMDevices' LIMIT 1),
    `name`          = 'get_rmmdevices',
    `actions`       = ''
WHERE `label` = 'RMM Geräte';

-- Prüfen ob das Revert funktioniert hat:
-- SELECT relation_id, tabid, related_tabid, name, actions, presence
-- FROM vtiger_relatedlists WHERE label = 'RMM Geräte';
--
-- Erwartetes Ergebnis:
--   tabid         = Accounts-tabid (6 oder ähnlich)
--   related_tabid = RMMDevices-tabid
--   name          = get_rmmdevices
--   actions       = (leer)
--   presence      = 0
--
-- Danach: Admin → Einstellungen → Reparieren → "Quick Repair and Rebuild"
-- =============================================================================
