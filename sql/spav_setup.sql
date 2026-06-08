-- Securepoint AV Portal — berliCRM Integration
-- Ausführen einmalig beim Deployment

-- ── Cache-Tabelle ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `mft_spav_devices` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `account_id`       VARCHAR(20)  NOT NULL COMMENT 'vtiger Account-ID (z.B. ACC27)',
    `deviceid`         VARCHAR(100) NOT NULL COMMENT 'Securepoint Device-UUID',
    `tid`              VARCHAR(100) NOT NULL DEFAULT '',
    `hostname`         VARCHAR(255) NOT NULL DEFAULT '',
    `domain`           VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Eigene Bezeichnung im SP Portal',
    `ip`               VARCHAR(45)  NOT NULL DEFAULT '',
    `os`               VARCHAR(100) NOT NULL DEFAULT '',
    `onaccess`         TINYINT(1)   NOT NULL DEFAULT 0,
    `version_product`  VARCHAR(50)  NOT NULL DEFAULT '',
    `version_vdb`      VARCHAR(50)  NOT NULL DEFAULT '',
    `date_lastseen`    DATETIME     NULL DEFAULT NULL,
    `infection_count`  INT          NOT NULL DEFAULT 0,
    `group_id`         VARCHAR(100) NOT NULL DEFAULT '',
    `group_name`       VARCHAR(255) NOT NULL DEFAULT '',
    `license_name`     VARCHAR(255) NOT NULL DEFAULT '',
    `first_seen_local` DATETIME     NULL DEFAULT NULL,
    `last_sync`        DATETIME     NULL DEFAULT NULL,
    `sync_status`      ENUM('active','lost') NOT NULL DEFAULT 'active',
    `deleted_at`       DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_deviceid` (`deviceid`),
    KEY `idx_account_id` (`account_id`),
    KEY `idx_last_sync`  (`last_sync`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Schritt 1: Fehlerhaften Eintrag (relation_id=0) entfernen ────────────────

DELETE FROM `vtiger_relatedlists`
WHERE `name` = 'get_spav_devices'
  AND `relation_id` = 0;

-- ── Schritt 2: SPAVDevices in vtiger_tab registrieren ────────────────────────
-- tabid ist kein AUTO_INCREMENT → expliziten Wert setzen.
-- presence=0 → Modul taucht NICHT in der Navigation auf (reiner Stub).

INSERT INTO `vtiger_tab`
    (`tabid`, `name`, `presence`, `tabsequence`, `tablabel`,
     `customized`, `ownedby`, `isentitytype`, `trial`, `version`, `parent`)
SELECT
    (SELECT MAX(`tabid`) + 1 FROM `vtiger_tab`)  AS tabid,
    'SPAVDevices',
    0,
    -1,
    'AV Clients',
    1,
    0,
    0,
    0,
    '1.0',
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM `vtiger_tab` WHERE `name` = 'SPAVDevices'
);

-- ── Schritt 3: Related Tab registrieren ──────────────────────────────────────
-- relation_id kommt aus vtiger_relatedlists_seq (kein AUTO_INCREMENT!).

INSERT INTO `vtiger_relatedlists`
    (`relation_id`, `tabid`, `related_tabid`, `name`, `sequence`, `label`, `presence`, `actions`)
SELECT
    (SELECT MAX(r3.`relation_id`) + 1 FROM `vtiger_relatedlists` r3) AS relation_id,
    (SELECT `tabid` FROM `vtiger_tab` WHERE `name` = 'Accounts') AS tabid,
    (SELECT `tabid` FROM `vtiger_tab` WHERE `name` = 'SPAVDevices') AS related_tabid,
    'get_spav_devices',
    (SELECT COALESCE(MAX(r2.`sequence`), 0) + 1
     FROM `vtiger_relatedlists` r2
     WHERE r2.`tabid` = (SELECT `tabid` FROM `vtiger_tab` WHERE `name` = 'Accounts')),
    'AV Clients',
    0,
    ''
WHERE NOT EXISTS (
    SELECT 1 FROM `vtiger_relatedlists`
    WHERE `name` = 'get_spav_devices'
);

-- ── Schritt 4: Sequenz nachführen ────────────────────────────────────────────

UPDATE `vtiger_relatedlists_seq`
SET    `id` = (SELECT MAX(`relation_id`) FROM `vtiger_relatedlists`);

-- ── Schritt 5: Profil-Berechtigungen für Nicht-Admin-User ────────────────────
-- vtiger prüft vtiger_profile2tab für jeden Nicht-Admin-User.
-- Fehlt ein Eintrag für tabid=63 (SPAVDevices) oder tabid=62 (RMMDevices),
-- schlägt isPermitted('SPAVDevices','DetailView') fehl → Tab wird ausgeblendet.
-- permissions=0 = erlaubt, permissions=1 = gesperrt.

INSERT INTO `vtiger_profile2tab` (`profileid`, `tabid`, `permissions`)
SELECT p.`profileid`, 63, 0
FROM   `vtiger_profile` p
WHERE  NOT EXISTS (
    SELECT 1 FROM `vtiger_profile2tab` x
    WHERE x.`profileid` = p.`profileid` AND x.`tabid` = 63
);

INSERT INTO `vtiger_profile2tab` (`profileid`, `tabid`, `permissions`)
SELECT p.`profileid`, 62, 0
FROM   `vtiger_profile` p
WHERE  NOT EXISTS (
    SELECT 1 FROM `vtiger_profile2tab` x
    WHERE x.`profileid` = p.`profileid` AND x.`tabid` = 62
);

-- ── Schritt 6: tabsequence auf -1 korrigieren (falls noch nicht erledigt) ────

UPDATE `vtiger_tab` SET `tabsequence` = -1 WHERE `name` = 'SPAVDevices' AND `tabsequence` != -1;
UPDATE `vtiger_tab` SET `tabsequence` = -1 WHERE `name` = 'RMMDevices'  AND `tabsequence` != -1;
