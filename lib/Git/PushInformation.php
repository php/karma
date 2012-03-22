<?php
namespace Git;

class PushInformation
{

    private $hook    = null;

    public function __construct(ReceiveHook $hook)
    {
        $this->hook    = $hook;
    }

    /**
     * Returns the common ancestor revision for two given revisions
     *
     * Returns false if no sha1 was returned. Throws an exception if calling
     * git fails.
     *
     * @return boolean
     */
    protected function mergeBase($oldrev, $newrev)
    {
        $baserev = \Git::gitExec('merge-base %s %s', escapeshellarg($oldrev), escapeshellarg($newrev));

        $baserev = trim($baserev);


        if (40 != strlen($baserev)) {
            return false;
        }

        return $baserev;
    }

    /**
     * Returns true if merging $newrev would be fast forward
     *
     * @return boolean
     */
    public function isFastForward()
    {
        $result = $this->hook->mapInput(
            function ($oldrev, $newrev) {
                if ($oldrev == \Git::NULLREV) {
                    return true;
                }
                return $oldrev == $this->mergeBase($oldrev, $newrev);
            });

        return array_reduce($result, function($a, $b) { return $a && $b; }, true);
    }

    /**
     * Returns true if updating the refs would fail if push is not forced.
     *
     * @return boolean
     */
    public function isForced()
    {
        $result = $this->hook->mapInput(
            function($oldrev, $newrev) {
                if ($oldrev == \Git::NULLREV) {
                    return false;
                } else if ($newrev == \Git::NULLREV) {
                    return true;
                }
                return $newrev == $this->mergeBase($oldrev, $newrev);
            });

        return array_reduce($result, function($a, $b) { return $a || $b; }, false);
    }

    public function isNewBranch()
    {
        $result = $this->hook->mapInput(
            function($oldrev, $newrev) {
                return $oldrev == \Git::NULLREV;
            });
        return array_reduce($result, function($a, $b) { return $a || $b; }, false);
    }

    public function isTag()
    {
        $result = $this->hook->mapInput(
            function($oldrev, $newrev, $refname) {
                if (preg_match('@^refs/tags/.+@i', $refname)) {
                    return true;
                }
                return false;
            });

        return array_reduce($result, function($a, $b) { return $a || $b; }, false);
    }
}
