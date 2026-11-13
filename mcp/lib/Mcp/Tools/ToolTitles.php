<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

use OCA\Mcp\L10n\Translator;

/**
 * Translated display title map for MCP tools, keyed by technical tool name.
 */
final class ToolTitles {
	private function __construct() {
	}

	/**
	 * Human-readable title for a tool in the language of the current user, falling back to the humanized technical name.
	 *
	 * @param string $name technical tool name
	 */
	public static function title(string $name): string {
		// One literal per call: the translation tool extracts the source texts from here.
		return match ($name) {
			// Diagnostics
			'mcp_status' => Translator::t('Check MCP status'),
			'mcp_guide' => Translator::t('Tool guide'),
			// Files
			'files_list' => Translator::t('List files'),
			'files_search' => Translator::t('Search files'),
			'files_read' => Translator::t('Read file'),
			'files_edit' => Translator::t('Edit file'),
			'files_tree' => Translator::t('View folder tree'),
			'files_mkdir' => Translator::t('Create folder'),
			'files_copy' => Translator::t('Copy file'),
			'files_delete' => Translator::t('Delete file or folder'),
			'files_move' => Translator::t('Move file'),
			'files_move_batch' => Translator::t('Move files in bulk'),
			'files_undo_batch' => Translator::t('Undo bulk operation'),
			'files_replace' => Translator::t('Replace part of a file'),
			'files_checkout' => Translator::t('Download file for local editing'),
			'files_upload' => Translator::t('Upload new file'),
			'files_create' => Translator::t('Create text file'),
			'files_versions_list' => Translator::t('List file versions'),
			'files_read_comments' => Translator::t('Read file comments'),
			'files_add_comment' => Translator::t('Add file comment'),
			'files_version_read' => Translator::t('Read file version'),
			'files_version_restore' => Translator::t('Restore file version'),
			'files_list_tags' => Translator::t('List file tags'),
			'files_set_tag' => Translator::t('Add or remove a file tag'),
			'files_image_view' => Translator::t('View image'),
			'files_images_view' => Translator::t('View several images'),
			'files_image_search' => Translator::t('Search images'),
			'files_list_shares' => Translator::t('List shares'),
			'files_share' => Translator::t('Share file or folder'),
			'files_unshare' => Translator::t('Stop sharing file or folder'),
			// Notes
			'notes_list' => Translator::t('List notes'),
			'notes_search' => Translator::t('Search notes'),
			'notes_read' => Translator::t('Read note'),
			'notes_create' => Translator::t('Create note'),
			'notes_edit' => Translator::t('Edit note'),
			'notes_move' => Translator::t('Move note'),
			'notes_delete' => Translator::t('Delete note'),
			// Calendar
			'calendar_list_calendars' => Translator::t('List calendars'),
			'calendar_list_events' => Translator::t('List events'),
			'calendar_check_availability' => Translator::t('Check availability'),
			'calendar_suggest_times' => Translator::t('Suggest meeting times'),
			'calendar_create_event' => Translator::t('Create event'),
			'calendar_update_event' => Translator::t('Edit event'),
			'calendar_move_event' => Translator::t('Move event to another calendar'),
			'calendar_transfer_event' => Translator::t('Transfer event to another person\'s calendar'),
			'calendar_delete_event' => Translator::t('Delete event'),
			// Contacts
			'contacts_list_addressbooks' => Translator::t('List address books'),
			'contacts_search_contacts' => Translator::t('Search contacts'),
			'contacts_read_contact' => Translator::t('Read contact'),
			'contacts_create_contact' => Translator::t('Create contact'),
			'contacts_edit_contact' => Translator::t('Edit contact'),
			'contacts_delete_contact' => Translator::t('Delete contact'),
			// Tasks
			'tasks_list_calendars' => Translator::t('List task calendars'),
			'tasks_list_tasks' => Translator::t('List tasks'),
			'tasks_read_task' => Translator::t('Read task'),
			'tasks_create_task' => Translator::t('Create task'),
			'tasks_edit_task' => Translator::t('Edit task'),
			'tasks_complete_task' => Translator::t('Complete task'),
			'tasks_delete_task' => Translator::t('Delete task'),
			// Deck
			'deck_list_boards' => Translator::t('List Deck boards'),
			'deck_list_stacks' => Translator::t('List Deck lists'),
			'deck_create_label' => Translator::t('Create Deck label'),
			'deck_update_label' => Translator::t('Change Deck label'),
			'deck_delete_label' => Translator::t('Delete Deck label'),
			'deck_list_labels' => Translator::t('List Deck labels'),
			'deck_edit_card_labels' => Translator::t('Change Deck card labels'),
			'deck_list_cards' => Translator::t('List Deck cards'),
			'deck_list_card_files' => Translator::t('List Deck card files'),
			'deck_unlink_card_file' => Translator::t('Unlink Deck card file'),
			'deck_link_card_file' => Translator::t('Link file to Deck card'),
			'deck_add_card_dependency' => Translator::t('Add Deck card dependency'),
			'deck_remove_card_dependency' => Translator::t('Remove Deck card dependency'),
			'deck_read_card_dependencies' => Translator::t('Read Deck card dependencies'),
			'deck_read_card' => Translator::t('Read Deck card'),
			'deck_create_card' => Translator::t('Create card in Deck'),
			'deck_edit_card' => Translator::t('Edit Deck card'),
			'deck_move_card' => Translator::t('Move card in Deck'),
			'deck_delete_card' => Translator::t('Delete Deck card'),
			'deck_followup_cards' => Translator::t('Follow up on Deck tasks'),
			'deck_create_board' => Translator::t('Create Deck board'),
			'deck_create_stack' => Translator::t('Create list in Deck'),
			'deck_delete_stack' => Translator::t('Delete Deck list'),
			'deck_delete_board' => Translator::t('Delete Deck board'),
			'deck_set_card_done' => Translator::t('Change Deck card completion'),
			'deck_set_card_archived' => Translator::t('Change Deck card archive state'),
			// Talk
			'talk_list_conversations' => Translator::t('List Talk conversations'),
			'talk_list_unread' => Translator::t('List unread Talk conversations'),
			'talk_read_messages' => Translator::t('Read Talk messages'),
			'talk_read_unread' => Translator::t('Read unread Talk messages'),
			'talk_reply' => Translator::t('Reply in Talk'),
			'talk_attach_file' => Translator::t('Attach file in Talk'),
			'talk_quote_file' => Translator::t('Quote file in Talk'),
			'talk_message_user' => Translator::t('Direct message in Talk'),
			'talk_send_batch' => Translator::t('Send batch in Talk'),
			'talk_create_group' => Translator::t('Create group in Talk'),
			// People
			'users_search' => Translator::t('Search people'),
			// Logs
			'logs_list' => Translator::t('Read server log'),
			'logs_analyze' => Translator::t('Analyze server log'),
			// Mail
			'mail_list_accounts' => Translator::t('List mail accounts'),
			'mail_list_mailboxes' => Translator::t('List mail folders'),
			'mail_list_messages' => Translator::t('List mail messages'),
			'mail_search_messages' => Translator::t('Search mail messages'),
			'mail_read_message' => Translator::t('Read a mail message'),
			'mail_read_thread' => Translator::t('Read a mail conversation'),
			'mail_read_attachment' => Translator::t('Read a mail attachment'),
			'mail_set_flags' => Translator::t('Mark a mail message read or flagged'),
			'mail_move_message' => Translator::t('Move a mail message to another folder'),
			'mail_create_draft' => Translator::t('Create a mail draft'),
			'mail_send_message' => Translator::t('Send a mail message'),
			'mail_save_attachment' => Translator::t('Save a mail attachment to Files'),
			// Forms
			'forms_list_forms' => Translator::t('List forms'),
			'forms_read_form' => Translator::t('Read form'),
			'forms_list_submissions' => Translator::t('List form submissions'),
			'forms_read_submission' => Translator::t('Read form submission'),
			'forms_create_form' => Translator::t('Create a form'),
			'forms_update_question' => Translator::t('Edit a form question'),
			'forms_update_option' => Translator::t('Edit a form option'),
			'forms_add_question' => Translator::t('Add a form question'),
			'forms_reorder_questions' => Translator::t('Reorder form questions'),
			'forms_add_option' => Translator::t('Add form options'),
			'forms_reorder_options' => Translator::t('Reorder form options'),
			'forms_delete_question' => Translator::t('Delete a form question'),
			'forms_delete_option' => Translator::t('Delete a form option'),
			'forms_change_question_type' => Translator::t('Change a form question type'),
			// Polls
			'polls_list_polls' => Translator::t('List polls'),
			'polls_read_poll' => Translator::t('Read poll'),
			'polls_list_votes' => Translator::t('Read poll votes'),
			'polls_read_results' => Translator::t('Read poll results'),
			'polls_list_links' => Translator::t('Read existing poll links'),
			'polls_create_poll' => Translator::t('Create a private poll'),
			'polls_update_poll' => Translator::t('Configure a poll'),
			'polls_set_poll_state' => Translator::t('Change poll state'),
			'polls_add_option' => Translator::t('Add a poll option'),
			'polls_update_option' => Translator::t('Edit a poll option'),
			'polls_reorder_options' => Translator::t('Reorder poll options'),
			'polls_remove_option' => Translator::t('Remove a poll option'),
			'polls_restore_option' => Translator::t('Restore a poll option'),
			'polls_add_share' => Translator::t('Share a poll'),
			'polls_update_share' => Translator::t('Change poll sharing'),
			'polls_invite_share' => Translator::t('Invite a poll recipient'),
			// Collectives
			'collectives_list' => Translator::t('List collectives'),
			'collectives_pages' => Translator::t('List collective pages'),
			'collectives_read' => Translator::t('Read collective page'),
			'collectives_search' => Translator::t('Search collective pages'),
			'collectives_create' => Translator::t('Create collective page'),
			'collectives_edit' => Translator::t('Edit collective page'),
			'collectives_move' => Translator::t('Move collective page'),
			'collectives_create_collective' => Translator::t('Create collective'),
			'collectives_list_members' => Translator::t('List collective members'),
			'collectives_add_member' => Translator::t('Add collective member'),
			'collectives_set_member_level' => Translator::t('Change collective member level'),
			'collectives_remove_member' => Translator::t('Remove collective member'),
			// Tables
			'tables_list_tables' => Translator::t('List tables'),
			'tables_list_views' => Translator::t('List table views'),
			'tables_list_columns' => Translator::t('List table columns'),
			'tables_list_rows' => Translator::t('List table rows'),
			'tables_create_row' => Translator::t('Create table row'),
			'tables_update_row' => Translator::t('Update table row'),
			'tables_create_table' => Translator::t('Create table'),
			'tables_update_table' => Translator::t('Update table'),
			'tables_create_column' => Translator::t('Create table column'),
			'tables_update_column' => Translator::t('Update table column'),
			'tables_create_view' => Translator::t('Create table view'),
			'tables_update_view' => Translator::t('Update table view'),
			'tables_share_node' => Translator::t('Share table or view'),
			'tables_unshare_node' => Translator::t('Revoke table or view share'),
			// Activity
			'activity_list_events' => Translator::t('List own activity'),
			'notifications_list' => Translator::t('List own notifications'),
			'notifications_dismiss' => Translator::t('Dismiss own notification'),
			// Availability
			'availability_read_absence' => Translator::t('Read own absence'),
			// Status
			'status_read_own' => Translator::t('Read own status'),
			'status_set_type' => Translator::t('Set own status type'),
			'status_set_message' => Translator::t('Set own status message'),
			'availability_set_absence' => Translator::t('Set own absence'),
			// Routines
			'routines_create_meeting' => Translator::t('Create meeting'),
			'routines_create_mail_card' => Translator::t('Create card from email'),
			default => ToolPresentation::humanized($name),
		};
	}
}
