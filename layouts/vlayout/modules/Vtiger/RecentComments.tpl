{*<!--
/*********************************************************************************
** The contents of this file are subject to the vtiger CRM Public License Version 1.0
* ("License"); You may not use this file except in compliance with the License
* The Original Code is:  vtiger CRM Open Source
* The Initial Developer of the Original Code is vtiger.
* Portions created by vtiger are Copyright (C) vtiger.
* All Rights Reserved.
*
********************************************************************************/
-->*}
{strip}

	{* Change to this also refer: AddCommentForm.tpl *}
	{assign var="COMMENT_TEXTAREA_DEFAULT_ROWS" value="2"}

	<div class="commentContainer recentComments">
		<div class="commentTitle row-fluid">
			{assign var=CREATE_PERMISSION value=$COMMENTS_MODULE_MODEL->isPermitted('CreateView')}
			{assign var=EDIT_PERMISSION value=$COMMENTS_MODULE_MODEL->isPermitted('EditView')}
			{if $CREATE_PERMISSION}
				<div class="addCommentBlock">
					<div>
						<textarea name="commentcontent" class="commentcontent"
							placeholder="{vtranslate('LBL_ADD_YOUR_COMMENT_HERE', $MODULE_NAME)}"
							rows="{$COMMENT_TEXTAREA_DEFAULT_ROWS}"></textarea>
					</div>
					<div class="commentAttachmentsArea" style="margin-top:6px;">
						<div class="commentDropZone" style="border:2px dashed #ccc;border-radius:4px;padding:8px 12px;cursor:pointer;color:#888;font-size:12px;margin-bottom:4px;">
							<i class="icon-upload"></i> Dateien hier ablegen oder klicken
							<input type="file" name="comment_files[]" class="commentFileInput" multiple style="display:none;" />
						</div>
						<ul class="commentFileList" style="list-style:none;margin:0;padding:0;font-size:12px;"></ul>
						<div class="commentLinkArea" style="margin-top:4px;display:flex;gap:6px;align-items:center;">
							<input type="text" class="commentLinkInput input-block-level" placeholder="NAS/SMB/URL einfügen..." style="font-size:12px;flex:1;" />
							<button type="button" class="btn btn-mini commentAddLinkBtn"><i class="icon-plus"></i> {vtranslate('LBL_ADD', $MODULE_NAME)}</button>
						</div>
						<ul class="commentLinkList" style="list-style:none;margin:2px 0 0;padding:0;font-size:12px;"></ul>
						<div style="margin-top:4px;">
							<button type="button" class="btn btn-mini commentSelectDocBtn"><i class="icon-file"></i> CRM-Dokument verknüpfen</button>
						</div>
						<ul class="commentDocList" style="list-style:none;margin:2px 0 0;padding:0;font-size:12px;"></ul>
					</div>
					{if $MODULE_NAME == 'HelpDesk'}
						<div style="display:inline-block; margin-right:20px; margin-top:6px;">
							<input type="checkbox" id="externalComment" name="externalComment" class="alignTop">&nbsp;
							<label for="externalComment"
								style="display:inline;">{vtranslate('LBL_EXTERNAL_COMMENT', $MODULE_NAME)}</label>
						</div>
					{/if}
					<div class="pull-right" style="margin-top:6px;">
						<button class="btn btn-success detailViewSaveComment" type="button"
							data-mode="add"><strong>{vtranslate('LBL_POST', $MODULE_NAME)}</strong></button>
					</div>
					{if $MODULE_NAME == 'HelpDesk'}
						<div class="pull-right" style="margin-top:6px;">
							<button class="btn saveButton detailViewSaveComment" type="button"
								data-mode="sendMail"><strong>{vtranslate('LBL_SEND_MAIL_AND_POST', $MODULE_NAME)}</strong></button>
						</div>
					{/if}
				</div>
			{/if}
		</div>
		<hr><br>
		<div class="commentsBody">
			{if !empty($COMMENTS)}
				{foreach key=index item=COMMENT from=$COMMENTS}
					{assign var=COMMENT_TYPE value=$COMMENT->getCommentType()}
					{if !isset($COMMENTS_COLORS) || empty($COMMENTS_COLORS)}
						{$COMMENTS_COLORS = ['customer' => 'red', 'outgoing' => 'green', 'internal' => 'yellow']}
					{/if}
					<div class="commentDetails" style="border: 1px solid {if isset($COMMENTS_COLORS[$COMMENT_TYPE])}{$COMMENTS_COLORS[$COMMENT_TYPE]}{/if};">
						<div class="commentDiv">
							<div class="singleComment">
								<div class="commentInfoHeader row-fluid" data-commentid="{$COMMENT->getId()}"
									data-parentcommentid="{$COMMENT->get('parent_comments')}">
									<div class="commentTitle">
										{assign var=PARENT_COMMENT_MODEL value=$COMMENT->getParentCommentModel()}
										{assign var=CHILD_COMMENTS_MODEL value=$COMMENT->getChildComments()}
										<div class="row-fluid">
											<div class="span1">
												{assign var=IMAGE_PATH value=$COMMENT->getImagePath()}
												<img class="alignMiddle pull-left"
													src="{if !empty($IMAGE_PATH)}{$IMAGE_PATH}{else}{vimage_path('DefaultUserIcon.png')}{/if}">
											</div>
											<div class="span11 commentorInfo">
												{assign var=COMMENTOR value=$COMMENT->getCommentedByModel()}
												<div class="inner">
													<span class="commentorName">
														<strong>{if $COMMENTOR}{$COMMENTOR->getName()}{else}{vtranslate('LBL_DELETED')}{/if}</strong>&nbsp;
														{if $COMMENT->getCommentMailTo() != NULL}
															<span class="muted">
																({vtranslate('LBL_MAILTO',$MODULE_NAME)}:&nbsp;
																{$COMMENT->getCommentMailTo()})
															</span>
														{/if}
													</span>
													<span class="pull-right">
														<p class="muted"><small
																title="{Vtiger_Util_Helper::formatDateTimeIntoDayString($COMMENT->getCommentedTime())}">{Vtiger_Util_Helper::formatDateDiffInStrings($COMMENT->getCommentedTime())}&nbsp;&nbsp;
																({Vtiger_Util_Helper::convertDateTimeIntoUsersDisplayFormat($COMMENT->getCommentedTime())})</small>
														</p>
													</span>
													<div class="clearfix"></div>
												</div>
												<div class="commentInfoContent">
													{nl2br($COMMENT->get('commentcontent'))}
												</div>
												{assign var=COMMENT_ATTACHMENTS value=$COMMENT->getAttachments()}
												{if !empty($COMMENT_ATTACHMENTS)}
												<div class="commentAttachmentsList" style="margin-top:6px;font-size:12px;">
													{foreach from=$COMMENT_ATTACHMENTS item=ATT}
														{if $ATT.type eq 'file'}
															<div style="margin-bottom:2px;">
																<i class="icon-file"></i>
																<a href="index.php?module=ModComments&action=DownloadFile&fileid={$ATT.id}" target="_blank">{$ATT.name|escape:'html'}</a>
															</div>
														{elseif $ATT.type eq 'link'}
															<div style="margin-bottom:2px;">
																<i class="icon-share"></i>
																<a href="{$ATT.url|escape:'html'}" target="_blank">{$ATT.name|escape:'html'}</a>
															</div>
														{elseif $ATT.type eq 'document'}
															<div style="margin-bottom:2px;">
																<i class="icon-file-text"></i>
																<a href="index.php?module=Documents&action=DetailView&record={$ATT.documentid}" target="_blank">{$ATT.name|escape:'html'}</a>
															</div>
														{/if}
													{/foreach}
												</div>
												{/if}
											</div>
										</div>
									</div>
								</div>
								<div class="row-fluid commentActionsContainer">
									{assign var=EXTERNAL_COMMENT value=$COMMENT->getExternalCommentId()}
									{if $MODULE_NAME == 'HelpDesk'}
										<input type="hidden" name="external" value="{$EXTERNAL_COMMENT}">
									{/if}

									{assign var="REASON_TO_EDIT" value=$COMMENT->get('reasontoedit')}
									<div class="row-fluid editStatus" name="editStatus">
										<span class="span6{if empty($REASON_TO_EDIT)} hide{/if}">
											<p class="muted">
												<small>
													[ {vtranslate('LBL_EDIT_REASON',$MODULE_NAME)} ] :
													<span name="editReason"
														class="textOverflowEllipsis">{nl2br($REASON_TO_EDIT)}</span>
												</small>
											</p>
										</span>
										{if $COMMENT->getCommentedTime() neq $COMMENT->getModifiedTime()}
											<span class="{if empty($REASON_TO_EDIT)}row-fluid{else} span6{/if}">
												<p class="muted pull-right">
													<small><em>{vtranslate('LBL_MODIFIED',$MODULE_NAME)}</em></small>&nbsp;
													<small
														title="{Vtiger_Util_Helper::formatDateTimeIntoDayString($COMMENT->getModifiedTime())}"
														class="commentModifiedTime">{Vtiger_Util_Helper::formatDateDiffInStrings($COMMENT->getModifiedTime())}&nbsp;&nbsp;
														({Vtiger_Util_Helper::convertDateTimeIntoUsersDisplayFormat($COMMENT->getModifiedTime())})</small>
												</p>
											</span>
										{/if}
									</div>
									<div class="row-fluid">
										<div class="pull-right commentActions">
											<span>
												{if $CREATE_PERMISSION}
													<a class="cursorPointer replyComment feedback">
														<i class="icon-share-alt"></i>{vtranslate('LBL_REPLY',$MODULE_NAME)}
													</a>
												{/if}
												{if $CURRENTUSER->getId() eq $COMMENT->get('userid') && $EDIT_PERMISSION}
													{if $CREATE_PERMISSION}&nbsp;<span>|</span>&nbsp;{/if}
													<a class="cursorPointer editComment feedback">
														{vtranslate('LBL_EDIT',$MODULE_NAME)}
													</a>
												{/if}
											</span>
											<span>
												{if $PARENT_COMMENT_MODEL neq false or $CHILD_COMMENTS_MODEL neq null}
													{if $CREATE_PERMISSION || $EDIT_PERMISSION}&nbsp;<span>|</span>&nbsp;{/if}
													<a href="javascript:void(0);"
														class="cursorPointer detailViewThread">{vtranslate('LBL_VIEW_THREAD',$MODULE_NAME)}</a>
												{/if}
											</span>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				{/foreach}
			{else}
				{include file="NoComments.tpl"|@vtemplate_path}
			{/if}
		</div>
		{if $PAGING_MODEL->isNextPageExists()}
			<div class="row-fluid">
				<div class="pull-right">
					<a href="javascript:void(0)" class="moreRecentComments">{vtranslate('LBL_MORE',$MODULE_NAME)}..</a>
				</div>
			</div>
		{/if}
		{if $CREATE_PERMISSION}
			<div class="hide basicAddCommentBlock">
				<div class="row-fluid">
					<span class="span1">&nbsp;</span>
					<div class="span11">
						<textarea class="commentcontenthidden fullWidthAlways" name="commentcontent"
							rows="{$COMMENT_TEXTAREA_DEFAULT_ROWS}"
							placeholder="{vtranslate('LBL_ADD_YOUR_COMMENT_HERE', $MODULE_NAME)}"></textarea>
						<div class="commentAttachmentsArea" style="margin-top:6px;">
							<div class="commentDropZone" style="border:2px dashed #ccc;border-radius:4px;padding:8px 12px;cursor:pointer;color:#888;font-size:12px;margin-bottom:4px;">
								<i class="icon-upload"></i> Dateien hier ablegen oder klicken
								<input type="file" name="comment_files[]" class="commentFileInput" multiple style="display:none;" />
							</div>
							<ul class="commentFileList" style="list-style:none;margin:0;padding:0;font-size:12px;"></ul>
							<div class="commentLinkArea" style="margin-top:4px;display:flex;gap:6px;align-items:center;">
								<input type="text" class="commentLinkInput input-block-level" placeholder="NAS/SMB/URL einfügen..." style="font-size:12px;flex:1;" />
								<button type="button" class="btn btn-mini commentAddLinkBtn"><i class="icon-plus"></i> {vtranslate('LBL_ADD', $MODULE_NAME)}</button>
							</div>
							<ul class="commentLinkList" style="list-style:none;margin:2px 0 0;padding:0;font-size:12px;"></ul>
							<div style="margin-top:4px;">
								<button type="button" class="btn btn-mini commentSelectDocBtn"><i class="icon-file"></i> CRM-Dokument verknüpfen</button>
							</div>
							<ul class="commentDocList" style="list-style:none;margin:2px 0 0;padding:0;font-size:12px;"></ul>
						</div>
					</div>
				</div>
				<div class="pull-right" style="margin-top:6px;">
					<button class="btn btn-success detailViewSaveComment" type="button"
						data-mode="add"><strong>{vtranslate('LBL_POST', $MODULE_NAME)}</strong></button>
					<a class="cursorPointer closeCommentBlock cancelLink"
						type="reset">{vtranslate('LBL_CANCEL', $MODULE_NAME)}</a>
				</div>
			</div>
		{/if}
		{if $EDIT_PERMISSION}
			<div class="hide basicEditCommentBlock" style="min-height: 150px;">
				<div class="row-fluid">
					<span class="span1">&nbsp;</span>
					<div class="span11">
						<input type="text" name="reasonToEdit"
							placeholder="{vtranslate('LBL_REASON_FOR_CHANGING_COMMENT', $MODULE_NAME)}"
							class="input-block-level" />
					</div>
				</div>
				<div class="row-fluid">
					<span class="span1">&nbsp;</span>
					<div class="span11">
						<textarea class="commentcontenthidden fullWidthAlways" name="commentcontent"
							rows="{$COMMENT_TEXTAREA_DEFAULT_ROWS}"></textarea>
					</div>
				</div>
				{if $MODULE_NAME == 'HelpDesk'}
					<div style="display:inline-block; margin-right:20px;">
						<input type="checkbox" id="externalComment" name="externalComment" class="alignTop">&nbsp;
						<label style="display:inline;">{vtranslate('LBL_EXTERNAL_COMMENT', $MODULE_NAME)}</label>
					</div>
				{/if}
				<div class="pull-right">
					<button class="btn btn-success detailViewSaveComment" type="button"
						data-mode="edit"><strong>{vtranslate('LBL_POST', $MODULE_NAME)}</strong></button>
					<a class="cursorPointer closeCommentBlock cancelLink"
						type="reset">{vtranslate('LBL_CANCEL', $MODULE_NAME)}</a>
				</div>
			</div>
		{/if}
	</div>
{/strip}
