<?php
namespace Git;

class PushInformation
{
    const GIT_EXECUTABLE = 'git';

    private $karmaFile;
    private $repositoryBasePath;

    private $hook    = null;
    private $repourl = null;

    public function __construct(ReceiveHook $hook)
    {
        $this->repourl = \Git::getRepositoryPath();
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
        $baserev = exec(sprintf('%s --git-dir=%s merge-base %s %s',
                        self::GIT_EXECUTABLE,
                        $this->repourl,
                        escapeshellarg($oldrev),
                        escapeshellarg($newrev)), $retval);

        $baserev = trim($baserev);

        if (0 !== $retval) {
            throw new \Exception('Failed to call git');
        }

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
                }
                return $newrev == $this->mergeBase($oldrev, $newrev);
            });

        return array_reduce($result, function($a, $b) { return $a || $b; }, false);
    }
}
