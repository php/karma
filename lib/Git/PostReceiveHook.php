<?php
namespace Git;

class PostReceiveHook extends ReceiveHook
{

    private $pushAuthor = '';
    private $mailingList = '';
    private $emailprefix = '';


    private $refs = array();
    private $revisions = array();

    private $allBranches = array();


    public function __construct($basePath, $pushAuthor, $mailingList, $emailprefix)
    {
        parent::__construct($basePath);

        $this->pushAuthor = $pushAuthor;
        $this->mailingList = $mailingList;
        $this->emailprefix = $emailprefix;

        $this->allBranches = $this->getAllBranches();
    }

    private function getAllBranches()
    {
        return explode("\n", $this->execute('git for-each-ref --format="%%(refname)" "refs/heads/*"'));
    }


    private function execute($cmd)
    {
        $args = func_get_args();
        array_shift($args);
        $output = shell_exec(vsprintf($cmd, $args));
        return $output;
    }

    public function process()
    {
        $this->refs = $this->hookInput();

        //send mails per ref push
        foreach ($this->refs as $ref) {
            if ($ref['reftype'] == self::REF_TAG) {
                $this->sendTagMail($ref);
            } else {
                $this->sendBranchMail($ref);
            }
        }

        // TODO: mail per commit
        // send mail only about new commits
        // But for new branches we must check if this branch was
        // cloned from other branch in this push - it's especial case
        // TODO: check old post-receive for other especial cases
    }

    private function sendBranchMail(array $branch)
    {

        if ($branch['changetype'] == self::TYPE_UPDATED) {
            $title = "Branch " . $branch['refname'] . " was updated";
            $message = $title . "\n\n";
        } elseif ($branch['changetype'] == self::TYPE_CREATED) {
            $title = "Branch " . $branch['refname'] . " was created";
            $message = $title . "\n\n";
        } else {
            $title = "Branch " . $branch['refname'] . " was deleted";
            $message = $title . "\n\n";
        }



        if ($branch['changetype'] != self::TYPE_DELETED) {

            // TODO: cache revisions to $this->revisions
            if ($branch['changetype'] == self::TYPE_UPDATED) {
                // git rev-list old..new
                $revisions = $this->getRevisions($branch['old'] . '..' . $branch['new']);
            } else {
                // for new branch we write log about new commits only
                $revisions = $this->getRevisions($branch['new']. ' --not ' . implode(' ', $this->allBranches));
            }

            $message .= "--------LOG--------\n";
            foreach ($revisions as $revision) {
                $diff = $this->execute(
                    'git diff-tree --stat --pretty=medium -c %s',
                    $revision
                );

                $message .= $diff."\n\n";
            }
        }

        $this->mail($this->emailprefix . '[push] ' . $title , $message);
    }

    private function sendTagMail(array $tag)
    {

        if ($tag['changetype'] == self::TYPE_UPDATED) {
            $title = "Tag " . $tag['refname'] . " was updated";
            $message = $title . "\n\n";
        } elseif ($tag['changetype'] == self::TYPE_CREATED) {
            $title = "Tag " . $tag['refname'] . " was created";
            $message = $title . "\n\n";
        } else {
            $title = "Tag " . $tag['refname'] . " was deleted";
            $message = $title . "\n\n";
        }

        if ($tag['changetype'] != self::TYPE_CREATED) $isAnnotatedOldTag = $this->isAnnotatedTag($tag['old']);
        if ($tag['changetype'] != self::TYPE_DELETED) $isAnnotatedNewTag = $this->isAnnotatedTag($tag['new']);

        // TODO: write info about tag and target

        $this->mail($this->emailprefix . '[push] ' . $title , $message);
    }

    private function isAnnotatedTag($rev)
    {
        return $this->execute('git for-each-ref --format="%%(objecttype)" %s', $rev) == 'tag';
    }


    private function getRevisions($revRange)
    {
        $output = $this->execute(
            'git rev-list %s',
            $revRange
        );
        $output = trim($output);
        $revisions = $output ? explode("\n", trim($output)) : array();
        return $revisions;
    }


    private function mail($subject, $message) {
        $headers = array(
            'From: ' . $this->pushAuthor . '@php.net',
            'Reply-To: ' . $this->pushAuthor . '@php.net'
        );

        mail($this->mailingList, $subject, $message, implode("\r\n", $headers));
    }


    private function isRevExistsInBranches($revision, array $branches) {
        return !(bool) $this->execute('git rev-list --max-count=1 %s --not %s', $revision, implode(' ', $branches));
    }

}
