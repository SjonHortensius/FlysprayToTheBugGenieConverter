#!/usr/bin/php
<?php
/*
	(C) 2013 Sjon Hortensius

	This file is part of FlysprayToTheBugGenieConverter.

	FlysprayToTheBugGenieConverter is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	FlysprayToTheBugGenieConverter is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with FlysprayToTheBugGenieConverter.  If not, see <http://www.gnu.org/licenses/>.
*/

$fs = new PDO('mysql:host=localhost;dbname=flyspray', 'username', 'password');
$fs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fs->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);

$tb = new PDO('mysql:host=localhost;dbname=thebuggenie', 'username', 'password');
$tb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);

$tb->query("TRUNCATE TABLE `projects`;");
$q = $tb->prepare("INSERT INTO `projects` VALUES (:name, '',0,0,1,1,0,0, :key, '',0,0,'', :description ,0,0,0,0,1,1,1,1, :client, 0,0, :archived, 0,0,0,0,0,0,0,1, :id);");
foreach ($fs->query("SELECT * FROM `projects`")->fetchAll() as $project)
{
	echo 'P';

	$q->execute(array(
		'name' => $project->project_title,
		'key' => preg_replace('~[^a-z]~', '-', strtolower($project->project_title)),
		'client' => 0,
		'archived' => $project->project_is_active,
		'description' => $project->intro_message,
		'id' => $project->project_id,
	));
}

$tb->query("TRUNCATE TABLE `users`");
$q = $tb->prepare("INSERT INTO `users` VALUES (:username, :password, :realname, :username, :email, 1,1,0,'','sys','',1,0,0, :registered, :enabled, 0,1,0, :id);");
foreach ($fs->query("SELECT * FROM `users`")->fetchAll() as $user)
{
	echo 'U';

	$q->execute(array(
		'username' => $user->user_name,
		'password' => '',
		'realname' => $user->real_name,
		'email' => $user->email_address,
		'registered' => $user->register_date,
		'enabled' => $user->account_enabled,
		'id' => $user->user_id,
	));
}

$map_task_types = array(1 => 1, 2 => 2, 5 => 1, 6 => 2, 7 => 1, 8 => 2, 9 => 2, 10 => 1);
$map_task_status = array(1 => 23, 2 => 23, 3 => 27, 4 => 24, 5 => 34);
$map_task_priority = array(1 => 7, 2 => 8, 3 => 5, 4 => 6, 5 => 4, 6 => 39);
$map_task_severity = array(1 => 20, 2 => 20, 3 => 21, 4 => 40, 5 => 22);

$tb->query("TRUNCATE TABLE `issues`");
$q = $tb->prepare("INSERT INTO `issues` VALUES (:name, :id, :type, :project, :description, '', :posted, :updated, :posted_by, 0, :assignee, 0,0,0,0,0, :state, 1, :status, :priority, 0, :severity, '',0,0,0,0,0,0,0,0,0,0, :percent ,0,0, :deleted, 0,0,0,0,0,0,0,0,1, :id);");
foreach ($fs->query("SELECT * FROM `tasks`")->fetchAll() as $task)
{
	echo 'I';

	$q->execute(array(
		'name' => $task->item_summary,
		'id' => $task->task_id,
		'type' => $map_task_types[ $task->task_type ],
		'project' => $task->project_id,
		'description' => $task->item_summary,
		'posted' => $task->date_opened,
		'updated' => $task->last_updated,
		'posted_by' => $task->opened_by,
		'assignee' => $fs->query("SELECT user_id FROM `assigned` WHERE `task_id` = ". $task->task_id)->fetchColumn(0),
		'state' => $task->is_closed,
		'status' => $map_task_status[ $task->item_status ],
		'priority' => $map_task_priority[ $task->task_priority ],
		'severity' => $map_task_severity[ $task->task_severity ],
		'deleted' => 0,
		'percent' => $task->percent_complete,
	));
}

$tb->query("TRUNCATE TABLE `comments`");
$q = $tb->prepare("INSERT INTO comments VALUES(:content, :poster, :updater, :posted, :updated, :issue, 1,1,'core',0, :is_system, :index, 0,1, :id)");
foreach ($fs->query("SELECT * FROM `comments`") as $comment)
{
	echo 'C';

	$q->execute(array(
		'content' => $comment->comment_text,
		'poster' => $comment->user_id,
		'updater' => ($comment->last_edited_time > $comment->date_added) ? $comment->user_id : 0,
		'posted' => $comment->date_added,
		'updated' => ($comment->last_edited_time > $comment->date_added) ? $comment->last_edited_time : 0,
		'issue' => $comment->task_id,
		'is_system' => 0,
		'index' => $tb->query("SELECT COUNT(*) FROM comments WHERE target_id = ". $comment->task_id)->fetchColumn(0),
		'id' => $comment->comment_id,
	));
}

// Group to prevent comment for each property
$grouped_issue_changes = array();
foreach ($fs->query("SELECT * FROM `history` WHERE user_id > 0") as $history)
{
	$k = $history->task_id.':'.$history->user_id.':'.$history->event_date;

	if (!isset($grouped_issue_changes[ $k ]))
		$grouped_issue_changes[ $k ] = array();

	array_push($grouped_issue_changes[ $k ], $history);
}

$events = array(
	2 => 'The issue is closed',
	3 => 'The %s is changed from "%s" to "%s"',
	5 => "Comment %s is changed from %s.to.%s",
	6 => 'Comment %s has been deleted',
	7 => 'Attachment %2$s is added',
	8 => 'Attachment %2$s is deleted',
	9 => 'User "%3$s" is added to notification list',
	10 => 'User "%3$s" is removed from notification list',
	11 => 'Related issue added: Issue #%3$s',
	12 => 'Related issue removed: Issue #%3$s',
	13 => 'Issue is re-opened',
	14 => 'Issue is (re)assigned from "%2$s" to "%3$s"',
	19 => 'User "%2$s" took ownership of issue',
	22 => 'Dependency added: Issue #%3$s',
	23 => 'Other issue is now depending on this issue: Issue #%3$s',
	24 => 'Removed from dependencies: Issue #%3$s',
	25 => 'Other issue no longer depends on this issue: Issue #%3$s',
	26 => 'This issue is made private',
	27 => 'This issue is made public',
	29 => 'Issue is (re)assigned from "%2$s" to "%3$s"',
);

function _user_get_name($id)
{
	static $cache = array();
	global $fs;

	if (!isset($cache[$id]))
		$cache[$id] = $fs->query("SELECT CONCAT(`user_name`, ' (#', `user_id`, ')') FROM users WHERE user_id = ". $id)->fetchColumn(0);
	return $cache[$id];
}

foreach ($grouped_issue_changes as $k => $changes)
{
	echo 'H';
	list($task, $user, $date) = explode(':', $k);

	$content = 'The issue was updated with the following change(s):';
	foreach ($changes as $history)
	{
		if (!isset($events[ $history->event_type ]))
			continue;

		foreach (array('old_value', 'new_value') as $index)
		{
			// Resolve user-ids to names
			if (in_array($history->event_type, array(9, 10, 14, 19, 29)))
			{
				$list = array();
				foreach (explode(' ', $history->$index) as $id)
				{
					if (!empty($id))
						array_push($list, _user_get_name($id));
				}
				$history->$index = implode(', ', $list);
			}
			// Quote comments
			elseif (5 == $history->event_type)
				$history->$index = "\n> ". str_replace("\n", "\n> ", trim($history->$index)) ."\n";
			elseif (3 == $history->event_type)
			{
				switch ($history->field_changed)
				{
					case 'detailed_desc':
					case 'item_summary':	$history->$index = "\n> ". str_replace("\n", "\n> ", trim($history->$index)) ."\n"; break;
					case 'task_type':		$history->$index = $tb->query("SELECT name FROM issuetypes WHERE id = ". $map_task_types[ $history->$index ])->fetchColumn(0); break;
					case 'item_status':		$history->$index = $tb->query("SELECT name FROM listtypes WHERE id = ". $map_task_status[ $history->$index ])->fetchColumn(0); break;
					case 'task_priority': 	$history->$index = $tb->query("SELECT name FROM listtypes WHERE itemtype = 'priority' AND id = ". $map_task_priority[ $history->$index ])->fetchColumn(0); break;
					case 'task_severity':	$history->$index = $tb->query("SELECT name FROM listtypes WHERE itemtype = 'severity' AND id = ". $map_task_severity[ $history->$index ])->fetchColumn(0); break;
				}

			}
		}

		$content .= "\n* ". sprintf($events[ $history->event_type ], $history->field_changed, $history->old_value, $history->new_value);
	}

	if (strlen($content) == 51)
		continue;

	$q->execute(array(
		'content' => $content,
		'poster' => $user,
		'updater' => 0,
		'posted' => $date,
		'updated' => 0,
		'issue' => $task,
		'is_system' => 1,
		'index' => $tb->query("SELECT COUNT(*) FROM comments WHERE target_id = ". $task)->fetchColumn(0),
		'id' => null,
	));
}

foreach ($fs->query("SELECT * FROM `tasks` WHERE `closure_comment` != ''") as $task)
{
	echo 'C';

	$q->execute(array(
		'content' => $task->closure_comment,
		'poster' => $task->closed_by,
		'updater' => 0,
		'posted' => $task->date_closed,
		'updated' => 0,
		'issue' => $task->task_id,
		'is_system' => 0,
		'index' => $tb->query("SELECT COUNT(*) FROM comments WHERE target_id = ". $comment->task_id)->fetchColumn(0),
		'id' => null,
	));
}
