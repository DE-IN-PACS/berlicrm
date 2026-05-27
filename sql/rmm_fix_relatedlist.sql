-- =============================================================================
-- TacticalRMM – vtiger_relatedlists korrekt setzen
--
-- Setzt die Pflichtfelder für den RMM-Tab auf die korrekten Werte:
--   tabid         = 6   (Accounts)
--   related_tabid = 62  (RMMDevices — wie in rmm_setup.sql angelegt)
--   name          = get_rmmdevices  (Methoden-Stub in Accounts.php)
--   actions       = InRelation      (vtiger sucht RMMDevices_InRelation_View)
--   presence      = 0               (Tab sichtbar)
--
-- Ausführen wenn:
--   a) rmm_migrate_rmmtab.sql (fehlerhafte Version) gelaufen ist, ODER
--   b) Tab nach rmm_setup.sql nicht sichtbar ist
--
-- HINWEIS: Falls tabid/related_tabid in deiner Datenbank abweichen,
-- prüfe zuerst mit: SELECT tabid FROM vtiger_tab WHERE name IN ('Accounts','RMMDevices');
-- =============================================================================

UPDATE `vtiger_relatedlists`
SET
    `tabid`         = 6,
    `related_tabid` = 62,
    `name`          = 'get_rmmdevices',
    `actions`       = 'InRelation',
    `presence`      = 0
WHERE `label` = 'RMM Geräte';

-- Prüfen:
-- SELECT relation_id, tabid, related_tabid, name, actions, presence
-- FROM vtiger_relatedlists WHERE label = 'RMM Geräte';

-- =============================================================================
-- NACH dem SQL: Admin → Einstellungen → Reparieren → "Quick Repair and Rebuild"
-- =============================================================================
