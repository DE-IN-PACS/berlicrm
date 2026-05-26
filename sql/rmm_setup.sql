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
    (62, 'RMMDevices', 0, -1, 'RMM Devices',
     NULL, NULL, 1, 0, 0,
     0, NULL, NULL);


-- 2. Related Tab bei Accounts (tabid=6) registrieren
--    sequence=13 – erscheint nach HelpDesk (sequence=10)
--    actions=''   – keine Hinzufügen/Auswählen-Buttons
SET @next_rel_id = (SELECT MAX(`relation_id`) + 1 FROM `vtiger_relatedlists`);

INSERT INTO `vtiger_relatedlists`
    (`relation_id`, `tabid`, `related_tabid`, `name`, `sequence`, `label`, `presence`, `actions`)
VALUES
    (@next_rel_id, 6, 62, 'get_rmmdevices', 13, 'RMM Geräte', 0, '');

-- Sequenz-Counter auf den neuen Höchstwert setzen
UPDATE `vtiger_relatedlists_seq` SET `id` = @next_rel_id;


-- 3. Profil-Berechtigungen für alle vorhandenen Profile setzen
--    (permissions=0 = Zugriff erlaubt)
--    Admins haben immer Zugriff; das hier gilt für normale Nutzerprofile.
INSERT INTO `vtiger_profile2tab` (`profileid`, `tabid`, `permissions`)
SELECT `profileid`, 62, 0
FROM   `vtiger_profile`
WHERE  `profileid` NOT IN (
    SELECT `profileid` FROM `vtiger_profile2tab` WHERE `tabid` = 62
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
--     $tab_info_array  → 'RMMDevices' => 62,
--     $tab_seq_array   → '62' => 0,
--     $tab_ownedby_array → '62' => 0,
-- =============================================================================

-- =============================================================================
-- Verknüpfungslogik:
--   berliCRM liest vtiger_account.account_no (z.B. "ACC27") und sucht in
--   TacticalRMM nach einem Client mit custom_fields[berlicrm_id] == "ACC27".
--   Das Custom Field "berlicrm_id" muss einmalig in TacticalRMM angelegt
--   werden: Settings → Custom Fields → Client → berlicrm_id (Text).
--   Kein Custom Field in vtiger/berliCRM erforderlich.
-- =============================================================================
