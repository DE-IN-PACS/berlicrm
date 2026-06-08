<?php
/*+***********************************************************************************
 * ModComments - Download attachment action
 ************************************************************************************/

class ModComments_DownloadFile_Action extends Vtiger_Action_Controller {

	public function checkPermission(Vtiger_Request $request) {
		if (!Users_Privileges_Model::isPermitted('ModComments', 'DetailView')) {
			throw new AppException(vtranslate('LBL_PERMISSION_DENIED', 'ModComments'));
		}
	}

	public function process(Vtiger_Request $request) {
		$db = PearDatabase::getInstance();
		$attachmentsid = intval($request->get('fileid'));

		if ($attachmentsid <= 0) {
			throw new AppException('Invalid file id');
		}

		// Verify the attachment belongs to a comment the user can reach
		$accessCheck = $db->pquery(
			'SELECT vsar.crmid FROM vtiger_seattachmentsrel vsar
			 INNER JOIN vtiger_crmentity ve ON ve.crmid = vsar.crmid AND ve.deleted = 0
			 WHERE vsar.attachmentsid = ? AND ve.setype = ?',
			array($attachmentsid, 'ModComments')
		);
		if ($db->num_rows($accessCheck) == 0) {
			throw new AppException(vtranslate('LBL_PERMISSION_DENIED', 'ModComments'));
		}

		$result = $db->pquery(
			'SELECT name, type, path FROM vtiger_attachments WHERE attachmentsid = ?',
			array($attachmentsid)
		);

		if ($db->num_rows($result) == 0) {
			throw new AppException('File not found');
		}

		$row = $db->query_result_rowdata($result, 0);
		$filePath = $row['path'] . $attachmentsid . '_' . $row['name'];

		if (!file_exists($filePath)) {
			throw new AppException('File not found on disk');
		}

		$safeName = str_replace(array('"', "\r", "\n"), '', $row['name']);
		$mimeType = !empty($row['type']) ? $row['type'] : 'application/octet-stream';
		header('Content-Type: ' . $mimeType);
		header('Content-Disposition: attachment; filename="' . $safeName . '"');
		header('Content-Length: ' . filesize($filePath));
		header('Cache-Control: private');
		readfile($filePath);
		exit;
	}
}
