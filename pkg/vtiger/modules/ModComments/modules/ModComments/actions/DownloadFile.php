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
		$attachmentsid = $request->get('fileid');

		if (empty($attachmentsid)) {
			throw new AppException('Invalid file id');
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

		$mimeType = !empty($row['type']) ? $row['type'] : 'application/octet-stream';
		header('Content-Type: ' . $mimeType);
		header('Content-Disposition: attachment; filename="' . $row['name'] . '"');
		header('Content-Length: ' . filesize($filePath));
		header('Cache-Control: private');
		readfile($filePath);
		exit;
	}
}
