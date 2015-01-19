<?php
$db = DevblocksPlatform::getDatabaseService();
$logger = DevblocksPlatform::getConsoleLog();
$tables = $db->metaTables();

// ===========================================================================
// Add created/updated fields to server records

if(!isset($tables['server'])) {
	$logger->error("The 'server' table does not exist.");
	return FALSE;
}

list($columns, $indexes) = $db->metaTable('server');

if(!isset($columns['created'])) {
	$db->Execute("ALTER TABLE server ADD COLUMN created INT UNSIGNED DEFAULT 0");
	$db->Execute(sprintf("UPDATE server SET created=%d", time()));
}

if(!isset($columns['updated'])) {
	$db->Execute("ALTER TABLE server ADD COLUMN updated INT UNSIGNED DEFAULT 0");
}

return TRUE;