<?php
/**
 * One-time migration: upgrades the FAQ "answer" field from plain textarea (uitype 20)
 * to rich-text / CKEditor (uitype 21) so it can store HTML including embedded images.
 *
 * Run once via CLI:  php modules/Faq/faq_richtext_upgrade.php
 * Or via browser:   index.php?module=Faq&action=faq_richtext_upgrade  (admin only)
 */

if (php_sapi_name() !== 'cli') {
    define('SUGAR_PATH', dirname(__FILE__) . '/../..');
    chdir(SUGAR_PATH);
    require_once 'include/entryPoint.php';
    if (!is_admin(current_user_is_admin())) {
        die('Admin access required.');
    }
} else {
    define('SUGAR_PATH', dirname(__FILE__) . '/../..');
    chdir(SUGAR_PATH);
    require_once 'include/entryPoint.php';
}

$db = PearDatabase::getInstance();

// Fetch FAQ tabid
$tabResult = $db->pquery("SELECT tabid FROM vtiger_tab WHERE name = ?", ['Faq']);
$tabRow    = $db->fetchByAssoc($tabResult);

if (!$tabRow) {
    die("ERROR: Faq module not found in vtiger_tab.\n");
}

$tabid = (int) $tabRow['tabid'];

// Check current uitype
$fieldResult = $db->pquery(
    "SELECT fieldid, uitype FROM vtiger_field WHERE tabid = ? AND fieldname = 'answer'",
    [$tabid]
);
$fieldRow = $db->fetchByAssoc($fieldResult);

if (!$fieldRow) {
    die("ERROR: Field 'answer' not found for FAQ module (tabid={$tabid}).\n");
}

if ($fieldRow['uitype'] == '21') {
    echo "INFO: Field 'answer' is already uitype 21 (rich text). Nothing to do.\n";
    exit(0);
}

// Update uitype 20 -> 21
$db->pquery(
    "UPDATE vtiger_field SET uitype = '21' WHERE fieldid = ?",
    [$fieldRow['fieldid']]
);

echo "OK: FAQ answer field (fieldid={$fieldRow['fieldid']}) updated to uitype 21 (rich text / CKEditor).\n";
echo "Existing plain-text entries will display as-is; new entries will be stored as HTML.\n";
