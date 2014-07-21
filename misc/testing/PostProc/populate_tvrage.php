<?php
require_once dirname(__FILE__) . '/../../../www/config.php';

use nzedb\db\Settings;

$c = new ColorCLI();

if (!isset($argv[1]) || $argv[1] != 'true') {
	exit($c->error("\nThis script will download all tvrage shows and insert into the db.\n\n"
			. "php $argv[0] true    ...: To run.\n"));
}

$pdo = new Settings();
$newnames = $updated = 0;

$tvshows = @simplexml_load_file('http://services.tvrage.com/feeds/show_list.php');
if ($tvshows !== false) {
	foreach ($tvshows->show as $rage) {
		$dupecheck = $pdo->queryOneRow(sprintf('SELECT COUNT(id) FROM tvrage WHERE id = %s', $pdo->escapeString($rage->id)));
		if (isset($rage->id) && isset($rage->name) && !empty($rage->id) && !empty($rage->name) && empty($dupecheck)) {
			$pdo->queryInsert(sprintf('INSERT INTO tvrage (rageid, releasetitle, country) VALUES (%s, %s, %s)', $pdo->escapeString($rage->id), $pdo->escapeString($rage->name), $pdo->escapeString($rage->country)));
			$updated++;
		}
	}
} else {
	exit($c->info("TVRage site has a hard limit of 400 concurrent API requests. At the moment, they have reached that limit. Please wait before retrying\n"));
}
if ($updated != 0) {
	echo $c->info("Inserted " . $updated . " new shows into the TvRage table.  To fill out the newly populated TvRage table\n"
	. "php misc/testing/PostProc/updateTvRage.php\n");
} else {
	echo "\n";
	echo $c->info("TvRage database is already up to date!\n");
}
