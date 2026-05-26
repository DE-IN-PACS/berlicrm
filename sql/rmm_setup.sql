-- =============================================================================
-- TacticalRMM Related Tab – Setup SQL
-- Ausführen in phpMyAdmin oder via MySQL-CLI gegen die berliCRM-Datenbank
-- =============================================================================

-- 1. Modul in vtiger_tab registrieren
--    WICHTIG: presence=0 (nicht 1!) – Relation.php filtert presence=1 heraus
--    tabsequence=-1  → kein Menüeintrag in der Navigation
--    isentitytype=0  → kein vollständiges CRM-Entitätsmodul
INSERT INTO `vtiger_tab`
    (`tabid`, `name`, `presence`, `tabsequence`, `tablabel`,
     `modifiedby`, `modifiedtime`, `customized`, `ownedby`, `isentitytype`,
     `trial`, `version`, `parent`)
VALUES
    (50, 'RMMDevices', 0, -1, 'RMM Devices',
     NULL, NULL, 1, 0, 0,
     0, NULL, NULL);


-- 2. Related Tab bei Accounts (tabid=6) registrieren
--    sequence=13 – erscheint nach HelpDesk (sequence=10)
--    actions=''   – keine Hinzufügen/Auswählen-Buttons
INSERT INTO `vtiger_relatedlists`
    (`relation_id`, `tabid`, `related_tabid`, `name`, `sequence`, `label`, `presence`, `actions`)
VALUES
    (91, 6, 50, 'get_rmmdevices', 13, 'RMM Geräte', 0, '');

-- Sequenz-Counter auf den neuen Höchstwert setzen
UPDATE `vtiger_relatedlists_seq` SET `id` = 91;


-- 3. Profil-Berechtigungen für alle vorhandenen Profile setzen
--    (permissions=0 = Zugriff erlaubt)
--    Admins haben immer Zugriff; das hier gilt für normale Nutzerprofile.
INSERT INTO `vtiger_profile2tab` (`profileid`, `tabid`, `permissions`)
SELECT `profileid`, 50, 0
FROM   `vtiger_profile`
WHERE  `profileid` NOT IN (
    SELECT `profileid` FROM `vtiger_profile2tab` WHERE `tabid` = 50
);


-- =============================================================================
-- NACH dem SQL zwingend erforderlich:
-- tabdata.php neu generieren – EINE der folgenden Optionen:
--
--   Option A (Admin-Panel):
--     Admin → Einstellungen → Reparieren → „Rebuild Module Relationships"
--     oder → „Quick Repair and Rebuild" → „Rebuild Relationships"
--
--   Option B (manuell in tabdata.php ergänzen):
--     $tab_info_array  → 'RMMDevices' => 50,
--     $tab_seq_array   → '50' => 0,
--     $tab_ownedby_array → '50' => 0,
-- =============================================================================

-- =============================================================================
-- Custom Field rmm_client_id anlegen (falls noch nicht vorhanden):
--   Settings → Studio → Accounts → Felder → Neu
--   Feldtyp: Text
--   Feldname: rmm_client_id
--   Label: RMM Client ID
-- Das Feld wird NICHT via SQL angelegt – vtiger Studio erzeugt automatisch
-- die nötigen Spalten in vtiger_accountscf und alle Metadaten.
-- =============================================================================
