<?php
/*+***********************************************************************************
 * ModComments - One-time migration: creates vtiger_modcomments_docrel table
 * Run via: index.php?module=ModComments&action=MigrateAttachments
 ************************************************************************************/

class ModComments_MigrateAttachments_Action extends Vtiger_Action_Controller {

	public function checkPermission(Vtiger_Request $request) {
		$currentUser = Users_Record_Model::getCurrentUserModel();
		if (!$currentUser->isAdminUser()) {
			throw new AppException(vtranslate('LBL_PERMISSION_DENIED', 'ModComments'));
		}
	}

	public function process(Vtiger_Request $request) {
		$db = PearDatabase::getInstance();
		$db->pquery(
			'CREATE TABLE IF NOT EXISTS vtiger_modcomments_docrel (
				modcommentsid INT NOT NULL,
				documentid INT NOT NULL,
				PRIMARY KEY (modcommentsid, documentid)
			)',
			array()
		);
		echo json_encode(array('success' => true, 'message' => 'vtiger_modcomments_docrel table created'));
		exit;
	}
}
