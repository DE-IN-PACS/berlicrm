<?php
/*+***********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 *************************************************************************************/

class ModComments_SaveAjax_Action extends Vtiger_SaveAjax_Action
{

	public function checkPermission(Vtiger_Request $request)
	{
		$moduleName = $request->getModule();
		$record = $request->get('record');
		//Do not allow ajax edit of existing comments
		if ($record) {
			throw new AppException('LBL_PERMISSION_DENIED');
		}
	}

	public function process(Vtiger_Request $request)
	{
		$currentUserModel = Users_Record_Model::getCurrentUserModel();
		$request->set('assigned_user_id', $currentUserModel->getId());
		$request->set('userid', $currentUserModel->getId());
		$request->set('username', $currentUserModel->getName());
		$mailTo = '';
		try {
			$recordModel = $this->saveRecord($request);
			if ($request->get('sendMail')) {
				$mailTo = $this->sendMail($request, $recordModel);
			}
			$this->saveModcommentsScope($request, $recordModel, $mailTo);
		} catch (\Throwable $th) {
			file_put_contents('test/0debug.txt', "Error: " . var_export($th, true) . "\n\n", FILE_APPEND);
		}

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
	protected function saveUploadedFiles($commentId, $filesArray)
	{
		$fileCount = count($filesArray['name']);
		for ($i = 0; $i < $fileCount; $i++) {
			if ($filesArray['error'][$i] !== UPLOAD_ERR_OK) {
				continue;
			}
			$fileDetails = array(
				'name'     => $filesArray['name'][$i],
				'type'     => $filesArray['type'][$i],
				'size'     => $filesArray['size'][$i],
				'tmp_name' => $filesArray['tmp_name'][$i],
			);
			$this->insertAttachment($commentId, $fileDetails);
		}
	}

	/**
	 * Insert a physical file as attachment and link to comment
	 */
	protected function insertAttachment($commentId, $fileDetails)
	{
		global $adb, $current_user, $upload_badext;

		require_once('include/utils/utils.php');

		$binFile = sanitizeUploadFileName($fileDetails['name'], $upload_badext);
		$filename = ltrim(basename(" " . $binFile));
		$filetype = $fileDetails['type'];

		$uploadPath = decideFilePath();
		$attachmentId = $adb->getUniqueID('vtiger_crmentity');

		$uploadStatus = move_uploaded_file($fileDetails['tmp_name'], $uploadPath . $attachmentId . '_' . $binFile);
		if (!$uploadStatus) {
			return false;
		}

		$date = date('Y-m-d H:i:s');
		$adb->pquery(
			'INSERT INTO vtiger_crmentity (crmid, smcreatorid, smownerid, setype, description, createdtime, modifiedtime) VALUES (?, ?, ?, ?, ?, ?, ?)',
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
	protected function saveExternalLink($commentId, $link)
	{
		global $adb, $current_user;

		$attachmentId = $adb->getUniqueID('vtiger_crmentity');
		$date = date('Y-m-d H:i:s');

		$adb->pquery(
			'INSERT INTO vtiger_crmentity (crmid, smcreatorid, smownerid, setype, description, createdtime, modifiedtime) VALUES (?, ?, ?, ?, ?, ?, ?)',
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
	protected function saveDocumentLinks($commentId, $documentIds)
	{
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
	 */
	public function saveRecord($request)
	{
		$recordModel = $this->getRecordModelFromRequest($request);

		$recordModel->save();
		if ($request->get('relationOperation')) {
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
	 */
	public function getRecordModelFromRequest(Vtiger_Request $request)
	{
		$recordModel = parent::getRecordModelFromRequest($request);
		$recordModel->set('commentcontent', $request->getRaw('commentcontent'));
		return $recordModel;
	}

	public function sendMail(Vtiger_Request $request, Vtiger_Record_Model $recordModel)
	{
		global $site_URL, $HELPDESK_SUPPORT_EMAIL_ID, $HELPDESK_COMMENTS_EMAIL_SUBJECT;
		$email = '';
		$relatedId = $recordModel->get('related_to');
		$relatedRecordModel = Vtiger_Record_Model::getInstanceById($relatedId);
		$parent_type = '';
		$parent_id = '';

		if (empty($email) && !empty($relatedRecordModel->get('contact_id')) && $relatedRecordModel->get('contact_id') != '0') {
			$contactModel = Vtiger_Record_Model::getInstanceById($relatedRecordModel->get('contact_id'));
			$email = $contactModel->get('email');
			$parent_type = 'Contacts';
			$parent_id = $relatedRecordModel->get('contact_id');
		}

		if (empty($email)) {
			$accountModel = Vtiger_Record_Model::getInstanceById($relatedRecordModel->get('parent_id'));
			$email = $accountModel->get('email1');
			$parent_type = 'Accounts';
			$parent_id = $relatedRecordModel->get('parent_id');
		}

		$theTicketNo = $relatedRecordModel->get('ticket_no');
		$theTicketId = $relatedRecordModel->getId();
		$theTicketName = $relatedRecordModel->getName();
		if (empty($HELPDESK_COMMENTS_EMAIL_SUBJECT)) {
			$HELPDESK_COMMENTS_EMAIL_SUBJECT = '{ticket_no} [ Ticket Id : {ticket_id} ] {ticket_subject}';
		}
		$subject = str_replace(['{ticket_no}', '{ticket_id}', '{ticket_subject}'], [$theTicketNo, $theTicketId, $theTicketName], $HELPDESK_COMMENTS_EMAIL_SUBJECT);

		$contents = '<h4>Ihr Vorgang hat einen neuen Kommentar / Your ticket has a new comment:</h4>';
		$contents .= nl2br((string)$recordModel->get('commentcontent'));
		$contents .= '<br><br>----------------------------------------------------------------------------------------------------';
		$contents .= '<h4>Ticket Details</h4>';
		$contents .= '<b>Ticket ID:</b> ' . $recordModel->getId() . '<br>';
		$contents .= '<b>Betreff / Subject:</b> ' . $relatedRecordModel->getName() . '<br>';
		$contents .= '<b>Ticket Nr:</b> ' . $relatedRecordModel->get('ticket_no') . '<br>';
		$contents .= '<b>Status:</b> ' . $relatedRecordModel->get('ticketstatus') . '<br>';
		$contents .= '<b>Description / Beschreibung:</b><br>' . nl2br((string)$relatedRecordModel->get('description')) . '<br>';

		$to = $email;
		if (is_array($to)) {
			$to = implode(',', $to);
		}

		$emailsRecordModel = Vtiger_Record_Model::getCleanInstance('Emails');
		$emailsRecordModel->set('subject', html_entity_decode($subject));
		$emailsRecordModel->set('description', $contents);
		$emailsRecordModel->set('email_flag', 'SENT');
		$emailsRecordModel->set('assigned_user_id', Users_Record_Model::getCurrentUserModel()->getId());
		$emailsRecordModel->set('parent_id', $relatedId . '@1|' . $parent_id . '@1|');
		$emailsRecordModel->set('toemailinfo', array($relatedId => array($email)));
		$emailsRecordModel->set('toMailNamesList', array($relatedId => array(array('label' => $name, 'value' => $email))));
		$emailsRecordModel->set('saved_toid', $to);
		$emailsRecordModel->set('from_email', $HELPDESK_SUPPORT_EMAIL_ID);
		$emailsRecordModel->fromAddress = $HELPDESK_SUPPORT_EMAIL_ID;
		$emailsRecordModel->save();

		$response = $emailsRecordModel->send();
		if ($response === true) {
			$emailsRecordModel->setAccessCountValue();
		} else {
			$emailsRecordModel->set('email_flag', 'FAILED');
			$emailsRecordModel->set('mode', 'edit');
			$emailsRecordModel->save();
		}

		return $response ? $email : $response;
	}

	public function saveModcommentsScope(Vtiger_Request $request, Vtiger_Record_Model $recordModel, $mailTo)
	{
		$adb = PearDatabase::getInstance();
		$external = json_decode($request->get('external'));

		if ($mailTo !== '') {
			$external = 1;
		}

		$query = "INSERT INTO vtiger_modcommentsscope (modcommentsid, mailto, external) VALUES (?, ?, ?)";
		$result = $adb->pquery($query, array($recordModel->getId(), $mailTo, $external));
	}
}
