<?php
namespace Git;

class PostReceiveHook extends ReceiveHook
{

    private $pushAuthor = '';
    private $mailingList = '';
    private $emailPrefix = '';


    private $refs = [];
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
     * @param $cmd string
     * @return string
     */
    private function gitExecute($cmd)
    {
        $cmd = \Git::GIT_EXECUTABLE . " --git-dir=" . $this->repositoryPath . " " . $cmd;
        $args = func_get_args();
        array_shift($args);
        $cmd = vsprintf($cmd, $args);
        $output = shell_exec($cmd);
        return $output;
    }

    /**
     * @return array
     */
    private function getAllBranches()
    {
        return explode("\n", $this->gitExecute('for-each-ref --format="%%(refname)" "refs/heads/*"'));
    }

    /**
     *
     */
    public function process()
    {
        $this->refs = $this->hookInput();

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
     * @param $name string
     * @param $changeType int
     * @param $oldrev string
     * @param $newrev string
     */
    private function sendBranchMail($name, $changeType, $oldrev, $newrev)
    {

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
                if ($replacedRevisions = $this->getRevisions($newrev . '..' . $oldrev)) {
                    $message .= "Discarded revisions: \n" . implode("\n", $replacedRevisions) . "\n";
                }

                // git rev-list old..new
                $revisions = $this->getRevisions($oldrev . '..' . $newrev);

            } else {
                // for new branch we write log about new commits only
                $revisions = $this->getRevisions($newrev. ' --not ' . implode(' ', array_diff($this->allBranches, $this->newBranches)));

                foreach ($this->updatedBranches as $refname) {
                    if ($this->isRevExistsInBranches($this->refs[$refname]['old'], [$name])) {
                        $this->cacheRevisions($name, $this->getRevisions($this->refs[$refname]['old'] . '..' . $newrev));
                    }
                }
            }

            $this->cacheRevisions($name, $revisions);

            if (count($revisions)) {
                $message .= "--------LOG--------\n";
                foreach ($revisions as $revision) {
                    $diff = $this->gitExecute(
                        'diff-tree --stat --pretty=medium -c %s',
                        $revision
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
     * @param $name string
     * @param $changetype int
     * @param $oldrev string
     * @param $newrev string
     */
    private function sendTagMail($name, $changetype, $oldrev, $newrev)
    {

        if ($changetype == self::TYPE_UPDATED) {
            $title = "Tag " . $name . " was updated";
        } elseif ($changetype == self::TYPE_CREATED) {
            $title = "Tag " . $name . " was created";
        } else {
            $title = "Tag " . $name . " was deleted";
        }

        $message = $title . "\n\n";

        if ($changetype != self::TYPE_DELETED) {
            $message .= "Tag info:\n";
            $isAnnotatedNewTag = $this->isAnnotatedTag($name);
            if ($isAnnotatedNewTag) {
                $message .= $this->getAnnotatedTagInfo($name) ."\n";
            } else {
                $message .= $this->getTagInfo($newrev) ."\n";
            }
        }
        if ($changetype != self::TYPE_CREATED) {
            $message .= "Old tag sha: \n" . $oldrev;
        }

        $this->mail($this->emailPrefix . '[push] ' . $title , $message);
    }

    /**
     * @param $tag string
     * @return string
     */
    private function getTagInfo($tag)
    {
        $info = "Target:\n";
        $info .= $this->gitExecute('diff-tree --stat --pretty=medium -c %s', $tag);
        return $info;
    }

    /**
     * @param $tag string
     * @return string
     */
    private function getAnnotatedTagInfo($tag)
    {
        $tagInfo = $this->gitExecute('for-each-ref --format="%%(*objectname) %%(taggername) %%(taggerdate)" %s', $tag);
        list($target, $tagger, $taggerdate) = explode(' ', $tagInfo);

        $info = "Tagger: " . $tagger . "\n";
        $info .= "Date: " . $taggerdate . "\n";
        $info .= $this->gitExecute("cat-file tag %s | sed -e '1,/^$/d'", $tag)."\n";
        $info .= "Target:\n";
        $info .= $this->gitExecute('diff-tree --stat --pretty=medium -c %s', $target);
        return $info;
    }

    /**
     * @param $rev string
     * @return bool
     */
    private function isAnnotatedTag($rev)
    {
        return trim($this->gitExecute('for-each-ref --format="%%(objecttype)" %s', $rev)) == 'tag';
    }

    /**
     * @param $revRange string
     * @return array
     */
    private function getRevisions($revRange)
    {
        $output = $this->gitExecute(
            'rev-list %s',
            $revRange
        );
        $revisions = $output ? explode("\n", trim($output)) : [];
        return $revisions;
    }



    private function getCommitInfo($revision)
    {
        $raw = $this->gitExecute('rev-list -n 1 --format="%%P%%n%%an%%n%%ae%%n%%aD%%n%%cn%%n%%ce%%n%%cD%%n%%B" %s', $revision);
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

    private function getCommitChangedPaths($revision)
    {
        $raw = $this->gitExecute('show --name-status --pretty="format:" %s', $revision);
        $paths = [];
        if (preg_match_all('/([ACDMRTUXB*]+)\s+([^\n\s]+)/', $raw , $matches,  PREG_SET_ORDER)) {
            foreach($matches as $item) {
                $paths[$item[2]] = $item[1];
            }
        }
        return $paths;
    }

    /**
     * Send mail about commit.
     * Subject: [git] [commit] %PROJECT% %PATHS%
     * Body:
     * Author: %USER%                               Thu, 08 Mar 2012 12:39:48 +0000
     * Committer: %USER%                               Thu, 08 Mar 2012 12:39:48 +0000
     *
     * Commit: http://git.php.net/?p=%PROJECT_PATH%;a=commitdiff;h=%SHA%
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
        $paths = $this->getCommitChangedPaths($revision);
        $pathsString = '';
        foreach ($paths as $path => $action)
        {
            $pathsString .= '  ' . $action . '  ' . $path . "\n";
        }
        $diff =  $this->gitExecute('diff-tree -c -p %s', $revision);

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
            $message .= "Changed paths:\n" . $pathsString . "\n";
            if ((strlen($pathsString) + strlen($diff)) < 8192) {
                $message .= "Diff:\n" . $diff . "\n";
            } else {
                $diffFile = 'diff_' . $revision . '.txt';
                $mail->addTextFile($diffFile, $diff);
                if ((strlen($message) + $mail->getFileLength($diffFile)) > 262144) {
                    $mail->dropFile($diffFile);
                    $message .= 'Diff: <Diff exceeded maximum size>';
                }
            }
        } else {
            $pathsFile = 'paths_' . $revision . '.txt';
            $mail->addTextFile($pathsFile, $pathsString);
            if ((strlen($message) + $mail->getFileLength($pathsFile)) > 262144) {
                $mail->dropFile($pathsFile);
                $message .= 'Changed paths: <changed paths exceeded maximum size>';
            } else {
                $diffFile = 'diff_' . $revision . '.txt';
                $mail->addTextFile($diffFile, $diff);
                if ((strlen($message) + $mail->getFileLength($pathsFile) + $mail->getFileLength($diffFile)) > 262144) {
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
        return !(bool) $this->gitExecute('rev-list --max-count=1 %s --not %s', $revision, implode(' ', $branches));
    }

}
