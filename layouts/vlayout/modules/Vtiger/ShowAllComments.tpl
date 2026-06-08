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

{* Change to this also refer: RecentComments.tpl *}
{assign var="COMMENT_TEXTAREA_DEFAULT_ROWS" value="2"}

{strip}
<div class="commentContainer">
	<div class="commentTitle row-fluid">
		{assign var=CREATE_PERMISSION value=$COMMENTS_MODULE_MODEL->isPermitted('CreateView')}
		{assign var=EDIT_PERMISSION value=$COMMENTS_MODULE_MODEL->isPermitted('EditView')}
		{if $CREATE_PERMISSION}
			<div class="addCommentBlock">
				<div>
					<textarea name="commentcontent" rows="{$COMMENT_TEXTAREA_DEFAULT_ROWS}" class="commentcontent"
						placeholder="{vtranslate('LBL_ADD_YOUR_COMMENT_HERE', $MODULE_NAME)}"></textarea>
				</div>
				<div class="commentAttachmentsArea" style="margin-top:6px;">
					<div class="commentDropZone" style="border:2px dashed #ccc;border-radius:4px;padding:8px 12px;cursor:pointer;color:#888;font-size:12px;margin-bottom:4px;">
						<i class="icon-upload"></i> {vtranslate('LBL_COMMENT_DROP_FILES', $MODULE_NAME)}
						<input type="file" name="comment_files[]" class="commentFileInput" multiple style="display:none;" />
					</div>
					<ul class="commentFileList" style="list-style:none;margin:0;padding:0;font-size:12px;"></ul>
					<div class="commentLinkArea" style="margin-top:4px;display:flex;gap:6px;align-items:center;">
						<input type="text" class="commentLinkInput input-block-level" placeholder="{vtranslate('LBL_COMMENT_LINK_PLACEHOLDER', $MODULE_NAME)}" style="font-size:12px;flex:1;" />
						<button type="button" class="btn btn-mini commentAddLinkBtn"><i class="icon-plus"></i> {vtranslate('LBL_ADD', $MODULE_NAME)}</button>
					</div>
					<ul class="commentLinkList" style="list-style:none;margin:2px 0 0;padding:0;font-size:12px;"></ul>
					<div style="margin-top:4px;">
						<button type="button" class="btn btn-mini commentSelectDocBtn"><i class="icon-file"></i> {vtranslate('LBL_COMMENT_LINK_DOCUMENT', $MODULE_NAME)}</button>
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
					<button class="btn btn-success saveComment" type="button"
						data-mode="add"><strong>{vtranslate('LBL_POST', $MODULE_NAME)}</strong></button>
				</div>
				{if $MODULE_NAME == 'HelpDesk'}
					<div class="pull-right" style="margin-top:6px;">
						<button class="btn saveButton saveComment" type="button"
							data-mode="sendMail"><strong>{vtranslate('LBL_SEND_MAIL_AND_POST', $MODULE_NAME)}</strong></button>
					</div>
				{/if}
			</div>
		{/if}
	</div>
	<br>
	<div class="commentsList commentsBody">
		{include file='CommentsList.tpl'|@vtemplate_path COMMENT_MODULE_MODEL=$COMMENTS_MODULE_MODEL}
	</div>
	{if $CREATE_PERMISSION}
		<div class="hide basicAddCommentBlock">
			<div class="row-fluid">
				<span class="span1">&nbsp;</span>
				<div class="span11">
					<textarea class="commentcontenthidden fullWidthAlways" rows="{$COMMENT_TEXTAREA_DEFAULT_ROWS}"
						name="commentcontent"
						placeholder="{vtranslate('LBL_ADD_YOUR_COMMENT_HERE', $MODULE_NAME)}"></textarea>
					<div class="commentAttachmentsArea" style="margin-top:6px;">
						<div class="commentDropZone" style="border:2px dashed #ccc;border-radius:4px;padding:8px 12px;cursor:pointer;color:#888;font-size:12px;margin-bottom:4px;">
							<i class="icon-upload"></i> {vtranslate('LBL_COMMENT_DROP_FILES', $MODULE_NAME)}
							<input type="file" name="comment_files[]" class="commentFileInput" multiple style="display:none;" />
						</div>
						<ul class="commentFileList" style="list-style:none;margin:0;padding:0;font-size:12px;"></ul>
						<div class="commentLinkArea" style="margin-top:4px;display:flex;gap:6px;align-items:center;">
							<input type="text" class="commentLinkInput input-block-level" placeholder="{vtranslate('LBL_COMMENT_LINK_PLACEHOLDER', $MODULE_NAME)}" style="font-size:12px;flex:1;" />
							<button type="button" class="btn btn-mini commentAddLinkBtn"><i class="icon-plus"></i> {vtranslate('LBL_ADD', $MODULE_NAME)}</button>
						</div>
						<ul class="commentLinkList" style="list-style:none;margin:2px 0 0;padding:0;font-size:12px;"></ul>
						<div style="margin-top:4px;">
							<button type="button" class="btn btn-mini commentSelectDocBtn"><i class="icon-file"></i> {vtranslate('LBL_COMMENT_LINK_DOCUMENT', $MODULE_NAME)}</button>
						</div>
						<ul class="commentDocList" style="list-style:none;margin:2px 0 0;padding:0;font-size:12px;"></ul>
					</div>
				</div>
			</div>
			<div class="pull-right" style="margin-top:6px;">
				<button class="btn btn-success saveComment" type="button"
					data-mode="add"><strong>{vtranslate('LBL_POST', $MODULE_NAME)}</strong></button>
				<a class="cursorPointer closeCommentBlock" type="reset">{vtranslate('LBL_CANCEL', $MODULE_NAME)}</a>
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
				<button class="btn btn-success saveComment" type="button"
					data-mode="edit"><strong>{vtranslate('LBL_POST', $MODULE_NAME)}</strong></button>
				<a class="cursorPointer closeCommentBlock cancelLink"
					type="reset">{vtranslate('LBL_CANCEL', $MODULE_NAME)}</a>
			</div>
		</div>
	{/if}
</div>
{/strip}
