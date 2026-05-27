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

-- ── Related Tab in vtiger_relatedlists registrieren ──────────────────────────
-- Nur ausführen wenn der Eintrag noch nicht existiert.

INSERT INTO `vtiger_relatedlists`
    (`tabid`, `related_tabid`, `name`, `sequence`, `label`, `presence`, `actions`)
SELECT
    vt.`tabid`,
    0,
    'get_spav_devices',
    (SELECT COALESCE(MAX(r2.`sequence`), 0) + 1
     FROM `vtiger_relatedlists` r2
     WHERE r2.`tabid` = vt.`tabid`),
    'AV Clients',
    0,
    ''
FROM `vtiger_tab` vt
WHERE vt.`name` = 'Accounts'
  AND NOT EXISTS (
      SELECT 1 FROM `vtiger_relatedlists` rl
      WHERE rl.`tabid` = vt.`tabid`
        AND rl.`name`  = 'get_spav_devices'
  );
