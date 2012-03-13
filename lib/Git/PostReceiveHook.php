<?php
namespace Git;

class PostReceiveHook extends ReceiveHook
{

    private $pushAuthor = '';
    private $mailingList = '';
    private $emailPrefix = '';

    private $newBranches = [];
    private $updatedBranches = [];
    private $revisions = [];

    private $allBranches = [];

    /**
     * @param $basePath string
     * @param $pushAuthor string
     * @param $mailingList string
     * @param $emailPrefix string
     */
    public function __construct($basePath, $pushAuthor, $mailingList, $emailPrefix)
    {
        parent::__construct($basePath);

        $this->pushAuthor = $pushAuthor;
        $this->mailingList = $mailingList;
        $this->emailPrefix = $emailPrefix;

        $this->allBranches = $this->getAllBranches();
    }

    /**
     *
     */
    public function process()
    {
        $this->hookInput();

        //cache list of new and updated branches
        foreach ($this->refs as $ref) {
            if ($ref['reftype'] == self::REF_BRANCH){
                if ($ref['changetype'] == self::TYPE_UPDATED) {
                    $this->updatedBranches[] = $ref['refname'];
                } elseif ($ref['changetype'] == self::TYPE_CREATED) {
                    $this->newBranches[] = $ref['refname'];
                }
            }
        }

        //send mails per ref push
        foreach ($this->refs as $ref) {
            if ($ref['reftype'] == self::REF_TAG) {
                $this->sendTagMail($ref['refname'], $ref['changetype'], $ref['old'], $ref['new']);
            } elseif ($ref['reftype'] == self::REF_BRANCH){
                $this->sendBranchMail($ref['refname'], $ref['changetype'], $ref['old'], $ref['new']);
            }
        }

        foreach ($this->revisions as $revision => $branches) {
            // check if it commit was already in other branches
            if (!$this->isRevExistsInBranches($revision, array_diff($this->allBranches, $branches))) {
                $this->sendCommitMail($revision);
            }
        }

    }

    /**
     * Send mail about branch.
     * Subject: [git] [branch] %STATUS% branch %BRANCH_NAME% in %PROJECT%
     * Body:
     * Branch %BRANCH_NAME% in %PROJECT% was %STATUS%
     * Date: Thu, 08 Mar 2012 12:39:48 +0000(current mail date)
     *
     * Link: http://git.php.net/?p=%PROJECT_PATH%;a=shortlog;h=refs/heads/%BRANCH_NAME%
     *
     * --part1--
     * Log:
     *
     * --per commit--
     * Commit: %SHA%
     * Author: %USER%                               Thu, 08 Mar 2012 12:39:48 +0000
     * Committer: %USER%                               Thu, 08 Mar 2012 12:39:48 +0000
     * Link: http://git.php.net/?p=%PROJECT_PATH%;a=commitdiff;h=%SHA%
     * Shortlog: %SHORT_MESSAGE%
     * --/per commit--
     *
     * --/part1--
     *
     * (if part1 exceeded maximum size)
     * --part1--
     * Commits:
     *
     * --per commit--
     * Commit: %SHA%
     * --/per commit--
     *
     * --/part1--
     *
     * @param $name string
     * @param $changeType int
     * @param $oldrev string
     * @param $newrev string
     */
    private function sendBranchMail($name, $changeType, $oldrev, $newrev)
    {
        // FIXME: work in progress

        if ($changeType == self::TYPE_UPDATED) {
            $title = "Branch " . $name . " was updated";
        } elseif ($changeType == self::TYPE_CREATED) {
            $title = "Branch " . $name . " was created";
        } else {
            $title = "Branch " . $name . " was deleted";
        }
        $message = $title . "\n\n";


        if ($changeType != self::TYPE_DELETED) {

            if ($changeType == self::TYPE_UPDATED) {
                // check if push was with --force option
                if ($replacedRevisions = $this->getRevisions(escapeshellarg($newrev . '..' . $oldrev))) {
                    $message .= "Discarded revisions: \n" . implode("\n", $replacedRevisions) . "\n";
                }

                // git rev-list old..new
                $revisions = $this->getRevisions(escapeshellarg($oldrev . '..' . $newrev));

            } else {
                // for new branch we write log about new commits only
                $revisions = $this->getRevisions(
                    escapeshellarg($newrev) . ' --not ' . implode(' ', $this->escapeArrayShellArgs(array_diff($this->allBranches, $this->newBranches)))
                );

                foreach ($this->updatedBranches as $refname) {
                    if ($this->isRevExistsInBranches($this->refs[$refname]['old'], [$name])) {
                        $this->cacheRevisions($name, $this->getRevisions(escapeshellarg($this->refs[$refname]['old'] . '..' . $newrev)));
                    }
                }
            }

            $this->cacheRevisions($name, $revisions);

            if (count($revisions)) {
                $message .= "--------LOG--------\n";
                foreach ($revisions as $revision) {
                    $diff = \Git::gitExec(
                        'diff-tree --stat --pretty=medium -c %s',
                        escapeshellarg($revision)
                    );

                    $message .= $diff."\n\n";
                }
            }
        }

        $this->mail($this->emailPrefix . '[push] ' . $title , $message);
    }


    /**
     * @param $branchName string
     * @param array $revisions
     */
    private function cacheRevisions($branchName, array $revisions)
    {
        //TODO: add mail order from older commit to newer
        foreach ($revisions as $revision)
        {
            $this->revisions[$revision][$branchName] = $branchName;
        }
    }


    /**
     * Send mail about tag.
     * Subject: [git] [tag] %PROJECT%: %STATUS% tag %TAGNAME%
     * Body:
     * Tag %TAGNAME% in %PROJECT% was %STATUS% (if sha was changed)from %OLD_SHA%
     * Tag(if annotaded): %SHA%
     * Tagger(if annotaded): %USER%                               Thu, 08 Mar 2012 12:39:48 +0000
     *
     * Log(if annotaded):
     * %MESSAGE%
     *
     * Link: http://git.php.net/?p=%PROJECT_PATH%;a=tag;h=%SHA%
     *
     * Target: %SHA%
     * Author: %USER%                               Thu, 08 Mar 2012 12:39:48 +0000
     * Committer: %USER%                               Thu, 08 Mar 2012 12:39:48 +0000
     * Parents: %SHA_PARENTS%
     * Target link: http://git.php.net/?p=%PROJECT_PATH%;a=commitdiff;h=%SHA%
     * Target log:
     * %MESSAGE%
     *
     * --part1--
     * Changed paths:
     * %PATHS%
     * --/part1--
     *
     * @param $name string
     * @param $changetype int
     * @param $oldrev string
     * @param $newrev string
     */
    private function sendTagMail($name, $changetype, $oldrev, $newrev)
    {

        $status = [self::TYPE_UPDATED => 'Update', self::TYPE_CREATED => 'Create', => self::TYPE_DELETED => 'Delete'];
        $shortname = str_replace('refs/tags/', '', $name);
        $mail = new \Mail();
        $mail->setSubject($this->emailPrefix . '[tag] ' . $this->getRepositoryName() . ': ' . $status[$changetype] . ' tag ' . $shortname;

        $message = 'Tag ' . $shortname . ' in ' . $this->getRepositoryName() . ' was ' . $status[$changetype] . 'd' .
            (($changetype == self::TYPE_DELETED) ? ' from ' . $oldrev : '' ) . "\n";

        if ($changetype != self::TYPE_DELETED) {
            $info = $this->getTagInfo($name);
            $targetInfo = $this->getCommitInfo($info['target']);
            $targetPaths = $this->getChangedPaths(escapeshellarg($info['target']));
            $pathsString = '';
            foreach ($targetPaths as $path => $action)
            {
                $pathsString .= '  ' . $action . '  ' . $path . "\n";
            }

            if ($info['annotated']) {
                $message .= 'Tag: ' . $info['revision'] . "\n";
                $message .= 'Tagger: ' . $info['tagger'] . '(' . $info['tagger_email'] . ')         ' . $info['tagger_date'] . "\n";
            }

            $message .= "\n";
            $message .= "Link: http://git.php.net/?p=" . $this->getRepositoryName() . ".git;;a=tag;h=" . $info['revision'] . "\n";
            $message .= "\n";

            $message .= 'Target: ' . $info['target'] . "\n";
            $message .= 'Author: ' . $targetInfo['author'] . '(' . $targetInfo['author_email'] . ')         ' . $targetInfo['author_date'] . "\n";
            $message .= 'Committer: ' . $targetInfo['committer'] . '(' . $targetInfo['committer_email'] . ')      ' . $targetInfo['committer_date'] . "\n";
            if ($targetInfo['parents']) $message .= 'Parents: ' . $targetInfo['parents'] . "\n";
            $message .= "Target link: http://git.php.net/?p=" . $this->getRepositoryName() . ".git;a=commitdiff;h=" . $info['target'] . "\n";
            $message .= "Target log:\n" . $targetInfo['log'] . "\n";


            if (strlen($pathsString) < 8192) {
                // inline changed paths
                $message .= "Changed paths:\n" . $pathsString . "\n";
            } else {
                // changed paths attach
                $pathsFile = 'paths_' . $info['target'] . '.txt';
                $mail->addTextFile($pathsFile, $pathsString);
                if ((strlen($message) + $mail->getFileLength($pathsFile)) > 262144) {
                    // changed paths attach exceeded max size
                    $mail->dropFile($pathsFile);
                    $message .= 'Changed paths: <changed paths exceeded maximum size>';
                }
            }
        }

        $mail->setMessage($message);

        $mail->setFrom($this->pushAuthor . '@php.net', $this->pushAuthor);
        $mail->addTo($this->mailingList);

        $mail->send();
    }

    /**
     * @param $tag string
     * @return string
     */
    private function getTagInfo($tag)
    {
        $temp = \Git::gitExec("for-each-ref --format=\"%%(objecttype)\n%%(*objectname)\n%%(taggername)\n%%(taggeremail)\n%%(taggerdate)\n%%(*objectname)\n%%(contents)\" %s", escapeshellarg($tag));
        $temp = explode("\n", $temp, 6);
        if ($temp[0] == 'tag') {
            $info = [
                'annotated' => true,
                'revision' => $temp[1],
                'tagger' => $temp[2],
                'tagger_email' => $temp[3],
                'tagger_date' => $temp[4],
                'target' => $temp[5],
                'log' => $temp[6]
            ];
        } else {
            $info = [
                'annotated' => false,
                'revision' => $temp[1],
                'target' => $temp[1]
            ];
        }
        return $info;
    }

    /**
     * Get list of revisions for $revRange
     *
     * Required already escaped string in $revRange!!!
     *
     * @param $revRange string A..B or A ^B C --not D   etc.
     * @return array
     */
    private function getRevisions($revRange)
    {
        $output = \Git::gitExec(
            'rev-list %s',
            $revRange
        );
        $revisions = $output ? explode("\n", trim($output)) : [];
        return $revisions;
    }



    private function getCommitInfo($revision)
    {
        $raw = \Git::gitExec('rev-list -n 1 --format="%%P%%n%%an%%n%%ae%%n%%aD%%n%%cn%%n%%ce%%n%%cD%%n%%B" %s', escapeshellarg($revision));
        $raw = explode("\n", $raw, 9); //8 elements separated by \n, last element - log message, first(skipped) element - "commit sha"
        return [
            'parents'           => $raw[1],  // %P
            'author'            => $raw[2],  // %an
            'author_email'      => $raw[3],  // %ae
            'author_date'       => $raw[4],  // %aD
            'committer'         => $raw[5],  // %cn
            'committer_email'   => $raw[6],  // %ce
            'committer_date'    => $raw[7],  // %cD
            'log'               => $raw[8]   // %B
        ];
    }

    /**
     * Send mail about commit.
     * Subject: [git] [commit] %PROJECT% %PATHS%
     * Body:
     * Commit: %SHA%
     * Author: %USER%                               Thu, 08 Mar 2012 12:39:48 +0000
     * Committer: %USER%                               Thu, 08 Mar 2012 12:39:48 +0000
     * Parents: %SHA_PARENTS%
     *
     * Link: http://git.php.net/?p=%PROJECT_PATH%;a=commitdiff;h=%SHA%
     *
     * Log:
     * %MESSAGE%
     *
     * Bug: %BUG%
     *
     * --part1--
     * Changed paths:
     * %PATHS%
     * --/part1--
     *
     * --part2--
     * Diff:
     * %DIFF%
     * --/part2--
     *
     * @param $revision string
     */
    private function sendCommitMail($revision)
    {

        $info = $this->getCommitInfo($revision);
        $paths = $this->getChangedPaths(escapeshellarg($revision));
        $pathsString = '';
        foreach ($paths as $path => $action)
        {
            $pathsString .= '  ' . $action . '  ' . $path . "\n";
        }
        $diff =  \Git::gitExec('diff-tree -c -p %s', escapeshellarg($revision));

        $mail = new \Mail();
        $mail->setSubject($this->emailPrefix . '[commit] ' . $this->getRepositoryName() . ' ' . implode(' ', array_keys($paths)));

        $message = '';

        $message .= 'Commit: ' . $revision . "\n";
        $message .= 'Author: ' . $info['author'] . '(' . $info['author_email'] . ')         ' . $info['author_date'] . "\n";
        $message .= 'Committer: ' . $info['committer'] . '(' . $info['committer_email'] . ')      ' . $info['committer_date'] . "\n";
        if ($info['parents']) $message .= 'Parents: ' . $info['parents'] . "\n";

        $message .= "\n" . "Link: http://git.php.net/?p=" . $this->getRepositoryName() . ".git;a=commitdiff;h=" . $revision . "\n";

        $message .= "\nLog:\n" . $info['log'] . "\n";


        if (strlen($pathsString) < 8192) {
            // inline changed paths
            $message .= "Changed paths:\n" . $pathsString . "\n";
            if ((strlen($pathsString) + strlen($diff)) < 8192) {
                // inline diff
                $message .= "Diff:\n" . $diff . "\n";
            } else {
                // diff attach
                $diffFile = 'diff_' . $revision . '.txt';
                $mail->addTextFile($diffFile, $diff);
                if ((strlen($message) + $mail->getFileLength($diffFile)) > 262144) {
                    // diff attach exceeded max size
                    $mail->dropFile($diffFile);
                    $message .= 'Diff: <Diff exceeded maximum size>';
                }
            }
        } else {
            // changed paths attach
            $pathsFile = 'paths_' . $revision . '.txt';
            $mail->addTextFile($pathsFile, $pathsString);
            if ((strlen($message) + $mail->getFileLength($pathsFile)) > 262144) {
                // changed paths attach exceeded max size
                $mail->dropFile($pathsFile);
                $message .= 'Changed paths: <changed paths exceeded maximum size>';
            } else {
                // diff attach
                $diffFile = 'diff_' . $revision . '.txt';
                $mail->addTextFile($diffFile, $diff);
                if ((strlen($message) + $mail->getFileLength($pathsFile) + $mail->getFileLength($diffFile)) > 262144) {
                    // diff attach exceeded max size
                    $mail->dropFile($diffFile);
                }
            }
        }

        $mail->setMessage($message);

        $mail->setFrom($this->pushAuthor . '@php.net', $this->pushAuthor);
        $mail->addTo($this->mailingList);

        $mail->send();
    }

    /**
     * @param $subject string
     * @param $message string
     */
    private function mail($subject, $message) {
        $headers = [
            'From: ' . $this->pushAuthor . '@php.net',
            'Reply-To: ' . $this->pushAuthor . '@php.net'
        ];

        mail($this->mailingList, $subject, $message, implode("\r\n", $headers));
    }

    /**
     * @param $revision string
     * @param array $branches
     * @return bool
     */
    private function isRevExistsInBranches($revision, array $branches) {
        return !(bool) \Git::gitExec('rev-list --max-count=1 %s --not %s', escapeshellarg($revision), implode(' ', $this->escapeArrayShellArgs($branches)));
    }

}
