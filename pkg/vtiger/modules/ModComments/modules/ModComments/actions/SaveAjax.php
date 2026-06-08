<?php
/*+***********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 *************************************************************************************/

class ModComments_SaveAjax_Action extends Vtiger_SaveAjax_Action {

	public function checkPermission(Vtiger_Request $request) {
		$moduleName = $request->getModule();
		$record = $request->get('record');
		//Do not allow ajax edit of existing comments
		if ($record) {
			throw new AppException('LBL_PERMISSION_DENIED');
		}
	}

	public function process(Vtiger_Request $request) {
		$currentUserModel = Users_Record_Model::getCurrentUserModel();
		$request->set('assigned_user_id', $currentUserModel->getId());
		$request->set('userid', $currentUserModel->getId());

		$recordModel = $this->saveRecord($request);
		$commentId = $recordModel->getId();

		// Handle file uploads
		if (!empty($_FILES['comment_files']['name'][0])) {
			$this->saveUploadedFiles($commentId, $_FILES['comment_files']);
		}

		// Handle external NAS/SMB/URL links
		$fileLinks = $request->get('file_links');
		if (!empty($fileLinks)) {
			$links = is_array($fileLinks) ? $fileLinks : json_decode($fileLinks, true);
			if (is_array($links)) {
				foreach ($links as $link) {
					$link = trim($link);
					if (!empty($link)) {
						$this->saveExternalLink($commentId, $link);
					}
				}
			}
		}

		// Handle linked CRM documents
		$documentIds = $request->get('document_ids');
		if (!empty($documentIds)) {
			$ids = is_array($documentIds) ? $documentIds : json_decode($documentIds, true);
			if (is_array($ids)) {
				$this->saveDocumentLinks($commentId, $ids);
			}
		}

		$fieldModelList = $recordModel->getModule()->getFields();
		$result = array();
		foreach ($fieldModelList as $fieldName => $fieldModel) {
			$fieldValue = $recordModel->get($fieldName);
			$result[$fieldName] = array('value' => $fieldValue, 'display_value' => $fieldModel->getDisplayValue($fieldValue));
		}
		$result['id'] = $commentId;
		$result['_recordLabel'] = $recordModel->getName();
		$result['_recordId'] = $commentId;

		$response = new Vtiger_Response();
		$response->setEmitType(Vtiger_Response::$EMIT_JSON);
		$response->setResult($result);
		$response->emit();
	}

	/**
	 * Save uploaded files and link them to the comment via vtiger_seattachmentsrel
	 */
	protected function saveUploadedFiles($commentId, $filesArray) {
		global $adb, $current_user;

		$fileCount = count($filesArray['name']);
		for ($i = 0; $i < $fileCount; $i++) {
			if ($filesArray['error'][$i] !== UPLOAD_ERR_OK) {
				continue;
			}
			$fileDetails = array(
				'name'      => $filesArray['name'][$i],
				'type'      => $filesArray['type'][$i],
				'size'      => $filesArray['size'][$i],
				'tmp_name'  => $filesArray['tmp_name'][$i],
			);
			$this->insertAttachment($commentId, $fileDetails);
		}
	}

	/**
	 * Insert a physical file as attachment and link to comment
	 */
	protected function insertAttachment($commentId, $fileDetails) {
		global $adb, $current_user, $upload_badext;

		require_once('include/utils/utils.php');

		$binFile = sanitizeUploadFileName($fileDetails['name'], $upload_badext);
		$filename = ltrim(basename(" " . $binFile));
		$filetype = $fileDetails['type'];
		$filesize = $fileDetails['size'];

		$uploadPath = decideFilePath();
		$attachmentId = $adb->getUniqueID('vtiger_crmentity');

		$uploadStatus = move_uploaded_file($fileDetails['tmp_name'], $uploadPath . $attachmentId . '_' . $binFile);
		if (!$uploadStatus) {
			return false;
		}

		$date = date('Y-m-d H:i:s');
		$adb->pquery(
			'INSERT INTO vtiger_crmentity (crmid, smcreatorid, smownerid, setype, description, createdtime, modifiedtime)
			 VALUES (?, ?, ?, ?, ?, ?, ?)',
			array($attachmentId, $current_user->id, $current_user->id, 'ModComments Attachment', '', $adb->formatDate($date, true), $adb->formatDate($date, true))
		);
		$adb->pquery(
			'INSERT INTO vtiger_attachments (attachmentsid, name, description, type, path) VALUES (?, ?, ?, ?, ?)',
			array($attachmentId, $filename, '', $filetype, $uploadPath)
		);
		$adb->pquery(
			'INSERT INTO vtiger_seattachmentsrel (crmid, attachmentsid) VALUES (?, ?)',
			array($commentId, $attachmentId)
		);
		return $attachmentId;
	}

	/**
	 * Save external link (NAS/SMB/URL) as attachment entry
	 */
	protected function saveExternalLink($commentId, $link) {
		global $adb, $current_user;

		$attachmentId = $adb->getUniqueID('vtiger_crmentity');
		$date = date('Y-m-d H:i:s');

		$adb->pquery(
			'INSERT INTO vtiger_crmentity (crmid, smcreatorid, smownerid, setype, description, createdtime, modifiedtime)
			 VALUES (?, ?, ?, ?, ?, ?, ?)',
			array($attachmentId, $current_user->id, $current_user->id, 'ModComments Link', '', $adb->formatDate($date, true), $adb->formatDate($date, true))
		);
		$adb->pquery(
			'INSERT INTO vtiger_attachments (attachmentsid, name, description, type, path) VALUES (?, ?, ?, ?, ?)',
			array($attachmentId, $link, '', 'E', '')
		);
		$adb->pquery(
			'INSERT INTO vtiger_seattachmentsrel (crmid, attachmentsid) VALUES (?, ?)',
			array($commentId, $attachmentId)
		);
	}

	/**
	 * Save links to existing CRM Documents
	 */
	protected function saveDocumentLinks($commentId, $documentIds) {
		global $adb;

		$tableCheck = $adb->pquery("SHOW TABLES LIKE 'vtiger_modcomments_docrel'", array());
		if ($adb->num_rows($tableCheck) == 0) {
			return;
		}

		foreach ($documentIds as $documentId) {
			$documentId = intval($documentId);
			if ($documentId <= 0) continue;
			$adb->pquery(
				'INSERT IGNORE INTO vtiger_modcomments_docrel (modcommentsid, documentid) VALUES (?, ?)',
				array($commentId, $documentId)
			);
		}
	}

	/**
	 * Function to save record
	 * @param <Vtiger_Request> $request - values of the record
	 * @return <RecordModel> - record Model of saved record
	 */
	public function saveRecord($request) {
		$recordModel = $this->getRecordModelFromRequest($request);

		$recordModel->save();
		if($request->get('relationOperation')) {
			$parentModuleName = $request->get('sourceModule');
			$parentModuleModel = Vtiger_Module_Model::getInstance($parentModuleName);
			$parentRecordId = $request->get('sourceRecord');
			$relatedModule = $recordModel->getModule();
			$relatedRecordId = $recordModel->getId();

			$relationModel = Vtiger_Relation_Model::getInstance($parentModuleModel, $relatedModule);
			$relationModel->addRelation($parentRecordId, $relatedRecordId);
		}
		return $recordModel;
	}

	/**
	 * Function to get the record model based on the request parameters
	 * @param Vtiger_Request $request
	 * @return Vtiger_Record_Model or Module specific Record Model instance
	 */
	public function getRecordModelFromRequest(Vtiger_Request $request) {
		$recordModel = parent::getRecordModelFromRequest($request);
		$recordModel->set('commentcontent', $request->getRaw('commentcontent'));
		return $recordModel;
	}
}
