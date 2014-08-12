<?php

use nzedb\db\Settings;
use nzedb\utility\Utility;

/**
 * Class NameFixer
 */
class NameFixer
{
	CONST PREDB_REGEX = '/([\w\(\)]+[\._]([\w\(\)]+[\._-])+[\w\(\)]+-\w+)/';

	/**
	 * Has the current release found a new name?
	 * @var bool
	 */
	public $matched;

	/**
	 * How many releases have got a new name?
	 * @var int
	 */
	public $fixed;

	/**
	 * How many releases were checked.
	 * @var int
	 */
	public $checked;

	/**
	 * Total releases we are working on.
	 * @var int
	 */
	protected $_totalReleases;

	/**
	 * @var nzedb\db\Settings
	 */
	public $pdo;

	/**
	 * @param array $options Class instances / Echo to cli.
	 */
	public function __construct(array $options = array())
	{
		$defaults = [
			'Echo'         => true,
			'Categorize'   => null,
			'ConsoleTools' => null,
			'Groups'       => null,
			'Utility'      => null,
			'Settings'     => null,
		];
		$options += $defaults;

		$this->echooutput = ($options['Echo'] && nZEDb_ECHOCLI);
		$this->relid = $this->fixed = $this->checked = 0;
		$this->pdo = ($options['Settings'] instanceof Settings ? $options['Settings'] : new Settings());
		$this->timeother = ' AND rel.adddate > (NOW() - INTERVAL 0 HOUR) AND rel.categoryid IN (1090, 2020, 3050, 6050, 5050, 7010, 8050) GROUP BY rel.id ORDER BY postdate DESC';
		$this->timeall = ' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) GROUP BY rel.id ORDER BY postdate DESC';
		$this->fullother = ' AND rel.categoryid IN (1090, 2020, 3050, 6050, 5050, 7010, 8050) GROUP BY rel.id';
		$this->fullall = '';
		$this->done = $this->matched = false;
		$this->consoletools = ($options['ConsoleTools'] instanceof ConsoleTools ? $options['ConsoleTools'] :new ConsoleTools(['ColorCLI' => $this->pdo->log]));
		$this->category = ($options['Categorize'] instanceof Categorize ? $options['Categorize'] : new Categorize(['Settings' => $this->pdo]));
		$this->utility = ($options['Utility'] instanceof Utility ? $options['Utility'] :new Utility());
		$this->_groups = ($options['Groups'] instanceof Groups ? $options['Groups'] : new Groups(['Settings' => $this->pdo]));
	}

	/**
	 * Attempts to fix release names using the NFO.
	 *
	 * @param int $time    1: 24 hours, 2: no time limit
	 * @param int $echo    1: change the name, anything else: preview of what could have been changed.
	 * @param int $cats    1: other categories, 2: all categories
	 * @param $nameStatus
	 * @param $show
	 */
	public function fixNamesWithNfo($time, $echo, $cats, $nameStatus, $show)
	{
		$this->_echoStartMessage($time, '.nfo files');
		$type = 'NFO, ';

		// Only select releases we haven't checked here before
		$preId = false;
		if ($cats === 3) {
			$query = '
				SELECT rel.id AS releaseid
				FROM releases rel
				INNER JOIN releasenfo nfo ON (nfo.releaseid = rel.id)
				WHERE nzbstatus = 1
				AND preid = 0';
			$cats = 2;
			$preId = true;
		} else {
			$query = '
				SELECT rel.id AS releaseid
				FROM releases rel
				INNER JOIN releasenfo nfo ON (nfo.releaseid = rel.id)
				WHERE (isrenamed = 0 OR rel.categoryid = 7010)
				AND proc_nfo = 0';
		}

		$releases = $this->_getReleases($time, $cats, $query);
		if ($releases !== false) {

			$total = $releases->rowCount();
			if ($total > 0) {
				$this->_totalReleases = $total;
				echo $this->pdo->log->primary(number_format($total) . ' releases to process.');

				foreach ($releases as $rel) {
					$releaseRow = $this->pdo->queryOneRow(
						sprintf('
							SELECT nfo.releaseid AS nfoid, rel.group_id, rel.categoryid, rel.name, rel.searchname,
								UNCOMPRESS(nfo) AS textstring, rel.id AS releaseid
							FROM releases rel
							INNER JOIN releasenfo nfo ON (nfo.releaseid = rel.id)
							WHERE rel.id = %d',
							$rel['releaseid']
						)
					);

					$this->checked++;

					// Ignore encrypted NFOs.
					if (preg_match('/^=newz\[NZB\]=\w+/', $releaseRow['textstring'])) {
						$this->pdo->queryExec(
							sprintf('UPDATE releases SET proc_nfo = 1 WHERE id = %d', $releaseRow['releaseid'])
						);
						continue;
					}

					$this->done = $this->matched = false;
					$this->checkName($releaseRow, $echo, $type, $nameStatus, $show, $preId);
					$this->_echoRenamed($show);
				}
				$this->_echoFoundCount($echo, ' NFO\'s');
			} else {
				echo $this->pdo->log->info('Nothing to fix.');
			}
		}
	}

	/**
	 * Attempts to fix release names using the File name.
	 *
	 * @param int $time   1: 24 hours, 2: no time limit
	 * @param int $echo   1: change the name, anything else: preview of what could have been changed.
	 * @param int $cats   1: other categories, 2: all categories
	 * @param $nameStatus
	 * @param $show
	 */
	public function fixNamesWithFiles($time, $echo, $cats, $nameStatus, $show)
	{
		$this->_echoStartMessage($time, 'file names');
		$type = 'Filenames, ';

		$preId = false;
		if ($cats === 3) {
			$query = '
				SELECT relfiles.name AS textstring, rel.categoryid, rel.name, rel.searchname, rel.group_id,
					relfiles.releaseid AS fileid, rel.id AS releaseid
				FROM releases rel
				INNER JOIN releasefiles relfiles ON (relfiles.releaseid = rel.id)
				WHERE nzbstatus = 1
				AND preid = 0';

			$cats = 2;
			$preId = true;
		} else {
			$query = '
				SELECT relfiles.name AS textstring, rel.categoryid, rel.name, rel.searchname, rel.group_id,
					relfiles.releaseid AS fileid, rel.id AS releaseid
				FROM releases rel
				INNER JOIN releasefiles relfiles ON (relfiles.releaseid = rel.id)
				WHERE (isrenamed = 0 OR rel.categoryid = 7010)
				AND proc_files = 0';
		}

		$releases = $this->_getReleases($time, $cats, $query);
		if ($releases !== false) {

			$total = $releases->rowCount();
			if ($total > 0) {
				$this->_totalReleases = $total;
				echo $this->pdo->log->primary(number_format($total) . ' file names to process.');

				foreach ($releases as $release) {
					$this->done = $this->matched = false;
					$this->checkName($release, $echo, $type, $nameStatus, $show, $preId);
					$this->checked++;
					$this->_echoRenamed($show);
				}

				$this->_echoFoundCount($echo, ' files');
			} else {
				echo $this->pdo->log->info('Nothing to fix.');
			}
		}
	}

	/**
	 * Attempts to fix release names using the Par2 File.
	 *
	 * @param int $time   1: 24 hours, 2: no time limit
	 * @param int $echo   1: change the name, anything else: preview of what could have been changed.
	 * @param int $cats   1: other categories, 2: all categories
	 * @param $nameStatus
	 * @param $show
	 * @param NNTP $nntp
	 */
	public function fixNamesWithPar2($time, $echo, $cats, $nameStatus, $show, $nntp)
	{
		$this->_echoStartMessage($time, 'par2 files');

		if ($cats === 3) {
			$query = 'SELECT rel.id AS releaseid, rel.guid, rel.group_id FROM releases rel WHERE nzbstatus = 1 AND preid = 0';
			$cats = 2;
		} else {
			$query = 'SELECT rel.id AS releaseid, rel.guid, rel.group_id FROM releases rel WHERE (isrenamed = 0 OR rel.categoryid = 7010) AND proc_par2 = 0';
		}

		$releases = $this->_getReleases($time, $cats, $query);
		if ($releases !== false) {

			$total = $releases->rowCount();
			if ($total > 0) {
				$this->_totalReleases = $total;

				echo $this->pdo->log->primary(number_format($total) . ' releases to process.');
				$Nfo = new Nfo(['Echo' => $this->echooutput, 'Settings' => $this->pdo]);
				$nzbContents = new NZBContents(
					[
						'Echo' => $this->echooutput,
						'NNTP' => $nntp,
						'Nfo'  => $Nfo,
						'Settings'   => $this->pdo,
						'PostProcess' => new PostProcess(['Settings' => $this->pdo, 'Nfo' => $Nfo])
					]
				);

				foreach ($releases as $release) {
					if (($nzbContents->checkPAR2($release['guid'], $release['releaseid'], $release['group_id'], $nameStatus, $show)) === true) {
						$this->fixed++;
					}

					$this->checked++;
					$this->_echoRenamed($show);
				}
				$this->_echoFoundCount($echo, ' files');
			} else {
				echo $this->pdo->log->alternate('Nothing to fix.');
			}
		}
	}

	/**
	 * @param int    $time  1: 24 hours, 2: no time limit
	 * @param int    $cats  1: other categories, 2: all categories
	 * @param string $query Query to execute.
	 *
	 * @return PDOStatement|bool False on failure, PDOStatement with query results on success.
	 */
	protected function _getReleases($time, $cats, $query)
	{
		$releases = false;
		// 24 hours, other cats
		if ($time == 1 && $cats == 1) {
			echo $this->pdo->log->header($query . $this->timeother . ";\n");
			$releases = $this->pdo->queryDirect($query . $this->timeother);
		} // 24 hours, all cats
		else if ($time == 1 && $cats == 2) {
			echo $this->pdo->log->header($query . $this->timeall . ";\n");
			$releases = $this->pdo->queryDirect($query . $this->timeall);
		} //other cats
		else if ($time == 2 && $cats == 1) {
			echo $this->pdo->log->header($query . $this->fullother . ";\n");
			$releases = $this->pdo->queryDirect($query . $this->fullother);
		}
		// all cats
		else if ($time == 2 && $cats == 2) {
			echo $this->pdo->log->header($query . $this->fullall . ";\n");
			$releases = $this->pdo->queryDirect($query . $this->fullall);
		}
		return $releases;
	}

	/**
	 * Echo the amount of releases that found a new name.
	 *
	 * @param int    $echo 1: change the name, anything else: preview of what could have been changed.
	 * @param string $type The function type that found the name.
	 */
	protected function _echoFoundCount($echo, $type)
	{
		if ($echo == 1) {
			echo $this->pdo->log->header(
				PHP_EOL .
				number_format($this->fixed) .
				' releases have had their names changed out of: ' .
				number_format($this->checked) .
				$type . '.'
			);
		} else {
			echo $this->pdo->log->header(
				PHP_EOL .
				number_format($this->fixed) .
				' releases could have their names changed. ' .
				number_format($this->checked) .
				$type . ' were checked.'
			);
		}
	}

	/**
	 * @param int    $time 1: 24 hours, 2: no time limit
	 * @param string $type The function type.
	 */
	protected function _echoStartMessage($time, $type)
	{
		echo $this->pdo->log->header(
			sprintf(
				'Fixing search names %s using %s.',
				($time == 1 ? 'in the past 6 hours' : 'since the beginning'),
				$type
			)
		);

	}

	/**
	 * @param int $show
	 */
	protected function _echoRenamed($show)
	{
		if ($this->checked % 500 == 0 && $show === 1) {
			echo $this->pdo->log->alternate(PHP_EOL . number_format($this->checked) . ' files processed.' . PHP_EOL);
		}

		if ($show === 2) {
			$this->consoletools->overWritePrimary(
				'Renamed Releases: [' .
				number_format($this->fixed) .
				'] ' .
				$this->consoletools->percentString($this->checked, $this->_totalReleases)
			);
		}
	}

	/**
	 * Update the release with the new information.
	 *
	 * @param array  $release
	 * @param string $name
	 * @param string $method
	 * @param int    $echo
	 * @param string $type
	 * @param int    $nameStatus
	 * @param int    $show
	 * @param int    $preId
	 */
	public function updateRelease($release, $name, $method, $echo, $type, $nameStatus, $show, $preId = 0)
	{
		if ($this->relid !== $release['releaseid']) {
			$releaseCleaning = new ReleaseCleaning($this->pdo);
			$newName = $releaseCleaning->fixerCleaner($name);
			if (strtolower($newName) != strtolower($release["searchname"])) {
				$this->matched = true;
				$this->relid = $release["releaseid"];

				$determinedCategory = $this->category->determineCategory($newName, $release['group_id']);

				if ($type === "PAR2, ") {
					$newName = ucwords($newName);
					if (preg_match('/(.+?)\.[a-z0-9]{2,3}(PAR2)?$/i', $name, $match)) {
						$newName = $match[1];
					}
				}

				$this->fixed++;

				$newName = explode("\\", $newName);
				$newName = preg_replace(array('/^[-=_\.:\s]+/', '/[-=_\.:\s]+$/'), '',  $newName[0]);

				if ($this->echooutput === true && $show === 1) {
					$groupName = $this->_groups->getByNameByID($release['group_id']);
					$oldCatName = $this->category->getNameByID($release['categoryid']);
					$newCatName = $this->category->getNameByID($determinedCategory);

					if ($type === "PAR2, ") {
						echo PHP_EOL;
					}

					echo
						$this->pdo->log->headerOver("\nNew name:  ") .
						$this->pdo->log->primary($newName) .
						$this->pdo->log->headerOver("Old name:  ") .
						$this->pdo->log->primary($release["searchname"]) .
						$this->pdo->log->headerOver("Use name:  ") .
						$this->pdo->log->primary($release["name"]) .
						$this->pdo->log->headerOver("New cat:   ") .
						$this->pdo->log->primary($newCatName) .
						$this->pdo->log->headerOver("Old cat:   ") .
						$this->pdo->log->primary($oldCatName) .
						$this->pdo->log->headerOver("Group:     ") .
						$this->pdo->log->primary($groupName) .
						$this->pdo->log->headerOver("Method:    ") .
						$this->pdo->log->primary($type . $method) .
						$this->pdo->log->headerOver("ReleaseID: ") .
						$this->pdo->log->primary($release["releaseid"]);
					if (isset($release['filename']) && $release['filename'] != ""){
						echo
							$this->pdo->log->headerOver("Filename:  ") .
							$this->pdo->log->primary($release["filename"]);
					}

					if ($type !== "PAR2, ") {
						echo "\n";
					}
				}

				if ($echo == 1) {
					if ($nameStatus == 1) {
						$status = '';
						switch ($type) {
							case "NFO, ":
								$status = "isrenamed = 1, iscategorized = 1, proc_nfo = 1,";
								break;
							case "PAR2, ":
								$status = "isrenamed = 1, iscategorized = 1, proc_par2 = 1,";
								break;
							case "Filenames, ":
							case "file matched source: ":
								$status = "isrenamed = 1, iscategorized = 1, proc_files = 1,";
								break;
							case "SHA1, ":
							case "MD5, ":
								$status = "isrenamed = 1, iscategorized = 1, dehashstatus = 1,";
								break;
							case "PreDB FT Exact, ":
								$status = "isrenamed = 1, iscategorized = 1,";
								break;
						}
						$this->pdo->queryExec(
							sprintf('
								UPDATE releases
								SET rageid = -1, seriesfull = NULL, season = NULL, episode = NULL,
									tvtitle = NULL, tvairdate = NULL, imdbid = NULL, musicinfoid = NULL,
									consoleinfoid = NULL, bookinfoid = NULL, anidbid = NULL, preid = %d,
									searchname = %s, %s categoryid = %d
								WHERE id = %d',
								$preId,
								$this->pdo->escapeString(substr($newName, 0, 255)),
								$status,
								$determinedCategory,
								$release['releaseid']
							)
						);
					} else {
						$this->pdo->queryExec(
							sprintf('
								UPDATE releases
								SET rageid = -1, seriesfull = NULL, season = NULL, episode = NULL,
									tvtitle = NULL, tvairdate = NULL, imdbid = NULL, musicinfoid = NULL,
									consoleinfoid = NULL, bookinfoid = NULL, anidbid = NULL, preid = %d,
									searchname = %s, iscategorized = 1, categoryid = %d
								WHERE id = %d',
								$preId,
								$this->pdo->escapeString(substr($newName, 0, 255)),
								$determinedCategory,
								$release['releaseid']
							)
						);
					}
				}
			}
		}
		$this->done = true;
	}

	/**
	 * Echo a updated release name to CLI.
	 *
	 * @param array $data
	 *        array(
	 *              'new_name'     => (string) The new release search name.
	 *              'old_name'     => (string) The old release search name.
	 *              'new_category' => (string) The new category name or ID for the release.
	 *              'old_category' => (string) The old category name or ID for the release.
	 *              'group'        => (string) The group name or ID of the release.
	 *              'release_id'   => (int)    The ID of the release.
	 *              'method'       => (string) The method used to rename the release.
	 *        )
	 *
	 * @access public
	 * @static
	 * @void
	 */
	public static function echoChangedReleaseName(array $data =
		array(
			'new_name'     => '',
			'old_name'     => '',
			'new_category' => '',
			'old_category' => '',
			'group'        => '',
			'release_id'   => 0,
			'method'       => ''
		)
	) {
		echo
			PHP_EOL .
			ColorCLI::headerOver('New name:     ') . ColorCLI::primaryOver($data['new_name']) . PHP_EOL .
			ColorCLI::headerOver('Old name:     ') . ColorCLI::primaryOver($data['old_name']) . PHP_EOL .
			ColorCLI::headerOver('New category: ') . ColorCLI::primaryOver($data['new_category']) . PHP_EOL .
			ColorCLI::headerOver('Old category: ') . ColorCLI::primaryOver($data['old_category']) . PHP_EOL .
			ColorCLI::headerOver('Group:        ') . ColorCLI::primaryOver($data['group']) . PHP_EOL .
			ColorCLI::headerOver('Release ID:   ') . ColorCLI::primaryOver($data['release_id']) . PHP_EOL .
			ColorCLI::headerOver('Method:       ') . ColorCLI::primaryOver($data['method']) . PHP_EOL;
	}

	// Match a PreDB title to a release name or searchname using an exact full-text match
	public function matchPredbFT($pre, $echo, $namestatus, $echooutput, $show)
	{
		$matching = $total = 0;

		//Remove all non-printable chars from PreDB title
		preg_match_all('#[a-zA-Z0-9]{3,}#', $pre['title'], $matches, PREG_PATTERN_ORDER);
		$titlematch = '+' . implode(' +', $matches[0]);

		//Find release matches with fulltext and then identify exact matches with cleaned LIKE string
		$res = $this->pdo->queryDirect(
						sprintf("
							SELECT rs.releaseid AS releaseid, rs.name, rs.searchname,
								r.group_id, r.categoryid
							FROM releasesearch rs
							INNER JOIN releases r ON rs.releaseid = r.id
							WHERE MATCH (rs.name, rs.searchname) AGAINST ('%1\$s' IN BOOLEAN MODE)
							AND r.preid = 0
							AND (r.name %2\$s OR r.searchname %2\$s)
							LIMIT 21",
							$titlematch,
							$this->pdo->likeString($pre['title'], true, true)
						)
		);

		if ($res !== false) {
			$total = $res->rowCount();
		}

		// Run if row count is positive, but do not run if row count exceeds 10 (as this is likely a failed title match)
		if ($total > 0 && $total <= 10) {
			foreach ($res as $row) {
					if ($pre['title'] !== $row['searchname']) {
						$this->updateRelease($row, $pre['title'], $method = "Title Match source: " . $pre['source'], $echo, "PreDB FT Exact, ", $namestatus, $show, $pre['preid']);
						$matching++;
					}
			}
		} elseif ($total >= 11) {
			$matching = -1;
		}
		return $matching;
	}

	public function getPreFileNames($args)
	{
		$timestart = time();
		$total = $counter = $counted = 0;
		$limit = $orderby = '';
		$show = (isset($args[2]) && $args[2] === 'show') ? 1 : 0;

		if (isset($args[1]) && is_numeric($args[1])) {
			$orderby = "ORDER BY r.id DESC";
			$limit = "LIMIT " . $args[1];
		}

		echo $this->pdo->log->header("\nMatch PreFiles (${args[1]}) Started at " . date('g:i:s'));
		echo $this->pdo->log->primary("Matching predb filename to cleaned releasefiles.name.\n");

		$qry =	sprintf('
				SELECT r.id AS releaseid, r.name, r.searchname, r.group_id, r.categoryid,
					rf.name AS filename
				FROM releases r
				INNER JOIN releasefiles rf ON r.id = rf.releaseid
				AND rf.name IS NOT NULL
				WHERE r.preid = 0
				GROUP BY r.id
				%s %s',
				$orderby,
				$limit
		);

		$query = $this->pdo->queryDirect($qry);

		if ($query !== false){
			$total = $query->rowCount();
		}

		if ($total > 0) {

			echo $this->pdo->log->header("\n" . number_format($total) . ' releases to process.');

			foreach ($query as $row) {
				$success = 0;
				$success = $this->matchPredbFiles($row, 1, 1, true, $show);
				if ($success === 1) {
					$counted++;
				}
				if ($show === 0) {
					$this->consoletools->overWritePrimary("Renamed Releases: [" . number_format($counted) . "] " . $this->consoletools->percentString(++$counter, $total));
				}
			}
			echo $this->pdo->log->header("\nRenamed " . number_format($counted) . " releases in " . $this->consoletools->convertTime(TIME() - $timestart) . ".");
		} else {
			echo $this->pdo->log->info("\nNothing to do.");
		}
	}

	// Match a release filename to a PreDB filename or title.
	public function matchPredbFiles($release, $echo, $namestatus, $echooutput, $show)
	{
		$matching = $total = 0;
		$fileName = $this->_cleanMatchFiles($release['filename']);
		$res = false;

		if ($fileName !== false){
			$res = $this->pdo->queryDirect(
						sprintf('
							SELECT id AS preid, title, source
							FROM predb
							WHERE filename = %s
							OR title = %1$s',
							$this->pdo->escapeString($fileName)
						)
			);
		}

		if ($res !== false) {
			$total = $res->rowCount();
		}

		if ($total > 0) {
			foreach ($res as $pre) {
				if ($pre['title'] !== $release['searchname']) {
						$this->updateRelease($release, $pre['title'], $method = "file matched source: " . $pre['source'], $echo, "PreDB file match, ", $namestatus, $show, $pre['preid']);
						$matching++;
				}
			}
		}
		return $matching;
	}

	// Cleans file names for PreDB Match
	protected function _cleanMatchFiles($fileName)
	{
		$this->fileName = $fileName;

		// first strip all non-printing chars  from filename
		$this->fileName = $this->utility->stripNonPrintingChars($this->fileName);

		if (strlen($this->fileName) !== false && strlen($this->fileName) > 0 && strpos($this->fileName, '.') !== 0) {
			switch (true) {
				case strpos($this->fileName, '.') !== false:
					//some filenames start with a period that ends up creating bad matches so we don't process them
					$this->fileName = $this->utility->cutStringUsingLast('.', $this->fileName, "left", false);
					continue;
				//if filename has a .part001, send it back to the function to cut the next period
				case preg_match('/\.part\d+$/', $this->fileName):
					$this->fileName = $this->utility->cutStringUsingLast('.', $this->fileName, "left", false);
					continue;
				//if filename has a .vol001, send it back to the function to cut the next period
				case preg_match('/\.vol\d+(\+\d+)?$/', $this->fileName):
					$this->fileName = $this->utility->cutStringUsingLast('.', $this->fileName, "left", false);
					continue;
				//if filename contains a slash, cut the string and keep string to the right of the last slash to remove dir
				case strpos($this->fileName, '\\') !== false:
					$this->fileName = $this->utility->cutStringUsingLast('\\', $this->fileName, "right", false);
					continue;
				// A lot of obscured releases have one NFO file properly named with a track number (Audio) at the front of it
				// This will strip out the track and match it to its pre title
				case preg_match('/^\d{2}-/', $this->fileName):
					$this->fileName = preg_replace('/^\d{2}-/', '', $this->fileName);
					break;
				default:
					break;
			}
			return trim($this->fileName);
		}
		return false;
	}

	// Match a Hash from the predb to a release.
	public function matchPredbHash($hash, $release, $echo, $namestatus, $echooutput, $show)
	{
		$pdo = $this->pdo;
		$matching = 0;
		$this->matched = false;
		$row = false;

		// Determine MD5 or SHA1
		if (strlen($hash) === 40) {
			$hashtype = "SHA1, ";
		} else {
			$hashtype = "MD5, ";
		}

		$row = $pdo->queryOneRow(
					sprintf("
						SELECT p.id AS preid, p.title, p.source
						FROM predb p INNER JOIN predbhash h ON h.pre_id = p.id
						WHERE MATCH (h.hashes) AGAINST (%s)
						LIMIT 1",
						$pdo->escapeString(strtolower($hash))
					)
		);

		if ($row !== false) {
			if ($row["title"] !== $release["searchname"]) {
					$this->updateRelease($release, $row["title"], $method = "predb hash release name: " . $row["source"], $echo, $hashtype, $namestatus, $show, $row['preid']);
					$matching++;
			}
		} else {
			$pdo->queryExec(
					sprintf("
						UPDATE releases
						SET dehashstatus = %d - 1
						WHERE id = %d",
						$release['dehashstatus'],
						$release['releaseid']
					)
			);
		}
		return $matching;
	}

	//  Check the array using regex for a clean name.
	public function checkName($release, $echo, $type, $namestatus, $show, $preid = false)
	{
		// Get pre style name from releases.name
		if (preg_match_all('/([\w\(\)]+[\s\._-]([\w\(\)]+[\s\._-])+[\w\(\)]+-\w+)/', $release['textstring'], $matches)) {
			foreach ($matches as $match) {
				foreach ($match as $val) {
					$title = $this->pdo->queryOneRow("SELECT title, id from predb WHERE title = " . $this->pdo->escapeString(trim($val)));
					if ($title !== false) {
						$this->updateRelease($release, $title['title'], $method = "preDB: Match", $echo, $type, $namestatus, $show, $title['id']);
					}
				}
			}
		}

		// if processing preid on filename, do not continue
		if ($preid === true) {
			return false;
		}

		if ($type == "PAR2, ") {
			$this->fileCheck($release, $echo, $type, $namestatus, $show);
		} else {
			// Just for NFOs.
			if ($type == "NFO, ") {
				$this->nfoCheckTV($release, $echo, $type, $namestatus, $show);
				$this->nfoCheckMov($release, $echo, $type, $namestatus, $show);
				$this->nfoCheckMus($release, $echo, $type, $namestatus, $show);
				$this->nfoCheckTY($release, $echo, $type, $namestatus, $show);
				$this->nfoCheckG($release, $echo, $type, $namestatus, $show);
			}
			// Just for filenames.
			if ($type == "Filenames, ") {
				$this->fileCheck($release, $echo, $type, $namestatus, $show);
			}
			$this->tvCheck($release, $echo, $type, $namestatus, $show);
			$this->movieCheck($release, $echo, $type, $namestatus, $show);
			$this->gameCheck($release, $echo, $type, $namestatus, $show);
			$this->appCheck($release, $echo, $type, $namestatus, $show);
		}
		// The release didn't match so set proc_nfo = 1 so it doesn't get rechecked. Also allows removeCrapReleases to run extra things on the release.
		if ($namestatus == 1 && $this->matched === false && $type == "NFO, ") {
			$pdo = $this->pdo;
			$pdo->queryExec(sprintf("UPDATE releases SET proc_nfo = 1 WHERE id = %d", $release["releaseid"]));
		} // The release didn't match so set proc_files = 1 so it doesn't get rechecked. Also allows removeCrapReleases to run extra things on the release.
		else if ($namestatus == 1 && $this->matched === false && $type == "Filenames, ") {
			$pdo = $this->pdo;
			$pdo->queryExec(sprintf("UPDATE releases SET proc_files = 1 WHERE id = %d", $release["releaseid"]));
		} // The release didn't match so set proc_par2 = 1 so it doesn't get rechecked. Also allows removeCrapReleases to run extra things on the release.
		else if ($namestatus == 1 && $this->matched === false && $type == "PAR2, ") {
			$pdo = $this->pdo;
			$pdo->queryExec(sprintf("UPDATE releases SET proc_par2 = 1 WHERE id = %d", $release["releaseid"]));
		}

		return $this->matched;
	}

	//  Look for a TV name.
	public function tvCheck($release, $echo, $type, $namestatus, $show)
	{
		$result = '';
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2})[-\w.\',;.()]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -][-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.Text.source.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[-\w.\',;& ]+((19|20)\d\d)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.Text.year.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[-\w.\',;& ]+(480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.Text.resolution.source.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.source.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.acodec.source.res.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[-\w.\',;& ]+((19|20)\d\d)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.SxxExx.resolution.source.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -]((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "tvCheck: Title.year.###(season/episode).source.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w(19|20)\d\d[._ -]\d{2}[._ -]\d{2}[._ -](IndyCar|NBA|NCW(T|Y)S|NNS|NSCS?)([._ -](19|20)\d\d)?[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "tvCheck: Sports", $echo, $type, $namestatus, $show);
		}
	}

	//  Look for a movie name.
	public function movieCheck($release, $echo, $type, $namestatus, $show)
	{
		$result = '';
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[-\w.\',;& ]+(480|720|1080)[ip][._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.Text.res.vcod.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -](480|720|1080)[ip][-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.source.vcodec.res.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.source.vcodec.acodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.language.acodec.source.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.resolution.source.acodec.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.resolution.source.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.source.resolution.acodec.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.resolution.acodec.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BR(RIP)?|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -][-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.source.res.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -][-\w.\',;& ]+[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BR(RIP)?|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.year.eptitle.source.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+(480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.resolution.source.acodec.vcodec.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+(480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[-\w.\',;& ]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]((19|20)\d\d)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.resolution.acodec.eptitle.source.year.group", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)[._ -]((19|20)\d\d)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "movieCheck: Title.language.year.acodec.src", $echo, $type, $namestatus, $show);
		}
	}

	//  Look for a game name.
	public function gameCheck($release, $echo, $type, $namestatus, $show)
	{
		$result = '';
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+(ASIA|DLC|EUR|GOTY|JPN|KOR|MULTI\d{1}|NTSCU?|PAL|RF|Region[._ -]?Free|USA|XBLA)[._ -](DLC[._ -]Complete|FRENCH|GERMAN|MULTI\d{1}|PROPER|PSN|READ[._ -]?NFO|UMD)?[._ -]?(GC|NDS|NGC|PS3|PSP|WII|XBOX(360)?)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "gameCheck: Videogames 1", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+(GC|NDS|NGC|PS3|WII|XBOX(360)?)[._ -](DUPLEX|iNSOMNi|OneUp|STRANGE|SWAG|SKY)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "gameCheck: Videogames 2", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[\w.\',;-].+-OUTLAWS/i', $release["textstring"], $result)) {
			$result = str_replace("OUTLAWS", "PC GAME OUTLAWS", $result['0']);
			$this->updateRelease($release, $result["0"], $method = "gameCheck: PC Games -OUTLAWS", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[\w.\',;-].+\-ALiAS/i', $release["textstring"], $result)) {
			$newresult = str_replace("-ALiAS", " PC GAME ALiAS", $result['0']);
			$this->updateRelease($release, $newresult, $method = "gameCheck: PC Games -ALiAS", $echo, $type, $namestatus, $show);
		}
	}

	//  Look for a app name.
	public function appCheck($release, $echo, $type, $namestatus, $show)
	{
		$result = '';
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+(\d{1,10}|Linux|UNIX)[._ -](RPM)?[._ -]?(X64)?[._ -]?(Incl)[._ -](Keygen)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "appCheck: Apps 1", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+\d{1,8}[._ -](winall-freeware)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "appCheck: Apps 2", $echo, $type, $namestatus, $show);
		}
	}

	/*
	 * Just for NFOS.
	 */

	//  TV.
	public function nfoCheckTV($release, $echo, $type, $namestatus, $show)
	{
		$result = '';
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/:\s*.*[\\\\\/]([A-Z0-9].+?S\d+[.-_ ]?[ED]\d+.+?)\.\w{2,}\s+/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["1"], $method = "nfoCheck: Generic TV 1", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(?:(\:\s{1,}))(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+?)(\s{2,}|\r|\n)/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["2"], $method = "nfoCheck: Generic TV 2", $echo, $type, $namestatus, $show);
		}
	}

	//  Movies.
	public function nfoCheckMov($release, $echo, $type, $namestatus, $show)
	{
		$result = '';
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(?:(\:\s{1,}))(.+?(19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["2"], $method = "nfoCheck: Generic Movies 1", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(?:(\s{2,}))(.+?[\.\-_ ](19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["2"], $method = "nfoCheck: Generic Movies 2", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(?:(\s{2,}))(.+?[\.\-_ ](NTSC|MULTi).+?(MULTi|DVDR)[\.\-_ ].+?)(\s{2,}|\r|\n)/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["2"], $method = "nfoCheck: Generic Movies 3", $echo, $type, $namestatus, $show);
		}
	}

	//  Music.
	public function nfoCheckMus($release, $echo, $type, $namestatus, $show)
	{
		$result = '';
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(?:\s{2,})(.+?-FM-\d{2}-\d{2})/i', $release["textstring"], $result)) {
			$newname = str_replace('-FM-', '-FM-Radio-MP3-', $result["1"]);
			$this->updateRelease($release, $newname, $method = "nfoCheck: Music FM RADIO", $echo, $type, $namestatus, $show);
		}
	}

	//  Title (year)
	public function nfoCheckTY($release, $echo, $type, $namestatus, $show)
	{
		$result = '';
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(\w[-\w`~!@#$%^&*()_+={}|"<>?\[\]\\;\',.\/ ]+\s?\((19|20)\d\d\))/i', $release["textstring"], $result) && !preg_match('/\.pdf|Audio ?Book/i', $release["textstring"])) {
			$releasename = $result[0];
			if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(idiomas|lang|language|langue|sprache).*?\b(Brazilian|Chinese|Croatian|Danish|DE|Deutsch|Dutch|Estonian|ES|English|Englisch|Finnish|Flemish|Francais|French|FR|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)\b/i', $release["textstring"], $result)) {
				switch ($result[2]) {
					case 'DE':
						$result[2] = 'DUTCH';
						break;
					case 'Englisch':
						$result[2] = 'English';
						break;
					case 'FR':
						$result[2] = 'FRENCH';
						break;
					case 'ES':
						$result[2] = 'SPANISH';
						break;
					default:
						break;
				}
				$releasename = $releasename . "." . $result[2];
			}
			if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(frame size|res|resolution|video|video res).*?(272|336|480|494|528|608|640|\(640|688|704|720x480|816|820|1080|1 080|1280 @|1280|1920|1 920|1920x1080)/i', $release["textstring"], $result)) {
				if ($result[2] == '272') {
					$result[2] = '272p';
				} else if ($result[2] == '336') {
					$result[2] = '480p';
				} else if ($result[2] == '480') {
					$result[2] = '480p';
				} else if ($result[2] == '494') {
					$result[2] = '480p';
				} else if ($result[2] == '608') {
					$result[2] = '480p';
				} else if ($result[2] == '640') {
					$result[2] = '480p';
				} else if ($result[2] == '\(640') {
					$result[2] = '480p';
				} else if ($result[2] == '688') {
					$result[2] = '480p';
				} else if ($result[2] == '704') {
					$result[2] = '480p';
				} else if ($result[2] == '720x480') {
					$result[2] = '480p';
				} else if ($result[2] == '816') {
					$result[2] = '1080p';
				} else if ($result[2] == '820') {
					$result[2] = '1080p';
				} else if ($result[2] == '1080') {
					$result[2] = '1080p';
				} else if ($result[2] == '1280x720') {
					$result[2] = '720p';
				} else if ($result[2] == '1280 @') {
					$result[2] = '720p';
				} else if ($result[2] == '1280') {
					$result[2] = '720p';
				} else if ($result[2] == '1920') {
					$result[2] = '1080p';
				} else if ($result[2] == '1 920') {
					$result[2] = '1080p';
				} else if ($result[2] == '1 080') {
					$result[2] = '1080p';
				} else if ($result[2] == '1920x1080') {
					$result[2] = '1080p';
				}
				$releasename = $releasename . "." . $result[2];
			}
			if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(largeur|width).*?(640|\(640|688|704|720|1280 @|1280|1920|1 920)/i', $release["textstring"], $result)) {
				if ($result[2] == '640') {
					$result[2] = '480p';
				} else if ($result[2] == '\(640') {
					$result[2] = '480p';
				} else if ($result[2] == '688') {
					$result[2] = '480p';
				} else if ($result[2] == '704') {
					$result[2] = '480p';
				} else if ($result[2] == '1280 @') {
					$result[2] = '720p';
				} else if ($result[2] == '1280') {
					$result[2] = '720p';
				} else if ($result[2] == '1920') {
					$result[2] = '1080p';
				} else if ($result[2] == '1 920') {
					$result[2] = '1080p';
				} else if ($result[2] == '720') {
					$result[2] = '480p';
				}
				$releasename = $releasename . "." . $result[2];
			}
			if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/source.*?\b(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)\b/i', $release["textstring"], $result)) {
				if ($result[1] == 'BD') {
					$result[1] = 'Bluray.x264';
				} else if ($result[1] == 'CAMRIP') {
					$result[1] = 'CAM';
				} else if ($result[1] == 'DBrip') {
					$result[1] = 'BDRIP';
				} else if ($result[1] == 'DVD R1') {
					$result[1] = 'DVD';
				} else if ($result[1] == 'HD') {
					$result[1] = 'HDTV';
				} else if ($result[1] == 'NTSC') {
					$result[1] = 'DVD';
				} else if ($result[1] == 'PAL') {
					$result[1] = 'DVD';
				} else if ($result[1] == 'Ripped ') {
					$result[1] = 'DVDRIP';
				} else if ($result[1] == 'VOD') {
					$result[1] = 'DVD';
				}
				$releasename = $releasename . "." . $result[1];
			}
			if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(codec|codec name|codec code|format|MPEG-4 Visual|original format|res|resolution|video|video codec|video format|video res|tv system|type|writing library).*?\b(AVC|AVI|DBrip|DIVX|\(Divx|DVD|[HX][._ -]?264|NTSC|PAL|WMV|XVID)\b/i', $release["textstring"], $result)) {
				if ($result[2] == 'AVI') {
					$result[2] = 'DVDRIP';
				} else if ($result[2] == 'DBrip') {
					$result[2] = 'BDRIP';
				} else if ($result[2] == '(Divx') {
					$result[2] = 'DIVX';
				} else if ($result[2] == 'h.264') {
					$result[2] = 'H264';
				} else if ($result[2] == 'MPEG-4 Visual') {
					$result[2] = 'x264';
				} else if ($result[1] == 'NTSC') {
					$result[1] = 'DVD';
				} else if ($result[1] == 'PAL') {
					$result[1] = 'DVD';
				} else if ($result[2] == 'x.264') {
					$result[2] = 'x264';
				}
				$releasename = $releasename . "." . $result[2];
			}
			if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(audio|audio format|codec|codec name|format).*?\b(0x0055 MPEG-1 Layer 3|AAC( LC)?|AC-?3|\(AC3|DD5(.1)?|(A_)?DTS-?(HD)?|Dolby(\s?TrueHD)?|TrueHD|FLAC|MP3)\b/i', $release["textstring"], $result)) {
				if ($result[2] == '0x0055 MPEG-1 Layer 3') {
					$result[2] = 'MP3';
				} else if ($result[2] == 'AC-3') {
					$result[2] = 'AC3';
				} else if ($result[2] == '(AC3') {
					$result[2] = 'AC3';
				} else if ($result[2] == 'AAC LC') {
					$result[2] = 'AAC';
				} else if ($result[2] == 'A_DTS') {
					$result[2] = 'DTS';
				} else if ($result[2] == 'DTS-HD') {
					$result[2] = 'DTS';
				} else if ($result[2] == 'DTSHD') {
					$result[2] = 'DTS';
				}
				$releasename = $releasename . "." . $result[2];
			}
			$releasename = $releasename . "-NoGroup";
			$this->updateRelease($release, $releasename, $method = "nfoCheck: Title (Year)", $echo, $type, $namestatus, $show);
		}
	}

	//  Games.
	public function nfoCheckG($release, $echo, $type, $namestatus, $show)
	{
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/ALiAS|BAT-TEAM|\FAiRLiGHT|Game Type|Glamoury|HI2U|iTWINS|JAGUAR|LARGEISO|MAZE|MEDIUMISO|nERv|PROPHET|PROFiT|PROCYON|RELOADED|REVOLVER|ROGUE|ViTALiTY/i', $release["textstring"])) {
			$result = '';
			if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[\w.+&*\/\()\',;: -]+\(c\)[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
				$releasename = str_replace(array("(c)", "(C)"), "(GAMES) (c)", $result['0']);
				$this->updateRelease($release, $releasename, $method = "nfoCheck: PC Games (c)", $echo, $type, $namestatus, $show);
			} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[\w.+&*\/()\',;: -]+\*ISO\*/i', $release["textstring"], $result)) {
				$releasename = str_replace("*ISO*", "*ISO* (PC GAMES)", $result['0']);
				$this->updateRelease($release, $releasename, $method = "nfoCheck: PC Games *ISO*", $echo, $type, $namestatus, $show);
			}
		}
	}

	//  Misc.
	public function nfoCheckMisc($release, $echo, $type, $namestatus, $show)
	{
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/Supplier.+?IGUANA/i', $release["textstring"])) {
			$releasename = '';
			$result = '';
			if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w`~!@#$%^&*()+={}|:"<>?\[\]\\;\',.\/ ]+\s\((19|20)\d\d\)/i', $release["textstring"], $result)) {
				$releasename = $result[0];
			} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\s\[\*\] (English|Dutch|French|German|Spanish)\b/i', $release["textstring"], $result)) {
				$releasename = $releasename . "." . $result[1];
			} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\s\[\*\] (DTS 6[._ -]1|DS 5[._ -]1|DS 2[._ -]0|DS 2[._ -]0 MONO)\b/i', $release["textstring"], $result)) {
				$releasename = $releasename . "." . $result[2];
			} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/Format.+(DVD(5|9|R)?|[HX][._ -]?264)\b/i', $release["textstring"], $result)) {
				$releasename = $releasename . "." . $result[1];
			} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\[(640x.+|1280x.+|1920x.+)\] Resolution\b/i', $release["textstring"], $result)) {
				if ($result[1] == '640x.+') {
					$result[1] = '480p';
				} else if ($result[1] == '1280x.+') {
					$result[1] = '720p';
				} else if ($result[1] == '1920x.+') {
					$result[1] = '1080p';
				}
				$releasename = $releasename . "." . $result[1];
			}
			$result = $releasename . ".IGUANA";
			$this->updateRelease($release, $result, $method = "nfoCheck: IGUANA", $echo, $type, $namestatus, $show);
		}
	}

	/*
	 * Just for filenames.
	 */

	public function fileCheck($release, $echo, $type, $namestatus, $show)
	{
		$result = '';
		if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/^(.+?(x264|XviD)\-TVP)\\\\/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["1"], $method = "fileCheck: TVP", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+)\.(.+)$/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["4"], $method = "fileCheck: Generic TV", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?([\.\-_ ]\d{4}[\.\-_ ].+?(BDRip|bluray|DVDRip|XVID)).+)\.(.+)$/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["4"], $method = "fileCheck: Generic movie 1", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/^([a-z0-9\.\-_]+(19|20)\d\d[a-z0-9\.\-_]+[\.\-_ ](720p|1080p|BDRip|bluray|DVDRip|x264|XviD)[a-z0-9\.\-_]+)\.[a-z]{2,}$/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["1"], $method = "fileCheck: Generic movie 2", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/(.+?([\.\-_ ](CD|FM)|[\.\-_ ]\dCD|CDR|FLAC|SAT|WEB).+?(19|20)\d\d.+?)\\\\.+/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["1"], $method = "fileCheck: Generic music", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/^(.+?(19|20)\d\d\-([a-z0-9]{3}|[a-z]{2,}|C4))\\\\/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["1"], $method = "fileCheck: music groups", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/.+\\\\(.+\((19|20)\d\d\)\.avi)/i', $release["textstring"], $result)) {
			$newname = str_replace('.avi', ' DVDRip XVID NoGroup', $result["1"]);
			$this->updateRelease($release, $newname, $method = "fileCheck: Movie (year) avi", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/.+\\\\(.+\((19|20)\d\d\)\.iso)/i', $release["textstring"], $result)) {
			$newname = str_replace('.iso', ' DVD NoGroup', $result["1"]);
			$this->updateRelease($release, $newname, $method = "fileCheck: Movie (year) iso", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/^(.+?IMAGESET.+?)\\\\.+/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["1"], $method = "fileCheck: XXX Imagesets", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+1080i[._ -]DD5[._ -]1[._ -]MPEG2-R&C(?=\.ts)/i', $release["textstring"], $result)) {
			$result = str_replace("MPEG2", "MPEG2.HDTV", $result["0"]);
			$this->updateRelease($release, $result, $method = "fileCheck: R&C", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]nSD[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -]NhaNC3[-\w.\',;& ]+\w/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "fileCheck: NhaNc3", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\wtvp-[\w.\-\',;]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](720p|1080p|xvid)(?=\.(avi|mkv))/i', $release["textstring"], $result)) {
			$result = str_replace("720p", "720p.HDTV.X264", $result['0']);
			$result = str_replace("1080p", "1080p.Bluray.X264", $result['0']);
			$result = str_replace("xvid", "XVID.DVDrip", $result['0']);
			$this->updateRelease($release, $result, $method = "fileCheck: tvp", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+\d{3,4}\.hdtv-lol\.(avi|mp4|mkv|ts|nfo|nzb)/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "fileCheck: Title.211.hdtv-lol.extension", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w[-\w.\',;& ]+-S\d{1,2}[EX]\d{1,2}-XVID-DL.avi/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "fileCheck: Title-SxxExx-XVID-DL.avi", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\S.*[\w.\-\',;]+\s\-\ss\d{2}[ex]\d{2}\s\-\s[\w.\-\',;].+\./i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "fileCheck: Title - SxxExx - Eptitle", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w.+?\)\.nds/i', $release["textstring"], $result)) {
			$this->updateRelease($release, $result["0"], $method = "fileCheck: ).nds Nintendo DS", $echo, $type, $namestatus, $show);
		} else if ($this->done === false && $this->relid !== $release["releaseid"] && preg_match('/\w.+?\.(epub|mobi|azw|opf|fb2|prc|djvu|cb[rz])/i', $release["textstring"], $result)) {
			$result = str_replace("." . $result["1"], " (" . $result["1"] . ")", $result['0']);
			$this->updateRelease($release, $result, $method = "fileCheck: EBook", $echo, $type, $namestatus, $show);
		}
	}

}
